<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Decision logic of a bot: builds a list of everything that is currently
 * possible, scores it like an experienced human would, then applies
 * personality, noise, skill and mistake rolls.
 *
 * A decision is an array:
 *   kind      'act' | 'mistake' | 'wait'
 *   type      'build' | 'research' | 'ship' | 'defense'   (not for wait)
 *   planet    planet id
 *   element   element id
 *   count     amount (ships/defense)
 *   cat       weight category
 *   note      free text for the log (wait reason)
 */
class BotBrain
{
    use GameUtilities;

    private const BUILDINGS = [1, 2, 3, 4, 12, 14, 15, 21, 22, 23, 24, 31, 33];

    /** research id => [base score, softcap] (score = base * cap / (cap + level)) */
    private const RESEARCH = [
        113 => [70, 6], 115 => [55, 4], 108 => [55, 6], 106 => [40, 3], 117 => [48, 5], 124 => [58, 5],
        114 => [30, 4], 118 => [25, 3], 109 => [38, 5], 110 => [36, 5], 111 => [36, 5], 120 => [20, 4],
        121 => [14, 3], 122 => [10, 3], 131 => [34, 5], 132 => [34, 5], 133 => [32, 5], 123 => [12, 2],
    ];

    /** defense id => [base score, softcap] */
    private const DEFENSE = [401 => [28, 30], 402 => [24, 20], 403 => [16, 12], 404 => [9, 6], 405 => [6, 4], 407 => [14, 1], 408 => [10, 1]];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Picks the next action of a bot. Takes every currently possible action (candidates()), perturbs the scores by
     * `randomness` percent so identical bots diverge, then in this order: with probability mistakeChance() takes a random
     * affordable action marked 'mistake'; 4 % of the time does nothing ('distracted'); otherwise draws among the best three
     * (the lower the skill, the more weight the 2nd and 3rd get). An unaffordable pick means the bot saves up
     * and waits. No candidates also yields a wait decision. Pure decision: nothing is written here.
     *
     * @param array<string, mixed> $settings    normalized profile settings (see BotProfiles)
     * @param array<string, float> $personality per-bot multipliers (`patience`, one per weight category), 1.0 = neutral
     * @param float                $skill       0-1, current skill of the bot
     * @return array<string, mixed> decision, see the class comment
     */
    public function choose(BotActions $ctx, array $settings, array $personality, float $skill): array
    {
        $candidates = $this->candidates($ctx, $settings, $personality, $skill);
        if (empty($candidates)) {
            return ['kind' => 'wait', 'note' => 'nothing to do'];
        }

        // noise so bots with identical state diverge
        $noise = max(0, (float) $settings['randomness']);
        foreach ($candidates as &$c) {
            $c['score'] *= 1 + (mt_rand(-1000, 1000) / 1000) * ($noise / 100);
        }
        unset($c);

        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
        $goal = $this->goal($ctx, $candidates, (float) ($personality['patience'] ?? 1.0));
        if ($goal !== null && !$goal['affordable'] && mt_rand(1, 100) <= (int) ($settings['saving_discipline'] ?? 0)) {
            $kept = array_values(array_filter($candidates, fn($c) => $this->compatibleWithSaving($ctx, $c, $goal)));
            if ($kept === []) {
                return ['kind' => 'wait', 'note' => 'saving for ' . $goal['label'], 'goal' => $goal];
            }
            $candidates = $kept;
        }
        $affordable = array_values(array_filter($candidates, static fn($c) => $c['affordable']));

        // mistake roll: skilled bots (and, with skill gain, bots that grew) err less
        if (!empty($affordable) && mt_rand(1, 10000) <= self::mistakeChance($settings, $skill) * 100) {
            $pick = $affordable[array_rand($affordable)];
            $pick['kind'] = 'mistake';

            return $pick;
        }

        // sometimes a human just does not do anything useful
        if (mt_rand(1, 100) <= 4) {
            return ['kind' => 'wait', 'note' => 'distracted'];
        }

        // weighted pick among the top 3, skill makes the best option dominate
        $top = array_slice($candidates, 0, 3);
        $slack = 1 - max(0.0, min(1.0, $skill));
        $weights = [1.0, $slack * 0.8 + 0.01, $slack * 0.4 + 0.005];
        $sum = 0.0;
        foreach ($top as $i => $_) {
            $sum += $weights[$i];
        }
        $roll = mt_rand() / mt_getrandmax() * $sum;
        $pick = $top[0];
        foreach ($top as $i => $c) {
            $roll -= $weights[$i];
            if ($roll <= 0) {
                $pick = $c;
                break;
            }
        }

        if (!$pick['affordable']) {
            return ['kind' => 'wait', 'note' => 'saving for ' . $pick['label'], 'goal' => $pick];
        }

        $pick['kind'] = 'act';

        return $pick;
    }

    /**
     * The bot's current goal: the building or research with the best value for its cost. Value = raw score (before the waiting
     * discount) divided by 1 + hours that the planet's income needs to pay for it / 12, so a cheap useful step beats an expensive slightly
     * better one, and goals more than 10 hours away are ignored (a player would not wait that long).
     *
     * @param list<array<string, mixed>> $candidates scored candidates
     * @return array<string, mixed>|null the candidate with `hours` added, null when there is none
     */
    public function goal(BotActions $ctx, array $candidates, float $patience): ?array
    {
        $best = null;
        $bestValue = 0.0;
        foreach ($candidates as $c) {
            if (!in_array($c['type'], ['build', 'research'], true) || !isset($c['cost'])) {
                continue;
            }
            $hours = $this->hoursToAfford($ctx, (array) $ctx->planets[$c['planet']], $c['cost']);
            if ($hours > 10 * max(0.5, $patience)) {
                continue;
            }
            $value = $c['raw'] / (1 + $hours / 12);
            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $c + ['hours' => $hours];
            }
        }

        return $best;
    }

    /**
     * Hours the planet's income needs to cover the missing part of a cost (0 when affordable).
     *
     * @param array<string, mixed> $planet planet row
     * @param array<int, int|float> $cost resource id => amount
     */
    private function hoursToAfford(BotActions $ctx, array $planet, array $cost): float
    {
        $e = $this->elements();
        $config = $ctx->config;
        $hours = 0.0;
        foreach ($cost as $rid => $amount) {
            $missing = $amount - floor((float) $planet[$e->column($rid)]);
            if ($missing <= 0) {
                continue;
            }
            $name = $e->column($rid);
            $income = (float) ($planet[$name . '_perhour'] ?? 0) + (float) ($config->{$name . '_basic_income'} ?? 0) * ((float) $config->resource_multiplier / 2500);
            $hours = max($hours, $income > 0 ? $missing / $income : 99.0);
        }

        return $hours;
    }

    /**
     * Whether a candidate may be bought while the bot saves for its goal: it is the goal, or it does not touch the resources the goal still
     * lacks on the goal's planet (at most 8 % of the missing amount), or it spends a resource that is about to overflow (90 % of storage).
     *
     * @param array<string, mixed> $c candidate
     * @param array<string, mixed> $goal the goal candidate
     */
    public function compatibleWithSaving(BotActions $ctx, array $c, array $goal): bool
    {
        if ($c['type'] === $goal['type'] && $c['element'] === $goal['element'] && $c['planet'] === $goal['planet']) {
            return true;
        }
        if (!isset($c['cost'])) {
            return true;
        }
        if ($c['planet'] !== $goal['planet']) {
            return true; // another planet pays from its own stock
        }
        $e = $this->elements();
        $goalPlanet = $ctx->planets[$goal['planet']];
        $planet = $ctx->planets[$c['planet']];
        foreach ($goal['cost'] as $rid => $amount) {
            $missing = $amount - floor((float) $goalPlanet[$e->column($rid)]);
            $max = (float) ($planet[$e->column($rid) . '_max'] ?? 0);
            $overflowing = $max > 0 && (float) $planet[$e->column($rid)] >= 0.9 * $max;
            if ($missing > 0 && !$overflowing && (float) ($c['cost'][$rid] ?? 0) > 0.08 * $missing) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mistake probability in percent (0-100). Bots with a skill gain (e.g. the noob profile) make `mistake_chance` percent
     * mistakes at `skill_start` and fall linearly to 0 at `skill_max`; others keep the constant base value.
     *
     * @param array<string, mixed> $settings
     */
    public static function mistakeChance(array $settings, float $skill): float
    {
        $base = (float) $settings['mistake_chance'];
        $gain = (float) $settings['skill_gain_per_day'];
        if ($gain > 0) {
            $range = max(0.01, (float) $settings['skill_max'] - (float) $settings['skill_start']);

            return max(0.0, min(100.0, $base * (((float) $settings['skill_max'] - $skill) / $range)));
        }

        return max(0.0, min(100.0, $base));
    }

    /**
     * Lists everything the bot could do now, each with a score: buildings (mines kept in a human-like ratio, energy
     * when the balance is negative, storage when nearly full, facilities by milestone; only if the build queue is
     * free), research (base score with diminishing returns per level; needs a lab and an empty research queue) and
     * ships/defense (when units can be queued; colony ship only if a colony is possible). The score is multiplied by the
     * profile weight and personality factor of the category (sqrt of the relative weight), and by a waiting penalty
     * 1 / (1 + hoursToAfford / (3 * patience)) where hours come from the planet's hourly income (production plus the
     * universe's basic income). `affordable` says whether the price is covered right now.
     *
     * @param array<mixed> $personality
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>> decisions with `type`, `planet`, `element`, `count`, `cat`, `score`, `affordable`, `label`
     */
    private function candidates(BotActions $ctx, array $settings, array $personality, float $skill): array
    {
        $e = $this->elements();
        $config = $ctx->config;
        $user = $ctx->user;
        $patience = (float) ($personality['patience'] ?? 1.0);

        $weights = $settings['weights'];
        $avg = max(1.0, array_sum($weights) / max(1, count($weights)));
        $wf = static function (string $cat) use ($weights, $avg, $personality): float {
            return sqrt(max(0.5, (float) ($weights[$cat] ?? $avg)) / $avg) * (float) ($personality[$cat] ?? 1.0);
        };

        $out = [];
        $planetCount = count($ctx->planets);
        $maxPlanets = $this->planetPositions()->maxPlanetCount($user);
        $colonyOk = $planetCount < $maxPlanets && $ctx->canColonize();

        foreach ($ctx->planets as $pid => $planet) {
            $income = [];
            foreach ([901 => 'metal', 902 => 'crystal', 903 => 'deuterium'] as $rid => $name) {
                $income[$rid] = (float) $planet[$name . '_perhour']
                    + (float) $config->{$name . '_basic_income'} * ((float) $config->resource_multiplier / 2500);
            }
            // penalty by hours needed to afford, patient bots tolerate more waiting
            $penalty = static function (array $cost) use ($planet, $e, $income, $patience): array {
                $ttf = 0.0;
                foreach ($cost as $rid => $amount) {
                    if (!isset($income[$rid])) {
                        continue;
                    }
                    $missing = $amount - floor((float) $planet[$e->column($rid)]);
                    if ($missing > 0) {
                        $ttf = max($ttf, $income[$rid] > 0 ? $missing / $income[$rid] : 99.0);
                    }
                }

                return [$ttf <= 0, 1 / (1 + $ttf / (3 * max(0.3, $patience)))];
            };
            $name = $ctx->planetLabel($planet);

            $this->buildingCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf);
            $this->researchCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf, $skill, $settings);
            $this->unitCandidates($out, $ctx, $planet, $pid, $name, $penalty, $wf, $skill, $colonyOk, $settings);
        }

        return $out;
    }

    /**
     * Score and category of building an element one level higher on a planet: mines follow the reference mine level,
     * energy buildings the energy balance, storage the fill level, facilities fixed targets (robotics up to half the
     * metal mine, ...). Elements the bot does not build give null.
     *
     * @param array<string, mixed> $planet planet row
     * @param int $level the level that would be built
     * @param float $bal energy balance
     * @param int $metal metal mine level
     * @param int $crystal crystal mine level
     * @param int $deut deuterium synthesizer level
     * @param int $ref reference mine level the mines are balanced against
     * @param int $free free fields on the planet
     * @return array{0: int|float, 1: string}|null score and category
     */
    private function buildingScore(int $el, array $planet, int $level, float $bal, int $metal, int $crystal, int $deut, int $ref, int $free): ?array
    {
        $e = $this->elements();
        $lv = static fn(int $id): int => (int) $planet[$e->column($id)];
        switch ($el) {
            case 1:
            case 2:
            case 3:
                $target = [1 => $ref + 1, 2 => $ref - 1, 3 => $ref - 3][$el];
                $score = 40 + 14 * max(-2, $target - $lv($el));
                if ($bal < 0) {
                    $score *= 0.3;
                }
                $cat = 'mines';
                break;
            case 4:
                $score = $bal < 0 ? 120 : ($bal < 15 ? 70 : 25);
                $cat = 'energy';
                break;
            case 12:
                $score = ($bal < 15 && $deut >= 8) ? 60 : 10;
                $cat = 'energy';
                break;
            case 22:
            case 23:
            case 24:
                $rid = [22 => 901, 23 => 902, 24 => 903][$el];
                $max = (float) ($planet[$e->column($rid) . '_max'] ?? 0);
                $fill = $max > 0 ? (float) $planet[$e->column($rid)] / $max : 0;
                $score = 12 + 110 * max(0, $fill - 0.55) + ($fill >= 0.85 ? 120 : 0); // a nearly full store is spent or enlarged before it overflows
                $cat = 'storage';
                break;
            case 14:
                $target = min(10, intdiv($metal, 2));
                $score = $level <= 2 ? ($metal >= 4 ? 105 : 40) : ($level - 1 < $target ? 55 + 6 * ($target - $level + 1) : 10);
                $cat = 'facilities';
                break;
            case 21:
                $score = $level == 1 ? ($metal >= 6 ? 95 : 20) : max(4, 30 - 5 * ($level - 1));
                $cat = 'facilities';
                break;
            case 31:
                $score = $level == 1 ? ($metal >= 4 ? 95 : 40) : ($level <= 8 ? 48 - 5 * ($level - 1) : 6);
                $cat = 'facilities';
                break;
            case 15:
                $score = $level <= 4 ? 30 - 6 * ($level - 1) : 0;
                $cat = 'facilities';
                break;
            case 33:
                $score = $free <= 3 ? 75 : 0;
                $cat = 'facilities';
                break;
            case SILO:
                $score = $level <= 4 ? 28 : 0; // only offered with the knob `missiles`: level 4 is what the launch needs
                $cat = 'facilities';
                break;
            default:
                return null;
        }

        return [$score, $cat];
    }

    /**
     * Building candidates of one planet (when a build slot is free): every buildable element with its score, weighted
     * by the bot's category weights and discounted by the time to afford it.
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     */
    private function buildingCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf): void
    {
        $e = $this->elements();
        $user = $ctx->user;
        $lv = static fn(int $id): int => (int) $planet[$e->column($id)];

        if ($ctx->canStartBuilding($planet)) {
            $bal = (float) $planet['energy'] + (float) $planet['energy_used'];
            $metal = $lv(1);
            $crystal = $lv(2);
            $deut = $lv(3);
            $ref = max($metal, $crystal + 2, $deut + 4);
            $free = (int) $this->planetQueries()->calculateMaxPlanetFields($planet) - (int) $planet['field_current'];

            foreach (array_merge(self::BUILDINGS, $ctx->bot->missiles ? [SILO] : []) as $el) {
                $level = $ctx->buildableLevel($planet, $el);
                if ($level === null) {
                    continue;
                }
                $cost = $this->buildFunctions()->getElementPrice($user, $planet, $el, false, $level);

                $scored = $this->buildingScore($el, $planet, $level, $bal, $metal, $crystal, $deut, $ref, $free);
                if ($scored === null) {
                    continue;
                }
                [$score, $cat] = $scored;
                if ($score <= 0) {
                    continue;
                }
                [$ok, $factor] = $penalty($cost);
                $out[] = [
                    'type' => 'build', 'planet' => $pid, 'element' => $el, 'count' => 1, 'cat' => $cat, 'cost' => $cost, 'raw' => $score * $wf($cat),
                    'score' => $score * $wf($cat) * $factor, 'affordable' => $ok,
                    'label' => $e->column($el) . ' ' . $level . ' on ' . $name,
                ];
            }
        }
    }

    /**
     * Research candidates of one planet (with a lab, an empty research queue and no lab under construction): score
     * falls with the level, the combat techs weigh with the bot's skill.
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     * @param array<string, mixed> $settings
     */
    private function researchCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf, float $skill, array $settings = []): void
    {
        $e = $this->elements();
        $user = $ctx->user;
        $lv = static fn(int $id): int => (int) $planet[$e->column($id)];

        if ($lv(31) > 0 && $ctx->isResearchQueueEmpty() && !$ctx->labInConstruction()) {
            foreach (self::RESEARCH as $el => [$base, $cap]) {
                $level = $ctx->researchLevel($planet, $el);
                if ($level === null) {
                    continue;
                }
                $cost = $this->buildFunctions()->getElementPrice($user, $planet, $el, false, $level);
                [$ok, $factor] = $penalty($cost);
                $score = $base * $cap / ($cap + $level - 1);
                if ($el >= 109 && $el <= 111) {
                    $score *= 0.6 + 0.6 * $skill;
                }
                $score *= $this->researchBoost($ctx, $el, $settings);
                $out[] = [
                    'type' => 'research', 'planet' => $pid, 'element' => $el, 'count' => 1, 'cat' => 'research', 'cost' => $cost, 'raw' => $score * $wf('research'),
                    'score' => $score * $wf('research') * $factor, 'affordable' => $ok,
                    'label' => $e->column($el) . ' ' . $level . ' on ' . $name,
                ];
            }
        }
    }

    /**
     * Research priorities that unlock expansion: astrophysics while the planet limit is reached (more colonies) and little otherwise,
     * computer technology while fleet slots are scarce, espionage for raiding profiles, drives for profiles that fly (raids,
     * expeditions).
     *
     * @param array<string, mixed> $settings profile settings
     */
    private function researchBoost(BotActions $ctx, int $el, array $settings): float
    {
        $raids = !empty($settings['raid_enabled']) && (int) ($settings['raid_chance'] ?? 0) >= 30;
        $flies = $raids || (int) ($settings['expedition_chance'] ?? 0) >= 10;

        return match ($el) {
            124 => $this->planetPositions()->currentPlanetCount($ctx->user) >= $this->planetPositions()->maxPlanetCount($ctx->user) ? 1.8 : 0.6,
            108 => $this->fleetFunctions()->getMaxFleetSlots($ctx->user) <= 2 + (int) ($settings['expedition_slots'] ?? 0) ? 1.6 : 1.0,
            106 => $raids ? 1.7 : 1.0,
            115, 117 => $flies ? 1.3 : 1.0,
            default => 1.0,
        };
    }

    /**
     * Ship and defense candidates of one planet (when units can be queued): ship scores follow the owned numbers and the
     * bot's skill, a colony ship is offered while a colony is possible; the batches come from addUnits().
     *
     * @param list<array<string, mixed>> $out candidate list, appended to
     * @param array<string, mixed> $planet planet row
     * @param callable(array<int, int|float>): array{0: bool, 1: float} $penalty affordability and waiting discount of a cost
     * @param callable(string): float $wf category weight factor
     * @param array<string, mixed> $settings
     */
    private function unitCandidates(array &$out, BotActions $ctx, array $planet, int|string $pid, string $name, callable $penalty, callable $wf, float $skill, bool $colonyOk, array $settings = []): void
    {
        $e = $this->elements();
        $lv = static fn(int $id): int => (int) $planet[$e->column($id)];

        if ($ctx->canQueueUnits($planet)) {
            $cargo = $this->cargoNeed($ctx);
            $war = $this->warFactor($ctx);
            $ships = [
                202 => 52 * 6 / (6 + $lv(202) + $lv(203)) * $cargo,
                203 => 42 * 4 / (4 + $lv(203)) * $cargo,
                204 => (14 + 14 * $skill) * 10 / (10 + $lv(204)) * $war,
                205 => (14 + 14 * $skill) * 10 / (10 + $lv(205)) * $war,
                206 => (16 + 14 * $skill) * 10 / (10 + $lv(206)) * $war,
                207 => (16 + 14 * $skill) * 10 / (10 + $lv(207)) * $war,
                209 => $lv(209) < 3 ? 14 : 3,
                210 => $lv(210) < 1 ? 36 : ($lv(210) < 5 ? 12 : 3),
            ];
            if ($war > 1.0) {
                // a bot that wants a war fleet also builds the heavier hulls as soon as its techs allow them
                foreach ([SHIP_BOMBER => 12, SHIP_DESTROYER => 16, SHIP_BATTLECRUISER => 18] as $id => $base) {
                    $ships[$id] = $base * (1 + $skill) * 10 / (10 + $lv($id)) * $war;
                }
            }
            if ($ctx->bot->missiles && $lv(SILO) >= 4 && (int) $ctx->user[$e->column(117)] > 0) {
                $ships[INTERPLANETARY_MISSILE] = 16 * 10 / (10 + $lv(INTERPLANETARY_MISSILE));
            }
            if ($colonyOk && $lv(208) == 0) {
                $ships[208] = 86; // expand early: a colony is worth more than most single steps
            }
            $bal = (float) $planet['energy'] + (float) $planet['energy_used'];
            if ($bal < 0 && $lv(SHIP_SOLSAT) < 400) {
                $ships[SHIP_SOLSAT] = 45; // solar satellites close an energy deficit without a building slot
            }
            foreach ($ships as $el => $score) {
                $this->addUnits($out, $ctx, $planet, $pid, $el, $score, 'ship', 'ships', $wf('ships'), $penalty, $name);
            }

            foreach (self::DEFENSE as $el => [$base, $cap]) {
                $score = $base * $cap / ($cap + $lv($el));
                $this->addUnits($out, $ctx, $planet, $pid, $el, $score, 'defense', 'defense', $wf('defense'), $penalty, $name);
            }
        }
    }

    /**
     * Multiplier on the scores of warships: 1 unless raids are on and the bot's aggression is above 50; then it grows with the aggression
     * (up to 3 more at 100) and with the game phase (the bot's points: a quarter of it for a newcomer, all of it from 150000 points).
     * The category weight of the ships (the ship weight knob) comes on top; a ship weight of 0 means no war fleet.
     */
    public function warFactor(BotActions $ctx): float
    {
        $aggression = $ctx->bot->aggression;
        if ($aggression <= 50 || empty($ctx->config->bots_raids_enabled) || $ctx->bot->shipWeightPercent < 1) {
            return 1.0;
        }
        $phase = min(1.0, max(0.25, (float) ($ctx->user['total_points'] ?? 0) / 150000));

        return 1.0 + ($aggression - 50) / 50 * 3 * $phase;
    }

    /**
     * Multiplier for cargo ship scores from the capacity the bot needs: half of the biggest stock on any planet has to fit (to move
     * resources between planets, to raid, to expedition), but a fleet far above that is not extended.
     */
    private function cargoNeed(BotActions $ctx): float
    {
        $e = $this->elements();
        $capacity = 0;
        $stock = 0;
        foreach ($ctx->planets as $planet) {
            $capacity += (int) $planet[$e->column(SHIP_SMALL_CARGO)] * (int) $e->capacity(SHIP_SMALL_CARGO)
                + (int) $planet[$e->column(SHIP_LARGE_CARGO)] * (int) $e->capacity(SHIP_LARGE_CARGO);
            $stock = max($stock, (float) $planet['metal'] + (float) $planet['crystal'] + (float) $planet['deuterium']);
        }
        $need = max(20000.0, 0.5 * $stock);

        return $capacity < $need ? 1 + min(2.0, ($need - $capacity) / $need) : ($capacity > 1.5 * $need ? 0.3 : 1.0);
    }

    /**
     * Adds a ship/defense candidate: batch size is 15-60 % of what the planet can afford (at least 1; exactly 1 for
     * colony ship, solar satellite-like single units and one-per-planet defenses), capped by `max_fleet_per_build`; the score
     * is weighted and penalised like in candidates(). Skips elements that are not allowed or score 0.
     *
     * @param list<array<string, mixed>> $out     candidate list, appended to
     * @param callable                   $penalty fn(array $cost): array{0: bool, 1: float} affordable now + waiting factor
     * @param array<string, mixed> $planet
     */
    private function addUnits(array &$out, BotActions $ctx, array $planet, int $pid, int $el, float $score, string $type, string $cat, float $wf, callable $penalty, string $name): void
    {
        $e = $this->elements();
        if ($score <= 0 || !$ctx->unitsAllowed($planet, $el)) {
            return;
        }

        $single = $this->buildFunctions()->getElementPrice($ctx->user, $planet, $el);
        $maxAfford = (int) $this->buildFunctions()->getMaxConstructibleElements($ctx->user, $planet, $el, $single);
        if (in_array($el, [208, 210]) || in_array($el, $this->elements()->ids('one'))) {
            $count = 1;
        } elseif ($maxAfford >= 1) {
            $count = max(1, (int) floor($maxAfford * (mt_rand(15, 60) / 100)));
        } else {
            $count = 1;
        }
        $count = min($count, (int) $ctx->config->max_fleet_per_build);
        $cost = $this->buildFunctions()->getElementPrice($ctx->user, $planet, $el, false, $count);
        [$ok, $factor] = $penalty($cost);

        $out[] = [
            'type' => $type, 'planet' => $pid, 'element' => $el, 'count' => $count, 'cat' => $cat, 'cost' => $cost, 'raw' => $score * $wf,
            'score' => $score * $wf * $factor, 'affordable' => $ok,
            'label' => $count . ' x ' . $e->column($el) . ' on ' . $name,
        ];
    }
}
