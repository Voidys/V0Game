<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\BuildFunctions;
use App\Game\Core\Player\ProtectionRules;
use App\Tests\Support\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the cost and time formulas of BuildFunctions (element price per level incl. demolition and the catch-up
 * discount, build/research/shipyard time with robotics, nanite, laboratory and university, rest price, maximum
 * constructible amount, buyability) against golden values captured from the pre-renewal code.
 *
 * Why: BuildFunctions is the economic core that the typed models and the class splits of the legacy renewal will
 * touch; any drift in a price or a duration changes the game balance and must be noticed.
 */
final class BuildFunctionsTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'price build 1/1' => [901 => 60.0, 902 => 15.0],
        'price build 1/5' => [901 => 303.75, 902 => 75.9375],
        'price build 1/12' => [901 => 5189.853515625, 902 => 1297.46337890625],
        'price build 1/25' => [901 => 1010046.731761694, 902 => 252511.6829404235],
        'price build 2/1' => [901 => 48.0, 902 => 24.0],
        'price build 2/5' => [901 => 314.57280000000003, 902 => 157.28640000000001],
        'price build 2/12' => [901 => 8444.249301319685, 902 => 4222.124650659843],
        'price build 2/25' => [901 => 3802951.8006846933, 902 => 1901475.9003423466],
        'price build 3/1' => [901 => 225.0, 902 => 75.0],
        'price build 3/5' => [901 => 1139.0625, 902 => 379.6875],
        'price build 3/12' => [901 => 19461.95068359375, 902 => 6487.31689453125],
        'price build 3/25' => [901 => 3787675.2441063523, 902 => 1262558.4147021174],
        'price build 4/1' => [901 => 75.0, 902 => 30.0],
        'price build 4/5' => [901 => 379.6875, 902 => 151.875],
        'price build 4/12' => [901 => 6487.31689453125, 902 => 2594.9267578125],
        'price build 4/25' => [901 => 1262558.4147021174, 902 => 505023.365880847],
        'price build 12/1' => [901 => 900.0, 902 => 360.0, 903 => 180.0],
        'price build 12/5' => [901 => 9447.84, 902 => 3779.136, 903 => 1889.568],
        'price build 12/12' => [901 => 578415.6907130881, 902 => 231366.27628523525, 903 => 115683.13814261762],
        'price build 12/25' => [901 => 1204432960.5471559, 902 => 481773184.21886235, 903 => 240886592.10943118],
        'price build 14/1' => [901 => 400.0, 902 => 120.0, 903 => 200.0],
        'price build 14/5' => [901 => 6400.0, 902 => 1920.0, 903 => 3200.0],
        'price build 14/12' => [901 => 819200.0, 902 => 245760.0, 903 => 409600.0],
        'price build 14/25' => [901 => 6710886400.0, 902 => 2013265920.0, 903 => 3355443200.0],
        'price build 15/1' => [901 => 1000000.0, 902 => 500000.0, 903 => 100000.0],
        'price build 15/5' => [901 => 16000000.0, 902 => 8000000.0, 903 => 1600000.0],
        'price build 15/12' => [901 => 2048000000.0, 902 => 1024000000.0, 903 => 204800000.0],
        'price build 15/25' => [901 => 16777216000000.0, 902 => 8388608000000.0, 903 => 1677721600000.0],
        'price build 21/1' => [901 => 400.0, 902 => 200.0, 903 => 100.0],
        'price build 21/5' => [901 => 6400.0, 902 => 3200.0, 903 => 1600.0],
        'price build 21/12' => [901 => 819200.0, 902 => 409600.0, 903 => 204800.0],
        'price build 21/25' => [901 => 6710886400.0, 902 => 3355443200.0, 903 => 1677721600.0],
        'price build 22/1' => [901 => 1000.0],
        'price build 22/5' => [901 => 16000.0],
        'price build 22/12' => [901 => 2048000.0],
        'price build 22/25' => [901 => 16777216000.0],
        'price build 31/1' => [901 => 200.0, 902 => 400.0, 903 => 200.0],
        'price build 31/5' => [901 => 3200.0, 902 => 6400.0, 903 => 3200.0],
        'price build 31/12' => [901 => 409600.0, 902 => 819200.0, 903 => 409600.0],
        'price build 31/25' => [901 => 3355443200.0, 902 => 6710886400.0, 903 => 3355443200.0],
        'price build 33/1' => [902 => 50000.0, 903 => 100000.0, 911 => 1000.0],
        'price build 33/5' => [902 => 800000.0, 903 => 1600000.0, 911 => 16000.0],
        'price build 33/12' => [902 => 102400000.0, 903 => 204800000.0, 911 => 2048000.0],
        'price build 33/25' => [902 => 838860800000.0, 903 => 1677721600000.0, 911 => 16777216000.0],
        'price build 44/1' => [901 => 20000.0, 902 => 20000.0, 903 => 1000.0],
        'price build 44/5' => [901 => 320000.0, 902 => 320000.0, 903 => 16000.0],
        'price build 44/12' => [901 => 40960000.0, 902 => 40960000.0, 903 => 2048000.0],
        'price build 44/25' => [901 => 335544320000.0, 902 => 335544320000.0, 903 => 16777216000.0],
        'price build 106/1' => [901 => 200.0, 902 => 1000.0, 903 => 200.0],
        'price build 106/4' => [901 => 1600.0, 902 => 8000.0, 903 => 1600.0],
        'price build 106/10' => [901 => 102400.0, 902 => 512000.0, 903 => 102400.0],
        'price build 108/1' => [902 => 400.0, 903 => 600.0],
        'price build 108/4' => [902 => 3200.0, 903 => 4800.0],
        'price build 108/10' => [902 => 204800.0, 903 => 307200.0],
        'price build 113/1' => [902 => 800.0, 903 => 400.0],
        'price build 113/4' => [902 => 6400.0, 903 => 3200.0],
        'price build 113/10' => [902 => 409600.0, 903 => 204800.0],
        'price build 115/1' => [901 => 400.0, 903 => 600.0],
        'price build 115/4' => [901 => 3200.0, 903 => 4800.0],
        'price build 115/10' => [901 => 204800.0, 903 => 307200.0],
        'price build 120/1' => [901 => 200.0, 902 => 100.0],
        'price build 120/4' => [901 => 1600.0, 902 => 800.0],
        'price build 120/10' => [901 => 102400.0, 902 => 51200.0],
        'price build 124/1' => [901 => 4000.0, 902 => 8000.0, 903 => 4000.0],
        'price build 124/4' => [901 => 21437.5, 902 => 42875.0, 903 => 21437.5],
        'price build 124/10' => [901 => 615747.1771240234, 902 => 1231494.3542480469, 903 => 615747.1771240234],
        'price build 131/1' => [901 => 750.0, 902 => 500.0, 903 => 250.0],
        'price build 131/4' => [901 => 6000.0, 902 => 4000.0, 903 => 2000.0],
        'price build 131/10' => [901 => 384000.0, 902 => 256000.0, 903 => 128000.0],
        'price build 199/1' => [911 => 300000.0],
        'price build 199/4' => [911 => 8100000.0],
        'price build 199/10' => [911 => 5904900000.0],
        'price build 202/1' => [901 => 2000.0, 902 => 2000.0],
        'price build 202/50' => [901 => 100000.0, 902 => 100000.0],
        'price build 204/1' => [901 => 3000.0, 902 => 1000.0],
        'price build 204/50' => [901 => 150000.0, 902 => 50000.0],
        'price build 207/1' => [901 => 45000.0, 902 => 15000.0],
        'price build 207/50' => [901 => 2250000.0, 902 => 750000.0],
        'price build 209/1' => [901 => 10000.0, 902 => 6000.0, 903 => 2000.0],
        'price build 209/50' => [901 => 500000.0, 902 => 300000.0, 903 => 100000.0],
        'price build 210/1' => [902 => 1000.0],
        'price build 210/50' => [902 => 50000.0],
        'price build 212/1' => [902 => 2000.0, 903 => 500.0],
        'price build 212/50' => [902 => 100000.0, 903 => 25000.0],
        'price build 214/1' => [901 => 5000000.0, 902 => 4000000.0, 903 => 1000000.0],
        'price build 214/50' => [901 => 250000000.0, 902 => 200000000.0, 903 => 50000000.0],
        'price build 401/1' => [901 => 2000.0],
        'price build 401/50' => [901 => 100000.0],
        'price build 407/1' => [901 => 10000.0, 902 => 10000.0],
        'price build 407/50' => [901 => 500000.0, 902 => 500000.0],
        'price build 502/1' => [901 => 8000.0, 903 => 2000.0],
        'price build 502/50' => [901 => 400000.0, 903 => 100000.0],
        'price build 503/1' => [901 => 12500.0, 902 => 2500.0, 903 => 10000.0],
        'price build 503/50' => [901 => 625000.0, 902 => 125000.0, 903 => 500000.0],
        'price destroy 1/1' => [901 => 30.0, 902 => 7.5],
        'price destroy 1/8' => [901 => 512.578125, 902 => 128.14453125],
        'price destroy 1/20' => [901 => 66505.13460159302, 902 => 16626.283650398254],
        'price destroy 2/1' => [901 => 24.0, 902 => 12.0],
        'price destroy 2/8' => [901 => 644.2450944000002, 902 => 322.1225472000001],
        'price destroy 2/20' => [901 => 181338.87294219458, 902 => 90669.43647109729],
        'price destroy 4/1' => [901 => 37.5, 902 => 15.0],
        'price destroy 4/8' => [901 => 640.72265625, 902 => 256.2890625],
        'price destroy 4/20' => [901 => 83131.41825199127, 902 => 33252.56730079651],
        'price destroy 14/1' => [901 => 200.0, 902 => 60.0, 903 => 100.0],
        'price destroy 14/8' => [901 => 25600.0, 902 => 7680.0, 903 => 12800.0],
        'price destroy 14/20' => [901 => 104857600.0, 902 => 31457280.0, 903 => 52428800.0],
        'price destroy 21/1' => [901 => 200.0, 902 => 100.0, 903 => 50.0],
        'price destroy 21/8' => [901 => 25600.0, 902 => 12800.0, 903 => 6400.0],
        'price destroy 21/20' => [901 => 104857600.0, 902 => 52428800.0, 903 => 26214400.0],
        'price destroy 31/1' => [901 => 100.0, 902 => 200.0, 903 => 100.0],
        'price destroy 31/8' => [901 => 12800.0, 902 => 25600.0, 903 => 12800.0],
        'price destroy 31/20' => [901 => 52428800.0, 902 => 104857600.0, 903 => 52428800.0],
        'price current metal_mine 9' => [901 => 1537.734375, 902 => 384.43359375],
        'price current energy_tech 6' => [902 => 25600.0, 903 => 12800.0],
        'time 1/10/[]' => 4151.0,
        'time 1/10/{"robot_factory":5}' => 691.0,
        'time 1/10/{"robot_factory":10,"nano_factory":2}' => 94.0,
        'time 4/15/{"robot_factory":3,"nano_factory":1}' => 5517.0,
        'time 14/6/{"robot_factory":5}' => 3993.0,
        'time 31/8/{"robot_factory":4}' => 22118.0,
        'time 2/30/{"robot_factory":12,"nano_factory":4}' => 414105.0,
        'time 202/1/{"hangar":4}' => 1152.0,
        'time 207/20/{"hangar":12,"nano_factory":3}' => 16615.0,
        'time 401/100/{"hangar":8}' => 32000.0,
        'time 502/10/{"hangar":6}' => 16457.0,
        'time destroy metal_mine 12' => 281.0,
        'time queued robotics at index 0' => 461.0,
        'time queued robotics at index 1' => 369.0,
        'research time 106/4/{"laboratory":3}/0' => 8640.0,
        'research time 120/8/{"laboratory":10}/0' => 12567.0,
        'research time 120/8/{"laboratory":10,"university":3}/0' => 9785.0,
        'research time 199/1/{"laboratory":12}/0' => 1.0,
        'research time 122/5/{"laboratory":7}/2' => 26584.0,
        'time fast universe mine 3' => 30.0,
        'time fast universe small cargo' => 1152.0,
        'rest price mine 3' => [901 => 34.1, 902 => 0],
        'max constructible 202' => 10,
        'max constructible 203' => 3,
        'max constructible 204' => 8,
        'max constructible 207' => 0,
        'max constructible 210' => 20,
        'max constructible 401' => 12,
        'max constructible 402' => 16,
        'max constructible small shield dome' => 1,
        'max rockets silo 4' => [502 => 24, 503 => 12.0],
        'catch-up factor' => 0.5668105580887524,
        'catch-up price mine 5 (record 20)' => [901 => 172.16870701945854, 902 => 43.042176754864634],
        'catch-up price mine 19 (one below record)' => [901 => 75869.36962777004, 902 => 18967.34240694251],
        'catch-up price mine 20 (at record)' => [901 => 133010.26920318604, 902 => 33252.56730079651],
        'catch-up price ship unaffected' => [901 => 20000.0, 902 => 20000.0],
    ];
    // GOLDEN-END

    private BuildFunctions $build;

    protected function setUp(): void
    {
        parent::setUp();
        $this->build = new BuildFunctions($this->db, $this->configs, $this->state);
    }

    /** @return iterable<string, array{int, int}> element id, level */
    public static function priceCases(): iterable
    {
        // buildings (exponential, factor 1.5 / 2 / 1.6 ...), research, ships (flat), defence, missiles
        foreach ([1, 2, 3, 4, 12, 14, 15, 21, 22, 31, 33, 44] as $building) {
            foreach ([1, 5, 12, 25] as $level) {
                yield "building $building level $level" => [$building, $level];
            }
        }
        foreach ([106, 108, 113, 115, 120, 124, 131, 199] as $tech) {
            foreach ([1, 4, 10] as $level) {
                yield "research $tech level $level" => [$tech, $level];
            }
        }
        foreach ([202, 204, 207, 209, 210, 212, 214, 401, 407, 502, 503] as $unit) {
            foreach ([1, 50] as $amount) {
                yield "unit $unit amount $amount" => [$unit, $amount];
            }
        }
    }

    #[DataProvider('priceCases')]
    public function testElementPrice(int $element, int $level): void
    {
        [$user, $planet] = $this->createPlayer();

        $this->goldenAssert("price build $element/$level", $this->build->getElementPrice($user, $planet, $element, false, $level));
    }

    /** @return iterable<string, array{int, int}> */
    public static function destroyCases(): iterable
    {
        foreach ([1, 2, 4, 14, 21, 31] as $building) {
            foreach ([1, 8, 20] as $level) {
                yield "building $building level $level" => [$building, $level];
            }
        }
    }

    #[DataProvider('destroyCases')]
    public function testDestroyPriceIsHalf(int $element, int $level): void
    {
        [$user, $planet] = $this->createPlayer();

        $destroy = $this->build->getElementPrice($user, $planet, $element, true, $level);
        $this->goldenAssert("price destroy $element/$level", $destroy);
        foreach ($this->build->getElementPrice($user, $planet, $element, false, $level) as $resource => $price) {
            $this->assertEqualsWithDelta($price / 2, $destroy[$resource], 1e-9);
        }
    }

    public function testPriceFromCurrentLevelOfPlanetAndUser(): void
    {
        [$user, $planet] = $this->createPlayer(['energy_tech' => 6], ['metal_mine' => 9]);

        // no forLevel: planet buildings use the planet's level, research the user's level (the price of the NEXT-1 level)
        $this->goldenAssert('price current metal_mine 9', $this->build->getElementPrice($user, $planet, 1));
        $this->goldenAssert('price current energy_tech 6', $this->build->getElementPrice($user, $planet, 113));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, int}> */
    public static function timeCases(): iterable
    {
        yield 'metal mine 10, no helpers' => [[], [], 1, 10];
        yield 'metal mine 10, robotics 5' => [[], ['robot_factory' => 5], 1, 10];
        yield 'metal mine 10, robotics 10 nanite 2' => [[], ['robot_factory' => 10, 'nano_factory' => 2], 1, 10];
        yield 'solar plant 15, robotics 3 nanite 1' => [[], ['robot_factory' => 3, 'nano_factory' => 1], 4, 15];
        yield 'robot factory 6' => [[], ['robot_factory' => 5], 14, 6];
        yield 'laboratory 8, robotics 4' => [[], ['robot_factory' => 4], 31, 8];
        yield 'crystal mine 30, nanite 4' => [[], ['robot_factory' => 12, 'nano_factory' => 4], 2, 30];
        yield 'small cargo, shipyard 4' => [[], ['hangar' => 4], 202, 1];
        yield 'battleship x20, shipyard 12 nanite 3' => [[], ['hangar' => 12, 'nano_factory' => 3], 207, 20];
        yield 'plasma-defence 401 x100, shipyard 8' => [[], ['hangar' => 8], 401, 100];
        yield 'interceptor missile x10, shipyard 6' => [[], ['hangar' => 6], 502, 10];
    }

    /**
     * @param array<string, mixed> $userOverrides
     * @param array<string, mixed> $planetOverrides
     */
    #[DataProvider('timeCases')]
    public function testBuildingTime(array $userOverrides, array $planetOverrides, int $element, int $level): void
    {
        [$user, $planet] = $this->createPlayer($userOverrides, $planetOverrides);

        $price = $this->build->getElementPrice($user, $planet, $element, false, $level);
        $this->goldenAssert('time ' . $element . '/' . $level . '/' . json_encode($planetOverrides), $this->build->getBuildingTime($user, $planet, $element, $price, false, $level));
    }

    public function testBuildingDemolitionTime(): void
    {
        [$user, $planet] = $this->createPlayer([], ['robot_factory' => 5, 'metal_mine' => 12]);

        $price = $this->build->getElementPrice($user, $planet, 1, true, 12);
        $this->goldenAssert('time destroy metal_mine 12', $this->build->getBuildingTime($user, $planet, 1, $price, true, 12));
    }

    public function testBuildingTimeCountsQueuedRoboticsLevels(): void
    {
        // with a robotics factory upgrade queued in front, later buildings are timed with the raised level
        [$user, $planet] = $this->createPlayer([], ['robot_factory' => 3]);
        $planet['b_building_id'] = \App\Game\Core\StoredData::encode([[14, 4, 100.0, 1.0e10, 'build', [901 => 400.0, 902 => 120.0, 903 => 200.0]]]);

        $price = $this->build->getElementPrice($user, $planet, 1, false, 8);
        $this->goldenAssert('time queued robotics at index 0', $this->build->getBuildingTime($user, $planet, 1, $price, false, 8, 0));
        $this->goldenAssert('time queued robotics at index 1', $this->build->getBuildingTime($user, $planet, 1, $price, false, 8, 1));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, int, int}> */
    public static function researchTimeCases(): iterable
    {
        yield 'espionage 4, lab 3' => [[], ['laboratory' => 3], 106, 4, 0];
        yield 'laser 8, lab 10' => [[], ['laboratory' => 10], 120, 8, 0];
        yield 'laser 8, lab 10, university 3' => [[], ['laboratory' => 10, 'university' => 3], 120, 8, 0];
        yield 'graviton 1, lab 12' => [[], ['laboratory' => 12], 199, 1, 0];
        yield 'plasma 5, lab 7 + network 2' => [['intergalactic_tech' => 2], ['laboratory' => 7], 122, 5, 2];
    }

    /**
     * @param array<string, mixed> $userOverrides
     * @param array<string, mixed> $planetOverrides
     */
    #[DataProvider('researchTimeCases')]
    public function testResearchTime(array $userOverrides, array $planetOverrides, int $element, int $level, int $networkPlanets): void
    {
        [$user, $planet] = $this->createPlayer($userOverrides, $planetOverrides);
        // research network: the game passes the list of lab levels (own planet first, then the best others)
        $planet['laboratory_inter'] = $networkPlanets > 0 ? [$planet['laboratory'], 5, 3] : $planet['laboratory'];

        $price = $this->build->getElementPrice($user, $planet, $element, false, $level);
        $this->goldenAssert('research time ' . $element . '/' . $level . '/' . json_encode($planetOverrides) . '/' . $networkPlanets, $this->build->getBuildingTime($user, $planet, $element, $price, false, $level));
    }

    public function testBuildingTimeRespectsSpeedSettingsAndMinimum(): void
    {
        [$user, $planet] = $this->createPlayer([], ['robot_factory' => 2]);

        $this->setUniverseConfig(['building_speed' => 7500, 'shipyard_speed' => 12500, 'research_speed' => 5000, 'min_build_time' => 30]);
        $price = $this->build->getElementPrice($user, $planet, 1, false, 3);
        $this->goldenAssert('time fast universe mine 3', $this->build->getBuildingTime($user, $planet, 1, $price, false, 3));
        $this->goldenAssert('time fast universe small cargo', $this->build->getBuildingTime($user, $planet, 202, null, false, 1));
    }

    public function testRestPriceAndBuyable(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 100.9, 'crystal' => 50, 'deuterium' => 0]);

        // mine level 3 costs 135 metal / 33.75 crystal ... (rest = what is still missing, resources floored)
        $this->goldenAssert('rest price mine 3', $this->build->getRestPrice($user, $planet, 1, $this->build->getElementPrice($user, $planet, 1, false, 3)));
        $this->assertFalse($this->build->isElementBuyable($user, $planet, 1, $this->build->getElementPrice($user, $planet, 1, false, 3)));
        $this->assertTrue($this->build->isElementBuyable($user, $planet, 1, $this->build->getElementPrice($user, $planet, 1, false, 1)));
    }

    public function testMaxConstructibleElements(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 25000, 'crystal' => 20000, 'deuterium' => 4321]);

        foreach ([202, 203, 204, 207, 210, 401, 402] as $unit) {
            $this->goldenAssert("max constructible $unit", $this->build->getMaxConstructibleElements($user, $planet, $unit, $this->build->getElementPrice($user, $planet, $unit)));
        }
        // single-instance elements (shields, orbital station) are capped at 1
        $this->goldenAssert('max constructible small shield dome', $this->build->getMaxConstructibleElements($user, $planet, 407, $this->build->getElementPrice($user, $planet, 407)));
    }

    public function testMaxConstructibleRockets(): void
    {
        [, $planet] = $this->createPlayer([], ['silo' => 4, 'interceptor_misil' => 6, 'interplanetary_misil' => 3]);
        $planet['b_hangar_id'] = \App\Game\Core\StoredData::encode([[503, 2, 100.0]]);

        $this->goldenAssert('max rockets silo 4', $this->build->getMaxConstructibleRockets($planet));
    }

    public function testCatchUpDiscountReducesPriceOfLaggingPlayersButNeverOfShips(): void
    {
        $this->setUniverseConfig(['catch_up' => true, 'catchup_percent' => 50]);
        [$leader] = $this->createPlayer();
        [$user, $planet] = $this->createPlayer(['onlinetime' => time()]);
        foreach ([[$leader['id'], 1000000], [$user['id'], 50000]] as [$id, $points]) {
            $this->db->update('UPDATE %%STATPOINTS%% SET build_points = :points WHERE id_owner = :id AND stat_type = 1;', [':id' => $id, ':points' => $points]);
        }
        $this->db->insert('INSERT INTO %%RECORDS%% (userID, elementID, level, universe) VALUES (:u, 1, 20, 1), (:u, 106, 12, 1);', [':u' => $leader['id']]);

        $players = new ProtectionRules($this->db, $this->configs, $this->state);
        $this->goldenAssert('catch-up factor', $players->catchUpFactorFor((new \App\Game\Model\ModelFactory($this->state))->user($user)));
        $this->goldenAssert('catch-up price mine 5 (record 20)', $this->build->getElementPrice($user, $planet, 1, false, 5));
        $this->goldenAssert('catch-up price mine 19 (one below record)', $this->build->getElementPrice($user, $planet, 1, false, 19));
        $this->goldenAssert('catch-up price mine 20 (at record)', $this->build->getElementPrice($user, $planet, 1, false, 20));
        $this->goldenAssert('catch-up price ship unaffected', $this->build->getElementPrice($user, $planet, 202, false, 10));
    }
}
