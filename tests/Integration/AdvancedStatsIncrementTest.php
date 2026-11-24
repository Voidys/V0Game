<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Tests\Support\GameTestCase;

/**
 * The counters are integers: fractional amounts (debris values are floats) are cut off toward zero without PHP
 * deprecations, and the counter keeps adding up.
 */
final class AdvancedStatsIncrementTest extends GameTestCase
{
    public function testFractionalAmountsAreCutOffExplicitly(): void
    {
        [$user] = $this->createPlayer();
        $stats = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, 12.9);
        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, '3.7');
        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, 5);

        $this->assertEquals(20, $stats->getValue((int) $user['id'], AdvancedStatsEnum::KBMETAL));
    }

    public function testCounterThresholdsGrantAchievements(): void
    {
        [$user] = $this->createPlayer();
        $userId = (int) $user['id'];
        $stats = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $achievements = new \App\Game\Core\Achievement($this->db, $this->configs, $this->state);

        $stats->increment($userId, AdvancedStatsEnum::RAIDED_METAL, 600000000);
        $this->assertFalse($achievements->checkAchievement($userId, 74));
        $stats->increment($userId, AdvancedStatsEnum::RAIDED_DEUTERIUM, 400000000);
        $this->assertTrue($achievements->checkAchievement($userId, 74));

        $stats->increment($userId, AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
        $this->assertTrue($achievements->checkAchievement($userId, 78));

        $stats->increment($userId, AdvancedStatsEnum::EXPO_COUNT, 999);
        $this->assertFalse($achievements->checkAchievement($userId, 81));
        $stats->increment($userId, AdvancedStatsEnum::EXPO_COUNT);
        $this->assertTrue($achievements->checkAchievement($userId, 81));
    }
}
