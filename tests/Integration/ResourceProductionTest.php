<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\ResourceUpdate;
use App\Tests\Support\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the resource economy of ResourceUpdate: hourly production of metal/crystal/deuterium, energy supply and
 * demand (incl. the production throttling on an energy deficit), storage capacity, the eco hash, the resources a
 * planet holds after some hours (storage cap, vacation mode), and every production/storage formula of the vars
 * table (ProdGrid), which the game evaluates with eval().
 *
 * Why: the formulas are stored as PHP expressions and run through eval(). The legacy renewal replaces that by a safe
 * formula evaluator; these golden values prove the replacement computes exactly the same numbers. They also protect
 * the typed planet/user models and the ResourceUpdate split.
 */
final class ResourceProductionTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'economy [[],[],1,[]]' => ['metal_perhour' => 0.0, 'crystal_perhour' => 0.0, 'deuterium_perhour' => 0.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 0.0, 'energy_used' => 0.0, 'metal' => 520.0, 'crystal' => 510.0, 'deuterium' => 0.0],
        'economy [[],[],24,[]]' => ['metal_perhour' => 0.0, 'crystal_perhour' => 0.0, 'deuterium_perhour' => 0.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 0.0, 'energy_used' => 0.0, 'metal' => 980.0, 'crystal' => 740.0, 'deuterium' => 0.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000},1,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1131.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000},10,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 10000.0, 'crystal' => 6529.742096000002, 'deuterium' => 2310.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"temp_max":120,"temp_min":80},1,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 102.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1102.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"temp_max":-40,"temp_min":-80},1,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 170.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1170.0],
        'economy [[],{"metal_mine":15,"crystal_mine":12,"deuterium_sintetizer":10,"solar_plant":3,"metal":5000,"crystal":3000,"deuterium":1000},1,[]]' => ['metal_perhour' => 98.80481872468043, 'crystal_perhour' => 39.59121211106653, 'deuterium_perhour' => 16.872536136662287, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 80.0, 'energy_used' => -1522.0, 'metal' => 5118.80481872468, 'crystal' => 3049.5912121110664, 'deuterium' => 1016.8725361366622],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"metal_mine_porcent":"5","crystal_mine_porcent":"8","deuterium_sintetizer_porcent":"0","solar_plant_porcent":"10"},1,[]]' => ['metal_perhour' => 389.06136901500037, 'crystal_perhour' => 274.3793676800002, 'deuterium_perhour' => 0.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -267.0, 'metal' => 5409.061369015, 'crystal' => 3284.37936768, 'deuterium' => 1000.0],
        'economy [{"energy_tech":5},{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"fusion_plant":8},1,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => -40.48710480000011, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 1268.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 959.5128951999999],
        'economy [{"energy_tech":5},{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"fusion_plant":8,"metal":100,"crystal":100,"deuterium":0},3,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => -40.48710480000011, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 1268.0, 'energy_used' => -643.0, 'metal' => 2494.3682140900023, 'crystal' => 1158.9226288000007, 'deuterium' => 0.0],
        'economy [[],{"metal_mine":8,"crystal_mine":6,"solar_satelit":40,"temp_max":70,"temp_min":30,"metal":5000,"crystal":3000,"deuterium":1000},1,[]]' => ['metal_perhour' => 514.4613144000003, 'crystal_perhour' => 212.5873200000001, 'deuterium_perhour' => 0.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 1533.0, 'energy_used' => -278.0, 'metal' => 5534.4613144, 'crystal' => 3222.58732, 'deuterium' => 1000.0],
        'economy [{"metal_proc_tech":4,"crystal_proc_tech":3,"deuterium_proc_tech":2},{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000},1,[]]' => ['metal_perhour' => 840.3725570724008, 'crystal_perhour' => 363.55266217600024, 'deuterium_perhour' => 136.24, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5860.372557072401, 'crystal' => 3373.552662176, 'deuterium' => 1136.24],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"metal_store":3,"crystal_store":2,"deuterium_store":1},1,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 75000.0, 'crystal_max' => 40000.0, 'deuterium_max' => 20000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1131.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal_store":1,"metal":90000,"crystal":90000,"deuterium":90000},400,[]]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 20000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 90000.0, 'crystal' => 90000.0, 'deuterium' => 90000.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"metal_store":2},1,{"storage_multiplier":2}]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 80000.0, 'crystal_max' => 20000.0, 'deuterium_max' => 20000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1131.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000},1,{"resource_multiplier":7500,"energy_multiplier":2}]' => ['metal_perhour' => 2334.3682140900023, 'crystal_perhour' => 1028.9226288000007, 'deuterium_perhour' => 393.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 1506.0, 'energy_used' => -1287.0, 'metal' => 7394.368214090002, 'crystal' => 4058.922628800001, 'deuterium' => 1393.0],
        'economy [[],{"metal":5000,"crystal":3000,"deuterium":1000},1,{"metal_basic_income":30,"crystal_basic_income":15,"deuterium_basic_income":5}]' => ['metal_perhour' => 0.0, 'crystal_perhour' => 0.0, 'deuterium_perhour' => 0.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 0.0, 'energy_used' => 0.0, 'metal' => 5030.0, 'crystal' => 3015.0, 'deuterium' => 1005.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"metal_bonus_percent":20,"crystal_bonus_percent":10,"deuterium_bonus_percent":5},1,{"planet_ressource_bonus":true}]' => ['metal_perhour' => 933.7472856360008, 'crystal_perhour' => 377.2716305600003, 'deuterium_perhour' => 137.55, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5953.747285636001, 'crystal' => 3387.27163056, 'deuterium' => 1137.55],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":5000,"crystal":3000,"deuterium":1000,"metal_bonus_percent":20,"crystal_bonus_percent":10,"deuterium_bonus_percent":5},1,{"planet_ressource_bonus":false}]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 5798.122738030001, 'crystal' => 3352.9742096, 'deuterium' => 1131.0],
        'economy [[],{"metal_mine":10,"crystal_mine":8,"deuterium_sintetizer":6,"solar_plant":12,"metal":100000,"crystal":0,"deuterium":0},100,{"max_overflow":2}]' => ['metal_perhour' => 778.1227380300007, 'crystal_perhour' => 342.9742096000002, 'deuterium_perhour' => 131.0, 'metal_max' => 10000.0, 'crystal_max' => 10000.0, 'deuterium_max' => 10000.0, 'energy' => 753.0, 'energy_used' => -643.0, 'metal' => 100000.0, 'crystal' => 20000.0, 'deuterium' => 13100.0],
        'eco hash mines 10, solar 10' => 'd023e69ce2169d0a459c91cf60c3519c',
        'eco hash mines 11, solar 10' => '9e2b3644610065bf4c45bb5256f60bd4',
        'formula 1 production 901 L0 T50 E0 F10' => 0.0,
        'formula 1 production 901 L1 T50 E0 F10' => 33.0,
        'formula 1 production 901 L7 T-40 E3 F10' => 409.23059100000023,
        'formula 1 production 901 L15 T50 E8 F10' => 1879.7616762370453,
        'formula 1 production 901 L15 T120 E8 F6' => 1127.8570057422273,
        'formula 1 production 901 L30 T20 E12 F10' => 15704.462041997804,
        'formula 1 production 901 L4 T70 E0 F0' => 0.0,
        'formula 1 production 911 L0 T50 E0 F10' => -0.0,
        'formula 1 production 911 L1 T50 E0 F10' => -11.0,
        'formula 1 production 911 L7 T-40 E3 F10' => -136.4101970000001,
        'formula 1 production 911 L15 T50 E8 F10' => -626.5872254123484,
        'formula 1 production 911 L15 T120 E8 F6' => -375.9523352474091,
        'formula 1 production 911 L30 T20 E12 F10' => -5234.820680665935,
        'formula 1 production 911 L4 T70 E0 F0' => -0.0,
        'formula 2 production 902 L0 T50 E0 F10' => 0.0,
        'formula 2 production 902 L1 T50 E0 F10' => 22.0,
        'formula 2 production 902 L7 T-40 E3 F10' => 272.8203940000002,
        'formula 2 production 902 L15 T50 E8 F10' => 1253.1744508246968,
        'formula 2 production 902 L15 T120 E8 F6' => 751.9046704948182,
        'formula 2 production 902 L30 T20 E12 F10' => 10469.64136133187,
        'formula 2 production 902 L4 T70 E0 F0' => 0.0,
        'formula 2 production 911 L0 T50 E0 F10' => -0.0,
        'formula 2 production 911 L1 T50 E0 F10' => -11.0,
        'formula 2 production 911 L7 T-40 E3 F10' => -136.4101970000001,
        'formula 2 production 911 L15 T50 E8 F10' => -626.5872254123484,
        'formula 2 production 911 L15 T120 E8 F6' => -375.9523352474091,
        'formula 2 production 911 L30 T20 E12 F10' => -5234.820680665935,
        'formula 2 production 911 L4 T70 E0 F0' => -0.0,
        'formula 3 production 903 L0 T50 E0 F10' => 0.0,
        'formula 3 production 903 L1 T50 E0 F10' => 13.0,
        'formula 3 production 903 L7 T-40 E3 F10' => 218.0,
        'formula 3 production 903 L15 T50 E8 F10' => 776.0,
        'formula 3 production 903 L15 T120 E8 F6' => 360.6000000000001,
        'formula 3 production 903 L30 T20 E12 F10' => 7119.0,
        'formula 3 production 903 L4 T70 E0 F0' => 0.0,
        'formula 3 production 911 L0 T50 E0 F10' => -0.0,
        'formula 3 production 911 L1 T50 E0 F10' => -22.0,
        'formula 3 production 911 L7 T-40 E3 F10' => -272.8203940000002,
        'formula 3 production 911 L15 T50 E8 F10' => -1253.1744508246968,
        'formula 3 production 911 L15 T120 E8 F6' => -751.9046704948182,
        'formula 3 production 911 L30 T20 E12 F10' => -10469.64136133187,
        'formula 3 production 911 L4 T70 E0 F0' => -0.0,
        'formula 4 production 911 L0 T50 E0 F10' => 0.0,
        'formula 4 production 911 L1 T50 E0 F10' => 22.0,
        'formula 4 production 911 L7 T-40 E3 F10' => 272.8203940000002,
        'formula 4 production 911 L15 T50 E8 F10' => 1253.1744508246968,
        'formula 4 production 911 L15 T120 E8 F6' => 751.9046704948182,
        'formula 4 production 911 L30 T20 E12 F10' => 10469.64136133187,
        'formula 4 production 911 L4 T70 E0 F0' => 0.0,
        'formula 12 production 903 L0 T50 E0 F10' => -0.0,
        'formula 12 production 903 L1 T50 E0 F10' => -11.0,
        'formula 12 production 903 L7 T-40 E3 F10' => -136.4101970000001,
        'formula 12 production 903 L15 T50 E8 F10' => -626.5872254123484,
        'formula 12 production 903 L15 T120 E8 F6' => -375.9523352474091,
        'formula 12 production 903 L30 T20 E12 F10' => -5234.820680665935,
        'formula 12 production 903 L4 T70 E0 F0' => -0.0,
        'formula 12 production 911 L0 T50 E0 F10' => 0.0,
        'formula 12 production 911 L1 T50 E0 F10' => 31.5,
        'formula 12 production 911 L7 T-40 E3 F10' => 359.9030964436994,
        'formula 12 production 911 L15 T50 E8 F10' => 2814.4216699637823,
        'formula 12 production 911 L15 T120 E8 F6' => 1688.6530019782697,
        'formula 12 production 911 L30 T20 E12 F10' => 99958.1850093074,
        'formula 12 production 911 L4 T70 E0 F0' => 0.0,
        'formula 22 storage 901 L0 T50 E0 F10' => 10000.0,
        'formula 22 storage 901 L1 T50 E0 F10' => 20000.0,
        'formula 22 storage 901 L7 T-40 E3 F10' => 865000.0,
        'formula 22 storage 901 L15 T50 E8 F10' => 110925000.0,
        'formula 22 storage 901 L15 T120 E8 F6' => 110925000.0,
        'formula 22 storage 901 L30 T20 E12 F10' => 984403885000.0,
        'formula 22 storage 901 L4 T70 E0 F0' => 140000.0,
        'formula 23 storage 902 L0 T50 E0 F10' => 10000.0,
        'formula 23 storage 902 L1 T50 E0 F10' => 20000.0,
        'formula 23 storage 902 L7 T-40 E3 F10' => 865000.0,
        'formula 23 storage 902 L15 T50 E8 F10' => 110925000.0,
        'formula 23 storage 902 L15 T120 E8 F6' => 110925000.0,
        'formula 23 storage 902 L30 T20 E12 F10' => 984403885000.0,
        'formula 23 storage 902 L4 T70 E0 F0' => 140000.0,
        'formula 24 storage 903 L0 T50 E0 F10' => 10000.0,
        'formula 24 storage 903 L1 T50 E0 F10' => 20000.0,
        'formula 24 storage 903 L7 T-40 E3 F10' => 865000.0,
        'formula 24 storage 903 L15 T50 E8 F10' => 110925000.0,
        'formula 24 storage 903 L15 T120 E8 F6' => 110925000.0,
        'formula 24 storage 903 L30 T20 E12 F10' => 984403885000.0,
        'formula 24 storage 903 L4 T70 E0 F0' => 140000.0,
        'formula 212 production 911 L0 T50 E0 F10' => 0.0,
        'formula 212 production 911 L1 T50 E0 F10' => 35.0,
        'formula 212 production 911 L7 T-40 E3 F10' => 140.0,
        'formula 212 production 911 L15 T50 E8 F10' => 525.0,
        'formula 212 production 911 L15 T120 E8 F6' => 420.00000000000006,
        'formula 212 production 911 L30 T20 E12 F10' => 900.0,
        'formula 212 production 911 L4 T70 E0 F0' => 0.0,
        'formula texts' => ['1/production/901' => '(30 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', '1/production/911' => '-(10 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', '2/production/902' => '(20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', '2/production/911' => '-(10 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor);', '3/production/903' => '(floor(10 * $BuildLevel * pow((1.1), $BuildLevel) * (1.44 -0.004 * $BuildTemp)) * (0.1 * $BuildLevelFactor))', '3/production/911' => '- (20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', '4/production/911' => '(20 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', '12/production/903' => '- (10 * $BuildLevel * pow(1.1,$BuildLevel) * (0.1 * $BuildLevelFactor))', '12/production/911' => '30 * $BuildLevel * pow(1.05 + ($BuildEnergy * 0.01), $BuildLevel) * (0.1 * $BuildLevelFactor)', '22/storage/901' => 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', '23/storage/902' => 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', '24/storage/903' => 'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', '212/production/911' => '((($BuildTemp + 160) / 6) * (0.1 * $BuildLevelFactor) * $BuildLevel)'],
    ];
    // GOLDEN-END

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     */
    private function economy(array $user, array $planet, int $hours): ResourceUpdate
    {
        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        $eco->CalcResource($user, $planet, false, $planet['last_update'] + $hours * 3600);

        return $eco;
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     * @return array<string, mixed> the economy figures the game derives from the building/research levels
     */
    private function figures(array $user, array $planet, int $hours = 1): array
    {
        [, $after] = $this->economy($user, $planet, $hours)->getData();
        $out = [];
        foreach (['metal_perhour', 'crystal_perhour', 'deuterium_perhour', 'metal_max', 'crystal_max', 'deuterium_max', 'energy', 'energy_used', 'metal', 'crystal', 'deuterium'] as $key) {
            $out[$key] = $after[$key];
        }

        return $out;
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        $mines = ['metal_mine' => 10, 'crystal_mine' => 8, 'deuterium_sintetizer' => 6, 'solar_plant' => 12];
        $rich = ['metal' => 5000, 'crystal' => 3000, 'deuterium' => 1000];

        yield 'fresh planet, basic income only' => [[], [], 1, []];
        yield 'fresh planet, 24 hours' => [[], [], 24, []];
        yield 'mines 10/8/6 with solar 12' => [[], $mines + $rich, 1, []];
        yield 'same, 10 hours' => [[], $mines + $rich, 10, []];
        yield 'same, hot planet (temp_max 120)' => [[], $mines + $rich + ['temp_max' => 120, 'temp_min' => 80], 1, []];
        yield 'same, cold planet (temp_max -40)' => [[], $mines + $rich + ['temp_max' => -40, 'temp_min' => -80], 1, []];
        yield 'energy deficit (solar 3)' => [[], ['metal_mine' => 15, 'crystal_mine' => 12, 'deuterium_sintetizer' => 10, 'solar_plant' => 3] + $rich, 1, []];
        yield 'mines at partial percentage' => [[], $mines + $rich + ['metal_mine_porcent' => '5', 'crystal_mine_porcent' => '8', 'deuterium_sintetizer_porcent' => '0', 'solar_plant_porcent' => '10'], 1, []];
        yield 'fusion plant 8, energy tech 5' => [['energy_tech' => 5], $mines + $rich + ['fusion_plant' => 8], 1, []];
        yield 'fusion plant but no deuterium stock' => [['energy_tech' => 5], $mines + ['fusion_plant' => 8, 'metal' => 100, 'crystal' => 100, 'deuterium' => 0], 3, []];
        yield 'solar satellites 40, no solar plant' => [[], ['metal_mine' => 8, 'crystal_mine' => 6, 'solar_satelit' => 40, 'temp_max' => 70, 'temp_min' => 30] + $rich, 1, []];
        yield 'processing technologies 4/3/2' => [['metal_proc_tech' => 4, 'crystal_proc_tech' => 3, 'deuterium_proc_tech' => 2], $mines + $rich, 1, []];
        yield 'storages 3/2/1' => [[], $mines + $rich + ['metal_store' => 3, 'crystal_store' => 2, 'deuterium_store' => 1], 1, []];
        yield 'storage cap reached after 400 hours' => [[], $mines + ['metal_store' => 1, 'metal' => 90000, 'crystal' => 90000, 'deuterium' => 90000], 400, []];
        yield 'storage multiplier 2' => [[], $mines + $rich + ['metal_store' => 2], 1, ['storage_multiplier' => 2]];
        yield 'resource multiplier x3, energy multiplier x2' => [[], $mines + $rich, 1, ['resource_multiplier' => 7500, 'energy_multiplier' => 2]];
        yield 'basic income 30/15/5' => [[], $rich, 1, ['metal_basic_income' => 30, 'crystal_basic_income' => 15, 'deuterium_basic_income' => 5]];
        yield 'planet resource bonus enabled' => [[], $mines + $rich + ['metal_bonus_percent' => 20, 'crystal_bonus_percent' => 10, 'deuterium_bonus_percent' => 5], 1, ['planet_ressource_bonus' => true]];
        yield 'planet resource bonus disabled' => [[], $mines + $rich + ['metal_bonus_percent' => 20, 'crystal_bonus_percent' => 10, 'deuterium_bonus_percent' => 5], 1, ['planet_ressource_bonus' => false]];
        yield 'max overflow 2' => [[], $mines + ['metal' => 100000, 'crystal' => 0, 'deuterium' => 0], 100, ['max_overflow' => 2]];
    }

    /**
     * @param array<string, mixed> $userOverrides
     * @param array<string, mixed> $planetOverrides
     * @param array<string, mixed> $config
     */
    #[DataProvider('scenarios')]
    public function testEconomyFigures(array $userOverrides, array $planetOverrides, int $hours, array $config): void
    {
        $this->setUniverseConfig($config);
        [$user, $planet] = $this->createPlayer($userOverrides, $planetOverrides);

        $this->goldenAssert('economy ' . json_encode([$userOverrides, $planetOverrides, $hours, $config]), $this->figures($user, $planet, $hours));
    }

    public function testVacationModeAndClosedUniverseFreezeTheEconomy(): void
    {
        [$user, $planet] = $this->createPlayer(['urlaubs_modus' => 1], ['metal_mine' => 10, 'solar_plant' => 10, 'metal' => 700]);
        [, $after] = $this->economy($user, $planet, 5)->getData();
        $this->assertEquals(700, $after['metal']);

        [$user, $planet] = $this->createPlayer([], ['metal_mine' => 10, 'solar_plant' => 10, 'metal' => 700]);
        $this->setUniverseConfig(['uni_status' => STATUS_CLOSED]);
        [, $after] = $this->economy($user, $planet, 5)->getData();
        $this->assertEquals(700, $after['metal']);
    }

    public function testMoonsProduceNothingAndHaveNoBasicIncome(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 500]);
        $planet['planet_type'] = 3;
        [, $after] = $this->economy($user, $planet, 10)->getData();

        $this->assertEquals(500, $after['metal']);
    }

    public function testEcoHashDependsOnTheEconomyInputsOnly(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal_mine' => 10, 'solar_plant' => 10]);
        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        $eco->CalcResource($user, $planet, false, $planet['last_update'] + 60);
        [, $first] = $eco->getData();
        $this->goldenAssert('eco hash mines 10, solar 10', $first['eco_hash']);

        // a changed mine level changes the hash, a changed unrelated column does not
        $renamed = $planet;
        $renamed['name'] = 'Renamed';
        $eco->CalcResource($user, $renamed, false, $planet['last_update'] + 60);
        [, $upgraded] = $eco->getData();
        $planet['metal_mine'] = 11;
        $eco->CalcResource($user, $planet, false, $planet['last_update'] + 60);
        [, $second] = $eco->getData();
        $this->assertNotSame($first['eco_hash'], $second['eco_hash']);
        $this->assertSame($first['eco_hash'], $upgraded['eco_hash']);
        $this->goldenAssert('eco hash mines 11, solar 10', $second['eco_hash']);
    }

    /** the exact (level, temperature, energy tech, percentage) inputs that are fed to every formula */
    private const FORMULA_INPUTS = [
        [0, 50, 0, 10],
        [1, 50, 0, 10],
        [7, -40, 3, 10],
        [15, 50, 8, 10],
        [15, 120, 8, 6],
        [30, 20, 12, 10],
        [4, 70, 0, 0],
    ];

    public function testEveryProductionAndStorageFormulaOfTheVarsTable(): void
    {
        $runFormula = new \ReflectionMethod(\App\Game\Core\ResourceProduction::class, 'runFormula');
        $eco = new \App\Game\Core\ResourceProduction($this->db, $this->configs, $this->state);

        $count = 0;
        foreach ($this->elements()->all() as $element => $data) {
            foreach (['production' => $data->production, 'storage' => $data->storage] as $kind => $kindFormulas) {
                foreach ($kindFormulas as $resource => $formula) {
                    foreach (self::FORMULA_INPUTS as [$level, $temp, $energy, $factor]) {
                        $value = $runFormula->invoke($eco, $formula, $level, $temp, $energy, $factor);
                        $this->goldenAssert("formula $element $kind $resource L$level T$temp E$energy F$factor", $value);
                        $count++;
                    }
                }
            }
        }
        $this->assertGreaterThan(50, $count, 'the vars table defines fewer formulas than expected');
    }

    public function testFormulaTextsOfTheVarsTable(): void
    {
        // the formula strings themselves: a later change to the vars data (or its parser) must be deliberate
        $formulas = [];
        foreach ($this->elements()->all() as $element => $data) {
            foreach (['production' => $data->production, 'storage' => $data->storage] as $kind => $kindFormulas) {
                foreach ($kindFormulas as $resource => $formula) {
                    $formulas["$element/$kind/$resource"] = $formula;
                }
            }
        }
        $this->goldenAssert('formula texts', $formulas);
    }
}
