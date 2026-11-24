<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotLogistics;
use App\Game\Bots\BotPersona;
use App\Game\Bots\BotProfiles;
use App\Game\Bots\BotRelations;
use App\Game\Bots\BotSettings;
use App\Game\Bots\Raid\BotAcs;
use App\Game\Bots\Raid\BotEnvelope;
use App\Game\Bots\Raid\BotMissiles;
use App\Game\Bots\Raid\BotRaids;
use App\Game\Bots\Raid\BotRecycling;
use App\Game\Bots\Raid\RaidFollowUp;
use App\Game\Bots\Raid\RaidLearning;
use App\Game\Bots\Raid\RaidPlanner;
use App\Game\Bots\Raid\RaidTargetFinder;
use App\Game\Enum\Mission;
use App\Game\Missions\MissionCaseSpy;
use App\Tests\Support\GameTestCase;

/**
 * The newer bot behaviours: the learned loss multiplier, grudges and revenge raids, raids from the nearest base (also a moon), the
 * recycling of debris, group attacks of allied bots, missiles at defense, moons as deploy point, and that the shared envelope caps
 * the attacks of all of them.
 */
final class BotBehavioursTest extends GameTestCase
{
    private const GALAXY = 6;

    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUniverseConfig([
            'noob_protection' => 0, 'rebuild_protection' => 0, 'adm_attack' => 0, 'bash_per_user' => 0, 'bash_per_planet' => 0,
            'bots_raids_enabled' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function player(array $user = [], array $planet = [], int $points = 100000): array
    {
        $this->slot++;
        [$u, $p] = $this->createPlayer($user + ['onlinetime' => time() - 10 * 86400], $planet + ['galaxy' => self::GALAXY, 'system' => 100 + $this->slot * 3, 'planet' => 8]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => $points, ':id' => $u['id']]);

        return [$u, $p];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} a bot with ships and fuel
     */
    private function bot(array $user = [], array $planet = []): array
    {
        $r = $this->elements()->columnMap();
        [$u, $p] = $this->player(
            $user + ['spy_tech' => 8, 'computer_tech' => 10, 'military_tech' => 3, 'shield_tech' => 3, 'defence_tech' => 3, 'impulse_motor_tech' => 5, 'onlinetime' => time()],
            $planet + [$r[203] => 60, $r[202] => 100, $r[206] => 30, $r[210] => 6, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6],
        );
        $this->db->insert('INSERT INTO %%BOT_PLAYERS%% SET user_id = :u, universe = 1, profile_id = 4, skill = 0.9, personality = :p, created_at = :t, session_until = :s;', [':u' => $u['id'], ':p' => '{}', ':t' => time() - 86400, ':s' => time() + 3600]);

        return [$u, $p];
    }

    /**
     * @param array<string, mixed> $bot
     */
    private function ctx(array $bot, ?BotSettings $knobs = null): BotActions
    {
        $ctx = new BotActions(1, $this->db, $this->configs, $this->state, self::container()->get(BotPersona::class));
        if ($knobs !== null) {
            $ctx->bot = $knobs;
        }
        $this->assertTrue($ctx->load((int) $bot['id']));

        return $ctx;
    }

    /**
     * @param array<mixed> $override
     * @return array<string, mixed>
     */
    private function settings(array $override = []): array
    {
        return $override + self::container()->get(BotProfiles::class)->defaults()['lunatic']['settings'];
    }

    /**
     * @param array<mixed> $fleet
     * @param array<mixed> $defense
     * @return array<string, mixed> parsed spy report
     */
    private function report(array $fleet = [], array $defense = [], array $resources = [400000, 200000, 100000]): array
    {
        return ['planet_id' => 1, 'time' => time(), 'resources' => [901 => $resources[0], 902 => $resources[1], 903 => $resources[2]], 'fleet' => $fleet, 'defense' => $defense, 'interceptors' => 0, 'buildings' => null, 'techs' => null, 'name' => 'x', 'coords' => '6:1:8'];
    }

    /**
     * @param array<string, mixed> $planet
     * @return array<string, mixed>
     */
    private function targetOf(array $planet, array $extra = []): array
    {
        return $extra + ['planet_id' => (int) $planet['id'], 'user_id' => (int) $planet['id_owner'], 'name' => 'target', 'galaxy' => (int) $planet['galaxy'], 'system' => (int) $planet['system'], 'planet' => (int) $planet['planet'], 'coords' => $planet['galaxy'] . ':' . $planet['system'] . ':' . $planet['planet'], 'activity_minutes' => null, 'is_bot' => false];
    }

    /** Writes a finished attack of a bot into the fleet log. */
    private function logFlight(int $botId, int $targetId, int $mission, int $arrival, string $group = '0'): void
    {
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = :g, universe = 1, mission = :m, owner = :o, target_owner = :t, arrival_time = :a, start_time = :s, start_id = 0, end_id = 0, hasCanceled = 0;',
            [':id' => 900000 + random_int(1, 90000000), ':g' => $group, ':m' => $mission, ':o' => $botId, ':t' => $targetId, ':a' => $arrival, ':s' => $arrival - 600],
        );
    }

    /**
     * Another planet (or a moon) of a user.
     *
     * @param array<string, mixed> $ships column => count
     */
    private function extraPlanet(int $userId, int $system, int $position, int $type, array $ships): int
    {
        $values = ['name' => 'extra', 'id_owner' => $userId, 'universe' => 1, 'galaxy' => self::GALAXY, 'system' => $system, 'planet' => $position, 'planet_type' => $type, 'last_update' => time(), 'field_max' => 200, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6] + $ships;
        $set = implode(', ', array_map(static fn(string $c): string => "`$c` = :$c", array_keys($values)));

        $this->db->insert("INSERT INTO %%PLANETS%% SET $set;", array_combine(array_map(static fn(string $c): string => ':' . $c, array_keys($values)), array_values($values)));

        return (int) $this->db->lastInsertId();
    }

    public function testTheLossMultiplierRisesWithBadRaidsAndFadesAgain(): void
    {
        $now = 1_800_000_000;
        $personality = [];
        $this->assertSame(1.0, RaidLearning::multiplier($personality, $now));

        $this->assertSame(1.0, RaidLearning::learn($personality, 10000.0, 10500.0, $now), 'a result close to the estimate teaches nothing');
        $this->assertSame(1.0, RaidLearning::learn($personality, 100.0, 300.0, $now), 'tiny losses teach nothing');
        $after = RaidLearning::learn($personality, 10000.0, 20000.0, $now);
        $this->assertGreaterThan(1.1, $after);
        $this->assertLessThanOrEqual(RaidLearning::MAX, $after);
        $this->assertSame($after, RaidLearning::multiplier($personality, $now));

        $this->assertLessThan($after, RaidLearning::multiplier($personality, $now + 86400), 'it fades');
        $this->assertEqualsWithDelta(1 + ($after - 1) / 2, RaidLearning::multiplier($personality, $now + 86400), 0.001, 'half of the excess per day');
        $this->assertLessThan(1.02, RaidLearning::multiplier($personality, $now + 8 * 86400));
        $this->assertSame(RaidLearning::MAX, RaidLearning::learn($personality, 1000.0, 90000.0, $now), 'never above the cap');
    }

    public function testASettledRaidThatCostMoreThanSimulatedRaisesThePlannersLossEstimate(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 60, 'planet' => 8]);
        $ctx = $this->ctx($bot);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);
        $s = $this->settings(['min_profit_ratio' => 0.1]);
        $report = $this->report([], [401 => 150]);

        $base = $planner->plan($ctx, $report, $t, $s);
        $this->assertTrue($base['ok'], $base['reason']);
        $ctx->lossMultiplier = 1.5;
        $learned = $planner->plan($ctx, $report, $t, $s);
        $this->assertEqualsWithDelta($base['losses'] * 1.5, $learned['losses'], 0.01);
        $this->assertSame($base['losses_sim'], $learned['losses_sim']);
        $this->assertLessThan($base['ratio'], $learned['ratio'], 'the same raid looks worse');

        // the settled raid: 20 cruisers sent, only 5 came back, the plan expected almost no losses
        $fleetId = 700000 + random_int(1, 90000);
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = :id, `group` = \'0\', universe = 1, mission = 1, owner = :o, target_owner = :t, arrival_time = :a, start_time = :s, start_id = 0, end_id = 0, status = 1, ships = :ships, start_ships = :start, gained_metal = 100;',
            [':id' => $fleetId, ':o' => $bot['id'], ':t' => $target['id'], ':a' => time() - 100, ':s' => time() - 700, ':ships' => SHIP_CRUISER . ',5', ':start' => SHIP_CRUISER . ',20'],
        );
        $followUp = self::container()->get(RaidFollowUp::class);
        $mem = ['targets' => [], 'spies' => [], 'attacks' => [$fleetId => [
            'id' => $fleetId, 'state' => 'none', 'settle_at' => time() - 10, 'planet' => (int) $targetPlanet['id'], 'user' => (int) $target['id'], 'name' => 'target', 'coords' => '6:60:8', 'is_bot' => true,
            'plan' => ['loot' => 5000.0, 'losses' => 100.0, 'losses_sim' => 100.0, 'fuel' => 10.0],
        ]], 'cand' => [], 'scouted_at' => 0];
        $personality = [];
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $followUp->settle($ctx, $botRow, $s, $mem, $personality, time());

        $this->assertGreaterThan(1.1, RaidLearning::multiplier($personality, time()), json_encode($personality));
        $this->assertGreaterThan(1.1, $ctx->lossMultiplier);
        $this->assertArrayHasKey('raid_learn', array_column($this->db->select('SELECT action FROM %%BOT_LOG%% WHERE user_id = :u;', [':u' => $bot['id']]), 'action', 'action'));
    }

    public function testGrudgesRiseFromAttacksAndSpyingAndFadeTenPercentPerDay(): void
    {
        [$bot] = $this->bot();
        [$human] = $this->player();
        [$other] = $this->player();
        $relations = self::container()->get(BotRelations::class);
        $now = time();

        $relations->record((int) $bot['id'], (int) $human['id'], 1, BotRelations::EVENT_PLAYER_SPIED);
        $relations->record((int) $bot['id'], (int) $human['id'], 1, BotRelations::EVENT_PLAYER_ATTACKED);
        $relations->record((int) $bot['id'], (int) $other['id'], 1, BotRelations::EVENT_MESSAGE);
        $grudges = $relations->grudges((int) $bot['id'], $now);

        $this->assertEqualsWithDelta(12.0, $grudges[(int) $human['id']], 0.1);
        $this->assertArrayNotHasKey((int) $other['id'], $grudges, 'a friendly message is no grudge');
        $row = $relations->get((int) $bot['id'], (int) $human['id']);
        $this->assertEqualsWithDelta(12.0 * 0.9 ** 3, $relations->grudge($row, $now + 3 * 86400), 0.1);
        $this->assertSame([], $relations->grudges((int) $bot['id'], $now + 60 * 86400), 'it fades away');
    }

    public function testTheMostHostilePlayerIsScoutedFirstEvenWhenActiveAndRaidedWithAThinnerProfit(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$friend, $friendPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 120, 'planet' => 8]);
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => time() - 86400, ':id' => $friendPlanet['id']]);
        [$foe, $foePlanet] = $this->player(['onlinetime' => time() - 5 * 86400], ['galaxy' => self::GALAXY, 'system' => 125, 'planet' => 8], 50000);
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => time() - 60, ':id' => $foePlanet['id']]);
        $ctx = $this->ctx($bot, BotSettings::fromValues(['revenge' => true, 'attackActives' => false]));
        $finder = self::container()->get(RaidTargetFinder::class);
        $s = $this->settings(['scout_range' => 50]);

        $plain = $finder->scout($ctx, $s, []);
        $this->assertNotContains((int) $foe['id'], array_column($plain['candidates'], 'user_id'), 'an active player is not wanted without a grudge');

        $found = $finder->scout($ctx, $s, [], [(int) $foe['id'] => 20.0]);
        $this->assertSame((int) $foe['id'], $found['candidates'][0]['user_id'], 'the grudge puts him first');
        $this->assertTrue($found['candidates'][0]['revenge']);
        $this->assertContains((int) $friend['id'], array_column($found['candidates'], 'user_id'));

        // a plan that does not reach the profit ratio is accepted for revenge (70 % of the ratio, half the minimum loot)
        $planner = self::container()->get(RaidPlanner::class);
        $report = $this->report([], [401 => 80]);
        $probe = $planner->plan($ctx, $report, $this->targetOf($friendPlanet), $this->settings(['min_profit_ratio' => 0.1]));
        $this->assertTrue($probe['ok'], $probe['reason']);
        $s = $this->settings(['min_profit_ratio' => $probe['ratio'] / 0.8]);
        $this->assertFalse($planner->plan($ctx, $report, $this->targetOf($friendPlanet), $s)['ok']);
        $this->assertTrue($planner->plan($ctx, $report, $this->targetOf($friendPlanet, ['revenge' => true]), $s)['ok']);
    }

    public function testARevengeRaidStillGoesThroughTheEnvelope(): void
    {
        [$bot] = $this->bot();
        [$other] = $this->bot([], ['system' => 400]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        $resource = $this->elements()->columnMap();
        [$foe, $foePlanet] = $this->player(['spy_tech' => 1], [$resource[1] => 5, 'metal' => 400000, 'crystal' => 200000, 'deuterium' => 100000], 90000);
        self::container()->get(BotRelations::class)->record((int) $bot['id'], (int) $foe['id'], 1, BotRelations::EVENT_PLAYER_ATTACKED);
        $knobs = BotSettings::fromValues(['revenge' => true, 'raidsPerHumanDay' => 1]);
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $settings = $this->settings(['recall_check_chance' => 0, 'min_profit_ratio' => 1.0, 'scout_range' => 50]);
        $raids = self::container()->get(BotRaids::class);
        $spyFlights = fn(): array => $this->db->select('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m AND target_owner = :t ORDER BY id DESC;', [':u' => $bot['id'], ':m' => Mission::Spy->value, ':t' => $foe['id']]);
        $attackFlights = fn(): array => $this->db->select('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Attack->value]);

        // another bot has used the daily quota on this player already: the bot does not even look at him
        $this->logFlight((int) $other['id'], (int) $foe['id'], Mission::Attack->value, time() - 7200);
        $personality = [];
        $session = ['left' => 5, 'raid' => true];
        $raids->play($this->ctx($bot, $knobs), $botRow, $settings, $personality, $session, time());
        $this->assertSame([], $spyFlights(), 'the envelope stops probes at a player whose raid quota is full');
        $this->assertSame([], $attackFlights());

        // the quota is free again: the grudge target is spied on, then raided, marked as revenge
        $this->db->delete('DELETE FROM %%LOG_FLEETS%% WHERE owner = :o;', [':o' => $other['id']]);
        $personality = [];
        $raids->play($this->ctx($bot, $knobs), $botRow, $settings, $personality, $session, time());
        $spies = $spyFlights();
        $this->assertCount(1, $spies, json_encode($this->db->select('SELECT action, detail FROM %%BOT_LOG%% WHERE user_id = :u;', [':u' => $bot['id']])));
        $this->db->update('UPDATE %%FLEETS%% SET arrival_time = :t, start_time = :s WHERE id = :id;', [':t' => time(), ':s' => time() - 60, ':id' => $spies[0]['id']]);
        (new MissionCaseSpy($this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $spies[0]['id']]), $this->db, $this->configs, $this->state))->TargetEvent();

        // in the meantime another bot attacks him: the revenge attack is refused by the shared cap
        $this->logFlight((int) $other['id'], (int) $foe['id'], Mission::Attack->value, time() - 7200);
        $raids->play($this->ctx($bot, $knobs), $botRow, $settings, $personality, $session, max(array_column($personality['_raid']['spies'], 'arrive')) + 10);
        $this->assertSame([], $attackFlights(), 'revenge does not lift the cap');
        $skips = implode(' | ', array_column($this->db->select('SELECT detail FROM %%BOT_LOG%% WHERE user_id = :u AND action = \'raid_skip\';', [':u' => $bot['id']]), 'detail'));
        $this->assertStringContainsString('often enough today', $skips);

        // without that attack the same report leads to the revenge raid
        $this->db->delete('DELETE FROM %%LOG_FLEETS%% WHERE owner = :o;', [':o' => $other['id']]);
        $personality['_raid']['targets'] = [];
        $personality['_raid']['spies'] = [(int) $spies[0]['id'] => ['planet' => (int) $foePlanet['id'], 'sent' => time() - 120, 'arrive' => time() - 20, 'n' => 2, 'target' => ['planet_id' => (int) $foePlanet['id'], 'user_id' => (int) $foe['id'], 'name' => $foe['username'], 'galaxy' => (int) $foePlanet['galaxy'], 'system' => (int) $foePlanet['system'], 'planet' => (int) $foePlanet['planet'], 'coords' => '6:' . $foePlanet['system'] . ':8', 'is_bot' => false, 'activity_minutes' => null, 'revenge' => true]]];
        $raids->play($this->ctx($bot, $knobs), $botRow, $settings, $personality, $session, time());
        $this->assertCount(1, $attackFlights(), json_encode($this->db->select('SELECT action, detail FROM %%BOT_LOG%% WHERE user_id = :u ORDER BY id;', [':u' => $bot['id']])));
        $attacked = implode(' | ', array_column($this->db->select('SELECT detail FROM %%BOT_LOG%% WHERE user_id = :u AND action = \'raid_attack\';', [':u' => $bot['id']]), 'detail'));
        $this->assertStringContainsString('(revenge)', $attacked);
    }

    public function testRaidsLeaveFromTheBaseNextToTheTarget(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot([], [$resource[203] => 60, $resource[206] => 30]);
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 300, 'planet' => 8]);
        $near = $this->extraPlanet((int) $bot['id'], 296, 5, 1, [$resource[203] => 60, $resource[206] => 30]);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);
        $ctx = $this->ctx($bot);
        $this->assertCount(2, $ctx->planets);

        $this->assertSame($near, $planner->origins($ctx, $t)[0], 'the nearest base with ships comes first');
        $plan = $planner->plan($ctx, $this->report([], []), $t, $this->settings(['min_profit_ratio' => 0.1]));
        $this->assertTrue($plan['ok'], $plan['reason']);
        $this->assertSame($near, $plan['origin'], 'the cheaper flight from the colony wins');

        // only the far planet has ships: it is the origin
        $this->db->update('UPDATE %%PLANETS%% SET ' . $resource[203] . ' = 0, ' . $resource[206] . ' = 0 WHERE id = :id;', [':id' => $near]);
        $plan = $planner->plan($this->ctx($bot), $this->report([], []), $t, $this->settings(['min_profit_ratio' => 0.1]));
        $this->assertSame((int) $home['id'], $plan['origin']);
    }

    public function testAMoonWithTheFleetIsAnOriginWhenTheKnobIsOn(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot([], [$resource[203] => 0, $resource[202] => 0, $resource[206] => 0]);
        $moon = $this->extraPlanet((int) $bot['id'], (int) $home['system'], (int) $home['planet'], 3, [$resource[203] => 80, $resource[206] => 40]);
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $home['system'] + 5, 'planet' => 8]);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);

        $without = $this->ctx($bot);
        $this->assertSame([], $without->moons);
        $this->assertSame([], $planner->origins($without, $t));
        $this->assertFalse($planner->plan($without, $this->report([], []), $t, $this->settings(['min_profit_ratio' => 0.1]))['ok']);

        $ctx = $this->ctx($bot, BotSettings::fromValues(['moons' => true]));
        $this->assertSame([$moon], array_keys($ctx->moons));
        $this->assertSame([$moon], $planner->origins($ctx, $t));
        $plan = $planner->plan($ctx, $this->report([], []), $t, $this->settings(['min_profit_ratio' => 0.1]));
        $this->assertTrue($plan['ok'], $plan['reason']);
        $this->assertSame($moon, $plan['origin']);

        $sent = self::container()->get(\App\Game\Bots\Raid\RaidDispatcher::class)->send($ctx, $ctx->originRef($moon), $plan['fleet'], Mission::Attack->value, (int) $targetPlanet['id'], (int) $target['id']);
        $this->assertTrue($sent['ok'], $sent['reason']);
        $flight = $this->db->selectSingle('SELECT start_id, start_type FROM %%FLEETS%% WHERE id = :id;', [':id' => $sent['id']]);
        $this->assertSame([$moon, 3], [(int) $flight['start_id'], (int) $flight['start_type']], 'the fleet starts on the moon');
    }

    public function testTheFleetIsDeployedOnTheMoonAtLogout(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot();
        $moon = $this->extraPlanet((int) $bot['id'], (int) $home['system'], (int) $home['planet'], 3, []);
        $ctx = $this->ctx($bot, BotSettings::fromValues(['moons' => true]));
        $sent = self::container()->get(BotLogistics::class)->fleetSave($ctx, $this->settings(['fleetsave_chance' => 100]), 3 * 3600, 'test', null, true);
        $this->assertSame(1, $sent);
        $flight = $this->db->selectSingle('SELECT mission, end_id, end_type FROM %%FLEETS%% WHERE owner = :u;', [':u' => $bot['id']]);
        $this->assertSame([Mission::Station->value, $moon, 3], [(int) $flight['mission'], (int) $flight['end_id'], (int) $flight['end_type']]);
        $this->assertSame(0, (int) $ctx->planets[(int) $home['id']][$resource[206]], 'the warships left the planet');
        $this->assertSame(6, (int) $ctx->planets[(int) $home['id']][$resource[210]], 'probes stay');
    }

    public function testRecyclersHarvestRichDebrisThatBeatsTheFuel(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot([], [$resource[SHIP_RECYCLER] => 20]);
        [$other, $otherPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $home['system'] + 4, 'planet' => 8]);
        $recycling = self::container()->get(BotRecycling::class);
        $s = $this->settings(['scout_range' => 20]);

        $off = $this->ctx($bot);
        $personality = [];
        $this->assertSame(0, $recycling->play($off, $s, $personality, time()), 'switched off');

        $ctx = $this->ctx($bot, BotSettings::fromValues(['recycling' => true]));
        $this->assertSame(0, $recycling->play($ctx, $s, $personality, time()), 'no debris, nothing to do');
        $this->db->update('UPDATE %%PLANETS%% SET der_metal = 60000, der_crystal = 30000 WHERE id = :id;', [':id' => $otherPlanet['id']]);
        $personality = [];
        $this->assertSame(1, $recycling->play($ctx, $s, $personality, time()));
        $flight = $this->db->selectSingle('SELECT mission, end_id, end_type, ships FROM %%FLEETS%% WHERE owner = :u;', [':u' => $bot['id']]);
        $this->assertSame([Mission::Recycling->value, (int) $otherPlanet['id'], 2], [(int) $flight['mission'], (int) $flight['end_id'], (int) $flight['end_type']]);
        $this->assertStringContainsString((string) SHIP_RECYCLER, (string) $flight['ships']);

        $this->assertSame(0, $recycling->play($ctx, $s, $personality, time() + 600), 'a flight to this field is under way');
    }

    public function testABotWithoutRecyclersBuildsSomeForARichField(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot(['combustion_tech' => 8], [$resource[SHIP_RECYCLER] => 0, $resource[21] => 8, 'metal' => 5e6, 'crystal' => 5e6, 'deuterium' => 5e6]);
        [$other, $otherPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $home['system'] + 4, 'planet' => 8]);
        $this->db->update('UPDATE %%PLANETS%% SET der_metal = 200000, der_crystal = 100000 WHERE id = :id;', [':id' => $otherPlanet['id']]);
        $ctx = $this->ctx($bot, BotSettings::fromValues(['recycling' => true]));
        $personality = [];

        $this->assertSame(1, self::container()->get(BotRecycling::class)->play($ctx, $this->settings(['scout_range' => 20]), $personality, time()));
        $this->assertStringContainsString('recyclers', implode(' ', array_column($this->db->select('SELECT detail FROM %%BOT_LOG%% WHERE user_id = :u;', [':u' => $bot['id']]), 'detail')));
    }

    public function testMissilesClearDefenseForARaidTheFleetCannotDoAlone(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $home] = $this->bot([], [$resource[SILO] => 5, $resource[INTERPLANETARY_MISSILE] => 40, $resource[203] => 40, $resource[202] => 0, $resource[206] => 5]);
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $home['system'] + 3, 'planet' => 8]);
        $ctx = $this->ctx($bot, BotSettings::fromValues(['missiles' => true]));
        $missiles = self::container()->get(BotMissiles::class);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);
        $s = $this->settings(['min_profit_ratio' => 0.1]);
        $report = $this->report([], [401 => 600]);
        $this->assertFalse($planner->plan($ctx, $report, $t, $s)['ok'], 'the launchers hold the target');

        $left = $missiles->afterVolley($ctx, $report, 40, 401);
        $this->assertLessThan(600, $left[401] ?? 0, 'missiles destroy launchers');
        $this->assertSame($report['defense'], $missiles->afterVolley($ctx, $report, 0, 401), 'no missiles, no change');

        $mem = ['strikes' => []];
        $text = $missiles->strike($ctx, $report, $t, $s, $mem, time());
        $this->assertNotNull($text);
        $fleet = $this->db->selectSingle('SELECT mission, ship_amount, cannotCancel FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Missile->value]);
        $this->assertSame(1, (int) $fleet['cannotCancel']);
        $this->assertArrayHasKey((int) $targetPlanet['id'], $mem['strikes']);
        $this->assertSame(40 - (int) $fleet['ship_amount'], (int) $ctx->planets[(int) $home['id']][$resource[INTERPLANETARY_MISSILE]]);
    }

    public function testAnAcsGroupCountsAsOneAttackForTheEnvelopeAndHelpersJoinOnTime(): void
    {
        $resource = $this->elements()->columnMap();
        [$leader, $leaderPlanet] = $this->bot(['ally_id' => 77], [$resource[203] => 60, $resource[206] => 4]);
        [$helper, $helperPlanet] = $this->bot(['ally_id' => 77], [$resource[203] => 0, $resource[202] => 0, $resource[206] => 300, $resource[210] => 6]);
        $this->db->update('UPDATE %%PLANETS%% SET galaxy = :g, `system` = :s, planet = 9 WHERE id = :id;', [':g' => self::GALAXY, ':s' => $leaderPlanet['system'], ':id' => $helperPlanet['id']]);
        [$human, $humanPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $leaderPlanet['system'] + 2, 'planet' => 8]);
        $knobs = BotSettings::fromValues(['botAcs' => true, 'raidsPerHumanDay' => 1]);
        $acs = self::container()->get(BotAcs::class);
        $envelope = self::container()->get(BotEnvelope::class);
        $planner = self::container()->get(RaidPlanner::class);

        $leaderCtx = $this->ctx($leader, $knobs);
        $t = $this->targetOf($humanPlanet);
        $report = $this->report([], [401 => 1500]);
        $this->assertFalse($planner->plan($leaderCtx, $report, $t, $this->settings(['min_profit_ratio' => 0.1]))['ok'], 'too strong alone');
        $joint = $acs->plan($leaderCtx, $report, $t, 0.1, time());
        $this->assertTrue($joint['ok'], $joint['reason']);
        $this->assertSame([(int) $helper['id']], $joint['helpers']);

        $sent = self::container()->get(\App\Game\Bots\Raid\RaidDispatcher::class)->send($leaderCtx, $leaderCtx->originRef($joint['origin']), $joint['fleet'], Mission::Attack->value, (int) $humanPlanet['id'], (int) $human['id']);
        $this->assertTrue($sent['ok'], $sent['reason']);
        $group = $acs->open($leaderCtx, $sent['id'], (int) $humanPlanet['id'], $sent['arrival'], $joint['helpers']);
        $this->assertSame($group, (int) $this->db->selectSingle('SELECT `group` FROM %%FLEETS%% WHERE id = :id;', [':id' => $sent['id']], 'group'));
        $this->assertSame(2, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_ACS%% WHERE acsID = :g;', [':g' => $group], 'c'));

        // with a quota of one attack per day the envelope refuses a second attack but lets the group's helper join the first
        $helperCtx = $this->ctx($helper, $knobs);
        $this->assertNotNull($envelope->refusal($helperCtx, (int) $human['id'], Mission::Attack->value), 'a second attack is refused');
        $this->assertNull($envelope->refusal($helperCtx, (int) $human['id'], Mission::Acs->value, $group), 'joining the group is the same attack');

        $this->assertSame(1, $acs->join($helperCtx, $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $helper['id']]), time()));
        $joined = $this->db->selectSingle('SELECT mission, `group`, arrival_time FROM %%FLEETS%% WHERE owner = :u;', [':u' => $helper['id']]);
        $this->assertSame([Mission::Acs->value, $group], [(int) $joined['mission'], (int) $joined['group']]);
        $this->assertSame($sent['arrival'], (int) $joined['arrival_time'], 'arrives with the group');
        $this->assertSame(0, $acs->join($helperCtx, $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $helper['id']]), time()), 'once');

        // the quota used up by another attack: the helper of a second group is refused
        [$other] = $this->bot(['ally_id' => 77]);
        $this->logFlight((int) $other['id'], (int) $human['id'], Mission::Attack->value, time() + 1000);
        $this->assertStringContainsString('often enough today', (string) $envelope->refusal($helperCtx, (int) $human['id'], Mission::Acs->value, $group));
        $this->assertGreaterThan(0, array_sum($acs->groupShips($group)));
    }

    public function testPresetsTurnTheBehavioursOnFromHard(): void
    {
        foreach (['revenge', 'botAcs', 'recycling', 'moons', 'missiles', 'warmonger'] as $knob) {
            $this->assertFalse(BotSettings::defaults()->withPreset('relaxed')->$knob, 'relaxed ' . $knob);
            $this->assertFalse(BotSettings::defaults()->withPreset('normal')->$knob, 'normal ' . $knob);
            $this->assertTrue(BotSettings::defaults()->withPreset('brutal')->$knob, 'brutal ' . $knob);
        }
        $hard = BotSettings::defaults()->withPreset('hard');
        $this->assertTrue($hard->revenge && $hard->botAcs && $hard->recycling && $hard->moons);
        $this->assertFalse($hard->missiles || $hard->warmonger);
    }

    public function testWarmongersAreAggressiveOnlyAboveTheirPoints(): void
    {
        $knobs = BotSettings::fromValues(['warmonger' => true, 'warmongerPoints' => 50000]);
        $this->assertFalse($knobs->warmongerActive(49999.0));
        $this->assertTrue($knobs->warmongerActive(50000.0));
        $this->assertFalse(BotSettings::fromValues(['warmongerPoints' => 1])->warmongerActive(1e9), 'the switch is off');
        $s = ['raid_chance' => 40, 'min_profit_ratio' => 2.0];
        $plain = $knobs->applyToProfile($s, 0, 100.0);
        $war = $knobs->applyToProfile($s, 0, 60000.0);
        $this->assertGreaterThan($plain['raid_chance'], $war['raid_chance']);
        $this->assertLessThan($plain['min_profit_ratio'], $war['min_profit_ratio']);

        // an active human is a target for a warmonger although actives are not wanted
        [$bot, $botPlanet] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 90000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$active, $activePlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => (int) $botPlanet['system'] + 6, 'planet' => 8], 60000);
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => time() - 60, ':id' => $activePlanet['id']]);
        $finder = self::container()->get(RaidTargetFinder::class);
        $without = $finder->scout($this->ctx($bot, BotSettings::fromValues(['attackActives' => false])), $this->settings(['scout_range' => 50]), []);
        $this->assertNotContains((int) $active['id'], array_column($without['candidates'], 'user_id'));
        $with = $finder->scout($this->ctx($bot, BotSettings::fromValues(['attackActives' => false, 'warmonger' => true, 'warmongerPoints' => 50000])), $this->settings(['scout_range' => 50]), []);
        $this->assertContains((int) $active['id'], array_column($with['candidates'], 'user_id'));
    }

    public function testTheWarFleetGoalGrowsWithAggressionAndPoints(): void
    {
        [$bot] = $this->bot();
        $brain = self::container()->get(\App\Game\Bots\BotBrain::class);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 200000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);

        $calm = $this->ctx($bot, BotSettings::fromValues(['aggression' => 50]));
        $this->assertSame(1.0, $brain->warFactor($calm));
        $hard = $this->ctx($bot, BotSettings::defaults()->withPreset('hard'));
        $brutal = $this->ctx($bot, BotSettings::defaults()->withPreset('brutal'));
        $this->assertGreaterThan(1.0, $brain->warFactor($hard));
        $this->assertGreaterThan($brain->warFactor($hard), $brain->warFactor($brutal));
        $this->assertSame(1.0, $brain->warFactor($this->ctx($bot, BotSettings::fromValues(['aggression' => 90, 'shipWeightPercent' => 0]))), 'the ship weight knob 0 means no war fleet');

        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 10000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        $this->assertLessThan($brain->warFactor($brutal), $brain->warFactor($this->ctx($bot, BotSettings::defaults()->withPreset('brutal'))), 'a newcomer builds less of a war fleet');
    }
}
