<?php

namespace App\Game\Bots;

use App\Game\Bots\Llm\LlmClientFactory;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Human-side identity of a bot: nickname, persona blurb, planet names.
 * Uses the LLM when enabled/available and always has a local fallback.
 * Also hosts the small shared helpers (LLM budget, text sanitising, logging) used by BotSocial.
 */
class BotPersona
{
    use GameUtilities;

    private const MAX_NICK = 20;

    private const BAD_NICK = '/bot|npc|gpt|claude|openai|assistant|(^|[^a-z])ai([^a-z]|$)/i';

    private const WORDS_DE = [
        'Schatten', 'Sternenstaub', 'Kaffee', 'Nachtfalke', 'Weltraumopa', 'Pixel', 'Donner', 'Wolf', 'Blitz',
        'Raumfahrer', 'Admiral', 'Kartoffel', 'Mondlicht', 'Kometenjaeger', 'Eisbaer', 'Feuerfalke', 'Tiefsee',
        'Sonnenwind', 'Stahlherz', 'Dunkelstern', 'Brummbaer', 'Sternschnuppe', 'Kapitaen', 'Nebel', 'Zocker',
        'Rakete', 'Galaxis', 'Atom', 'Kosmos', 'Waldschrat', 'Hering', 'Turbo', 'Nachteule', 'Funke', 'Eroberer',
    ];

    private const WORDS_EN = [
        'Starlord', 'Nova', 'Cosmic', 'Raven', 'Void', 'Orbit', 'Pilot', 'Rocket', 'Comet', 'Nebula', 'Falcon',
        'Shadow', 'Vortex', 'Ghost', 'Rogue', 'Titan', 'Eclipse', 'Quasar', 'Pixel', 'Drifter', 'Phantom',
        'Blaze', 'Frost', 'Hunter', 'Wanderer', 'Zenith', 'Cyclone', 'Viper', 'Echo', 'Maverick', 'Nomad',
        'Spark', 'Galaxy', 'Reaper', 'Stardust', 'Warp', 'Lunar', 'Solar', 'Iron', 'Thunder',
    ];

    private const FIRST = [
        'Tim', 'Jan', 'Max', 'Lukas', 'Jonas', 'Nico', 'Felix', 'Tom', 'Ben', 'Paul', 'Chris', 'Alex', 'Sven',
        'Dennis', 'Kevin', 'Marco', 'Dave', 'Mike', 'Steve', 'Ryan', 'Leon', 'Finn', 'Timo', 'Jens', 'Olli',
    ];

    private const TONES = ['friendly', 'casual', 'grumpy', 'enthusiastic', 'shy', 'nerdy', 'laconic', 'cheeky'];

    private const QUIRKS = [
        'de' => [
            'schreibt alles klein', 'benutzt oft "xD"', 'sagt gern "digga"', 'trinkt zu viel Kaffee',
            'redet viel ueber Deuterium', 'ist immer muede', 'faengt Saetze mit "also" an', 'liebt Spionagesonden',
            'jammert ueber Angriffe', 'benutzt kaum Satzzeichen', 'ist stolz auf seine Minen',
        ],
        'en' => [
            'writes all lowercase', 'uses "lol" a lot', 'says "mate" often', 'drinks too much coffee',
            'talks about deuterium constantly', 'always tired', 'starts sentences with "well"',
            'loves espionage probes', 'complains about attacks', 'barely uses punctuation', 'proud of his mines',
        ],
    ];

    private const BIOS = [
        'de' => [
            'Spiele nebenbei, wenn die Arbeit es zulaesst.', 'Baue lieber Minen als Flotten.',
            'Bin seit Jahren in solchen Spielen unterwegs.', 'Eher entspannt unterwegs, kein Stress.',
            'Zocke abends nach Feierabend ein bisschen.', 'Noch neu hier, lerne taeglich dazu.',
            'Wirtschaft first, Kampf spaeter.', 'Mag Weltraumspiele und gutes Essen.',
        ],
        'en' => [
            'Play on the side whenever work allows.', 'Prefer mines over fleets.',
            'Been into games like this for years.', 'Pretty relaxed, no stress.',
            'Playing a bit after work in the evening.', 'Still new here, learning every day.',
            'Economy first, fighting later.', 'Into space games and good food.',
        ],
    ];

    private const PLANETS_HOME = [
        'Heimatwelt', 'Homebase', 'Mothership', 'Hauptquartier', 'Home Sweet Home', 'Zuhause', 'Capital',
        'Basecamp', 'Terra Nova', 'Hauptwelt', 'Eden', 'Origin',
    ];

    private const PLANETS_COLONY = [
        'Kolonie', 'Outpost', 'New Hope', 'Aussenposten', 'Colony', 'Vorposten', 'Neuland', 'Farmplanet',
        'Mining Rock', 'Felsbrocken', 'Kartoffelacker', 'Dust Ball', 'Schrottplatz', 'Backup', 'Plan B',
        'Nebenwelt', 'Fort Knox', 'Waldmond', 'Eisklotz', 'Sandkasten', 'Last Stop', 'Nowhere',
    ];

    private ?float $llmDeadline = null;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly LlmClientFactory $llm,
        private readonly BotLog $botLog,
        private readonly BotNameGenerator $names,
    ) {}

    // ------------------------------------------------------------------------------------------------
    // public API
    // ------------------------------------------------------------------------------------------------

    /**
     * @param array<mixed> $profile
     * @return array{nickname:string, persona:array}
     */
    public function generate(int $universe, array $profile): array
    {
        $style = self::profileKey($profile);
        $language = $this->rollLanguage($universe);

        try {
            $result = $this->generateWithLlm($universe, $style, $language);
            if ($result !== null) {
                return $result;
            }
        } catch (Throwable $e) {
            $this->botLog->log(0, $universe, 'llm_error', 'persona: ' . $e->getMessage());
        }

        return [
            'nickname' => $this->localNickname($universe),
            'persona' => self::localPersona($style, $language),
        ];
    }

    /**
     * Name for a bot's home planet or colony: asks the LLM (when enabled, within the budget) and accepts the answer only if it
     * is a valid planet name (2-20 characters, no tags); otherwise a local name from a fixed list.
     *
     * @param array<string, mixed> $botRow  bot_players row (user_id, universe)
     * @param array<string, mixed> $persona decoded persona (tone, language, bio)
     */
    public function planetName(array $botRow, array $persona, bool $home): string
    {
        $universe = (int) ($botRow['universe'] ?? 1);
        $userId = (int) ($botRow['user_id'] ?? 0);

        try {
            $system = 'You name planets in a browser space strategy game. Answer with the name only.';
            $prompt = 'Invent one short planet name (1-3 words, max 18 characters, only letters, digits and spaces) for '
                . ($home ? 'the home planet' : 'a new colony') . ' of a player. Player style: '
                . ($persona['tone'] ?? 'casual') . ', language: ' . ($persona['language'] ?? 'en')
                . ', bio: ' . ($persona['bio'] ?? '') . '. Output only the name.';
            $text = $this->ask($universe, $userId, $system, $prompt, 30);
            if ($text !== null) {
                $name = trim(strtok(trim($text), "\n"), " \t\"'`*.");
                if ($this->validPlanetName($name)) {
                    return $name;
                }
                $this->botLog->log($userId, $universe, 'llm_error', 'invalid planet name from llm');
            }
        } catch (Throwable $e) {
            $this->botLog->log($userId, $universe, 'llm_error', 'planetName: ' . $e->getMessage());
        }

        return self::localPlanetName($home);
    }

    /** Renames a planet if the name is valid for the game (no-op otherwise). */
    public function renamePlanet(int $planetId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || !$this->playerRegistration()->isNameValid($name, true) || mb_strlen($name) > 32) {
            return;
        }

        $this->db->update(
            'UPDATE %%PLANETS%% SET name = :name WHERE id = :id;',
            [':name' => $name, ':id' => $planetId],
        );
    }

    // ------------------------------------------------------------------------------------------------
    // shared helpers (also used by BotSocial)
    // ------------------------------------------------------------------------------------------------

    /**
     * Wall-clock limit (microtime) after which ask() answers null without calling the provider; null = no limit.
     * Set by BotEngine::tick() so slow LLM calls cannot stretch a cron run.
     */
    public function limitLlmUntil(?float $deadline): void
    {
        $this->llmDeadline = $deadline;
    }

    /**
     * Asks the LLM if it is enabled, available and within the bot's daily budget. Returns null otherwise
     * (or on any error), so callers fall back to local text. $userId 0 = no budget accounting.
     */
    public function ask(int $universe, int $userId, string $system, string $prompt, int $maxTokens = 400): ?string
    {
        if ($this->llmDeadline !== null && microtime(true) > $this->llmDeadline) {
            return null; // the LLM time budget of this tick is used up: callers fall back to local text
        }

        try {
            if (!$this->configs->get($universe)->bots_llm_enabled) {
                return null;
            }
            $client = $this->llm->active();
            if ($client === null) {
                return null;
            }
            if ($userId > 0 && (!$this->profileAllowsLlm($userId) || !$this->takeBudget($universe, $userId))) {
                return null;
            }

            return trim($client->complete($system, $prompt, $maxTokens));
        } catch (Throwable $e) {
            $this->botLog->log($userId, $universe, 'llm_error', $e->getMessage());

            return null;
        }
    }

    /**
     * Whether the bot's profile lets it use the LLM (profile setting `llm_advice`, "Allow LLM (names and chat)", on unless switched off).
     * A bot without a profile row counts as allowed.
     */
    private function profileAllowsLlm(int $userId): bool
    {
        $json = $this->db->selectSingle(
            'SELECT p.settings FROM %%BOT_PLAYERS%% bp INNER JOIN %%BOT_PROFILES%% p ON p.id = bp.profile_id WHERE bp.user_id = :id;',
            [':id' => $userId],
            'settings',
        );
        $settings = is_string($json) ? json_decode($json, true) : null;

        return !is_array($settings) || ($settings['llm_advice'] ?? true) !== false;
    }

    /**
     * Counts one LLM call against the bot's daily budget (`bots_llm_daily_limit`, default and fallback 20 calls per
     * calendar day). Returns false when the bot is unknown or the budget is used up; the counter resets when the date changes.
     */
    private function takeBudget(int $universe, int $userId): bool
    {
        $db = $this->db;
        $limit = (int) ($this->configs->get($universe)->bots_llm_daily_limit ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $today = (int) date('Ymd');
        $row = $db->selectSingle(
            'SELECT llm_calls_day, llm_calls_date FROM %%BOT_PLAYERS%% WHERE user_id = :id;',
            [':id' => $userId],
        );
        if (empty($row)) {
            return false;
        }
        $used = ((int) $row['llm_calls_date'] === $today) ? (int) $row['llm_calls_day'] : 0;
        if ($used >= $limit) {
            return false;
        }
        $db->update(
            'UPDATE %%BOT_PLAYERS%% SET llm_calls_day = :n, llm_calls_date = :d WHERE user_id = :id;',
            [':n' => $used + 1, ':d' => $today, ':id' => $userId],
        );

        return true;
    }

    /**
     * Persona as array: arrays pass through, JSON strings are decoded, anything else gives [].
     *
     * @return array<mixed>
     */
    public static function decode(mixed $persona): array
    {
        if (is_array($persona)) {
            return $persona;
        }
        $data = json_decode((string) $persona, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Cleans plain text coming from the LLM or a template: no tags, no links, bounded length.
     * Returns RAW text (not HTML-escaped); use escapeForStorage() before saving.
     */
    public static function cleanText(string $text, int $maxLen): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = strip_tags($text);
        $text = preg_replace('~(https?://|www\.)\S+~i', '', $text);
        $text = preg_replace('/[*_`#]{2,}/', '', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text, " \n\t\"");
        if (!preg_match('/^./us', $text)) {
            return '';
        }

        return trim(mb_substr($text, 0, $maxLen));
    }

    /** True if the LLM answer looks like a refusal/leak instead of in-character text. */
    public static function looksBroken(string $text): bool
    {
        return $text === '' || preg_match('/\b(as an ai|language model|i cannot|i can\'t assist|als ki|sprachmodell|openai|anthropic)\b/i', $text) === 1;
    }

    /** Same treatment RequestInput::typed() gives user input: escape, trim; optionally newline to <br>. */
    public static function escapeForStorage(string $text, bool $br = true): string
    {
        $text = trim(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        return $br ? nl2br($text, false) : $text;
    }

    /** Swaps two adjacent inner letters in words of 4+ letters; each word is hit with probability `$rate * 2` (rate as fraction, e.g. 0.02). */
    public static function typos(string $text, float $rate): string
    {
        if ($rate <= 0) {
            return $text;
        }

        return preg_replace_callback('/\p{L}{4,}/u', function ($m) use ($rate) {
            if (mt_rand(0, 9999) / 10000 >= $rate * 2) {
                return $m[0];
            }
            $chars = preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY);
            $i = mt_rand(1, count($chars) - 2);
            [$chars[$i], $chars[$i + 1]] = [$chars[$i + 1], $chars[$i]];

            return implode('', $chars);
        }, $text);
    }

    /**
     * Normalized profile key (lowercase, spaces to underscores) from profile_key, name or key; defaults to 'office_worker'.
     *
     * @param array<mixed> $profile
     */
    public static function profileKey(array $profile): string
    {
        $key = (string) ($profile['profile_key'] ?? $profile['name'] ?? $profile['key'] ?? '');

        return $key === '' ? 'office_worker' : strtolower(str_replace(' ', '_', $key));
    }

    // ------------------------------------------------------------------------------------------------
    // generation internals
    // ------------------------------------------------------------------------------------------------

    /** Language of a new persona: the universe's default language (de or en) with 70 % probability, the other one otherwise. */
    private function rollLanguage(int $universe): string
    {
        $default = 'de';
        try {
            $cfg = (string) ($this->configs->get($universe)->lang ?? 'de');
            $default = in_array($cfg, ['de', 'en'], true) ? $cfg : 'de';
        } catch (Throwable $e) {
            // keep default
        }
        $other = $default === 'de' ? 'en' : 'de';

        return mt_rand(1, 100) <= 70 ? $default : $other;
    }

    /**
     * Asks the LLM for a nickname and persona (JSON object: nickname, bio, tone, quirks, age_hint), at most two attempts. Every
     * field is sanitized; fields that fail validation keep the local persona's value. Returns null when the LLM is
     * unavailable or no usable, still free nickname came back (the caller then generates locally).
     *
     * @return array{nickname: string, persona: array<string, mixed>}|null
     */
    private function generateWithLlm(int $universe, string $style, string $language): ?array
    {
        $system = 'You create fictional human players for a browser space strategy game. '
            . 'Answer with one strict JSON object only, no markdown.';
        $prompt = 'Create a player. Player type: ' . $style . '. Language: ' . ($language === 'de' ? 'German' : 'English')
            . '. JSON keys: "nickname" (3-16 chars, a believable gamer handle, letters/digits/space/_/./- only, '
            . 'must NOT contain "bot", "ai" or "npc"), "bio" (max 140 chars, first person, in the language), '
            . '"tone" (one word), "quirks" (array of 1-3 short strings), "age_hint" (e.g. "mid 20s"). '
            . 'Nickname must be original and different from common examples.';

        for ($try = 0; $try < 2; ++$try) {
            $text = $this->ask($universe, 0, $system, $prompt, 300);
            if ($text === null) {
                return null;
            }
            if (!preg_match('/\{.*\}/s', $text, $m)) {
                continue;
            }
            $data = json_decode($m[0], true);
            if (!is_array($data)) {
                continue;
            }
            $nick = trim((string) ($data['nickname'] ?? ''));
            if (!$this->nickAvailable($universe, $nick)) {
                continue;
            }
            $persona = self::localPersona($style, $language);
            $bio = self::cleanText((string) ($data['bio'] ?? ''), 140);
            if ($bio !== '' && !self::looksBroken($bio)) {
                $persona['bio'] = $bio;
            }
            $tone = preg_replace('/[^a-z\- ]/', '', strtolower(trim((string) ($data['tone'] ?? ''))));
            if ($tone !== '' && strlen($tone) <= 24) {
                $persona['tone'] = $tone;
            }
            if (!empty($data['quirks']) && is_array($data['quirks'])) {
                $quirks = [];
                foreach (array_slice($data['quirks'], 0, 3) as $q) {
                    $q = self::cleanText((string) $q, 60);
                    if ($q !== '') {
                        $quirks[] = $q;
                    }
                }
                if ($quirks) {
                    $persona['quirks'] = $quirks;
                }
            }
            $age = self::cleanText((string) ($data['age_hint'] ?? ''), 24);
            if ($age !== '') {
                $persona['age_hint'] = $age;
            }

            return ['nickname' => $nick, 'persona' => $persona];
        }

        return null;
    }

    /**
     * Whether a nickname is usable: 3-20 characters, no surrounding whitespace, valid game name, nothing that suggests a bot or AI
     * (BAD_NICK), and not taken as player name in the universe or as account name.
     */
    public function nickAvailable(int $universe, string $nick): bool
    {
        $len = mb_strlen($nick);
        if ($len < 3 || $len > self::MAX_NICK || $nick !== trim($nick)) {
            return false;
        }
        if (!$this->playerRegistration()->isNameValid($nick) || preg_match(self::BAD_NICK, $nick)) {
            return false;
        }
        $db = $this->db;
        $inUsers = $db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%USERS%% WHERE universe = :u AND username = :n;',
            [':u' => $universe, ':n' => $nick],
            'c',
        );
        $inAccounts = $db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%ACCOUNTS%% WHERE accountname = :n;',
            [':n' => $nick],
            'c',
        );

        return (int) $inUsers === 0 && (int) $inAccounts === 0;
    }

    /** Free nickname without the LLM: 20 tries with BotNameGenerator, 20 with random gamer handles, then "Pilot<number>". */
    private function localNickname(int $universe): string
    {
        for ($i = 0; $i < 40; ++$i) {
            $nick = $i < 20 ? $this->names->generate($universe) : self::randomHandle();
            if ($this->nickAvailable($universe, $nick)) {
                return $nick;
            }
        }

        return 'Pilot' . mt_rand(1000, 99999);
    }

    /** Random gamer handle (word combinations, first names, years, leetspeak), cut to MAX_NICK characters; not checked for availability. */
    private static function randomHandle(): string
    {
        $pick = static fn(array $a): string => $a[array_rand($a)];
        $words = mt_rand(0, 1) ? self::WORDS_DE : self::WORDS_EN;
        $w = $pick($words);
        $w2 = $pick(mt_rand(0, 1) ? self::WORDS_DE : self::WORDS_EN);
        $num = mt_rand(0, 2) === 0 ? (string) mt_rand(1, 99) : (string) mt_rand(1975, 2008);

        $nick = match (mt_rand(0, 9)) {
            0 => $w . $w2,
            1 => $w . $num,
            2 => strtolower($w) . '_' . strtolower($w2),
            3 => $pick(self::FIRST) . $num,
            4 => strtoupper($w),
            5 => 'xX' . $w . 'Xx',
            6 => $pick(self::FIRST) . '_' . $w,
            7 => $w . '.' . $pick(self::FIRST),
            8 => strtolower($w) . $num,
            default => $pick(self::FIRST) . $w,
        };

        if (mt_rand(0, 4) === 0) {
            $nick = strtr($nick, ['e' => '3', 'E' => '3', 'o' => '0', 'a' => '4', 'i' => '1', 'l' => '1']);
        }
        if (mb_strlen($nick) > self::MAX_NICK) {
            $nick = mb_substr($nick, 0, self::MAX_NICK);
        }

        return $nick;
    }

    /**
     * Persona without the LLM: tone, age hint and `typo_rate` (share of misspelled words, max 0.1) depend on the profile
     * style (noob types most typos), plus a random bio and one or two quirks in the given language.
     *
     * @return array{bio: string, tone: string, language: string, quirks: list<string>, age_hint: string, play_style: string, typo_rate: float}
     */
    private static function localPersona(string $style, string $language): array
    {
        $typo = match ($style) {
            'noob' => 0.06,
            'jobless' => 0.03,
            'shift_worker' => 0.025,
            'lunatic' => 0.01,
            default => 0.02,
        };
        $tone = match ($style) {
            'noob' => 'shy',
            'lunatic' => 'enthusiastic',
            'jobless' => 'casual',
            'office_worker' => 'laconic',
            default => self::TONES[array_rand(self::TONES)],
        };
        if (mt_rand(0, 3) === 0) {
            $tone = self::TONES[array_rand(self::TONES)];
        }
        $quirkPool = self::QUIRKS[$language];
        shuffle($quirkPool);
        $age = match ($style) {
            'noob' => ['teens', 'early 20s'][mt_rand(0, 1)],
            'jobless' => ['early 20s', 'mid 20s', 'late 20s'][mt_rand(0, 2)],
            'office_worker' => ['late 20s', '30s', 'mid 30s'][mt_rand(0, 2)],
            'shift_worker' => ['30s', '40s'][mt_rand(0, 1)],
            default => ['20s', '30s'][mt_rand(0, 1)],
        };

        return [
            'bio' => mb_substr(self::BIOS[$language][array_rand(self::BIOS[$language])], 0, 140),
            'tone' => $tone,
            'language' => $language,
            'quirks' => array_slice($quirkPool, 0, mt_rand(1, 2)),
            'age_hint' => $age,
            'play_style' => $style,
            'typo_rate' => min(0.1, max(0.0, $typo + mt_rand(-5, 10) / 1000)),
        ];
    }

    /** Planet name from the LLM: 2-20 characters, valid game name, no markup. */
    private function validPlanetName(string $name): bool
    {
        $len = mb_strlen($name);

        return $len >= 2 && $len <= 20 && $this->playerRegistration()->isNameValid($name, true) && strip_tags($name) === $name;
    }

    /** Planet name from the fixed lists; colonies sometimes get a number 2-9 appended; max 20 characters. */
    private static function localPlanetName(bool $home): string
    {
        $list = $home ? self::PLANETS_HOME : self::PLANETS_COLONY;
        $name = $list[array_rand($list)];
        if (!$home && mt_rand(0, 2) === 0) {
            $name .= ' ' . mt_rand(2, 9);
        }
        if (mb_strlen($name) > 20) {
            $name = mb_substr($name, 0, 20);
        }

        return $name;
    }
}
