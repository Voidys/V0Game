<?php

declare(strict_types=1);

namespace App\Game\Engagement\Stories;

use App\Game\Missions\Expedition\ExpeditionContext;
use App\Game\Missions\Expedition\ExpeditionFleetValue;

/**
 * Expedition story chains (settings expedition_stories_enabled, story_*): an expedition rarely picks up a signal (story
 * start, a line in the report) that points to another system of the same galaxy at the expedition position. The next expedition
 * of the same player that ends exactly there (before the 48 h run out) does not draw a card: step 1 gives a second signal,
 * step 2 the reward (ships of a capital ship type, "derelict carrier", or resources, each a large find times story_reward_factor, within
 * the normal expedition caps). Rows of `eng_stories` are bound to the player (one active story each, unique user_id), so shared
 * coordinates do nothing for others; bots never get stories. Expired rows are removed when the player's next expedition looks at
 * them. Called from the mission inside the fleet event transaction (no transaction of its own).
 */
final class ExpeditionStories
{
    use \App\Game\Core\GameUtilities;

    /** Capital ships a derelict carrier may hold. */
    private const CARRIER_SHIPS = [SHIP_BATTLESHIP, SHIP_BATTLECRUISER, SHIP_DESTROYER, SHIP_BOMBER, SHIP_CRUISER];

    private const LIFETIME = 48 * 3600;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /** Whether the universe has switched stories on. */
    public function enabled(int $universe): bool
    {
        return !empty($this->setting($universe, 'expedition_stories_enabled', false));
    }

    /**
     * If the fleet ends exactly at the active story's target, the story advances instead of a normal card.
     *
     * @param array<string, mixed> $fleet the expedition fleet row
     * @return array{message: string, color: string, label: string, factor: int}|null the event result for the report, null = no story step
     */
    public function advance(ExpeditionContext $ctx, array $fleet): ?array
    {
        $universe = (int) $fleet['universe'];
        $userId = (int) $fleet['owner'];
        if (!$this->enabled($universe) || $this->isBot($userId)) {
            return null;
        }
        $story = $this->db->selectSingle('SELECT * FROM %%ENG_STORIES%% WHERE `user_id` = :user FOR UPDATE;', [':user' => $userId]);
        if (empty($story)) {
            return null;
        }
        $now = (int) $fleet['stay_time'];
        if ((int) $story['expires_at'] <= $now) {
            $this->db->delete('DELETE FROM %%ENG_STORIES%% WHERE `id` = :id;', [':id' => $story['id']]);

            return null;
        }
        if ((int) $fleet['end_galaxy'] !== (int) $story['galaxy'] || (int) $fleet['end_system'] !== (int) $story['system'] || (int) $fleet['end_planet'] !== (int) $story['planet']) {
            return null;
        }
        $label = $ctx->LNG['st_label'] ?? 'Story';
        if ((int) $story['step'] < 2) {
            $target = $this->pickTarget($universe, (int) $story['galaxy'], (int) $story['system']);
            if ($target === null) {
                $this->db->delete('DELETE FROM %%ENG_STORIES%% WHERE `id` = :id;', [':id' => $story['id']]);

                return null;
            }
            $this->db->update('UPDATE %%ENG_STORIES%% SET `step` = 2, `system` = :system, `planet` = :planet, `expires_at` = :expires WHERE `id` = :id;', [
                ':system' => $target['system'], ':planet' => $target['planet'], ':expires' => $now + self::LIFETIME, ':id' => $story['id'],
            ]);

            return ['message' => $ctx->LNG->format('st_signal_next', $story['galaxy'] . ':' . $target['system'] . ':' . $target['planet']), 'color' => 'teal', 'label' => $label, 'factor' => 0];
        }
        $this->db->delete('DELETE FROM %%ENG_STORIES%% WHERE `id` = :id;', [':id' => $story['id']]);

        $message = $this->reward($ctx, $fleet);
        // relics (setting relics_src_story): a chance of a relic with the final reward; guarded
        $this->achievementGuard()->run(function () use (&$message, $ctx, $userId, $universe, $now): void {
            $message .= (new \App\Game\Engagement\Relics\RelicDrops($this->db, $this->configs, $this->state))->fromSource('story', $userId, $universe, $ctx->LNG, $now);
        });

        return ['message' => $message, 'color' => 'teal', 'label' => $label, 'factor' => 0];
    }

    /**
     * Rolls the start chance after a normal card. Only for a player without an active story who is not a bot.
     *
     * @param array<string, mixed> $fleet the expedition fleet row
     * @return string the report line ("Your crew picks up a faint signal from [G:S:P]...") or an empty string
     */
    public function maybeStart(ExpeditionContext $ctx, array $fleet): string
    {
        $universe = (int) $fleet['universe'];
        $userId = (int) $fleet['owner'];
        if (!$this->enabled($universe) || $this->isBot($userId) || random_int(1, 1000) > (int) $this->setting($universe, 'story_chance_permille', 5)) {
            return '';
        }
        $now = (int) $fleet['stay_time'];
        $this->db->delete('DELETE FROM %%ENG_STORIES%% WHERE `user_id` = :user AND `expires_at` <= :now;', [':user' => $userId, ':now' => $now]);
        $target = $this->pickTarget($universe, (int) $fleet['end_galaxy'], (int) $fleet['end_system']);
        if ($target === null) {
            return '';
        }
        // the unique user id makes a second active story impossible (INSERT IGNORE), also against a parallel event
        $this->db->insert(
            'INSERT IGNORE INTO %%ENG_STORIES%% SET `user_id` = :user, `universe` = :universe, `step` = 1, `galaxy` = :galaxy, `system` = :system, `planet` = :planet,
                `created_at` = :now, `expires_at` = :expires;',
            [
                ':user' => $userId, ':universe' => $universe, ':galaxy' => $fleet['end_galaxy'], ':system' => $target['system'], ':planet' => $target['planet'],
                ':now' => $now, ':expires' => $now + self::LIFETIME,
            ],
        );
        if ($this->db->rowCount() !== 1) {
            return '';
        }

        return '<br><br>' . $ctx->LNG->format('st_signal_start', $fleet['end_galaxy'] . ':' . $target['system'] . ':' . $target['planet']);
    }

    /**
     * Another system of the galaxy within story_range_systems and the expedition position (max_planets + 1).
     *
     * @return array{system: int, planet: int}|null null when the galaxy has no other system
     */
    private function pickTarget(int $universe, int $galaxy, int $system): ?array
    {
        $config = $this->configs->get($universe);
        $range = max(1, (int) $this->setting($universe, 'story_range_systems', 20));
        $low = max(1, $system - $range);
        $high = min((int) $config->max_system, $system + $range);
        $candidates = $high - $low + 1 - ($system >= $low && $system <= $high ? 1 : 0);
        if ($candidates < 1) {
            return null;
        }
        $pick = random_int(0, $candidates - 1) + $low;
        if ($pick >= $system) {
            $pick++;
        }

        return ['system' => $pick, 'planet' => (int) $config->max_planets + 1];
    }

    /**
     * The final reward, ships or resources at random; changes the fleet through the context.
     *
     * @param array<string, mixed> $fleet
     */
    private function reward(ExpeditionContext $ctx, array $fleet): string
    {
        return random_int(0, 1) === 0 ? $this->shipReward($ctx, $fleet) : $this->resourceReward($ctx, $fleet);
    }

    /**
     * Ships the fleet could find on a normal expedition (ExpeditionFindableShipsHandler), a large ship find: 102..200 times the
     * capped expedition points, times the reward factor and the EXPEDITION_FIND event factor, capped at the normal maximum
     * (calculateMaxFactor) times 200. Like normal finds, ships are drawn until the budget is used up (random counts); when nothing
     * is affordable or findable the reward is resources instead.
     *
     * @param array<string, mixed> $fleet
     */
    private function shipReward(ExpeditionContext $ctx, array $fleet): string
    {
        $catalog = $this->elements();
        $universe = (int) $fleet['universe'];
        $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
        $findable = (new \App\Game\Core\ExpeditionFindableShipsHandler($this->db, $this->configs, $this->state))->findableShipsForFleet($universe, $fleetArray);
        $options = array_values(array_filter(self::CARRIER_SHIPS, fn(int $id): bool => $catalog->has($id) && in_array($id, $findable)));
        $maxPoints = $this->fleetFunctions()->calculateMaxFactor($universe);
        $points = (new ExpeditionFleetValue($this->db, $this->configs, $this->state))->pointsAndCapacity($fleet)[0];
        $factor = $this->rewardFactor($universe) * $this->eventFactor($universe) * $this->relicInventory()->fleetFactor((int) $fleet['id'], $universe);
        $budget = min(random_int(102, 200) * min($points, $maxPoints) * $factor, 200 * $maxPoints);
        $found = [];
        for ($try = 0; $try < 3 && $options !== [] && $budget > 0; $try++) {
            $shipId = $options[random_int(0, count($options) - 1)];
            $cost = $catalog->cost($shipId)->metal + $catalog->cost($shipId)->crystal;
            $max = $cost > 0 ? (int) floor($budget / $cost) : 0;
            if ($max < 1) {
                continue;
            }
            $count = random_int(1, $max);
            $found[$shipId] = ($found[$shipId] ?? 0) + $count;
            $budget -= $count * $cost;
        }
        if ($found === []) {
            return $this->resourceReward($ctx, $fleet);
        }
        $listed = '';
        foreach ($found as $shipId => $count) {
            $fleetArray[$shipId] = ($fleetArray[$shipId] ?? 0) + $count;
            $listed .= $ctx->LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber($count) . ' ';
        }
        $serialized = '';
        $total = 0;
        foreach ($catalog->ids('fleet') as $id) {
            if (!empty($fleetArray[$id])) {
                $serialized .= $id . ',' . floatToString($fleetArray[$id]) . ';';
                $total += $fleetArray[$id];
            }
        }
        $serialized = substr($serialized, 0, -1);
        $ctx->updateFleet('ships', $serialized);
        $ctx->updateFleet('ship_amount', $total);
        $ctx->updateFleetLog('ships', $serialized);
        $ctx->updateFleetLog('ship_amount', $total);
        $ctx->stats->updateFoundShipsAdvancedStats($fleet['owner'], $found);
        $ctx->eventvalue = json_encode($found);

        return $ctx->LNG->format('st_reward_ships', trim($listed));
    }

    /** Combined EXPEDITION_FIND event factor (1.0 when the events feature does not exist or is off). */
    private function eventFactor(int $universe): float
    {
        if (!class_exists(\App\Game\Engagement\Events\EventModifiers::class)) {
            return 1.0;
        }

        return (new \App\Game\Engagement\Events\EventModifiers($this->db, $this->configs, $this->state))->factor($universe, \App\Game\Engagement\Events\EventModifiers::EXPEDITION_FIND);
    }

    /**
     * A large resource find (100..200 times the capped expedition points, metal full, crystal half, deuterium a third) times the
     * reward factor, limited to the free cargo room of the fleet like every expedition find.
     *
     * @param array<string, mixed> $fleet
     */
    private function resourceReward(ExpeditionContext $ctx, array $fleet): string
    {
        $catalog = $this->elements();
        $universe = (int) $fleet['universe'];
        $config = $this->configs->get($universe);
        [$points, $capacity] = (new ExpeditionFleetValue($this->db, $this->configs, $this->state))->pointsAndCapacity($fleet);
        $factor = random_int(100, 200) * $this->rewardFactor($universe) * $this->eventFactor($universe) * $this->relicInventory()->fleetFactor((int) $fleet['id'], $universe);
        $roll = random_int(1, 100);
        if ($roll <= $config->expo_ress_met_chance) {
            $resourceId = RESOURCE_METAL;
        } elseif ($roll <= $config->expo_ress_met_chance + $config->expo_ress_crys_chance) {
            $resourceId = RESOURCE_CRYSTAL;
            $factor /= 2;
        } else {
            $resourceId = RESOURCE_DEUT;
            $factor /= 3;
        }
        $found = floor(min($capacity, $factor * max(min($points, $this->fleetFunctions()->calculateMaxFactorRes($universe)), 200)));
        $found = max(0, $found);
        $column = 'resource_' . $catalog->column($resourceId);
        $ctx->updateFleet($column, $fleet[$column] + $found);
        $ctx->updateFleetLog('gained_' . $catalog->column($resourceId), $found);
        $ctx->stats->increment($fleet['owner'], \App\Game\AdvancedStats\AdvancedStatsEnum::getExpoFound($resourceId), $found);
        $ctx->eventvalue = '{"' . $resourceId . '":' . $found . '}';

        return $ctx->LNG->format('st_reward_resources', $this->viewHelpers()->prettyNumber($found), $ctx->LNG['tech'][$resourceId]);
    }

    private function rewardFactor(int $universe): float
    {
        return max(0.1, (float) $this->setting($universe, 'story_reward_factor', 2.0));
    }

    /** Universe setting with a default for keys the config does not hold yet. */
    private function setting(int $universe, string $key, mixed $default): mixed
    {
        return $this->configs->get($universe)->$key ?? $default;
    }

    /** True for a bot account (bots never get stories). */
    private function isBot(int $userId): bool
    {
        return (bool) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%BOT_PLAYERS%% WHERE `user_id` = :id;', [':id' => $userId], 'n');
    }
}
