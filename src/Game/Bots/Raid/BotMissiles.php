<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Interplanetary missiles against the defense of a target that blocks a raid. Only with the knob `missiles`, and only when the bot has
 * a silo (level 4), the impulse drive, missiles on a planet of the same galaxy within the missile range, and a spy report that shows the
 * target's defense. The bot works out with the game's own hit rule (MissionCaseMIP: attack of the missiles with the weapons tech,
 * the structure of every defense type with the defender's defense tech, primary target first) how few missiles clear enough defense for
 * the raid planner to find a winning, profitable plan on the weakened target. Interceptor missiles are only counted when the report
 * shows them. The strike goes through RaidDispatcher::sendMissiles() (protection, bash and envelope rules of an attack); after the
 * impact the target is spied again like any other and attacked only when the fresh report says so.
 */
class BotMissiles
{
    use GameUtilities;

    /** Most missiles sent in one volley. */
    private const MAX_VOLLEY = 60;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidPlanner $planner,
        private readonly RaidDispatcher $dispatcher,
        private readonly BotEnvelope $envelope,
    ) {}

    /**
     * Fires the fewest missiles that make the raid possible, if there are such. Returns a log text when a volley left.
     *
     * @param array<string, mixed> $report parsed spy report
     * @param array<string, mixed> $target scouted target
     * @param array<string, mixed> $settings profile settings
     * @param array<string, mixed> $mem raid memory (`strikes`: planet id => `at`, `target`), changed in place
     */
    public function strike(BotActions $ctx, array $report, array $target, array $settings, array &$mem, int $now): ?string
    {
        if (!$ctx->bot->missiles || $report['defense'] === null || $report['defense'] === [] || !empty($target['is_bot'])) {
            return null;
        }
        $origin = $this->launchBase($ctx, $target);
        if ($origin === null || $this->envelope->refusal($ctx, (int) $target['user_id'], \App\Game\Enum\Mission::Attack->value) !== null) {
            return null;
        }
        $stock = (int) $ctx->planets[$origin][$this->elements()->column(INTERPLANETARY_MISSILE)];
        $primary = $this->primaryTarget($report['defense']);
        for ($n = 1; $n <= min($stock, self::MAX_VOLLEY); $n++) {
            $weakened = ['defense' => $this->afterVolley($ctx, $report, $n, $primary)] + $report;
            if ($this->planner->plan($ctx, $weakened, $target, $settings)['ok']) {
                $sent = $this->dispatcher->sendMissiles($ctx, $ctx->planets[$origin], $n, $primary, (int) $target['planet_id'], (int) $target['user_id']);
                if (!$sent['ok']) {
                    return null;
                }
                $mem['strikes'][(int) $target['planet_id']] = ['at' => $sent['arrival'] + 15, 'target' => $target];

                return $n . ' missiles at the defense of ' . $target['name'] . ' [' . $target['coords'] . '], impact ' . date('H:i:s', $sent['arrival']);
            }
        }

        return null;
    }

    /**
     * The planet with missiles in range of the target (the one with the most), null when there is none.
     *
     * @param array<string, mixed> $target scouted target
     * @return int|null planet id
     */
    private function launchBase(BotActions $ctx, array $target): ?int
    {
        $e = $this->elements();
        $range = (int) $ctx->user[$e->column(117)];
        $best = null;
        foreach ($ctx->planets as $id => $planet) {
            $have = (int) $planet[$e->column(INTERPLANETARY_MISSILE)];
            $row = ['galaxy' => $target['galaxy'], 'system' => $target['system'], 'planet' => $target['planet']];
            if ($have < 1 || (int) $planet[$e->column(SILO)] < 4 || $range < 1 || (int) $planet['galaxy'] !== (int) $target['galaxy'] || !$this->fleetFunctions()->inMissileRange($planet, $row, $range)) {
                continue;
            }
            if ($best === null || $have > (int) $ctx->planets[$best][$e->column(INTERPLANETARY_MISSILE)]) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * The defense type to aim at first: the one that shoots the most (attack times count).
     *
     * @param array<int, int> $defense id => count
     */
    private function primaryTarget(array $defense): int
    {
        $e = $this->elements();
        $best = 0;
        $bestPower = 0.0;
        foreach ($defense as $id => $count) {
            $power = $count * $e->combat($id)->attack;
            if ($id !== INTERCEPTOR_MISSILE && $power > $bestPower) {
                $bestPower = $power;
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * The defense that is left after `$missiles` hit (MissionCaseMIP::calculateMIPAttack(): every missile does its attack times
     * 1 + 0.1 weapons tech; a unit takes (metal + crystal) x (1 + 0.1 defense tech) / 10; primary target first, then the game's list).
     *
     * @param array<string, mixed> $report parsed report (`defense`, `techs`)
     * @return array<int, int> id => count
     */
    public function afterVolley(BotActions $ctx, array $report, int $missiles, int $primary): array
    {
        $e = $this->elements();
        $interceptors = (int) ($report['interceptors'] ?? 0);
        $left = $report['defense'];
        $hits = $missiles - $interceptors;
        if ($hits <= 0) {
            return $left;
        }
        $defTech = $report['techs'] !== null ? (int) ($report['techs'][DEFENCE_TECH] ?? 0) : (int) $ctx->user[$e->column(DEFENCE_TECH)] + 1;
        $damage = $hits * $e->combat(INTERPLANETARY_MISSILE)->attack * (1 + 0.1 * (int) $ctx->user[$e->column(MILITARY_TECH)]);
        $order = array_diff(array_merge($primary > 0 ? [$primary] : [], $e->ids('defense')), [INTERCEPTOR_MISSILE]);
        foreach (array_unique($order) as $id) {
            if (empty($left[$id])) {
                continue;
            }
            $structure = ($e->cost($id)->metal + $e->cost($id)->crystal) * (1 + 0.1 * $defTech) / 10;
            $kills = min($left[$id], (int) floor($damage / max(1.0, $structure)));
            $damage -= $kills * $structure;
            $left[$id] -= $kills;
            if ($left[$id] <= 0) {
                unset($left[$id]);
            }
            if ($damage <= 0) {
                break;
            }
        }

        return $left;
    }
}
