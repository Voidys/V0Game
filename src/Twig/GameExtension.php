<?php

namespace App\Twig;

use App\Runtime\GameConfig;
use App\Runtime\LanguagePacks;
use App\Runtime\ProjectPaths;
use App\Runtime\RuntimeContext;
use App\Runtime\SafeFile;
use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Game-wide template helpers:
 *   game_config('game_name')  value from the game configuration
 *   languages()               available languages (locale => name)
 *   asset_version             cache-busting version (short git commit, RuntimeContext::$gitVersion)
 *   import_map()              <script type="importmap"> that versions every ES module under
 *                             public/scripts by its mtime, so modules imported by other modules
 *                             (`import './dialog.js'`) are refetched as soon as the file changes
 *   module_preload(entries)   <link rel="modulepreload"> for the static imports of the page modules (versioned like the map)
 *   data_island(id, data)     <script type="application/json" id="id"> with the data as JSON: the way a template
 *                             hands data to its page module (read with pageData() from scripts/app/core.js).
 *                             JSON is not executed, so no nonce is needed
 */
final class GameExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly LanguagePacks $languagePacks,
        private readonly RuntimeContext $runtime,
        private readonly CspNonce $nonce,
        // debug (dev): scan the files on every request, so an edited module is versioned at once
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = true,
    ) {}

    /** @return list<TwigFunction> game_config(key), languages(), import_map(), data_island(id, data) */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_config', $this->config->get(...)),
            new TwigFunction('languages', $this->languagePacks->getNames(...)),
            new TwigFunction('import_map', $this->importMap(...)),
            new TwigFunction('module_preload', $this->modulePreload(...)),
            new TwigFunction('data_island', $this->dataIsland(...), ['is_safe' => ['all']]),
        ];
    }

    /** @return list<TwigFilter> json_script (JSON for inside a <script> element, see jsonForScript()) */
    public function getFilters(): array
    {
        return [new TwigFilter('json_script', self::jsonForScript(...), ['is_safe' => ['all']])];
    }

    /**
     * `<script type="importmap">` mapping every file under public/scripts to the same path with `?v=<mtime>`, so ES
     * module imports are cache-busted per file. Must precede every module script of the page (import maps apply to
     * later module loads only). Outside debug the map is built once and kept in var/game/cache.importmap.php under a
     * key of the git version and the modification times of the script directories (a new or removed file, a new
     * deployment; an edit alone keeps the old versions until var/game/ is cleared); the admin "clear cache" and a deploy that clears var/game/ rebuild it. The tag carries the CSP nonce.
     */
    public function importMap(): Markup
    {
        return $this->importMapTag($this->moduleMap()['json']);
    }

    /**
     * `<link rel="modulepreload">` for the static import closure of the given page modules (paths below
     * public/scripts, e.g. `app/main.js`, `game/galaxy.js`), with the same versioned URLs the import map resolves, so
     * the browser fetches the whole tree in parallel instead of one import level per round trip. The entries themselves
     * are not listed (their `<script>` tags fetch them); dynamic `import()` is not followed (lazy on purpose).
     *
     * @param list<string> $entries
     */
    public function modulePreload(array $entries): Markup
    {
        $map = $this->moduleMap();
        $imports = json_decode($map['json'], true)['imports'] ?? [];
        $skip = [];
        foreach ($entries as $entry) {
            $skip['./scripts/' . ltrim($entry, '/')] = true;
        }
        $seen = [];
        $queue = array_keys($skip);
        while ($queue) {
            $path = array_shift($queue);
            foreach ($map['graph'][$path] ?? [] as $dep) {
                if (!isset($seen[$dep])) {
                    $seen[$dep] = true;
                    $queue[] = $dep;
                }
            }
        }
        $html = '';
        foreach (array_keys($seen) as $dep) {
            if (isset($imports[$dep]) && !isset($skip[$dep])) {
                $html .= '<link rel="modulepreload" href="' . htmlspecialchars($imports[$dep], ENT_QUOTES) . '">' . "\n  ";
            }
        }

        return new Markup(rtrim($html), 'UTF-8');
    }

    /**
     * The import map JSON and the static import graph (`./scripts/<file>` => the files it imports statically), built
     * once per process and, outside debug, cached in var/game/cache.importmap.php (see importMap()).
     *
     * @return array{json: string, graph: array<string, list<string>>}
     */
    private function moduleMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $root = ProjectPaths::ROOT . 'public/scripts/';
        $cacheFile = ProjectPaths::CACHE . 'cache.importmap.php';
        $key = $this->debug ? null : $this->importMapKey($root);
        if ($key !== null && is_file($cacheFile)) {
            $cached = include $cacheFile;
            if (is_array($cached) && ($cached['key'] ?? null) === $key && is_string($cached['json'] ?? null) && is_array($cached['graph'] ?? null)) {
                return $map = ['json' => $cached['json'], 'graph' => $cached['graph']];
            }
        }

        $imports = [];
        $graph = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $path = './scripts/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            $imports[$path] = $path . '?v=' . $file->getMTime();
            $graph[$path] = $this->staticImports($path, (string) file_get_contents($file->getPathname()));
        }
        ksort($imports);
        ksort($graph);

        $json = json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        if ($key !== null) {
            SafeFile::write($cacheFile, '<?php return ' . var_export(['key' => $key, 'json' => $json, 'graph' => $graph], true) . ';');
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cacheFile, true);
            }
        }

        return $map = ['json' => $json, 'graph' => $graph];
    }

    /**
     * Relative modules a script imports statically (`import … from './x.js'`, `import './x.js'`, `export … from`), as
     * `./scripts/...` paths. Only relative specifiers are followed (all game modules use them).
     *
     * @return list<string>
     */
    private function staticImports(string $path, string $source): array
    {
        $source = preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $source) ?? $source;
        preg_match_all('~(?:^|\n)[ \t]*(?:import|export)\s+(?:[^;\'"()]*?\s+from\s*)?[\'"](\.{1,2}/[^\'"]+)[\'"]~', $source, $found);
        $deps = [];
        foreach ($found[1] as $specifier) {
            $parts = explode('/', dirname($path) . '/' . $specifier);
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '..') {
                    array_pop($resolved);
                } elseif ($part !== '.') {
                    $resolved[] = $part;
                }
            }
            $deps[] = './' . implode('/', array_slice($resolved, $resolved[0] === '.' ? 1 : 0));
        }

        return array_values(array_unique($deps));
    }

    /** git version plus the modification times of the script directory and its subdirectories (added/removed files) */
    private function importMapKey(string $root): string
    {
        $parts = [$this->runtime->gitVersion, SafeFile::mtime($root)];
        foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $parts[] = SafeFile::mtime($dir) . $dir;
        }

        return md5(implode('|', $parts));
    }

    /**
     * JSON data island. `<`, `>`, `&` and quotes are \u-escaped, so no value can close the script element
     * or leak markup; translations (trusted Markup) and plain values are encoded as they are.
     */
    public function dataIsland(string $id, mixed $data): string
    {
        return '<script type="application/json" id="' . htmlspecialchars($id, ENT_QUOTES) . '">' . self::jsonForScript($data) . '</script>';
    }

    /**
     * JSON for the inside of a <script> element (data islands, JSON-LD): `<`, `>`, `&`, `'` and `"` are \u-escaped, so no value
     * (host name, player text, translation) can close the element or open a comment. "null" when the data cannot be encoded.
     */
    public static function jsonForScript(mixed $data): string
    {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? 'null' : $json;
    }

    /** the importmap is an inline script, so it carries the Content-Security-Policy nonce of the request */
    private function importMapTag(string $json): Markup
    {
        return new Markup('<script type="importmap" nonce="' . $this->nonce->value() . '">' . $json . '</script>', 'UTF-8');
    }

    /** @return array{asset_version: string} short git hash, appended to static asset URLs for cache busting */
    public function getGlobals(): array
    {
        return ['asset_version' => $this->runtime->gitVersion];
    }
}
