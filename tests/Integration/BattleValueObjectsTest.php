<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\BattleEngine\BattleEngineRust;
use App\Game\BattleEngine\BattleEngineSteemNova;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleSide;
use App\Game\BattleEngine\BattleTechs;
use App\Tests\Support\GameTestCase;

/**
 * Pins what the typed battle objects (BattleFleet, BattleTechs, BattleResult, ...) must not change:
 *
 * - the JSON handed to the Rust library is byte-identical to what the array code produced (fixture
 *   tests/Fixtures/battle-engine-input.json, captured before the typed rewrite: key order, number formatting, seed last),
 * - the report data (BattleHandler::GenerateReport) and the figures of the result of both engines for seeded fights,
 *   compared by a hash of the canonical (key-sorted) JSON captured before the rewrite,
 * - the small behaviours of the value objects.
 */
final class BattleValueObjectsTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const REPORT_HASHES = [
        'A-php' => ['868a84aa7954a439', '307609ac9db0aa63'],
        'A-rust' => ['5700cccc1ce7fc9b', '307609ac9db0aa63'],
        'multi-php' => ['06afdb21b6fc8754', 'f692a55216c96b55'],
        'multi-rust' => ['e2a00b3eee513699', 'f692a55216c96b55'],
        'npc-php' => ['11cca57de930a461', '80ad852a3aa0b32c'],
        'npc-rust' => ['16e8e6cd9e00fc24', 'c32aff4d7a0804f6'],
    ];
    // GOLDEN-END

    private const FLEET_A = [[204 => 100, 205 => 40, 207 => 10], [5, 3, 4], [202 => 20, 204 => 50, 401 => 80, 402 => 30], [3, 2, 2]];
    private const FLEET_D = [[206 => 30, 207 => 20, 211 => 5], [8, 6, 7], [205 => 40, 206 => 10, 401 => 200, 403 => 50, 404 => 5, 407 => 1], [7, 7, 8]];

    /**
     * @param array<int, int|float> $units
     * @param array{0: int|float, 1: int|float, 2: int|float} $tech weapons, shields, armor
     * @param array<string, mixed> $extraPlayer
     */
    private function fleet(int|string $id, int $userId, array $units, array $tech = [0, 0, 0], array $extraPlayer = []): BattleFleet
    {
        return new BattleFleet(
            $id,
            ['id' => $userId, 'username' => 'p' . $userId, 'military_tech' => $tech[0], 'shield_tech' => $tech[1], 'defence_tech' => $tech[2]] + $extraPlayer,
            $units,
            ['start_galaxy' => 1, 'start_system' => 2, 'start_planet' => 3, 'start_type' => 1, 'resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0],
        );
    }

    /** @return array<string, array{0: array<int|string, BattleFleet>, 1: array<int|string, BattleFleet>, 2: ?int}> */
    private function cases(): array
    {
        [$a, $d] = [self::FLEET_A, self::FLEET_D];

        return [
            'A' => [[1001 => $this->fleet(1001, 1001, $a[0], $a[1])], [0 => $this->fleet(0, 2001, $a[2], $a[3])], 42],
            'multi' => [
                [1001 => $this->fleet(1001, 1001, $d[0], $d[1]), 77 => $this->fleet(77, 1002, [206 => 15, 204 => 60], [6, 4, 5])],
                [0 => $this->fleet(0, 2001, $d[2], $d[3]), 9 => $this->fleet(9, 2002, [203 => 3], [1, 0, 12])],
                5,
            ],
            'npc' => [
                [1001 => $this->fleet(1001, 1001, [206 => 10, 207 => 4], [3, 3, 3])],
                [0 => $this->fleet(0, 0, [204 => 8, 205 => 3], [0, 0, 0], ['factor' => [1 => 0, 2 => 0]])],
                11,
            ],
        ];
    }

    /** The document sent to the library is the same bytes as before: fixture captured from the array implementation. */
    public function testRustInputJsonIsByteIdentical(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/battle-engine-input.json'), true, flags: JSON_THROW_ON_ERROR);
        [$a, $d] = [self::FLEET_A, self::FLEET_D];
        $inputs = [
            'A' => [[1001 => $this->fleet(1001, 1001, $a[0], $a[1])], [0 => $this->fleet(0, 2001, $a[2], $a[3])], null],
            'D-seed' => [[1001 => $this->fleet(1001, 1001, $d[0], $d[1])], [0 => $this->fleet(0, 2001, $d[2], $d[3])], 42],
            'multi' => [
                [1001 => $this->fleet(1001, 1001, $d[0], $d[1]), 77 => $this->fleet(77, 1002, [206 => 15, 204 => 60, 210 => 0], [6, 4, 5])],
                [0 => $this->fleet(0, 2001, $d[2], $d[3]), 9 => $this->fleet(9, 2002, [203 => 3], [1, 0, 12])],
                5,
            ],
            'fractional' => [[1001 => $this->fleet(1001, 1001, [213 => 12.0, 214 => 3], [2.5, 0, 7])], [0 => $this->fleet(0, 2001, [401 => 1000.0, 405 => 12], [0, 3, 0])], null],
        ];
        $this->assertSame(array_keys($fixture), array_keys($inputs));

        foreach ($inputs as $name => [$attackers, $defenders, $seed]) {
            $engine = new BattleEngineRust($this->db, $this->configs, $this->state);
            $engine->useSeed($seed);

            $this->assertSame($fixture[$name], $engine->engineJson($attackers, $defenders), "engine input of '$name' changed");
        }
    }

    /**
     * Report data and result figures of seeded fights in both engines are the ones the array code produced
     * (compared by hash of the key-sorted JSON, so only the content counts, not the order of keys).
     */
    public function testReportDataAndResultAreUnchanged(): void
    {
        $info = [
            'thisFleet' => ['start_galaxy' => 1, 'start_system' => 33, 'start_planet' => 7, 'start_type' => 1, 'end_galaxy' => 1, 'end_system' => 33, 'end_planet' => 7, 'end_type' => 1, 'arrival_time' => 1000],
            'debris' => [901 => 1, 902 => 2], 'stealResource' => [901 => 0, 902 => 0, 903 => 0], 'moonChance' => 3, 'additionalChance' => 0, 'moonDestroy' => false,
            'moonName' => null, 'moonDestroyChance' => null, 'moonDestroySuccess' => null, 'fleetDestroyChance' => null, 'fleetDestroySuccess' => null, 'additionalInfo' => 'x',
        ];
        $handler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        foreach ($this->cases() as $name => [$attackers, $defenders, $seed]) {
            foreach (['php', 'rust'] as $which) {
                $engine = $which === 'rust' ? new BattleEngineRust($this->db, $this->configs, $this->state) : new BattleEngineSteemNova($this->db, $this->configs, $this->state);
                if (!$engine->isAvailable(true)) {
                    $this->markTestSkipped("battle engine '$which' is not available here");
                }
                mt_srand($seed);
                if ($engine instanceof BattleEngineRust) {
                    $engine->useSeed($seed);
                }
                $fleetsA = array_map(static fn(BattleFleet $f): BattleFleet => $f->copy(), $attackers);
                $fleetsD = array_map(static fn(BattleFleet $f): BattleFleet => $f->copy(), $defenders);

                $result = $engine->fight($fleetsA, $fleetsD, 1, true);
                $this->assertNotFalse($result);
                $report = $handler->GenerateReport($result, $info, SIM_FIGHT, $fleetsA, $fleetsD, $engine->getName());
                $report['duration'] = 0;
                $figures = array_diff_key($result->toArray(), ['duration' => 1, 'rw' => 1]);

                $this->assertSame(self::REPORT_HASHES["$name-$which"], [self::hash($report), self::hash($figures)], "report of '$name' ($which) changed");
            }
        }
    }

    /** The engine writes the survivors into the fleets it was given and the old array adapter agrees. */
    public function testSurvivorsAreWrittenIntoTheFleetsAndTheAdapterAgrees(): void
    {
        [$attackers, $defenders] = [[1001 => $this->fleet(1001, 1001, self::FLEET_A[0], self::FLEET_A[1])], [0 => $this->fleet(0, 2001, self::FLEET_A[2], self::FLEET_A[3])]];
        $arrayAttackers = BattleFleet::listToArrays($attackers);
        $arrayDefenders = BattleFleet::listToArrays($defenders);

        $engine = new BattleEngineSteemNova($this->db, $this->configs, $this->state);
        mt_srand(42);
        $typed = $engine->fight($attackers, $defenders, 1, true);
        mt_srand(42);
        $legacy = $engine->calculateAttack($arrayAttackers, $arrayDefenders, 1, true);

        $this->assertSame([204 => 34, 205 => 36, 207 => 10], array_filter($attackers[1001]->units));
        $this->assertSame(array_filter($attackers[1001]->units), array_filter($arrayAttackers[1001]['unit']));
        $this->assertSame(array_filter($defenders[0]->units), array_filter($arrayDefenders[0]['unit']));
        $this->assertSame(self::hash(array_diff_key($typed->toArray(), ['duration' => 1])), self::hash(array_diff_key($legacy, ['duration' => 1])));
        $this->assertSame(304000, $typed->losses->value(BattleSide::Attacker));
        $this->assertSame(304000, $typed->losses->userValue(BattleSide::Attacker, 1001));
        $this->assertSame(0, $typed->losses->userValue(BattleSide::Attacker, 424242));
        $this->assertSame([1001], $typed->losses->userIds(BattleSide::Attacker));
        $this->assertSame(1001, $typed->startAttackers()[1001]->userId(), 'the start state is rebuilt from the round log');
    }

    public function testTechsAndFleetValueObjects(): void
    {
        $techs = BattleTechs::fromPlayer(['military_tech' => '5', 'shield_tech' => 2.5, 'defence_tech' => null, 'factor' => [1 => 0]]);
        $this->assertSame([5, 2.5, 0], [$techs->weapons, $techs->shields, $techs->armor]);
        $this->assertSame([1 => 0], $techs->factor);
        $this->assertSame([1.5, 1.25, 1.0], $techs->multipliers());

        $pirates = $techs->withBonus(-3);
        $this->assertSame([2, 0, 0], [$pirates->weapons, $pirates->shields, $pirates->armor], 'levels never go below 0');
        $this->assertSame([1 => 0], $pirates->factor);

        $fleet = $this->fleet(7, 1001, [202 => 3, 203 => 0], [1, 2, 3]);
        $this->assertSame(3, $fleet->unitCount());
        $this->assertFalse($fleet->isPlanet());
        $copy = $fleet->copy();
        $copy->units[202] = 0;
        $this->assertSame(3, $fleet->units[202], 'a copy has its own unit list');
        $this->assertSame(['player', 'fleetDetail', 'unit'], array_keys($fleet->toArray()));
        $this->assertEquals($fleet, BattleFleet::fromArray(7, $fleet->toArray()));
    }

    /** @param array<string, mixed> $data */
    private static function hash(array $data): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (\is_array($value)) {
                ksort($value);
                foreach ($value as $key => $item) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return substr(sha1(json_encode($sort($data), JSON_PRESERVE_ZERO_FRACTION)), 0, 16);
    }
}
