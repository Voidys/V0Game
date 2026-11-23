<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Alliance\AllianceActivityService;
use App\Game\Alliance\AllianceServices;
use App\Game\Alliance\AllianceTargetService as T;
use App\Game\Core\AcsService;
use App\Game\Enum\DiploType;
use App\Tests\Support\GameTestCase;

/**
 * Alliance tools: the shared target list (add, claim, unclaim, delete rules), the member activity buckets, the
 * notes pages, the ACS overview and the expiry of treaties.
 */
final class AllianceToolsTest extends GameTestCase
{
    /** @param array<mixed> $actor */
    private function services(array $actor): AllianceServices
    {
        $this->state->USER = $actor;

        return new AllianceServices($this->db, $this->configs, $this->state);
    }

    /** @return array{0: array<mixed>, 1: array<mixed>} owner row, alliance row */
    private function found(string $tag): array
    {
        $owner = $this->createPlayer()[0];
        $this->services($owner)->membership()->found($tag, 'Tools ' . $tag);
        $alliance = $this->db->selectSingle('SELECT * FROM %%ALLIANCE%% WHERE ally_tag = :t;', [':t' => $tag]);

        return [$this->loadUser((int) $owner['id']), $alliance];
    }

    /** @param array<mixed> $alliance @return array<mixed> */
    private function member(array $alliance): array
    {
        $player = $this->createPlayer()[0];
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a WHERE id = :u;', [':a' => $alliance['id'], ':u' => $player['id']]);

        return $this->loadUser((int) $player['id']);
    }

    private function targetId(int $allianceId): int
    {
        return (int) $this->db->selectSingle('SELECT id FROM %%ALLIANCE_TARGETS%% WHERE alliance_id = :a;', [':a' => $allianceId], 'id');
    }

    public function testTargetAddValidatesAndRejectsDuplicates(): void
    {
        [$owner, $alliance] = $this->found('TLA');
        $targets = $this->services($owner)->targets();
        $a = (int) $alliance['id'];
        $u = (int) $owner['id'];

        self::assertSame(T::OK, $targets->add($a, $u, 1, 10, 5, 1, 'farm'));
        self::assertSame(T::EXISTS, $targets->add($a, $u, 1, 10, 5, 1, 'again'));
        self::assertSame(T::OK, $targets->add($a, $u, 1, 10, 5, 3, 'moon'));
        self::assertSame(T::INVALID, $targets->add($a, $u, 99, 10, 5, 1, ''));
        self::assertSame(T::INVALID, $targets->add($a, $u, 1, 10, 5, 7, ''));
        self::assertSame(2, count($targets->list($a)));
    }

    public function testClaimBlocksOthersUntilItExpiresOrIsReleased(): void
    {
        [$owner, $alliance] = $this->found('TLB');
        $other = $this->member($alliance);
        $targets = $this->services($owner)->targets();
        $a = (int) $alliance['id'];
        $targets->add($a, (int) $owner['id'], 2, 20, 4, 1, '');
        $id = $this->targetId($a);

        self::assertSame(T::OK, $targets->claim($a, $id, (int) $owner['id'], 4));
        self::assertSame(T::TAKEN, $targets->claim($a, $id, (int) $other['id'], 4), 'a live claim of somebody else blocks');
        self::assertSame(T::OK, $targets->claim($a, $id, (int) $owner['id'], 8), 'the holder may renew');
        self::assertSame(T::DENIED, $targets->unclaim($a, $id, (int) $other['id'], false), 'only holder or manager lifts it');

        // an expired claim is free and shows as no claim
        $this->db->update('UPDATE %%ALLIANCE_TARGETS%% SET claimed_until = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 5, ':id' => $id]);
        self::assertNull($targets->list($a)[0]['claimed_by']);
        self::assertSame(T::OK, $targets->claim($a, $id, (int) $other['id'], 1));
        self::assertSame((int) $other['id'], (int) $targets->list($a)[0]['claimed_by']);

        self::assertSame(T::OK, $targets->unclaim($a, $id, (int) $other['id'], false));
        self::assertNull($targets->list($a)[0]['claimed_by']);
    }

    public function testManagerCanUnclaimAndHoursAreClamped(): void
    {
        [$owner, $alliance] = $this->found('TLC');
        $other = $this->member($alliance);
        $targets = $this->services($owner)->targets();
        $a = (int) $alliance['id'];
        $targets->add($a, (int) $owner['id'], 2, 20, 4, 1, '');
        $id = $this->targetId($a);

        $targets->claim($a, $id, (int) $other['id'], 9999);
        $until = (int) $targets->list($a)[0]['claimed_until'];
        self::assertLessThanOrEqual($this->state->runtime->timestamp + T::MAX_CLAIM_HOURS * 3600, $until);

        self::assertSame(T::OK, $targets->unclaim($a, $id, (int) $owner['id'], true));
        self::assertSame(T::MISSING, $targets->claim($a, $id + 999, (int) $owner['id'], 1));
    }

    public function testDeleteNeedsManagerOrAdderAndStaysInsideTheAlliance(): void
    {
        [$owner, $alliance] = $this->found('TLD');
        [$foreignOwner, $foreign] = $this->found('TLE');
        $other = $this->member($alliance);
        $targets = $this->services($owner)->targets();
        $a = (int) $alliance['id'];
        $targets->add($a, (int) $owner['id'], 3, 30, 6, 1, '');
        $id = $this->targetId($a);

        self::assertSame(T::DENIED, $targets->delete($a, $id, (int) $other['id'], false));
        self::assertSame(T::MISSING, $targets->delete((int) $foreign['id'], $id, (int) $foreignOwner['id'], true), 'other alliances cannot reach it');
        self::assertSame(T::MISSING, $targets->claim((int) $foreign['id'], $id, (int) $foreignOwner['id'], 1));
        self::assertSame(T::OK, $targets->delete($a, $id, (int) $other['id'], true));
        self::assertSame([], $targets->list($a));
    }

    public function testActivityBuckets(): void
    {
        self::assertSame('now', AllianceActivityService::bucket(0));
        self::assertSame('now', AllianceActivityService::bucket(899));
        self::assertSame('hour', AllianceActivityService::bucket(900));
        self::assertSame('day', AllianceActivityService::bucket(3600));
        self::assertSame('week', AllianceActivityService::bucket(86400));
        self::assertSame('older', AllianceActivityService::bucket(604800));

        [$owner, $alliance] = $this->found('TLF');
        $old = $this->member($alliance);
        $this->db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 10 * 86400, ':id' => $old['id']]);
        $grid = $this->services($owner)->activity()->grid((int) $alliance['id']);
        self::assertSame([(int) $old['id']], array_column($grid['older'], 'id'));
    }

    public function testWikiPagesAreScopedToTheAlliance(): void
    {
        [$owner, $alliance] = $this->found('TLG');
        [$foreignOwner, $foreign] = $this->found('TLH');
        $wiki = $this->services($owner)->wiki();
        $a = (int) $alliance['id'];

        $id = $wiki->save($a, (int) $owner['id'], 0, 'Rules', 'be nice');
        self::assertGreaterThan(0, $id);
        self::assertSame(0, $wiki->save($a, (int) $owner['id'], 0, '  ', 'x'), 'empty title');
        self::assertSame(0, $wiki->save((int) $foreign['id'], (int) $foreignOwner['id'], $id, 'Hijack', 'x'), 'cannot edit a foreign page');
        self::assertSame('Rules', $wiki->page($a, $id)['title']);
        self::assertNull($wiki->page((int) $foreign['id'], $id));

        $wiki->delete((int) $foreign['id'], $id);
        self::assertNotNull($wiki->page($a, $id));
        $wiki->delete($a, $id);
        self::assertNull($wiki->page($a, $id));
    }

    public function testAcsOverviewListsGroupsOfMembers(): void
    {
        [$owner, $alliance] = $this->found('TLI');
        [, $foreign] = $this->found('TLJ');
        $planetId = (int) $this->db->selectSingle('SELECT id FROM %%PLANETS%% WHERE id_owner = :u LIMIT 1;', [':u' => $owner['id']], 'id');
        $now = $this->state->runtime->timestamp;
        $this->db->insert('INSERT INTO %%AKS%% SET public_id = :p, name = :n, target = :t, ankunft = :a;', [':p' => str_repeat('a', 32), ':n' => 'Raid', ':t' => $planetId, ':a' => $now + 600]);
        $acsId = $this->db->lastInsertId();
        $this->db->insert('INSERT INTO %%USERS_TO_ACS%% SET userID = :u, acsID = :a;', [':u' => $owner['id'], ':a' => $acsId]);
        $this->db->insert('INSERT INTO %%AKS%% SET public_id = :p, name = :n, target = :t, ankunft = :a;', [':p' => str_repeat('b', 32), ':n' => 'Old', ':t' => $planetId, ':a' => $now - 600]);
        $this->db->insert('INSERT INTO %%USERS_TO_ACS%% SET userID = :u, acsID = :a;', [':u' => $owner['id'], ':a' => $this->db->lastInsertId()]);

        $acs = new AcsService($this->db, $this->configs, $this->state);
        $groups = $acs->groupsForAlliance((int) $alliance['id']);
        self::assertSame(['Raid'], array_column($groups, 'name'), 'only running groups');
        self::assertSame([$owner['username']], array_column($groups[0]['participants'], 'username'));
        self::assertSame([], $acs->groupsForAlliance((int) $foreign['id']));
    }

    public function testExpiredTreatiesAreEndedAndBothSidesInformed(): void
    {
        [$ownerA, $allianceA] = $this->found('TLK');
        [$ownerB, $allianceB] = $this->found('TLL');
        $diplomacy = $this->services($ownerA)->diplomacy();
        $diplomacy->create($allianceA, $allianceB['id'], DiploType::TradePact->value, 'trade', 14);
        $treaty = (int) $this->db->selectSingle('SELECT id FROM %%DIPLO%% WHERE owner_1 = :a AND owner_2 = :b;', [':a' => $allianceA['id'], ':b' => $allianceB['id']], 'id');
        $expires = (int) $this->db->selectSingle('SELECT expires_at FROM %%DIPLO%% WHERE id = :id;', [':id' => $treaty], 'expires_at');
        self::assertSame($this->state->runtime->timestamp + 14 * 86400, $expires);

        $this->services($ownerB)->diplomacy()->accept($allianceB, $treaty);
        self::assertSame(0, $this->services($ownerA)->diplomacy()->expireTreaties(), 'not due yet');

        $before = $this->messages((int) $ownerA['id']) + $this->messages((int) $ownerB['id']);
        $this->db->update('UPDATE %%DIPLO%% SET expires_at = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 1, ':id' => $treaty]);
        self::assertSame(1, $this->services($ownerA)->diplomacy()->expireTreaties());
        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%DIPLO%% WHERE id = :id;', [':id' => $treaty], 'c'));
        self::assertSame($before + 2, $this->messages((int) $ownerA['id']) + $this->messages((int) $ownerB['id']));
    }

    public function testWarAndUnlimitedTreatiesNeverExpire(): void
    {
        [$ownerA, $allianceA] = $this->found('TLM');
        [, $allianceB] = $this->found('TLN');
        $this->services($ownerA)->diplomacy()->create($allianceA, $allianceB['id'], DiploType::War->value, '', 30);
        self::assertNull($this->db->selectSingle('SELECT expires_at FROM %%DIPLO%% WHERE owner_1 = :a;', [':a' => $allianceA['id']], 'expires_at'));
    }

    private function messages(int $userId): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% WHERE user_id = :u AND message_unread = 1;', [':u' => $userId], 'c');
    }
}
