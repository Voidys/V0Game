<?php

declare(strict_types=1);

namespace App\Game\Engagement\Feed;

use App\Community\Chat;
use App\Game\Chat\ChatSettings;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\EngagementFeature;

/**
 * Battle feed: a system line in the global chat for every big real fight ("Alice vs Bob: 2.1M units lost", with a link
 * to the public report). The line is stored as a plain-text chat message of user 0 plus a report mark (see
 * Chat::SYSTEM_REPORT_MARK); the chat client shows text with textContent and builds the report link itself from the
 * whitelisted report id, so no HTML or user text can get into the line. Skipped while the chat module or its global
 * channel is off, and for fights without two human sides (pirates).
 */
final class BattleFeed extends EngagementFeature
{
    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'battle_feed_enabled', false);
    }

    /** Posts the line when the fight lost at least battle_feed_min_units. */
    public function onBattle(BattleOutcome $outcome): void
    {
        $universe = $outcome->universe;
        $config = $this->configs->get($universe);
        if ($outcome->kind === 'pirate' || $outcome->attackers === [] || $outcome->defenders === []
            || $outcome->totalLost() < max(1, (int) $this->setting($universe, 'battle_feed_min_units', 1000000))
            || !preg_match('/^[A-Za-z0-9]{1,32}$/', $outcome->reportId)
            || !$this->viewHelpers()->isModuleAvailable(MODULE_CHAT, $universe)
            || !ChatSettings::fromConfig($config)->globalEnabled
            || $this->postedLastHour($universe, $outcome->time) >= max(1, (int) $this->setting($universe, 'battle_feed_max_per_hour', 6))) {
            return;
        }
        $lang = $this->playerLookup()->getLanguage((string) $config->lang);
        $text = $lang->format('bf_line', $this->label($outcome->attackers), $this->label($outcome->defenders), $this->units($outcome->totalLost()));
        if ($this->setting($universe, 'battle_feed_show_coords', false)) {
            $text .= ' [' . $outcome->galaxy . ':' . $outcome->system . ':' . $outcome->planet . ']';
        }
        $this->db->insert('INSERT INTO %%CHAT_MESSAGES%% (`universe`, `channel`, `user_id`, `username`, `ally_tag`, `message`, `original_message`, `created_at`)
            VALUES (:universe, \'global\', 0, \'\', NULL, :message, NULL, :now);', [
            ':universe' => $universe,
            ':message' => mb_substr($text, 0, 400) . Chat::SYSTEM_REPORT_MARK . $outcome->reportId,
            ':now' => $outcome->time,
        ]);
    }

    /** System lines posted to the global chat in the last hour (the cap of battle_feed_max_per_hour). */
    private function postedLastHour(int $universe, int $now): int
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%CHAT_MESSAGES%% WHERE `universe` = :u AND `channel` = \'global\' AND `user_id` = 0 AND `created_at` > :since;',
            [':u' => $universe, ':since' => $now - 3600],
            'c',
        );
    }

    /**
     * "Name" or "Name +2" for the players of a side.
     *
     * @param array<int, string> $names
     */
    private function label(array $names): string
    {
        // names are stored HTML-encoded: decode before cutting (the chat client shows text with textContent)
        $first = html_entity_decode((string) reset($names), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr($first, 0, 32) . (count($names) > 1 ? ' +' . (count($names) - 1) : '');
    }

    /** Short number: 950, 1.2K, 2.1M, 3.4B. */
    private function units(float $units): string
    {
        foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$step, $suffix]) {
            if ($units >= $step) {
                return rtrim(rtrim(number_format($units / $step, 1, '.', ''), '0'), '.') . $suffix;
            }
        }

        return (string) (int) $units;
    }
}
