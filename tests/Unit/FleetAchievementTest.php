<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Achievements\FleetAchievement;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use PHPUnit\Framework\TestCase;

class FleetAchievementTest extends TestCase
{
    private function achievement(Database $db): FleetAchievement
    {
        return new FleetAchievement($db, $this->createStub(ConfigRegistry::class), $this->createStub(Singleton::class));
    }

    public function testMoonTriesBelowTenTouchNothing(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('selectSingle');
        $db->expects(self::never())->method('insert');

        $this->achievement($db)->checkMoonTries(5, 9, 0);
    }

    public function testMoonTriesWithAMoonAlreadyReceivedTouchNothing(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('selectSingle');
        $db->expects(self::never())->method('insert');

        $this->achievement($db)->checkMoonTries(5, 12, 1);
    }

    public function testMissileDispatchIsIgnored(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('update');
        $db->expects(self::never())->method('select');

        $this->achievement($db)->onDispatch(5, 1, \App\Game\Enum\Mission::Missile->value, 1000, 2000, 0.0, 1);
    }
}
