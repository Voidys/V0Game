<?php

declare(strict_types=1);

namespace App\Game\Engagement\Relics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * The relic catalog of a universe: slots with their possible main stats, substat maxima, rarities (weight, substat
 * count, roll band, dust, reforge costs), sets with their 2 and 4 piece bonuses and the caps per stat. The defaults are
 * code (defaults()); an admin can store a changed catalog per universe as JSON in eng_relic_config. config() returns the
 * effective catalog (the stored JSON sanitised against the defaults, so a damaged or hand-edited JSON can never break the
 * game); save() validates and clamps. All values are percent points. The scalar settings (drop chance, inventory size,
 * cooldown, power percent, pity, extra sources) live in the universe settings category relics.
 */
final class RelicCatalog
{
    public const SLOTS = [1 => 'core', 2 => 'lattice', 3 => 'assembler', 4 => 'vault'];
    public const RARITIES = [0 => 'common', 1 => 'uncommon', 2 => 'rare', 3 => 'epic'];

    public const METAL = 'metal';
    public const CRYSTAL = 'crystal';
    public const DEUTERIUM = 'deuterium';
    public const ALL = 'all';
    public const ENERGY = 'energy';
    public const FUSION = 'fusion';
    public const BUILD_TIME = 'build_time';
    public const SHIPYARD_TIME = 'shipyard_time';
    public const RESEARCH_TIME = 'research_time';
    public const STORAGE = 'storage';
    public const FUEL = 'fuel';
    public const EXPEDITION = 'expedition';

    /** every stat a relic can carry */
    public const STATS = [
        self::METAL, self::CRYSTAL, self::DEUTERIUM, self::ALL, self::ENERGY, self::FUSION,
        self::BUILD_TIME, self::SHIPYARD_TIME, self::RESEARCH_TIME, self::STORAGE, self::FUEL, self::EXPEDITION,
    ];

    /** stats that reduce something (shown with a minus sign) */
    public const REDUCTIONS = [self::FUSION, self::BUILD_TIME, self::SHIPYARD_TIME, self::RESEARCH_TIME, self::FUEL];

    public const MAX_VALUE = 100.0;
    public const MAX_SETS = 12;
    public const MAX_BONUS_LINES = 6;

    /** @var array<int, array<string, mixed>> effective catalog per universe, memoised for the process */
    private static array $effective = [];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** Whether the admin switched the relics on for the universe (no query). */
    public function enabled(int $universe): bool
    {
        return (bool) ($this->configs->get($universe)->relics_enabled ?? false);
    }

    /** Forgets the memoised catalogs (after a save or reset). */
    public static function flush(): void
    {
        self::$effective = [];
    }

    /**
     * The catalog that ships with the code.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $rarity = static fn(int $weight, int $subs, int $min, int $max, int $dust, int $reforgeDust, float $factor): array => [
            'weight' => $weight, 'subs' => $subs, 'min' => $min, 'max' => $max,
            'dust' => $dust, 'reforge_dust' => $reforgeDust, 'reforge_factor' => $factor,
        ];
        $set = static fn(string $code, array $b2, array $b4): array => [
            'enabled' => true, 'name' => 'rl_set_' . $code,
            'b2' => array_map(static fn(array $row): array => ['stat' => $row[0], 'value' => $row[1]], $b2),
            'b4' => array_map(static fn(array $row): array => ['stat' => $row[0], 'value' => $row[1]], $b4),
        ];
        $sub = static fn(float $max): array => ['max' => $max, 'slots' => [1, 2, 3, 4]];

        return [
            'slots' => [
                1 => [self::METAL => 4.0, self::CRYSTAL => 4.0, self::DEUTERIUM => 4.0, self::ALL => 2.0],
                2 => [self::ENERGY => 6.0, self::FUSION => 8.0, self::ALL => 1.5],
                3 => [self::BUILD_TIME => 2.0, self::SHIPYARD_TIME => 2.0, self::RESEARCH_TIME => 2.0],
                4 => [self::STORAGE => 10.0, self::FUEL => 3.0, self::EXPEDITION => 3.0],
            ],
            'subs' => [
                self::METAL => $sub(1.0), self::CRYSTAL => $sub(1.0), self::DEUTERIUM => $sub(1.0), self::ALL => $sub(0.5),
                self::ENERGY => $sub(1.5), self::FUSION => $sub(0.0),
                self::BUILD_TIME => $sub(1.0), self::SHIPYARD_TIME => $sub(1.0), self::RESEARCH_TIME => $sub(1.0),
                self::STORAGE => $sub(3.0), self::FUEL => $sub(1.0), self::EXPEDITION => $sub(1.0),
            ],
            'rarities' => [
                0 => $rarity(62, 1, 25, 40, 1, 5, 1.0),
                1 => $rarity(27, 2, 40, 60, 3, 10, 2.0),
                2 => $rarity(9, 3, 60, 80, 9, 20, 4.0),
                3 => $rarity(2, 4, 80, 100, 27, 40, 8.0),
            ],
            'reforge_base' => ['metal' => 5000, 'crystal' => 2500, 'deuterium' => 1000],
            'sets' => [
                'deepcore' => $set('deepcore', [[self::METAL, 1.5]], [[self::METAL, 3.0], [self::CRYSTAL, 1.0]]),
                'prism' => $set('prism', [[self::CRYSTAL, 1.5]], [[self::CRYSTAL, 3.0], [self::DEUTERIUM, 1.0]]),
                'leviathan' => $set('leviathan', [[self::DEUTERIUM, 1.5]], [[self::DEUTERIUM, 3.0], [self::FUSION, 5.0]]),
                'harmonic' => $set('harmonic', [[self::ENERGY, 3.0]], [[self::ALL, 1.5]]),
                'architect' => $set('architect', [[self::BUILD_TIME, 1.0]], [[self::BUILD_TIME, 2.5], [self::SHIPYARD_TIME, 2.5]]),
                'wanderer' => $set('wanderer', [[self::FUEL, 2.0]], [[self::EXPEDITION, 3.0]]),
            ],
            'caps' => [
                self::METAL => 12.0, self::CRYSTAL => 12.0, self::DEUTERIUM => 12.0, self::ALL => 5.0, self::ENERGY => 10.0,
                self::FUSION => 10.0, self::BUILD_TIME => 5.0, self::SHIPYARD_TIME => 5.0, self::RESEARCH_TIME => 5.0,
                self::STORAGE => 15.0, self::FUEL => 5.0, self::EXPEDITION => 5.0,
            ],
        ];
    }

    /**
     * Validates and clamps a catalog (form post or JSON). A section or entry that is missing falls back to the default,
     * one that is present is taken as given (an empty main stat list disables the slot). Numbers are clamped to 0 - 100
     * (counts and costs to their own bounds); unknown stats are dropped.
     *
     * @param array<mixed> $raw
     * @return array{0: array<string, mixed>, 1: list<string>} the complete catalog and the error keys (translations rl_err_*); a catalog with errors must not be saved
     */
    public static function sanitize(array $raw): array
    {
        $defaults = self::defaults();
        $errors = [];
        $config = [
            'slots' => self::sanitizeSlots($raw['slots'] ?? null, $defaults['slots']),
            'subs' => self::sanitizeSubs($raw['subs'] ?? null, $defaults['subs']),
            'rarities' => self::sanitizeRarities($raw['rarities'] ?? null, $defaults['rarities'], $errors),
            'reforge_base' => [],
            'sets' => self::sanitizeSets($raw['sets'] ?? null, $defaults['sets'], $errors),
            'caps' => [],
        ];
        foreach (['metal', 'crystal', 'deuterium'] as $key) {
            $config['reforge_base'][$key] = (int) self::num($raw['reforge_base'][$key] ?? null, 0, 1_000_000_000, $defaults['reforge_base'][$key]);
        }
        foreach (self::STATS as $stat) {
            $config['caps'][$stat] = self::num($raw['caps'][$stat] ?? null, 0, self::MAX_VALUE, $defaults['caps'][$stat]);
        }
        if (array_sum(array_map('count', $config['slots'])) === 0) {
            $errors[] = 'rl_err_slots';
        }

        return [$config, array_values(array_unique($errors))];
    }

    /**
     * @param array<int, array<string, float>> $defaults
     * @return array<int, array<string, float>> slot => stat => maximum of the main stat
     */
    private static function sanitizeSlots(mixed $raw, array $defaults): array
    {
        $out = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            $src = is_array($raw) ? ($raw[$slot] ?? null) : null;
            if (!is_array($src)) {
                $out[$slot] = $defaults[$slot];
                continue;
            }
            $mains = [];
            foreach ($src as $stat => $max) {
                $value = self::num($max, 0, self::MAX_VALUE, 0);
                if (in_array((string) $stat, self::STATS, true) && $value > 0) {
                    $mains[(string) $stat] = $value;
                }
            }
            $out[$slot] = $mains;
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $defaults
     * @return array<string, array{max: float, slots: list<int>}> stat => substat maximum (0 = never a substat) and the slots that may roll it
     */
    private static function sanitizeSubs(mixed $raw, array $defaults): array
    {
        $out = [];
        foreach (self::STATS as $stat) {
            $src = is_array($raw) ? ($raw[$stat] ?? null) : null;
            if (!is_array($src)) {
                $out[$stat] = $defaults[$stat];
                continue;
            }
            $slots = [];
            foreach ((array) ($src['slots'] ?? [1, 2, 3, 4]) as $slot) {
                if (isset(self::SLOTS[(int) $slot])) {
                    $slots[(int) $slot] = (int) $slot;
                }
            }
            $out[$stat] = ['max' => self::num($src['max'] ?? null, 0, self::MAX_VALUE, 0), 'slots' => array_values($slots)];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $defaults
     * @param list<string> $errors extended
     * @return array<int, array<string, mixed>>
     */
    private static function sanitizeRarities(mixed $raw, array $defaults, array &$errors): array
    {
        $out = [];
        foreach (array_keys(self::RARITIES) as $rarity) {
            $src = is_array($raw) ? ($raw[$rarity] ?? null) : null;
            $src = is_array($src) ? $src : [];
            $d = $defaults[$rarity];
            $min = (int) self::num($src['min'] ?? null, 0, 100, $d['min']);
            $max = (int) self::num($src['max'] ?? null, 0, 100, $d['max']);
            if ($min > $max) {
                [$min, $max] = [$max, $min];
                $errors[] = 'rl_err_band';
            }
            $out[$rarity] = [
                'weight' => (int) self::num($src['weight'] ?? null, 0, 10000, $d['weight']),
                'subs' => (int) self::num($src['subs'] ?? null, 0, 6, $d['subs']),
                'min' => $min,
                'max' => $max,
                'dust' => (int) self::num($src['dust'] ?? null, 0, 100000, $d['dust']),
                'reforge_dust' => (int) self::num($src['reforge_dust'] ?? null, 0, 100000, $d['reforge_dust']),
                'reforge_factor' => self::num($src['reforge_factor'] ?? null, 0, 1000, $d['reforge_factor']),
            ];
        }
        if (array_sum(array_column($out, 'weight')) <= 0) {
            $errors[] = 'rl_err_weights';
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $defaults
     * @param list<string> $errors extended
     * @return array<string, array<string, mixed>> set code => enabled, name key, b2 and b4 bonus lists
     */
    private static function sanitizeSets(mixed $raw, array $defaults, array &$errors): array
    {
        if (!is_array($raw)) {
            return $defaults;
        }
        $out = [];
        foreach ($raw as $code => $src) {
            $code = (string) $code;
            if (!is_array($src)) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,23}$/', $code) || count($out) >= self::MAX_SETS) {
                $errors[] = 'rl_err_code';
                continue;
            }
            $name = (string) ($src['name'] ?? '');
            $out[$code] = [
                'enabled' => self::flag($src['enabled'] ?? false),
                'name' => preg_match('/^[a-z0-9_]{1,48}$/', $name) ? $name : 'rl_set_' . $code,
                'b2' => self::sanitizeBonus($src['b2'] ?? []),
                'b4' => self::sanitizeBonus($src['b4'] ?? []),
            ];
        }
        $enabled = array_filter($out, static fn(array $set): bool => $set['enabled']);
        if ($enabled === []) {
            $errors[] = 'rl_err_sets';
        }

        return $out;
    }

    /**
     * @return list<array{stat: string, value: float}>
     */
    private static function sanitizeBonus(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row) || !in_array((string) ($row['stat'] ?? ''), self::STATS, true)) {
                continue;
            }
            $value = self::num($row['value'] ?? null, 0, self::MAX_VALUE, 0);
            if ($value > 0 && count($out) < self::MAX_BONUS_LINES) {
                $out[] = ['stat' => (string) $row['stat'], 'value' => $value];
            }
        }

        return $out;
    }

    /** Number clamped to [$min, $max], 2 decimals; a non-numeric value gives $fallback. */
    private static function num(mixed $value, float $min, float $max, float $fallback): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return $fallback;
        }

        return round(min($max, max($min, (float) $value)), 2);
    }

    /** Checkbox value of a posted or decoded form: true, 1, '1', 'on' or 'true'. */
    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true';
    }

    /**
     * The effective catalog of the universe: the stored JSON sanitised against the defaults (the defaults when nothing is stored).
     *
     * @return array<string, mixed>
     */
    public function config(int $universe): array
    {
        if (!isset(self::$effective[$universe])) {
            $json = $this->stored($universe);
            $raw = $json === null ? [] : json_decode($json, true);
            self::$effective[$universe] = self::sanitize(is_array($raw) ? $raw : [])[0];
        }

        return self::$effective[$universe];
    }

    /** The stored catalog JSON of the universe, null when the universe uses the defaults. */
    public function stored(int $universe): ?string
    {
        $json = $this->db->selectSingle('SELECT `json` FROM %%ENG_RELIC_CONFIG%% WHERE `universe` = :uni;', [':uni' => $universe], 'json');

        return is_string($json) && $json !== '' ? $json : null;
    }

    /**
     * Validates and stores a catalog for the universe.
     *
     * @param array<mixed> $raw
     * @return list<string> error keys; empty when saved
     */
    public function save(int $universe, array $raw, int $now): array
    {
        [$config, $errors] = self::sanitize($raw);
        if ($errors !== []) {
            return $errors;
        }
        $this->db->replace(
            'REPLACE INTO %%ENG_RELIC_CONFIG%% SET `universe` = :uni, `json` = :json, `updated_at` = :now;',
            [':uni' => $universe, ':json' => json_encode($config, JSON_THROW_ON_ERROR), ':now' => $now],
        );
        self::flush();

        return [];
    }

    /** Deletes the stored catalog: the universe uses the defaults again. */
    public function reset(int $universe): void
    {
        $this->db->delete('DELETE FROM %%ENG_RELIC_CONFIG%% WHERE `universe` = :uni;', [':uni' => $universe]);
        self::flush();
    }

    /** "+3.25 %" for a gain, "-0.37 %" for a reduction (plain text, 2 decimals). */
    public static function formatValue(string $stat, float $value): string
    {
        return (in_array($stat, self::REDUCTIONS, true) ? '-' : '+') . number_format($value, 2, '.', '') . ' %';
    }

    /**
     * Display name of a set: the translation of its configured name key (the key itself when untranslated).
     *
     * @param array<string, mixed> $config effective catalog
     */
    public static function setLabel(\ArrayAccess $LNG, array $config, string $code): string
    {
        return (string) $LNG[$config['sets'][$code]['name'] ?? 'rl_set_' . $code];
    }

    /** Scalar setting of the relics category with a default. */
    public function setting(int $universe, string $key, int|bool $default): int|bool
    {
        $value = $this->configs->get($universe)->$key ?? $default;

        return is_bool($default) ? (bool) $value : (int) $value;
    }
}
