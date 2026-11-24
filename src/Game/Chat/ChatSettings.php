<?php

declare(strict_types=1);

namespace App\Game\Chat;

use App\Game\Core\Config;

/**
 * The chat settings of a universe (admin config category "chat"); keys missing in the configuration count as their defaults.
 */
final class ChatSettings
{
    public function __construct(
        public readonly bool $globalEnabled = true,
        public readonly ChatFilter $filter = new ChatFilter(),
        public readonly int $minAccountAgeHours = 0,
        public readonly int $minPoints = 0,
        public readonly int $globalSlowmode = 0,
    ) {}

    /** account age (hours) required for the global channel when the universe has no setting yet; also the schema default */
    public const DEFAULT_MIN_ACCOUNT_AGE_HOURS = 24;

    /** Reads the settings of a universe config; a key the config does not have yet counts as its default. */
    public static function fromConfig(Config $config, string $ownHost = ''): self
    {
        return new self(
            (bool) ($config->chat_global_enabled ?? true),
            new ChatFilter(
                (string) ($config->chat_filter_words ?? ''),
                (bool) ($config->chat_block_links ?? true),
                [...(preg_split('/\\R/u', (string) ($config->chat_allowed_domains ?? '')) ?: []), $ownHost],
            ),
            (int) ($config->chat_min_account_age_hours ?? self::DEFAULT_MIN_ACCOUNT_AGE_HOURS),
            (int) ($config->chat_min_points ?? 0),
            max(0, min(3600, (int) ($config->chat_global_slowmode ?? 0))),
        );
    }
}
