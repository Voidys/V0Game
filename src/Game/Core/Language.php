<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use ArrayAccess;
use Exception;

/**
 * Texts of one language: array access to the translations ($LNG['key'], nested for tech names etc.), loaded
 * per domain from the cached language files (includeData(): English first as fallback, then the player's
 * language), with format()/formatHtml() for printf-style texts. The language comes from the request (?lang, cookie,
 * Accept-Language) or from the universe default; "{game_name}" in texts is replaced by the configured game name.
 */
class Language implements ArrayAccess
{
    /** @var array<mixed> */
    private array $container = [];
    private string $language = '';
    /** texts were added while the config was still loading: "{game_name}" is filled in on first read */
    private bool $gameNamePending = false;
    /** @var string[] domains whose packs are read on first use (includeDataLazily) */
    private array $pendingFiles = [];

    public function __construct(
        private ConfigRegistry $configs,
        ?string $language = null,
    ) {
        $this->setLanguage($language);
        if ($language === null) {
            $this->getUserAgentLanguage();
        }
    }

    /**
     * Determines the language of the visitor: ?lang= parameter (also stored in the `lang` cookie), the cookie in
     * login/install modes, else the best match of the Accept-Language header with the allowed languages (default
     * language when none); stores the choice in the cookie.
     */
    private function getUserAgentLanguage(): void
    {
        if (isset($this->configs->gameState()->requestInput['lang']) && in_array($this->configs->gameState()->requestInput['lang'], $this->catalog()->getAllowedLangs())) {
            (new HTTP($this->configs->gameState()))->sendCookie('lang', $this->configs->gameState()->requestInput['lang'], 2147483647);
            $this->setLanguage($this->configs->gameState()->requestInput['lang']);
            return;
        }

        if (($this->configs->gameState()->runtime->mode === 'LOGIN' || $this->configs->gameState()->runtime->mode === 'INSTALL' || $this->configs->gameState()->runtime->mode === 'UPGRADE') && isset($this->configs->gameState()->requestCookies['lang']) && in_array($this->configs->gameState()->requestCookies['lang'], $this->catalog()->getAllowedLangs())) {
            $this->setLanguage($this->configs->gameState()->requestCookies['lang']);
            return;
        }

        if (empty($this->configs->gameState()->requestServer['HTTP_ACCEPT_LANGUAGE'])) {
            $this->setLanguage(DEFAULT_LANG);
            return;
        }

        $accepted_languages = preg_split('/,\s*/', $this->configs->gameState()->requestServer['HTTP_ACCEPT_LANGUAGE']);

        $language = $this->getLanguage();

        foreach ($accepted_languages as $accepted_language) {
            $isValid = preg_match('!^([a-z]{1,8}(?:-[a-z]{1,8})*)(?:;\s*q=(0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?$!i', $accepted_language, $matches);

            if ($isValid !== 1) {
                continue;
            }

            $code = LanguageCatalog::packCodeForTag($matches[1]);

            if (in_array($code, $this->catalog()->getAllowedLangs())) {
                $language	= $code;
                break;
            }
        }

        (new HTTP($this->configs->gameState()))->sendCookie('lang', $language, 2147483647);
        $this->setLanguage($language);
    }

    /**
     * Selects the language if allowed; otherwise the universe's default language (or DEFAULT_LANG during install).
     */
    public function setLanguage(?string $language): void
    {
        if (!is_null($language) && in_array($language, $this->catalog()->getAllowedLangs())) {
            $this->language = $language;
        } elseif ($this->configs->gameState()->runtime->mode !== 'INSTALL') {
            $this->language	= $this->configs->get()->lang;
        } else {
            $this->language	= DEFAULT_LANG;
        }
    }

    /** Like includeData(), but the packs are read on the first access: a request that prints no text never reads them. @param string[] $files */
    public function includeDataLazily(array $files): void
    {
        $this->pendingFiles = array_merge($this->pendingFiles, $files);
    }

    private function settle(): void
    {
        if ($this->pendingFiles !== []) {
            $files = $this->pendingFiles;
            $this->pendingFiles = [];
            $this->includeData($files);
        }
    }

    /**
     * Merges text arrays into the loaded texts (later data overrides earlier).
     *
     * @param array<string, mixed> $data
     */
    public function addData(array $data): void
    {
        $this->settle();
        $this->container = array_replace_recursive($this->container, $data);
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Contents of a text template templates/texts/<lang>/<name>.txt (e-mail and rule texts); a visible error text when missing.
     */
    public function getTemplate(string $templateName): string
    {
        if (file_exists('templates/texts/' . $this->getLanguage() . '/' . $templateName . '.txt')) {
            return $this->withCommunityLinks(file_get_contents('templates/texts/' . $this->getLanguage() . '/' . $templateName . '.txt'));
        } else {
            return '### Template "' . $templateName . '" on language "' . $this->getLanguage() . '" not found! ###';
        }
    }


    /**
     * Loads the language domains (e.g. 'L18N', 'INGAME', 'TECH') from the cache files: English as fallback, then the
     * current language over it. Rebuilds the language cache once if an English file is missing.
     * @param string[] $files domain names in upper case.
     */
    public function includeData(array $files): void
    {
        // Fixed BOM problems.
        ob_start();

        //FALLBACK
        $path = $this->configs->gameState()->runtime->cachePath . 'languages/';

        // A pack file that is missing although its translations exist (cache cleared or older than a new
        // domain) would leave raw keys in messages written by missions: rebuild the packs once.
        foreach ($files as $file) {
            if (
                \App\Game\Cache\Resource\PhpArrayFile::read($path . 'en-' . $file) === null
                && file_exists(\App\Runtime\ProjectPaths::ROOT . 'translations/' . strtolower($file) . '.en.yaml')
            ) {
                (new \App\Game\Cache\Builder\LanguageBuildCache())->buildCache();
                break;
            }
        }

        foreach ($files as $file) {
            $data = \App\Game\Cache\Resource\PhpArrayFile::read($path . 'en-' . $file);
            if ($data !== null) {
                try {
                    $this->addData($this->withGameName($data));
                } catch (Exception $e) {
                    error_log("YAML-Fehler: " . $e->getMessage());
                }
            }
        }

        // Get current client language
        foreach ($files as $file) {
            $data = \App\Game\Cache\Resource\PhpArrayFile::read($path . $this->getLanguage() . '-' . $file);
            if ($data !== null) {
                try {
                    $this->addData($this->withGameName($data));
                } catch (Exception $e) {
                    error_log("YAML-Fehler: " . $e->getMessage());
                }
            }
        }

        ob_end_clean();
    }

    /** community tokens of a text template ({wiki_url}, ...) filled from the config; mail texts also lose their Discord sentence when none is set */
    private function withCommunityLinks(string $text): string
    {
        if (!$this->configs->isLoaded()) {
            return $text;
        }
        $urls = $this->communityUrls();

        return CommunityLinks::fill(CommunityLinks::prepareMail($text, $urls['discord_url']), $urls);
    }

    /**
     * The configured community links by config key (empty string = not set).
     *
     * @return array<string, string>
     */
    private function communityUrls(): array
    {
        $config = $this->configs->get();
        $urls = [];
        foreach (CommunityLinks::KEYS as $key) {
            $urls[$key] = (string) ($config->$key ?? '');
        }

        return $urls;
    }

    /**
     * texts name the game by "{game_name}" and the community links by tokens, both filled in from the config
     *
     * @param array<string, mixed> $data
     * @return array<mixed>
     */
    private function withGameName(array $data): array
    {
        // the config schema loads texts while the config is built: asking for the config here would recurse
        if (!$this->configs->isLoaded()) {
            $this->gameNamePending = true;

            return $data;
        }

        try {
            $gameName = (string) $this->configs->get()->game_name;
        } catch (\Throwable) {
            return $data;
        }

        try {
            $urls = $this->communityUrls();
        } catch (\Throwable) {
            $urls = [];
        }

        $replace = static function (array $items) use (&$replace, $gameName, $urls): array {
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $items[$key] = $replace($value);
                } elseif (is_string($value)) {
                    if (str_contains($value, '{game_name}')) {
                        $value = str_replace('{game_name}', $gameName, $value);
                    }
                    $items[$key] = CommunityLinks::fill($value, $urls);
                }
            }

            return $items;
        };

        return $replace($data);
    }

    /** ArrayAccess Functions **/
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->settle();
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }
    /**
     * ArrayAccess: true if the text key is loaded.
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        $this->settle();
        return isset($this->container[$offset]);
    }
    /**
     * ArrayAccess: removes a loaded text key.
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->settle();
        unset($this->container[$offset]);
    }
    /**
     * ArrayAccess: the text for the key. A missing key returns the key itself (never null), so `|default` does not apply (see CLAUDE.md).
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $this->settle();
        if ($this->gameNamePending && $this->configs->isLoaded()) {
            $this->gameNamePending = false;
            $this->container = $this->withGameName($this->container);
        }

        return isset($this->container[$offset]) ? $this->container[$offset] : $offset;
    }

    /**
     * When true a text whose placeholder count differs from the number of given arguments throws a
     * LogicException (debug and tests); when false the mismatch is logged and the text is still
     * produced (missing arguments print empty, extra ones are dropped). Set at boot from kernel.debug.
     */
    public static bool $strictFormat = false;

    /**
     * Fills a translation with values (printf placeholders: %s, %d, %1$s, %%) and returns the text as is.
     *
     * Use this instead of sprintf($LNG['key'], ...). An unknown key stays the key text (like $LNG['key']),
     * a placeholder/argument count mismatch is reported (see $strictFormat). The arguments are NOT escaped:
     * pass values that are already HTML-safe, or plain text for a plain-text target (mail subject, JSON
     * message that the client prints as text). For HTML output with raw values use formatHtml().
     */
    public function format(string $key, mixed ...$args): string
    {
        return $this->fill($key, $this->text($key), $args);
    }

    /**
     * Like format(), for a nested entry such as $LNG['mail']['subject']['mail_changed']:
     * formatIn(['mail', 'subject', 'mail_changed'], $gameName).
     *
     * @param list<string|int> $path
     */
    public function formatIn(array $path, mixed ...$args): string
    {
        $label = implode('.', $path);
        $node = $this[$path[0]];
        foreach (array_slice($path, 1) as $step) {
            $node = is_array($node) ? ($node[$step] ?? null) : null;
        }

        return is_string($node) ? $this->fill($label, $node, $args) : $label;
    }

    /**
     * format() for HTML text: the arguments are HTML-escaped without double encoding (existing entities
     * stay, so already encoded request input and stored names are not changed) unless they are
     * Twig\Markup (trusted markup, e.g. a link built by the game). Numbers pass unchanged.
     * Use whenever a player-controlled value (name, tag, subject, planet name) goes into a text that is
     * printed or stored as HTML.
     */
    public function formatHtml(string $key, mixed ...$args): string
    {
        return $this->fill($key, $this->text($key), array_map(self::escapeArgument(...), $args));
    }

    /** HTML-escapes one formatHtml() argument (Markup and numbers are left alone) */
    private static function escapeArgument(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \Twig\Markup => (string) $value,
            is_string($value), $value instanceof \Stringable => \App\Twig\GameEscaping::escape((string) $value, 'UTF-8'),
            default => $value,
        };
    }

    /** the text of a top-level key; an unknown key gives the key itself */
    private function text(string $key): string
    {
        $text = $this[$key];

        return is_string($text) ? $text : throw new \UnexpectedValueException(sprintf('Language entry "%s" is not a text.', $key));
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private function fill(string $key, string $text, array $args): string
    {
        if ($text === $key) {
            return $text; // missing translation: the key stays visible
        }
        $expected = self::placeholderCount($text);
        if ($expected !== count($args)) {
            $message = sprintf('Text "%s" (%s) has %d placeholder(s) but got %d argument(s).', $key, $this->language, $expected, count($args));
            if (self::$strictFormat) {
                throw new \LogicException($message);
            }
            error_log($message);
            $args = array_slice(array_pad($args, $expected, ''), 0, $expected);
        }

        return sprintf($text, ...$args);
    }

    /**
     * The conversions of a printf format as argument position => type letter: "%s of %d" gives
     * [1 => 's', 2 => 'd'], "%2$s %1$s" gives [1 => 's', 2 => 's']. "%%" is no placeholder.
     *
     * @return array<int, string>
     */
    public static function placeholders(string $format): array
    {
        preg_match_all('/%(?:(\d+)\$)?(?:[-+ 0]|\'.)*\d*(?:\.\d+)?([bcdeEfFgGosuxX%])/', $format, $matches, PREG_SET_ORDER);
        $found = [];
        $next = 1;
        foreach ($matches as [, $position, $type]) {
            if ($type === '%') {
                continue;
            }
            $found[$position !== '' ? (int) $position : $next++] = $type;
        }
        ksort($found);

        return $found;
    }

    /** number of arguments a printf format needs (highest position used) */
    public static function placeholderCount(string $format): int
    {
        $found = self::placeholders($format);

        return $found === [] ? 0 : max(array_keys($found));
    }

    /**
     * selectable languages (code list, or code => name); see LanguageCatalog
     *
     * @return array<mixed>
     */
    public function getAllowedLangs(bool $OnlyKey = true): array
    {
        return $this->catalog()->getAllowedLangs($OnlyKey);
    }

    /**
     * Language selector options with the fun languages grouped at the end (see LanguageCatalog::getLanguageOptions()).
     *
     * @return array<mixed>
     */
    public function getLanguageOptions(): array
    {
        return $this->catalog()->getLanguageOptions((string) $this['lang_group_fun']);
    }

    private function catalog(): LanguageCatalog
    {
        return new LanguageCatalog();
    }
}
