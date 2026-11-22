<?php

namespace App\Game\Core;

/**
 * Language pack metadata (config/languages.yaml, language list cache); formerly static methods
 * of Language. Dependency-free, memoized per request.
 */
class LanguageCatalog
{
    /** @var array<mixed> */
    private static array $allLanguages = [];

    /**
     * Two-letter pack code for a browser language tag (pt-BR, zh_Hant_TW, fil, de-AT, ...): pack codes are two letters
     * because the lang columns are varchar(2), so Filipino is "tl", Brazilian Portuguese "br" and Traditional Chinese
     * (Taiwan, Hong Kong, Macau, Hant) "tw"; everything else is the primary subtag.
     */
    public static function packCodeForTag(string $tag): string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        [$code] = explode('-', $tag);

        return match (true) {
            $code === 'fil' => 'tl',
            $tag === 'pt-br' => 'br',
            $code === 'zh' && preg_match('/-(tw|hk|mo|hant)\b/', $tag) === 1 => 'tw',
            default => $code,
        };
    }

    /**
     * Languages the game offers.
     * @param bool $OnlyKey true: codes only; false: code => display name.
     * @return array<mixed>
     */
    public function getAllowedLangs(bool $OnlyKey = true): array
    {
        if (empty(self::$allLanguages)) {
            $cache	= new Cache(); // the language cache reads files only
            self::$allLanguages = $this->sorted((array) $cache->getData('language'));
        }

        if ($OnlyKey) {
            return array_keys(self::$allLanguages);
        }

        // LanguageBuildCache stores the full pack metadata; UI selectors need code => name.
        // Older caches may still hold plain name strings until rebuilt.
        $names = [];
        foreach (self::$allLanguages as $code => $meta) {
            $names[$code] = is_array($meta) ? ($meta['name'] ?? $code) : $meta;
        }

        return $names;
    }

    /**
     * Language selector options: real languages as code => name, the fun languages (pack flag `fun`) as an
     * option group under `$funLabel` at the end (rendered as <optgroup> by html_options and the start page).
     *
     * @return array<string, string|array<string, string>>
     */
    public function getLanguageOptions(string $funLabel): array
    {
        $options = [];
        $fun = [];
        foreach ($this->getAllowedLangs(false) as $code => $name) {
            if ($this->isFun($code)) {
                $fun[$code] = $name;
            } else {
                $options[$code] = $name;
            }
        }
        if ($fun !== []) {
            $options[$funLabel] = $fun;
        }

        return $options;
    }

    /** Whether the pack is a joke language (flag `fun` in config/languages.yaml). */
    public function isFun(string $code): bool
    {
        return !empty($this->getPackConfig($code)['fun']);
    }

    /**
     * Language list order: real languages by code, fun languages last.
     *
     * @param array<string, mixed> $languages code => metadata
     * @return array<string, mixed>
     */
    private function sorted(array $languages): array
    {
        uksort($languages, fn(string $a, string $b): int => [$this->isFun($a), $a] <=> [$this->isFun($b), $b]);

        return $languages;
    }

    /**
     * All selectable language packs, locale => metadata (config/languages.yaml).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPacks(): array
    {
        static $packs = null;

        if ($packs === null) {
            $root = \App\Runtime\ProjectPaths::ROOT;
            $packs = \Symfony\Component\Yaml\Yaml::parseFile($root . 'config/languages.yaml');
        }

        return $packs;
    }

    /**
     * Metadata of a language pack (suffixes, profile, …) from config/languages.yaml,
     * read directly (memoized per request) so number formatting does not depend on the
     * language list cache being rebuilt. LanguageBuildCache stores the same metadata
     * for the allowed-lang list.
     *
     * @return array<string, mixed>
     */
    public function getPackConfig(?string $lang = null): array
    {
        static $packMemo = [];

        $lang = strtolower(trim((string) $lang));
        if ($lang === '') {
            $lang = DEFAULT_LANG;
        }

        if (array_key_exists($lang, $packMemo)) {
            return $packMemo[$lang];
        }

        $packs = $this->getPacks();
        $packMemo[$lang] = $packs[$lang] ?? [];

        return $packMemo[$lang];
    }
}
