<?php

declare(strict_types=1);

namespace App\Game\Bots;

use App\Game\Core\Config;

/**
 * Every knob of the bots of one universe (admin category group "bots"), as a value object. Keys missing in the universe config
 * count as their defaults, and the defaults reproduce the behaviour from before the knobs existed (preset `normal`). A single bot can
 * overlay a preset and an aggression of its own (forBot()), kept in its personality under `_cfg`.
 *
 * Aggression is relative to 50 (today's behaviour): the raid chance of a session scales with aggression / 50 (at most 100 %), the
 * profit ratio a raid has to promise with 50 / aggression (between a half and double).
 */
final class BotSettings
{
    /**
     * Knobs by property name: [config key, kind, default, min, max]. Kinds: int, bool, string, shares (percent list).
     * The same table drives parsing, the admin schema and the tests.
     */
    public const KNOBS = [
        'enabled' => ['bots_enabled', 'bool', false, 0, 1],
        'maxCount' => ['bots_max_count', 'int', 50, 0, 1000],
        'ticksPerRun' => ['bots_ticks_per_run', 'int', 25, 1, 500],
        'preset' => ['bots_preset', 'string', BotPresets::NORMAL, 0, 0],
        'maxFleets' => ['bots_max_fleets', 'int', 0, 0, 10000],
        'alliancesEnabled' => ['bots_alliances_enabled', 'bool', true, 0, 1],
        'joinHumanAlliances' => ['bots_join_human_alliances', 'bool', false, 0, 1],
        'llmEnabled' => ['bots_llm_enabled', 'bool', false, 0, 1],
        'llmDailyLimit' => ['bots_llm_daily_limit', 'int', 20, 0, 10000],
        'replyDelayHours' => ['bot_reply_delay_hours', 'int', 3, 0, 168],
        'chatter' => ['bot_chatter', 'bool', true, 0, 1],

        'raidsEnabled' => ['bots_raids_enabled', 'bool', false, 0, 1],
        'aggression' => ['bots_aggression', 'int', 50, 0, 100],
        'attackActives' => ['bots_attack_actives', 'bool', true, 0, 1],
        'raidsPerTargetDay' => ['bots_raids_per_target_day', 'int', 0, 0, 50],
        'targetCooldownHours' => ['bots_target_cooldown_hours', 'int', 2, 1, 168],
        'minTargetPoints' => ['bots_min_target_points', 'int', 0, 0, 1000000000],
        'minTargetRatioPercent' => ['bots_min_target_ratio_percent', 'int', 0, 0, 1000],
        'probesPerTargetDay' => ['bots_probes_per_target_day', 'int', 0, 0, 100],
        'probesWanted' => ['bots_probes_wanted', 'int', 4, 1, 50],
        'minLoot' => ['bots_min_loot', 'int', 3000, 0, 1000000000],
        'escortShares' => ['bots_escort_shares', 'shares', [0.0, 0.15, 0.3, 0.5, 0.75, 1.0], 0, 0],
        'simulationRuns' => ['bots_simulation_runs', 'int', 2, 1, 5],
        'spyCooldownMinutes' => ['bots_spy_cooldown_minutes', 'int', 120, 1, 10080],
        'spyCooldownStreakMinutes' => ['bots_spy_cooldown_streak_minutes', 'int', 15, 1, 1440],
        'recallLootPercent' => ['bots_recall_loot_percent', 'int', 50, 0, 100],
        'recallRatioPercent' => ['bots_recall_ratio_percent', 'int', 50, 0, 100],
        'streakLootPercent' => ['bots_streak_loot_percent', 'int', 50, 0, 1000],
        'raidsPerHumanDay' => ['bots_raids_per_human_day', 'int', 3, 1, 100],
        'humanCooldownHours' => ['bots_human_cooldown_hours', 'int', 1, 1, 168],
        'probesPerHumanDay' => ['bots_probes_per_human_day', 'int', 12, 1, 1000],
        'scoutRangeMax' => ['bots_scout_range_max', 'int', 50, 1, 200],
        'respyJitterSeconds' => ['bots_respy_jitter_seconds', 'int', 45, 0, 300],

        'fleetsaveEnabled' => ['bots_fleetsave_enabled', 'bool', true, 0, 1],
        'fleetsaveKeepPercent' => ['bots_fleetsave_keep_percent', 'int', 20, 0, 100],
        'fleetsaveMinOfflineMinutes' => ['bots_fleetsave_min_offline_minutes', 'int', 20, 0, 1440],
        'threatWindowHours' => ['bots_threat_window_hours', 'int', 3, 1, 48],

        'shipWeightPercent' => ['bots_ship_weight_percent', 'int', 100, 0, 1000],
        'defenseWeightPercent' => ['bots_defense_weight_percent', 'int', 100, 0, 1000],
        'growthSpeedPercent' => ['bots_growth_speed_percent', 'int', 100, 0, 1000],
        'growthBonus' => ['bots_growth_bonus', 'int', 0, 0, 1000],
        'maxPlanets' => ['bots_max_planets', 'int', 0, 0, 500],

        'activityPercent' => ['bots_activity_percent', 'int', 100, 0, 300],
        'hiddenRhythmPercent' => ['bots_hidden_rhythm_percent', 'int', 0, 0, 100],
        'timezoneSpreadHours' => ['bots_timezone_spread_hours', 'int', 3, 0, 12],

        'revenge' => ['bots_revenge', 'bool', false, 0, 1],
        'botAcs' => ['bots_bot_acs', 'bool', false, 0, 1],
        'recycling' => ['bots_recycling', 'bool', false, 0, 1],
        'moons' => ['bots_moons', 'bool', false, 0, 1],
        'missiles' => ['bots_missiles', 'bool', false, 0, 1],
        'warmonger' => ['bots_warmonger', 'bool', false, 0, 1],
        'warmongerPoints' => ['bots_warmonger_points', 'int', 100000, 0, 1000000000],
    ];

    /**
     * @param list<float> $escortShares shares (0-1) of the target planet's warships tried as escort, smallest first
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly int $maxCount,
        public readonly int $ticksPerRun,
        public readonly string $preset,
        public readonly int $maxFleets,
        public readonly bool $alliancesEnabled,
        public readonly bool $joinHumanAlliances,
        public readonly bool $llmEnabled,
        public readonly int $llmDailyLimit,
        public readonly int $replyDelayHours,
        public readonly bool $chatter,
        public readonly bool $raidsEnabled,
        public readonly int $aggression,
        public readonly bool $attackActives,
        public readonly int $raidsPerTargetDay,
        public readonly int $targetCooldownHours,
        public readonly int $minTargetPoints,
        public readonly int $minTargetRatioPercent,
        public readonly int $probesPerTargetDay,
        public readonly int $probesWanted,
        public readonly int $minLoot,
        public readonly array $escortShares,
        public readonly int $simulationRuns,
        public readonly int $spyCooldownMinutes,
        public readonly int $spyCooldownStreakMinutes,
        public readonly int $recallLootPercent,
        public readonly int $recallRatioPercent,
        public readonly int $streakLootPercent,
        public readonly int $raidsPerHumanDay,
        public readonly int $humanCooldownHours,
        public readonly int $probesPerHumanDay,
        public readonly int $scoutRangeMax,
        public readonly int $respyJitterSeconds,
        public readonly bool $fleetsaveEnabled,
        public readonly int $fleetsaveKeepPercent,
        public readonly int $fleetsaveMinOfflineMinutes,
        public readonly int $threatWindowHours,
        public readonly int $shipWeightPercent,
        public readonly int $defenseWeightPercent,
        public readonly int $growthSpeedPercent,
        public readonly int $growthBonus,
        public readonly int $maxPlanets,
        public readonly int $activityPercent,
        public readonly int $hiddenRhythmPercent,
        public readonly int $timezoneSpreadHours,
        public readonly bool $revenge,
        public readonly bool $botAcs,
        public readonly bool $recycling,
        public readonly bool $moons,
        public readonly bool $missiles,
        public readonly bool $warmonger,
        public readonly int $warmongerPoints,
    ) {}

    /** The defaults (preset `normal`, raids off): the behaviour of the bots before the knobs existed. */
    public static function defaults(): self
    {
        return self::fromValues([]);
    }

    /** Reads the universe settings; a key the config does not have yet counts as its default. */
    public static function fromConfig(Config $config): self
    {
        $raw = [];
        foreach (self::KNOBS as $property => [$key]) {
            $raw[$property] = $config->$key ?? null;
        }

        return self::fromValues($raw);
    }

    /**
     * Builds the settings from raw values by property name (strings from the config are fine): missing or null values become the default,
     * numbers are clamped to the knob's range, an unknown preset name becomes `custom`.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromValues(array $raw): self
    {
        $args = [];
        foreach (self::KNOBS as $property => [, $kind, $default, $min, $max]) {
            $value = $raw[$property] ?? null;
            $args[$property] = match (true) {
                $value === null => $default,
                $kind === 'bool' => (bool) $value,
                $kind === 'int' => max($min, min($max, (int) $value)),
                $kind === 'shares' => self::parseShares($value, $default),
                default => (string) $value,
            };
        }
        if (!in_array($args['preset'], BotPresets::NAMES, true)) {
            $args['preset'] = BotPresets::CUSTOM;
        }

        return new self(...$args);
    }

    /**
     * Escort shares from the config text ("0, 15, 30, 50, 75, 100" in percent) or a list of fractions; unusable input gives the default.
     *
     * @param mixed $value text or list
     * @param list<float> $default
     * @return list<float> fractions 0-1, ascending, without duplicates
     */
    public static function parseShares(mixed $value, array $default): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $shares = [];
        foreach ($parts ?: [] as $part) {
            if (is_numeric($part)) {
                $shares[] = max(0.0, min(1.0, is_array($value) ? (float) $part : (float) $part / 100));
            }
        }
        $shares = array_values(array_unique($shares));
        sort($shares);

        return $shares === [] ? $default : $shares;
    }

    /** The escort shares as the admin field's text (percent, comma separated). */
    public function escortSharesText(): string
    {
        return implode(', ', array_map(static fn(float $s): string => (string) round($s * 100), $this->escortShares));
    }

    /**
     * The settings with the knobs of a preset laid over them; `custom` and unknown names change nothing.
     */
    public function withPreset(string $preset): self
    {
        if (!BotPresets::isPreset($preset)) {
            return $this;
        }

        return $this->with(['preset' => $preset] + BotPresets::values($preset));
    }

    /** The settings with another aggression (0-100). */
    public function withAggression(int $aggression): self
    {
        return $this->with(['aggression' => max(0, min(100, $aggression))]);
    }

    /**
     * The settings of one bot: its own preset (a name from BotPresets, empty = the universe's) first, then its own aggression
     * (null/absent = the preset's or the universe's).
     *
     * @param array<string, mixed> $override the bot's `_cfg` (`preset`, `aggression`)
     */
    public function forBot(array $override): self
    {
        $out = $this;
        $preset = (string) ($override['preset'] ?? '');
        if ($preset !== '' && BotPresets::isPreset($preset)) {
            $out = $out->withPreset($preset);
        }
        if (isset($override['aggression']) && $override['aggression'] !== '') {
            $out = $out->withAggression((int) $override['aggression']);
        }

        return $out;
    }

    /**
     * A copy with some properties replaced.
     *
     * @param array<string, mixed> $changes by property name
     */
    private function with(array $changes): self
    {
        $values = [];
        foreach (array_keys(self::KNOBS) as $property) {
            $values[$property] = $this->$property;
        }

        return self::fromValues($changes + $values);
    }

    /**
     * The profile settings of a bot with the universe's knobs laid over them: raid chance and the profit ratio a raid must promise
     * follow the aggression, the category weights of ships and defense their multipliers, the skill gain the growth knobs, the activity
     * table the activity multiplier, fleetsave the on/off switch, the attacks per target and day the cap (when set). At the defaults
     * (`normal`) the profile comes back unchanged.
     *
     * @param array<string, mixed> $s profile settings (BotProfiles::normalizeSettings())
     * @param int $userId the bot's user id (decides whether it has a hidden rhythm: `hidden_rhythm` is then the id, else 0)
     * @param float $points the bot's total points (warmonger trait)
     * @return array<string, mixed>
     */
    public function applyToProfile(array $s, int $userId = 0, float $points = 0.0): array
    {
        if ($this->aggression !== 50) {
            $s['raid_chance'] = min(100, (int) round((int) ($s['raid_chance'] ?? 0) * $this->aggression / 50));
            $s['min_profit_ratio'] = max(0.5, round((float) ($s['min_profit_ratio'] ?? 1.0) * 50 / max(25, min(100, $this->aggression)), 2));
        }
        if ($this->warmongerActive($points)) {
            // a warmonger raids more often and takes a thinner profit (the planner still needs a winnable, profitable plan)
            $s['raid_chance'] = min(100, (int) round((int) ($s['raid_chance'] ?? 0) * 1.3) + 10);
            $s['min_profit_ratio'] = max(0.5, round((float) ($s['min_profit_ratio'] ?? 1.0) * 0.85, 2));
        }
        if ($this->shipWeightPercent !== 100 && isset($s['weights']['ships'])) {
            $s['weights']['ships'] = (int) round($s['weights']['ships'] * $this->shipWeightPercent / 100);
        }
        if ($this->defenseWeightPercent !== 100 && isset($s['weights']['defense'])) {
            $s['weights']['defense'] = (int) round($s['weights']['defense'] * $this->defenseWeightPercent / 100);
        }
        if ($this->growthSpeedPercent !== 100 || $this->growthBonus > 0) {
            $s['skill_gain_per_day'] = (float) ($s['skill_gain_per_day'] ?? 0) * $this->growthSpeedPercent / 100 + $this->growthBonus / 1000;
        }
        if ($this->activityPercent !== 100 && isset($s['activity'])) {
            $s['activity'] = array_map(fn($p): float => min(1.0, (float) $p * $this->activityPercent / 100), array_values((array) $s['activity']));
        }
        if (!$this->fleetsaveEnabled) {
            $s['fleetsave_chance'] = 0;
        }
        if ($this->raidsPerTargetDay > 0) {
            $s['max_attacks_per_target_per_day'] = $this->raidsPerTargetDay;
        }
        $s['hidden_rhythm'] = $userId > 0 && $this->hiddenRhythmPercent > 0 && crc32('rhythm:' . $userId) % 100 < $this->hiddenRhythmPercent ? $userId : 0;

        return $s;
    }

    /** Whether a bot with this many points is a warmonger (switch on and at or above `bots_warmonger_points`). */
    public function warmongerActive(float $points): bool
    {
        return $this->warmonger && $points >= $this->warmongerPoints;
    }

    /** Seconds a skipped, failed or unprofitable target is left alone. */
    public function targetCooldownSeconds(): int
    {
        return $this->targetCooldownHours * 3600;
    }

    /** Seconds before a target is spied on again outside a raid streak. */
    public function spyCooldownSeconds(): int
    {
        return $this->spyCooldownMinutes * 60;
    }

    /** Seconds before a target is spied on again inside a raid streak. */
    public function spyCooldownStreakSeconds(): int
    {
        return $this->spyCooldownStreakMinutes * 60;
    }

    /** Seconds all bots together leave a human target alone after one of them launched an attack on it. */
    public function humanCooldownSeconds(): int
    {
        return $this->humanCooldownHours * 3600;
    }

    /**
     * The stable personal hour offset of a bot against the server clock (its own time zone), -spread ... +spread, from its id.
     *
     * @param int $userId the bot's user id
     * @param int|null $stored an offset kept in the bot's personality (`_tz`), wins when present
     */
    public function timezoneOffset(int $userId, ?int $stored = null): int
    {
        $spread = $this->timezoneSpreadHours;
        if ($spread < 1) {
            return 0;
        }
        if ($stored !== null) {
            return max(-$spread, min($spread, $stored));
        }

        return (crc32('tz:' . $userId) % (2 * $spread + 1)) - $spread;
    }

    /** Seconds ahead the bot looks for incoming attacks. */
    public function threatWindowSeconds(): int
    {
        return $this->threatWindowHours * 3600;
    }

    /** Share of every stock a fleetsave leaves on the planet (0-1). */
    public function fleetsaveKeep(): float
    {
        return $this->fleetsaveKeepPercent / 100;
    }
}
