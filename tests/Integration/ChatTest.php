<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\Chat;
use App\Community\ChatRetention;
use App\Game\Admin\Chat\ChatModeration;
use App\Game\Chat\ChatFilter;
use App\Game\Chat\ChatSettings;
use App\Tests\Support\GameTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * In-game chat: send validation (length, normalisation, rate limit, ban, mute), channel access (alliance members only),
 * the blocklist, deleted messages, unread counts and the retention cleanup.
 */
final class ChatTest extends GameTestCase
{
    private const NOW = 1800000000;

    private function chat(int $gapLimit = 1000, int $burstLimit = 1000, int $reportLimit = 1000): Chat
    {
        $factory = static fn(string $id, int $limit, string $interval): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage(),
        );

        return new Chat($this->db, $factory('gap', $gapLimit, '3 seconds'), $factory('burst', $burstLimit, '30 seconds'), $factory('report', $reportLimit, '10 minutes'));
    }

    /** @return array<string, mixed> */
    private function player(array $user = []): array
    {
        return $this->createPlayer($user)[0];
    }

    public function testSendStoresTrimmedTextAndRejectsEmptyOrTooLong(): void
    {
        $chat = $this->chat();
        $user = $this->player();

        $this->assertSame(['error' => Chat::ERR_EMPTY], $chat->send($user, 'global', "  \n\t ", self::NOW));
        $ok = $chat->send($user, 'global', "  hello\n\n  <b>world</b>  ", self::NOW);
        $this->assertArrayHasKey('id', $ok);
        $long = $chat->send($user, 'global', str_repeat('äö ', 200), self::NOW);
        $this->assertArrayHasKey('id', $long);

        $messages = $chat->messages(1, 'global', (int) $user['id'], 0);
        $this->assertSame('hello <b>world</b>', $messages[0]['text'], 'raw text is stored, only whitespace is normalised');
        $this->assertSame(Chat::MAX_LENGTH, mb_strlen($messages[1]['text']));
        $this->assertTrue($messages[0]['own']);
        $this->assertSame($user['username'], $messages[0]['user']);
    }

    public function testRateLimitGapAndBurst(): void
    {
        $user = $this->player();
        $chat = $this->chat(1, 1000);
        $this->assertArrayHasKey('id', $chat->send($user, 'global', 'one', self::NOW));
        $this->assertSame(['error' => Chat::ERR_RATE], $chat->send($user, 'global', 'two', self::NOW));

        $burst = $this->chat(1000, 5);
        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey('id', $burst->send($user, 'global', "m$i", self::NOW));
        }
        $this->assertSame(['error' => Chat::ERR_RATE], $burst->send($user, 'global', 'sixth', self::NOW));
    }

    public function testBannedAndMutedPlayersCannotSend(): void
    {
        $chat = $this->chat();
        $banned = $this->player(['bana' => 1]);
        $this->assertSame(['error' => Chat::ERR_BANNED], $chat->send($banned, 'global', 'hi', self::NOW));

        $muted = $this->player(['chat_muted_until' => self::NOW + 60]);
        $this->assertSame(['error' => Chat::ERR_MUTED], $chat->send($muted, 'global', 'hi', self::NOW));
        $this->assertArrayHasKey('id', $chat->send($muted, 'global', 'hi', self::NOW + 61), 'the mute expires');
    }

    public function testAllianceChannelOnlyForMembersOfThatAlliance(): void
    {
        $chat = $this->chat();
        $a = $this->player(['ally_id' => 91001]);
        $b = $this->player(['ally_id' => 91002]);
        $loner = $this->player();

        $this->assertSame('ally:91001', $chat->channelFor($a, Chat::ALLIANCE));
        $this->assertNull($chat->channelFor($loner, Chat::ALLIANCE));
        $this->assertNull($chat->channelFor($a, 'ally:91002'), 'raw channel names are not accepted');
        $this->assertSame(['error' => Chat::ERR_CHANNEL], $chat->send($loner, 'alliance', 'let me in', self::NOW));

        $chat->send($a, 'alliance', 'secret plan', self::NOW);
        $this->assertCount(1, $chat->messages(1, (string) $chat->channelFor($a, Chat::ALLIANCE), (int) $a['id'], 0));
        $this->assertSame([], $chat->messages(1, (string) $chat->channelFor($b, Chat::ALLIANCE), (int) $b['id'], 0), 'other alliances see nothing');
        $this->assertSame([], $chat->messages(1, Chat::GLOBAL, (int) $a['id'], 0), 'the global channel does not show alliance messages');
    }

    public function testBlocklistHidesMessagesOfBlockedPlayers(): void
    {
        $chat = $this->chat();
        $reader = $this->player();
        $author = $this->player();
        $other = $this->player();
        $chat->send($author, 'global', 'noise', self::NOW);
        $chat->send($other, 'global', 'signal', self::NOW);
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% (`blocking_user`, `blocked_user`, `block_dm`) VALUES (:a, :b, 1);', [':a' => $reader['id'], ':b' => $author['id']]);

        $texts = array_column($chat->messages(1, 'global', (int) $reader['id'], 0), 'text');
        $this->assertSame(['signal'], $texts);
        $this->assertSame(1, $chat->unread(1, 'global', (int) $reader['id'], 0));
        $this->assertCount(2, $chat->messages(1, 'global', (int) $other['id'], 0), 'only the blocking reader is affected');
    }

    public function testDeletedMessagesVanishAndAreReportedAndStaffCannotBeMuted(): void
    {
        $chat = $this->chat();
        $reader = $this->player();
        $author = $this->player();
        $staff = $this->player(['authlevel' => 1]);
        $first = $chat->send($author, 'global', 'bad', self::NOW)['id'];
        $chat->send($staff, 'global', 'official', self::NOW);

        $this->assertTrue($chat->isModerator($staff));
        $this->assertFalse($chat->isModerator($author));
        $this->assertTrue($chat->delete(1, $first));
        $this->assertFalse($chat->delete(1, 999999999));
        $this->assertSame(['official'], array_column($chat->messages(1, 'global', (int) $reader['id'], 0), 'text'));
        $this->assertSame([$first], $chat->deletedSince(1, 'global', $first));
        $this->assertSame(1, $chat->unread(1, 'global', (int) $reader['id'], 0), 'deleted messages are not unread');

        $staffMessage = $chat->messages(1, 'global', (int) $reader['id'], 0)[0]['id'];
        $this->assertFalse($chat->muteAuthor(1, $staffMessage, 24, self::NOW));
        $again = $chat->send($author, 'global', 'again', self::NOW)['id'];
        $this->assertTrue($chat->muteAuthor(1, $again, 24, self::NOW));
        $this->assertSame(self::NOW + 86400, (int) $this->loadUser((int) $author['id'])['chat_muted_until']);
    }

    public function testUnreadCountsOnlyForeignMessagesAfterTheReadId(): void
    {
        $chat = $this->chat();
        $reader = $this->player();
        $other = $this->player();
        $a = $chat->send($other, 'global', 'a', self::NOW)['id'];
        $chat->send($reader, 'global', 'mine', self::NOW);
        $chat->send($other, 'global', 'b', self::NOW);

        $this->assertSame(2, $chat->unread(1, 'global', (int) $reader['id'], 0));
        $this->assertSame(1, $chat->unread(1, 'global', (int) $reader['id'], $a));
        $this->assertGreaterThanOrEqual($a, $chat->latestId(1, 'global'));
    }

    public function testLatestIdsMatchTheSingleChannelLookupAndGiveZeroForEmptyChannels(): void
    {
        $chat = $this->chat();
        $user = $this->player();
        $chat->send($user, 'global', 'one', self::NOW);
        $last = $chat->send($user, 'global', 'two', self::NOW)['id'];

        $ids = $chat->latestIds(1, ['global', 'ally:987654']);
        $this->assertSame(['global' => $chat->latestId(1, 'global'), 'ally:987654' => 0], $ids);
        $this->assertSame($last, $ids['global']);
        $this->assertSame([], $chat->latestIds(1, []));
    }

    public function testRetentionRemovesOldMessagesAndKeepsTheNewestPerChannel(): void
    {
        $chat = $this->chat();
        $user = $this->player();
        $chat->send($user, 'global', 'recent', self::NOW);
        $this->db->insert('INSERT INTO %%CHAT_MESSAGES%% (`universe`, `channel`, `user_id`, `username`, `message`, `created_at`) VALUES (1, \'global\', :u, \'x\', \'old\', :t);', [
            ':u' => $user['id'], ':t' => self::NOW - (ChatRetention::RETENTION_DAYS * 86400) - 10,
        ]);
        (new ChatRetention($this->db))->purge(self::NOW);
        $this->assertSame(['recent'], array_column($chat->messages(1, 'global', (int) $user['id'], 0), 'text'));

        // overflow: the cap keeps the newest rows only
        $this->db->insert('INSERT INTO %%CHAT_MESSAGES%% (`universe`, `channel`, `user_id`, `username`, `message`, `created_at`)
            SELECT 1, \'ally:77\', :u, \'x\', CONCAT(\'m\', n), :t FROM (SELECT a.N + b.N * 10 + c.N * 100 + d.N * 1000 + 1 AS n FROM
            (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
            (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
            (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c,
            (SELECT 0 N UNION SELECT 1 UNION SELECT 2) d) t WHERE n <= ' . (ChatRetention::CHANNEL_CAP + 50) . ';', [':u' => $user['id'], ':t' => self::NOW]);
        (new ChatRetention($this->db))->purge(self::NOW);
        $this->assertSame(ChatRetention::CHANNEL_CAP, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%CHAT_MESSAGES%% WHERE `channel` = \'ally:77\';', [], 'c'));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%CHAT_MESSAGES%% WHERE `channel` = \'global\';', [], 'c'));
    }

    public function testEveryChatRouteIsGuardedByTheModuleSwitch(): void
    {
        $attributes = (new \ReflectionClass(\App\Controller\Game\ChatController::class))->getAttributes(\App\Controller\RequireModule::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('MODULE_CHAT', $attributes[0]->newInstance()->module);

        $config = $this->configs->get(1);
        $modules = $config->moduls;
        $view = self::container()->get(\App\Game\Core\ViewHelpers::class);
        $modules[MODULE_CHAT] = 0;
        $config->moduls = $modules;
        $this->assertFalse($view->isModuleAvailable(MODULE_CHAT, 1), 'off = no chat (a new module row starts off)');
        $modules[MODULE_CHAT] = 1;
        $config->moduls = $modules;
        $this->assertTrue($view->isModuleAvailable(MODULE_CHAT, 1));
    }

    public function testGlobalSwitchAgeAndPointsRules(): void
    {
        $chat = $this->chat();
        $user = $this->player(['ally_id' => 91003]);
        $off = new ChatSettings(globalEnabled: false);

        $this->assertSame(['error' => Chat::ERR_GLOBAL_OFF], $chat->send($user, 'global', 'hi', self::NOW, $off));
        $this->assertNull($chat->channelFor($user, 'global', false));
        $this->assertArrayHasKey('id', $chat->send($user, 'alliance', 'ally still works', self::NOW, $off));

        $young = new ChatSettings(minAccountAgeHours: 24);
        $user['register_time'] = self::NOW - 3600;
        $this->assertSame(['error' => Chat::ERR_AGE], $chat->send($user, 'global', 'hi', self::NOW, $young));
        $user['register_time'] = self::NOW - 90000;
        $this->assertArrayHasKey('id', $chat->send($user, 'global', 'hi', self::NOW, $young));

        $points = new ChatSettings(minPoints: 1000);
        $user['total_points'] = 10;
        $this->assertSame(['error' => Chat::ERR_POINTS], $chat->send($user, 'global', 'hello', self::NOW, $points));
        $user['total_points'] = 1000;
        $this->assertArrayHasKey('id', $chat->send($user, 'global', 'hello', self::NOW, $points));
        $this->assertArrayHasKey('id', $chat->send($user, 'alliance', 'ally ignores the rules', self::NOW, new ChatSettings(minPoints: 99999)));
    }

    public function testFilterMasksStoresOriginalAndAllianceIsExempt(): void
    {
        $chat = $this->chat();
        $user = $this->player(['ally_id' => 91004]);
        $settings = new ChatSettings(filter: new ChatFilter("badword\n", true));

        $id = $chat->send($user, 'global', 'you BadWord!!!!!!!', self::NOW, $settings)['id'];
        $row = $this->db->selectSingle('SELECT `message`, `original_message` FROM %%CHAT_MESSAGES%% WHERE `id` = :id;', [':id' => $id]);
        $this->assertSame('you ***!!!!', $row['message']);
        $this->assertSame('you BadWord!!!!!!!', $row['original_message']);

        $this->assertSame(['error' => ChatFilter::ERR_LINK], $chat->send($user, 'global', 'see example.com', self::NOW, $settings));
        $this->assertSame(['error' => ChatFilter::ERR_DUPLICATE], $chat->send($user, 'global', 'YOU badword!!!!', self::NOW + 5, $settings));
        $ally = $chat->send($user, 'alliance', 'badword example.com', self::NOW, $settings)['id'];
        $this->assertNull($this->db->selectSingle('SELECT `original_message` FROM %%CHAT_MESSAGES%% WHERE `id` = :id;', [':id' => $ally], 'original_message'));
    }

    public function testSettingsReadTheConfigWithDefaults(): void
    {
        $settings = ChatSettings::fromConfig($this->configs->get(1));
        $this->assertTrue($settings->globalEnabled);
        $this->assertSame(0, $settings->minPoints);
        $this->assertSame(24, $settings->minAccountAgeHours, 'new default: a day old account');
    }

    public function testReportValidationDuplicatesAndVisibility(): void
    {
        $chat = $this->chat(reportLimit: 2);
        $author = $this->player(['ally_id' => 91005]);
        $reporter = $this->player();
        $ally = $this->player(['ally_id' => 91005]);
        $global = $chat->send($author, 'global', 'rude', self::NOW)['id'];
        $private = $chat->send($author, 'alliance', 'ally secret', self::NOW)['id'];

        $this->assertSame(['ok' => true], $chat->report($reporter, $global, 'insult', "  too\nrude ", self::NOW));
        $this->assertSame(['error' => Chat::ERR_DUPLICATE_REPORT], $chat->report($reporter, $global, 'spam', '', self::NOW));
        $this->assertSame(['error' => Chat::ERR_UNKNOWN], $chat->report($reporter, $global, 'nonsense', '', self::NOW + 1));
        $this->assertSame(['error' => Chat::ERR_UNKNOWN], $chat->report($reporter, $private, 'spam', '', self::NOW), 'alliance messages only for that alliance');
        $this->assertSame(['error' => Chat::ERR_SELF], $chat->report($author, $global, 'spam', '', self::NOW));
        $this->assertSame(['error' => Chat::ERR_UNKNOWN], $chat->report($reporter, 99999999, 'spam', '', self::NOW));
        $this->assertSame(['error' => Chat::ERR_UNKNOWN], $chat->report($reporter, $global, 'spam', '', self::NOW, false), 'global off: not reportable');
        $this->assertSame(['ok' => true], $chat->report($ally, $private, 'spam', str_repeat('x', 300), self::NOW));
        $this->assertSame(Chat::REPORT_COMMENT_MAX, mb_strlen((string) $this->db->selectSingle('SELECT `comment` FROM %%CHAT_REPORTS%% WHERE `reporter_id` = :r;', [':r' => $ally['id']], 'comment')));
        $this->assertSame('too rude', $this->db->selectSingle('SELECT `comment` FROM %%CHAT_REPORTS%% WHERE `reporter_id` = :r;', [':r' => $reporter['id']], 'comment'));

        $third = $this->player();
        $chat->report($third, $global, 'spam', '', self::NOW);
        $limited = $this->chat(reportLimit: 1);
        $second = $limited->send($author, 'global', 'again rude', self::NOW + 10)['id'];
        $this->assertSame(['ok' => true], $limited->report($reporter, $second, 'spam', '', self::NOW));
        $third2 = $limited->send($author, 'global', 'third rude', self::NOW + 20)['id'];
        $this->assertSame(['error' => Chat::ERR_RATE], $limited->report($reporter, $third2, 'spam', '', self::NOW));
    }

    public function testBlockAuthorHidesTheirMessages(): void
    {
        $chat = $this->chat();
        $reader = $this->player();
        $author = $this->player();
        $id = $chat->send($author, 'global', 'noise', self::NOW)['id'];

        $this->assertSame(['error' => Chat::ERR_SELF], $chat->blockAuthor($author, $id));
        $this->assertSame(['ok' => true], $chat->blockAuthor($reader, $id));
        $this->assertSame(['ok' => true], $chat->blockAuthor($reader, $id), 'blocking twice keeps one row');
        $this->assertSame([], $chat->messages(1, 'global', (int) $reader['id'], 0));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_BLOCKLIST%% WHERE `blocking_user` = :r;', [':r' => $reader['id']], 'c'));
    }

    public function testRetentionKeepsMessagesWithOpenReports(): void
    {
        $chat = $this->chat();
        $author = $this->player();
        $reporter = $this->player();
        $old = self::NOW - (ChatRetention::RETENTION_DAYS * 86400) - 100;
        $kept = $chat->send($author, 'global', 'reported old', $old)['id'];
        $gone = $chat->send($author, 'global', 'plain old', $old)['id'];
        $dismissed = $chat->send($author, 'global', 'dismissed old', $old)['id'];
        $chat->report($reporter, $kept, 'spam', '', $old);
        $chat->report($reporter, $dismissed, 'spam', '', $old);
        (new ChatModeration($this->db))->dismiss(1, $dismissed, 1, $old);

        (new ChatRetention($this->db))->purge(self::NOW);
        $exists = fn(int $id): bool => (bool) $this->db->selectSingle('SELECT `id` FROM %%CHAT_MESSAGES%% WHERE `id` = :id;', [':id' => $id], 'id');
        $this->assertTrue($exists($kept));
        $this->assertFalse($exists($gone));
        $this->assertFalse($exists($dismissed));
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%CHAT_REPORTS%% WHERE `message_id` IN (:a, :b);', [':a' => $kept, ':b' => $dismissed], 'c'), 'reports of removed messages go too');
    }

    public function testModerationHistoryFiltersPaginationDeleteAndRestore(): void
    {
        $chat = $this->chat();
        $mod = new ChatModeration($this->db);
        $anna = $this->player(['username' => 'AnnaChat']);
        $bob = $this->player(['username' => 'Bob_Chat']);
        $ids = [];
        for ($i = 1; $i <= ChatModeration::PAGE + 5; $i++) {
            $ids[$i] = $chat->send($i % 2 ? $anna : $bob, 'global', "message $i 50%", self::NOW + $i)['id'];
        }

        $page = $mod->history(1, 'global', [], 0);
        $this->assertCount(ChatModeration::PAGE, $page['rows']);
        $this->assertSame($ids[ChatModeration::PAGE + 5], (int) $page['rows'][0]['id'], 'newest first');
        $second = $mod->history(1, 'global', [], (int) $page['next']);
        $this->assertCount(5, $second['rows']);
        $this->assertNull($second['next']);

        $this->assertCount(1, $mod->history(1, 'global', ['text' => 'message 7 '], 0)['rows']);
        $this->assertCount(ChatModeration::PAGE, $mod->history(1, 'global', ['text' => '50%'], 0)['rows'], 'a percent sign is searched literally');
        $this->assertCount(0, $mod->history(1, 'global', ['text' => '5_%'], 0)['rows'], 'an underscore is not a wildcard');
        $this->assertCount(0, $mod->history(1, 'global', ['name' => 'nobody'], 0)['rows']);
        $names = array_unique(array_column($mod->history(1, 'global', ['name' => 'annachat'], 0)['rows'], 'username'));
        $this->assertSame(['AnnaChat'], array_values($names));
        $this->assertCount(2, $mod->history(1, 'global', ['from' => self::NOW + 10, 'to' => self::NOW + 11], 0)['rows']);

        $this->assertTrue($mod->delete(1, $ids[3], 1, self::NOW + 500));
        $this->assertFalse($mod->delete(2, $ids[4], 1, self::NOW), 'other universe');
        $deleted = $mod->history(1, 'global', ['deleted' => true], 0)['rows'];
        $this->assertCount(1, $deleted);
        $this->assertSame(1, (int) $deleted[0]['deleted_by']);
        $this->assertSame(self::NOW + 500, (int) $deleted[0]['deleted_at']);
        $this->assertTrue($mod->restore(1, $ids[3]));
        $this->assertCount(0, $mod->history(1, 'global', ['deleted' => true], 0)['rows']);
        $this->assertSame([], $chat->deletedSince(1, 'global', $ids[3]));
    }

    public function testReportQueueHasContextAndDeleteResolvesReports(): void
    {
        $chat = $this->chat();
        $mod = new ChatModeration($this->db);
        $author = $this->player();
        $a = $this->player();
        $b = $this->player();
        $ids = [];
        for ($i = 1; $i <= 9; $i++) {
            $ids[$i] = $chat->send($author, 'global', "ctx $i", self::NOW + $i)['id'];
        }
        $chat->report($a, $ids[5], 'spam', 'one', self::NOW);
        $chat->report($b, $ids[5], 'insult', 'two', self::NOW);

        $this->assertSame(1, $mod->openReportCount(1));
        $queue = $mod->reported(1)['items'];
        $this->assertCount(1, $queue);
        $this->assertCount(2, $queue[0]['reports']);
        $this->assertSame(2, $queue[0]['count']);
        $this->assertSame(['ctx 1', 'ctx 2', 'ctx 3', 'ctx 4'], array_column($queue[0]['before'], 'message'));
        $this->assertSame(['ctx 6', 'ctx 7', 'ctx 8', 'ctx 9'], array_column($queue[0]['after'], 'message'));

        $this->assertTrue($mod->delete(1, $ids[5], 1, self::NOW + 9));
        $this->assertSame(0, $mod->openReportCount(1));
        $this->assertSame('resolved', $this->db->selectSingle('SELECT `status` FROM %%CHAT_REPORTS%% WHERE `reporter_id` = :r;', [':r' => $a['id']], 'status'));
    }

    public function testReportQueueIsOrderedByAgeNotByCountAndPaginated(): void
    {
        $chat = $this->chat();
        $mod = new ChatModeration($this->db);
        $author = $this->player();
        $first = $chat->send($author, 'global', 'single real report', self::NOW)['id'];
        $junk = $chat->send($author, 'global', 'mass reported junk', self::NOW + 1)['id'];
        $chat->report($this->player(), $first, 'insult', '', self::NOW);
        for ($i = 0; $i < 3; $i++) {
            $chat->report($this->player(), $junk, 'spam', '', self::NOW + 1);
        }

        $items = $mod->reported(1)['items'];
        $this->assertSame([$first, $junk], array_map(static fn(array $i): int => (int) $i['message']['id'], $items), 'oldest open report first');
        $this->assertSame([1, 3], array_column($items, 'count'));

        $more = [];
        for ($i = 0; $i < ChatModeration::MAX_REPORTED; $i++) {
            $id = $chat->send($author, 'global', "filler $i", self::NOW + 10 + $i)['id'];
            $chat->report($this->player(), $id, 'other', '', self::NOW + 10 + $i);
            $more[] = $id;
        }
        $page = $mod->reported(1);
        $this->assertCount(ChatModeration::MAX_REPORTED, $page['items']);
        $this->assertNotNull($page['next']);
        $rest = $mod->reported(1, (int) $page['next']);
        $this->assertCount(2, $rest['items']);
        $this->assertNull($rest['next']);
    }

    public function testOldOpenReportsAreDismissedAndHandledOnesDeleted(): void
    {
        $chat = $this->chat();
        $author = $this->player();
        $reporter = $this->player();
        $old = self::NOW - (ChatRetention::REPORT_DAYS * 86400) - 100;
        $stale = $chat->send($author, 'global', 'stale', $old)['id'];
        $fresh = $chat->send($author, 'global', 'fresh', self::NOW - 60)['id'];
        $chat->report($reporter, $stale, 'spam', '', $old);
        $chat->report($reporter, $fresh, 'spam', '', self::NOW - 60);
        $done = $chat->send($author, 'global', 'handled', self::NOW - 50)['id'];
        $chat->report($reporter, $done, 'spam', '', self::NOW - 50);
        (new ChatModeration($this->db))->dismiss(1, $done, 1, $old);

        (new ChatRetention($this->db))->purge(self::NOW);
        $status = fn(int $id) => $this->db->selectSingle('SELECT `status` FROM %%CHAT_REPORTS%% WHERE `message_id` = :id;', [':id' => $id], 'status');
        $this->assertFalse($status($stale), 'dismissed, then its old message and report are gone');
        $this->assertSame('open', $status($fresh));
        $this->assertFalse($status($done), 'handled reports are deleted after 30 days');
    }

    public function testOpenReportsPerReporterAreCapped(): void
    {
        $chat = $this->chat();
        $author = $this->player();
        $reporter = $this->player();
        for ($i = 0; $i < Chat::MAX_OPEN_REPORTS; $i++) {
            $id = $chat->send($author, 'global', "m $i", self::NOW + $i)['id'];
            $this->assertSame(['ok' => true], $chat->report($reporter, $id, 'spam', '', self::NOW));
        }
        $extra = $chat->send($author, 'global', 'one too many', self::NOW + 99)['id'];
        $this->assertSame(['error' => Chat::ERR_REPORT_LIMIT], $chat->report($reporter, $extra, 'spam', '', self::NOW));
    }

    public function testInvisibleOnlyTextCountsAsEmpty(): void
    {
        $chat = $this->chat();
        $user = $this->player();
        foreach (["\u{3164}", "\u{2800}\u{115F}\u{1160}\u{FFA0}", "\u{00A0}\u{3000} ", "\u{200B}\u{FEFF}"] as $text) {
            $this->assertSame(['error' => Chat::ERR_EMPTY], $chat->send($user, 'global', $text, self::NOW), json_encode($text));
        }
        $this->assertSame('a b', $chat->clean("a\u{00A0}\u{3164}\u{3000}b"));
    }
}
