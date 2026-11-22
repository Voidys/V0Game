<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\RaportController;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\AuthLevel;

/**
 * Battle report visibility: participants and staff see the full report; a report without stored attacker/defender
 * lists (from before r2484) is no longer public and shows the reduced view to everybody else.
 */
final class RaportAccessTest extends GameTestCase
{
    /**
     * @param array<string, mixed> $viewer
     * @return array{0: bool, 1: bool}
     */
    private function access(array $viewer, string $attackers, string $defenders): array
    {
        $this->state->USER = $viewer;
        $controller = (new RaportController())->withGameServices($this->db, $this->configs, $this->state);

        return (new \ReflectionMethod($controller, 'detailAccess'))->invoke($controller, ['attacker' => $attackers, 'defender' => $defenders]);
    }

    public function testEmptyParticipantListsAreNotPublic(): void
    {
        [$viewer] = $this->createPlayer();

        $this->assertSame([false, false], $this->access($viewer, '', ''));
    }

    public function testParticipantsAndStaffSeeTheFullReport(): void
    {
        [$attacker] = $this->createPlayer();
        [$defender] = $this->createPlayer();
        [$staff] = $this->createPlayer(['authlevel' => AuthLevel::Moderator->value]);
        $list = $attacker['id'] . ',' . 999999;

        $this->assertTrue($this->access($attacker, $list, (string) $defender['id'])[0]);
        $this->assertTrue($this->access($defender, $list, (string) $defender['id'])[0]);
        $this->assertTrue($this->access($staff, '', '')[0]);
        [$stranger] = $this->createPlayer();
        $this->assertFalse($this->access($stranger, $list, (string) $defender['id'])[0]);
    }

    public function testOwnSimulationOpensInFull(): void
    {
        [$player] = $this->createPlayer();
        [$other] = $this->createPlayer();

        $this->assertSame([true, true], $this->access($player, 'sim:' . $player['id'], ''));
        $this->assertFalse($this->access($other, 'sim:' . $player['id'], '')[0]);
    }
}
