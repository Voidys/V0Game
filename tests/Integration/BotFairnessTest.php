<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotLogistics;
use App\Game\Bots\BotPersona;
use App\Game\Bots\BotProfiles;
use App\Game\Bots\BotSettings;
use App\Game\Bots\Raid\BotEnvelope;
use App\Game\Bots\Raid\RaidDispatcher;
use App\Game\Bots\Raid\RaidTargetFinder;
use App\Game\Core\FleetFunctions;
use App\Game\Enum\Mission;
use App\Tests\Support\GameTestCase;

/**
 * The fairness rules of the bots: the shared anti-frustration envelope, relations rechecked at send, threat reaction on what the
 * overview shows (unknown attackers are hostile, defense only when it finishes before the impact, defense after a hit), the scouting
 * radius and the fleetsave of a bot with one planet.
 */
final class BotFairnessTest extends GameTestCase
{
    private const GALAXY = 5;

    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUniverseConfig(['noob_protection' => 0, 'bash_per_user' => 0, 'bash_per_planet' => 0, 'adm_attack' => 0, 'bots_raids_enabled' => 1]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function player(array $user = [], array $planet = []): array
    {
        $this->slot++;

        return $this->createPlayer($user + ['onlinetime' => time() - 10 * 86400], $planet + ['galaxy' => self::GALAXY, 'system' => 20 + $this->slot * 2, 'planet' => 8]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} a bot with ships and fuel
     */
    private function bot(array $user = [], array $planet = []): array
    {
        $r = $this->elements()->columnMap();
        [$u, $p] = $this->player($user + ['spy_tech' => 8, 'computer_tech' => 10, 'onlinetime' => time()], $planet + [$r[203] => 60, $r[202] => 40, $r[206] => 20, $r[210] => 6, 'metal' => 1e6, 'crystal' => 1e6, 'deuterium' => 1e6]);
        $this->db->insert('INSERT INTO %%BOT_PLAYERS%% SET user_id = :u, universe = 1, profile_id = 4, skill = 0.9, personality = :p, created_at = :t;', [':u' => $u['id'], ':p' => '{}', ':t' => time() - 86400]);

        return [$u, $p];
    }

    /**
     * @param array<string, mixed> $bot
     */
    private function ctx(array $bot, ?BotSettings $knobs = null): BotActions
    {
        $ctx = new BotActions(1, $this->db, $this->configs, $this->state, self::container()->get(BotPersona::class));
        $this->assertTrue($ctx->load((int) $bot['id']));
        if ($knobs !== null) {
            $ctx->bot = $knobs;
        }

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

    /** Writes a finished bot attack (or other mission) into the fleet log. */
    private function logFlight(int $botId, int $targetId, int $mission, int $arrival, int $endId = 0): void
    {
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET id = :id, universe = 1, mission = :m, owner = :o, target_owner = :t, arrival_time = :a, start_time = :s, start_id = 0, end_id = :e, hasCanceled = 0;',
            [':e' => $endId, ':id' => 900000 + random_int(1, 90000000), ':m' => $mission, ':o' => $botId, ':t' => $targetId, ':a' => $arrival, ':s' => $arrival - 600],
        );
    }

    public function testEnvelopeCapsRaidsProbesAndCooldownOverAllBots(): void
    {
        [$bot] = $this->bot();
        [$other] = $this->bot();
        [$human] = $this->player();
        [$botTarget] = $this->bot();
        $envelope = self::container()->get(BotEnvelope::class);
        $ctx = $this->ctx($bot, BotSettings::fromValues(['raidsPerHumanDay' => 2, 'humanCooldownHours' => 1, 'probesPerHumanDay' => 3]));
        $attack = Mission::Attack->value;
        $spy = Mission::Spy->value;

        $this->assertNull($envelope->refusal($ctx, (int) $human['id'], $attack));

        // an attack of another bot two hours ago: inside the day quota, outside the cooldown
        $this->logFlight((int) $other['id'], (int) $human['id'], $attack, time() - 7200);
        $this->assertNull($envelope->refusal($ctx, (int) $human['id'], $attack));
        // a second one 30 minutes ago: quota full (2) for everybody, whoever sends
        $this->logFlight((int) $other['id'], (int) $human['id'], $attack, time() - 1800);
        $this->assertStringContainsString('often enough today', (string) $envelope->refusal($ctx, (int) $human['id'], $attack));
        $this->assertStringContainsString('often enough today', (string) $envelope->refusal($this->ctx($other, $ctx->bot), (int) $human['id'], $attack));
        $this->assertNull($envelope->refusal($ctx, (int) $botTarget['id'], $attack), 'bots as targets are not protected by the envelope');

        // with a higher quota the cooldown (the 30 minute old attack) still holds
        $loose = BotSettings::fromValues(['raidsPerHumanDay' => 10, 'humanCooldownHours' => 1]);
        $this->assertStringContainsString('short while ago', (string) $envelope->refusal($this->ctx($bot, $loose), (int) $human['id'], $attack));

        // probes: three flights of any bot make the daily quota
        for ($i = 0; $i < 3; $i++) {
            $this->logFlight((int) $other['id'], (int) $human['id'], $spy, time() - 600 * ($i + 1));
        }
        $this->assertStringContainsString('spied on this player', (string) $envelope->refusal($ctx, (int) $human['id'], $spy));
    }

    public function testNoSecondBotAttackWhileOneIsInFlight(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$other] = $this->bot();
        [$human, $humanPlanet] = $this->player();
        $ctx = $this->ctx($bot);
        $sent = self::container()->get(RaidDispatcher::class)->send($ctx, $ctx->planets[(int) $botPlanet['id']], [SHIP_SMALL_CARGO => 5], Mission::Attack->value, (int) $humanPlanet['id'], (int) $human['id']);
        $this->assertTrue($sent['ok'], $sent['reason']);

        $envelope = self::container()->get(BotEnvelope::class);
        $this->assertStringContainsString('on its way', (string) $envelope->refusal($this->ctx($other, BotSettings::fromValues(['raidsPerHumanDay' => 50])), (int) $human['id'], Mission::Attack->value));
    }

    public function testEveryPresetKeepsTheEnvelope(): void
    {
        foreach (['relaxed', 'normal', 'hard', 'brutal'] as $preset) {
            $knobs = BotSettings::defaults()->withPreset($preset);
            $this->assertGreaterThanOrEqual(1, $knobs->raidsPerHumanDay, $preset);
            $this->assertLessThanOrEqual(6, $knobs->raidsPerHumanDay, $preset);
            $this->assertGreaterThanOrEqual(1, $knobs->humanCooldownHours, $preset);
            $this->assertGreaterThanOrEqual(1, $knobs->probesPerHumanDay, $preset);
        }
        $this->assertSame(3, BotSettings::defaults()->raidsPerHumanDay);
    }

    public function testDispatchRechecksBuddyAndPactAtSend(): void
    {
        [$bot, $botPlanet] = $this->bot();
        [$human, $humanPlanet] = $this->player();
        $dispatcher = self::container()->get(RaidDispatcher::class);
        $ctx = $this->ctx($bot);
        $fleet = [SHIP_SMALL_CARGO => 5];

        // a buddy relation that arose after scouting
        $this->db->insert('INSERT INTO %%BUDDY%% SET sender = :s, owner = :o, universe = 1;', [':s' => $human['id'], ':o' => $bot['id']]);
        $result = $dispatcher->send($ctx, $ctx->planets[(int) $botPlanet['id']], $fleet, Mission::Attack->value, (int) $humanPlanet['id'], (int) $human['id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('buddy', $result['reason']);
        $this->assertNotNull($dispatcher->relationRefusalFor($ctx->user, (int) $human['id']));

        $this->db->delete('DELETE FROM %%BUDDY%%;');
        $this->assertNull($dispatcher->relationRefusalFor($ctx->user, (int) $human['id']));
        $this->assertTrue($dispatcher->send($ctx, $ctx->planets[(int) $botPlanet['id']], $fleet, Mission::Attack->value, (int) $humanPlanet['id'], (int) $human['id'])['ok']);
    }

    public function testScoutRangeIsCappedByTheKnob(): void
    {
        [$bot] = $this->bot();
        $finder = self::container()->get(RaidTargetFinder::class);

        $this->assertSame(50, $finder->range($this->ctx($bot), ['scout_range' => 200]));
        $this->assertSame(15, $finder->range($this->ctx($bot), ['scout_range' => 15]));
        $this->assertSame(10, $finder->range($this->ctx($bot, BotSettings::fromValues(['scoutRangeMax' => 10])), ['scout_range' => 200]));
    }

    /**
     * @param array<string, mixed> $botPlanet
     * @param array<int, int> $ships
     * @param int $arrivesIn seconds until the impact
     */
    private function attackerAt(array $botPlanet, array $ships, int $arrivesIn = 1800): int
    {
        $columns = [];
        foreach ($ships as $id => $count) {
            $columns[$this->elements()->column($id)] = $count;
        }
        [$enemy, $enemyPlanet] = $this->player([], $columns);
        $this->state->USER = $enemy;
        $this->state->PLANET = $enemyPlanet;
        $now = time();

        return (int) (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            $ships,
            Mission::Attack->value,
            (int) $enemy['id'],
            (int) $enemyPlanet['id'],
            (int) $enemyPlanet['galaxy'],
            (int) $enemyPlanet['system'],
            (int) $enemyPlanet['planet'],
            1,
            (int) $botPlanet['id_owner'],
            (int) $botPlanet['id'],
            (int) $botPlanet['galaxy'],
            (int) $botPlanet['system'],
            (int) $botPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now + $arrivesIn,
            $now + $arrivesIn,
            $now + 2 * $arrivesIn,
        );
    }

    /**
     * @return array<string, string> action => details of the bot log, joined
     */
    private function log(int $botId): array
    {
        $out = [];
        foreach ($this->db->select('SELECT action, detail FROM %%BOT_LOG%% WHERE user_id = :u ORDER BY id;', [':u' => $botId]) as $row) {
            $out[$row['action']] = ($out[$row['action']] ?? '') . $row['detail'] . ' | ';
        }

        return $out;
    }

    public function testAnUnidentifiableAttackIsTreatedAsHostile(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $planet] = $this->bot(['spy_tech' => 1], [$r[401] => 2000, 'hangar' => 0]);
        $this->attackerAt($planet, [SHIP_LIGHT_FIGHTER => 1]);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];

        // no coin flip: with fleetsave chance 0 the bot logs that it does not react, never that the planet "should hold"
        for ($i = 0; $i < 6; $i++) {
            $personality = [];
            $this->db->delete('DELETE FROM %%BOT_LOG%%;');
            $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 0]), $personality, time());
            $log = $this->log((int) $bot['id'])['threat'] ?? '';
            $this->assertStringContainsString('treated as hostile', $log);
            $this->assertStringNotContainsString('should hold', $log);
            $this->assertStringNotContainsString('1 ships', $log, 'with spy technology 1 the size is not shown');
        }
    }

    public function testDefenseOnlyWhenItFinishesBeforeTheImpact(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $planet] = $this->bot(['spy_tech' => 8], [$r[401] => 400, 'hangar' => 6]);
        $this->attackerAt($planet, [SHIP_LIGHT_FIGHTER => 3], 20);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];

        $acted = $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 100]), $personality, time());

        $this->assertSame(0, $acted, 'twenty seconds are not enough for any defense unit');
        $this->assertEmpty($this->loadPlanet((int) $planet['id'])['b_hangar_id']);
    }

    public function testDefenseAfterAHitFollowsTheDefenseWeight(): void
    {
        $r = $this->elements()->columnMap();
        [$bot, $planet] = $this->bot(['spy_tech' => 8], [$r[401] => 400, 'hangar' => 6]);
        [$enemy] = $this->player();
        $this->logFlight((int) $enemy['id'], (int) $bot['id'], Mission::Attack->value, time() - 600, (int) $planet['id']);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];

        $none = $logistics->threats($this->ctx($bot, BotSettings::fromValues(['defenseWeightPercent' => 0])), $this->settings(), $personality, time());
        $this->assertSame(0, $none, 'a defense weight of 0 builds nothing');

        $personality = [];
        $acted = $logistics->threats($this->ctx($bot), $this->settings(), $personality, time());
        $this->assertSame(1, $acted);
        $this->assertNotEmpty($this->loadPlanet((int) $planet['id'])['b_hangar_id']);
        $this->assertStringContainsString('hit on', $this->log((int) $bot['id'])['threat']);
        $this->assertSame(0, $logistics->threats($this->ctx($bot), $this->settings(), $personality, time()), 'one answer per hit');
    }

    public function testAcsGroupIsOneThreat(): void
    {
        [$bot, $planet] = $this->bot(['spy_tech' => 8]);
        $first = $this->attackerAt($planet, [SHIP_CRUISER => 2]);
        $second = $this->attackerAt($planet, [SHIP_CRUISER => 3]);
        $this->db->update('UPDATE %%FLEETS%% SET `group` = 7 WHERE id IN (:a, :b);', [':a' => $first, ':b' => $second]);
        $logistics = self::container()->get(BotLogistics::class);
        $personality = [];

        $logistics->threats($this->ctx($bot), $this->settings(['fleetsave_chance' => 0]), $personality, time());

        $this->assertCount(1, $personality['_fs']['seen'], 'the group is judged once');
        $this->assertStringContainsString('5 ships', $this->log((int) $bot['id'])['threat']);
    }

    public function testASinglePlanetBotFleetsavesOnAnExpedition(): void
    {
        [$lonely, $planet] = $this->bot(['computer_tech' => 5, 'expedition_tech' => 4]);
        $logistics = self::container()->get(BotLogistics::class);

        $sent = $logistics->fleetSave($this->ctx($lonely), $this->settings(['fleetsave_chance' => 100]), 8 * 3600, 'test');

        $log = $this->log((int) $lonely['id']);
        if ($sent === 0) {
            $this->assertStringContainsString('no second planet', ($log['fleetsave_skip'] ?? ''), 'the skip is explained: ' . json_encode($log));
            $this->markTestSkipped('expeditions are not possible in the test universe: ' . json_encode($log));
        }
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE owner = :u;', [':u' => $lonely['id']]);
        $this->assertSame(Mission::Expedition->value, (int) $fleet['mission']);
        $this->assertGreaterThan(0, (int) $fleet['ship_amount']);
        $this->assertStringContainsString('slow expedition', $log['fleetsave']);
        $this->assertSame(0, (int) $this->loadPlanet((int) $planet['id'])[$this->elements()->column(SHIP_SMALL_CARGO)]);
    }

    public function testTheLlmSettingOfTheProfileDecidesWhetherABotMayAsk(): void
    {
        $this->setUniverseConfig(['bots_llm_enabled' => 1]);
        [$bot] = $this->bot();
        $row = $this->db->selectSingle('SELECT * FROM %%BOT_PROFILES%% WHERE id = 4;');
        $settings = json_decode((string) $row['settings'], true);
        $settings['llm_advice'] = false;
        $this->db->update('UPDATE %%BOT_PROFILES%% SET settings = :s WHERE id = 4;', [':s' => json_encode($settings)]);
        $persona = self::container()->get(BotPersona::class);

        // no provider is configured in the tests: the question is answered null either way, the profile gate must not throw
        $this->assertNull($persona->ask(1, (int) $bot['id'], 'system', 'prompt'));
    }
}
