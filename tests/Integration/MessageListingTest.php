<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\SpyReport;
use App\Game\Enum\MessageType;
use App\Game\Messages\MessageListing;
use App\Tests\Support\GameTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The message list: the per-type counts come from one query and also serve as page count; recipients load in one
 * query per page; rendered spy reports are cached and the cache key follows everything the output depends on.
 */
final class MessageListingTest extends GameTestCase
{
    private const REPORT = '{"targetUserName":"target","time":1794511304,"stbEnabled":0,"danger":0,"dangerClass":"nonedanger","smallCargoNeeded":1,"largeCargoNeeded":1,"energy":0,"energyClass":"","spyData":{"900":{"901":500,"902":500},"200":[],"400":[],"0":[],"100":[]},"repair_order":[],"targetPlanet":{"name":"Planet","planet_type":"1","galaxy":1,"system":1,"planet":4},"targetChance":0,"spyChance":86,"totalShipDefCount":0,"isBattleSim":true}';

    /** @return int message id */
    private function addMessage(int $userId, int $type, string $text = 'text', bool $favorite = false, bool $unread = true, ?string $group = null, ?int $senderId = null, ?int $deletedAt = null): int
    {
        $this->db->insert(
            "INSERT INTO %%MESSAGES%% (message_group, message_owner, message_sender, message_time, message_type, message_from, message_subject, message_text, message_unread, message_universe) VALUES (:g, :u, 0, :t, :type, 'from', 'subject', :text, 1, 1);",
            [':g' => $group, ':u' => $userId, ':t' => 1700000000 + $this->db->lastInsertId(), ':type' => $type, ':text' => $text],
        );
        $messageId = $this->db->lastInsertId();
        $this->db->insert(
            'INSERT INTO %%USERS_TO_MESSAGES%% (message_id, user_id, sender_id, message_unread, message_favorite, deleted_at) VALUES (:m, :u, :s, :unread, :fav, :del);',
            [':m' => $messageId, ':u' => $userId, ':s' => $senderId, ':unread' => (int) $unread, ':fav' => (int) $favorite, ':del' => $deletedAt],
        );

        return $messageId;
    }

    private function listing(?ArrayAdapter $cache = null): MessageListing
    {
        return new MessageListing($this->db, $this->configs, $this->state, $cache);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function list(array $user, mixed $category, mixed $page = 1, mixed $fav = 0, string $search = '', int $grp = 0): array
    {
        $this->state->USER = $user;

        return $this->listing()->listPage($category, $page, $fav, $search, $grp);
    }

    private function seedMessages(int $userId): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->addMessage($userId, MessageType::SpyReport->value, 'report ' . $i, $i % 3 === 0, true, 'm_5');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->addMessage($userId, MessageType::BattleReport->value, 'battle needle ' . $i, $i === 1, false);
        }
        $this->addMessage($userId, MessageType::System->value, 'system', true, true);
        $this->addMessage($userId, MessageType::Alliance->value, 'ally', false, true, senderId: $userId);
        $this->addMessage($userId, MessageType::Transport->value, 'deleted', true, true, deletedAt: 1700000100);
    }

    public function testCategoryOverviewCountsPerTypeFromOneQuery(): void
    {
        [$user] = $this->createPlayer();
        $user['messages_per_page'] = 5;
        $this->seedMessages((int) $user['id']);

        // an empty category lists (and so marks as read) nothing
        $list = $this->list($user, MessageType::Transport->value);
        $cat = $list['CategoryList'];

        $this->assertEquals(7, $cat[MessageType::SpyReport->value]['total']);
        $this->assertEquals(7, $cat[MessageType::SpyReport->value]['unread']);
        $this->assertEquals(4, $cat[MessageType::BattleReport->value]['total']);
        $this->assertEquals(0, $cat[MessageType::BattleReport->value]['unread']);
        $this->assertEquals(0, $cat[MessageType::Transport->value]['total'], 'deleted messages do not count');
        $this->assertEquals(13, $cat[MessageType::All->value]['total']);
        $this->assertEquals(9, $cat[MessageType::All->value]['unread']);
        $this->assertEquals(3 + 1 + 1, $cat[MessageType::Favorites->value]['total']);
        $this->assertEquals(0, $list['MessageCount']);

        $all = $this->list($user, MessageType::All->value);
        $this->assertEquals(13, $all['MessageCount']);
        $this->assertEquals(3, $all['maxPage']);
        // the counters of the answer already reflect the messages read by listing them
        $this->assertLessThan(9, $all['CategoryList'][MessageType::All->value]['unread']);
    }

    public function testPageCountFollowsTheFilterWithoutASeparateCountQuery(): void
    {
        [$user] = $this->createPlayer();
        $user['messages_per_page'] = 5;
        $this->seedMessages((int) $user['id']);

        $cases = [
            'spy' => [[MessageType::SpyReport->value], 7],
            'spy favourites' => [[MessageType::SpyReport->value, 1, 1], 3],
            'all favourites' => [[MessageType::All->value, 1, 1], 5],
            'favourites category' => [[MessageType::Favorites->value], 5],
            'search category, no text' => [[MessageType::Search->value], 13],
            'text search' => [[MessageType::All->value, 1, 0, 'needle'], 4],
            'unknown category' => [[42], 0],
        ];
        foreach ($cases as $label => [$args, $expected]) {
            $list = $this->list($user, ...$args);
            $this->assertEquals($expected, $list['MessageCount'], $label);
            $this->assertSame(max(1, (int) ceil($expected / 5)), (int) $list['maxPage'], $label);
        }
    }

    public function testGroupedPagesKeepTheirBlocksAndOrder(): void
    {
        [$user] = $this->createPlayer();
        $user['messages_per_page'] = 10;
        $this->seedMessages((int) $user['id']);

        $list = $this->list($user, MessageType::SpyReport->value, 1, 0, '', 1);

        $this->assertCount(7, $list['MessageList']);
        $blocks = array_count_values(array_column($list['MessageList'], 'grp_id'));
        $this->assertSame(['m_5_1' => 4, 'm_5_2' => 3], $blocks, 'blocks of 4 messages per conversation');
        $this->assertSame('m_5_1', $list['MessageList'][0]['grp_id'], 'newest block first');
    }

    public function testRecipientsOfPrivateMessagesLoadForTheWholePage(): void
    {
        [$sender] = $this->createPlayer();
        $sender['messages_per_page'] = 10;
        [$other] = $this->createPlayer();
        $first = $this->addMessage((int) $sender['id'], MessageType::User->value, 'hi', senderId: (int) $sender['id']);
        $second = $this->addMessage((int) $sender['id'], MessageType::User->value, 'again', senderId: (int) $other['id']);
        foreach ([$first, $second] as $messageId) {
            $this->db->insert(
                'INSERT INTO %%USERS_TO_MESSAGES%% (message_id, user_id, sender_id, message_unread) VALUES (:m, :u, :s, 1);',
                [':m' => $messageId, ':u' => $other['id'], ':s' => $messageId === $first ? $sender['id'] : $other['id']],
            );
        }

        $list = $this->list($sender, MessageType::User->value);

        $byId = array_column($list['MessageList'], null, 'id');
        $this->assertCount(2, $list['MessageList']);
        $this->assertSame([(int) $other['id']], array_map('intval', array_column($byId[$first]['receiver'], 'id')));
        $this->assertStringStartsWith($other['username'] . ' [', $byId[$first]['receiver'][0]['receiver']);
        $this->assertEquals([(int) $other['id']], array_map('intval', $byId[$first]['answerTo']));
        $this->assertEquals([(int) $other['id']], array_map('intval', $byId[$second]['answerTo']));
    }

    public function testSpyReportHtmlIsRenderedOnceAndServedFromTheCache(): void
    {
        [$user] = $this->createPlayer();
        $this->state->USER = $user;
        $cache = new ArrayAdapter();
        $reports = new SpyReport($this->db, $this->configs, $this->state, $cache);

        $first = $reports->titleAndHtml(self::REPORT);
        $this->assertStringContainsString('spyRaport', $first['html']);
        $this->assertNotSame('', $first['title']);
        $this->assertSame($first, (new SpyReport($this->db, $this->configs, $this->state))->titleAndHtml(self::REPORT), 'cached output equals the uncached render');
        $this->assertCount(1, $cache->getValues());

        $this->assertSame($first, $reports->titleAndHtml(self::REPORT));
        $this->assertCount(1, $cache->getValues(), 'second call hits the cache');
    }

    public function testSpyReportCacheKeyFollowsReportThemeTimezoneAndNumberFormat(): void
    {
        [$user] = $this->createPlayer();
        $cache = new ArrayAdapter();
        $variants = [
            'base' => [],
            'timezone' => ['timezone' => 'Asia/Tokyo'],
            'decimal separator' => ['decimal_separator' => ($user['decimal_separator'] ?? 0) == 0 ? 1 : 0],
            'css theme' => ['css_dpath' => 'other-theme'],
        ];
        foreach ($variants as $changes) {
            $this->state->USER = $changes + $user;
            (new SpyReport($this->db, $this->configs, $this->state, $cache))->titleAndHtml(self::REPORT);
        }
        $this->assertCount(count($variants), $cache->getValues(), 'each variant is cached separately');

        $this->state->USER = $user;
        (new SpyReport($this->db, $this->configs, $this->state, $cache))->titleAndHtml(str_replace('"energy":0', '"energy":5', self::REPORT));
        $this->assertCount(count($variants) + 1, $cache->getValues(), 'changed report data = new entry');
    }

    public function testTimezoneChangesTheCachedTitle(): void
    {
        [$user] = $this->createPlayer();
        $cache = new ArrayAdapter();
        $titles = [];
        foreach (['UTC', 'Pacific/Auckland'] as $timezone) {
            $this->state->USER = ['timezone' => $timezone] + $user;
            $titles[$timezone] = (new SpyReport($this->db, $this->configs, $this->state, $cache))->titleAndHtml(self::REPORT)['title'];
        }
        $this->assertNotSame($titles['UTC'], $titles['Pacific/Auckland']);
    }

    public function testNonJsonReportsAreNotCached(): void
    {
        [$user] = $this->createPlayer();
        $this->state->USER = $user;
        $cache = new ArrayAdapter();

        $result = (new SpyReport($this->db, $this->configs, $this->state, $cache))->titleAndHtml('old <b>format</b>');

        $this->assertSame(['title' => '', 'html' => 'old <b>format</b>'], $result);
        $this->assertCount(0, $cache->getValues());
    }
}
