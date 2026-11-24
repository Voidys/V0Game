<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Achievements\PlanetRessAchievement;
use PHPUnit\Framework\TestCase;

class PlanetRessAchievementTest extends TestCase
{
    public function testEnergyDeficitNeedsConsumersAndNoMoon(): void
    {
        self::assertTrue(PlanetRessAchievement::hasEnergyDeficit(['planet_type' => 1, 'energy' => 50, 'energy_used' => -100]));
        self::assertFalse(PlanetRessAchievement::hasEnergyDeficit(['planet_type' => 1, 'energy' => 100, 'energy_used' => -100]));
        self::assertFalse(PlanetRessAchievement::hasEnergyDeficit(['planet_type' => 1, 'energy' => 0, 'energy_used' => 0]));
        self::assertFalse(PlanetRessAchievement::hasEnergyDeficit(['planet_type' => 3, 'energy' => 0, 'energy_used' => -10]));
    }

    public function testLowEnergySinceFlipsOnlyOnStateChange(): void
    {
        self::assertSame(1000, PlanetRessAchievement::nextLowEnergySince(0, true, 1000));
        self::assertSame(1000, PlanetRessAchievement::nextLowEnergySince(1000, true, 5000));
        self::assertSame(0, PlanetRessAchievement::nextLowEnergySince(1000, false, 5000));
        self::assertSame(0, PlanetRessAchievement::nextLowEnergySince(0, false, 5000));
    }

    public function testLowEnergyNeedsMoreThanSevenDays(): void
    {
        self::assertFalse(PlanetRessAchievement::lowEnergyLongEnough(0, 10 * 86400));
        self::assertFalse(PlanetRessAchievement::lowEnergyLongEnough(1000, 1000 + 7 * 86400));
        self::assertTrue(PlanetRessAchievement::lowEnergyLongEnough(1000, 1001 + 7 * 86400));
    }
}
