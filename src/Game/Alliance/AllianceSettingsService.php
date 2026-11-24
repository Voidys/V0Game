<?php

namespace App\Game\Alliance;

use App\Community\Chat;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * The alliance's own settings and texts (admin overview page): name and tag, leader title, logo and website links,
 * application rules, public statistics/diplomacy switches and the three texts (external, internal, application).
 */
class AllianceSettingsService
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * The text that belongs to the selected text tab (shown when the form is not submitted).
     *
     * @param array<string, mixed> $alliance
     * @param string $textMode 'internal', 'apply', anything else = external description
     */
    public function textFor(array $alliance, string $textMode): ?string
    {
        return match ($textMode) {
            'internal' => $alliance['ally_text'],
            'apply' => $alliance['ally_request'],
            default => $alliance['ally_description'],
        };
    }

    /**
     * Validates and stores the submitted settings.
     *
     * Rules: tag max. 8 and name max. 30 characters and valid names; a changed tag/name must be unused in the
     * universe (an unchanged or empty one is kept); website/image only absolute http(s) links (empty otherwise); the
     * member limit never drops below the current member count; ally_request_notallow is coerced to 0/1; only the text
     * of the selected tab (`textMode`: external, internal, apply) is written.
     *
     * @param array<string, mixed> $alliance current alliance row
     * @param array<string, mixed> $input    owner_range, web, image, request_notallow, ally_max_members,
     *                                       request_min_points, stats, diplo, ally_tag, ally_name, text, textMode, chat_slowmode (only for the leader, null = unchanged)
     * @return array<string, mixed> the alliance row with the new values (for re-rendering the page)
     * @throws AllianceException message page (back to the admin page) on invalid or duplicate name/tag
     */
    public function save(array $alliance, array $input): array
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $back = 'game_alliance_admin';

        // the request values are HTML-encoded and the columns hold the encoded text: a too long link is refused with a
        // message, the leader title is cut on the typed text (never inside an entity) until its encoded form fits
        foreach (['web', 'image'] as $linkField) {
            if (mb_strlen((string) $input[$linkField]) > 255) {
                throw AllianceException::message($LNG['al_link_too_long'], $LNG['sys_back'], $back);
            }
        }
        $alliance['ally_owner_range'] = self::fitEncoded((string) $input['owner_range'], 32);
        $alliance['ally_web'] = AllianceText::httpUrlOrEmpty($input['web']);
        $alliance['ally_image'] = AllianceText::httpUrlOrEmpty($input['image']);
        $alliance['ally_request_notallow'] = $input['request_notallow'];
        $alliance['ally_max_members'] = max($input['ally_max_members'], $alliance['ally_members']);
        $alliance['ally_request_min_points'] = $input['request_min_points'];
        $alliance['ally_stats'] = $input['stats'];
        $alliance['ally_diplo'] = $input['diplo'];
        // the chat slow mode is the leader's alone: the controller passes it only for him, and only listed values count
        $slow = $input['chat_slowmode'] ?? null;
        if ($slow !== null && in_array((int) $slow, Chat::ALLIANCE_SLOWMODES, true)) {
            $alliance['chat_slowmode'] = (int) $slow;
        }

        $alliance = $this->applyTagAndName($alliance, $input, $back);

        if ($alliance['ally_request_notallow'] != 0 && $alliance['ally_request_notallow'] != 1) {
            $alliance['ally_request_notallow'] = 0;
        }

        $textSQL = match ($input['textMode']) {
            'external' => "ally_description = :text, ",
            'internal' => "ally_text = :text, ",
            'apply' => "ally_request = :text, ",
            default => "",
        };

        $sql = "UPDATE %%ALLIANCE%% SET
			" . $textSQL . "
			ally_tag = :AllianceTag,
			ally_name = :AllianceName,
			ally_owner_range = :AllianceOwnerRange,
			ally_image = :AllianceImage,
			ally_web = :AllianceWeb,
			ally_request_notallow = :AllianceRequestNotAllow,
			ally_max_members = :AllianceMaxMember,
			ally_request_min_points = :AllianceRequestMinPoints,
			ally_stats = :AllianceStats,
			ally_diplo = :AllianceDiplo,
			chat_slowmode = :ChatSlowmode
			WHERE id = :AllianceID;";

        $db->update($sql, [
            ':AllianceTag' => $alliance['ally_tag'],
            ':AllianceName' => $alliance['ally_name'],
            ':AllianceOwnerRange' => $alliance['ally_owner_range'],
            ':AllianceImage' => $alliance['ally_image'],
            ':AllianceWeb' => $alliance['ally_web'],
            ':AllianceRequestNotAllow' => $alliance['ally_request_notallow'],
            ':AllianceMaxMember' => $alliance['ally_max_members'],
            ':AllianceRequestMinPoints' => $alliance['ally_request_min_points'],
            ':AllianceStats' => $alliance['ally_stats'],
            ':AllianceDiplo' => $alliance['ally_diplo'],
            ':ChatSlowmode' => (int) ($alliance['chat_slowmode'] ?? 0),
            ':AllianceID' => $alliance['id'],
            ':text' => $input['text'],
        ]);

        return $alliance;
    }

    /**
     * Validates and applies a new alliance tag/name (length, allowed characters, not taken by another alliance).
     *
     * @param array<string, mixed> $alliance current alliance row
     * @param array<string, mixed> $input    request values (ally_tag, ally_name)
     * @return array<string, mixed> the alliance row with the new tag/name
     * @throws AllianceException message page (back to `$back`) on invalid or duplicate name/tag
     */
    private function applyTagAndName(array $alliance, array $input, string $back): array
    {
        $LNG = $this->state->LNG;
        $new_ally_tag = $input['ally_tag'] ?? $alliance['ally_tag'];
        $new_ally_name = $input['ally_name'] ?? $alliance['ally_name'];

        if (mb_strlen($new_ally_tag) > 8 || mb_strlen($new_ally_name) > 30) {
            throw AllianceException::message($LNG['al_name_tag_too_long'], $LNG['sys_back'], $back);
        }

        if (!$this->playerRegistration()->isNameValid($new_ally_tag) || !$this->playerRegistration()->isNameValid($new_ally_name)) {
            throw AllianceException::message($LNG['al_newname_specialchar'], $LNG['sys_back'], $back);
        }

        if (!empty($new_ally_tag) && $alliance['ally_tag'] != $new_ally_tag) {
            $this->assertFree('ally_tag', ':NewAllianceTag', $new_ally_tag);
            $alliance['ally_tag'] = $new_ally_tag;
        }

        if (!empty($new_ally_name) && $alliance['ally_name'] != $new_ally_name) {
            $this->assertFree('ally_name', ':NewAllianceName', $new_ally_name);
            $alliance['ally_name'] = $new_ally_name;
        }

        return $alliance;
    }

    /** encoded request text cut to what fits into $length characters once encoded again, cut on the decoded text */
    private static function fitEncoded(string $encoded, int $length): string
    {
        $text = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        do {
            $result = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $text = mb_substr($text, 0, -1);
        } while (mb_strlen($result) > $length && $text !== '');

        return mb_strlen($result) > $length ? '' : $result;
    }

    /**
     * @param string $column ally_tag or ally_name (fixed by the caller, never user input)
     * @throws AllianceException message page when another alliance of the universe already uses the value
     */
    private function assertFree(string $column, string $placeholder, string $value): void
    {
        $LNG = $this->state->LNG;
        $sql = "SELECT COUNT(*) as count FROM %%ALLIANCE%% WHERE ally_universe = :universe"
            . " AND $column = $placeholder;";
        $allianceCount = $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            $placeholder => $value,
        ], 'count');

        if ($allianceCount != 0) {
            throw AllianceException::message(AllianceText::html($LNG, 'al_already_exists', $value), $LNG['sys_back'], 'game_alliance_admin');
        }
    }
}
