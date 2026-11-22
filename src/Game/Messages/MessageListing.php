<?php

namespace App\Game\Messages;

use App\Game\Enum\MessageType;
use Doctrine\DBAL\ArrayParameterType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Core\SpyReport;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Builds the message list page of the current player: category overview with unread/total counts, the filtered
 * (category, favourites, search) and paged list with grouping of conversations, and the per-row data the template needs.
 *
 * Side effect: the messages shown on the page are marked as read (listPage()).
 */
class MessageListing
{
    use GameUtilities;

    /** @var array<int, array{count: int|string, unread: int|string, fav: int|string}>|null per message type, loaded once per request */
    private ?array $typeCounts = null;

    private SpyReport $reports;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        ?CacheInterface $spyReportCache = null,
    ) {
        $this->reports = new SpyReport($db, $configs, $state, $spyReportCache);
    }

    /**
     * Count, unread count and favourite count of the player's non-deleted messages per message type, in one query. The
     * category overview and the page count of the list both read from it.
     *
     * @return array<int, array{count: int|string, unread: int|string, fav: int|string}>
     */
    private function typeCounts(): array
    {
        if ($this->typeCounts !== null) {
            return $this->typeCounts;
        }

        $sql = "SELECT m.`message_type`, SUM(um.`message_unread`) as message_unread, COUNT(um.`id`) as count, SUM(um.`message_favorite` = 1) as fav
            FROM %%USERS_TO_MESSAGES%% AS um
            INNER JOIN %%MESSAGES%% as m ON m.`message_id` = um.`message_id`
            WHERE um.`user_id` = :userID AND um.`deleted_at` IS NULL
            GROUP BY m.`message_type`;";
        $this->typeCounts = [];
        foreach ($this->db->select($sql, [':userID' => $this->state->USER['id']]) as $row) {
            $this->typeCounts[$row['message_type']] = [
                'count' => $row['count'],
                'unread' => $row['message_unread'],
                'fav' => $row['fav'],
            ];
        }

        return $this->typeCounts;
    }

    /**
     * Category overview: for every message type (and the pseudo categories ALL, favourites, search) its colour, unread
     * count and total count of the player's non-deleted messages.
     *
     * @return array<int, array{color: string, unread: int|string, total: int|string}>
     */
    public function categoryList(): array
    {
        $CategoryList   = [];
        $TitleColor = [
            MessageType::SpyReport->value         => '#FFFF00',
            MessageType::SpyDefence->value        => '#FBE870',
            MessageType::User->value               => '#FF6699',
            MessageType::Alliance->value           => '#FF3300',
            MessageType::BattleReport->value      => '#FF9900',
            MessageType::System->value             => '#773399',
            MessageType::Transport->value          => '#009933',
            MessageType::ForeignTransport->value  => '#005e1f',
            MessageType::Expedition->value         => '#6495ed',
            MessageType::Game->value               => '#666600',
            MessageType::BuildList->value         => '#007070',
            MessageType::All->value                => '#ABABAB',
            MessageType::Favorites->value          => '#CCCCCC',
            MessageType::Search->value             => '#CCCCCC',
        ];
        $Total = $UnRead = array_fill_keys(array_keys($TitleColor), 0);

        $MessFav = 0;
        foreach ($this->typeCounts() as $type => $counts) {
            $UnRead[$type] = $counts['unread'];
            $Total[$type] = $counts['count'];
            $MessFav += $counts['fav'];
        }

        $UnRead[MessageType::All->value]       = array_sum($UnRead);
        $Total[MessageType::All->value]        = array_sum($Total);
        $Total[MessageType::Favorites->value]  = $MessFav;

        foreach ($TitleColor as $CategoryID => $CategoryColor) {
            $CategoryList[$CategoryID]	= [
                'color'		=> $CategoryColor,
                'unread'	=> $UnRead[$CategoryID],
                'total'		=> $Total[$CategoryID],
            ];
        }

        return $CategoryList;
    }

    /**
     * SQL condition and parameters of the list for the category, the favourites switch and the search text (always
     * restricted to the player's non-deleted messages).
     *
     * @return array{0: string, 1: array<string, mixed>} condition on aliases um/m, named parameters
     */
    private function filter(mixed $MessCategory, mixed $fav, string $searchtext): array
    {
        $USER = $this->state->USER;
        $conditions = "um.`user_id` = :userId AND um.`deleted_at` IS NULL";
        $params = [':userId' => $USER['id']];

        if ($fav == 1 || $MessCategory == MessageType::Favorites->value) {
            $conditions .= " AND um.`message_favorite` = 1";
        }

        if (!empty($searchtext)) {
            $conditions .= " AND (m.`message_subject` LIKE :searchtext OR m.`message_text` LIKE :searchtext)";
            $params[':searchtext'] = "%" . $searchtext . "%";
        }

        if ($MessCategory != MessageType::All->value && $MessCategory != MessageType::Favorites->value && $MessCategory != MessageType::Search->value) {
            $conditions .= " AND m.`message_type` = :messCategory";
            $params[':messCategory'] = $MessCategory;
        }

        return [$conditions, $params];
    }

    /**
     * Message types that are shown grouped by conversation: with the grouping cookie off only private messages,
     * otherwise also reports, system and transport messages.
     *
     * @return list<int>
     */
    private function groupedTypes(int $grp): array
    {
        $groupedArray = [
            MessageType::SpyReport->value,
            MessageType::SpyDefence->value,
            MessageType::User->value,
            MessageType::BattleReport->value,
            MessageType::System->value,
            MessageType::Transport->value,
            MessageType::ForeignTransport->value,
        ];
        if ($grp === 0) {
            $groupedArray = [
                MessageType::User->value,
            ];
        }

        return $groupedArray;
    }

    /**
     * Number of the player's messages the list filter matches. Without search text it is derived from the per-type counts
     * of the category overview (no extra query); a text search has to count the matching messages.
     *
     * @param array<string, mixed> $params
     */
    private function countMatching(string $conditions, array $params, mixed $MessCategory, mixed $fav, string $searchtext): int|string
    {
        if (!empty($searchtext)) {
            $sql = "SELECT COUNT(*) as `MessageCount` FROM %%USERS_TO_MESSAGES%% um
                INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                WHERE $conditions;";

            return $this->db->selectSingle($sql, $params, 'MessageCount');
        }

        $key = ($fav == 1 || $MessCategory == MessageType::Favorites->value) ? 'fav' : 'count';
        $counts = $this->typeCounts();
        if ($MessCategory == MessageType::All->value || $MessCategory == MessageType::Favorites->value || $MessCategory == MessageType::Search->value) {
            return array_sum(array_column($counts, $key));
        }

        return $counts[$MessCategory][$key] ?? 0;
    }

    /**
     * Loads one page of the list (in groups of 4 messages per conversation block, newest first) and the page window.
     *
     * @param list<int> $groupedArray types shown grouped
     * @param array<string, mixed> $params
     * @return array{rows: list<array<string, mixed>>, count: int|string, page: int, maxPage: int|float}
     */
    private function fetchPage(string $conditions, array $params, array $groupedArray, mixed $page, mixed $MessCategory, mixed $fav, string $searchtext): array
    {
        $USER = $this->state->USER;
        $grouped = '(' . implode(', ', $groupedArray) . ')';

        $MessageCount = $this->countMatching($conditions, $params, $MessCategory, $fav, $searchtext);
        $maxPage = max(1, ceil($MessageCount / $USER["messages_per_page"]));
        $page = max(1, min($page, $maxPage));

        $params[':offset'] = (($page - 1) * $USER["messages_per_page"]);
        $params[':limit'] = $USER["messages_per_page"];

        // The conversation partition (blocks of 4 messages per group, newest first) and the newest time of each block come
        // from one window pass over the player's grouped messages. The page is cut (order, limit) before the subject, the
        // sender name and the text are read, so only the shown rows load their text.
        $sql = "SELECT t.`message_id`, t.`grp_id`, t.`message_group`, t.`message_time`, m.`message_from`, m.`message_subject`, t.`message_sender`,
                    t.`message_type`, t.`message_unread`, t.`message_favorite`, t.`message_forwarded`, m.`message_text`, t.`LatestTimestamp`
            FROM (
                SELECT DISTINCT m.`message_id`, CONCAT(m.`message_group`, '_', part.`group_partition`) AS grp_id, m.`message_group`, m.`message_time`, um.`sender_id` as message_sender,
                    m.`message_type`, um.`message_unread`, um.`message_favorite`, um.`message_forwarded`, part.`LatestTimestamp`
                FROM %%USERS_TO_MESSAGES%% um
                JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                JOIN (
                    SELECT ranked.`message_id`, ranked.`group_partition`,
                        MAX(ranked.`message_time`) OVER (PARTITION BY ranked.`message_group`, ranked.`group_partition`) AS LatestTimestamp
                    FROM (
                        SELECT m2.`message_id`, m2.`message_group`, m2.`message_time`,
                            CEIL(ROW_NUMBER() OVER (PARTITION BY m2.`message_group` ORDER BY m2.`message_time` DESC) / 4) AS group_partition
                        FROM %%MESSAGES%% m2
                        JOIN %%USERS_TO_MESSAGES%% um2 ON um2.`message_id` = m2.`message_id`
                        WHERE um2.`user_id` = :userId
                            AND um2.`deleted_at` IS NULL
                            AND m2.`message_type` IN $grouped
                            AND m2.`message_group` IS NOT NULL
                        GROUP BY m2.`message_id`, m2.`message_group`
                    ) AS ranked
                ) AS part ON part.`message_id` = m.`message_id`
                WHERE $conditions
                    AND m.`message_type` IN $grouped
                    AND m.`message_group` IS NOT NULL
                UNION ALL
                SELECT DISTINCT m.`message_id`, '' AS grp_id, m.`message_group`, m.`message_time`, um.`sender_id` as message_sender,
                    m.`message_type`, um.`message_unread`, um.`message_favorite`, um.`message_forwarded`, m.`message_time` as LatestTimestamp
                FROM %%USERS_TO_MESSAGES%% as um
                INNER JOIN %%MESSAGES%% as m ON m.`message_id` = um.`message_id`
                WHERE $conditions
                    AND ( m.`message_type` NOT IN $grouped OR m.`message_group` IS NULL )
                ORDER BY LatestTimestamp DESC, message_time DESC, message_id
                LIMIT :offset, :limit
            ) AS t
            JOIN %%MESSAGES%% m ON m.`message_id` = t.`message_id`
            ORDER BY t.`LatestTimestamp` DESC, t.`message_time` DESC, t.`message_id`;";

        $MessageResult = [];
        if ($MessCategory != MessageType::Search->value || !empty($searchtext)) {
            $MessageResult = $this->db->select($sql, $params);
        }
        $MessageResult = $this->hideReturnedFleetButtons($MessageResult);

        return ['rows' => $MessageResult, 'count' => $MessageCount, 'page' => $page, 'maxPage' => $maxPage];
    }

    /**
     * Recipients of the private messages in $rows (everyone but the sender), keyed by message id: one query for the whole page.
     *
     * @param list<array<string, mixed>> $rows message rows of the page
     * @return array<int|string, list<array{id: mixed, receiver: mixed}>>
     */
    private function receiversByMessage(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['message_type'] == MessageType::User->value) {
                $ids[(int) $row['message_id']] = (int) $row['message_id'];
            }
        }
        if ($ids === []) {
            return [];
        }

        $sql = "SELECT um.`message_id`, u.`id`, CONCAT(u.`username`, ' [', u.`galaxy`, ':', u.`system`, ':', u.`planet`, ']') AS receiver
                FROM %%USERS%% as u
                INNER JOIN %%USERS_TO_MESSAGES%% um ON um.`user_id` = u.`id`
                WHERE um.`user_id` <> um.`sender_id` AND um.`message_id` IN (" . implode(',', $ids) . ")
                ORDER BY um.`message_id`, um.`user_id`, um.`sender_id`, um.`forwarder_id`;";
        $receivers = [];
        foreach ($this->db->select($sql) as $row) {
            $receivers[$row['message_id']][] = ['id' => $row['id'], 'receiver' => $row['receiver']];
        }

        return $receivers;
    }

    /** Id of the planet or moon a stored spy report is about (for "spy again"), 0 when the report has no readable target or the planet is gone. */
    private function spiedPlanetId(string $reportText): int
    {
        $target = json_decode($reportText, true)['targetPlanet'] ?? null;
        if (!is_array($target) || !isset($target['galaxy'], $target['system'], $target['planet'], $target['planet_type'])) {
            return 0;
        }
        $sql = 'SELECT `id` FROM %%PLANETS%% WHERE `universe` = :universe AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet AND `planet_type` = :type AND `destruyed` = 0;';

        return (int) $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(), ':galaxy' => (int) $target['galaxy'], ':system' => (int) $target['system'],
            ':planet' => (int) $target['planet'], ':type' => (int) $target['planet_type'],
        ], 'id');
    }

    /**
     * Template data of one message row: formatted time, spy report text, recipients of private messages and the
     * user ids an answer goes to.
     *
     * @param array<string, mixed> $MessageRow
     * @param list<int> $groupedArray types shown grouped
     * @param list<array{id: mixed, receiver: mixed}> $receiverList recipients of a private message (receiversByMessage())
     * @return array<string, mixed>
     */
    private function describeRow(array $MessageRow, array $groupedArray, array $receiverList): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $answerTo = [];
        if ($MessageRow['message_sender'] != $USER['id']) {
            $answerTo[] = $MessageRow['message_sender'];
        }
        foreach ($receiverList as $receiverRow) {
            if ($receiverRow['id'] != $USER['id']) {
                $answerTo[] = $receiverRow['id'];
            }
        }
        $spyTitle = '';
        $spyPlanetId = 0;
        if ($MessageRow['message_type'] == MessageType::SpyReport->value) {
            $spyPlanetId = $this->spiedPlanetId((string) $MessageRow['message_text']);
            ['title' => $spyTitle, 'html' => $MessageRow['message_text']] = $this->reports->titleAndHtml((string) $MessageRow['message_text']);
        }

        if (!in_array($MessageRow['message_type'], $groupedArray)) {
            $MessageRow['message_group'] = $MessageRow['message_id'];
        }

        return [
            'id'		=> $MessageRow['message_id'],
            'time'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $MessageRow['message_time'], $USER['timezone']),
            'from'		=> $MessageRow['message_from'],
            'subject'	=> $MessageRow['message_subject'],
            'sender'	=> $MessageRow['message_sender'],
            'type'		=> $MessageRow['message_type'],
            'unread'	=> $MessageRow['message_unread'],
            'favorite'	=> $MessageRow['message_favorite'],
            'forwarded'	=> $MessageRow['message_forwarded'],
            'text'		=> $MessageRow['message_text'],
            'spyTitle'	=> $spyTitle,
            'spyPlanetId' => $spyPlanetId,
            'grp_id'	=> empty($MessageRow['grp_id']) ? $MessageRow['message_id'] : $MessageRow['grp_id'],
            'group'		=> empty($MessageRow['message_group']) ? $MessageRow['message_id'] : $MessageRow['message_group'],
            'myself'	=> $MessageRow['message_sender'] == $USER['id'],
            'receiver'	=> $receiverList,
            'answerTo'	=> $answerTo,
        ];
    }

    /**
     * The list page: filtered messages with their template data, the category overview and the page window. The messages
     * on the page are marked as read.
     *
     * @param mixed $MessCategory requested category (MessageType value, -1 = ALL)
     * @param mixed $page         requested page number (clamped to the available pages)
     * @param mixed $fav          1 = only favourites
     * @param int   $grp          1 = group reports and system messages like private messages
     * @return array{MessageList: list<array<string, mixed>>, CategoryList: array<int, array<string, mixed>>, MessageCount: int|string, page: int, maxPage: int|float}
     */
    public function listPage(mixed $MessCategory, mixed $page, mixed $fav, string $searchtext, int $grp): array
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $MessageList    = [];
        $MessagesID     = [];
        [$conditions, $params] = $this->filter($MessCategory, $fav, $searchtext);
        $groupedArray = $this->groupedTypes($grp);
        $data = $this->fetchPage($conditions, $params, $groupedArray, $page, $MessCategory, $fav, $searchtext);

        $receivers = $this->receiversByMessage($data['rows']);
        foreach ($data['rows'] as $MessageRow) {
            $MessagesID[] = $MessageRow['message_id'];
            $MessageList[] = $this->describeRow($MessageRow, $groupedArray, $receivers[$MessageRow['message_id']] ?? []);
        }

        if (!empty($MessagesID)) {
            // the listed messages count as read
            $db->execute(
                $db->qb()
                    ->update($db->table('USERS_TO_MESSAGES'))
                    ->set('`message_unread`', '0')
                    ->where('`message_id` IN (:messageIds)')
                    ->andWhere('`user_id` = :userID')
                    ->setParameter('messageIds', array_map('intval', $MessagesID), ArrayParameterType::INTEGER)
                    ->setParameter('userID', $USER['id']),
            );
        }

        // counted after the listed messages were marked as read
        $this->typeCounts = null;
        $CategoryList = $this->categoryList();

        return [
            'MessageList' => $MessageList,
            'CategoryList' => $CategoryList,
            'MessageCount' => $data['count'],
            'page' => $data['page'],
            'maxPage' => $data['maxPage'],
        ];
    }

    /**
     * Marketplace hold messages embed a recall form. Remove it once that fleet
     * is flying home or has already arrived and been deleted.
     *
     * @param array<mixed> $messages
     * @return array<mixed>
     */
    public function hideReturnedFleetButtons(array $messages): array
    {
        $fleetIds = [];
        foreach ($messages as $message) {
            $text = $message['message_text'] ?? '';
            if (!is_string($text) || !preg_match('#fleet/recall|sendfleetback#', $text)) {
                continue;
            }
            if (preg_match_all('/name="fleetID"\s+value="(\d+)"/', $text, $matches)) {
                foreach ($matches[1] as $fleetId) {
                    $fleetIds[(int) $fleetId] = (int) $fleetId;
                }
            }
        }

        if ($fleetIds === []) {
            return $messages;
        }

        $fleets = $this->db->fetchAll(
            $this->db->qb()
                ->select('id', 'mess')
                ->from($this->db->table('FLEETS'))
                ->where('id IN (:fleetIds)')
                ->setParameter('fleetIds', array_values($fleetIds), ArrayParameterType::INTEGER),
        );
        $stateById = [];
        foreach ($fleets as $fleet) {
            $stateById[(int) $fleet['id']] = (int) $fleet['mess'];
        }

        foreach ($messages as &$message) {
            $text = $message['message_text'] ?? '';
            if (!is_string($text) || !preg_match('#fleet/recall|sendfleetback#', $text)) {
                continue;
            }
            $message['message_text'] = preg_replace_callback(
                '/<form\b[^>]*(?:fleet\/recall|sendfleetback)[^>]*>.*?<\/form>/is',
                static function (array $match) use ($stateById): string {
                    if (!preg_match('/name="fleetID"\s+value="(\d+)"/', $match[0], $idMatch)) {
                        return $match[0];
                    }
                    $fleetId = (int) $idMatch[1];
                    if (!isset($stateById[$fleetId]) || $stateById[$fleetId] === FLEET_RETURN) {
                        return '';
                    }
                    return $match[0];
                },
                $text,
            );
        }
        unset($message);

        return $messages;
    }
}
