<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotPersona;
use App\Game\Bots\BotProfiles;
use App\Game\Bots\Raid\BotRaids;
use App\Game\Bots\Raid\RaidDispatcher;
use App\Game\Bots\Raid\RaidFollowUp;
use App\Game\Bots\Raid\RaidPlanner;
use App\Game\Bots\Raid\RaidSpyReports;
use App\Game\Bots\Raid\RaidTargetFinder;
use App\Game\Missions\MissionCaseSpy;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * Raiding bots on the test database: the target filter (what a bot may even look at), the profit decision with crafted spy
 * reports, the recall decision, the bash limit, the dispatch rules (an attack the game refuses is refused for bots too), the
 * pre-impact re-spy and recall, and a whole raid from scouting to the attack through the real espionage mission.
 */
final class BotRaidTest extends GameTestCase
{
    private const GALAXY = 7;

    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUniverseConfig([
            'noob_protection' => 1, 'noob_protection_time' => 5000, 'noob_protection_multi' => 5, 'noob_inactives' => 0,
            'noob_protection_outlaw' => 0, 'rebuild_protection' => 0, 'adm_attack' => 0, 'bash_per_user' => 0, 'bash_per_planet' => 0,
            'bots_raids_enabled' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} user and planet row of a player placed in galaxy 7
     */
    private function player(array $user = [], array $planet = [], int $points = 100000): array
    {
        $this->slot++;
        [$u, $p] = $this->createPlayer($user + ['onlinetime' => time() - 10 * 86400], $planet + ['galaxy' => self::GALAXY, 'system' => 100 + $this->slot, 'planet' => 8]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = :p WHERE id_owner = :id AND stat_type = 1;', [':p' => $points, ':id' => $u['id']]);

        return [$u, $p];
    }

    /**
     * A bot with ships and fuel; returns the user row (bot_players row written).
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     * @return array<mixed>
     */
    private function bot(array $user = [], array $planet = []): array
    {
        $r = $this->elements()->columnMap();
        [$u, $p] = $this->player(
            $user + ['spy_tech' => 8, 'computer_tech' => 10, 'military_tech' => 3, 'shield_tech' => 3, 'defence_tech' => 3, 'onlinetime' => time()],
            $planet + [$r[203] => 60, $r[202] => 100, $r[206] => 30, $r[210] => 6, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6],
        );
        $this->makeBot((int) $u['id']);

        return [$u, $p];
    }

    private function makeBot(int $userId): void
    {
        $this->db->insert(
            'INSERT INTO %%BOT_PLAYERS%% SET user_id = :u, universe = 1, profile_id = 4, skill = 0.9, personality = :p, created_at = :t;',
            [':u' => $userId, ':p' => '{}', ':t' => time() - 86400],
        );
    }

    /**
     * @param array<string, mixed> $bot
     */
    private function ctx(array $bot): BotActions
    {
        $ctx = new BotActions(1, $this->db, $this->configs, $this->state, self::container()->get(BotPersona::class));
        $this->assertTrue($ctx->load((int) $bot['id']));

        return $ctx;
    }

    /**
     * @param array<mixed> $override
     * @return array<string, mixed> lunatic profile settings with raid values for the tests
     */
    private function settings(array $override = []): array
    {
        return $override + self::container()->get(BotProfiles::class)->defaults()['lunatic']['settings'];
    }

    /**
     * @param array<mixed> $defense
     * @param array<string, mixed> $fleet
     * @param array<mixed> $resources
     * @param array<mixed> $techs
     * @return array<string, mixed> parsed spy report like RaidSpyReports::parse() gives
     */
    private function report(array $resources, ?array $fleet, ?array $defense, ?array $techs = null): array
    {
        return ['planet_id' => 1, 'time' => time(), 'resources' => [901 => $resources[0], 902 => $resources[1], 903 => $resources[2]], 'fleet' => $fleet, 'defense' => $defense, 'buildings' => null, 'techs' => $techs, 'name' => 'x', 'coords' => '7:1:8'];
    }

    /**
     * @param array<mixed> $extra
     * @param array<string, mixed> $planet
     * @return array<string, mixed> scouted target at the planet row's coordinates
     */
    private function targetOf(array $planet, array $extra = []): array
    {
        return $extra + ['planet_id' => (int) $planet['id'], 'user_id' => (int) $planet['id_owner'], 'name' => 'target', 'galaxy' => (int) $planet['galaxy'], 'system' => (int) $planet['system'], 'planet' => (int) $planet['planet'], 'coords' => $planet['galaxy'] . ':' . $planet['system'] . ':' . $planet['planet'], 'activity_minutes' => null, 'is_bot' => false];
    }

    /**
     * @param array<string, mixed> $bot
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function scout(array $bot, array $settings = []): array
    {
        $ctx = $this->ctx($bot);

        return self::container()->get(RaidTargetFinder::class)->scout($ctx, $this->settings($settings), []);
    }

    /**
     * @param array<mixed> $found
     * @return list<int> user ids of the candidates
     */
    private function candidateUsers(array $found): array
    {
        return array_column($found['candidates'], 'user_id');
    }

    public function testTargetFilterExcludesProtectedPlayers(): void
    {
        [$bot, $botPlanet] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$open] = $this->player();
        [$noob] = $this->player(['onlinetime' => time() - 60], [], 500);          // 500 points, the bot has 200 times as many: noob protected
        [$vacation] = $this->player(['urlaubs_modus' => 1]);
        [$admin] = $this->player(['authlevel' => 3]);
        [$banned] = $this->player(['banaday' => time() + 86400]);
        [$buddy] = $this->player();
        [$partner] = $this->player();
        [$war] = $this->player();
        [$mate] = $this->player();
        $this->db->insert('INSERT INTO %%BUDDY%% SET sender = :s, owner = :o, universe = 1;', [':s' => $bot['id'], ':o' => $buddy['id']]);
        $this->db->insert("INSERT INTO %%ALLIANCE%% SET ally_name = 'A', ally_tag = 'A', ally_owner = :o, ally_universe = 1, ally_members = 2;", [':o' => $bot['id']]);
        $a = $this->db->lastInsertId();
        $this->db->insert("INSERT INTO %%ALLIANCE%% SET ally_name = 'B', ally_tag = 'B', ally_owner = :o, ally_universe = 1, ally_members = 1;", [':o' => $partner['id']]);
        $b = $this->db->lastInsertId();
        $this->db->insert("INSERT INTO %%ALLIANCE%% SET ally_name = 'C', ally_tag = 'C', ally_owner = :o, ally_universe = 1, ally_members = 1;", [':o' => $war['id']]);
        $c = $this->db->lastInsertId();
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a WHERE id IN (:b, :m);', [':a' => $a, ':b' => $bot['id'], ':m' => $mate['id']]);
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a WHERE id = :p;', [':a' => $b, ':p' => $partner['id']]);
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a WHERE id = :p;', [':a' => $c, ':p' => $war['id']]);
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 3, accept = 1, universe = 1;', [':a' => $a, ':b' => $b]);
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 5, accept = 1, universe = 1;', [':a' => $a, ':b' => $c]);

        $found = $this->scout($bot);
        $users = $this->candidateUsers($found);

        $this->assertContains((int) $open['id'], $users, 'an ordinary player is a target');
        $this->assertContains((int) $war['id'], $users, 'an alliance at war is a target');
        foreach (['noob' => $noob, 'vacation' => $vacation, 'admin' => $admin, 'banned' => $banned, 'buddy' => $buddy, 'pact partner' => $partner, 'own alliance' => $mate] as $why => $user) {
            $this->assertNotContains((int) $user['id'], $users, $why . ' is no target');
        }
        $this->assertArrayHasKey('noob protection', $found['skipped']);
        $this->assertArrayHasKey('staff', $found['skipped']);
        $this->assertArrayHasKey('own alliance', $found['skipped']);
        $this->assertArrayHasKey('pact partner', $found['skipped']);
        $this->assertArrayHasKey('buddy', $found['skipped']);
        $this->assertArrayHasKey('vacation', $found['skipped']);
        $this->assertArrayHasKey('banned', $found['skipped']);
    }

    public function testTargetFilterFollowsTheBotAndHumanSettingsAndTheRange(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$human] = $this->player();
        [$otherBot] = $this->player();
        $this->makeBot((int) $otherBot['id']);
        [$far] = $this->player([], ['system' => 390]);

        $both = $this->candidateUsers($this->scout($bot));
        $this->assertContains((int) $human['id'], $both);
        $this->assertContains((int) $otherBot['id'], $both, 'bots are targets too');
        $this->assertNotContains((int) $far['id'], $both, 'outside the scout range');

        $onlyBots = $this->candidateUsers($this->scout($bot, ['attack_humans' => false]));
        $this->assertSame([(int) $otherBot['id']], $onlyBots);
        $onlyHumans = $this->candidateUsers($this->scout($bot, ['attack_bots' => false]));
        $this->assertSame([(int) $human['id']], $onlyHumans);
    }

    public function testInactiveAndWeakTargetsRankHigher(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$active, $activePlanet] = $this->player(['onlinetime' => time() - 60]);
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 120, ':id' => $activePlanet['id']]);
        [$inactive] = $this->player(['onlinetime' => time() - 9 * 86400]);

        $found = $this->scout($bot, ['prefer_inactive' => 80]);
        $this->assertSame((int) $inactive['id'], $found['candidates'][0]['user_id']);
        $activeRow = array_values(array_filter($found['candidates'], static fn(array $c): bool => $c['user_id'] === (int) $active['id']))[0];
        $this->assertSame(2, $activeRow['activity_minutes'], 'the galaxy view marker: minutes since the planet was updated');
        $this->assertContains('inactive', $found['candidates'][0]['status']);
    }

    public function testBashLimitIsRespectedByScoutingAndDispatch(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$target, $targetPlanet] = $this->player(['onlinetime' => time() - 60], [], 90000);
        $this->setUniverseConfig(['bash_per_user' => 2]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);

        $ctx = $this->ctx($bot);
        $dispatcher = self::container()->get(RaidDispatcher::class);
        $resource = $this->elements()->columnMap();
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $dispatcher->send($ctx, $ctx->planets[$botPlanet['id']], [206 => 2, 203 => 2], Mission::Attack->value, (int) $targetPlanet['id'], (int) $target['id']);
        }
        $this->assertTrue($results[0]['ok'], $results[0]['reason']);
        $this->assertTrue($results[1]['ok'], $results[1]['reason']);
        $this->assertFalse($results[2]['ok'], 'the third attack on the same player within the bash window is refused');
        $this->assertSame('bash limit', $results[2]['reason']);

        $finder = self::container()->get(RaidTargetFinder::class);
        $this->assertSame([(int) $target['id'] => 2], $finder->recentAttacks((int) $bot['id']));
        $found = $finder->scout($this->ctx($bot), $this->settings(['max_attacks_per_target_per_day' => 5]), []);
        $this->assertArrayHasKey('bash limit', $found['skipped'], 'scouting leaves the target alone: the game limit (2) caps the profile limit (5)');
        $this->assertSame(2, $finder->attackLimit($this->settings(['max_attacks_per_target_per_day' => 5])));
        $this->assertSame(1, $finder->attackLimit($this->settings(['max_attacks_per_target_per_day' => 1])));
        $this->assertGreaterThan(0, $resource[206]);
    }

    public function testDispatchRefusesWhatTheGameRefuses(): void
    {
        [$bot, $botPlanet] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$noob, $noobPlanet] = $this->player(['onlinetime' => time() - 60], [], 500);
        [$vac, $vacPlanet] = $this->player(['urlaubs_modus' => 1]);
        [$admin, $adminPlanet] = $this->player(['authlevel' => 3]);
        [$ok, $okPlanet] = $this->player([], [], 90000);

        $ctx = $this->ctx($bot);
        $d = self::container()->get(RaidDispatcher::class);
        $send = fn(array $p, array $u, array $fleet, int $mission) => $d->send($ctx, $ctx->planets[$botPlanet['id']], $fleet, $mission, (int) $p['id'], (int) $u['id']);

        $this->assertSame('target under noob protection', $send($noobPlanet, $noob, [206 => 1], Mission::Attack->value)['reason']);
        $this->assertSame('target under noob protection', $send($noobPlanet, $noob, [210 => 1], Mission::Spy->value)['reason'], 'a probe is no way around the protection');
        $this->assertSame('target in vacation mode', $send($vacPlanet, $vac, [206 => 1], Mission::Attack->value)['reason']);
        $this->assertSame('target is protected staff', $send($adminPlanet, $admin, [206 => 1], Mission::Attack->value)['reason']);
        $this->assertSame('mission not available for this target', $send($okPlanet, $ok, [206 => 1], Mission::Spy->value)['reason'], 'spying needs a probe-only fleet');
        $this->assertSame('ships not on the planet', $send($okPlanet, $ok, [206 => 999], Mission::Attack->value)['reason']);
        $this->assertSame('target gone', $d->send($ctx, $ctx->planets[$botPlanet['id']], [206 => 1], Mission::Attack->value, (int) $okPlanet['id'], (int) $noob['id'])['reason'], 'the owner changed');

        $sent = $send($okPlanet, $ok, [206 => 1], Mission::Attack->value);
        $this->assertTrue($sent['ok'], json_encode($sent));
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $sent['id']]);
        $this->assertSame((int) Mission::Attack->value, (int) $fleet['mission']);
        $this->assertSame((int) $bot['id'], (int) $fleet['owner']);
        $this->assertEquals(29, $ctx->planets[$botPlanet['id']][$this->elements()->column(206)], 'the in-memory planet follows');
        $this->assertSame(29, (int) $this->loadPlanet((int) $botPlanet['id'])[$this->elements()->column(206)], 'ships and fuel are taken from the planet in the database');
    }

    public function testNoFleetSlotRefusesTheDispatch(): void
    {
        [$bot, $botPlanet] = $this->bot(['computer_tech' => 2]);
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$target, $targetPlanet] = $this->player([], [], 90000);
        $ctx = $this->ctx($bot);
        $d = self::container()->get(RaidDispatcher::class);
        $slots = $ctx->freeFleetSlots();
        for ($i = 0; $i < $slots; $i++) {
            $this->assertTrue($d->send($ctx, $ctx->planets[$botPlanet['id']], [210 => 1], Mission::Spy->value, (int) $targetPlanet['id'], (int) $target['id'])['ok']);
        }
        $this->assertSame('no free fleet slot', $d->send($ctx, $ctx->planets[$botPlanet['id']], [210 => 1], Mission::Spy->value, (int) $targetPlanet['id'], (int) $target['id'])['reason']);
    }

    public function testSpyReportIsReducedToWhatItShows(): void
    {
        $reports = self::container()->get(RaidSpyReports::class);
        $json = json_encode(['targetUserName' => 'x', 'time' => 1000, 'targetPlanet' => ['name' => 'P', 'galaxy' => 1, 'system' => 2, 'planet' => 3],
            'spyData' => [900 => [901 => 5000, 902 => 3000, 903 => 1000], 200 => [202 => 3, 206 => 0], 400 => [401 => 10, 502 => 4]]]);
        $parsed = $reports->parse($json, 77);

        $this->assertSame([901 => 5000.0, 902 => 3000.0, 903 => 1000.0], $parsed['resources']);
        $this->assertSame([202 => 3], $parsed['fleet'], 'zero entries are dropped');
        $this->assertSame([401 => 10], $parsed['defense'], 'missiles are no defense');
        $this->assertNull($parsed['buildings']);
        $this->assertNull($parsed['techs'], 'no research section: the bot does not know the techs');

        $low = $reports->parse(json_encode(['spyData' => [900 => [901 => 1, 902 => 2, 903 => 3]]]), 77);
        $this->assertNull($low['fleet']);
        $this->assertNull($low['defense']);
        $this->assertNull($reports->parse('not json', 77));
    }

    public function testProfitDecisionWithCraftedReports(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 120, 'planet' => 8]);
        $ctx = $this->ctx($bot);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);
        $s = $this->settings(['min_profit_ratio' => 2.0]);

        $rich = $planner->plan($ctx, $this->report([400000, 200000, 100000], [], []), $t, $s);
        $this->assertTrue($rich['ok'], $rich['reason']);
        $this->assertGreaterThan(5000, $rich['loot']);
        $this->assertGreaterThanOrEqual(2.0, $rich['ratio']);
        $this->assertArrayHasKey(SHIP_LARGE_CARGO, $rich['fleet'], 'cargo for the loot');
        $this->assertLessThanOrEqual(60, $rich['fleet'][203]);

        $poor = $planner->plan($ctx, $this->report([500, 300, 100], [], []), $t, $s);
        $this->assertFalse($poor['ok']);
        $this->assertStringContainsString('loot too small', $poor['reason']);

        $strong = $planner->plan($ctx, $this->report([400000, 200000, 100000], [SHIP_BATTLESHIP => 3000], [401 => 20000]), $t, $s);
        $this->assertFalse($strong['ok'], 'a strong defense is not worth it ' . json_encode($strong));

        $unknownFleet = $planner->plan($ctx, $this->report([400000, 200000, 100000], null, []), $t, $s);
        $this->assertFalse($unknownFleet['ok']);
        $this->assertStringContainsString('unknown defense', $unknownFleet['reason']);
        $unknownDef = $planner->plan($ctx, $this->report([400000, 200000, 100000], [], null), $t, $s);
        $this->assertStringContainsString('unknown defense', $unknownDef['reason']);

        $picky = $planner->plan($ctx, $this->report([400000, 200000, 100000], [], []), $t, $this->settings(['min_profit_ratio' => 1000000.0]));
        $this->assertFalse($picky['ok']);
        $this->assertStringContainsString('not worth it', $picky['reason']);
    }

    public function testEscortIsAddedWhenTheTargetDefends(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 121, 'planet' => 8]);
        $ctx = $this->ctx($bot);
        $planner = self::container()->get(RaidPlanner::class);
        $t = $this->targetOf($targetPlanet);

        $none = $planner->plan($ctx, $this->report([400000, 200000, 100000], [], []), $t, $this->settings(['min_profit_ratio' => 1.0]));
        $some = $planner->plan($ctx, $this->report([400000, 200000, 100000], [], [401 => 60]), $t, $this->settings(['min_profit_ratio' => 1.0]));
        $this->assertTrue($none['ok'] && $some['ok'], ($none['reason'] ?? '') . ($some['reason'] ?? ''));
        $this->assertArrayNotHasKey(206, $none['fleet'], 'nothing defends: cargo only');
        $this->assertArrayHasKey(206, $some['fleet'], 'rocket launchers: warships come along');
    }

    public function testRecallDecision(): void
    {
        $followUp = self::container()->get(RaidFollowUp::class);
        $plan = ['loot' => 20000, 'min_ratio' => 2.0, 'fuel' => 500];
        $good = ['win' => true, 'loot' => 18000.0, 'losses' => 100.0, 'ratio' => 12.0];

        $this->assertNull($followUp->recallReason($good, $plan), 'still good: keep');
        $this->assertStringContainsString('hides its defense', (string) $followUp->recallReason(null, $plan));
        $this->assertStringContainsString('stronger', (string) $followUp->recallReason(['win' => false] + $good, $plan));
        $this->assertStringContainsString('resources dropped', (string) $followUp->recallReason(['loot' => 900.0] + $good, $plan));
        $this->assertStringContainsString('resources dropped', (string) $followUp->recallReason(['ratio' => 0.9] + $good, $plan));
        $this->assertNull($followUp->recallReason(['ratio' => 1.2] + $good, $plan), 'half the threshold is the hysteresis');
    }

    public function testAssessAgainstANewReport(): void
    {
        [$bot] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        $ctx = $this->ctx($bot);
        $planner = self::container()->get(RaidPlanner::class);
        $fleet = [203 => 30];

        $quiet = $planner->assess($ctx, $fleet, $this->report([400000, 200000, 100000], [], []), ['fuel' => 300]);
        $this->assertTrue($quiet['win']);
        $this->assertGreaterThan(5000, $quiet['loot']);
        $armed = $planner->assess($ctx, $fleet, $this->report([400000, 200000, 100000], [SHIP_BATTLESHIP => 500], []), ['fuel' => 300]);
        $this->assertFalse($armed['win'], 'the target got a fleet: the cargo ships would die');
        $this->assertNull($planner->assess($ctx, $fleet, $this->report([1, 1, 1], null, null), ['fuel' => 300]));
    }

    public function testRespyIsScheduledBeforeTheImpactWithTimeToSpare(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$target, $targetPlanet] = $this->player([], ['galaxy' => self::GALAXY, 'system' => 130, 'planet' => 8]);
        $ctx = $this->ctx($bot);
        $followUp = self::container()->get(RaidFollowUp::class);
        $planner = self::container()->get(RaidPlanner::class);
        $now = time();
        $flight = $planner->flight($ctx, $ctx->planets[$botPlanet['id']], [SHIP_PROBE => 3], self::GALAXY, 130, 8);

        for ($i = 0; $i < 40; $i++) {
            $attack = ['impact' => $now + 3600, 'origin' => (int) $botPlanet['id'], 'target' => ['galaxy' => self::GALAXY, 'system' => 130, 'planet' => 8]];
            $text = $followUp->schedule($ctx, $attack, $this->settings(['recall_check_chance' => 100]), 3, $now);
            $this->assertSame('scheduled', $attack['state'], $text);
            $reportAt = $attack['recheck_at'] + $flight['duration'];
            $this->assertLessThanOrEqual($attack['impact'] - 150 - 190, $reportAt, 'the report arrives at least 2 minutes before the impact, with late cron runs counted');
            $this->assertGreaterThanOrEqual($attack['impact'] - 240 - 190 - $ctx->bot->respyJitterSeconds - 1, $reportAt, 'at most the jitter earlier');
            $this->assertSame($attack['impact'] + 15, $attack['settle_at']);
        }

        $never = ['impact' => $now + 3600, 'origin' => (int) $botPlanet['id'], 'target' => ['galaxy' => self::GALAXY, 'system' => 130, 'planet' => 8]];
        $this->assertSame('no re-spy', $followUp->schedule($ctx, $never, $this->settings(['recall_check_chance' => 0]), 3, $now));
        $this->assertSame('none', $never['state']);

        $short = ['impact' => $now + 100, 'origin' => (int) $botPlanet['id'], 'target' => ['galaxy' => self::GALAXY, 'system' => 130, 'planet' => 8]];
        $this->assertSame('flight too short to re-spy', $followUp->schedule($ctx, $short, $this->settings(['recall_check_chance' => 100]), 3, $now));
    }

    /** Runs the spy mission of a probe fleet the way the fleet handler would, arriving now. */
    private function arrive(int $fleetId): void
    {
        $this->db->update('UPDATE %%FLEETS%% SET arrival_time = :t, start_time = :s WHERE id = :id;', [':t' => time(), ':s' => time() - 60, ':id' => $fleetId]);
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        (new MissionCaseSpy($fleet, $this->db, $this->configs, $this->state))->TargetEvent();
    }

    /**
     * @return array<mixed>
     */
    private function actionLog(int $botId): array
    {
        return array_column($this->db->select('SELECT action, detail FROM %%BOT_LOG%% WHERE user_id = :u ORDER BY id;', [':u' => $botId]), 'detail', 'action');
    }

    public function testWholeRaidFromScoutingToAttackToRecall(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $botPlanet] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$target, $targetPlanet] = $this->player(['spy_tech' => 1], [$resource[1] => 5, 'metal' => 400000, 'crystal' => 200000, 'deuterium' => 100000], 90000);
        $raids = self::container()->get(BotRaids::class);
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $settings = $this->settings(['recall_check_chance' => 100, 'min_profit_ratio' => 1.5, 'relentless_minutes' => [30, 30], 'scout_range' => 50]);
        $personality = [];
        $session = ['left' => 5, 'raid' => true];

        // tick 1: scouts the galaxy and sends probes
        $ctx = $this->ctx($bot);
        $raids->play($ctx, $botRow, $settings, $personality, $session, time());
        $spies = $this->db->select('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Spy->value]);
        $this->assertCount(1, $spies, 'one probe flight to the only possible target');
        $this->assertSame((int) $targetPlanet['id'], (int) $spies[0]['end_id']);
        $this->assertSame((int) $target['id'], (int) $spies[0]['target_owner']);

        // the probes arrive: the game writes the report; tick 2 reads it and attacks
        $this->arrive((int) $spies[0]['id']);
        $ctx = $this->ctx($bot);
        $raids->play($ctx, $botRow, $settings, $personality, $session, max(array_column($personality['_raid']['spies'], 'arrive')) + 10);
        $attacks = $this->db->select('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Attack->value]);
        $this->assertCount(1, $attacks, json_encode($this->actionLog((int) $bot['id'])));
        $this->assertSame((int) $targetPlanet['id'], (int) $attacks[0]['end_id']);
        $log = $this->actionLog((int) $bot['id']);
        foreach (['raid_scout', 'raid_spy', 'raid_attack'] as $action) {
            $this->assertArrayHasKey($action, $log);
        }
        $attackId = (int) $attacks[0]['id'];
        $mem = $personality['_raid'];
        $this->assertArrayHasKey($attackId, $mem['attacks']);
        $this->assertContains($mem['attacks'][$attackId]['state'], ['scheduled', 'none']);

        // pre-impact check: the target built a big defense meanwhile -> the re-spy sees it and the fleet is recalled
        $this->db->update('UPDATE %%PLANETS%% SET ' . $resource[401] . ' = 30000, ' . $resource[402] . ' = 20000 WHERE id = :id;', [':id' => $targetPlanet['id']]);
        $personality['_raid']['attacks'][$attackId]['state'] = 'scheduled';
        $personality['_raid']['attacks'][$attackId]['recheck_at'] = time() - 1;
        $personality['_raid']['attacks'][$attackId]['impact'] = time() + 4000;
        $this->db->update('UPDATE %%FLEETS%% SET arrival_time = :t WHERE id = :id;', [':t' => time() + 4000, ':id' => $attackId]);
        $ctx = $this->ctx($bot);
        $raids->maintain($ctx, $botRow, $settings, $personality, time());
        $this->assertSame('probing', $personality['_raid']['attacks'][$attackId]['state']);
        $probe = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $personality['_raid']['attacks'][$attackId]['probe_id']]);
        $this->assertSame((int) Mission::Spy->value, (int) $probe['mission']);
        $this->arrive((int) $probe['id']);
        $personality['_raid']['attacks'][$attackId]['read_at'] = time();
        $ctx = $this->ctx($bot);
        $raids->maintain($ctx, $botRow, $settings, $personality, time() + 5);

        $this->assertStringContainsString('recalled', $this->actionLog((int) $bot['id'])['raid_recall'] ?? '');
        $fleet = $this->db->selectSingle('SELECT f.status, l.hasCanceled FROM %%FLEETS%% f INNER JOIN %%LOG_FLEETS%% l USING (id) WHERE f.id = :id;', [':id' => $attackId]);
        $this->assertSame((int) FLEET_RETURN, (int) $fleet['status'], 'the game returns the fleet like a player recall');
        $this->assertSame(1, (int) $fleet['hasCanceled']);
        $this->assertArrayNotHasKey($attackId, $personality['_raid']['attacks'], 'a recalled attack is forgotten');
        $this->assertGreaterThan(time(), $personality['_raid']['targets'][(int) $targetPlanet['id']]['skip_until'], 'and the target is left alone for a while');
    }

    public function testReRespyKeepsAGoodAttack(): void
    {
        $resource = $this->elements()->columnMap();
        [$bot, $botPlanet] = $this->bot();
        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 100000 WHERE id_owner = :id AND stat_type = 1;', [':id' => $bot['id']]);
        [$target, $targetPlanet] = $this->player(['spy_tech' => 1], ['metal' => 400000, 'crystal' => 200000, 'deuterium' => 100000], 90000);
        $raids = self::container()->get(BotRaids::class);
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $settings = $this->settings(['recall_check_chance' => 100, 'min_profit_ratio' => 1.5, 'scout_range' => 50]);
        $personality = [];
        $session = ['left' => 5, 'raid' => true];
        $ctx = $this->ctx($bot);
        $raids->play($ctx, $botRow, $settings, $personality, $session, time());
        $spy = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Spy->value]);
        $this->arrive((int) $spy['id']);
        $raids->play($this->ctx($bot), $botRow, $settings, $personality, $session, max(array_column($personality['_raid']['spies'], 'arrive')) + 10);
        $attack = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE owner = :u AND mission = :m;', [':u' => $bot['id'], ':m' => Mission::Attack->value]);
        $attackId = (int) $attack['id'];

        $personality['_raid']['attacks'][$attackId]['state'] = 'scheduled';
        $personality['_raid']['attacks'][$attackId]['recheck_at'] = time() - 1;
        $personality['_raid']['attacks'][$attackId]['impact'] = time() + 4000;
        $raids->maintain($this->ctx($bot), $botRow, $settings, $personality, time());
        $probe = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $personality['_raid']['attacks'][$attackId]['probe_id']]);
        $this->arrive((int) $probe['id']);
        $personality['_raid']['attacks'][$attackId]['read_at'] = time();
        $raids->maintain($this->ctx($bot), $botRow, $settings, $personality, time() + 5);

        $log = $this->actionLog((int) $bot['id']);
        $this->assertArrayHasKey('raid_recheck', $log);
        $this->assertStringContainsString('stays', $log['raid_keep'] ?? '', json_encode($log));
        $this->assertArrayNotHasKey('raid_recall', $log);
        $this->assertSame((int) FLEET_OUTWARD, (int) $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $attackId], 'status'));
        $this->assertGreaterThan(0, $resource[1]);
    }

    public function testProfitableHitStartsAStreakAndTheFarmListAndTheStreakEnds(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$target, $targetPlanet] = $this->player();
        $raids = self::container()->get(BotRaids::class);
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $settings = $this->settings(['relentless_minutes' => [45, 45]]);
        $now = time();
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = 987654, universe = 1, owner = :o, target_owner = :t, start_id = :s, end_id = :e, gained_metal = 30000, gained_crystal = 10000, gained_deuterium = 2000;',
            [':o' => $bot['id'], ':t' => $target['id'], ':s' => $botPlanet['id'], ':e' => $targetPlanet['id']],
        );
        $personality = ['_raid' => ['attacks' => [987654 => [
            'id' => 987654, 'planet' => (int) $targetPlanet['id'], 'user' => (int) $target['id'], 'name' => 'victim', 'coords' => '7:1:8', 'is_bot' => true,
            'impact' => $now - 30, 'settle_at' => $now - 15, 'state' => 'none', 'plan' => ['loot' => 25000.0],
        ]]]];

        $ctx = $this->ctx($bot);
        $raids->maintain($ctx, $botRow, $settings, $personality, $now);

        $streak = $personality['_raid']['streak'];
        $this->assertSame($now + 45 * 60, $streak['until']);
        $this->assertSame(1, $streak['hits']);
        $this->assertSame((int) $targetPlanet['id'], $personality['farm'][0]['p'], 'the target is in the farm list');
        $this->assertGreaterThan(40000, $personality['farm'][0]['loot']);
        $log = $this->actionLog((int) $bot['id']);
        $this->assertStringContainsString('+30000 metal', $log['raid_result']);
        $this->assertStringContainsString('start: 45 min', $log['raid_streak']);
        $this->assertTrue(\App\Game\Bots\Raid\RaidMemory::streakActive($personality, $now));
        $this->assertSame([], $personality['_raid']['attacks'], 'settled attacks are forgotten');

        // the farm list is laid over the target memory when the bot scouts again
        $this->assertGreaterThan(0, \App\Game\Bots\Raid\RaidMemory::withFarm([], $personality)[(int) $targetPlanet['id']]['last_loot']);

        // a streak that times out ends with a log entry and flags the session
        $session = ['left' => 3, 'raid' => true];
        $raids->play($this->ctx($bot), $botRow, $settings, $personality, $session, $now + 46 * 60);
        $this->assertArrayNotHasKey('streak', $personality['_raid']);
        $this->assertTrue($session['streak_ended']);
        $this->assertStringContainsString('end: time is up', $this->actionLog((int) $bot['id'])['raid_streak']);
    }

    public function testStreakEndsWhenNothingProfitableIsLeft(): void
    {
        [$bot] = $this->bot();
        $raids = self::container()->get(BotRaids::class);
        $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $bot['id']]);
        $now = time();
        $personality = ['_raid' => ['streak' => ['start' => $now - 600, 'until' => $now + 3600, 'hits' => 2, 'idle' => 0]]];
        $session = ['left' => 3, 'raid' => false];
        $settings = $this->settings();

        for ($tick = 1; $tick <= 3; $tick++) {
            $raids->play($this->ctx($bot), $botRow, $settings, $personality, $session, $now + $tick * 120);
        }
        $this->assertArrayNotHasKey('streak', $personality['_raid']);
        $this->assertStringContainsString('end: nothing profitable left, 2 hit(s)', $this->actionLog((int) $bot['id'])['raid_streak']);
    }

    public function testWakeTimeFollowsTheEarliestRaidTask(): void
    {
        $now = time();
        $personality = ['_raid' => ['attacks' => [
            1 => ['state' => 'scheduled', 'recheck_at' => $now + 500, 'settle_at' => $now + 900],
            2 => ['state' => 'probing', 'read_at' => $now + 300, 'settle_at' => $now + 2000],
            3 => ['state' => 'settled', 'settle_at' => $now + 10],
        ]]];
        $this->assertSame($now + 300, \App\Game\Bots\Raid\RaidMemory::nextWake($personality));
        $this->assertNull(\App\Game\Bots\Raid\RaidMemory::nextWake([]));
    }

    public function testProfilesCarryClampedRaidSettings(): void
    {
        $profiles = self::container()->get(BotProfiles::class);
        $defaults = $profiles->defaults();
        $this->assertTrue($defaults['lunatic']['settings']['raid_enabled']);
        $this->assertFalse($defaults['noob']['settings']['raid_enabled']);
        $this->assertGreaterThan($defaults['office_worker']['settings']['raid_chance'], $defaults['lunatic']['settings']['raid_chance']);
        $this->assertGreaterThan($defaults['shift_worker']['settings']['raid_chance'], $defaults['jobless']['settings']['raid_chance']);

        $n = $profiles->normalizeSettings([
            'raid_enabled' => false, 'raid_chance' => 500, 'scout_range' => 0, 'min_profit_ratio' => -3, 'relentless_minutes' => [900, 20],
            'recall_check_chance' => -5, 'max_attacks_per_target_per_day' => 999, 'attack_bots' => 0, 'attack_humans' => 1, 'prefer_inactive' => 200,
        ]);
        $this->assertFalse($n['raid_enabled']);
        $this->assertSame(100, $n['raid_chance']);
        $this->assertSame(1, $n['scout_range']);
        $this->assertSame(0.5, $n['min_profit_ratio']);
        $this->assertSame([20, 600], $n['relentless_minutes']);
        $this->assertSame(0, $n['recall_check_chance']);
        $this->assertSame(50, $n['max_attacks_per_target_per_day']);
        $this->assertFalse($n['attack_bots']);
        $this->assertTrue($n['attack_humans']);
        $this->assertSame(100, $n['prefer_inactive']);

        $empty = $profiles->normalizeSettings([]);
        $this->assertSame(['raid_enabled', 'raid_chance', 'scout_range', 'min_profit_ratio', 'relentless_minutes', 'recall_check_chance', 'max_attacks_per_target_per_day', 'attack_bots', 'attack_humans', 'prefer_inactive'], array_values(array_intersect(array_keys($empty), ['raid_enabled', 'raid_chance', 'scout_range', 'min_profit_ratio', 'relentless_minutes', 'recall_check_chance', 'max_attacks_per_target_per_day', 'attack_bots', 'attack_humans', 'prefer_inactive'])));
    }

    public function testRaidsAreOffUnlessTheUniverseEnablesThem(): void
    {
        $raids = self::container()->get(BotRaids::class);
        $this->assertTrue($raids->enabled(1));
        $this->setUniverseConfig(['bots_raids_enabled' => 0]);
        $this->assertFalse($raids->enabled(1));
    }
}
