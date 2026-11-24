<?php

namespace App\Game\Bots\Raid;

use App\Game\Enum\Mission;
use App\Game\Bots\BotActions;
use App\Game\Bots\BotLog;
use App\Game\Bots\BotRelations;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * The raiding behaviour of a bot during a session. One call of play() per tick does, in order: settle hits that landed, run due
 * pre-impact re-spies (RaidFollowUp), read the reports of probes that arrived and decide on them (RaidPlanner: loot against fuel
 * and expected losses; an attack goes out only when the game's rules allow it, RaidDispatcher), and scout the galaxy around the
 * bot's planets for new targets to spy on (RaidTargetFinder). A profitable hit starts a raid streak: the bot stays online and keeps
 * scouting, spying and attacking until nothing profitable is left or the time is up.
 *
 * The bot only uses what a player has: the galaxy view (scouting) and the spy reports in its inbox. Everything is remembered in the
 * personality JSON (RaidMemory). Per tick at most two new probe flights and two attacks, so a tick stays short.
 */
class BotRaids
{
    use GameUtilities;

    /** Seconds a candidate list from scouting is reused. */
    private const SCOUT_CACHE = 600;

    /** Seconds after which a report of a target is too old to attack on. */
    private const REPORT_MAX_AGE = 1800;

    /** Probe flights in the air at once (scouting). */
    private const MAX_PENDING_SPIES = 3;

    /** Idle raid ticks (nothing to spy, decide or send) that end a streak. */
    private const STREAK_IDLE = 3;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidTargetFinder $finder,
        private readonly RaidSpyReports $reports,
        private readonly RaidPlanner $planner,
        private readonly RaidDispatcher $dispatcher,
        private readonly RaidFollowUp $followUp,
        private readonly BotEnvelope $envelope,
        private readonly BotLog $botLog,
        private readonly BotAcs $acs,
        private readonly BotMissiles $missiles,
        private readonly BotRelations $relations,
    ) {}

    /** Whether raids are on in the universe (`bots_raids_enabled`). */
    public function enabled(int $universe): bool
    {
        return !empty($this->configs->get($universe)->bots_raids_enabled);
    }

    /**
     * At session start: whether this session goes raiding (profile enabled, `raid_chance` percent).
     *
     * @param array<string, mixed> $s profile settings
     */
    public function rollSession(array $s): bool
    {
        return !empty($s['raid_enabled']) && mt_rand(1, 100) <= (int) $s['raid_chance'];
    }

    /**
     * Only the follow-up of attacks under way (settle, re-spy), for a wake-up tick outside a session.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality changed in place
     * @return int number of actions taken
     */
    public function maintain(BotActions $ctx, array $bot, array $s, array &$personality, int $now): int
    {
        $mem = RaidMemory::of($personality);
        $ctx->lossMultiplier = RaidLearning::multiplier($personality, $now);
        $actions = $this->followUp->recheck($ctx, $bot, $s, $mem, $now);
        $actions += $this->acs->join($ctx, $bot, $now);
        $this->followUp->settle($ctx, $bot, $s, $mem, $personality, $now);
        RaidMemory::prune($mem, $now);
        $personality['_raid'] = $mem;

        return $actions;
    }

    /**
     * One raid turn of a session tick.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality changed in place (`_raid`, `farm`)
     * @param array<string, mixed> $session session state; `raid` says this session raids, `extend_until` asks for a longer session, `streak_ended` flags the end of a streak
     * @return int number of actions taken (probes sent, attacks sent, fleets recalled)
     */
    public function play(BotActions $ctx, array $bot, array $s, array &$personality, array &$session, int $now): int
    {
        $actions = $this->maintain($ctx, $bot, $s, $personality, $now);
        $mem = RaidMemory::of($personality);
        $streak = !empty($mem['streak']) && (int) $mem['streak']['until'] > $now;
        if (empty($session['raid']) && !$streak) {
            return $actions;
        }

        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        if (!$this->hasRaiders($ctx)) {
            $session['raid'] = false;
            $this->botLog->log($botId, $uni, 'raid_skip', 'no ships to raid with (cargo or warships)');

            return $actions;
        }

        $work = $this->prepareProbes($ctx, $botId, $uni);
        $work += $this->decide($ctx, $bot, $s, $mem, $personality, $now);
        $work += $this->scoutAndSpy($ctx, $bot, $s, $mem, $personality, $now);
        $actions += $work;

        $this->streakTick($mem, $session, $botId, $uni, $work, $now);
        if (!empty($mem['spies']) || !empty($mem['streak'])) {
            $until = !empty($mem['streak']) ? (int) $mem['streak']['until'] : 0;
            $arrive = $mem['spies'] === [] ? 0 : max(array_column($mem['spies'], 'arrive')) + 120;
            $session['extend_until'] = max($until, min($arrive, $now + 900));
        }
        $personality['_raid'] = $mem;

        return $actions;
    }

    /**
     * Bookkeeping of the raid streak per tick: counts idle ticks, ends the streak when time is up or nothing profitable is left.
     *
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $session session state, `streak_ended` set when the streak ends
     * @param int $work what this tick did (spies, attacks) plus pending work counted by the caller
     */
    private function streakTick(array &$mem, array &$session, int $botId, int $uni, int $work, int $now): void
    {
        if (empty($mem['streak'])) {
            return;
        }
        $pending = $mem['spies'] !== [] || array_filter($mem['attacks'], static fn(array $a): bool => ($a['state'] ?? '') !== 'settled') !== [];
        $mem['streak']['idle'] = ($work > 0 || $pending) ? 0 : (int) $mem['streak']['idle'] + 1;
        $reason = (int) $mem['streak']['until'] <= $now ? 'time is up' : ((int) $mem['streak']['idle'] >= self::STREAK_IDLE ? 'nothing profitable left' : null);
        if ($reason === null) {
            return;
        }
        $this->botLog->log($botId, $uni, 'raid_streak', 'end: ' . $reason . ', ' . $mem['streak']['hits'] . ' hit(s) in ' . round(($now - (int) $mem['streak']['start']) / 60) . ' min');
        unset($mem['streak']);
        $session['streak_ended'] = true;
    }

    /** Whether any planet has cargo ships or warships. */
    private function hasRaiders(BotActions $ctx): bool
    {
        $e = $this->elements();
        foreach ($ctx->planets + $ctx->moons as $planet) {
            foreach ($this->elements()->ids('fleet') as $id) {
                $fighter = $e->combat($id)->attack > 0 && !in_array($id, [SHIP_COLOSHIP, SHIP_RECYCLER, SHIP_PROBE, SHIP_SOLSAT], true);
                if ($fighter && (int) ($planet[$e->column($id)] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Total probes on all planets. */
    private function probesAtHome(BotActions $ctx): int
    {
        $e = $this->elements();
        $sum = 0;
        foreach ($ctx->planets as $planet) {
            $sum += (int) $planet[$e->column(SHIP_PROBE)];
        }

        return $sum;
    }

    /**
     * A bot that starts raiding builds a few probes (through the normal shipyard rules) when it has fewer than it wants.
     *
     * @return int 1 when a batch was queued
     */
    private function prepareProbes(BotActions $ctx, int $botId, int $uni): int
    {
        $missing = $ctx->bot->probesWanted - $this->probesAtHome($ctx);
        $queued = $this->queuedProbes($ctx);
        if ($missing - $queued < 1) {
            return 0;
        }
        foreach ($ctx->planets as $id => $planet) {
            $detail = $ctx->units($id, SHIP_PROBE, $missing - $queued);
            if ($detail !== null) {
                $this->botLog->log($botId, $uni, 'raid_prep', 'building probes for raiding: ' . $detail);

                return 1;
            }
        }

        return 0;
    }

    /** Probes in the shipyard queues of all planets. */
    private function queuedProbes(BotActions $ctx): int
    {
        $sum = 0;
        foreach ($ctx->planets as $planet) {
            $queue = !empty($planet['b_hangar_id']) ? \App\Game\Core\StoredData::decode($planet['b_hangar_id']) : [];
            foreach ((array) $queue as $item) {
                if ((int) $item[0] === SHIP_PROBE) {
                    $sum += (int) $item[1];
                }
            }
        }

        return $sum;
    }

    /**
     * Reads the reports of arrived scouting probes and, where the report allows it, attacks.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $personality
     * @return int actions taken (attacks sent)
     */
    private function decide(BotActions $ctx, array $bot, array $s, array &$mem, array &$personality, int $now): int
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        $sent = 0;
        foreach ($mem['spies'] as $fleetId => $spy) {
            if ($now < (int) $spy['arrive'] + 5) {
                continue;
            }
            $report = $this->reports->latest($botId, (int) $spy['planet'], $uni, (int) $spy['sent']);
            if ($report === null) {
                if ($now > (int) $spy['arrive'] + 600) {
                    unset($mem['spies'][$fleetId]);
                }
                continue;
            }
            unset($mem['spies'][$fleetId]);
            $target = $spy['target'];
            $this->remember($mem, $target, $report, $now);
            if ($sent >= 2 || $now - $report['time'] > self::REPORT_MAX_AGE) {
                continue;
            }
            $sent += $this->considerAttack($ctx, $bot, $s, $mem, $personality, $target, $report, (int) $spy['n'], $now);
        }

        return $sent;
    }

    /**
     * Notes what a report taught: time, the target's spy tech when the research section was visible.
     *
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $target scouted target
     * @param array<string, mixed> $report parsed report
     */
    private function remember(array &$mem, array $target, array $report, int $now): void
    {
        $t = &$mem['targets'][(int) $target['planet_id']];
        $t['user'] = (int) $target['user_id'];
        $t['seen'] = $now;
        $t['spy_at'] = (int) $report['time'];
        if ($report['techs'] !== null) {
            $t['tspy'] = (int) ($report['techs'][SPY_TECH] ?? 0);
        }
    }

    /**
     * Decides on the attack from a fresh report: unknown defense leads to more probes next time, an unprofitable target is left alone
     * for a while, a profitable one is attacked (the dispatcher applies the game's rules).
     *
     * @param array<string, mixed> $target scouted target
     * @param array<string, mixed> $report parsed report
     * @param array<string, mixed> $bot
     * @param array<mixed> $mem
     * @param array<mixed> $personality
     * @param array<mixed> $s
     * @return int 1 when an attack was sent
     */
    private function considerAttack(BotActions $ctx, array $bot, array $s, array &$mem, array &$personality, array $target, array $report, int $probes, int $now): int
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        $label = $target['name'] . ' [' . $target['coords'] . ']';
        $pid = (int) $target['planet_id'];
        $plan = $this->planner->plan($ctx, $report, $target, $s);
        if (!$plan['ok'] && str_starts_with((string) $plan['reason'], 'no fleet that wins')) {
            $help = $this->beyondSolo($ctx, $s, $mem, $target, $report, $botId, $uni, $label, $now);
            if ($help === true) {
                return 1;
            }
            $plan = $help ?? $plan;
        }
        if (!$plan['ok']) {
            $this->noGo($mem, $pid, $botId, $uni, $label, $plan['reason'], $report, $now, $ctx->bot->targetCooldownSeconds());

            return 0;
        }
        if ($ctx->freeFleetSlots() < ((int) $s['recall_check_chance'] > 0 ? 2 : 1)) {
            $this->botLog->log($botId, $uni, 'raid_skip', $label . ': no free fleet slot for the attack');

            return 0;
        }
        if ($this->fleetCapReached($ctx)) {
            $this->botLog->log($botId, $uni, 'raid_skip', $label . ': too many bot fleets in the air (limit ' . $ctx->bot->maxFleets . ')');

            return 0;
        }
        $envelope = !empty($target['is_bot']) ? null : $this->envelope->refusal($ctx, (int) $target['user_id'], Mission::Attack->value);
        if ($envelope !== null) {
            $this->botLog->log($botId, $uni, 'raid_skip', $label . ': ' . $envelope);
            $mem['targets'][$pid]['skip_until'] = $now + $ctx->bot->targetCooldownSeconds();

            return 0;
        }
        $plan['min_ratio'] = (float) $s['min_profit_ratio'];
        $plan['min_loot'] = $ctx->bot->minLoot;
        $plan['recall_loot'] = $ctx->bot->minLoot * $ctx->bot->recallLootPercent / 100;
        $plan['recall_ratio'] = $ctx->bot->recallRatioPercent / 100;
        $origin = $plan['origin'];
        $revenge = !empty($target['revenge']);
        $sent = $this->dispatcher->send($ctx, $ctx->originRef($origin), $plan['fleet'], Mission::Attack->value, $pid, (int) $target['user_id']);
        if (!$sent['ok']) {
            $this->botLog->log($botId, $uni, 'raid_skip', $label . ': game rules refuse the attack (' . $sent['reason'] . ')');
            $mem['targets'][$pid]['skip_until'] = $now + $ctx->bot->targetCooldownSeconds();

            return 0;
        }
        $attack = [
            'id' => $sent['id'], 'planet' => $pid, 'user' => (int) $target['user_id'], 'name' => $target['name'], 'coords' => $target['coords'],
            'is_bot' => (bool) $target['is_bot'], 'impact' => $sent['arrival'], 'origin' => $origin, 'plan' => $plan,
            'target' => ['galaxy' => $target['galaxy'], 'system' => $target['system'], 'planet' => $target['planet']],
        ];
        if (!empty($plan['joint'])) {
            $attack['acs'] = $this->acs->open($ctx, (int) $sent['id'], $pid, (int) $sent['arrival'], $plan['helpers']);
        }
        $schedule = $this->followUp->schedule($ctx, $attack, $s, $probes, $now);
        $mem['attacks'][$sent['id']] = $attack;
        $kind = !empty($plan['joint']) ? ' (ACS group #' . $attack['acs'] . ', ' . count($plan['helpers']) . ' helper(s))' : ($revenge ? ' (revenge)' : '');
        $this->botLog->log($botId, $uni, 'raid_attack', $this->attackText($label, $plan, $sent['arrival']) . $kind . '; ' . $schedule);

        return 1;
    }

    /**
     * What a bot tries when the target is too strong for its own fleet: a group attack with allied bots (BotAcs), else missiles at the
     * defense (BotMissiles; the target is then spied on again after the impact).
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $target scouted target
     * @param array<string, mixed> $report parsed report
     * @return array<string, mixed>|true|null a joint plan, true when missiles were launched, null when nothing helps
     */
    private function beyondSolo(BotActions $ctx, array $s, array &$mem, array $target, array $report, int $botId, int $uni, string $label, int $now): array|bool|null
    {
        $joint = $this->acs->plan($ctx, $report, $target, (float) $s['min_profit_ratio'], $now);
        if ($joint['ok']) {
            return $joint;
        }
        $volley = $this->missiles->strike($ctx, $report, $target, $s, $mem, $now);
        if ($volley === null) {
            return null;
        }
        $this->botLog->log($botId, $uni, 'raid_missiles', $label . ': ' . $volley);

        return true;
    }

    /**
     * Log text of an attack: fleet, expected loot, cost and ratio.
     *
     * @param array<string, mixed> $plan
     */
    private function attackText(string $label, array $plan, int $impact): string
    {
        $ships = [];
        foreach ($plan['fleet'] as $id => $count) {
            $ships[] = $count . ' ' . $this->elements()->column($id);
        }

        return $label . ' with ' . implode(', ', $ships) . ': expected loot ' . round($plan['loot']) . ' vs cost ' . round($plan['cost'])
            . ' (fuel ' . round($plan['fuel']) . ', losses ' . round($plan['losses']) . ', ratio ' . round($plan['ratio'], 1) . '), impact ' . date('H:i:s', $impact)
            . ($plan['techs_assumed'] ? ', targets research assumed' : '');
    }

    /**
     * A target that will not be attacked: logs the reason and the cooldown. Unknown defense (report without fleet / defense section)
     * instead raises the probe count for the next look; after the third try the target is left alone.
     *
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $report parsed report
     */
    private function noGo(array &$mem, int $pid, int $botId, int $uni, string $label, string $reason, array $report, int $now, int $cooldown = RaidTargetFinder::COOLDOWN): void
    {
        if ($report['fleet'] === null || $report['defense'] === null) {
            $tries = (int) ($mem['targets'][$pid]['spy_try'] ?? 0) + 1;
            $mem['targets'][$pid]['spy_try'] = $tries;
            if ($tries < 3) {
                $this->botLog->log($botId, $uni, 'raid_skip', $label . ': ' . $reason . ', will spy again with more probes');

                return;
            }
        }
        $mem['targets'][$pid]['skip_until'] = $now + $cooldown / (str_starts_with($reason, 'not worth') ? 2 : 1);
        $this->botLog->log($botId, $uni, 'raid_skip', $label . ': ' . $reason);
    }

    /**
     * Scouts (cached for ten minutes) and sends probes to the best targets that have no probe or attack under way.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $personality
     * @return int probe flights sent
     */
    private function scoutAndSpy(BotActions $ctx, array $bot, array $s, array &$mem, array $personality, int $now): int
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        if (count($mem['spies']) >= self::MAX_PENDING_SPIES || $this->probesAtHome($ctx) < 1) {
            return 0;
        }
        if ($now - (int) $mem['scouted_at'] > self::SCOUT_CACHE || $mem['cand'] === []) {
            $this->scout($ctx, $botId, $uni, $s, $mem, $personality, $now);
        }

        $busy = array_merge(array_column($mem['spies'], 'planet'), array_column($mem['attacks'], 'planet'));
        $sent = 0;
        $struck = $this->dueStrikes($mem, $now);
        // a target is looked at again after the spy cooldown, in a running raid streak after the (shorter) streak cooldown
        $cooldown = !empty($mem['streak']) && (int) $mem['streak']['until'] > $now ? $ctx->bot->spyCooldownStreakSeconds() : $ctx->bot->spyCooldownSeconds();
        foreach (array_merge($struck, $mem['cand']) as $target) {
            $pid = (int) $target['planet_id'];
            $known = $mem['targets'][$pid] ?? [];
            $recent = !in_array($target, $struck, true) && ($known['spy_at'] ?? 0) > $now - $cooldown && (int) ($known['spy_try'] ?? 0) === 0;
            if ($sent >= 2 || count($mem['spies']) >= self::MAX_PENDING_SPIES || in_array($pid, $busy, true) || $recent || ($known['skip_until'] ?? 0) > $now) {
                continue;
            }
            if ($this->probeLimitReached($ctx, $known, $now) || $this->fleetCapReached($ctx)) {
                continue;
            }
            if (empty($target['is_bot']) && $this->envelope->refusal($ctx, (int) $target['user_id'], Mission::Spy->value) !== null) {
                continue;
            }
            if ($this->spy($ctx, $botId, $uni, $mem, $target, $now)) {
                $sent++;
                $busy[] = $pid;
            }
        }

        return $sent;
    }

    /**
     * Targets whose missile volley has landed: they are looked at again right away (the spy cooldown does not apply), once.
     *
     * @param array<string, mixed> $mem raid memory (`strikes`), the returned ones are removed
     * @return list<array<string, mixed>> scouted targets
     */
    private function dueStrikes(array &$mem, int $now): array
    {
        $due = [];
        foreach ((array) ($mem['strikes'] ?? []) as $pid => $strike) {
            if ($now >= (int) $strike['at']) {
                $due[] = $strike['target'];
                unset($mem['strikes'][$pid]);
            }
        }

        return $due;
    }

    /**
     * Whether the bot already sent its probes-per-target-and-day quota of probe flights to this target (0 = no quota).
     *
     * @param array<string, mixed> $known memory of the target (`spy_times`)
     */
    private function probeLimitReached(BotActions $ctx, array $known, int $now): bool
    {
        $limit = $ctx->bot->probesPerTargetDay;
        if ($limit < 1) {
            return false;
        }
        $recent = array_filter((array) ($known['spy_times'] ?? []), static fn($t): bool => (int) $t > $now - 86400);

        return count($recent) >= $limit;
    }

    /**
     * Whether the universe-wide limit of bot raid fleets in the air (spying or attacking, `bots_max_fleets`, 0 = none) is reached.
     */
    private function fleetCapReached(BotActions $ctx): bool
    {
        $max = $ctx->bot->maxFleets;
        if ($max < 1) {
            return false;
        }
        $count = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%FLEETS%% f INNER JOIN %%BOT_PLAYERS%% bp ON bp.user_id = f.owner
            WHERE bp.universe = :uni AND f.mission IN (:att, :acs, :spy);',
            [':uni' => $ctx->universe, ':att' => Mission::Attack->value, ':acs' => Mission::Acs->value, ':spy' => Mission::Spy->value],
            'c',
        );

        return $count >= $max;
    }

    /**
     * Looks at the galaxy around the bot's planets and logs what it saw and why some planets are no targets.
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory, `cand` (top eight) and `scouted_at` set
     * @param array<string, mixed> $personality
     */
    private function scout(BotActions $ctx, int $botId, int $uni, array $s, array &$mem, array $personality, int $now): void
    {
        $grudges = $ctx->bot->revenge ? $this->relations->grudges($botId, $now) : [];
        $found = $this->finder->scout($ctx, $s, RaidMemory::withFarm($mem['targets'], $personality), $grudges);
        $mem['cand'] = array_slice($found['candidates'], 0, 8);
        $mem['scouted_at'] = $now;
        $names = array_map(static fn(array $c): string => $c['name'] . ' ' . $c['coords'] . ($c['activity_minutes'] === null ? '' : ' (active ' . $c['activity_minutes'] . ' min ago)') . ' ' . $c['score'], array_slice($mem['cand'], 0, 3));
        $this->botLog->log($botId, $uni, 'raid_scout', $found['seen'] . ' planets in ' . $found['systems'] . ' systems, ' . count($found['candidates']) . ' possible targets' . ($names === [] ? '' : ': ' . implode(', ', $names)));
        foreach ($found['skipped'] as $reason => $labels) {
            $this->botLog->log($botId, $uni, 'raid_skip', $reason . ': ' . count($labels) . ' (' . implode(', ', array_slice($labels, 0, 3)) . ')');
        }
    }

    /**
     * Sends probes to a target from the nearest planet that has them. Probe count: from what the bot knows (the target's spy tech
     * from an earlier report, the game's visibility rule for fleet and defense), else 2, 3, 6, 12 by the number of unclear reports so far.
     *
     * @param array<string, mixed> $mem raid memory
     * @param array<string, mixed> $target scouted target
     * @return bool whether probes were sent
     */
    private function spy(BotActions $ctx, int $botId, int $uni, array &$mem, array $target, int $now): bool
    {
        $e = $this->elements();
        $pid = (int) $target['planet_id'];
        $n = $this->probeCount($ctx, $mem['targets'][$pid] ?? []);
        $origin = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($ctx->planets as $id => $planet) {
            if ((int) $planet[$e->column(SHIP_PROBE)] < 1) {
                continue;
            }
            $d = $this->fleetFunctions()->getTargetDistance([$planet['galaxy'], $planet['system'], $planet['planet']], [$target['galaxy'], $target['system'], $target['planet']]);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $origin = $id;
            }
        }
        if ($origin === null) {
            return false;
        }
        $n = min($n, (int) $ctx->planets[$origin][$e->column(SHIP_PROBE)]);
        $sent = $this->dispatcher->send($ctx, $ctx->planets[$origin], [SHIP_PROBE => $n], Mission::Spy->value, $pid, (int) $target['user_id']);
        if (!$sent['ok']) {
            $this->botLog->log($botId, $uni, 'raid_skip', $target['name'] . ' [' . $target['coords'] . ']: spying refused (' . $sent['reason'] . ')');
            $mem['targets'][$pid]['skip_until'] = $now + $ctx->bot->targetCooldownSeconds();

            return false;
        }
        $times = array_values(array_filter((array) ($mem['targets'][$pid]['spy_times'] ?? []), static fn($t): bool => (int) $t > $now - 86400));
        $times[] = $now;
        $mem['targets'][$pid]['spy_times'] = $times;
        $mem['spies'][$sent['id']] = ['planet' => $pid, 'sent' => $now, 'arrive' => $sent['arrival'], 'target' => $target, 'n' => $n];
        $this->botLog->log($botId, $uni, 'raid_spy', $n . ' probes to ' . $target['name'] . ' [' . $target['coords'] . '], report at ' . date('H:i:s', $sent['arrival']));

        return true;
    }

    /**
     * Probes for a look at a target. Known target spy tech t and own s: the game shows the fleet with n0 = +-((s-t)^2) probes and the
     * defense with n0 + SPY_VIEW_FACTOR (negative n0 when s is higher), so the bot sends n0 + SPY_VIEW_FACTOR, at least 2. Unknown t: a
     * growing guess by the number of unclear reports.
     *
     * @param array<string, mixed> $known memory of the target (`tspy`, `spy_try`)
     */
    public function probeCount(BotActions $ctx, array $known): int
    {
        $own = max(1, (int) $ctx->user[$this->elements()->column(SPY_TECH)]);
        if (isset($known['tspy'])) {
            $t = max(1, (int) $known['tspy']);
            $base = ($own > $t ? -1 : 1) * pow(abs($own - $t) * SPY_DIFFENCE_FACTOR, 2);

            return max(2, (int) ceil($base + SPY_VIEW_FACTOR));
        }

        return [2, 3, 6, 12][min(3, (int) ($known['spy_try'] ?? 0))];
    }
}
