<?php

namespace App\Game\Bots;

use App\Game\Bots\Raid\BotRaids;
use App\Game\Bots\Raid\BotRecycling;
use App\Game\Bots\Raid\RaidMemory;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\FlyingFleetHandler;
use App\Game\Core\GameData;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Drives the bots: decides when a bot is "online", runs its session ticks and
 * lets the brain pick actions. All gameplay decisions are local (no LLM).
 */
class BotEngine
{
    use GameUtilities;

    /** Seconds of one tick run after which the bots stop asking the LLM (they use their local texts). */
    private const LLM_BUDGET = 20;

    /** Seconds of one tick run after which no further bot is started. */
    private const TICK_BUDGET = 45;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotProfiles $profiles,
        private readonly BotBrain $brain,
        private readonly BotSocial $social,
        private readonly BotPersona $persona,
        private readonly BotLog $botLog,
        private readonly FlyingFleetHandler $fleetHandler,
        private readonly BotRaids $raids,
        private readonly BotLogistics $logistics,
        private readonly BotExpeditions $expeditions,
        private readonly BotRecycling $recycling,
    ) {}

    /** Writes an entry to the bot activity log (see BotLog). */
    public function log(int $userId, int $universe, string $action, string $detail = ''): void
    {
        $this->botLog->log($userId, $universe, $action, $detail);
    }

    /**
     * Makes $universe the current universe of this process: fleet functions, module checks and
     * the unit data (game vars) follow Universe::current(), which is the game state's universeCurrent.
     */
    public function useUniverse(int $universe): void
    {
        $current = $this->state->universeCurrent;
        if ((int) $current === $universe && $this->elements()->all() !== []) {
            return;
        }

        $this->state->universeCurrent = $universe;
        (new GameData($this->db, $this->configs, $this->state))->load();
    }

    /**
     * Random integer in the inclusive range [min, max] given as a two-element profile setting (order-insensitive, default 1).
     *
     * @param array<mixed> $range
     */
    private function roll(array $range): int
    {
        $min = (int) ($range[0] ?? 1);
        $max = (int) ($range[1] ?? $min);

        return random_int(min($min, $max), max($min, $max));
    }

    /**
     * Processes up to `$limit` bots of the universe whose `next_tick` is due (oldest first), after running the due
     * fleet events of bot fleets. Does nothing unless the universe is open (STATUS_OPEN). An exception in one bot is
     * logged ('error' in the bot log), that bot is postponed by 15 minutes, and the loop continues. Roughly every 200th
     * call purges bot log entries older than 14 days.
     *
     * @return int number of processed bots
     */
    public function tick(int $universe, int $limit): int
    {
        $db = $this->db;
        $now = time();

        $config = $this->configs->get($universe);
        if ($config->uni_status != STATUS_OPEN) {
            return 0;
        }

        $this->useUniverse($universe);
        $this->processFleetEvents($universe);

        $bots = $db->select(
            'SELECT * FROM %%BOT_PLAYERS%% WHERE universe = :universe AND next_tick <= :now ORDER BY next_tick ASC LIMIT :limit;',
            [':universe' => $universe, ':now' => $now, ':limit' => $limit],
        );

        $profiles = [];
        $processed = 0;
        // time budget of the run: LLM calls stop after LLM_BUDGET seconds, no new bot is started after TICK_BUDGET (it stays due for the next run)
        $started = microtime(true);
        $this->persona->limitLlmUntil($started + self::LLM_BUDGET);
        foreach ($bots as $bot) {
            if (microtime(true) - $started > self::TICK_BUDGET) {
                break;
            }
            $processed++;
            try {
                if (!isset($profiles[$bot['profile_id']])) {
                    $profiles[$bot['profile_id']] = $this->profiles->load((int) $bot['profile_id']);
                }
                $profile = $profiles[$bot['profile_id']];
                if ($profile === null) {
                    throw new \Exception('profile ' . $bot['profile_id'] . ' missing');
                }
                $this->processBot($bot, $profile, $now);
            } catch (Throwable $e) {
                try {
                    $db->rollback();
                } catch (Throwable $ignored) {
                }
                try {
                    $this->log((int) $bot['user_id'], $universe, 'error', get_class($e) . ': ' . $e->getMessage());
                    $db->update(
                        'UPDATE %%BOT_PLAYERS%% SET next_tick = :next WHERE user_id = :id;',
                        [':next' => $now + 900, ':id' => $bot['user_id']],
                    );
                } catch (Throwable $ignored) {
                }
                error_log('BotEngine bot ' . $bot['user_id'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        $this->persona->limitLlmUntil(null);

        if (random_int(1, 200) === 1) {
            $db->delete('DELETE FROM %%BOT_LOG%% WHERE `time` < :t;', [':t' => $now - 14 * 86400]);
        }

        return $processed;
    }

    /**
     * Bot fleets (expeditions, colony ships) are normally resolved when a human loads a page.
     * Do the same claim + run here (as GameBootstrap does) so they also work on quiet servers.
     */
    private function processFleetEvents(int $universe): void
    {
        $db = $this->db;
        $due = (int) $db->selectSingle(
            'SELECT COUNT(*) as count FROM %%FLEETS_EVENT%% fe
            INNER JOIN %%FLEETS%% f ON f.id = fe.fleetID
            INNER JOIN %%BOT_PLAYERS%% b ON b.user_id = f.owner
            WHERE fe.`lock` IS NULL AND fe.`time` <= :time AND fe.universe = :universe;',
            [':time' => time(), ':universe' => $universe],
            'count',
        );
        if ($due === 0) {
            return;
        }

        $token = getRandomString();
        // deadlocks / lock wait timeouts repeat the transaction (Database::transactional), other errors are thrown
        $claimed = $db->transactional(function () use ($db, $token, $universe): int {
            $db->update(
                'UPDATE %%FLEETS_EVENT%% SET `lock` = :token
                WHERE `lock` IS NULL AND `time` <= :time AND `universe` = :universe;',
                [':token' => $token, ':time' => time(), ':universe' => $universe],
            );

            return $db->rowCount();
        });

        if ($claimed > 0) {
            try {
                // every event runs in its own transaction and a failing one is quarantined (FlyingFleetHandler::run)
                $this->fleetHandler->setToken($token);
                $this->fleetHandler->run();
            } finally {
                $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `lock` = :token;', [':token' => $token]);
            }
        }
    }

    /**
     * One tick of one bot. Skill grows with the bot's age (`skill_start` + days * `skill_gain_per_day`, capped at
     * `skill_max`). Outside a session the bot first rolls whether it comes online this tick (rollActive(), hourly
     * activity profile); on success a session starts: length from `session_minutes`, planned action count from
     * `actions_per_session`, plus rolled special actions (expedition, colonize; chances in percent). While online it
     * bumps `onlinetime`, runs 1-3 actions in one DB transaction (rolled back on error), then the social behaviour
     * (isolated: its errors are only logged). Finally it schedules the next tick (60-240 s later inside a session,
     * `check_interval` minutes after the session ended) and persists skill, personality and session state.
     *
     * @param array<string, mixed> $bot     row of {prefix}bot_players
     * @param array<string, mixed> $profile profile with its `settings` (see BotProfiles)
     * @param int                  $now     unix timestamp of this tick
     */
    private function processBot(array $bot, array $profile, int $now): void
    {
        $db = $this->db;
        $universe = (int) $bot['universe'];
        $userId = (int) $bot['user_id'];
        $s = $profile['settings'];
        $personality = json_decode((string) $bot['personality'], true);
        if (!is_array($personality)) {
            $personality = [];
        }

        // the universe's bot knobs (with this bot's own preset / aggression) laid over the profile
        $knobs = BotSettings::fromConfig($this->configs->get($universe))->forBot((array) ($personality['_cfg'] ?? []));
        $s = $knobs->applyToProfile($s, $userId, $this->points($knobs, $userId));
        $tz = $this->timezone($knobs, $personality, $userId);

        $skill = min((float) $s['skill_max'], (float) $s['skill_start'] + max(0, ($now - (int) $bot['created_at']) / 86400) * (float) $s['skill_gain_per_day']);

        $session = $personality['_s'] ?? ['left' => 0];
        $sessionUntil = (int) $bot['session_until'];
        $inSession = $sessionUntil > $now;
        $raidsOn = $this->raids->enabled($universe);

        // a raid task (re-spy, hit to settle) woke the bot before its regular next check: do only that, unless it starts a raid streak
        $regular = (int) ($personality['_reg'] ?? 0);
        if (!$raidsOn || $regular <= $now) {
            unset($personality['_reg']);
        }
        if ($raidsOn && !$inSession && $regular > $now) {
            if (!$this->wakeTick($bot, $s, $personality, $now)) {
                $this->save($bot, $skill, $personality, $this->scheduleNext($personality, $regular, $now), $sessionUntil);

                return;
            }
            $inSession = true;
            $sessionUntil = (int) RaidMemory::of($personality)['streak']['until'];
            $session = ['left' => 1, 'expo' => false, 'colo' => false, 'social' => true, 'raid' => true];
            unset($personality['_reg']);
            $this->log($userId, $universe, 'login', 'raid streak, online until ' . date('H:i', $sessionUntil));
        }

        if (!$inSession) {
            if (!$this->rollActive($s, $now, (int) $bot['created_at'], $tz)) {
                $this->save($bot, $skill, $personality, $this->scheduleNext($personality, $now + $this->roll($s['check_interval']) * 60, $now), $sessionUntil);

                return;
            }

            $sessionUntil = $now + $this->roll($s['session_minutes']) * 60;
            $session = $this->newSession($s, $personality, $raidsOn);
            $db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $userId]);
            $this->log($userId, $universe, 'login', 'session ' . round(($sessionUntil - $now) / 60) . ' min, ' . $session['left'] . ' actions planned' . (!empty($session['raid']) ? ', going raiding' : ''));
        } else {
            $db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $userId]);
        }

        $user = $this->playSession($bot, $s, $personality, $skill, $session, $userId, $universe, $raidsOn);
        $sessionUntil = $this->applyRaidSession($session, $personality, $sessionUntil, $now);

        // --- social behaviour, isolated from gameplay errors ---
        if ($user !== null) {
            try {
                $this->social->act($bot, $user, $profile['settings']);
            } catch (Throwable $e) {
                $this->log($userId, $universe, 'social_error', get_class($e) . ': ' . $e->getMessage());
            }
        }

        $next = $now + random_int(60, 240);
        $wake = $raidsOn ? RaidMemory::nextWake($personality) : null;
        $personality['_s'] = $session;
        if ($session['left'] <= 0 || $next >= $sessionUntil) {
            $personality['_s']['left'] = 0;
            $offline = $this->roll($s['check_interval']) * 60;
            // the fleet is saved for the time the bot really expects to stay away (checks fail at night), not for one check interval
            $this->fleetSaveAtLogout($bot, $s, $personality, BotSchedule::expectedOffline($s, $now, (int) $bot['created_at'], $offline, $tz));
            $this->save($bot, $skill, $personality, $this->scheduleNext($personality, $now + $offline, $now), $now);
            $this->log($userId, $universe, 'logout', 'session finished');
        } else {
            $this->save($bot, $skill, $personality, $wake !== null ? min($next, max($now, $wake)) : $next, $sessionUntil);
        }
    }

    /** Total points of a bot, only looked up when the warmonger trait is on (it is what decides it), else 0. */
    private function points(BotSettings $knobs, int $userId): float
    {
        if (!$knobs->warmonger) {
            return 0.0;
        }

        return (float) $this->db->selectSingle('SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;', [':id' => $userId], 'total_points');
    }

    /**
     * The game context of one bot with the knobs of its universe, overlaid with the preset and aggression the admin set for this bot
     * (personality `_cfg`).
     *
     * @param array<string, mixed> $personality bot personality
     */
    private function context(int $universe, array $personality): BotActions
    {
        $ctx = new BotActions($universe, $this->db, $this->configs, $this->state, $this->persona);
        $ctx->bot = $ctx->bot->forBot((array) ($personality['_cfg'] ?? []));

        return $ctx;
    }

    /**
     * State of a session that starts now: planned action count (`left`), whether it tries an expedition and a colonization (chances
     * in percent, expedition scaled by the bot's `expo_mult`) and goes raiding (`raid`).
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality bot personality
     * @return array<string, mixed>
     */
    private function newSession(array $s, array $personality, bool $raidsOn): array
    {
        $mult = (float) ($personality['expo_mult'] ?? 1.0);

        return [
            'left' => max(1, $this->roll($s['actions_per_session'])),
            'expo' => mt_rand(1, 10000) <= (float) $s['expedition_chance'] * $mult * 100,
            'colo' => mt_rand(1, 10000) <= (float) $s['colonize_chance'] * 100,
            'social' => true,
            'raid' => $raidsOn && $this->raids->rollSession($s),
        ];
    }

    /**
     * What the raid turn asks of the session: a longer session (probes under way, raid streak running) and the end of it when the streak
     * ended. A running streak keeps at least one action left, so the session does not close under it.
     *
     * @param array<string, mixed> $session session state; the raid flags are consumed
     * @param array<string, mixed> $personality bot personality (raid memory)
     * @return int the session end (unix time) after the raid's request
     */
    private function applyRaidSession(array &$session, array $personality, int $sessionUntil, int $now): int
    {
        if (!empty($session['extend_until'])) {
            $sessionUntil = max($sessionUntil, (int) $session['extend_until']);
        }
        if (!empty($session['streak_ended'])) {
            $session['left'] = 0;
        } elseif (RaidMemory::streakActive($personality, $now)) {
            $session['left'] = max((int) $session['left'], 1);
        }
        unset($session['extend_until'], $session['streak_ended']);

        return $sessionUntil;
    }

    /**
     * Session end: with the profile's `fleetsave_chance` the fleet and most resources leave on a slow flight that brings them home after the
     * expected offline time (BotLogistics::fleetSave()). Own transaction; a failure is only logged, the logout goes on.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality bot personality
     * @param int $offline expected seconds until the bot is online again
     */
    private function fleetSaveAtLogout(array $bot, array $s, array $personality, int $offline): void
    {
        $db = $this->db;
        $userId = (int) $bot['user_id'];
        $db->startTransaction();
        try {
            $ctx = $this->context((int) $bot['universe'], $personality);
            if (!$ctx->load($userId)) {
                $db->rollback();

                return;
            }
            $ctx->reservedSlots = 0;
            $sent = $this->logistics->fleetSave($ctx, $s, $offline, 'session end');
            $db->commit();
            $this->countActions($userId, $sent);
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
            }
            $this->log($userId, (int) $bot['universe'], 'error', 'fleetsave: ' . $e->getMessage());
        }
    }

    /**
     * The next tick of a bot that is not in a session: its regular check time, or earlier when a raid task (re-spy before an
     * impact, settling a hit) is due first; then the regular time is remembered in `_reg` and the early tick only does the raid task.
     *
     * @param array<string, mixed> $personality `_reg` set or removed
     */
    private function scheduleNext(array &$personality, int $regularNext, int $now): int
    {
        $wake = RaidMemory::nextWake($personality);
        if ($wake !== null && $wake < $regularNext) {
            $personality['_reg'] = $regularNext;

            return max($now, $wake);
        }
        unset($personality['_reg']);

        return $regularNext;
    }

    /**
     * Early tick of a bot outside its session for a raid task: marks it online (it looks at its fleets), settles landed attacks and
     * runs the due re-spy checks in one transaction.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality changed in place
     * @return bool whether a raid streak is running afterwards (the bot then stays online)
     */
    private function wakeTick(array $bot, array $s, array &$personality, int $now): bool
    {
        $db = $this->db;
        $userId = (int) $bot['user_id'];
        $db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $userId]);
        $db->startTransaction();
        try {
            $ctx = $this->context((int) $bot['universe'], $personality);
            if (!$ctx->load($userId)) {
                $db->rollback();
                throw new \Exception('user row missing');
            }
            $actions = $this->raids->maintain($ctx, $bot, $s, $personality, $now);
            $db->commit();
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
        $this->countActions($userId, $actions);

        return RaidMemory::streakActive($personality, $now);
    }

    /** Counts raid actions in `actions_total` / `last_action_at` of the bot. */
    private function countActions(int $userId, int $count): void
    {
        if ($count > 0) {
            $this->db->update(
                'UPDATE %%BOT_PLAYERS%% SET actions_total = actions_total + :n, last_action_at = :t WHERE user_id = :id;',
                [':n' => $count, ':t' => time(), ':id' => $userId],
            );
        }
    }

    /**
     * The gameplay of one tick inside one transaction: loads the bot's user, renames default colonies to the persona's
     * names and plays 1-3 steps (at most the actions left of the session). Any failure rolls back and is rethrown.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $personality personality of the bot (`_raid` raid memory and `farm` list are updated in place)
     * @param array<string, mixed> $session session state (`left` actions, flags), updated in place
     * @param bool $raidsOn whether bot raids are enabled in the universe (the raid turn follows the ordinary steps)
     * @return array<string, mixed>|null the user row after the steps
     */
    private function playSession(array $bot, array $s, array &$personality, float $skill, array &$session, int $userId, int $universe, bool $raidsOn = false): ?array
    {
        $db = $this->db;
        $user = null;
        $db->startTransaction();
        try {
            $ctx = $this->context($universe, $personality);
            if (!$ctx->load($userId)) {
                $db->rollback();
                throw new \Exception('user row missing');
            }

            $ctx->reservedSlots = (int) ($s['expedition_slots'] ?? 0);
            $this->expeditions->settle($bot, $personality, time());
            $persona = !empty($bot['persona']) ? json_decode((string) $bot['persona'], true) : null;
            if (is_array($persona)) {
                $ctx->renameDefaultColonies($bot, $persona);
            }

            // in a raid streak the bot mostly raids: at most one ordinary action, and it does not use up the session's actions
            $streak = $raidsOn && RaidMemory::streakActive($personality, time());
            $actions = $streak ? random_int(0, 1) : min((int) $session['left'], random_int(1, 3));
            for ($i = 0; $i < $actions; $i++) {
                $this->step($ctx, $s, $personality, $skill, $session, $userId, $universe);
                $session['left'] -= $streak ? 0 : 1;
            }
            $raidActions = $raidsOn ? $this->raids->play($ctx, $bot, $s, $personality, $session, time()) : 0;
            $raidActions += $this->logistics->threats($ctx, $s, $personality, time());
            $raidActions += $this->recycling->play($ctx, $s, $personality, time());
            $user = $ctx->user;
            $db->commit();
            $this->countActions($userId, $raidActions);
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }

        return $user;
    }

    /**
     * One action: first the special actions rolled at session start (expedition, then colonization; each is tried only
     * once and ends the step when it succeeded), otherwise BotBrain picks a build, research, ship, defense, mistake or
     * wait decision and BotActions executes it. A rejected decision is logged as 'wait'.
     *
     * @param array<string, mixed> $session session state (`left` actions, `expo`/`colo` flags), updated in place
     * @param array<mixed> $personality
     * @param array<mixed> $s
     */
    private function step(BotActions $ctx, array $s, array &$personality, float $skill, array &$session, int $userId, int $universe): void
    {
        // optional special actions rolled at session start
        if (!empty($session['expo'])) {
            $session['expo'] = false;
            $detail = $this->expeditions->send($ctx, $personality, time());
            if ($detail !== null) {
                $this->done($userId, $universe, 'expedition', $detail);

                return;
            }
        }
        if (!empty($session['colo'])) {
            $session['colo'] = false;
            $detail = $ctx->colonize();
            if ($detail !== null) {
                $this->done($userId, $universe, 'colonize', $detail);

                return;
            }
        }

        $decision = $this->brain->choose($ctx, $s, $personality, $skill);
        if ($decision['kind'] === 'wait') {
            $this->log($userId, $universe, 'wait', (string) ($decision['note'] ?? ''));
            // saving for a goal on one planet: bring resources from the others
            if (!empty($decision['goal'])) {
                $this->logistics->consolidate($ctx, $decision['goal']);
            }

            return;
        }

        $detail = match ($decision['type']) {
            'build' => $ctx->build($decision['planet'], $decision['element']),
            'research' => $ctx->research($decision['planet'], $decision['element']),
            'ship', 'defense' => $ctx->units($decision['planet'], $decision['element'], $decision['count']),
            default => null,
        };

        if ($detail === null) {
            $this->log($userId, $universe, 'wait', 'rejected ' . $decision['label']);

            return;
        }

        $action = $decision['kind'] === 'mistake' ? 'mistake' : $decision['type'];
        $this->done($userId, $universe, $action, ($decision['kind'] === 'mistake' ? $decision['type'] . ': ' : '') . $detail);
    }

    /** Logs a successful action and counts it in `actions_total` / `last_action_at` of the bot. */
    private function done(int $userId, int $universe, string $action, string $detail): void
    {
        $this->log($userId, $universe, $action, $detail);
        $this->db->update(
            'UPDATE %%BOT_PLAYERS%% SET actions_total = actions_total + 1, last_action_at = :t WHERE user_id = :id;',
            [':t' => time(), ':id' => $userId],
        );
    }

    /**
     * The bot's personal hour offset against the server clock (its own time zone): kept in the personality (`_tz`), derived from the
     * bot id when missing (BotSettings::timezoneOffset()).
     *
     * @param array<string, mixed> $personality `_tz` is set when the spread knob allows an offset
     */
    private function timezone(BotSettings $knobs, array &$personality, int $userId): int
    {
        $stored = isset($personality['_tz']) ? (int) $personality['_tz'] : null;
        $tz = $knobs->timezoneOffset($userId, $stored);
        if ($knobs->timezoneSpreadHours > 0) {
            $personality['_tz'] = $tz;
        }

        return $tz;
    }

    /**
     * Whether the bot comes online now: `activity` holds 24 probabilities (0-1) by hour of day of the bot's own time; after
     * `pattern_shift_days` the daily rhythm moves by `pattern_shift_hours`, so bots do not keep the same schedule forever (BotSchedule).
     *
     * @param array<mixed> $s
     */
    private function rollActive(array $s, int $now, int $createdAt, int $tzOffset = 0): bool
    {
        return mt_rand(1, 10000) <= BotSchedule::probability($s, $now, $createdAt, $tzOffset) * 10000;
    }

    /**
     * Persists skill, personality JSON, `next_tick` and `session_until` of the bot (unix timestamps).
     *
     * @param array<string, mixed> $bot
     * @param array<mixed> $personality
     */
    private function save(array $bot, float $skill, array $personality, int $nextTick, int $sessionUntil): void
    {
        $this->db->update(
            'UPDATE %%BOT_PLAYERS%% SET skill = :skill, personality = :personality, next_tick = :next, session_until = :until WHERE user_id = :id;',
            [
                ':skill' => $skill,
                ':personality' => json_encode($personality),
                ':next' => $nextTick,
                ':until' => $sessionUntil,
                ':id' => $bot['user_id'],
            ],
        );
    }
}
