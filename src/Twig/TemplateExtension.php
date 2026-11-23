<?php

namespace App\Twig;

use App\Game\Core\LanguageCatalog;
use App\Game\Core\Singleton;
use App\Game\Core\ThemeAssets;
use App\Game\Core\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Functions, filters and globals of the game area templates (templates/{game,login,account,admin,install}/).
 *
 * - `LNG`: the language strings of the current request (App\Twig\LanguageView, trusted markup).
 * - Game view helpers (pretty_number, shortly_number, isModuleAvailable) and a small set
 *   of PHP functions (in_array, ...), as the templates
 *   were written against them.
 * - Markup helpers (icon, html_options) and the escape-aware `format`/`nl2br`. Their output
 *   is safe for every escaping strategy (`is_safe => ['all']`), see App\Twig\GameEscaping.
 */
final class TemplateExtension extends AbstractExtension implements GlobalsInterface
{
    /** PHP functions available under their own name, as function and as filter. */
    private const PASSTHROUGH = [
        'pretty_fly_time', 'getRestTimeFormat', 'array_key_exists', 'array_filter', 'array_sum', 'intval',
        'in_array', 'is_numeric', 'strip_tags', 'ceil',
    ];

    public function __construct(
        private readonly ViewHelpers $viewHelpers,
        private readonly Singleton $state,
    ) {}

    /** @return array{LNG: LanguageView} the translation view of the current request */
    public function getGlobals(): array
    {
        return ['LNG' => LanguageView::of($this->state)];
    }

    /** URL of a theme image (own folder, artwork sets, common), see App\Game\Core\ThemeAssets */
    public function themeAsset(string $path, ?string $theme = null): string
    {
        $theme ??= isset($this->state->THEME) ? $this->state->THEME->getImageThemeName() : DEFAULT_THEME;

        return ThemeAssets::resolve($theme, $path);
    }

    /**
     * `srcset` of a theme WebP image URL (from theme_asset): the width variants next to it (`x-64w.webp`, written by
     * `bin/console app:images:variants`) plus the original; null when there is none (other formats, no variants).
     */
    public function themeSrcset(string $url): ?string
    {
        if (!str_ends_with($url, '.webp') || !str_starts_with($url, './')) {
            return null;
        }
        $file = \App\Runtime\ProjectPaths::ROOT . 'public/' . substr($url, 2);
        $info = \App\Runtime\SafeFile::imageSize($file);

        return $info === false ? null : \App\Content\ImageProcessor::srcset($file, $url, (int) $info[0]);
    }

    /** BCP 47 tag of a language pack for <html lang> (pack `tag`, e.g. br => pt-BR, tw => zh-Hant-TW), else the pack code */
    public function htmlLang(?string $lang): string
    {
        $tag = (new LanguageCatalog())->getPackConfig($lang)['tag'] ?? '';

        return is_string($tag) && $tag !== '' ? $tag : (string) $lang;
    }

    /** @return array<string, callable> game view helpers, as function and as filter */
    private function viewHelperCallables(): array
    {
        return [
            'pretty_number' => $this->viewHelpers->prettyNumber(...),
            'shortly_number' => $this->viewHelpers->shortlyNumber(...),
            'compact_number' => $this->viewHelpers->compactNumber(...),
            'display_number' => $this->viewHelpers->displayNumber(...),
            'millions_number' => $this->viewHelpers->millionsNumber(...),
            'isModuleAvailable' => $this->viewHelpers->isModuleAvailable(...),
        ];
    }

    /** @return list<TwigFunction> PHP passthroughs, game view helpers (pretty_number, ...), theme_asset, icon, php_date and html_lang */
    public function getFunctions(): array
    {
        $functions = [];
        foreach (self::PASSTHROUGH as $name) {
            $functions[] = new TwigFunction($name, self::call($name));
        }
        foreach ($this->viewHelperCallables() as $name => $callable) {
            $functions[] = new TwigFunction($name, $callable);
        }
        $functions[] = new TwigFunction('theme_asset', $this->themeAsset(...));
        $functions[] = new TwigFunction('theme_srcset', $this->themeSrcset(...));
        $functions[] = new TwigFunction('php_date', 'date');
        $functions[] = new TwigFunction('icon', $this->icon(...), ['is_safe' => ['all']]);
        $functions[] = new TwigFunction('html_lang', $this->htmlLang(...));

        return $functions;
    }

    /** @return list<TwigFilter> the same passthroughs and view helpers as filters, plus escape-aware format/nl2br and legacy_markup */
    public function getFilters(): array
    {
        $filters = [];
        foreach (self::PASSTHROUGH as $name) {
            $filters[] = new TwigFilter($name, self::call($name));
        }
        foreach ($this->viewHelperCallables() as $name => $callable) {
            $filters[] = new TwigFilter($name, $callable);
        }
        // escape-aware replacements of Twig's own format/nl2br, see format() and nl2br()
        $filters[] = new TwigFilter('format', [self::class, 'format'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('nl2br', [self::class, 'nl2br'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('legacy_markup', [self::class, 'legacyMarkup'], ['is_safe' => ['all']]);

        return $filters;
    }

    /**
     * sprintf() that keeps markup intact: a trusted format string (a translation, Twig\Markup) keeps
     * its HTML, a plain one is escaped; the arguments are escaped unless they are Markup themselves.
     */
    public static function format(mixed $format, mixed ...$args): Markup
    {
        $escape = static fn(mixed $v): mixed => match (true) {
            $v instanceof Markup => (string) $v,
            is_string($v), $v instanceof \Stringable => GameEscaping::escape((string) $v, 'UTF-8'),
            default => $v,
        };

        return new Markup(sprintf((string) $escape($format), ...array_map($escape, $args)), 'UTF-8');
    }

    /**
     * Stored message HTML written before the Content-Security-Policy carries inline handlers such as
     * onclick="return Dialog.Playercard(12, 'name');" (blocked by the policy, and reported). Rewrites those
     * player/info links to the data attributes of public/scripts/app/actions.js before the browser sees them.
     * Use on stored HTML only (`Message.text|legacy_markup`); new texts are written with data attributes.
     */
    public static function legacyMarkup(mixed $html): Markup
    {
        $out = preg_replace_callback(
            '/\sonclick\s*=\s*(["\'])\s*(?:return\s+)?Dialog\.(Playercard|PM|Buddy|info|ach)\(\s*(\d+)(?:(?!\1).)*\1/is',
            static fn(array $m): string => ' data-action="dialog-' . strtolower($m[2]) . '" data-id="' . $m[3] . '"',
            (string) $html,
        );

        return new Markup($out ?? (string) $html, 'UTF-8');
    }

    /** nl2br() for plain text: escapes it (unless it is Markup), then turns line breaks into <br> */
    public static function nl2br(mixed $text): Markup
    {
        $html = $text instanceof Markup ? (string) $text : GameEscaping::escape((string) $text, 'UTF-8');

        return new Markup(nl2br($html), 'UTF-8');
    }

    /**
     * Late-bound call: the game's global helpers (pretty_number, ...) are only defined once
     * the game code is bootstrapped, not when templates are compiled or linted.
     */
    private static function call(string $function): \Closure
    {
        return static fn(...$args) => $function(...$args);
    }

    /**
     * A Lucide icon from the local sprite (public/styles/resource/icons/lucide.svg). Decorative
     * unless a label is given, which becomes the accessible name.
     */
    public function icon(string $name, ?string $label = null, string $class = ''): string
    {
        $a11y = $label === null || $label === ''
            ? ' aria-hidden="true"'
            : ' role="img" aria-label="' . self::escape($label) . '"';

        return '<svg class="icon' . ($class !== '' ? ' ' . self::escape($class) : '') . '"' . $a11y . '>'
            . '<use href="' . $this->assetBase() . 'styles/resource/icons/lucide.svg?v=' . self::spriteVersion() . '#i-' . self::escape($name) . '"></use></svg>';
    }

    /** relative path to the site root: the installer (/install/) lives one level down, every other page is addressed from the root */
    private function assetBase(): string
    {
        return str_starts_with($this->state->runtime->httpRoot, '/install/') ? '../' : './';
    }

    /** cache buster of the icon sprite (its modification time), so new icons show up without a hard reload */
    private static function spriteVersion(): string
    {
        static $version = null;

        return $version ??= (string) \App\Runtime\SafeFile::mtime(\App\Runtime\ProjectPaths::ROOT . 'public/styles/resource/icons/lucide.svg');
    }

    /** Attribute/text escaping without double encoding (input is already entity-encoded by RequestInput::typed()). */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_COMPAT, 'UTF-8', false);
    }
}
