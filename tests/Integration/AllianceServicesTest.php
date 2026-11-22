<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Alliance\AllianceException;
use App\Game\Alliance\AllianceRights;
use App\Game\Alliance\AllianceServices;
use App\Game\Alliance\AllianceText;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\DiploType;

/**
 * Pins the rules of the alliance services (App\Game\Alliance): founding, applying and accepting, rank rights,
 * kick rules (the leader cannot be kicked, nobody kicks a member holding a right he lacks), leadership transfer,
 * diplomacy scoping (create/accept/reject/delete only between the own alliance and another one), leaving, disbanding,
 * circular messages and the settings page. The services are driven exactly as AllianceController drives them: the
 * acting user is `state->USER`, an early exit is an AllianceException.
 *
 * Accepting an application (and the "full" rejection) runs a real SQL transaction in the service; MySQL commits the
 * test transaction implicitly there, so those tests purge their rows themselves (see purge()).
 */
final class AllianceServicesTest extends GameTestCase
{
    /** @var list<int> users created by tests that cannot rely on the rollback */
    private array $purgeUsers = [];
    /** @var list<int> */
    private array $purgeAlliances = [];

    protected function tearDown(): void
    {
        if ($this->purgeUsers !== [] || $this->purgeAlliances !== []) {
            $this->purge();
        }
        parent::tearDown();
    }

    /**
     * @param array<mixed> $actor
     */
    private function services(array $actor): AllianceServices
    {
        $this->state->USER = $actor;

        return new AllianceServices($this->db, $this->configs, $this->state);
    }

    /** @return array<string, mixed> */
    private function player(): array
    {
        return $this->createPlayer()[0];
    }

    /**
     * Founds an alliance with a fresh player; returns [owner row, alliance row].
     *
     * @return array<mixed>
     */
    private function foundAlliance(string $tag, string $name): array
    {
        $owner = $this->player();
        $this->services($owner)->membership()->found($tag, $name);

        return [$this->loadUser((int) $owner['id']), $this->alliance($tag)];
    }

    /** @return array<string, mixed> */
    private function alliance(string $tag): array
    {
        return $this->db->selectSingle('SELECT * FROM %%ALLIANCE%% WHERE ally_tag = :tag AND ally_universe = 1;', [':tag' => $tag]);
    }

    /**
     * @return array<mixed>
     */
    private function reloadAlliance(int|string $id): array|false
    {
        return $this->db->selectSingle('SELECT * FROM %%ALLIANCE%% WHERE id = :id;', [':id' => $id]);
    }

    /**
     * Adds an existing player to an alliance (with an optional rank id) and fixes the counter.
     *
     * @param array<string, mixed> $alliance
     * @param array<mixed> $player
     * @return array<mixed>
     */
    private function join(array $player, array $alliance, int $rankId = 0): array
    {
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a, ally_rank_id = :r, ally_register_time = 1 WHERE id = :u;', [
            ':a' => $alliance['id'], ':r' => $rankId, ':u' => $player['id'],
        ]);
        $this->db->update('UPDATE %%ALLIANCE%% SET ally_members = (SELECT COUNT(*) FROM %%USERS%% WHERE ally_id = :a) WHERE id = :a;', [':a' => $alliance['id']]);

        return $this->loadUser((int) $player['id']);
    }

    /**
     * Inserts a rank with the given rights switched on; returns its rank id.
     *
     * @param array<string, mixed> $alliance
     * @param array<mixed> $rights
     */
    private function rank(array $alliance, string $name, array $rights = []): int
    {
        $this->db->insert('INSERT INTO %%ALLIANCE_RANK%% SET rankName = :n, allianceID = :a;', [':n' => $name, ':a' => $alliance['id']]);
        $rankId = (int) $this->db->lastInsertId();
        foreach ($rights as $right) {
            $this->db->update("UPDATE %%ALLIANCE_RANK%% SET `$right` = 1 WHERE rankID = :r;", [':r' => $rankId]);
        }

        return $rankId;
    }

    /**
     * @param array<string, mixed> $alliance
     * @param array<string, mixed> $user
     * @return array<string, bool|int|string>
     */
    private function rights(array $user, array $alliance): array
    {
        return $this->services($user)->repository()->rightsOf($user, $alliance);
    }

    private function exitOf(callable $action): AllianceException
    {
        try {
            $action();
        } catch (AllianceException $exit) {
            return $exit;
        }
        self::fail('expected an AllianceException');
    }

    private function messageCount(int|string $userId): int
    {
        // received (unread) messages: the sender of a message additionally gets a read copy
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% WHERE user_id = :u AND message_unread = 1;', [':u' => $userId], 'c');
    }

    /** removes the rows of tests that ran a transaction themselves, then opens a transaction for the rollback in tearDown */
    private function purge(): void
    {
        foreach ($this->purgeUsers as $id) {
            foreach (['PLANETS' => 'id_owner', 'STATPOINTS' => 'id_owner', 'USERS_SETTINGS' => 'user_id', 'MESSAGES' => 'message_owner', 'ALLIANCE_REQUEST' => 'userId', 'USERS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        foreach ($this->purgeAlliances as $id) {
            $this->db->delete('DELETE FROM %%ALLIANCE%% WHERE id = :id;', [':id' => $id]);
            $this->db->delete('DELETE FROM %%ALLIANCE_RANK%% WHERE allianceID = :id;', [':id' => $id]);
        }
        $this->purgeUsers = $this->purgeAlliances = [];
        $this->db->startTransaction();
    }

    // ---- founding -------------------------------------------------------------------------------------------

    public function testFoundAllianceMakesTheFounderLeader(): void
    {
        $owner = $this->player();
        $label = $this->services($owner)->membership()->found('TAG1', 'First Alliance');

        self::assertSame('First Alliance [TAG1]', $label);
        $alliance = $this->alliance('TAG1');
        self::assertSame('First Alliance', $alliance['ally_name']);
        self::assertSame((int) $owner['id'], (int) $alliance['ally_owner']);
        self::assertSame(1, (int) $alliance['ally_members']);
        $reloaded = $this->loadUser((int) $owner['id']);
        self::assertSame((int) $alliance['id'], (int) $reloaded['ally_id']);
        self::assertSame(0, (int) $reloaded['ally_rank_id']);
    }

    public function testFoundRejectsInvalidDuplicateAndTooLongNames(): void
    {
        $this->foundAlliance('DUP', 'Dup Alliance');
        $second = $this->player();
        $membership = $this->services($second)->membership();

        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $membership->found('', 'Name'))->kind);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $membership->found('TAG', ''))->kind);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $membership->found('TOOLONGTAG', 'Name'))->kind);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $membership->found('TAG', str_repeat('n', 31)))->kind);
        $duplicate = $this->exitOf(fn() => $membership->found('DUP', 'Other Name'));
        self::assertSame(AllianceException::MESSAGE, $duplicate->kind);
        self::assertSame('game_alliance_create', $duplicate->buttons[0]['url']);
        self::assertSame(0, (int) $this->loadUser((int) $second['id'])['ally_id']);
    }

    public function testFoundStripsForbiddenCharacters(): void
    {
        $owner = $this->player();
        $label = $this->services($owner)->membership()->found('A<b>', 'My <i>Club');

        self::assertSame('My iClub [Ab]', $label);
    }

    public function testFoundNeedsTheMinimumPoints(): void
    {
        $owner = $this->player();
        $this->configs->get()->alliance_create_min_points = 100000;

        $exit = $this->exitOf(fn() => $this->services($owner)->membership()->assertMayFound());
        self::assertSame(AllianceException::MESSAGE, $exit->kind);
        self::assertSame('game_alliance_show', $exit->buttons[0]['url']);
    }

    // ---- applications ---------------------------------------------------------------------------------------

    public function testApplyAndCancel(): void
    {
        [$owner, $alliance] = $this->foundAlliance('APP', 'Apply Alliance');
        $applicant = $this->player();
        $applications = $this->services($applicant)->applications();

        $target = $applications->applicationTarget($alliance['id']);
        self::assertSame('APP', $target['ally_tag']);

        $applications->submit($alliance['id'], 'let me in');
        self::assertTrue($this->services($applicant)->repository()->hasOpenApplication((int) $applicant['id']));
        self::assertSame(1, (int) $this->services($owner)->repository()->countApplications($alliance['id']));
        self::assertSame(1, $this->messageCount($owner['id']), 'the leader is told about the application');
        $applications = $this->services($applicant)->applications();
        self::assertSame('APP', $applications->pendingTarget()['ally_tag']);

        self::assertSame('APP', $applications->cancel());
        self::assertFalse($this->services($applicant)->repository()->hasOpenApplication((int) $applicant['id']));
    }

    public function testApplyIsRefusedForClosedFullOrUnknownAlliances(): void
    {
        [, $alliance] = $this->foundAlliance('CLO', 'Closed Alliance');
        $applicant = $this->player();
        $applications = $this->services($applicant)->applications();

        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $applications->applicationTarget(999999))->kind);

        $this->db->update('UPDATE %%ALLIANCE%% SET ally_request_notallow = 1 WHERE id = :id;', [':id' => $alliance['id']]);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $applications->applicationTarget($alliance['id']))->kind);

        $this->db->update('UPDATE %%ALLIANCE%% SET ally_request_notallow = 0, ally_max_members = 1 WHERE id = :id;', [':id' => $alliance['id']]);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $applications->applicationTarget($alliance['id']))->kind);
    }

    public function testApplyRespectsTheMinimumPoints(): void
    {
        [, $alliance] = $this->foundAlliance('MIN', 'Min Points');
        $this->db->update('UPDATE %%ALLIANCE%% SET ally_request_min_points = 5000 WHERE id = :id;', [':id' => $alliance['id']]);
        $applicant = $this->player();

        $exit = $this->exitOf(fn() => $this->services($applicant)->applications()->applicationTarget($alliance['id']));
        self::assertSame(AllianceException::MESSAGE, $exit->kind);
    }

    public function testAcceptingAnApplicationMakesTheApplicantAMember(): void
    {
        [$owner, $alliance] = $this->foundAlliance('ACC', 'Accept Alliance');
        $applicant = $this->player();
        $this->purgeUsers = [(int) $owner['id'], (int) $applicant['id']];
        $this->purgeAlliances = [(int) $alliance['id']];
        $this->services($applicant)->applications()->submit($alliance['id'], 'please');
        $applyId = $this->db->selectSingle('SELECT applyID FROM %%ALLIANCE_REQUEST%% WHERE userId = :u;', [':u' => $applicant['id']], 'applyID');

        $result = $this->services($owner)->applications()->answer($alliance, $applyId, 'yes', 'welcome');

        self::assertSame('yes', $result);
        $member = $this->loadUser((int) $applicant['id']);
        self::assertSame((int) $alliance['id'], (int) $member['ally_id']);
        self::assertSame(0, (int) $member['ally_rank_id']);
        self::assertSame(2, (int) $this->reloadAlliance($alliance['id'])['ally_members']);
        self::assertSame(0, (int) $this->services($owner)->repository()->countApplications($alliance['id']));
        self::assertSame(1, $this->messageCount($applicant['id']), 'the applicant gets the answer');
    }

    public function testDecliningDropsTheApplication(): void
    {
        [$owner, $alliance] = $this->foundAlliance('DEC', 'Decline Alliance');
        $applicant = $this->player();
        $this->services($applicant)->applications()->submit($alliance['id'], 'please');
        $applyId = $this->db->selectSingle('SELECT applyID FROM %%ALLIANCE_REQUEST%% WHERE userId = :u;', [':u' => $applicant['id']], 'applyID');

        self::assertSame('no', $this->services($owner)->applications()->answer($alliance, $applyId, 'no', 'sorry'));
        self::assertSame(0, (int) $this->loadUser((int) $applicant['id'])['ally_id']);
        self::assertSame(1, $this->messageCount($applicant['id']));
        // the request is gone: answering again is a no-op
        self::assertNull($this->services($owner)->applications()->answer($alliance, $applyId, 'yes', ''));
    }

    public function testAnswerOnlyReachesApplicationsOfTheOwnAlliance(): void
    {
        [, $alliance] = $this->foundAlliance('OWN', 'Own Alliance');
        [$other, $otherAlliance] = $this->foundAlliance('OTH', 'Other Alliance');
        $applicant = $this->player();
        $this->services($applicant)->applications()->submit($alliance['id'], 'please');
        $applyId = $this->db->selectSingle('SELECT applyID FROM %%ALLIANCE_REQUEST%% WHERE userId = :u;', [':u' => $applicant['id']], 'applyID');

        self::assertNull($this->services($other)->applications()->answer($otherAlliance, $applyId, 'yes', ''));
        self::assertTrue($this->services($applicant)->repository()->hasOpenApplication((int) $applicant['id']));
        self::assertNull($this->services($other)->applications()->find($otherAlliance, $applyId));
    }

    public function testAcceptingIntoAFullAllianceDeclinesInstead(): void
    {
        [$owner, $alliance] = $this->foundAlliance('FUL', 'Full Alliance');
        $applicant = $this->player();
        $this->purgeUsers = [(int) $owner['id'], (int) $applicant['id']];
        $this->purgeAlliances = [(int) $alliance['id']];
        $this->services($applicant)->applications()->submit($alliance['id'], 'please');
        $applyId = $this->db->selectSingle('SELECT applyID FROM %%ALLIANCE_REQUEST%% WHERE userId = :u;', [':u' => $applicant['id']], 'applyID');
        $this->db->update('UPDATE %%ALLIANCE%% SET ally_max_members = 1 WHERE id = :id;', [':id' => $alliance['id']]);

        $result = $this->services($owner)->applications()->answer($this->reloadAlliance($alliance['id']), $applyId, 'yes', '');

        self::assertSame('no', $result);
        self::assertSame(0, (int) $this->loadUser((int) $applicant['id'])['ally_id']);
    }

    public function testAcceptingAnApplicantWhoJoinedElsewhereRedirects(): void
    {
        [$owner, $alliance] = $this->foundAlliance('GON', 'Gone Alliance');
        [, $otherAlliance] = $this->foundAlliance('ELS', 'Elsewhere');
        $applicant = $this->player();
        $this->purgeUsers = [(int) $owner['id'], (int) $applicant['id']];
        $this->purgeAlliances = [(int) $alliance['id'], (int) $otherAlliance['id']];
        $this->services($applicant)->applications()->submit($alliance['id'], 'please');
        $applyId = $this->db->selectSingle('SELECT applyID FROM %%ALLIANCE_REQUEST%% WHERE userId = :u;', [':u' => $applicant['id']], 'applyID');
        $this->join($applicant, $otherAlliance);

        $exit = $this->exitOf(fn() => $this->services($owner)->applications()->answer($alliance, $applyId, 'yes', ''));

        self::assertSame(AllianceException::REDIRECT, $exit->kind);
        self::assertSame((int) $otherAlliance['id'], (int) $this->loadUser((int) $applicant['id'])['ally_id']);
    }

    // ---- ranks and rights -----------------------------------------------------------------------------------

    public function testLeaderHoldsEveryRightAndMembersTheirRanksRights(): void
    {
        [$owner, $alliance] = $this->foundAlliance('RIG', 'Rights Alliance');
        $rankId = $this->rank($alliance, 'Officer', ['MEMBERLIST', 'KICK']);
        $officer = $this->join($this->player(), $alliance, $rankId);
        $plain = $this->join($this->player(), $alliance);

        self::assertSame(AllianceRights::all(true), $this->rights($owner, $alliance));
        $officerRights = $this->rights($officer, $alliance);
        self::assertEquals(1, $officerRights['MEMBERLIST']);
        self::assertEquals(1, $officerRights['KICK']);
        self::assertEquals(0, $officerRights['RANKS']);
        self::assertSame(AllianceRights::all(false), $this->rights($plain, $alliance));
    }

    public function testRankCreationOnlyGrantsRightsTheActorHolds(): void
    {
        [$owner, $alliance] = $this->foundAlliance('RAN', 'Rank Alliance');
        $managerRank = $this->rank($alliance, 'Rank Manager', ['RANKS', 'MEMBERLIST']);
        $manager = $this->join($this->player(), $alliance, $managerRank);
        $rights = $this->rights($manager, $alliance);

        $this->services($manager)->ranks()->create($alliance, $rights, ['rankName' => 'Scout', '0' => '1', '7' => '1', '9' => '1']);

        $scout = $this->db->selectSingle('SELECT * FROM %%ALLIANCE_RANK%% WHERE allianceID = :a AND rankName = :n;', [':a' => $alliance['id'], ':n' => 'Scout']);
        self::assertEquals(1, $scout['MEMBERLIST'], 'right the manager holds (index 0) is set');
        self::assertEquals(0, $scout['KICK'], 'KICK (index 7) is a right the manager lacks');
        self::assertEquals(1, $scout['RANKS'], 'RANKS (index 9) is a right the manager holds');
        // the leader can grant everything
        $this->services($owner)->ranks()->create($alliance, AllianceRights::all(true), ['rankName' => 'Warden', '7' => '1']);
        self::assertEquals(1, $this->db->selectSingle('SELECT KICK FROM %%ALLIANCE_RANK%% WHERE allianceID = :a AND rankName = :n;', [':a' => $alliance['id'], ':n' => 'Warden'], 'KICK'));
    }

    public function testRankNamesAreValidated(): void
    {
        [$owner, $alliance] = $this->foundAlliance('RNM', 'Rank Names');
        $ranks = $this->services($owner)->ranks();
        $rank = $this->rank($alliance, 'Named');

        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $ranks->create($alliance, AllianceRights::all(true), ['rankName' => str_repeat('x', 33)]))->kind);
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $ranks->update($alliance, AllianceRights::all(true), [$rank => ['rankName' => str_repeat('x', 33)]]))->kind);
    }

    public function testRankUpdateLeavesRightsTheActorLacksUntouched(): void
    {
        [, $alliance] = $this->foundAlliance('UPD', 'Update Alliance');
        $managerRank = $this->rank($alliance, 'Manager', ['RANKS', 'MEMBERLIST']);
        $manager = $this->join($this->player(), $alliance, $managerRank);
        $target = $this->rank($alliance, 'Target', ['KICK', 'MEMBERLIST']);
        $low = $this->rank($alliance, 'Low');
        $rights = $this->rights($manager, $alliance);
        $ranks = $this->services($manager)->ranks();

        // a rank carrying a right the manager lacks (KICK) is out of reach: neither renamed nor stripped
        $ranks->update($alliance, $rights, [$target => ['rankName' => 'Renamed']]);
        $row = $this->db->selectSingle('SELECT * FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $target]);
        self::assertSame('Target', $row['rankName']);
        self::assertEquals(1, $row['KICK']);
        self::assertEquals(1, $row['MEMBERLIST']);

        // the own rank is out of reach too
        $ranks->update($alliance, $rights, [$managerRank => ['rankName' => 'Boss']]);
        self::assertSame('Manager', $this->db->selectSingle('SELECT rankName FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $managerRank], 'rankName'));

        // a lower rank can be renamed, only rights the manager holds are written
        $ranks->update($alliance, $rights, [$low => ['rankName' => 'Renamed', '7' => '1']]);
        $row = $this->db->selectSingle('SELECT * FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $low]);
        self::assertSame('Renamed', $row['rankName']);
        self::assertEquals(0, $row['KICK'], 'KICK is not held by the manager');

        // deleting the out-of-reach rank is refused
        $ranks->delete($alliance, $rights, $target);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $target], 'c'));
    }

    public function testPermissionPageMarksOwnAndOutOfReachRanksAsUnchangeable(): void
    {
        [, $alliance] = $this->foundAlliance('PER', 'Permission Alliance');
        $managerRank = $this->rank($alliance, 'Manager', ['RANKS', 'MEMBERLIST']);
        $low = $this->rank($alliance, 'Low', ['MEMBERLIST']);
        $high = $this->rank($alliance, 'High', ['MEMBERLIST', 'KICK']);
        $manager = $this->join($this->player(), $alliance, $managerRank);

        $page = $this->services($manager)->ranks()->permissionPage($alliance, $this->rights($manager, $alliance));

        self::assertFalse($page['changeableRanks'][$managerRank], 'the own rank cannot be changed');
        self::assertTrue($page['changeableRanks'][$low]);
        self::assertFalse($page['changeableRanks'][$high], 'carries KICK which the manager lacks');
        self::assertSame(['MEMBERLIST', 'RANKS'], array_values($page['availableRanks']));
    }

    public function testRankDeleteResetsMembersAndStaysInsideTheAlliance(): void
    {
        [$owner, $alliance] = $this->foundAlliance('RDL', 'Rank Delete');
        [, $other] = $this->foundAlliance('ROT', 'Rank Other');
        $rank = $this->rank($alliance, 'Doomed');
        $foreignRank = $this->rank($other, 'Foreign');
        $member = $this->join($this->player(), $alliance, $rank);

        $this->services($owner)->ranks()->delete($alliance, AllianceRights::all(true), $foreignRank);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $foreignRank], 'c'), 'a rank of another alliance survives');

        $this->services($owner)->ranks()->delete($alliance, AllianceRights::all(true), $rank);
        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_RANK%% WHERE rankID = :r;', [':r' => $rank], 'c'));
        self::assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_rank_id']);
    }

    public function testRankAssignmentSkipsTheLeaderAndRanksBeyondTheActorsRights(): void
    {
        [$owner, $alliance] = $this->foundAlliance('ASG', 'Assign Alliance');
        $managerRank = $this->rank($alliance, 'Manager', ['MANAGEUSERS', 'MEMBERLIST']);
        $low = $this->rank($alliance, 'Low', ['MEMBERLIST']);
        $high = $this->rank($alliance, 'High', ['KICK']);
        $manager = $this->join($this->player(), $alliance, $managerRank);
        $member = $this->join($this->player(), $alliance);
        $rights = $this->rights($manager, $alliance);

        $this->services($manager)->ranks()->assign($alliance, $rights, [
            $member['id'] => $high,
            $owner['id'] => $low,
        ]);
        self::assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_rank_id'], 'rank with KICK is out of reach');
        self::assertSame(0, (int) $this->loadUser((int) $owner['id'])['ally_rank_id'], 'the leader is never given a rank');

        $this->services($manager)->ranks()->assign($alliance, $rights, [$member['id'] => $low]);
        self::assertSame($low, (int) $this->loadUser((int) $member['id'])['ally_rank_id']);
        $this->services($manager)->ranks()->assign($alliance, $rights, [$member['id'] => 0]);
        self::assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_rank_id']);
    }

    public function testRankAssignmentCannotTouchPlayersOfOtherAlliances(): void
    {
        [$owner, $alliance] = $this->foundAlliance('SCO', 'Scope Alliance');
        [$otherOwner] = $this->foundAlliance('SC2', 'Scope Two');
        $low = $this->rank($alliance, 'Low', ['MEMBERLIST']);

        $this->services($owner)->ranks()->assign($alliance, AllianceRights::all(true), [$otherOwner['id'] => $low]);

        self::assertSame(0, (int) $this->loadUser((int) $otherOwner['id'])['ally_rank_id']);
    }

    // ---- kick ----------------------------------------------------------------------------------------------

    public function testKickRules(): void
    {
        [$owner, $alliance] = $this->foundAlliance('KIK', 'Kick Alliance');
        $officerRank = $this->rank($alliance, 'Officer', ['KICK']);
        $seniorRank = $this->rank($alliance, 'Senior', ['KICK', 'RANKS']);
        $officer = $this->join($this->player(), $alliance, $officerRank);
        $senior = $this->join($this->player(), $alliance, $seniorRank);
        $plain = $this->join($this->player(), $alliance);
        $plain2 = $this->join($this->player(), $alliance);
        [$foreigner] = $this->foundAlliance('FOR', 'Foreign Kick');
        $alliance = $this->reloadAlliance($alliance['id']);
        $rights = $this->rights($officer, $alliance);

        $kick = fn(array $actor, array $actorRights, array $target) => $this->exitOf(fn() => $this->services($actor)->membership()->kick($alliance, $actorRights, $target['id']));

        self::assertSame(AllianceException::HOME, $kick($officer, $rights, $owner)->kind, 'the leader cannot be kicked');
        self::assertSame(AllianceException::HOME, $kick($officer, $rights, $officer)->kind, 'nobody kicks himself');
        self::assertSame(AllianceException::HOME, $kick($officer, $rights, $senior)->kind, 'the target holds RANKS which the officer lacks');
        self::assertSame(AllianceException::HOME, $kick($officer, $rights, $foreigner)->kind, 'only members of the own alliance');
        self::assertSame(AllianceException::HOME, $kick($owner, AllianceRights::all(true), $owner)->kind, 'not even the leader kicks himself');
        self::assertSame(5, (int) $this->reloadAlliance($alliance['id'])['ally_members']);

        $this->services($officer)->membership()->kick($alliance, $rights, $plain['id']);
        self::assertSame(0, (int) $this->loadUser((int) $plain['id'])['ally_id']);
        self::assertSame(4, (int) $this->reloadAlliance($alliance['id'])['ally_members']);

        $this->services($owner)->membership()->kick($alliance, AllianceRights::all(true), $senior['id']);
        self::assertSame(0, (int) $this->loadUser((int) $senior['id'])['ally_id']);
        self::assertSame(0, (int) $this->loadUser((int) $senior['id'])['ally_rank_id']);
        self::assertSame(3, (int) $this->reloadAlliance($alliance['id'])['ally_members']);
        self::assertSame((int) $alliance['id'], (int) $this->loadUser((int) $plain2['id'])['ally_id']);
    }

    // ---- transfer -----------------------------------------------------------------------------------------

    public function testTransferNeedsTheLeaderAndASuccessorWithTheTransferRight(): void
    {
        [$owner, $alliance] = $this->foundAlliance('TRA', 'Transfer Alliance');
        $heirRank = $this->rank($alliance, 'Heir', ['TRANSFER']);
        $otherRank = $this->rank($alliance, 'Clerk', ['MEMBERLIST']);
        $heir = $this->join($this->player(), $alliance, $heirRank);
        $clerk = $this->join($this->player(), $alliance, $otherRank);
        $alliance = $this->reloadAlliance($alliance['id']);

        // not the leader
        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $this->services($heir)->membership()->transfer($alliance, $heir['id']))->kind);
        // successor without the TRANSFER right, the leader himself, a stranger
        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $this->services($owner)->membership()->transfer($alliance, $clerk['id']))->kind);
        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $this->services($owner)->membership()->transfer($alliance, $owner['id']))->kind);
        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $this->services($owner)->membership()->transfer($alliance, $this->player()['id']))->kind);
        self::assertSame((int) $owner['id'], (int) $this->reloadAlliance($alliance['id'])['ally_owner']);

        self::assertSame([$heir['id'] => $heir['username'] . ' [Heir]'], $this->services($owner)->membership()->transferCandidates($alliance));

        $this->services($owner)->membership()->transfer($alliance, $heir['id']);

        self::assertSame((int) $heir['id'], (int) $this->reloadAlliance($alliance['id'])['ally_owner']);
        self::assertSame($heirRank, (int) $this->loadUser((int) $owner['id'])['ally_rank_id'], 'the old leader takes over the rank');
        self::assertSame(0, (int) $this->loadUser((int) $heir['id'])['ally_rank_id'], 'the leader has no rank');
    }

    // ---- leave and disband ----------------------------------------------------------------------------------

    public function testLeavingDetachesTheMemberAndTellsTheManagers(): void
    {
        [$owner, $alliance] = $this->foundAlliance('LEA', 'Leave Alliance');
        $member = $this->join($this->player(), $alliance);
        $alliance = $this->reloadAlliance($alliance['id']);

        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $this->services($owner)->membership()->leave($alliance))->kind, 'the leader has to transfer or disband');

        $before = $this->messageCount($owner['id']);
        $this->services($member)->membership()->leave($alliance);

        $reloaded = $this->loadUser((int) $member['id']);
        self::assertSame(0, (int) $reloaded['ally_id']);
        self::assertSame(0, (int) $reloaded['ally_register_time']);
        self::assertSame(1, (int) $this->reloadAlliance($alliance['id'])['ally_members']);
        self::assertSame($before + 1, $this->messageCount($owner['id']));
    }

    public function testDisbandIsLeaderOnlyAndRemovesEverything(): void
    {
        [$owner, $alliance] = $this->foundAlliance('DIS', 'Disband Alliance');
        [, $other] = $this->foundAlliance('DI2', 'Disband Two');
        $member = $this->join($this->player(), $alliance);
        $this->rank($alliance, 'Rank');
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 3, accept = 1, universe = 1, request_time = 1;', [':a' => $alliance['id'], ':b' => $other['id']]);
        $alliance = $this->reloadAlliance($alliance['id']);

        self::assertFalse($this->services($member)->membership()->disband($alliance));
        self::assertNotFalse($this->reloadAlliance($alliance['id']));

        self::assertTrue($this->services($owner)->membership()->disband($alliance));
        self::assertFalse($this->reloadAlliance($alliance['id']));
        self::assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_id']);
        self::assertSame(0, (int) $this->loadUser((int) $owner['id'])['ally_id']);
        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_RANK%% WHERE allianceID = :a;', [':a' => $alliance['id']], 'c'));
        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE owner_1 = :a OR owner_2 = :a;', [':a' => $alliance['id']], 'c'));
        self::assertNotFalse($this->reloadAlliance($other['id']), 'other alliances are untouched');
    }

    // ---- diplomacy ----------------------------------------------------------------------------------------

    /**
     * @return array{0: array, 1: array, 2: array, 3: array} owner A, alliance A, owner B, alliance B
     */
    private function twoAlliances(): array
    {
        [$ownerA, $allianceA] = $this->foundAlliance('DPA', 'Diplo A');
        [$ownerB, $allianceB] = $this->foundAlliance('DPB', 'Diplo B');

        return [$ownerA, $allianceA, $ownerB, $allianceB];
    }

    private function treatyId(int|string $a, int|string $b): int
    {
        return (int) $this->db->selectSingle('SELECT id FROM %%DIPLO%% WHERE owner_1 = :a AND owner_2 = :b;', [':a' => $a, ':b' => $b], 'id');
    }

    public function testDiplomacyRequestIsAcceptedOnlyByTheRecipient(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();

        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], 3, 'friends?');
        $treaty = $this->treatyId($allianceA['id'], $allianceB['id']);
        self::assertSame(0, (int) $this->db->selectSingle('SELECT accept FROM %%DIPLO%% WHERE id = :id;', [':id' => $treaty], 'accept'));
        self::assertGreaterThan(0, $this->messageCount($ownerB['id']), 'the other leader gets the request');

        // the sender cannot accept his own request
        $exit = $this->exitOf(fn() => $this->services($ownerA)->diplomacy()->accept($allianceA, $treaty));
        self::assertSame(AllianceException::REDIRECT, $exit->kind);
        self::assertSame(0, (int) $this->db->selectSingle('SELECT accept FROM %%DIPLO%% WHERE id = :id;', [':id' => $treaty], 'accept'));

        $this->services($ownerB)->diplomacy()->accept($allianceB, $treaty);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT accept FROM %%DIPLO%% WHERE id = :id;', [':id' => $treaty], 'accept'));
        self::assertGreaterThan(0, $this->messageCount($ownerA['id']));
    }

    public function testThirdAlliancesCannotTouchATreaty(): void
    {
        [$ownerA, $allianceA, , $allianceB] = $this->twoAlliances();
        [$ownerC, $allianceC] = $this->foundAlliance('DPC', 'Diplo C');
        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], 3, '');
        $treaty = $this->treatyId($allianceA['id'], $allianceB['id']);

        foreach (['accept', 'reject', 'delete'] as $method) {
            $exit = $this->exitOf(fn() => $this->services($ownerC)->diplomacy()->$method($allianceC, $treaty));
            self::assertSame(AllianceException::HOME, $exit->kind, $method);
        }
        self::assertSame($treaty, $this->treatyId($allianceA['id'], $allianceB['id']));
        self::assertNull($this->services($ownerC)->diplomacy()->targetAlliance($treaty) ?: null);
    }

    public function testRejectAndWithdrawDeleteTheTreaty(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();

        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], 4, '');
        $this->services($ownerB)->diplomacy()->reject($allianceB, $this->treatyId($allianceA['id'], $allianceB['id']));
        self::assertSame(0, $this->treatyId($allianceA['id'], $allianceB['id']));

        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], 4, '');
        $this->services($ownerA)->diplomacy()->delete($allianceA, $this->treatyId($allianceA['id'], $allianceB['id']));
        self::assertSame(0, $this->treatyId($allianceA['id'], $allianceB['id']));
    }

    public function testDiplomacyCreateValidation(): void
    {
        [$ownerA, $allianceA, , $allianceB] = $this->twoAlliances();
        $diplomacy = $this->services($ownerA)->diplomacy();

        self::assertSame(AllianceException::HOME, $this->exitOf(fn() => $diplomacy->create($allianceA, $allianceB['id'], 99, ''))->kind, 'invalid level');
        $unknown = $this->exitOf(fn() => $diplomacy->create($allianceA, 999999, 3, ''));
        self::assertSame(AllianceException::JSON, $unknown->kind);
        self::assertTrue($unknown->payload['error']);
        $same = $this->exitOf(fn() => $diplomacy->create($allianceA, $allianceA['id'], 3, ''));
        self::assertSame(AllianceException::JSON, $same->kind);
        self::assertTrue($same->payload['error']);

        $diplomacy->create($allianceA, $allianceB['id'], 3, '');
        $duplicate = $this->exitOf(fn() => $diplomacy->create($allianceA, $allianceB['id'], 4, ''));
        self::assertSame(AllianceException::JSON, $duplicate->kind, 'only one treaty per pair');
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE owner_1 = :a;', [':a' => $allianceA['id']], 'c'));
    }

    public function testWarIsAcceptedAtOnceAndPeaceEndsIt(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();

        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::War->value, 'war');
        $war = $this->treatyId($allianceA['id'], $allianceB['id']);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT accept FROM %%DIPLO%% WHERE id = :id;', [':id' => $war], 'accept'));

        // peace may be offered during a war, by either side
        $this->services($ownerB)->diplomacy()->create($allianceB, $allianceA['id'], DiploType::Peace->value, 'enough');
        $peace = $this->treatyId($allianceB['id'], $allianceA['id']);
        self::assertGreaterThan(0, $peace);
        // the proposer cannot accept; the recipient can, which removes war and peace rows
        self::assertSame(AllianceException::REDIRECT, $this->exitOf(fn() => $this->services($ownerB)->diplomacy()->accept($allianceB, $peace))->kind);
        $this->services($ownerA)->diplomacy()->accept($allianceA, $peace);
        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE owner_1 IN (:a,:b) AND owner_2 IN (:a,:b);', [':a' => $allianceA['id'], ':b' => $allianceB['id']], 'c'));
    }

    public function testAWarCannotBeEndedByDeletingOrRejectingIt(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();
        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::War->value, 'war');
        $war = $this->treatyId($allianceA['id'], $allianceB['id']);

        foreach ([[$ownerB, $allianceB, 'delete'], [$ownerB, $allianceB, 'reject'], [$ownerA, $allianceA, 'delete'], [$ownerA, $allianceA, 'reject']] as [$owner, $alliance, $method]) {
            $exit = $this->exitOf(fn() => $this->services($owner)->diplomacy()->$method($alliance, $war));
            self::assertSame(AllianceException::HOME, $exit->kind, $method);
        }
        self::assertSame($war, $this->treatyId($allianceA['id'], $allianceB['id']));

        // a second open peace offer between the pair is refused
        $this->services($ownerB)->diplomacy()->create($allianceB, $allianceA['id'], DiploType::Peace->value, 'enough');
        $again = $this->exitOf(fn() => $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::Peace->value, 'again'));
        self::assertSame(AllianceException::JSON, $again->kind);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE level = :peace;', [':peace' => DiploType::Peace->value], 'c'));
    }

    public function testMembersCannotFoundASecondAlliance(): void
    {
        [$owner] = $this->foundAlliance('SEC', 'Second Alliance');
        $exit = $this->exitOf(fn() => $this->services($owner)->membership()->assertMayFound());
        self::assertSame(AllianceException::MESSAGE, $exit->kind);
    }

    public function testKickAndDisbandInformThoseConcerned(): void
    {
        [$owner, $alliance] = $this->foundAlliance('INF', 'Inform Alliance');
        $kicked = $this->join($this->player(), $alliance);
        $member = $this->join($this->player(), $alliance);
        $alliance = $this->reloadAlliance($alliance['id']);

        $this->services($owner)->membership()->kick($alliance, AllianceRights::all(true), $kicked['id']);
        self::assertSame(1, $this->messageCount($kicked['id']));

        $this->services($owner)->membership()->disband($alliance);
        self::assertSame(1, $this->messageCount($member['id']));
        self::assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_rank_id']);
    }

    public function testDiplomacyViewsGroupTreatiesByStatus(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();
        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::TradePact->value, '');
        $treaty = $this->treatyId($allianceA['id'], $allianceB['id']);

        $sent = $this->services($ownerA)->diplomacy()->overview($allianceA);
        self::assertSame([$treaty => 'Diplo B'], $sent[2][DiploType::TradePact->value]);
        $received = $this->services($ownerB)->diplomacy()->overview($allianceB);
        self::assertSame([$treaty => 'Diplo A'], $received[1][DiploType::TradePact->value]);
        self::assertArrayHasKey(6, $this->services($ownerA)->diplomacy()->relations($allianceA));
        self::assertArrayHasKey(5, $this->services($ownerB)->diplomacy()->relations($allianceB));

        $this->services($ownerB)->diplomacy()->accept($allianceB, $treaty);
        $accepted = $this->services($ownerA)->diplomacy()->overview($allianceA);
        self::assertSame([$treaty => 'Diplo B'], $accepted[0][DiploType::TradePact->value]);
    }

    // ---- circular, lists, settings ------------------------------------------------------------------------

    public function testCircularReachesAllMembersOrOneRank(): void
    {
        [$owner, $alliance] = $this->foundAlliance('CIR', 'Circular Alliance');
        $rank = $this->rank($alliance, 'Pilots');
        $pilot = $this->join($this->player(), $alliance, $rank);
        $plain = $this->join($this->player(), $alliance);
        $messenger = $this->services($owner)->messenger();

        $list = $messenger->sendCircular($alliance, $rank, 'Subject', 'Hello');
        self::assertStringContainsString($pilot['username'], $list);
        self::assertStringNotContainsString($plain['username'], $list);
        self::assertSame(1, $this->messageCount($pilot['id']));
        self::assertSame(0, $this->messageCount($plain['id']));

        $messenger->sendCircular($alliance, 0, 'Subject', 'Hello all');
        self::assertSame(2, $this->messageCount($pilot['id']));
        self::assertSame(1, $this->messageCount($plain['id']));
        self::assertSame(0, $this->messageCount($owner['id']), 'the sender gets one read copy, no unread one');
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% WHERE user_id = :u AND message_unread = 0 AND message_id = (SELECT MAX(message_id) FROM %%USERS_TO_MESSAGES%% WHERE user_id = :u);', [':u' => $owner['id']], 'c'));

        $exit = $this->exitOf(fn() => $messenger->sendCircular($alliance, 0, 'Subject', ''));
        self::assertSame(AllianceException::JSON, $exit->kind);
        self::assertTrue($exit->payload['error']);
    }

    public function testCircularRankChoicesAreTheRealRankIds(): void
    {
        [$owner, $alliance] = $this->foundAlliance('CRK', 'Circular Ranks');
        [, $other] = $this->foundAlliance('CRO', 'Circular Other');
        $pilots = $this->rank($alliance, 'Pilots');
        $gunners = $this->rank($alliance, 'Gunners');
        $this->rank($other, 'Foreign');
        $pilot = $this->join($this->player(), $alliance, $pilots);
        $gunner = $this->join($this->player(), $alliance, $gunners);

        $choices = $this->services($owner)->repository()->rankNames($alliance['id']);
        self::assertSame([$pilots => 'Pilots', $gunners => 'Gunners'], $choices);

        $this->services($owner)->messenger()->sendCircular($alliance, array_key_last($choices), 'S', 'Only gunners');
        self::assertSame(1, $this->messageCount($gunner['id']));
        self::assertSame(0, $this->messageCount($pilot['id']));
    }

    public function testDiplomacyMessagesReachEveryMemberOfBothAlliancesOnce(): void
    {
        [$ownerA, $allianceA, $ownerB, $allianceB] = $this->twoAlliances();
        $membersA = [$this->join($this->player(), $allianceA), $this->join($this->player(), $allianceA), $this->join($this->player(), $allianceA)];
        $membersB = [$this->join($this->player(), $allianceB), $this->join($this->player(), $allianceB)];
        $allianceA = $this->reloadAlliance($allianceA['id']);

        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::War->value, 'war');

        self::assertSame(0, $this->messageCount($ownerA['id']), 'the sender only has the read copy');
        foreach ([$ownerB, ...$membersA, ...$membersB] as $user) {
            self::assertSame(1, $this->messageCount($user['id']), 'user ' . $user['id']);
        }
    }

    public function testMemberListsShowRankNamesAndSurviveMissingStatistics(): void
    {
        [$owner, $alliance] = $this->foundAlliance('LST', 'List Alliance');
        $rank = $this->rank($alliance, 'Pilots');
        $pilot = $this->join($this->player(), $alliance, $rank);
        $plain = $this->join($this->player(), $alliance);
        // a member without a statistics row (just created, statistics not built yet)
        $this->db->delete('DELETE FROM %%STATPOINTS%% WHERE id_owner = :u;', [':u' => $plain['id']]);
        $alliance = $this->reloadAlliance($alliance['id']);
        $lists = $this->services($owner)->memberLists();

        $members = $lists->members($alliance);
        self::assertSame($this->state->LNG['al_default_leader_name'], $members[$owner['id']]['rankName']);
        self::assertSame('Pilots', $members[$pilot['id']]['rankName']);
        self::assertSame($this->state->LNG['al_new_member_rank_text'], $members[$plain['id']]['rankName']);
        self::assertCount(3, $members);

        $admin = $lists->adminMembers($alliance);
        self::assertSame(-1, (int) $admin[$owner['id']]['rankID'], 'the leader is shown with rank -1');
        self::assertSame($rank, (int) $admin[$pilot['id']]['rankID']);
        self::assertStringContainsString($pilot['username'], $admin[$pilot['id']]['kickQuestion']);
    }

    public function testMainAndWingAlliancesAreListed(): void
    {
        [$ownerA, $allianceA, , $allianceB] = $this->twoAlliances();
        $this->db->insert('INSERT INTO %%DIPLO%% SET owner_1 = :a, owner_2 = :b, level = 1, accept = 1, universe = 1, request_time = 1;', [':a' => $allianceA['id'], ':b' => $allianceB['id']]);
        $lists = $this->services($ownerA)->memberLists();

        $wings = $lists->wingAlliances($allianceA);
        self::assertArrayHasKey($allianceB['id'], $wings);
        self::assertSame('DPB', $wings[$allianceB['id']]['wingData']['ally_tag']);
        self::assertCount(1, $wings[$allianceB['id']]['memberList']);
        self::assertSame([], $lists->mainAlliances($allianceA));
        self::assertArrayHasKey($allianceA['id'], $this->services($ownerA)->memberLists()->mainAlliances($allianceB));
    }

    public function testSettingsAreValidatedAndSaved(): void
    {
        [$owner, $alliance] = $this->foundAlliance('SET', 'Settings Alliance');
        $this->foundAlliance('TAKEN', 'Taken Name');
        $member = $this->join($this->player(), $alliance);
        $alliance = $this->reloadAlliance($alliance['id']);
        $settings = $this->services($owner)->settings();
        $input = [
            'owner_range' => str_repeat('L', 40),
            'web' => 'javascript:alert(1)',
            'image' => 'https://example.com/logo.png',
            'request_notallow' => 7,
            'ally_max_members' => 1,
            'request_min_points' => 10,
            'stats' => 1,
            'diplo' => 1,
            'ally_tag' => 'SET',
            'ally_name' => 'Settings Alliance',
            'text' => 'external text',
            'textMode' => 'external',
        ];

        $saved = $settings->save($alliance, $input);
        $row = $this->reloadAlliance($alliance['id']);

        self::assertSame(str_repeat('L', 32), $row['ally_owner_range']);
        self::assertSame('', $row['ally_web'], 'javascript: links are dropped');
        self::assertSame('https://example.com/logo.png', $row['ally_image']);
        self::assertEquals(0, $row['ally_request_notallow'], 'coerced to 0/1');
        self::assertEquals(2, $row['ally_max_members'], 'never below the member count');
        self::assertSame('external text', $row['ally_description']);
        self::assertSame($row['ally_name'], $saved['ally_name']);

        $settings->save($saved, ['text' => 'secret', 'textMode' => 'internal'] + $input);
        $row = $this->reloadAlliance($alliance['id']);
        self::assertSame('secret', $row['ally_text']);
        self::assertSame('external text', $row['ally_description'], 'only the selected text is written');

        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $settings->save($saved, ['ally_tag' => 'TAKEN'] + $input))->kind, 'tag already used');
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $settings->save($saved, ['ally_name' => 'Taken Name'] + $input))->kind, 'name already used');
        self::assertSame(AllianceException::MESSAGE, $this->exitOf(fn() => $settings->save($saved, ['ally_tag' => 'WAYTOOLONGTAG'] + $input))->kind);
        self::assertSame('SET', $this->reloadAlliance($alliance['id'])['ally_tag']);
        self::assertSame((int) $alliance['id'], (int) $this->loadUser((int) $member['id'])['ally_id']);
    }

    public function testHttpUrlOrEmpty(): void
    {
        self::assertSame('https://example.com/a?b=c', AllianceText::httpUrlOrEmpty(' https://example.com/a?b=c '));
        self::assertSame('http://example.com', AllianceText::httpUrlOrEmpty('http://example.com'));
        self::assertSame('', AllianceText::httpUrlOrEmpty('javascript:alert(1)'));
        self::assertSame('', AllianceText::httpUrlOrEmpty('data:text/html,x'));
        self::assertSame('', AllianceText::httpUrlOrEmpty('/relative/path'));
        self::assertSame('', AllianceText::httpUrlOrEmpty(''));
        self::assertSame('', AllianceText::httpUrlOrEmpty("http://a.b/x&#039;><form/action=x>"));
        self::assertSame('', AllianceText::httpUrlOrEmpty("http://a.b/x'y"));
        self::assertSame('http://a.b/?x=1&amp;y=2', AllianceText::httpUrlOrEmpty('http://a.b/?x=1&amp;y=2'));
    }
}
