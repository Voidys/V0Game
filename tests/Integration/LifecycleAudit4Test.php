<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Player\VacationMode;
use App\Game\Cronjob\CleanerCronjob;
use App\Game\Cronjob\Cronjob;
use App\Game\Cronjob\DailyCronjob;
use App\Tests\Support\GameTestCase;

/**
 * Audit round 4, lifecycle: production levels survive vacation and the inactivity reset, the cleaner's three deletion
 * groups, and bulk cronjob recalculation that must not swallow missed runs.
 */
final class LifecycleAudit4Test extends GameTestCase
{
    public function testVacationKeepsThePlayersProductionLevels(): void
    {
        [$user, $planet] = $this->createPlayer(['urlaubs_modus' => 0], ['metal_mine_porcent' => '6', 'fusion_plant_porcent' => '0', 'solar_satelit_porcent' => '3']);
        $vacation = new VacationMode($this->db, $this->configs, $this->state);

        $vacation->enable_vmode($user, $planet);
        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('0', (string) $row['metal_mine_porcent']);
        $this->assertSame('6,10,10,10,0,3', $row['porcent_saved']);

        $this->db->update('UPDATE %%USERS%% SET `urlaubs_until` = 0, `urlaubs_start` = :t WHERE id = :id;', [':t' => time() - 100, ':id' => $user['id']]);
        $user = $this->loadUser((int) $user['id']);
        $planet = $this->loadPlanet((int) $planet['id']);
        $vacation->disable_vmode($user, $planet);

        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('6', (string) $row['metal_mine_porcent']);
        $this->assertSame('0', (string) $row['fusion_plant_porcent']);
        $this->assertSame('3', (string) $row['solar_satelit_porcent']);
        $this->assertSame('', $row['porcent_saved']);
        $this->assertSame('6', (string) $planet['metal_mine_porcent'], 'the request copy shows the restored level');
    }

    public function testReturnedPlayerGetsHisLevelsBackAfterTheInactivityReset(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->setUniverseConfig(['uni_status' => STATUS_OPEN]);
        [$user, $planet] = $this->createPlayer(['onlinetime' => $now - 20 * 86400], ['metal_mine_porcent' => '4', 'last_update' => $now - 100]);
        $daily = new DailyCronjob($this->db, $this->configs, $this->state);

        $daily->updateInactiveMines(1);
        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('10', (string) $row['metal_mine_porcent']);
        $this->assertStringStartsWith('4,', $row['porcent_saved']);

        // still away: nothing is restored
        $daily->restoreReturnedMines(1);
        $this->assertSame('10', (string) $this->loadPlanet((int) $planet['id'])['metal_mine_porcent']);

        $this->db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $user['id']]);
        $daily->restoreReturnedMines(1);
        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('4', (string) $row['metal_mine_porcent']);
        $this->assertSame('', $row['porcent_saved']);
    }

    public function testCleanerDeletionGroupsAreSeparatelySwitchable(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->setUniverseConfig([
            'uni_status' => STATUS_OPEN, 'del_user_automatic' => 90,
            'del_user_automatic_on' => true, 'del_user_vacation_on' => false, 'del_user_vacation' => 0,
            'del_user_banned_on' => true, 'del_user_banned' => 30,
        ]);
        $old = $now - 100 * 86400;
        [$normal] = $this->createPlayer(['onlinetime' => $old]);
        [$vacation] = $this->createPlayer(['onlinetime' => $old, 'urlaubs_modus' => 1]);
        [$banned] = $this->createPlayer(['onlinetime' => $now - 40 * 86400, 'bana' => 1]);
        [$bannedRecent] = $this->createPlayer(['onlinetime' => $now - 10 * 86400, 'bana' => 1]);

        (new CleanerCronjob($this->db, $this->configs, $this->state))->run();

        $exists = fn(array $user): bool => !empty($this->db->selectSingle('SELECT id FROM %%USERS%% WHERE id = :id;', [':id' => $user['id']]));
        $this->assertFalse($exists($normal), 'inactive player after 90 days');
        $this->assertTrue($exists($vacation), 'vacation group switched off');
        $this->assertFalse($exists($banned), 'banned player after 30 days');
        $this->assertTrue($exists($bannedRecent));
    }

    public function testBulkRecalculationLeavesDueJobsAlone(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->db->update("UPDATE %%CRONJOBS%% SET `nextTime` = :t WHERE `class` = 'CleanerCronjob';", [':t' => $now - 3600]);
        $this->db->update("UPDATE %%CRONJOBS%% SET `nextTime` = :t WHERE `class` = 'InactiveMailCronjob';", [':t' => $now + 5]);

        (new Cronjob($this->db, $this->configs, $this->state))->reCalculateCronjobs(null, true);

        $this->assertSame($now - 3600, (int) $this->db->selectSingle("SELECT nextTime FROM %%CRONJOBS%% WHERE `class` = 'CleanerCronjob';", [], 'nextTime'));
        $this->assertGreaterThan($now + 5, (int) $this->db->selectSingle("SELECT nextTime FROM %%CRONJOBS%% WHERE `class` = 'InactiveMailCronjob';", [], 'nextTime'));
    }
}
