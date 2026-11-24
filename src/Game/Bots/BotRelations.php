<?php

namespace App\Game\Bots;

use App\Game\Core\Database;

/**
 * Internal relation counter between a bot and a human player (never shown to players). Every interaction (a message, a buddy
 * request, a spy probe, an attack, a raid by the bot) moves `balance` by a fixed delta (EVENTS) within -100..100 and counts as
 * an interaction. `stance()` turns the balance into hostile / neutral / friendly, which sets the tone of replies and combat
 * reactions. `player_contacted` is set once the player wrote to, spied on or attacked the bot: a bot never opens contact with
 * a stranger, so chat and reactions only go to contacted players. The row also holds the per-player message pointer and the
 * time of the bot's last message (reply throttle).
 */
class BotRelations
{
    public const EVENT_MESSAGE = 'message';
    public const EVENT_BUDDY_REQUEST = 'buddy_request';
    public const EVENT_BUDDY_ACCEPTED = 'buddy_accepted';
    public const EVENT_PLAYER_SPIED = 'player_spied';
    public const EVENT_PLAYER_ATTACKED = 'player_attacked';
    public const EVENT_BOT_RAIDED = 'bot_raided';

    /** Balance change per event. */
    public const EVENTS = [
        self::EVENT_MESSAGE => 1,
        self::EVENT_BUDDY_REQUEST => 3,
        self::EVENT_BUDDY_ACCEPTED => 2,
        self::EVENT_PLAYER_SPIED => -2,
        self::EVENT_PLAYER_ATTACKED => -8,
        self::EVENT_BOT_RAIDED => -3,
    ];

    /** Events that mean the player got in touch with the bot (sets `player_contacted`). */
    public const CONTACT_EVENTS = [self::EVENT_MESSAGE, self::EVENT_PLAYER_SPIED, self::EVENT_PLAYER_ATTACKED];

    /** Grudge points an event adds (the grudge decays 10 % per day, see grudge()). */
    public const GRUDGE_EVENTS = [
        self::EVENT_PLAYER_SPIED => 2.0,
        self::EVENT_PLAYER_ATTACKED => 10.0,
    ];

    /** Daily decay factor of a grudge. */
    public const GRUDGE_DECAY = 0.9;

    public const MIN_BALANCE = -100;
    public const MAX_BALANCE = 100;
    /** Balance at or below this is hostile, at or above FRIENDLY a friendly stance. */
    public const HOSTILE = -15;
    public const FRIENDLY = 10;

    public function __construct(private readonly Database $db) {}

    /**
     * Records an interaction: creates the relation on first contact (message pointer starts at the bot's legacy
     * `last_message_id`, so old mail is not answered), moves the balance by the event delta (clamped), counts the interaction and
     * flags the player as contacted for contact events. Unknown events only count as an interaction.
     */
    public function record(int $bot, int $player, int $uni, string $event): void
    {
        $delta = self::EVENTS[$event] ?? 0;
        $contact = in_array($event, self::CONTACT_EVENTS, true) ? 1 : 0;
        $grudge = self::GRUDGE_EVENTS[$event] ?? 0.0;
        $now = time();
        // grudge first, then its time: MySQL evaluates the assignments left to right, the decay needs the old time
        $grudgeSql = $grudge > 0
            ? 'grudge = LEAST(100, grudge * POW(' . self::GRUDGE_DECAY . ', GREATEST(0, :now3 - grudge_at) / 86400) + :g2), grudge_at = :now4,'
            : '';
        $params = [
            ':bot' => $bot, ':bot2' => $bot, ':player' => $player, ':uni' => $uni, ':delta' => $delta,
            ':delta2' => $delta, ':contact' => $contact, ':contact2' => $contact, ':now' => $now, ':now2' => $now, ':now6' => $now, ':g' => $grudge,
        ];
        if ($grudge > 0) {
            $params += [':now3' => $now, ':now4' => $now, ':g2' => $grudge];
        }
        $this->db->insert(
            'INSERT INTO %%BOT_RELATIONS%% (bot_id, player_id, universe, balance, grudge, grudge_at, interactions, player_contacted, last_interaction, last_handled_message_id)
             VALUES (:bot, :player, :uni, :delta, :g, :now, 1, :contact, :now6, COALESCE((SELECT last_message_id FROM %%BOT_PLAYERS%% WHERE user_id = :bot2), 0))
             ON DUPLICATE KEY UPDATE
               balance = LEAST(' . self::MAX_BALANCE . ', GREATEST(' . self::MIN_BALANCE . ', balance + CAST(:delta2 AS SIGNED))),
               ' . $grudgeSql . '
               interactions = interactions + 1,
               player_contacted = IF(CAST(:contact2 AS UNSIGNED) = 1, 1, player_contacted),
               last_interaction = :now2;',
            $params,
        );
    }

    /**
     * The grudge of a relation row now: the stored value decayed by 10 % per day since it was last raised.
     *
     * @param array<mixed> $relation row with `grudge` and `grudge_at`
     */
    public function grudge(array $relation, int $now): float
    {
        $value = (float) ($relation['grudge'] ?? 0);
        if ($value <= 0) {
            return 0.0;
        }

        return $value * self::GRUDGE_DECAY ** (max(0, $now - (int) ($relation['grudge_at'] ?? 0)) / 86400);
    }

    /**
     * All grudges of a bot that are still above `$min` after the decay, by player id, strongest first.
     *
     * @return array<int, float>
     */
    public function grudges(int $bot, int $now, float $min = 1.0): array
    {
        $out = [];
        $rows = $this->db->select('SELECT player_id, grudge, grudge_at FROM %%BOT_RELATIONS%% WHERE bot_id = :bot AND grudge > 0;', [':bot' => $bot]);
        foreach ($rows as $row) {
            $value = $this->grudge($row, $now);
            if ($value >= $min) {
                $out[(int) $row['player_id']] = $value;
            }
        }
        arsort($out);

        return $out;
    }

    /**
     * The relation row, or a neutral default (balance 0, not contacted, pointer = the bot's legacy `last_message_id`)
     * when there is none.
     *
     * @return array<string, mixed>
     */
    public function get(int $bot, int $player): array
    {
        $row = $this->db->selectSingle('SELECT * FROM %%BOT_RELATIONS%% WHERE bot_id = :bot AND player_id = :player;', [':bot' => $bot, ':player' => $player]);

        return !empty($row) ? $row : [
            'bot_id' => $bot, 'player_id' => $player, 'balance' => 0, 'interactions' => 0, 'player_contacted' => 0,
            'last_interaction' => 0, 'last_handled_message_id' => 0, 'last_bot_message_at' => 0,
        ];
    }

    /**
     * 'hostile', 'neutral' or 'friendly' for a relation row (or a plain balance).
     *
     * @param array<mixed> $relation
     */
    public function stance(array|int $relation): string
    {
        $balance = is_array($relation) ? (int) ($relation['balance'] ?? 0) : $relation;
        if ($balance <= self::HOSTILE) {
            return 'hostile';
        }

        return $balance >= self::FRIENDLY ? 'friendly' : 'neutral';
    }

    /** Moves the message pointer of a relation (creating a neutral, uncontacted row if needed) to `$messageId`. */
    public function markHandled(int $bot, int $player, int $uni, int $messageId): void
    {
        $this->db->insert(
            'INSERT INTO %%BOT_RELATIONS%% (bot_id, player_id, universe, last_handled_message_id) VALUES (:bot, :player, :uni, :id)
             ON DUPLICATE KEY UPDATE last_handled_message_id = GREATEST(last_handled_message_id, CAST(:id2 AS UNSIGNED));',
            [':bot' => $bot, ':player' => $player, ':uni' => $uni, ':id' => $messageId, ':id2' => $messageId],
        );
    }

    /** Stores the time the bot last wrote to the player. */
    public function markBotMessage(int $bot, int $player, int $uni): void
    {
        $now = time();
        $this->db->insert(
            'INSERT INTO %%BOT_RELATIONS%% (bot_id, player_id, universe, last_bot_message_at) VALUES (:bot, :player, :uni, :now)
             ON DUPLICATE KEY UPDATE last_bot_message_at = :now2;',
            [':bot' => $bot, ':player' => $player, ':uni' => $uni, ':now' => $now, ':now2' => $now],
        );
    }

    /**
     * One-line summary for LLM prompts, e.g. "hostile (balance -23, 7 interactions)".
     *
     * @param array<mixed> $relation
     */
    public function summary(array $relation): string
    {
        return $this->stance($relation) . ' (balance ' . (int) $relation['balance'] . ', ' . (int) $relation['interactions'] . ' interactions)';
    }
}
