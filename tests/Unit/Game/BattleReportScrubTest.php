<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\BattleEngine\BattleHandler;
use PHPUnit\Framework\TestCase;

/**
 * The start state stored in Rust combat reports keeps only what the report pages read.
 */
final class BattleReportScrubTest extends TestCase
{
    public function testStartStateKeepsOnlyTheWhitelistedFields(): void
    {
        $fleet = [
            'player' => ['id' => 7, 'username' => 'x', 'military_tech' => 3, 'shield_tech' => 2, 'defence_tech' => 1, 'user_lastip' => '1.2.3.4', 'ip_at_reg' => '1.2.3.4', 'b_tech' => '[]', 'discord_hook' => 'h'],
            'fleetDetail' => ['id' => 5, 'start_galaxy' => 1, 'start_system' => 2, 'start_planet' => 3, 'start_type' => 1, 'resource_metal' => 10],
            'unit' => [202 => 5],
        ];
        $round0 = ['attacker' => ['Roundstats' => []], 'attackers' => [5 => $fleet], 'defenders' => [0 => $fleet]];

        $scrubbed = BattleHandler::scrubStartState($round0);

        foreach (['attackers', 'defenders'] as $side) {
            $row = reset($scrubbed[$side]);
            $this->assertSame(['id', 'username', 'military_tech', 'shield_tech', 'defence_tech'], array_keys($row['player']));
            $this->assertSame(['start_galaxy', 'start_system', 'start_planet', 'start_type'], array_keys($row['fleetDetail']));
            $this->assertSame([202 => 5], $row['unit']);
        }
        $this->assertSame($round0['attacker'], $scrubbed['attacker']);
        $this->assertSame($scrubbed, BattleHandler::scrubStartState($scrubbed), 'idempotent');
    }
}
