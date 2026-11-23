#!/usr/bin/env php
<?php

/**
 * Moves theme images that are byte-identical in several themes into the shared folder.
 *
 *   php tools/images/dedupe-theme-assets.php [--apply]
 *
 * Without --apply it only reports. Run tools/images/convert-theme-images.sh first (identical sources give
 * identical WebP files). Concept and lookup order: App\Game\Core\ThemeAssets.
 *
 *   public/styles/resource/theme-shared/common/        files held by (nearly) all themes
 *   public/styles/resource/theme-shared/sets/<set>/    artwork of a group of themes; a theme declares the sets it
 *                                                      can use in style.cfg: 'assetSets' => ['nebula']
 *
 * Per relative path the files of all themes are grouped by content:
 *   - the largest group goes to common/ when it is every theme that has the file (at least two), or at least
 *     five themes;
 *   - every other group of two or more themes goes to the set whose members include all of them (the one with
 *     the fewest members), if that set does not yet hold the path;
 *   - a theme's own file is deleted only when the lookup chain (sets in order, then common/) then yields a file
 *     with the same hash. Everything else stays: files that differ, files a theme.css refers to relatively,
 *     files hard-coded as styles/theme/<name>/... in src/, templates/ or public/scripts/, the *.cfg/theme.css.
 * After --apply the script re-resolves every theme x path of the old state and fails on any hash mismatch.
 * Idempotent: a second run (new theme, new artwork) only handles what is still in the theme folders.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Game\Core\ThemeAssets;
use App\Runtime\ProjectPaths;

$apply = in_array('--apply', $argv, true);
$root = realpath(ProjectPaths::ROOT) . '/';
$themesDir = $root . ThemeAssets::THEMES_DIR;
$sharedDir = $root . ThemeAssets::SHARED_DIR;
const SKIP = ['style.cfg', 'theme.css', 'settings.cfg', 'readme.md', '.gitignore', 'index.htm'];

// --- themes, their sets, files ----------------------------------------------------------------------------
$themes = [];
foreach (glob($themesDir . '*/style.cfg') as $cfg) {
    $themes[] = basename(dirname($cfg));
}
sort($themes);
$sets = [];   // set => [theme, ...]
foreach ($themes as $t) {
    foreach (ThemeAssets::setsOf($t) as $s) {
        $sets[$s][] = $t;
    }
}

function files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[substr($f->getPathname(), strlen($dir))] = $f->getPathname();
        }
    }

    return $out;
}

$own = [];   // theme => rel => hash
$orig = [];  // the state before: theme => rel => hash (own or shared as resolved now)
foreach ($themes as $t) {
    foreach (files($themesDir . $t . '/') as $rel => $abs) {
        if (in_array($rel, SKIP, true) || preg_match('/\.(png|jpe?g|gif)\.webp$/i', $rel)) {
            continue;
        }
        $own[$t][$rel] = sha1_file($abs);
    }
}
$all = [];
foreach ($themes as $t) {
    foreach (ThemeAssets::chain($t) as $i => [$dir]) {
        foreach (files($dir) as $rel => $abs) {
            if (!in_array($rel, SKIP, true) && !preg_match('/\.(png|jpe?g|gif)\.webp$/i', $rel)) {
                $orig[$t][$rel] ??= sha1_file($abs);
                $all[$rel] = true;
            }
        }
    }
}

// --- protected files: referenced relatively by the theme's css or hard-coded in the code --------------------
$protected = [];   // theme => rel => true
foreach ($themes as $t) {
    $css = $themesDir . $t . '/theme.css';
    if (is_file($css) && preg_match_all('/url\(\s*[\'"]?([^)\'"]+)[\'"]?\s*\)/', (string) file_get_contents($css), $m)) {
        foreach ($m[1] as $ref) {
            if (!preg_match('#^(https?:|//|data:)#', $ref)) {
                $protected[$t][ltrim(preg_replace('#^\./#', '', explode('?', $ref)[0]), '/')] = true;
            }
        }
    }
}
$scan = [];
foreach ([$root . 'src', $root . 'templates', $root . 'public/scripts', $root . 'public/styles/resource/css'] as $d) {
    foreach (files($d . '/') as $abs) {
        $scan[] = $abs;
    }
}
foreach ($scan as $f) {
    if (!preg_match('/\.(php|twig|js|css)$/', $f)) {
        continue;
    }
    if (preg_match_all('#styles/theme/([A-Za-z0-9_ -]+)/([A-Za-z0-9_./ -]+\.(?:png|jpe?g|gif|webp))#', (string) file_get_contents($f), $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $protected[$hit[1]][$hit[2]] = true;
            $protected[$hit[1]][preg_replace('/\.(png|jpe?g|gif)$/', '.webp', $hit[2])] = true;
        }
    }
}

// --- plan -------------------------------------------------------------------------------------------------
$plan = [];   // location (common | sets/<s>) => rel => [hash, sourceAbs]
$has = static function (string $loc, string $rel) use ($sharedDir): ?string {
    $f = $sharedDir . $loc . '/' . $rel;

    return is_file($f) ? sha1_file($f) : null;
};
foreach (array_keys($all) as $rel) {
    $holders = [];
    foreach ($themes as $t) {
        if (isset($own[$t][$rel])) {
            $holders[$own[$t][$rel]][] = $t;
        }
    }
    if ($holders === []) {
        continue;
    }
    uasort($holders, static fn($a, $b) => count($b) <=> count($a));
    $total = array_sum(array_map('count', $holders));
    $i = 0;
    foreach ($holders as $hash => $ts) {
        $src = $themesDir . $ts[0] . '/' . $rel;
        if ($i === 0 && count($ts) >= 2 && (count($ts) === $total || count($ts) >= 5)) {
            if ($has('common', $rel) === null) {
                $plan['common'][$rel] = [$hash, $src];
            }
        } elseif (count($ts) >= 2) {
            $best = null;
            foreach ($sets as $s => $members) {
                if (!array_diff($ts, $members) && ($best === null || count($members) < count($sets[$best]))
                    && $has('sets/' . $s, $rel) === null && !isset($plan['sets/' . $s][$rel])) {
                    $best = $s;
                }
            }
            if ($best !== null) {
                $plan['sets/' . $best][$rel] = [$hash, $src];
            }
        }
        $i++;
    }
}

// --- removals: own file whose chain without it yields the same hash -----------------------------------------
$lookup = static function (string $theme, string $rel) use ($plan, $has, $sets): ?array {
    $locs = [];
    foreach (ThemeAssets::setsOf($theme) as $s) {
        $locs[] = 'sets/' . $s;
    }
    $locs[] = 'common';
    foreach ($locs as $loc) {
        $h = $has($loc, $rel) ?? ($plan[$loc][$rel][0] ?? null);
        if ($h !== null) {
            return [$loc, $h];
        }
    }

    return null;
};
$remove = [];   // [theme, rel]
$used = [];     // loc => rel => true
foreach ($own as $t => $files) {
    foreach ($files as $rel => $hash) {
        if (isset($protected[$t][$rel])) {
            continue;
        }
        $hit = $lookup($t, $rel);
        if ($hit !== null && $hit[1] === $hash) {
            $remove[] = [$t, $rel];
            $used[$hit[0]][$rel] = true;
        }
    }
}

$bytes = 0;
foreach ($remove as [$t, $rel]) {
    $bytes += filesize($themesDir . $t . '/' . $rel);
}
$added = 0;
foreach ($plan as $loc => $items) {
    foreach ($items as $rel => [$hash, $src]) {
        if (isset($used[$loc][$rel])) {
            $added += filesize($src);
        }
    }
}
printf("themes: %s\nsets: %s\n", implode(', ', $themes), json_encode($sets));
printf("remove %d theme files (%.1f MB), add %.1f MB to the shared folder\n", count($remove), $bytes / 1e6, $added / 1e6);
foreach ($used as $loc => $items) {
    printf("  %-20s %d files\n", $loc, count($items));
}
if (!$apply) {
    echo "dry run, use --apply\n";
    exit(0);
}

// --- apply ------------------------------------------------------------------------------------------------
foreach ($plan as $loc => $items) {
    foreach ($items as $rel => [$hash, $src]) {
        if (!isset($used[$loc][$rel])) {
            continue;
        }
        $dst = $sharedDir . $loc . '/' . $rel;
        @mkdir(dirname($dst), 0775, true);
        copy($src, $dst);
    }
}
foreach ($remove as [$t, $rel]) {
    $f = $themesDir . $t . '/' . $rel;
    unlink($f);
    foreach (['.webp'] as $suffix) {
        @unlink($f . $suffix);   // generated copy of an original
    }
}
// empty folders
foreach ($themes as $t) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themesDir . $t, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        }
    }
}

// --- verification: every theme x path of the old state resolves to a file with the same hash ----------------
$checked = $mismatch = $missing = 0;
foreach ($orig as $t => $files) {
    $chain = ThemeAssets::chain($t);
    foreach ($files as $rel => $hash) {
        $checked++;
        $found = null;
        foreach ($chain as [$dir]) {
            if (is_file($dir . $rel)) {
                $found = $dir . $rel;
                break;
            }
        }
        if ($found === null) {
            $missing++;
            echo "MISSING  $t/$rel\n";
        } elseif (sha1_file($found) !== $hash) {
            $mismatch++;
            echo "MISMATCH $t/$rel\n";
        }
    }
}
printf("verified: %d theme x path pairs checked, %d mismatches, %d missing\n", $checked, $mismatch, $missing);
exit($mismatch + $missing > 0 ? 1 : 0);
