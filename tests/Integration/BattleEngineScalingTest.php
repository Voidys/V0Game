<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\BattleEngine\BattleEngineSteemNova;
use App\Game\BattleEngine\BattleFleet;
use App\Tests\Support\GameTestCase;

/**
 * The PHP engine scales huge fights down to its ship budget. Small stacks are rounded stochastically (no inflation to one
 * full ship) and a type that sat the fight out shares the losses of its fleet (or side).
 */
final class BattleEngineScalingTest extends GameTestCase
{
    /** engine with a tiny ship budget, so ordinary fleets are scaled */
    private function engine(): BattleEngineSteemNova
    {
        return new class ($this->db, $this->configs, $this->state) extends BattleEngineSteemNova {
            protected function shipBudget(int $ships): int
            {
                return 500;
            }
        };
    }

    /** @param array<int, int> $units */
    private function fleet(int $id, int $userId, array $units): BattleFleet
    {
        return new BattleFleet(
            $id,
            ['id' => $userId, 'username' => 'p' . $userId, 'military_tech' => 0, 'shield_tech' => 0, 'defence_tech' => 0],
            $units,
            ['start_galaxy' => 1, 'start_system' => 2, 'start_planet' => 3, 'start_type' => 1, 'resource_metal' => 0, 'resource_crystal' => 0, 'resource_deuterium' => 0],
        );
    }

    public function testScaledFightReportsRealAmountsAndBoundedSurvivors(): void
    {
        for ($seed = 1; $seed <= 5; $seed++) {
            mt_srand($seed);
            $attackers = [1 => $this->fleet(1, 1, [204 => 20000, 207 => 2])];
            $defenders = [0 => $this->fleet(0, 2, [204 => 2000, 206 => 3])];

            $result = $this->engine()->fight($attackers, $defenders, 1, true);

            $this->assertNotFalse($result);
            $start = $result->toArray()['rw'][0];
            $this->assertSame(20000, (int) $start['attackers'][1]['unit'][204], 'round 0 shows the real amounts');
            $this->assertSame(2, (int) $start['attackers'][1]['unit'][207]);
            $this->assertSame(3, (int) $start['defenders'][0]['unit'][206]);
            foreach ([[$attackers[1], [204 => 20000, 207 => 2]], [$defenders[0], [204 => 2000, 206 => 3]]] as [$fleet, $real]) {
                foreach ($real as $element => $amount) {
                    $left = (int) ($fleet->units[$element] ?? 0);
                    $this->assertGreaterThanOrEqual(0, $left);
                    $this->assertLessThanOrEqual($amount, $left);
                }
            }
        }
    }

    public function testASingleBigShipIsNotInflatedToAFullScaledShip(): void
    {
        $lost = 0;
        for ($seed = 1; $seed <= 10; $seed++) {
            mt_srand($seed);
            // 1 Death Star (rapid fire 200 against light fighters) among ~20000 ships: inflated to one scaled ship it would
            // kill ~200 scaled = ~8000 real light fighters per round
            $attackers = [1 => $this->fleet(1, 1, [204 => 20000])];
            $defenders = [0 => $this->fleet(0, 2, [214 => 1, 204 => 20])];

            $this->assertNotFalse($this->engine()->fight($attackers, $defenders, 1, true));
            $lost += 20000 - (int) ($attackers[1]->units[204] ?? 0);
        }
        // one real Death Star kills at most ~1200 light fighters per fight (6 rounds); averaged over the seeds well below that
        $this->assertLessThan(10 * 3000, $lost, 'the Death Star kept its real weight');
    }

    public function testSatOutTypeSharesAWipeOut(): void
    {
        for ($seed = 1; $seed <= 5; $seed++) {
            mt_srand($seed);
            // the defender's single cruiser is far below the scale (~41): it fights at most rarely, but the whole fleet dies
            $attackers = [1 => $this->fleet(1, 1, [204 => 20000])];
            $defenders = [0 => $this->fleet(0, 2, [204 => 300, 206 => 1])];

            $result = $this->engine()->fight($attackers, $defenders, 1, true);

            $this->assertNotFalse($result);
            $this->assertSame(0, (int) ($defenders[0]->units[204] ?? 0));
            $this->assertSame(0, (int) ($defenders[0]->units[206] ?? 0), 'a type that sat the fight out shares the wipe-out');
        }
    }
}
