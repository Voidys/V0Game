<?php

declare(strict_types=1);

namespace App\Game\Core;

use App\Runtime\ProjectPaths;
use App\Runtime\SafeFile;

/**
 * Resolves theme image paths (`gebaeude/229.gif`, `planeten/varianten/v2_storm/x.webp`) to a URL.
 *
 * Lookup order for a theme (image theme):
 *   1. the theme's own folder        public/styles/theme/<theme>/
 *   2. each artwork set the theme declares in its style.cfg (`'assetSets' => ['nebula', 'classic']`, in order)
 *                                    public/styles/resource/theme-shared/sets/<set>/
 *   3. the files every theme has in common
 *                                    public/styles/resource/theme-shared/common/
 *   A GIF with a WebP of the same name in the same folder is answered by the WebP (animated copy, smaller).
 *   Each folder is tried with the exact path first, then with the extension replaced by .webp (images are
 *   shipped as WebP, templates may still ask for x.gif / x.jpg / x.png).
 *   4. nothing found: the theme path is returned, so a missing image shows up as before (404).
 *
 * A folder that ships only `planeten/mond.webp` (no `mond01` .. `mond09`) answers every numbered moon with it.
 *
 * A new theme overrides any image by putting a file at the same relative path into its folder, or reuses
 * existing artwork by declaring a set. tools/images/dedupe-theme-assets.php moves identical files out of the
 * theme folders into the shared folder.
 *
 * The per-theme lookup map (relative path => URL) is cached in var/game/theme-assets-<theme>.php and rebuilt
 * when the modification time of one of the folders it was built from changes (a file added or removed).
 * Static: the resolver needs no request state, only the project paths.
 */
final class ThemeAssets
{
    public const string THEMES_DIR = 'public/styles/theme/';
    public const string SHARED_DIR = 'public/styles/resource/theme-shared/';

    /** @var array<string, array<string, string>> per-process memo: theme => path => URL */
    private static array $maps = [];

    /**
     * URL of the asset for the theme (the theme folder URL when no file is found).
     */
    public static function resolve(string $theme, string $path): string
    {
        $path = ltrim(preg_replace('#^\./#', '', $path), '/');
        if (!isset(self::$maps[$theme])) {
            self::$maps[$theme] = self::load($theme);
        }

        return self::$maps[$theme][$path] ?? self::themeUrl($theme) . $path;
    }

    /**
     * the artwork sets a theme declares in style.cfg (list of set names, in lookup order)
     *
     * @return array<mixed>
     */
    public static function setsOf(string $theme): array
    {
        $cfg = ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg';
        if (!is_file($cfg)) {
            return [];
        }
        $Skin = [];
        require $cfg;
        $sets = $Skin['assetSets'] ?? [];

        return array_values(array_filter((array) $sets, static fn($s) => is_string($s) && preg_match('/^[A-Za-z0-9_-]+$/', $s)));
    }

    /**
     * Lookup chain of a theme: list of [absolute directory, URL prefix] in priority order.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function chain(string $theme): array
    {
        $root = ProjectPaths::ROOT;
        $chain = [[$root . self::THEMES_DIR . $theme . '/', self::themeUrl($theme)]];
        foreach (self::setsOf($theme) as $set) {
            $chain[] = [$root . self::SHARED_DIR . 'sets/' . $set . '/', './styles/resource/theme-shared/sets/' . $set . '/'];
        }
        $chain[] = [$root . self::SHARED_DIR . 'common/', './styles/resource/theme-shared/common/'];

        return $chain;
    }

    /**
     * URL prefix of a theme folder.
     */
    public static function themeUrl(string $theme): string
    {
        return './styles/theme/' . $theme . '/';
    }

    /** @return array<string, string> */
    private static function load(string $theme): array
    {
        $file = ProjectPaths::CACHE . 'theme-assets-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $theme) . '.php';
        $cached = is_file($file) ? (is_readable($file) ? include $file : null) : null;
        if (is_array($cached) && self::fresh($cached['dirs'] ?? [])) {
            return $cached['map'];
        }

        $dirs = [];
        $map = [];
        foreach (self::chain($theme) as [$dir, $url]) {
            $files = self::scan($dir, '', $dirs);
            $aliases = self::moonAliases($files);
            $present = array_flip($files);
            foreach ($files as $rel) {
                // an animated `x.webp` made from `x.gif` (tools/images/animated-gif-to-webp.sh) answers for the GIF (when smaller), which stays as the original
                $webp = str_ends_with($rel, '.gif') ? substr($rel, 0, -4) . '.webp' : null;
                $map[$rel] ??= $url . ($webp !== null && isset($present[$webp]) && filesize($dir . $webp) < filesize($dir . $rel) ? $webp : $rel);
            }
            foreach ($aliases as $virtual => $rel) {
                $map[$virtual] ??= $url . $rel;
            }
            // `x.webp` also answers for a requested x.gif / x.jpg / x.png (converted artwork), after the exact files
            foreach (array_merge($files, array_keys($aliases)) as $rel) {
                if (str_ends_with($rel, '.webp')) {
                    foreach (['gif', 'jpg', 'jpeg', 'png'] as $ext) {
                        $map[substr($rel, 0, -4) . $ext] ??= $url . ($aliases[$rel] ?? $rel);
                    }
                }
            }
        }
        $dirs[ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg'] = SafeFile::mtime(ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg');

        // best effort: without a cache file the map is simply rebuilt on the next request (failure is logged)
        SafeFile::write($file, '<?php return ' . var_export(['dirs' => $dirs, 'map' => $map], true) . ';');

        return $map;
    }

    /**
     * Moon pictures `mond01` .. `mond09` (and their variants `mond01_v1_magic`, ...) of a folder that ships only the
     * single `mond` picture: the folder needs no nine identical copies, each number is answered by `mond`.
     * A folder with at least one numbered moon of its own is left alone.
     *
     * @param list<string> $files
     * @return array<string, string> virtual path => real path
     */
    private static function moonAliases(array $files): array
    {
        $aliases = [];
        foreach ($files as $rel) {
            if (!preg_match('#^(planeten/(?:varianten/[^/]+/)?)mond((?:_v\d_[a-z]+)?)\.webp$#', $rel, $m)) {
                continue;
            }
            $numbered = false;
            for ($i = 1; $i <= 9; $i++) {
                $numbered = $numbered || in_array($m[1] . 'mond0' . $i . $m[2] . '.webp', $files, true);
            }
            if (!$numbered) {
                for ($i = 1; $i <= 9; $i++) {
                    $aliases[$m[1] . 'mond0' . $i . $m[2] . '.webp'] = $rel;
                }
            }
        }

        return $aliases;
    }

    /** @param array<string, int> $dirs directory (or file) => mtime it was built from; a missing folder counts as 0 */
    private static function fresh(array $dirs): bool
    {
        if ($dirs === []) {
            return false;
        }
        foreach ($dirs as $path => $mtime) {
            clearstatcache(false, $path);
            if (SafeFile::mtime($path) !== $mtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Files below $dir as relative paths. WebP copies of static images (`foo.png.webp`, made by
     * app:images:webp and picked by the web server) and width variants (`foo-64w.webp`, app:images:variants) are no assets of their own.
     *
     * @param array<string, int> $dirs collects every folder with its mtime
     * @return list<string>
     */
    private static function scan(string $dir, string $rel, array &$dirs): array
    {
        $dir = rtrim($dir, '/');
        $entries = is_dir($dir . '/' . $rel) ? scandir($dir . '/' . $rel) : false;
        $dirs[rtrim($dir . '/' . $rel, '/')] = SafeFile::mtime($dir . '/' . $rel);
        if ($entries === false) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $rel . $entry;
            if (is_dir($dir . '/' . $path)) {
                array_push($found, ...self::scan($dir, $path . '/', $dirs));
            } elseif (!preg_match('/\.(png|jpe?g|gif)\.webp$/i', $entry) && !preg_match('/-\d+w\.webp$/i', $entry)) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
