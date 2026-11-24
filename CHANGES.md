# Changes

Technical changes per step, newest last (started with the migration to PHP 8.4, Symfony 8.1 and Twig). New entries go at the end of this file.

## 1. PHP 8.4 + MySQL 8.4 (`74d81b37`)

- DDEV: PHP 8.2 → 8.4, MySQL 8.0 → 8.4, extra packages `php8.4-imap/opcache/ds`.
- Explicit nullable types for parameters defaulting to null (`BattleHallFilter`, `PlayerUtil::createPlayer`, `ResourceUpdate::add/removeResources`).
- Removed deprecated `E_STRICT` from the error type map (`GeneralFunctions.php`).
- Registered PHP functions used in templates as Smarty modifiers (`pretty_fly_time`, `allowedTo`, `array_key_exists`, `array_filter`, `range`, `intval`, `min`, `max`, `date`).
- Removed the unused `.config/` lint configs.

## 2. Symfony 8.1 kernel with legacy bridge (`c2e5bd79`)

- Added Symfony 8.1 (FrameworkBundle, Twig 3, Doctrine DBAL 4/ORM 3, Monolog) via Flex next to the existing code.
- Document root moved to `public/` (DDEV config and `.ddev/apache/pr0game.conf`); the rest of the repository is no longer web-reachable.
- `public/index.php` as the single entry point; a bridge handed unported URLs to the old entry scripts.
- Legacy asset dirs symlinked into `public/` (`styles`, `scripts`, `favicon.ico`, `robots.txt`, `battlesim.html`).
- `App\` and legacy `TwoMoons\` share `src/`; legacy dirs excluded from service discovery.
- `DATABASE_URL` and `TABLE_PREFIX` in `.env`; `/healthz` smoke route (Twig + DBAL).
- Upgraded `symfony/yaml`, `symfony/var-dumper` and `php-cs-fixer` for Symfony 8; cs-fixer config moved to `.php-cs-fixer.dist.php`.

## 3. Shared login, locale and routing (`9c2cd0f9`)

- Symfony uses the game's native session (`2Moons`, `cache/sessions`); `LegacySessionAuthenticator` authenticates from it (stateless firewall).
- Account roles from `accounts.authlevel` (`ROLE_MODERATOR`/`OPERATOR`/`ADMIN` with a hierarchy).
- Legacy language packs (`language/<locale>/*.yaml`) registered as translator domains; default locale `en`, fallback `de`.
- `LocaleSubscriber`: `?lang=` > account language > `lang` cookie > Accept-Language > default.
- Services `GameConfig`, `LanguagePacks`, `TableNames`; Twig helpers `game_config()`, `languages()`, `asset_version`.
- Login layout and rules page ported to Twig (`/rules`); old `index.php?page=rules` redirects (308).

## 4. Smarty replaced by Twig (`80408618`)

- All 218 Smarty templates converted to Twig in `templates/legacy/<area>/`.
- `TwoMoons\Core\Template` is a Twig adapter with the former API (`assign_vars`, `show`, `display`, `fetch`, `message`).
- Child templates extend `layout_template`; layout includes that define blocks are inlined.
- `TemplateExtension` keeps PHP semantics (`empty`, `isset`, foreach casting, constants, `html_options`).
- `TemplateLanguage` renders missing translation keys as the key name (Smarty behaviour).
- Removed `smarty/smarty` and `styles/templates/`.

## 5. Entry points as Symfony controllers (`6eea66b2`)

- `GameEntryController` serves `/`, `/index.php`, `/game.php`, `/admin.php`, `/account.php`, `/cronjob.php`, `/CombatReport.php` (also with `/uniN/`), plus the installer, voting and wiki SSO scripts.
- Root entry scripts deleted; `LegacyKernel` (bootstrap) and `PageDispatcher` (`?page=`/`&mode=` dispatch) replace them.
- All `exit`/`die` in game code replaced by `throw new EndOfRequest()`; `LegacyResponseFactory` turns output, native headers and status into a Response.
- Game routes run outside the Symfony firewall (security.yaml `legacy`).
- `Template::loadFilter('output', 'trimwhitespace')` ported (spy reports); fixed the ajax layouts extending themselves.
- Removed the root `.htaccess` and `index.html`; `nginx.md` and README updated for the `public/` docroot.

## 6. Template helpers in Symfony Twig (`8490791c`)

- `TemplateExtension` registered with the Symfony Twig environment; `bin/console lint:twig templates` covers the game templates.
- Global game helpers are called late-bound, so templates compile without bootstrapping the game.

## 7. Bootstrap class and DB settings from .env (`808c5d4c`)

- `includes/common.php` → `App\Legacy\GameBootstrap::run()` (same steps, same order); the kernel, installer, voting and PHP migrations call it.
- `TwoMoons\Core\DatabaseConfig`: game DB connection and table prefix from `DATABASE_URL`/`TABLE_PREFIX`, with `includes/config.php` as fallback (installer, CLI).

## 8. Cron command and CLI migrations (`7b494e9d`)

- `bin/console app:cron:run [--job=ID]` runs due game cronjobs (same locking/rescheduling as the in-game `cronjob.php` trigger, which stays).
- `LegacyKernel::bootCli()`: CLI bootstrap without session/user (game data, default language, universe config).
- Installer runs PHP migrations through the PHP CLI instead of an HTTP request to the (no longer web-reachable) project root.
- PHP migrations call `GameBootstrap::run()` instead of `includes/common.php`.

## 9. Page controllers base + PER-CS

- `GamePage` route attribute: original URLs (`index.php?page=x`, case-insensitive, `/uniN/` variant) as real Symfony routes with priority over the generic entry route.
- `GameControllerInterface` + `GameControllerListener`: boot the game for the controller's area and collect results via `LegacyResponseFactory` (now also accepts returned Responses + native headers).
- `AbstractLoginController`: `render()`, `printMessage()`, `redirectTo()`, `sendJSON()` with the layout data of the former `AbstractLoginPage`.
- Pilot: `ShowNewsPage` → `Login\NewsController` (output identical).
- Added `symfony/expression-language` (route conditions).
- Code style: new code PER-CS (`.php-cs-fixer.dist.php`), legacy code PSR-12 (`.php-cs-fixer.legacy.php`).

## 10. Login area ported to controllers

- Pattern: page class → controller with class-level `#[GamePage]`; base controllers generated from the legacy `Abstract*Page` (constructor work → `initialize()`), `PageModeDispatch` resolves `?mode=` only to methods of the concrete controller.
- `display()`/`printMessage()`/`redirectTo()`/`sendJSON()` keep their request-ending semantics (`EndOfRequest`, may carry a Response) so control flow stays identical.
- All login pages ported (`App\Controller\Login\*`); legacy `ShowRulesPage` dropped (native `/rules`).
- `$requireModule = MODULE_*` stored as constant name and resolved after bootstrap (routing loads classes before the game constants exist).
- Side effect: `?page=hof` (lowercase) now works; the file/class case mismatch used to break it on Linux.

## 11. Account area ported to controllers

- All account pages ported (`App\Controller\Account\*`); `DataPrivacyController` (login base) keeps the ACCOUNT mode under `account.php`.
- Legacy `AbstractLoginPage`/`AbstractAccountPage` removed; unknown pages answered by the area error controller.

## 12. Game area ported to controllers

- All 51 game pages ported (`App\Controller\Game\*`, 102 routes incl. `/uniN/`); legacy `AbstractGamePage` removed.
- `AllianceController` keeps the former second page construction (`PlayerCardController` + `initialize()`), `GalaxyRows` uses `PhalanxController::allowPhalanx()`.
- Typed `static int $requireModule` handled like the untyped ones (constant name, resolved late).

## 13. Admin area ported, legacy pages gone

- All admin pages ported (`App\Controller\Admin\*`); `AbstractAdminController::initialize()` = former admin.php checks, then `construct()` (former page constructor, base = permission check by former class name).
- `AccountDataController` keeps its former lack of a per-page permission check; unreachable `ShowVertify.php` removed.
- `src/Pages/` and `PageDispatcher` removed; `GameEntryController` only answers unknown pages, cron, CombatReport and the standalone scripts.

## 14. Root cleanup

- Removed dead tooling: `.vscode/`, `.drone.yml`, `.gitlab-ci.yml`, `docker-compose.yml`, `tests/run.js`.
- Removed obsolete leftovers: `logs/`, `cache/.htaccess`, `.phpcs-cache`.
- `public/stats` symlink: the stats JSON export (`MODULE_STATS_JSON`) is web-reachable again after the `public/` docroot move.

## 15. Translations in Symfony format

- `language/<locale>/<DOMAIN>.yaml` → `translations/<domain>.<locale>.yaml` (101 files); Symfony loads them natively (`debug:translation`, `lint:yaml` work).
- `LanguageBuildCache` builds the game language cache from `translations/` (same cache keys, e.g. `en-L18N`).
- `LegacyTranslationsPass` → `AvailableLocalesPass` (only `app.locales` from `language/*/LANG.cfg`).
- `language/` keeps the pack metadata (`LANG.cfg`), text templates (`templates/*.txt`) and `bsim.json`.
- Battle simulator texts (`/language/<lang>/bsim.json`, loaded by the browser) reachable again via per-file symlinks in `public/language/` (broken since the `public/` docroot move); `LANG.cfg` stays private.

## 16. language/ dissolved

- `language/<locale>/LANG.cfg` → `config/languages.yaml` (lossless conversion); read via `Language::getPacks()`, `LanguagePacks`, `AvailableLocalesPass`, `LanguageBuildCache`.
- Text templates `language/<locale>/templates/*.txt` → `templates/texts/<locale>/*.txt` (still plain text with sprintf placeholders).
- `bsim.json` → `public/language/<lang>/bsim.json` (same URL, real files instead of symlinks).
- Language lists are now in alphabetical order (formerly filesystem order).

## 17. Docs and web root files

- `nginx.md` → `docs/nginx.md`.
- `robots.txt` → real file in `public/` (was a root file + symlink); dropped its rules for `/includes/` and `/cache/`, which are not web-reachable anymore.

## 18. Composer cleanup

- Removed unused `doctrine/orm` (+ collections, inflector, instantiator, lexer), `symfony/apache-pack`, `symfony/polyfill-php86`; ORM config and `src/Entity` dropped (only DBAL is used).
- `doctrine/dbal` required explicitly; `symfony/var-dumper` moved to require-dev.
- Exact pins relaxed: `erusev/parsedown ^1.7.2` (→ 1.8.0), `phpmailer/phpmailer ^6.9.1` (→ 6.12.0); `composer validate` clean, no audit advisories.
- Locked production packages: 71 → 64.
- `battlesim.html` (static TrashSim page) → real file in `public/` (was a root file + symlink).

## 20. Runtime data in var/

- `cache/` → `var/game/` (`CACHE_PATH`: game data and language caches, Twig cache, blocklist) and `var/sessions/` (native sessions, shared by game and Symfony).
- Updated `Session`, `framework.session`, `Language`, `LanguageBuildCache`, `ClearCache()`, the installer writable-dir check; `GameBootstrap` creates the dirs; Smarty leftover `cache/templates/` dropped.

## 21. Static assets in public/

- `styles/` and `scripts/` moved into `public/` as real directories (were symlinks); URLs unchanged.
- Disk reads adjusted: `Theme` (style.cfg/settings.cfg/theme list), `ScreensController` (screenshot listing); `ttf_file` default path updated.
- Theme `*.cfg` files are denied by the web server (`public/.htaccess`, `docs/nginx.md`).
- AssetMapper/Encore deliberately not used: ~4,900 files / 377 MB of mostly runtime-built image paths, cache-busting already via `?v=<commit>`.

## 22. Core port, phase 1: namespace

- `src/{Achievements,AdvancedStats,BattleEngine,Cache,Core,Cronjob,Missions,Models,Tutorials}` → `src/Game/*`, namespace `TwoMoons\*` → `App\Game\*` (230 files, tokenizer-based; string-built class names in Cache, TutorialUtil, Cronjob and path refs updated).
- Composer autoload: only `App\` → `src/`; `src/Game/` excluded from service discovery.
- Existing sessions are invalidated once (serialized `Session` class renamed).

## 23. Core port, phase 2: one database connection

- `App\Game\Core\Database` uses the PDO of Symfony's DBAL connection (`Database::usePdo()`, wired in `LegacyKernel`); standalone connect only outside Symfony (CLI migration scripts).
- DBAL runs the game's connection setup (`Database::INIT_COMMAND`: utf8mb4, `sql_mode = STRICT_ALL_TABLES`) via `doctrine.yaml` options.
- Verified: game and DBAL report the same connection id, sql_mode and charset.

## 24. Core port, phase 3: includes/ dissolved

- Code → `src/Game/Support/`: `constants.php`, `functions.php` (was GeneralFunctions), `tables.php` (dbtables), `vars.php`, `fleet_events.php` (FleetHandler), `julia.php`, `planet_data.php`.
- Config → `config/game.php` (installer-written, gitignored) + `config/game.sample.php`.
- Runtime → `var/log/game.log` (was includes/error.log), `var/backups/`, `var/ENABLE_INSTALL_TOOL`, `var/FIRST_INSTALL` (installer, `ddev installtool`, install translations updated).
- Installer writable-dir check now covers `var/backups/`, `var/log/`, `config/`.

## 25. Voting removed

- Removed the voting feature: `voting.php` callback (+ route, firewall pattern), `VotingController` + template, navigation menu entry and its per-page voting query in `AbstractGameController`, `voting-refresh.js`, bootstrap `VOTING` mode, `VOTING`/`USERS_VOTING` table names, `AdvancedStatsEnum::VOTING_POINTS`, `$apikeys` in the config sample.
- Removed the TCG token setting (only used for voting rewards) from settings page + save.
- Removed the voting/TCG translation keys (ingame, all locales; verified no other key changed).
- Database tables `voting_sites`/`users_voting` and `users_settings.tcg_token` are left untouched (no destructive migration).
- Kept: the archived Uni 7 Hall of Fame entry "Voting Points" (historical content).

## 26. Wiki SSO resolver as Symfony controller

- `internal_sso_resolve.php` → `App\Controller\WikiSsoResolveController` (same URL, token check, statuses and JSON); settings via `App\Legacy\GameSettings` (reads `config/game.php`).
- Added the missing `App\Game\Core\WikiSsoCodeStore` (never existed upstream, so the SSO could not run): one-time codes on Symfony Cache (file in `var/game/wiki_sso/` or Redis), TTL 120 s.
- Verified end to end: wrong token 403, valid code 200 (account id/name), reuse 404, malformed 400, unconfigured 503.

## 27. Installer as class

- `install/index.php` → `App\Legacy\Installer::run()` (logic unchanged, re-indented; imports for global `Exception`/`PDO` added so catches keep working), served at `/install/` by `GameEntryController::install()` via `LegacyKernel::boot()` (INSTALL, or UPGRADE for `?mode=upgrade`).
- `install/` now only holds data (`install.sql`, `migrations/`, `VERSION`); `LegacyKernel::runScript()` removed (no standalone scripts left).

## 28. Game templates on Symfony Twig

- `App\Game\Core\Template` renders with Symfony's Twig environment (`Template::useEnvironment()`, wired in `LegacyKernel`); standalone environment only as CLI fallback. Compiled templates now live in Symfony's cache (`var/cache/<env>/twig`).
- Template areas are Twig namespaces (`@game`, `@login`, `@adm`, `@account`, `@install`, `twig.yaml`); includes in `templates/legacy/` namespaced, so no per-render loader switching.
- `App\Twig\EscapingStrategy` (`autoescape_service`): no autoescaping for game templates under any name (`@area/…`, `legacy/…`, file path), default strategy for the rest; `strict_variables: false`.
- Verified: crawl status + HTML identical (no added escaping).

## 29. Cron on Symfony Scheduler

- `App\Schedule` (Symfony Scheduler): every minute `RunDueCronjobs` → handler starts `bin/console app:cron:run` in a fresh process (the game fixes its time/state per process).
- Worker `bin/console messenger:consume scheduler_default`; DDEV runs it as `web_extra_daemons` entry, README documents production setup.
- Removed the legacy trigger: `cronjob.php` route + firewall pattern, the trigger images in the game layout (`getCronjobsTodo()`); CLI bootstrap now presents itself as `/index.php`.
- Added `symfony/scheduler`, `symfony/messenger`, `symfony/process`.
- Verified: the daemon picks up due jobs and reschedules them; crawl status identical, no trigger image in pages.

## 30. Game core in the container

- `Database`, `Config` and the game state (`Singleton`) are container services (factories over the static accessors, so game code and Symfony share the same instances; `Database`/`Config` lazy because they need the bootstrap).
- Cronjob tasks are DI services (tag `app.game_cronjob`); `Cronjob::execute()` resolves them through a service locator (`LegacyKernel` → `Cronjob::useTaskLocator()`), `new` only as fallback outside Symfony.
- `DailyCronJob`/`ReferralCronJob` renamed to match their files and the stored names (`DailyCronjob`, `ReferralCronjob`).
- Verified: scheduler daemon and admin "run" execute jobs through the container.

## 31. Core DI, phase 1: cronjob tasks

- New `ConfigRegistry` service (`get($universe)`) and `AbstractCronjobTask` (constructor-injected `Database`, `ConfigRegistry`, game state); all 9 tasks extend it and use `$this->db` / `$this->configs` / `$this->state` instead of static accessors.
- `InactiveMailCronjob` now implements the task interface via the base (it never did); `PirateSpawnerCronjob` lost its duplicate `$db` property.
- Verified: all 9 tasks executed through the command without errors.

## 32. Core DI, phase 2: page controllers

- `App\Controller\GameServices` trait (setter injection via `#[Required]`) in all four base controllers: `$this->db`, `$this->configs`, `$this->state`.
- 831 static accessor calls in the page controllers replaced (tokenizer-based, only where `$this` exists); 8 remain in static helper methods (phase 4).
- Controllers created with `new` get the services of their creator (`withGameServicesOf()`); `GameEntryController` injects them for the admin error page; static `printError()` entry points use the shared instances.
- Verified: crawl status identical, HTML differences only live data.

## 33. Core DI, phase 3: fleet processing and missions

- `FlyingFleetHandler` is a container service (constructor-injected `Database`, `ConfigRegistry`, game state); `fleet_events.php` gets it via `GameBootstrap::fleetHandler()` (wired by `LegacyKernel`).
- `MissionFunctions` and all 16 mission classes receive the services through their constructors (created by the handler); 97 static accessor calls replaced.
- Mission map `$missionObjPattern` → `FlyingFleetHandler::missionClasses()` (the MISSION_* constants only exist after bootstrap, the container loads the class earlier).
- Verified: transport dispatch, arrival (messages) and return (ships back) processed through the injected handler; crawl status identical.

## 34. Core DI, phase 4: battle engines

- `BattleHandler(bool $sim, ConfigRegistry, Singleton)` and `AbstractBattleEngine` (constructor-injected `ConfigRegistry` + game state); the handler builds the engines with them, missions and the simulator pass their own services; 28 static accessor calls replaced.
- Verified: battle simulation + report.

## 35. Core DI, phase 5: core instance classes

- `ResourceUpdate`, `Statbuilder`, `GalaxyRows`, `FlyingFleetsTable`, `MarketManager`, `AdvancedStatsHandler`, `SQLDumper`, `ExpeditionDeckHandler`, `SupportTickets`, `Template`, `TutorialUtil` + tutorial supports take `Database`, `ConfigRegistry` and game state as promoted constructor params; ~100 call sites pass their own services, static contexts (`functions.php`, `Installer`, `Template::printMessage`) fall back to the static accessors; 118 static accessor calls replaced by injected properties.
- `BattleHandler` also takes `Database` (fixes the steal/stats writes after phase 4).
- Verified: crawl unchanged, lint:container, lint:twig, cron run, mechanics run.

## 36. Core DI, phase 6: static utilities become objects

- `PlayerUtil`, `FleetFunctions`, `BuildFunctions`, `AccountUtil`, `ExpeditionFindableShipsHandler`, `Achievement`, `SpyReport` and the `Achievements/*` checkers: static methods → instance methods on the injected services, `X::fromGlobals()` for static contexts.
- New `GameUtilities` trait: lazy `$this->playerUtil()`, `$this->fleetFunctions()`, … built from the host's `db`/`configs`/`state`; used by controllers, missions, cronjob tasks, tutorials and core classes; ~590 call sites rewritten, ~180 static accessor calls inside the utilities replaced.
- `Discord` stays static on purpose (error-reporting sink).

## 37. Core DI, phase 7: leftovers

- Battle engines get an injected `Database` (stats writes no longer reach for the static accessor).
- Phalanx rules move from the phalanx page into `FleetFunctions::allowPhalanx()`/`getPhalanxRange()` (galaxy view no longer calls a controller statically); search list and jumpgate cooldown helpers become controller instance methods (`_getSearchList` private, out of `?mode=` reach).
- `ResourceUpdate::getProd()`/`getNetworkLevel()`, `HangarFilters` and `BattleHallFilter` run on injected services; base controllers and `PageModeDispatch` use their own services instead of `fromGlobals()`.
- Static on purpose (bootstrap boundary): the `Database`/`Config`/`Singleton` accessors themselves, `GameBootstrap`, `LegacyKernel`, `Installer`, `functions.php` + support files, `Session`, `Log`, `Mail`, `Language`, `Discord`, `ErrorController::printError()`, `Template::printMessage()`.

## 38. Core DI, phase 8: bootstrap, installer, global helpers

- `GameBootstrap` and `Installer` are container services with injected `Database`/`ConfigRegistry`/game state; `LegacyKernel` and `GameEntryController` get them injected (`$this->bootstrap->run()`, `$this->installer->run()`), both accessor-free; installer's post-config connection test forces the lazy DB service.
- Game rules out of `functions.php`: `getLanguage`, `getPlanets`, `getMainPlanet`, `CalculateMaxPlanetFields`, `CheckNoobProtec` → `PlayerUtil`; `ClearCache`, `listMigrations`, `checkDBUpgrade` → new `Maintenance` class; the globals stay as one-line wrappers, callers with services use `$this->playerUtil()`/`$this->maintenance()`.
- Left global by design: Twig/presentation helpers (`pretty_number`, `pretty_time`, `shortly_number`, date formatting, `message`, `allowedTo`, `isModuleAvailable`) and the PHP error/exception handlers.

## 39. Core DI, phase 9: view helpers, session, errors, mail/log/language, fleet events

- `ViewHelpers` (injected; `pretty_number`, `pretty_time`, `shortly_number`, date formatting, `message`, `allowedTo`, `isModuleAvailable`): Twig binds them via `TemplateExtension(ViewHelpers)`, PHP via `$this->viewHelpers()`; the globals remain as one-line wrappers.
- `SessionManager` replaces the static side of `Session` (init/create/load/client IP); the current session lives in the game state, `Session` objects get the services attached after create/unserialize (not serialized).
- `ErrorReporter` service = former `exceptionHandler()`/`errorHandler()`: registered by `GameBootstrap`, injected into `LegacyResponseFactory`; `Discord` and `Mail` are instance classes (Discord without DB, so it still reports when the DB is down).
- `Log`, `Language` (now `ConfigRegistry`-injected; pack metadata → dependency-free `LanguageCatalog`), `Cronjob` (container service with the task locator; `app:cron:run` gets it injected), `ExpeditionDeckHandler::updateDeckConfig()`, error pages (`showError()` instead of static `printError()`) run on injected services.
- Fleet events run inside `GameBootstrap` on the injected `FlyingFleetHandler` (`fleet_events.php` and the static locator are gone); `julia.php` → `JuliaShipData` (also fixes its engine construction after phase 7); `vars.php`/`constants.php` write the including method's `$this->state`; `Template::printMessage()` (unused) removed; `Singleton::__isset()` no longer warns on unset keys.

## 40. Core DI, phase 10: last statics, two old bugs

- `Universe` is an injected object (state: current/emulated/available universes in the game state; no database, so the installer works before a config exists); 244 call sites → `$this->universe()`.
- `Cache` is a plain injected object with a fixed key → builder map (no runtime registration order); builders (`VarsBuildCache`) get the services; `vars.php` → `GameData::load()`, the `MODULE_*` map → `GameBootstrap::registerModules()`.
- `_date()` → `ViewHelpers::formatDate()`; the remaining global wrappers are gone — `functions.php` keeps only stateless helpers (battle engines use `$this->viewHelpers()->isModuleAvailable()`; a tokenizer scan finds no calls to undefined functions).
- `ConfigFieldSchema` gets the game state passed, `SQLDumper` reads the connection settings from the injected `Database`, `Template` no longer builds its own Twig fallback.
- Fix: `uni1_advanced_stats_history` primary key includes `timestamp` (migration `1794621600`; the statistics cron failed on every second snapshot).
- Fix: admin universe and reset pages no longer warn on a missing `sid` parameter.

## 41. Core DI, phase 11: static accessors removed

- `Database::get()`, `Database::usePdo()`, `Config::get()`/`reload()`, `Singleton::load()`, `DatabaseConfig::get()` are gone: the container builds `Database` (from `DatabaseConfig` + Symfony's DBAL connection, lazy), `Singleton` (game state) and `DatabaseConfig` directly.
- `ConfigRegistry` owns the per-universe `Config` objects and schemas (former static side of `Config`); `Config` instances save through their registry.
- All `fromGlobals()` factories removed; `Database::formatDate()` is an instance method.

## 42. Request, response and session on Symfony

- Request input from the Symfony `Request` only: `LegacyKernel` puts query/post/input (POST over GET)/cookies/server into the game state; no `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` in game code (~260 reads), `HTTP` is an injected object (`$this->http()->_GP()`, 625 call sites).
- Response side: redirects are `RedirectResponse`s, headers/cookies/status codes are collected (`HTTP::sendHeader()`/`sendCookie()`) and applied by `LegacyResponseFactory`; no native `header()`/`setcookie()`/`http_response_code()` left.
- The game session lives in the Symfony session (`obj` attribute, `SessionManager` on `Request::getSession()`, written back before the response); no `session_start()`/`$_SESSION`. Storage: `SESSION_HANDLER_DSN` (default files in `var/sessions`; replaces the admin `session_handler`/`redis_connection_url` settings). Existing logins are invalidated once.
- `DatabaseConfig` gets `DATABASE_URL`/`TABLE_PREFIX` via env processors; Twig reaches `Template` through the game state; `NumberAbbreviation` is an instance; unused `clearGIF()` removed.
- Fix: admin rights page lists the pages from the admin controllers (it still scanned the removed `src/Pages/Admin/`).

## 43. UI refactor: top bar, side panel, native JS (branch task/ui-refactor)

- New game layout: `layout.base` (document shell) + `layout.full`/`layout.popup`; sticky top bar (planet switch, live resources, server clock, messages with badge, rank, account, admin, logout), grouped left side panel with Lucide icons and server-side active state, drawer on small screens, skip link.
- CSS: `app/base.css` (tokens + layered base/components: modal, tooltip, tabs, sortable tables, autocomplete, progress) and `app/game.css` (layout); classic theme stylesheets keep styling the content on top; old layout rules stripped from `ingame/main.css`.
- JS: native ES modules in `public/scripts/app/` (core, dialog on `<dialog>`, tooltip, timers, ticker, ui) replace jQuery, jQuery UI, fancybox, tablesorter, validationEngine, jquery.cookie, tooltip.js and base.js/topnav.js; all page scripts are modules, inline scripts run via `onPageInit()`; same for login, account, admin and installer.
- Removed: jQuery & plugins, tailwind runtime (account area got its own CSS), Font Awesome CDN, tinymce, numeral, html5 shim, unused base CSS (-2 MB scripts, -350 KB CSS); local Lucide sprite (`public/styles/resource/icons/lucide.svg`, `icon()` Twig helper).
- Layout data: player rank/points for the top bar (`total_rank` in the bootstrap user query); new `ui_*` translation keys (de, en).
- Fixes along the way: dead Google jsapi feed on the admin overview, broken `false.png` path, unicode info glyph in the fleet expedition counter.

## 44. UI refactor, part 2: CSS fully on the layer system, account lobby

- `ingame/main.css` → `app/content.css`, rewritten with native nesting and logical properties, dead rules removed (jQuery UI, tinymce, tablesorter, trader, commented-out blocks; 1133 → 1095 lines, pixel-identical).
- The game imports the player's theme and `content.css` into the `legacy` cascade layer (`@layer reset, base, legacy, components` + unlayered shell), so all game CSS runs through cascade layers.
- Account lobby (account.php: universes, settings, register, popup) redesigned in the shell language: slim top bar with Lucide icons, token-based panels and buttons, labelled form grid, responsive to 390px.
- Verified in the browser: market offer → arrival → purchase end to end, account popup (logout), all game pages at 1440/390, admin, login.

## 45. UI refactor, part 3: no legacy CSS or libraries left

- All nine theme stylesheets (`styles/theme/*/formate.css`) rewritten as modern CSS: `--theme-*` palette custom properties, native nesting, logical properties, rem units, range media queries; dead rules removed (old layout selectors, jQuery UI, fancybox, tinymce, old OGame table classes, IE scrollbar hacks, invalid selectors); 6,600 → 4,300 lines. Pixel-verified per theme against the previous files (0.00–0.01 % with clock masked).
- `app/content.css` uses rem instead of px (exact conversion, pixel-identical in game, admin and installer).
- TrashSim removed (static `battlesim.html`, its prebuilt bundle with jQuery, the `trashsim` JSON page and both links); the native battle simulator (also linked from spy reports) covers it. Shipped scripts now ~512 KB (was ~3.1 MB); no jQuery anywhere.
- Dead jQuery UI rule removed from the admin stylesheet.

## 46. Runtime naming

- `App\Legacy` → `App\Runtime` (the game's runtime layer on Symfony): `LegacyKernel` → `GameKernel`, `LegacyResponseFactory` → `GameResponseFactory`, `LegacySession` → `GameSessionReader`, `Security\LegacySessionAuthenticator` → `GameSessionAuthenticator`; firewall `legacy` → `game`. No behaviour change.

## 47. Last global access gone

- Login pages read the `lang` cookie from the request state instead of `$GLOBALS['_COOKIE']`; the installer's unattended-install defaults (`DB_*`, `ADMIN_*`) come in as Symfony env parameters instead of `getenv()`. No superglobals, `$GLOBALS`, `getenv()` or static accessors remain in `src/`.
- Theme breakpoints use range media queries (`width <= 699px`), last stray px padding → rem; pixel-identical at 1440 and 390.

## 48. Units and theme file names

- All shipped stylesheets (app, account, login, admin, installer, base, nine themes) use rem only: 424 px values converted (n/16), incl. hairlines, outlines, shadows and breakpoints; pixel-identical at 1440 and 390 in game (3 themes), login, account, admin and installer.
- Theme stylesheets renamed `formate.css` → `theme.css` (`Theme::getThemeCssPath()`, template variable `themeCssPath`).

## 49. Game constants as compile-time consts

- The 279 fixed game constants (modules, elements, fleet states, limits, auth levels, ...) are `const` declarations in `src/Game/Support/game_constants.php`, loaded by Composer (`autoload.files`); values verified identical. `constants.php` and its runtime `define()` block are gone.
- Request/install-dependent constants (protocol, URLs, cache path, code version) are defined by `GameBootstrap::defineRequestConstants()`.
- Fix: `GIT_VERSION` resolves the repository's `.git` again (it was empty since the move to `src/Game/Support/`), so asset URLs get their cache-busting `?v=<commit>` back.

## 50. Runtime context instead of request constants

- `App\Runtime\RuntimeContext` (container service, `$state->runtime`, PHP 8.4 asymmetric visibility + property hook) replaces the define()d `TIMESTAMP`, `MODE`, `ROOT_PATH`, `CACHE_PATH`, `AJAX_REQUEST`, `HTTPS`, `PROTOCOL`, `HTTP_BASE/ROOT/FILE/HOST/PATH` and `GIT_VERSION`; started once per request by `GameKernel::boot()/bootCli()`, timestamp frozen per request as before. ~400 uses in 90 files rewritten token-aware (strings/SQL untouched).
- `App\Runtime\ProjectPaths` (typed class consts) gives path-only code (theme, cache builders, language catalog, disposable-mail blocklist) the project/cache dirs without request state.
- `DB_NAME`/`DB_PREFIX` → `Database::getDatabaseName()/getTablePrefix()`; `tables.php` uses a local `$prefix`.
- PHP error state moved to `ErrorReporter::register()` (E_ERROR, log to var/log/game.log, handlers); dropped no-op `mb_internal_encoding`, timezone fallback, `display_errors` and the dead P3P header from the bootstrap.
- `functions.php` is Composer-autoloaded (`autoload.files`); `userStatus()` moved to `PlayerUtil::userStatus()`.
- Templates use the `asset_version` Twig global instead of `php_const('GIT_VERSION')`; `GameExtension` reads it from the runtime context.
- `composer.json` requires `php >=8.4.1` (matches Symfony 8.1).
- UI: top bar, side panel and content sit in a 75rem (1200px) shell (`--shell-max`, full-bleed bar with centred contents via `--shell-gutter`); account lobby uses the same width; resources always show short numbers (exact value in the tooltip), "Rank" label hidden in favour of the trophy icon.

## 51. Top bar rows, themeable shell

- Top bar in two rows: brand, planet switch and status on top, the four resources in their own evenly spaced row below (full numbers again from 40rem up, short + tooltip on phones); "Rank" label back. Opaque bar, `--topbar-h` measured by `ui.js` (ResizeObserver) so the sticky side panel and fixed theme boxes sit below it.
- Fix: the player's theme is imported after `content.css` again (it wins ties, as in the original header); the order was reversed since the first UI commit.
- Shell tokens in `@layer base` so themes can set them: `--shell-bar-bg`, `--shell-side-bg`, `--shell-line`, `--nav-link-border/-radius/-align/-gap`, `--nav-icon-display`, `--brand-h`. MinimalNoir uses them for its classic look (black bars, boxed centred menu, terminal logo via `.topbar__brand img { content }`).
- Theme `.message-box` offsets follow the bar height (`calc(var(--topbar-h) + 0.5rem)`) instead of the old 6.25rem header.
- Side panel: more top padding below the bar, tighter menu items; permanent page scrollbar on the full game layout in every theme.

## 52. Compact build queue, one button component

- Build and research queues share `shared.buildqueue.html.twig`: one fixed-height row per entry (position, name/requeue, countdown, end time, cancel) plus free slots up to the queue limit (`queueSlots` = `max_elements_build`/`max_elements_tech`), so queueing or cancelling never moves the page; progress as a thin bar on the active row. `buildlist.js` reads the active name for the tab title again (dead selector).
- Buttons: one component in the `components` layer for all themes (`:where()` keeps it overridable by later components), signal-coloured buttons carry their colour on the border, `.selected` toggles, `.btn--danger`/`.btn--small`; no wrapping except build-list buttons.
- New theme tokens: `--btn-bg/-fg/-border/-hover-bg/-hover-border/-radius/-shadow/-selected`, `--progress-fill/-track`, `--nav-active-bg/-fg/-icon`; MinimalNoir (black, white, inverted active item) and pr0gress/community pr0gress (slate, green) use them.
- Translations: `ui_queue`, `ui_queue_free` (de, en).

## 53. SteemNova skin: one corner radius (0.25rem)

- `nova/theme.css`: all 19 radius declarations → 0.25rem (square corners stay square), shell tokens `--radius`, `--radius-sm`, `--btn-radius`, `--nav-link-radius` = 0.25rem, and the shared content corners (artwork `img`, nested tables, repair-dock and tutorial headers) overridden to match. Circles (status dot, planet switch) and count badges keep their shape.

## 54. Dead third-party scripts removed

- Google Analytics (legacy `ga.js`/`_gaq`, shut down by Google in 2023) removed from the game, account and login footers and the Symfony login layout, with its template variables, the admin config group (`use_google_analytics`, `google_analytics_key`) and the `se_*google*` admin translations; DB columns left in place.
- `templates/base.html.twig` no longer pulls idiomorph / frankenphp-hot-reload from a CDN.

## 55. Clean URLs

- All pages answer without file extension: `/game`, `/admin`, `/account`, `/CombatReport`, `/` (login), `/install/`, also under `/uniN/`. `#[GamePage]` and the entry routes match `/<area>{_ext}` (optional `.php`); `LegacyUrlRedirectListener` 301-redirects GET/HEAD on the former `*.php` URLs (query kept), POSTs to them are still served. Firewall pattern updated.
- Links rewritten token-aware in templates, page scripts, PHP and translations (584 lines): `game.php` → `game`, `index.php?…` → `./?…`, script identifiers (`GamePage`, `boot()`, `getEntryScript()`) and external links untouched. `HTTP::redirectTo()` accepts `./…`.
- `RuntimeContext`: `httpRoot` is the request's directory for both URL forms, `httpFile` the clean area name (`''` for the login), so the universe check and `redirectToUniverse()` keep working.

## 56. Admin backend overhaul

- Frameset (menu / top nav / content frames) replaced by one layout on every admin page: top bar (universe switcher, back to game, drop admin rights), grouped side menu with icons, ticket badge, live filter (`/` focuses, Enter opens), page title from the menu; drawer below 60rem. Menu data from `App\Controller\Admin\AdminShell` (one definition, filtered by rights), injected by `Template::adm_main()`; password prompt and quick editors render bare. `?page=index|menu|topnav` redirect to the overview, `?page=logout` returns to the game.
- `admin/admin.css` (on the shared tokens; old `admin/main.css`, boilerplate, nova theme, icon font and Google font no longer loaded): cards, notices, stat tiles, data tables (sticky header, sortable, row filter), form grid with visible help texts, switches, sticky save bar, toolbar, tabs, badges, pager, empty states. `admin/main.js`: menu filter, universe switch, `data-confirm`, unsaved-changes guard (`data-track-dirty`), `data-table-filter`. Quick editor opens in the modal dialog.
- All 60+ admin pages rebuilt for their task: dashboard (warnings, online/players/tickets/fleets, quick access, system and cron state), settings as section cards with jump list, search with structured results and windowed pager, data sheets, ban/creator/rights/reset/universe, cronjobs, modules as switches, logs with old→new diff, tickets as thread. Destructive actions confirm. 18 Lucide icons added to the sprite; ~60 admin translation keys (de, en).
- Fixes: search pager lost the filter (`fuki`/`fucki`) and delete guard for own/root account (`||` → `&&`); rank labels (`rank_N` never existed → `se_rank`); cronjob editor showed "range(0, 59)"; log player list SQL (`ORDER BYl.id`); news count always 1; destroyed-planets flag only checked the last planet; ban sort links lost their mode; broken `<form>` nesting in the rights pages; dashboard checked removed `cache/`/`includes/` instead of `var/`.

## 57. Clean URLs in stored content, admin checked in use

- Migrations `1794700800`/`1794700801`: stored messages and admin comments link the clean URLs (plain and URL-encoded `*.php` links rewritten); Hall of Fame links to the live universes use `/game` too. No `.php` page link left in templates, scripts, translations or the database.
- Fix: saving a module or clearing the cache crashed (503) when the stored `version` was empty (`Maintenance::clearCache()` falls back to `install/VERSION`).
- Admin verified in use (settings save round-trip, Enter = save, module switch, cronjob state toggle, search pager keeps filters, quick editor modal, confirmations, table filter; flying fleets with a live fleet). Flying-fleet table condensed (sender/target/time columns combined, icon actions) so it fits without horizontal scrolling; `.data--wrap-head` and `.break` helpers; comment cells break long URLs. No admin table overflows at 1440.

## 58. Admin fully on translations, destructive forms verified

- Every visible admin text comes from the Symfony translations (`translations/admin.{de,en}.yaml`): hard-coded labels, column headers, aria-labels/placeholders, inline-script texts and PHP strings ("Ja/Nein", info page, support categories, exception texts) moved to ~100 new `adm_*` keys. de/en key sets of admin, ingame, public and l18n are identical now (137 admin, 123 ingame, 15 public, 2 l18n English values added); tone reviewed (natural game language, proper names like "Hall of Fame" kept). English menu labels in Title Case, clearer menu groups (Players, Monitoring), "Logout" instead of "Drop admin rights", "Password Hash" instead of "MD5 generator".
- Icon sprite URL carries `?v=<mtime>` so new icons are not hidden by a cached sprite.
- Destructive admin forms exercised end to end with cleanup: ban/unban, planet create + delete, mass message, ticket answer + close, giveaway, universe reset (empty selection). Fix: the planet creator built `SET , name` without a field limit, so the planet name was never applied.

## 59. Universe reset works with a partial selection

- Fix: the universe reset read every option as `$_POST['x'] == 'on'`; unticked checkboxes are not posted, the "undefined key" warning became an error in the admin and the reset died (503) unless every option was ticked. All 21 options use `?? ''` now; same fix for the moon creator's diameter checkbox. Dead "reset error list" option (no handler) removed.
- Fix: ticket list status badges were empty (Twig macro without `LNG`).
- Verified: reset with a real selection (notes only → notes cleared, players/planets/messages intact), ticket list with several tickets, flying-fleet "send back" on a live fleet.

## 60. Compact admin menu with tabs

- Admin menu from ~40 to 21 entries: related pages share one entry and switch via tabs below the page title (`AdminShell` entries carry their tabs; rights per tab, an entry shows when any tab is allowed). Settings (Server, Universe, Planets, Modules), Expeditions (Card Deck, Findable Ships), Players (Players, Online, Planets, Moons, Active Planets, Advanced Search), Accounts (Info, Editor, Create, Swap), Moderation (Bans, Activations, Multi-IP, Comments, Analytics), Messages (List, Global Message, News), Permissions (Rights, Ranks); groups General, Community, Tools, System. The menu filter also matches tab names.
- New Maintenance page (Tools) with Clear Cache and Update Points as explained actions instead of action links in the menu; both lead back to it. Message pages only auto-redirect when a delay is set (a 0 s delay used to skip the result).
- Section jump list on the settings pages styled as chips, distinct from tabs.

## 61. Symfony Security for the whole game, leaner dependencies

- Login is Symfony Security: one stateful firewall over all routes, `LoginFormAuthenticator` handles the start-page login (bcrypt cost 13 via the password hasher, `PasswordUpgraderInterface`; failed-login counter code=1/4, verification link for not yet verified accounts, entry point code=3). `access_control` protects `/game`, `/account`, `/admin` (public battle report links `game?page=raport&raport=…` stay open). Logout via the firewall (`/logout`) and `GameAuth::logout()` (game/account logout, password change, account deletion); `GameLogoutListener` closes the game session and its row before the session is invalidated. Programmatic login after e-mail verification and installation (`GameAuth::login()`).
- The game session object (chosen player, planet, admin second factor) follows the Symfony login (`GameBootstrap::syncGameSession()`): new game session on a fresh login, logout after SESSION_LIFETIME idle, rewritten row after a session id renewal. The old login controller only redirects; `GameSessionAuthenticator`/`GameSessionReader` (stateless bridge) removed.
- Cron without worker: `bin/console app:cron:run` every minute from a system cron (README) or the DDEV loop daemon; symfony/messenger, symfony/scheduler, symfony/process (+ clock) removed, as is monolog-bundle (Symfony's built-in logger). PHP extension requirements trimmed to what is used (+ pdo_mysql).

## 62. Symfony profiler with a game database panel

- symfony/web-profiler-bundle (dev only): debug toolbar and profiler on every page, game pages included.
- Panel "Game DB" (`App\DataCollector\GameDatabaseCollector`): the game's queries run on the native PDO connection and are not seen by Doctrine's panel, so `Database` records SQL, parameters, type and duration in debug mode (`$debug` = `%kernel.debug%`, nothing recorded in prod); toolbar item with count and time, panel with repeated queries and the full list.
- DBAL for the game queries was measured and left out: +5–12 % per query (0.19 → 0.21 ms) without security gain (the game already binds values).

## 63. Universe in the top bar

- Game top bar shows the universe name with a globe icon next to the server clock (icon only below 68.75rem, name in the tooltip; hidden on phones with the clock); the footer drops the universe name and the server time and keeps game name and version.

## 64. Start page overhaul

- Login area on one layout (`login/layout.base.html.twig`, light/normal/popup extend it; main.header/navigation/footer removed); `css/login/login.css` replaces main.css/register.css/icon font: nova palette, CSS star field instead of background images, header nav with language select (no flag images), orange-bar panels, menu toggle on phones.
- Start page: hero with the game description, register/login buttons, plain universe start line with live countdown (`.uni-countdown`, own class so app/timers.js keeps off it) and the comic panel (`images/login/hero.webp`); link box (Discord, wiki, HoF, battle hall, screenshots, news) next to the login form; login errors as notice instead of alert(); meta description, Open Graph and JSON-LD in the head.
- Content pages (news, register, lost password, ban list, battle hall, team, rules, imprint, privacy, screenshots, HoF variants, error/redirect) re-marked without layout tables or inline styles; Symfony login layout on the same CSS.
- Screenshots regenerated from the current UI as WebP with thumbnails (6.6 MB → 0.6 MB), sorted by file name; old backgrounds, header image and flags deleted.

## 65. Start memes, compact start page, fresh-install wizard

- Start page memes from `public/memes/` (any webp/avif/jpg/png/gif; new files join automatically), one per hour in file name order (`IndexController::memes()`), alt text `home_meme_alt_<name>` with a generic fallback; the two new comics as WebP (6 MB JPEG → 273 KB), `hero.webp` moved there as `first-day.webp`.
- Start page in two columns: meme + link box left, welcome + login right (welcome and login first on phones); hero login button and the duplicate register button removed.
- Footer: `© Project Br0Game` with the default game name, else `© <name> on Project Br0Game`; `game_name` defaults to `Br0Game`.
- New global setting `default_theme` (admin select over the css themes, default nova) for new accounts, players and visitors (`Theme::resolveDefaultTheme()`).
- `{game_name}` placeholder in texts no longer recurses while the config loads (`ConfigRegistry::isLoaded()`, replacement deferred to the first read) — fixed the runaway memory of the CLI cron.
- Installer rebuilt for fresh installs: no `config/game.php` → `/` redirects to `/install/`, which opens without `var/ENABLE_INSTALL_TOOL` (created automatically, removed after the admin step). Steps licence → requirements (check list) → database form (connection, database, prefix, "create database", migration password) → connect with the form values, create the database, check the prefix is free, write `config/game.php` and `.env.local` (`DATABASE_URL` + `TABLE_PREFIX`) → build the tables task by task (tables, migrations, config, universe; JSON per task, `scripts/install/build.js`, `task=all` without JS) → game name (default Br0Game) + default theme → administrator → done. New `ins_layout` on the start page styles (`css/install/installer.css`), language from `?lang=`/cookie; upgrade mode unchanged.
- Installer texts rebranded to Br0Game (de/en; the historic "pr0game v1.7" upgrade note stays) and a credits strip below every installer step: UGamela → XNova → XG Proyecto → 2Moons → SteemNova → pr0game → Br0Game (credits as in README.md).
- Licence made consistent with `LICENSE` (GNU AGPL v3): installer licence step shows the LICENSE file (the bundled GPL v3 copy is gone), `composer.json` `AGPL-3.0-only` (was MIT), installer footer notes the AGPL; new global setting `source_url` (default the public fork) linked as "Source code" in the start page, Symfony login and game footers (empty hides it).

## 66. Board, mini CMS, team section, news overhaul, WebP images

- Shared content layer: `App\Content\HtmlContent` (symfony/html-sanitizer; `rich()` for pages/news, `basic()` for the board, `fromPlainText()`, `excerpt()`), native rich text editor `scripts/app/rte.js` (`<textarea data-rte="rich|basic">`, toolbar, paste cleanup, HTML source view) + `css/app/rte.css` (`.rich-text` for rendered content), loaded by the admin, account and login layouts.
- Board instead of the external forum: reddit-style board in the account area (`account?page=board`, login required, cross-universe, identity = account): posts with flairs, hot/new/top sorting, up/down votes via fetch (score adjusted by vote delta), threaded comments with collapse and inline reply/edit, own edit/soft delete, moderators pin/lock/delete, rate limits, CSRF token in the session. Tables `board_posts`, `board_comments`, `board_votes` (migration 1794787203). Old `?page=board` routes redirect there; board links in the game navigation, account top bar and start page header. `forum_url` stays only as the contact link in the ban message.
- Mini CMS: `pages` table (slug + language, migration 1794787200), `App\Content\ContentPages` with fallback (language → en → any → the previous built-in text), admin `admin?page=pages` with the editor and "reset to default"; contacts (`?page=disclamer`), data privacy (login + account) and rules (`/rules`) render from it.
- Team: own admin section `admin?page=team` (rows with name, position, contact, description, order; add/remove rows), table `team_members` (migration 1794787201), empty after installation; the hard-coded team table is gone.
- News overhaul (migration 1794787202): title up to 160 chars, summary, draft/published/scheduled (publish date), pinned, rich text (old news keep rendering as plain text via `format`), one image per news (uploaded, stored as WebP Q85 in `public/uploads/news/`, removed with the news); admin list with filters and badges, POST-only actions; public list with cards, pagination and single article view; "latest news" panel on the start page.
- Admin menu: new entry "Content" (pages, news, team).
- WebP for all static images: `App\Content\ImageProcessor` (GD, quality 85, animated GIF/WebP/PNG detected and left alone) and `bin/console app:images:webp` (copies `foo.png.webp` next to the originals when smaller; 2150 images, 53.7 MB → 12.7 MB); `public/.htaccess` serves the copy to browsers that accept WebP (`Vary: Accept`), URLs unchanged; the installer runs it as last build task; copies and uploads are gitignored.
- install.sql carries the new tables and the extended news table.

## 67. Templates out of legacy, native rendering, autoescaping

- Templates moved from `templates/legacy/<area>/` to `templates/{login,game,account,admin,install}/`; Twig namespaces (`@game`, `@adm`, …) replaced by plain paths (`admin/` instead of `adm/`), `twig.yaml` path mapping removed. Conventions in `docs/templates.md`.
- `App\Game\Core\Template` (Smarty-compatible wrapper: `assign_vars`, `show`, `fetch`, `.tpl` names, `extends:` chains, template dirs, caching no-ops) deleted. Controllers render through the `App\Controller\RendersPages` trait: `assign()`, `render($template): Response`, `display()` (throws the page for helpers), `addScript()`/`addInlineScript()`; page actions return their Response; area layout data comes from `layoutData()` in each base class; admin layout data from `AdminShell::pageData()`.
- `App\View\GameView` renders outside controllers (installer, admin/installer message page, spy reports, compiled-template clearing); `App\View\HtmlWhitespace` replaces the Smarty trimwhitespace output filter for stored report HTML.
- All 120 page controllers ported (`: Response` actions, admin `show()` returns the page, `$this->message()` for admin notices), unused imports and dead assigns removed, class docblocks rewritten in English; installer renders via `GameView`; unused install templates (`ins_convert`, `ins_header`, `ins_upgrade`) deleted.
- Autoescaping on for all game templates with the `game_html` strategy (`App\Twig\GameEscaping`, htmlspecialchars without double encoding, registered on every Twig environment by `App\Twig\EscaperConfigurator`): input stays encoded by `HTTP::_GP()`, raw values (old data, code-built strings) are now escaped — fixes stored XSS through names printed unescaped (player/planet names in statistics, player card, empire, admin lists).
- `LNG` is a Twig global (`App\Twig\LanguageView`, lazily bound to the request's language): translations are trusted `Twig\Markup`, nested lists wrapped the same way, missing keys print the key name (missing list entries print nothing); `format`/`sprintf`/`nl2br` filters are escape-aware; helper output is `is_safe: all`; `TemplateExtension` moved to `App\Twig`.
- Markup from code is explicit: `|raw` for stored HTML (messages, reports, CMS content), `Twig\Markup` for code-built HTML with escaped parts, translations formatted in templates instead of PHP (`LNG.key|format(...)`), JSON in scripts `|json_encode|raw`; `_GET`/`_POST` template variables replaced by `app.request`.
- Fixed on the way: admin message list overwrote the user's timezone (misplaced parenthesis), statistics update failure log was unreachable, notes page character counter still used jQuery, account privacy page rendered the login layout, lost-password form used a misspelled translation key (`passwordErrorUsernameEmpty`).
- Verified by a 140-page crawl diffed against the pre-port HTML (identical status codes; differences only escaped raw test data, URL `&amp;`, live data), a fresh install end to end, and ~40 browser-driven action flows (messages, notes, buddies, alliance lifecycle, settings, tickets, build/research/shipyard, fleet send/recall, simulator, account settings and board, registration errors, admin ban/creator/broadcast/news/pages/team/quick editor/account editor/rights/support/giveaway/stats) with `<b>x</b> & "q" 'z'` input shown literally and escaped once; FAQ answers, board bodies and admin/system message subjects marked as stored HTML.

## 68. GDPR privacy policy, clean content typography

- Default privacy policy rewritten for the GDPR (de/en; the other locales keep copying en/de): controller and contact, server logs, account/player data, in-game communication, fair-play checks (IP comparison, sessions, bans, per-account page log on suspicion, disposable-mail check), emails, optional Google reCAPTCHA (EU-U.S. DPF), strictly necessary cookies (`2Moons`, `lang`, `account`) and local storage, recipients and processors, storage periods, Art. 15–21/77 rights with the Art. 21 objection notice, no Art. 22 decisions, security.
- `ContentPages::privacyPolicy()` fills it from the live settings: `{game_name}`, `{contacts_url}`, `{contact_mail}`, `{deletion_days}`, `{inactive_days}`, `{message_days}`; optional blocks `<!--mail-->`, `<!--recaptcha-->`, `<!--inactive-->`, `<!--messages-->` drop out when the feature is off (replaces the positional `%s` template).
- Rules text cleaned in all locales (no list-item wrappers, `h2` sections, no inline colours; notice line as `.rules-notice`); game rules page uses the shared rich-text styles (rte.css now loaded in the game layout).
- Rich text typography calmer: 76ch line length, softer body colour, smaller headings with more space above, muted list markers, framed `details`; the login heading reset no longer flattens rich-text headings.
- Menus: the public header and the admin tabs mark the current page with a plain underline instead of a filled pill.

## 69. Inline CSS out of the templates

- New `utilities` cascade layer in `css/app/base.css` (alignment, widths, spacing, visibility helpers) that wins over themes and components; replaces one-off `style=""` attributes.
- About 500 static inline styles and 19 `<style>` blocks moved into the area stylesheets (`app/content.css` page sections, `admin.css`, `login.css`, `account.css`, `installer.css`) or onto utilities; PHP-built markup (fleet events, expedition messages, tutorial links, error page) uses classes. `shared.questions.styles` include removed.
- Inline styles left only where needed: data-driven values (category/signal colours, report grid columns, comment depth) and `display:none` on elements that JS toggles via `style.display`.
- Fixed on the way: combat report header colours (`color:colorPositive` was invalid CSS), `text-right!`-style Tailwind leftovers became real utilities (fleet cargo table now right-aligned), market and galaxy toggles use `hidden`.

## 70. Game logic bug sweep (docs/GAMEBUGS.md)

- Four read-only reviews (fleets/missions, combat, economy/queues, players/alliances/market/cron) plus end-to-end runs produced 79 findings, documented with evidence in `docs/GAMEBUGS.md`; all fixed and tested (oversized PHP-engine battles now fight on scaled counts instead of being cancelled, moon wrecks stay with the parent planet's dock by design, fusion burn at empty stock, player deletion cleans secondary tables).
- Critical: jump gate SQL injection through ship amounts (GB-01), repair dock double deploy race (GB-02), cleaner deleting every player when automatic deletion is disabled (GB-03).
- Fleets: ACS target/delay validation, hold limits, missile recall and moon interceptors, missiles had no element class (migration 1794787211), deliveries to destroyed planets, universe filters, trade parameter whitelist, step-3 locking, hostile missions return when the target is in vacation.
- Combat: repair rate/rounding capped and fair, memory limit parsing, consistent Rust rounding, honour loot tiers, per-user battle stats, ACS loot without resource creation, moon destruction RIP count and hall-of-fame galaxy, simulator input and report limits, report comment limit.
- Economy: research queue timing and resources, demolish duration, queue re-timing and removal, shipyard multi-type cost/limit checks, planet abandon queue handling, moon income on the shared config, techtree branches toggle again, planet name length.
- Players/alliances/market/cron: alliance rights (settings, kick, close, transfer, succession, diplomacy, applications), note and ticket ownership, market universe/self-buy/rollback, buddy and message validation, daily cron cleanup, stats export joins, registration settings copy, phalanx wording, language pack self-repair.

## 71. Performance pass (docs/PERFORMANCE.md)

- Benchmarked in prod mode (built-in server in the web container, OPcache, no profiler; per-page medians + `performance_schema` query counts) and on a seeded clone for the cron routines; results unchanged (crawl diff, side-by-side row comparison).
- Per request: config read once (`GameConfig` reuses `ConfigRegistry` rows), session/onlinetime/multi-IP writes throttled to changes or 30 s, ban-expiry update only when needed, migration scan cached, tutorial sync at most once a minute, alliance counters skipped without an alliance; game pages 40 → 28 queries, about -15 to -20 %.
- Pages: empire chart one query (65 → 1) and fleets one query, galaxy colonisation/noob checks batched; empire 50 → 26 ms, galaxy 36 → 25 ms.
- Routines: statistics cronjob 1560 → 213 ms (4375 → 66 queries; batched stat rows, record maxima per type), resource update about -50 % (shared wreck field lock, formulas compiled to closures instead of `eval`), fleet events -24 % (language packs reused).
- Indexes (migration 1794787218): `accounts_to_users`, tutorial tables, `users_to_messages (user_id, message_unread, deleted_at)`.
- README: production install with an authoritative class map and OPcache preloading (about -3 ms per request on top).

## 72. One Rust battle engine, Julia and FFI removed

- Rust engine was never loaded since the move to `src/Game/` (library path pointed to `src/bin/`, GB-80).
- `bin/battlerust`: standalone launcher (C, `tools/battlerust-cli/`, built in a throwaway Ubuntu 20.04 container by `build.sh`, so it needs only glibc 2.4 and runs wherever the library's glibc 2.30 is available) that loads `libbattlerust.so` and exchanges the battle JSON over stdin/stdout; the library's progress output is kept off the result stream.
- `BattleEngineRust` runs the launcher via `proc_open` (no FFI extension needed); engines are now Rust → PHP. Removed: `BattleEngineJulia`, `JuliaShipData` (+ its export/restart in maintenance), `juliaBattleEngine/`, the FFI call and `bin/libbattlerust.dll` (Windows).
- Benchmark (PERFORMANCE.md): Rust ≈ 40× faster than the PHP engine for 39k units, ≈ 110× for 600k, ≈ 32× for 3.4M (PHP on scaled counts); the launcher adds ≈ 2 ms per battle.
- composer.json: `ext-gd` required (news image uploads, WebP copies), `ext-openssl` moved to `suggest` (only PHPMailer's SMTP over TLS uses it); README requirement list updated.

## 73. In-game tables and forms

- `css/app/tables-forms.css` (layer `components`, loaded by the game layout): tables span the content width, cells left aligned with roles `.num` (right, tabular), `.ctr`, `.act`; `table.form-table` label/control rows with one shared control width; `.form-actions`, `.control-group`, `.control-wide`, `input.num`/`type=number` and `input.coord` widths; even control heights; `.table-scroll` for wide tables on phones. Applies to the content area and dialogs in every theme; documented in `docs/templates.md`.
- Fixed widths gone (`table519`/`table569` with `!important`, `width=` attributes, `w-80/90/95` on main tables); all in-game templates got column roles and form conversions (settings, fleet steps, fleet table, galaxy, information, resources, empire, market, simulator, battle hall, records, messages, alliance pages, tickets, notes, buddies, search, statistics, player card, …) without changing submitted fields or JS hooks.
- Phone check: no in-game page wider than a 390 px screen (was 5 of 50); wide tables scroll inside their wrapper, fleet/empire tables keep sticky first columns.
- Broken markup fixed on the way (galaxy tooltips, fleet step 1 ACS list, techtree, records, alliance info, achievements, simulator); in-game team page reads the admin team list (GB-81); alliance ranks removed with their alliance (GB-82).

- Themes are palette and decoration only: table/form layout declarations (cell and control padding/alignment, input widths, build-button sizes, `.table519` margins) and dead selectors (`#menu`, `.planetb*`, `.bulida`, `.buildb`) removed from all nine theme packages (401 lines); build buttons get one even width from `content.css`.
- Verified per theme: contact sheets of 12 pages × 9 themes (fresh screenshots); fixed what they showed (resources table now six columns throughout, simulator fleet/defence split 50/50 and top aligned via the new `.top` role). Jump gate ship list rendered with a temporary second gate moon (removed again); amount fields use 5rem on phones so "amount + max" fits. CSS cost measured: 6 KB / 1.9 KB gzip, style and layout times unchanged within noise.

- Messages page reworked (same oldschool look): category tiles in two even rows of seven (three columns on phones) with unread counts in bold and empty categories dimmed; one toolbar (search | favourites + grouping filters); bulk bar above and below the list with a new "select all" box, the action select and compact page links (no empty links for hidden pages); message headers share one column grid (type, sender, subject, date, tools) so the list reads like a table; unread messages get a coloured edge, single messages lose the double frame; "An:" replaced by the translated `mg_to`; new key `mg_select_all` in all locales.
- Bulk "delete unmarked" deleted the marked messages too and bulk actions dropped the page number (GB-83).
- In-game warning boxes (temporary admin rights, rebuild protection) printed black text on the dark shell background: old light-box rule removed from `content.css`, text and link colours set in `game.css`; dead `.globalWarning` rules removed from the pr0gress themes.
- Charts: Chart.js 4.4.7 (full UMD copy in `scripts/base/`, 206 KB) replaced by a reduced Chart.js 4.6.1 ES module (`public/scripts/lib/chart.js`, line + bar, 173 KB / 60 KB gzip; rebuild with `sh tools/chartjs/build.sh [version]`). The ~600 lines of inline chart code moved out of the templates into `scripts/app/charts.js` (theme colours, number format, tooltip helpers), `scripts/game/empire.js` (points + change charts, collapsible row groups via `data-toggle-rows`) and `scripts/game/alliance-chart.js`; templates only print the data as `<script type="application/json">`. Charts draw right away (the 500 ms start delay is gone); `shared.alliance.chart.script.html.twig` removed.


## 74. Public board, start page menu

- Board readable without login: `access_control` lets guests reach `account?page=board` (list and post view), `GameBootstrap::bootAccountGuest()` builds an account stub (id 0, cookie/browser language, server timezone, PUBLIC texts); `BoardController` sends guests to the login for every other mode (all writes, vote), guests get no CSRF token/session, disabled vote buttons and a "log in to post" hint instead of forms; `AbstractAccountController` keeps all other account pages login-only. Guests see the board in the start page frame (`account/layout.site` extends `login/layout.base`, new `body_class` block, Board marked current in the site nav); sprite icon `log-in` added. The account bar logo links to the start page.
- Start page menu on narrow screens: toggle moved to the right edge after the language select, menu opens as a full-width list with 44 px rows and a current-page marker, closes on Escape (focus back to the toggle), outside click and when the window grows past the breakpoint; brand text hidden below 24rem.
- Start page display font Michroma (OFL, self-hosted in `styles/resource/fonts/michroma/` with `OFL.txt`, preloaded): logo, chrome headings (`h1`–`h3` outside rich text and board post titles) and panel title bars; weight 400 only (`font-synthesis: none`), sizes toned down for the wide face. One subset woff2 (17 KB instead of 32 KB for Google's latin + latin-ext): Basic Latin, Latin-1, Polish/Turkish letters, Œœ Ÿ, dashes, quotes, ellipsis, €, ™ (unicode-range in `login.css`; built from Michroma-Regular.ttf with HarfBuzz subsetting, e.g. npm `subset-font`). Cyrillic falls back to the body font. Logo has no hover underline; phone header keeps brand, language and toggle in one row (globe icon hidden, select capped below 30rem; brand text hidden below 22rem). In-game unchanged.

## 75. Popups become modals (no iframes, no popup windows)

- `scripts/app/dialog.js` rewritten: `Dialog.open(url[, width])` fetches the page as a fragment (header `X-Game-Fragment: 1`) into one in-document `<dialog>` instead of an iframe; scripts of the fragment are re-created and run in order (`onPageInit` callbacks wait for them), forms submit and same-page links navigate inside the modal, a POST that redirects to another page follows with the whole window; `modal:load` event for page scripts that render per fragment. Height follows the content (old height arguments ignored). `Dialog.show()` also takes a `<template>` (cloned); `Dialog.alert()` is a themed message box with callback instead of `window.alert`.
- Server: `RuntimeContext::$ajax` is also set by the fragment header, so modal requests skip the planet resource tick and fleet event processing; `AbstractGameController::setWindow('popup')` becomes `ajax` in that mode, `game/layout.ajax` now outputs the page scripts, `{% block script %}` and inline code.
- Converted: info (`Dialog.info`), message write/forward, player card, buddy request, planet actions, alliance circular and diplomacy request, notes, spy report and phalanx (former `OpenPopup` windows, `OpenPopup()` now opens the modal), battle reports (links with `target="_blank"` in messages, battle hall, expedition texts open as modal in the game, modifier click still opens a tab), battle simulator result (former full screen `window.open`), admin quick editor (`opener.location` was broken in the iframe), rules link on the overview and data privacy in game navigation and account bar (`data-dialog`), alliance links in the statistics no longer target a named window.
- Achievements: descriptions are inline `<template>`s (`game/shared.achievementInfo.html.twig`, also on the player card); `AchievementsInfoController` and its template removed (no request at all).
- `parent.*`/`opener.*` calls replaced (same document now); per-fragment init: `raport.js` renders on page init and `modal:load` (first unrendered `#battle-statistics`), `message.js` spy report init on `modal:load`, overview actions tabs inline (ids prefixed `planet-actions-`); success/error messages in the modal flows use `Dialog.alert`.
- Theme: modal surface tokens `--modal-bg`, `--modal-border`, `--modal-fg` (defaults in `base.css`, set by all nine themes from their palette); the content inherits the theme's table, form and font styles because it lives in the page document. Close button follows the tokens; loading state with reduced-motion fallback.
- Left as is: external links (wiki, Discord, donations, GitHub), the building-34 YouTube easter egg, spy report links to fleet/simulator pages (new tab on purpose), public battle hall on the start page.
- Module cache busting: imported ES modules (`import './dialog.js'`) had no `?v=` and stayed in the browser cache (no Cache-Control, heuristic caching; `asset_version` = git commit ignores uncommitted edits), so old popup code kept running. `import_map()` (App\Twig\GameExtension) emits a `<script type="importmap">` mapping every file under `public/scripts/` to `?v=<mtime>`; included at the top of the game, account, login and admin layouts.
- Inline modals (`Dialog.show`): content goes into a scrolling `.modal__body` like fragment modals, the dialog keeps `overflow: visible` (close button on the edge was clipped and caused a horizontal scrollbar). Achievement description as picture + heading + text (`.achievement-info`) instead of a table that inherited the 42 % width of the tile name.

## 76. Achievements page overhaul

- Compact layout instead of building-style tiles (15rem tall, three per row; page height 3680 → ~1300 px desktop, 5160 → ~2250 px phone): header with "x of y unlocked" and progress bar, filter chips (all / unlocked / locked, inline onPageInit script, `data-ach-filter`), auto-fill tile grid (picture, name wrapping over up to two lines, unlock date or lock icon); locked tiles dimmed and greyscale. Tiles are buttons opening the description modal, which now also shows the unlock date or "not unlocked yet".
- `AchievementsController` passes one list (`id`, `unlocked`, formatted `date` via `php_dateformat`) without the not-implemented ones, plus counts. Styles in `game.css` (unlayered: the shared button component layer would otherwise restyle chips and tiles); accent `--ach-done` (default positive colour, nova: teal line). Keys `ach_progress`, `ach_filter_*`, `ach_locked`, `ach_unlocked_on` (de, en).

## 77. New messages notice only after login

- The "You have x new messages" banner on top of overview, buildings, research, shipyard, repair dock, tech tree and achievements is gone (`game/shared.messages.html.twig` and the `messages`/`message_type` assigns of seven controllers removed); the badge on the messages icon in the top bar shows the count.
- Instead a notice card under the top bar on the first full page after login (per player: `AbstractGameController::newMessagesNotice()`, flag `messages_notice_<userId>` in the Symfony session, which logout invalidates; link respects the "open message category" setting), closable (`[data-login-notice-close]` in ui.js), full width on phones, theme surface via the modal tokens. Key `ui_close` (de, en).

## 78. Battle simulator layout

- One toolbar row (add ACS slot, swap sides, share code input with load/share) instead of three; per slot a two-column grid (ships | technology, planetary defense and the defender's resources, the latter two in the first slot only, marked `data-first-slot-only` so share-code slot clones drop them); unit thumbnails from the theme (`gebaeude/<id>.gif`, removed when missing); per-column clear icons in the section headers instead of extra "reset" rows; sticky action bar with Calculate (disabled with the wait text while a simulation runs, 2 s cooldown) and reset; share/load messages via `Dialog.alert`. Field names unchanged (controller and share codes untouched). Page height ~1500 → ~1000 px.
- Layout only in `game.css` (`.sim`, unlayered): tables, inputs and buttons keep the theme's look. Keys `bs_swap`, `bs_share_code` (de, en).

## 79. Bots

- Optional bot players per universe (uni settings category `bots`: `bots_enabled` default off, `bots_max_count`, `bots_ticks_per_run`, `bots_llm_enabled`, `bots_llm_daily_limit`). Game logic in `src/Game/Bots/` (container services, `App\Game\Bots\` block in services.yaml): `BotEngine` (sessions by profile activity per server hour, shift rotation), `BotBrain` (scored candidates × profile weight × per-bot personality × noise, skill-weighted top-3 pick, mistake roll that shrinks with skill), `BotActions` (buildings, research, shipyard, defense, expeditions, colonisation with the same rules/costs/queue limits as the controllers), `BotSpawner`, `BotRegistry`, `BotLog`.
- LLM only for the human layer (`BotPersona`: nickname, persona, planet names; `BotSocial`: replies to private messages, buddy requests, alliance apply/found/accept, neighbour greetings), daily call budget per bot, local fallbacks without a key. `Llm\LlmClientFactory`/`LlmClient`: OpenAI, Claude, OpenCode Go, DeepSeek, OpenRouter (default base URLs, `max_completion_tokens` for OpenAI, `x-opencode-session` for OpenCode Go, stored key never sent to another base URL).
- Admin page `admin?page=bots` (`BotsController`, `admin/BotsPage.html.twig`, menu entry `adm_nav_bots` with tabs providers/profiles/bots): provider keys/models/base URLs with a test-connection button (JSON), profile CRUD/duplicate/restore defaults, spawn/list/remove bots, action log; session CSRF token on all POSTs.
- Tables `bot_llm_providers`, `bot_profiles` (5 defaults: office worker, jobless, shift worker, lunatic, noob), `bot_players`, `bot_log`, cronjob `bots` (every minute, `BotCronjob` ticks each universe with bots enabled) — migration 1794900000 + install.sql. Inactive mails skip bots.
- Fix: `ConfigFieldProcessor::processPostData()` skips `TYPE_NONE` fields, so saving the settings no longer resets `last_*_pos`/`users_amount`.

## 80. Admin information page

- Rebuilt `admin?page=information` on `App\Game\Core\ServerInfo` (read-only, via `GameUtilities::serverInfo()`). It shows:
  - health checks: PHP, extensions, OPcache, env/debug, display_errors, writable dirs, install tool, pending migrations, Rust engine, cron, HTTPS, mail, session handler;
  - server facts: OS, limits, CPU, load, uptime, disk/memory/OPcache meters;
  - database facts: size, top tables, migrations, MySQL status;
  - game statistics per universe plus totals, and a 30-day registration bar chart;
  - logs/caches: errors in the last 24 h, read from at most 2 MB of the log tail;
  - a copy-as-text block without secrets.
- Keys `adm_info_*` (de, en); admin.css block for the health list, meters, bar chart and log tail. Median ~80 ms.

## 81. Br0Game branding and version scheme

- Game name and links point to Br0Game / github.com/Voidys/Br0Game. This covers the admin bug-report text in all locales, installer texts, FAQ, the dashboard project link, error reports, user agents, dump file names (`br0gameBackup_`), composer metadata (`voidys/br0game`) and the DDEV project `br0game`. Credits, license headers, historic Hall of Fame entries and pr0game wiki links are kept.
- Defaults: `source_url` = repository, `git_issues_link` = GitHub issues. Migration 1794900100 fixes existing installs that still have empty, placeholder or Codeberg values.
- Version: `install/VERSION` is the single source, year scheme `1.<yy>.<patch>` (start: 1.26.0). `Maintenance::clearCache()` copies it into the config instead of forcing `<major>.<minor>.git`.

## 82. Easy install

- Setup wizard for non-technical users:
  - database step: defaults; prefix and migration password under "Advanced", the password generated and shown once; plain-language connection errors;
  - game step: language, timezone, universe name, speed preset (classic 1×, fast 3×, blitz 10×), registration, bots 0–50, mail off by default;
  - admin step: password confirmation, minimum length 8;
  - final checklist: login, cron line, lock, HTTPS, docs.
- Shared steps live in `App\Runtime\InstallService`. It generates `APP_SECRET`, sets prod mode outside DDEV, and writes `.env.local` and `config/game.php` with mode 0640.
- `bin/console app:install`: the same steps from the CLI, interactive or `--no-interaction`. It refuses on an existing install.
- `install.sh`: interactive or flag-driven. Modes: DDEV trial, server install, requirements check. It verifies the Composer installer signature, offers `--dry-run`, never runs sudo itself, and updates existing checkouts.
- `tools/release/build.sh`: release ZIP with vendor, prod `.env` and no `.env.local`, plus a `.sha256` file. `.github/workflows/release.yml` builds and attaches both on `v*` tags. `docs/INSTALL.md` covers the ZIP, script and DDEV paths, checksum verification, docroot `public/`, cron, updates, a security checklist and troubleshooting.

## 83. Shared theme artwork, WebP images

- `App\Game\Core\ThemeAssets` + `theme_asset()` (Twig) / `Theme::asset()` (PHP): theme image lookup = theme folder, the `assetSets` declared in its `style.cfg`, `styles/resource/theme-shared/common/`; each folder tries the exact path, then `.webp`. Per-theme map cached in `var/game/theme-assets-<theme>.php`, rebuilt when a folder mtime changes. All `{{ dpath }}…` image paths in templates, `GalaxyRows`, `GalaxyController`, `FleetDealerController` and the message/trader JS go through it (JS gets resolved URLs). Docs: `docs/themes.md`.
- Theme artwork stored once: `theme-shared/common` (identical in all/most themes), `theme-shared/sets/nebula` (DarkNebula, MinimalNoir, community, community pr0gress), `sets/classic` (nova, EpicBlueXIII, gow). 3193 duplicate files removed from the theme folders, byte-identical resolution verified for 4313 theme x path pairs.
- `tools/images/convert-theme-images.sh`: shipped JPG/PNG/GIF to WebP q90 (animated GIFs stay animated), size limits per category, originals deleted when the WebP is smaller; `tools/images/dedupe-theme-assets.php`: the dedupe step, with `--apply` and a built-in hash verification.
- `theme.css` url()s and the few hard-coded resource image references point to the `.webp` files.

## 84. Br0Game theme and content layout

- New theme `public/styles/theme/br0game/` ("Br0Game"). It is the default for new installs and players (`DEFAULT_THEME`, `default_theme`, install.sql). Existing players keep their theme. Palette, contrast values and rules are in DESIGN.md.
  - Look: a dark green-tinted CSS ground, layered surfaces, an emerald accent for state only, themed scrollbars/selection/caret/focus, and tabular numbers.
  - Artwork comes from the `nebula` asset set.
- Theme hooks (fallbacks keep every other theme unchanged):
  - signal colours and overview mission colours become CSS custom properties, emitted inline only when the player changed the factory default;
  - `--galaxy-pos-*` for galaxy position states, `--c-accent-ink`, `--shell-bar-shadow`.
- Content-area layout (shared, all themes):
  - build lists (buildings, research, hangar, defense, repair dock) as a title bar + artwork/requirements + a fixed action column (~25–30 % shorter, button always in the same place); one column with a thumbnail on phones;
  - empty queue slots collapsed into one row; build filters as a segmented control (no `|` separators);
  - overview status and planet in one row, planets/moons as an auto-fill grid;
  - galaxy navigation as a compact toolbar with chevron buttons (same fields);
  - fleet Continue rows sticky at the viewport bottom; an empty fleet table takes one row;
  - message categories as uniform chips (colour dot, count, full labels on desktop); neutral message rows with unread = raised + bold; row actions as Lucide icons (`star`, `reply`, `forward`, `chevron-up` added to the sprite).
- Shell (top bar, side panel, drawer, footer) structurally unchanged.

## 85. Legacy cleanup (phase 1a)
- Removed local leftovers of the old development branch (untracked `cache/`, `includes/`, `juliaBattleEngine/`, `stats_Universe_1.json`); the game cache lives in `var/game/`.
- Removed unreferenced images: `fancybox/`, `facebook/`, `battlesim/` (art), `banned.png`, `banner.jpg`, `meta.png`, `steem.png`, `pr0game_old.gif`, `icons/closed.png`, `icons/open.png` (+ generated `.webp` copies).
- `galaxy.js`: the userscript-style `GM_getValue`/`GM_setValue` shim became `galaxyStore.get/set` (same `<uniN>_<key>` localStorage keys and JSON format, storage errors handled).
- Removed 8 never-rendered templates (admin officiers editor, mod version, vertify x2, admin ticket create, alliance rename name/tag, login rules.default) and unused `HTTP::getPageResponse()`, `Theme::getTemplatePath()/isCustomTPL()`, `MarketPlaceController::checkTechs()`.

## 86. Test foundation (phase 1b)
- PHPUnit 13 as dev dependency (`phpunit.dist.xml`, `tests/bootstrap.php`, `composer test` / `composer test:unit`, autoload-dev `App\Tests\`).
- `tests/Unit` (no database): `HTTP::_GP` casting/encoding, `PlayerUtil::isNameValid` (player + planet names), fleet string format, reference-ratio format.
- `tests/Integration` (kernel + game booted like `bin/console`, dedicated database `db_test`): the schema is rebuilt per run by `tests/Support/setup-database.php` from `install.sql` + migrations + default config through `InstallService::runBuildTask`; every test runs in a transaction that is rolled back; refuses any database whose name does not end in `_test`.
- Golden values captured from the pre-renewal code (`GOLDEN_CAPTURE=1`, see `tests/Support/GoldenAssertions.php`): `BuildFunctions` prices/times/max constructible/catch-up, `ResourceUpdate` production, energy, storage, eco hash and every `ProdGrid` formula (the `eval()` ones), build/research/shipyard queue structures (serialize format) and their progression, `FleetFunctions` distance/duration/consumption/speed/room/ranges, market conversion + push detection, expedition value and findable ships.
- Battle: PHP engine seeded (`mt_srand`) golden fights, fights decided without luck identical in the PHP and Rust engines, invariants for both, statistical agreement of Rust vs PHP (the Rust engine has no seed).
- `config/packages/doctrine.yaml`: removed the `when@test` `dbname_suffix` (the test database is `db_test` via `.env.test`).
- CI job `tests` (PHP 8.4, MySQL 8.4 service, `db_test`).

## 87. HttpClient, no `@`, zero PHPUnit warnings (phase 2c)
- `curl_*` replaced by `symfony/http-client` (`HttpClientInterface`, injected): `LlmClient` (+ `LlmClientFactory`; POST, 10 s idle / 30 s total, no redirects, same headers, errors still `HTTP <status>: <message>` with the key masked), `Discord` (optional constructor argument, default client; failures swallowed as before), `DisposableEmailDomainsCronjob` (https only incl. redirects, 10 MB cap, same user agent), reCAPTCHA verification in `GameBootstrap`, multi-universe probe in `UniverseController` (no redirects, TLS check off only for localhost).
- New `App\Runtime\SafeFile` (read/mtime/write/mkdir/remove/touch/chmod/imageSize on `symfony/filesystem`): checks first, atomic writes with the final permissions (secret files never world-readable), real failures go to the error log; all file-system `@` suppressions (Maintenance, ThemeAssets, ImageProcessor, ServerInfo, installer, GameView, RuntimeContext, news/info controllers, ...) replaced; `@` on `ini_set`/`set_time_limit`/`date_default_timezone_get`/`sys_getloadavg`/`opcache_get_status`/`disk_*_space` dropped; the forbidden-text regex check of `BotSocial` swallows the PCRE warning in one scoped handler.
- `BattleEngineRust::parseRustJson`: statistics/loss accumulators initialised instead of `+=` on missing keys (results unchanged).
- Tests: `LlmClientTest` (request shape per provider, `max_completion_tokens` for openai, `x-opencode-session`, error mapping, key masking), `DiscordTest` (payload, failing webhook); `phpunit.dist.xml`: `failOnWarning=true`, details displayed.

## 88. No unserialize, no eval (phase 2a)
- New `App\Game\Core\StoredData` (`encode`/`decode`/`tryDecode`): structured DB columns are JSON (`JSON_THROW_ON_ERROR`-style, zero fraction preserved, assoc arrays); `decode` also reads legacy `serialize()` rows (`allowed_classes: false`, arrays only). It is the only `unserialize()` call left in `src/`.
- Columns converted: `planets.b_building_id/b_hangar_id`, `users.b_tech_queue/rights`, `planet_wreckfield.repair_order`, `log.data`, `raports.raport` (PHP-engine reports; Rust reports were already compressed JSON). All writers (build/research/shipyard/repairdock controllers, ResourceUpdate, PlayerUtil, BotActions, Log, admin rights, battle report writers) call `StoredData::encode`.
- Migration `migration_1794900200.php` + `App\Game\Core\StoredDataMigrator` (PDO, no game bootstrap, runs from the web upgrader and CLI): chunked, idempotent (only `a:<n>:{` rows), each row verified (JSON decodes to the identical structure) and left untouched when damaged/with objects/too long. No column widening needed (JSON is shorter than serialize).
- Cache files (`var/game/cache.*.php`, language packs) are `<?php return [...];` via `App\Game\Cache\Resource\PhpArrayFile` (atomic write, `include`, opcache); files in the old serialized format count as missing and are rebuilt once.
- Game session in the Symfony session: array (`Session::toArray/fromArray`) instead of a serialized object; sessions written before are still read once (`StoredData::decodeLegacySession`, `allowed_classes: [Session]`), so players stay logged in.
- `eval()` removed: `App\Game\Core\FormulaEvaluator` (tokenizer + recursive descent, closures compiled once per process, whitelist: $BuildLevel/$BuildTemp/$BuildEnergy/$BuildLevelFactor, abs/ceil/floor/round/sqrt/pow/min/max/log/exp, + - * / % **, unary +/-, parentheses, trailing ";") used by `ResourceUpdate`, `ResourcesController`, `InformationController`, `BuildingsController`; `ResourceUpdate::getProd` dropped. Results bit-identical (ProdGrid goldens unchanged).
- Tests: `FormulaEvaluatorTest`, `StoredDataTest`, `StoredDataMigratorTest`; `QueueTest` goldens compare the serialize() of the decoded queue (structure unchanged), plus a JSON-format and a legacy-input case.

## 89. Web hardening (phase 2b)
- CSRF everywhere, one mechanism: `framework.csrf_protection` + `App\Security\CsrfPolicy` + `App\EventListener\CsrfListener` (kernel.request, priority 16: after routing, before the firewall, so login/registration/lost password are covered). Every POST/PUT/PATCH/DELETE to a game controller (areas index/game/account/admin) needs the session token (header `X-CSRF-Token`, field/query `_csrf`); a few state-changing GETs are listed in `CsrfPolicy::GET_RULES` (token required too). Failure: 403, JSON for scripts (header present, `X-Requested-With`, `Accept: application/json`, `ajax=1`), a small page otherwise (`csrf_invalid`, de/en). A valid `_csrf` is removed from the request before game code sees it.
- Front end: `public/scripts/app/csrf.js` (imported by `app/main.js` and `login/main.js`): hidden `_csrf` field on submit/`formdata`/`form.submit()`, `X-CSRF-Token` header on every same-origin non-GET `fetch`, `_csrf` query on `sendBeacon`, links with `data-method="post"` submit an invisible POST form (confirm handlers run first). Twig: `csrf_meta()` in all layout heads, `csrf_token()`, `csrf_field()` (`App\Twig\CsrfExtension`).
- State-changing GETs turned into POST: all `getJSON`/`getText` callers that changed state (fleetAjax x4, jump gate, missile destroy, planet rename, message delete/favourite, rebuild protection, admin drop-rights, ACS rename, player card block toggle, diplomacy create) and ~40 action links (logout x3, buddy accept/delete, alliance diplomacy/kick/rank delete, tutorial, admin cronjob/active/universe/flying fleet/multi-IP/maintenance, module switch is now a form). Phalanx popups carry the token in the URL.
- Foreign navigation guard: a top-level cross-site navigation (`Sec-Fetch-Site: cross-site`) into an authenticated game/account/admin page is answered with 303 to the area's start page (battle reports, board and wiki login excepted), so a forgotten GET writer cannot be driven by a link.
- Admin `sid=<session id>` URL parameter removed (Universe, Reset, Rights, AdminShell, templates); the central token replaces it. Admin password prompt: POST body only (was GET too), 5 attempts per 15 min per IP+account, session id renewed when access is granted.
- Client IP: `SessionManager::getClientIp()` = `Request::getClientIp()`, validated (`UNKNOWN` otherwise); `framework.trusted_proxies`/`trusted_headers` from env `SYMFONY_TRUSTED_PROXIES` (default none). Client-IP/X-Forwarded-* headers of untrusted peers are never read.
- Sessions: `cookie_samesite: lax` (httponly, secure auto and strict mode were already on); id migrated by Symfony on login and logout, explicitly on admin elevation (`SessionManager::regenerateId()`); `SessionManager::revokeAccountSessions()` ends all game sessions of an account on password change/reset.
- Throttling (`symfony/rate-limiter`): `login_throttling` on the main firewall (5 per 15 min per IP+e-mail, `?code=4`, text changed), limiters `registration`, `lost_password`, `admin_elevation` (`App\Security\AuthRateLimits`). Login failures answer `?code=1` for known and unknown accounts alike (per-account `code=4` removed); lost password answers identically for unknown/known/recent requests and only works with working mail (no on-screen reset link).
- Tokens: reset/verification keys and generated passwords from `random_bytes` (`getRandomString()`, lost password, registration, battle simulator report id); reset links expire after 30 min (existing), verification links after 3 days (checked on use); mail links built from `DEFAULT_URI` (`App\Runtime\CanonicalUrl`), not the Host header.
- `SecurityHeadersListener`: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy` (no CSP yet).
- Tests: `CsrfListenerTest` (valid/invalid/missing token, ajax JSON, GET rules, foreign navigation), `ClientIpTest` (trusted proxies, garbage headers).

## 90. Remaining audit fixes

- Alliance website/image links: only absolute http(s) URLs are stored and rendered (`AllianceController::httpUrlOrEmpty()`). Migration 1794900300 clears stored `javascript:`/`data:` values (audit XSS-1).
- Error page: exception message, file, URL and trace go into the HTML comment only in debug mode (`ErrorReporter` gets `%kernel.debug%`), escaped and with `--` neutralised (audit AUTH-10).

## 91. Translations completed (phase 3c)

- All locales (es, fr, pl, pt, ru, tr, pi, of) now carry every key of `en`/`de` in every domain (about 6800 keys added: admin, install, ingame, public, account, tech, faq, tutorial, fleet); es/fr/pl/pt/ru/tr translated, pi/of take the German text where no dialect text exists.
- Stale duplicate key `shortDescription.231'` (stray apostrophe) removed from `tech.de.yaml`.

## 92. Non-game frontend without inline code (phase 3b)

- Admin, account, login and installer templates have no `on*=` handlers, `javascript:` links or inline scripts left (CSP `script-src 'self' 'nonce'` clean); only JSON data islands and module scripts remain.
- New `templates/shared/page_config.html.twig` + `scripts/page/config.js`: server clock, language strings and flags as a JSON island (`#page-config`), published as the globals the shared modules read; `readPageData(id)` for per-page islands (`#login-config`, `#bots-config`, `#config-uni-data`, `#captcha-config`).
- Admin behaviour by data attributes in `scripts/admin/main.js` (`data-open-edit`, `-autosubmit`, `-set-checked`, `-check-all`, `-count-chars`, `-toggle-rows`, `-reveal`, `-goto-page`), `filterSelect.js` (replaces the 2003 `filterlist.js`), `ajaxForm.js` (`data-ajax-dialog`); one page module per complex page in `scripts/admin/pages/` (configUni incl. former `Percentslider.js`, bots, team, dump, reshuffle, sendMessages, active, tickets, createTestUser).
- Confirm questions: `data-confirm` sits on the submit button / link (asked once by `app/actions.js`), no longer on forms; admin fills the empty generic text. Removed duplicate confirm code from `admin/main.js` and `account/board.js`.
- Login: `[data-autosubmit]` selects, time zone preselection and `LoginConfig` moved into `login/main.js`/island; dead `Login.setLanguage`, `loginError`, `toggleBlock`, `execscript` (non-game layouts) removed. Installer language select and the four legacy table pages (update, doupdate, migration, message) now use `ins_layout`; `scripts/install/main.js`.
- Account popup layout: reCAPTCHA loader is `scripts/account/recaptcha.js` (needs `https://www.google.com` in `script-src`/`connect-src`/`frame-src` while it exists); `ErrorReporter` page lost its dead inline script.

## 93. Content-Security-Policy, no inline handlers in the game (phase 3a)

- `Content-Security-Policy` on the HTML of every area (`App\Security\ContentSecurityPolicy`, `EventListener\CspListener`): `default-src 'self'`, `script-src 'self' 'nonce-…'`, inline styles allowed (style="" attributes, signal colour `<style>`), `img-src 'self' data: https:` (alliance logos/avatars), `connect-src/font-src 'self'`, `frame-ancestors/base-uri/form-action 'self'`, `object-src 'none'`. Per-request nonce (`CspNonce`, Twig `csp_nonce()`; the importmap is the only inline script).
- `CSP_MODE=enforce|report|off` (default enforce; areas in `app.csp.enforced_areas`, all five by default, the rest report-only); violations go to `POST /csp-report` (`CspReportController`, rate limited 30/min per IP) and `var/log/csp.log`.
- reCAPTCHA sources (google.com, gstatic.com in script/frame/connect-src) are opened only for pages that call `csp_allow('recaptcha')` (game and account layouts when a key is configured).
- Game templates: 0 `on*=` attributes (was 198 in 46 files), 0 `javascript:` links (was 26), 0 inline `<script>` (was 34 files). Behaviour by data attributes and one delegated listener per event type (`public/scripts/app/actions.js`: `data-action`, `data-on-change|input|keyup|focus|blur|submit|error`, `data-confirm`, `registerActions()`); built-in actions for dialogs, popups, auto-submit selects, collapsible rows, char counters, row filters, fill-input.
- Template data reaches modules as JSON data islands (`data_island(id, data)`, JSON_HEX_* escaped; `pageData(id)` in `app/core.js`); layout clock/language values in `#game-env` (`app/env.js`), resource tickers in `#resource-tickers`, reCAPTCHA loader `app/recaptcha.js`. `execscript`/`addInlineScript` no longer used by the game (4 callers replaced).
- New page modules: settings, alliance, acs, achievements, techtree, tutorial, playercard, logout; `var` -> `let` in app and game scripts. Dead scripts removed: `trader.js`, `vertify.js`, `install.js`.
- Stored message texts and old translation strings with `onclick="Dialog.Playercard(…)"` keep working (legacy shim reads the blocked attribute); PHP-built links (fleet table, tutorial link, ticket back button) use data attributes.
- Fixes found on the way: receiver search list of the write dialog never became visible and went stale on the second open; galaxy `annotatehover` crashed on a tooltip-less cell; alliance "leave" link lacked `data-method="post"`; phalanx/overview-actions/message modules now re-init on every modal load.
- Translations: the two alliance message texts (`al_leaving_msg`, `al_new_apply`) in all ten `ingame.*.yaml` now use `data-action="dialog-playercard" data-id="%s" data-name="%s"` (same three placeholders). Old stored messages are rewritten when shown by the Twig filter `legacy_markup` (no CSP report); `actions.js` keeps a click fallback. `javascript:history.back()` buttons (ticket, login/account register) became `data-action="history-back"`. No `on*=` or `javascript:` is left in `src`, `translations` or `templates/texts`.
- Layout tables: fleet step 1 submit row (`div.form-actions`), fleet step 2 mission choice (`div.mission-list`), repair dock and shipyard info boxes (`p#infobox`), alliance chart wrapper (plain `div`). Header-strip tables (custom fleets toggle, alliance "back") stay tables: as buttons/divs they lost the theme's header look.

## 94. PlayerUtil split (phase 4b1)

- `PlayerUtil` (2827 lines) is a facade of 406 lines; same public methods and signatures, each delegates to one of 11 services in `App\Game\Core\Player\*` (built through `GameUtilities`, same `Database`/`ConfigRegistry`/`Singleton` constructor): `PlanetPositions` (free slot, random/block/linear position strategies, astrophysics limits), `PlanetManager` (create planet/moon, delete planet, clear), `PlanetReshuffle` (admin reshuffle), `PlanetQueries` (main planet, planet list, field limit), `PlayerRegistration` (create player, name validation), `PlayerDeletion` (delete player, alliance and buddy cleanup), `PlayerMessenger` (send/forward messages), `VacationMode`, `PlayerLookup` (colours, stb, buddy/block status, auth level, language), `ProtectionRules` (catch-up, noob/strong/outlaw/rebuild protection, status), `HonorPoints`, `PlayerCharts`.
- Long methods shortened (createPlayer and deletePlayer are ~85 lines only because of their 16-parameter signature and flat delete sequence): `createPlayer`, `createPlanet`, `createMoon`, `deletePlayer`, `removeFromAlliance`, `sendMessage`, `disable_vmode`, `randomHP`, `calculate_honorpoints_multi`, `checkNoobProtection`, `reshuffleAllPlanets` split into private steps; default colour table is a constant. SQL, order of queries and random calls unchanged. All public methods and non-trivial private methods have docblocks.
- New `PlayerLifecycleTest` (colony/moon, position rules, messages, vacation round trip, protection rules, player deletion).

## 95. AllianceController split (phase 4b2)

- `AllianceController` (2631 lines, 33 handlers with SQL and rules inline) is the HTTP adapter only (672 lines): same `#[GamePage]` routes, modes, `?action=` admin dispatch, request parameters and template variables; the domain logic moved into `src/Game/Alliance/` (namespace `App\Game\Alliance`, reached through `AllianceServices`, built with the controller's db/configs/state like the `GameUtilities` objects).
- Services: `AllianceRepository` (alliance/rank/member/receiver queries, `rightsOf()`), `AllianceMembershipService` (found, leave, kick, transfer, disband), `AllianceApplicationService` (search, apply, withdraw, list/detail, accept/decline with the transaction), `AllianceRankService` (permission page, create/update/delete ranks, assign ranks, `AllianceRights::canHandOut()` replaces five copies of the rights loop), `AllianceDiplomacyService` (relations, overview, create/accept/reject/delete, scoping checks of GB-10/12/30/31/34), `AllianceSettingsService` (admin overview save), `AllianceMessenger` (application/leave notices, circular, per-language diplomacy mails: one grouping loop instead of five), `AllianceMemberLists` (member, main/wing and admin lists from one row builder instead of three copies), `AllianceStatistics`, `AlliancePages` (info/home template variables), `AllianceText` (`html()`, `multiline()`, `httpUrlOrEmpty()`), `AllianceRights` (right names).
- Early exits (rights, rule violations, message pages, JSON errors) are thrown as `AllianceException` by the services and turned into redirect/message/JSON by `AllianceController::exitWith()`; services never end the request.
- Fixed on the way (both covered by `AllianceServicesTest`): applying to a non-existing alliance id no longer reads array offsets of `false` (it redirects to the alliance home instead of answering "alliance full"); the admin member list no longer fatals for a leader/member without a statistics row (`+= false`), it uses the 0-points fallback of the other lists.
- Circular dialog: the rank select now lists the alliance's ranks by their real rank id (`AllianceRepository::rankNames()`, same data as the rank admin page); the send side already filtered receivers by `ally_rank_id` (it never worked before because the select only offered "all players").
- Diplomacy accept/delete/war messages: the two receiver lists were merged with array `+` (by index); they are now merged with `array_merge` and de-duplicated by user id, so every member of both alliances gets exactly one message.
- New `AllianceServicesTest` (35 tests): founding and name rules, apply/withdraw/accept/decline/full/elsewhere, rank rights (grant/update/assign limited to held rights, leader never ranked, scoping to the own alliance), kick rules (leader, self, foreigner, higher rights), transfer, leave, disband, diplomacy scoping and peace flow, circular, member lists, settings, link sanitising. Verified against the old controller: 23 alliance GET pages render byte-identical (rich data set), 64-step HTTP flow (create to disband) passes.

## 96. FleetFunctions and ResourceUpdate split (phase 4b3)

- `FleetFunctions` (1260 lines) is a facade (same public signatures, `$allowedSpeed` stays there) over seven services: `FleetMath` (distance, speed, duration, consumption, room, slots, missile/phalanx range), `FleetString` ("id,count;" format), `FleetMissionRules` (available missions, stay blocks, phalanx rules), `BashRules`, `FleetLauncher` (`sendFleet`, `sendFleetBack`, `getCurrentFleets`), `AcsService` (group timing), `FleetStatistics` (max factors, total fleet state).
- `sendFleet()` split into planet cargo update, flight insert, event insert and fleet log insert (one shared parameter array); `sendFleetBack()` into load/recall/mark-returning; `getAvailableMissions()` into pirate base / recycling / planet mission methods. SQL and parameters unchanged.
- `ResourceUpdate` (1239 -> 280 lines) is the orchestrator (order: shipyard, repair job, wreckfield, building/research completions by end time, production, save). It delegates to `ResourceProduction` (eco hash, `rebuild()`, `produce()`, research network level; formulas via `FormulaEvaluator`), `ResourcePersistence` (planet save with achievements/stats, resource and queue updates), `WreckfieldMaintenance` (72 h repair deployment and wreckfield expiry), `QueueFailureNotifier` (queue failure messages) and the queue state machines `HangarQueueProcessor`, `BuildingQueueProcessor`, `ResearchQueueProcessor`, which work on a shared `QueueState` (user/planet rows, built deltas, target time, stock-update callback; same copy semantics as the old properties). Public `ResourceUpdate` API unchanged.
- Research queue "not progressing" investigated: no bug. A planet whose `last_update` lies in the future (my test had set a queue end time ahead of `b_building`, which pushed `last_update` forward) is skipped entirely, queues included; the original code (a64f9ce5) has the same `ProductionTime > 0` gate. Research completes on any page load of its owner, also on another planet than `b_tech_planet`; documented in the `ResourceUpdate` docblock and pinned by two tests.
- `ResourceProductionTest` reflects `ResourceProduction::runFormula()` now; all other goldens unchanged.

## 97. Statbuilder, marketplace, messages, expedition, account editor, creator split (phase 4b4)

- `Statbuilder` (987 lines) is a facade (same public methods) over `App\Game\Statistics`: `StatDataLoader` (input queries), `StatScores` (research/building/defense/fleet formulas), `StatPointsWriter` (batched upserts), `StatRanker` (category ranks, honor ranks, honor titles), `StatRecords`, `StatArchive`, `StatJsonExport`; `generateStats()` split into hidden check, row fill and alliance totals. New `StatbuilderTest` pins the written statpoints rows (stat 1 and 2, honor titles), records and history on fixture data (captured before the split).
- `MarketPlaceController` (887) keeps `show()`; `App\Game\Market`: `MarketRules` (slots, vacation, diplomacy filter, push check), `MarketPurchase` (the 370-line `doBuy()` split into load/validate/plan trip/send/re-route/notify; unreachable duplicate wanted-resource check dropped), `MarketOfferListing`, `MarketTradeHistory`.
- `MessagesController` (846) is the HTTP adapter over `App\Game\Messages`: `MessageMailbox` (favourite, delete, bulk actions, recipient search), `MessageComposer` (receiver filtering, forbidden-content ban, send/forward), `MessageListing` (categories, filter, grouped paging, row data).
- `MissionCaseExpedition` (946) keeps draw/dispatch/report/return; the events live in `App\Game\Missions\Expedition` (`ExpeditionResourceEvent`, `ExpeditionShipEvent`, `ExpeditionCombatEvent`, `ExpeditionCombatReport`, `ExpeditionFleetValue`, `ExpeditionSize`, shared `ExpeditionContext`); RNG calls and their order unchanged. `MarketAndExpeditionTest` reflects `ExpeditionShipEvent::determineFoundShips()` now.
- `AccountEditorController` (990) and `CreatorController` (838) only pick the tab: one class per tab in `App\Game\Admin\AccountEditor` / `App\Game\Admin\Creator` (planets and alliances saves split into collect/write steps), pages and messages through `AdminPageResponder` (`ClosureAdminResponder`).
- Fixed: creating a second moon in the admin creator reported success (`createMoon(): int` turns `false` into 0); the check is now `> 0`. New `MessagesAndCreatorTest` (PM send with string ids, second moon, expedition stay event).
- Round 2 of step 97: `AlliancesTab` deletion fixed (members were released with the wrong placeholder `:id` instead of `:userId`; the statistics log now records each row's own "after" data instead of the whole list), `deleteAlliance()` split into release members / detach statistics / delete related rows; removing a member (`delete_u`) selected a non-existing `userId` column of users, now `id`; `PersonalTab` without a changed field no longer builds an empty `SET` (no-op, still logged as success). `AccountEditorTabsTest` covers all three.
- `MarketPurchase` is covered by `MarketPurchaseTest` (buy a resource offer: paid resources, buyer fleet, re-routed seller fleet, trade link, messages; refusals: unknown/own offer, push prevention, not enough ships).
- `MissionCaseAttack` (729) and `MissionCaseDestruction` (748) share `AbstractBattleMission` (target lock, participants, losses, debris, result stats, report, messages, loot, top-KB; split into `BattleFleetHandling` and `BattleReporting` traits and the `BattleSetup` value object); each class keeps its own `TargetEvent()` order (moon roll for the attack, moon/fleet destruction for the destruction). `BattleMissionTest` pins the rows of an attack on a planet and a moon and of a destruction without death stars (captured on the old classes).
- `FleetStep3Controller` (698) hands the dispatch to `App\Game\Fleet\FleetDispatch` (form/session, validation, ACS, target, mission rules in `FleetDispatchMissionRules`, flight plan, launch, trade offer); refusals are `FleetDispatchRefused`, shown by the controller as the same message page.
- `SettingsController` (767) hands `send()` to `App\Game\Settings\SettingsUpdater`: the 76 plain settings are a column to request-parameter/default table, the special ones (language, decimal separator, themes, hangar filters, spy hours, spy count, fleet actions, messages per page) are validated individually; the save is verified by posting the unchanged settings form back (row identical).
- `ServerInfo` (787) is a facade over `ServerInfoSystem`, `ServerInfoDatabase`, `ServerInfoGame`, `ServerInfoLogs` and `ServerInfoFormat` (`App\Game\Core\ServerInfo`); the information page renders identically.
- `BotSocial` (748) keeps the turn and the buddy step; `BotSocialMessaging` (replies, greetings, sending, language/situation helpers) and `BotSocialAlliance` (apply, found, applications) are injected services; `BotSocialTest` runs 40 social turns.
- Fixed on the way: `AdvancedStatsHandler::getValue()` and the honor lookups read `['statvalue']` of a missing row ("array offset on false" warnings in every fight of a player without stats; covered by `BattleMissionTest`).
- Admin player search: the page size is limited to the offered values (an empty `limit` caused `LIMIT x,0` and a division by zero).

## 98. Typed User/Planet read models (phase 4a)

- New `App\Game\Model\User` and `App\Game\Model\Planet` (final readonly): typed snapshots of the `$USER` / `$PLANET` state arrays (ids, coordinates, type, temperature, fields, stocks with production/storage/bonus/energy, queue end times and raw queue strings, protection/vacation/ban/outlaw data, points, bonus factors; building levels, tech levels and ship/defense/missile counts as element maps behind `level()`, `count()`, `has()`, `productionRate()`). Built with `fromState($row, $resource)` or `$this->models()->user()/planet()` (`ModelFactory`, via `GameUtilities`); missing or NULL keys read as 0. Snapshots only: mutations still go through the arrays.
- Typed service methods, old array signatures kept as `@deprecated` adapters (convert and delegate): `BuildFunctions` (`elementPriceFor`, `restPriceFor`, `isBuyableFor`, `isAccessibleFor`, `buildingTimeFor`, `maxConstructibleFor`, `maxRocketsFor`), `ResourceProduction` (`hashFor`, `rebuildFor`, `networkLevelFor`; the eco hash string is unchanged), `FleetMath`/`FleetFunctions` (`maxFleetSlotsFor`, `expeditionLimitFor`, `fleetMaxSpeedFor`, `missionDurationFor`, `fleetConsumptionFor`, `fleetShipInfoFor`), `ProtectionRules`/`PlayerUtil` (`catchUpFactorFor`, `spyTechAllowedFor`, `checkNoobProtectionFor`).
- Call sites moved to the typed versions: queue processors (building, research, hangar), `ResourceUpdate`, noob-protection checks (galaxy, fleet dispatch/step 2/missile/ajax, statistics, alliance members, mission rules), spy-tech checks (player card, charts, alliance applications), fleet planning (`FleetDispatch`, `FleetStep2Controller`, `MarketPurchase`).
- Reference aliases: 674 of the 787 `$X = & $this->state->X;` aliases (293 for USER/PLANET) became plain reads, found by an AST script (variable never written, passed by reference or captured by reference, and no call into a method that writes that state member); 113 remain (61 USER/PLANET).
- Tests: `UserTest`, `PlanetTest` (mapping, casts, defaults, snapshot semantics) and `TypedModelServicesTest` (typed versus array results, legacy eco-hash formula).
- Fixed: an unknown admin page for a not-yet-elevated admin crashed (the hand-built error page had no rate limiter); `GameEntryController` now passes the security services.

## 99. Formatted translations (phase 4c)

- `Language::format()`, `formatHtml()` (arguments escaped without double encoding, Markup/numbers kept) and `formatIn()` (nested keys) replace every `sprintf($LNG['key'], ...)` in `src/` (about 180 call sites in 70 files); templates use `LNG.format('key', ...)` (`LanguageView::format()`, trusted Markup) instead of `|format`/`sprintf(LNG.key, ...)`. `AllianceText::html()` takes the language and key.
- The helper counts the printf placeholders (`Language::placeholders()`/`placeholderCount()`, `%%` ignored, positional `%1$s` supported) and throws a `LogicException` on a mismatch when `Language::$strictFormat` is set (kernel debug via `ErrorReporter::register()`, always in PHPUnit); in production it logs and pads/cuts the arguments.
- Escaping rule: `formatHtml()` for player-controlled values in HTML/stored messages (buddy requests, alliance applications/leaving/circulars/treaty texts); `format()` for plain text and already-safe values. See docs/templates.md "Formatted texts".
- Latent bugs found by the placeholder scan and fixed: texts with a missing or broken placeholder (`al_request_deleted` in 7 locales, `reg_file`/`reg_dir` es/fr, `sb_stats_update` es/fr/pt/tr `%t`, `ov_security_confirm` fr, `tn_delete_mode` tr, `tn_vacation_mode_until` pt, `al_make_ally_insufficient_points` tr, `al_diplo_war_mes` es/ru (the reason printed the treaty level), `sys_notenough_money` pt (shifted arguments), `fl_attack_confirm_diplo` tr `%1%s`, `sys_mess_spy_report_details_*` tr `1$s`, `bd_need_engine`/`bd_more_engine` ru), `css_account_banned_expire` (all locales now carry `%1$s` date and `%2$s` contact link = the `forum_url` setting; without a forum_url the contact sentence is left out; the hard-coded Discord link is gone), and calls with the wrong argument count (`tn_delete_mode`: the time zone belonged to `formatDate()`, `registerWelcomePMText` passed an unused extra value).
- New `LanguageFormatTest` (formatting, placeholder counting, mismatch handling, escaping, and a scan that every language has the placeholders of English for all texts filled by `format()`).

## 100. Documentation sweep (phase 4d)

- Docblocks for `src/Runtime`, `Security`, `EventListener`, `EventSubscriber`, `Twig`, `View`, `Command`, `Content`, `Routing`, `DataCollector`, `Kernel`, `Game/Bots` (engine, brain, actions, persona, social steps, LLM client) and `Game/Admin`: purpose, units, side effects and game rules; stray/duplicate docblocks merged (`GameBootstrap`, `HtmlContent`, `LlmClient`, two account editor tabs), stale references (`ShowFleetStep3Page`, `ConfigUni.tpl`, `class.FleetFuntions.php`) updated; JSDoc for the non-trivial functions and module headers of `public/scripts`. SQL audit of these directories: no runtime value is concatenated into SQL (column names come from `$resource`/`$reslist`, the installer database name is validated with a whitelist regex), so no query had to change.
- Docblocks for the game core in `src/Game` (Core, Missions incl. Expedition, BattleEngine, Cronjob, Achievements, AdvancedStats, Cache, Tutorials, Models, `Support/functions.php`): every mission class (target/hold/return events, spy and counter-espionage rules, missile damage, recycling capacity, moon/fleet destruction, pirate bases, trade phases), the battle engines (round, shot, rapid fire, loot, rebuild protection, wreck fields), every cronjob (what it does and when), expedition deck, session/config/database/language services; PlayerUtil facade methods link to the service that implements them. Removed orphaned comment blocks (`DailyCronjob` debug snippet, stale handler comments in `functions.php`); the `LanguageCatalog` docblocks were back on the right methods. Comments only, no logic changes.
- Docblocks for all controllers in `src/Controller` (Game, Login, Account, Admin, entry/base classes): every mode method now documents its request parameters, what it changes and how it ends (render, redirect, JSON, EndOfRequest). Removed commented-out dead code in a few controllers.
- Admin quick editor save rewritten (`QuickEditorController::savePlanet/savePlayer`): only fields present in the POST are written (a partial POST no longer zeroes techs, buildings, ships or resources), all values are bound parameters (column names only from the `$resource` whitelist), the log entries carry the real old/new values, and the password change now writes to `accounts` (the old query targeted a non-existent `users.password`). New `QuickEditorSaveTest`. SQL audit of `src/Controller`: admin search ORDER BY direction is now limited to ASC/DESC, giveaway uses bound parameters, cronjob overview binds the id, notes delete binds one placeholder per id; the remaining concatenations only use whitelisted column names or integers from the database.
- Referral chain repaired: the registration form reads `referralID` again, the verification step stores it on the account (new column `accounts.referralID`, migration `1794900400`, also in `install.sql`) because no player exists yet, and `Account\RegisterController` applies it to the account's first player (`ref_id`, `ref_bonus` = 1, checked against the universe's users, then cleared) so `ReferralCronjob` finds referred players again. Previously the dead `$referralID` / `$userID` variables meant no referral was ever recorded.
- 4d1 fixes: `AbstractBattleMission::roll()` is the single random source of the attack/destruction rolls (still `random_int`), so `BattleMissionTest` forces outcomes (it failed about every second run on the 45% fleet-destruction roll; new test for a hit); `AccountUtil::getDeletionDate()` finds the job by class `CleanerCronjob` (the one that deletes players and accounts) instead of cronjob id 4; `MissionCasePiratebaseAttack::updateFleetsAfterBattle()` now really removes destroyed attacker fleets from the list used for loot and report (it unset a non-existent variable); `AdvancedStatsHandler::increment()` casts fractional amounts to the integer column explicitly (no float-to-int deprecation, same stored value); dead code removed (PHP 5.3 `array_replace_recursive` polyfill, `Session` IP check with `compareIpAddress()`/`shortIpv6()`). Tests: `AccountDeletionDateTest`, `PirateBaseAttackFleetsTest`, `AdvancedStatsIncrementTest`.
- Fleet arrival/recall clocks (flotten.js, fleetTable.js) convert from the configured server timezone (`serverTimezone` in the game page config) instead of a hard-coded Europe/Berlin.
- BotBrain: storage cap read as `(float) ($planet[…] ?? 0)` (the cast ran before the `??`, so a missing key warned).

## 101. Rust battle engine with source (phase 5)

- New crate `tools/battlerust/` (Rust 2021, serde + serde_json only, `cdylib`): a port of the PHP engine `BattleEngineSteemNova` (per-ship simulation, same shooter order, same random draws, rapid fire, explosions, simultaneous destruction, shield restore) with its own bit-exact PHP `mt_rand` (MT19937, PHP seeding, rejection-sampled `mt_rand(min, max)` incl. the 64-bit path where the first output is the low half, `rand()` swapping reversed bounds, degenerate ranges consuming a draw). Optional `seed` in the input JSON; without it the generator is seeded from `/dev/urandom`; the result carries `seed` and `rngDraws`.
- Same C ABI as before (`fight_battle_rounds`) plus `battlerust_free` (the first library never freed its result) and `battlerust_version`; panics are caught at the boundary (NULL result). The launcher `bin/battlerust` calls `battlerust_free` when present (old libraries keep working) and was rebuilt.
- Result format unchanged for the game (`Roundstats` + `Statistics` maps per round, `outcome`); statistics defined from the PHP rules (`shield_hit`, `damage_done`, `damage_dead`, `shield_bounced`, `ship_destroyed`, `explosion_triggered`, `explosion_damage_done`, `rapid_fire_done`; `damage_done + damage_dead + shield_hit + shield_bounced` equals the attack fired). Memory guard: fights above 60 million ships (`maxShips`, about 1 GB peak) are refused.
- `BattleEngineRust`: unrounded attack/hull/shield and the explosion threshold per unit id (like the PHP engine) go to the library; `useSeed()` seeds the library and replays `mt_srand(seed)` + `rngDraws` draws so the defense rebuild rolls continue the PHP engine's stream; users without losses are listed with 0 in the per-user books (as the PHP engine does); defense rebuild rolls in fleet unit order; when the library fails or refuses a fight the PHP engine fights instead and the engine reports `steemnova` (report stored in the matching format; before, a failed Rust fight returned false).
- `tools/battlerust/build.sh [build|test|check]`: pinned `rust:1.91.1-bullseye` container (glibc 2.31, library needs at most GLIBC_2.30), `--locked`, LTO, strip, path remapping, no build-id: reproducible (same sha256 on another host path and with an empty cargo cache). `bin/libbattlerust.so` rebuilt from source (565 KB, was 660 KB).
- Tests: cargo tests (PHP `mt_rand` sequences for 7 seeds, range mapping incl. 64-bit, reload, engine rules, invariants, goldens captured from the PHP engine, C ABI); PHPUnit `BattleEngineTest` now compares Rust and PHP engine field by field with the same seed (golden fleets x seeds, 300 generated battles, `BATTLE_PARITY_RUNS=5000` for a soak, large fleets, fallback) instead of the statistical comparison.
- CI: job `battlerust` (cargo test, rebuild + sha256 check against the committed library, battle parity PHPUnit against the fresh build).
- Docs: `docs/battle-engine-contract.md` (ABI, input/output JSON, statistics, parity limits, differences to the first library), `docs/PERFORMANCE.md` (benchmarks), README.
- Performance pass on the library (results and parity unchanged, 5000-battle soak identical): 16 bytes per ship instead of 24 (type by position per fleet, destroyed flag in the sign bit of the shield, order-preserving compaction), per-type loops, precomputed draw ranges without divisions, prefetching of upcoming victims from a peek at the deterministic generator (fights over 1M ships); 36M ships now 8.8 s / 567 MB (first library 8.9 s / 426 MB), `maxShips` default 60 million (about 1 GB).

## 102. Phase 4 completion

- Reference aliases `$X = & $this->state->X;` removed outside the e2 battle files: USER/PLANET/ACCOUNT are written as `$this->state->USER[...]` (so by-reference calls like `disable_vmode($this->state->USER, ...)` work on the state property), read-only data (LNG, resource, reslist, pricelist, ProdGrid, ...) are plain copies; `GameBootstrap::run()` and `GameData::load()` write the state directly, `AllianceMemberLists` takes its USER copy after `loadOwnPoints()`.
- `NewPlayerOptions` value object and `PlayerRegistration::register()` (steps `linkHomePlanet`, `linkAccount`); `createPlayer()` keeps its 16-parameter signature as an adapter.
- `PlayerDeletion::deletePlayer()` split into named steps (market offers and fleets, planets, user rows, incoming fleet recall, account link), SQL order unchanged.
- `TestDataCreator`: stage tables moved to `TestGameStages::STAGES`, one `updateAbsoluteValues()` instead of three copies, player and colony creation split out; `UserCreator`/`AccountCreator` split into form handling, `validationErrors()` and `showForm()`; `MarketPurchase::planBuyerTrip()` split (distance, duration and fuel, cargo, payment).
- Battle objects (phase 4e2): `BattleFleet` (id, owner row, units, fleet row, fleet state of the defending planet; units are the only mutable part), `BattleTechs` (weapons/shield/armor levels, `factor` list, multipliers, `withBonus()`), `BattleResult` (winner, round log, `BattleLosses` per side and user, `BattleDebris`, repaired defense, wreck field, duration; `startAttackers()`/`startDefenders()` rebuild the fleets before the fight from the round log), enum `BattleSide`. `BattleEngineInterface::fight(BattleFleet[], BattleFleet[], ...)` returns a `BattleResult` and reduces the units of the given fleets to the survivors; `calculateAttack()` stays as a deprecated array adapter (the engine test still runs through it).
- `BattleSetup`, `BattleFleetHandling` (collect/apply losses), `AbstractBattleMission`, `BattleReporting`, attack/destruction/pirate base attack, expedition fights, `BattleHandler` (report, rebuild protection, wreck field, loot), the achievements and the simulator controller use the objects. The per-ship simulation of the PHP engine keeps plain arrays inside (one array per ship, up to 500k); the honor point calculation and the stored round log are still arrays.
- The JSON for the Rust library is built from the objects (`BattleEngineRust::engineJson()`) and is byte-identical to the array version (fixture `tests/Fixtures/battle-engine-input.json`); report data and result figures of seeded fights in both engines are unchanged (hash test `BattleValueObjectsTest`). Only the key order of the fleet snapshot in `rounds[0]` of a Rust report changed for simulator and expedition fights (`player` first); the content is the same.
- `ResourceProduction::produceFor()` is the pure production calculation (planet snapshot + seconds + config -> `ProductionResult`: deltas, capped stocks, energy figures); `produce()` is the array adapter, `ResourceUpdate` applies the result itself.
- `BuildFunctions::queueFieldProjectionFor()` / `canQueueOnFieldsFor()` replay the building queue against the field limit from the `Planet` snapshot (new `FieldProjection`) without a mutated planet copy; `PlanetQueries::maxFieldsFor()` takes optional terraformer/moon base levels; the array versions are adapters, `BuildingQueueProcessor` uses the typed one.
- Buildings, research, shipyard and empire pages build one typed `User`/`Planet` snapshot per page (per planet on the empire page) and use the typed price/time/access/buyable/max methods. Deliberately kept on the array adapters: the queue add/cancel/recalculate flows of `BuildingsController`, `ResearchController` and `ShipyardController` (the planet array changes between the calls: queue writes, resources paid, `<lab>_inter` set) and the `getNetworkLevel()` calls in them.
- SQL builders (phase 4e3): `Database::qb()` (DBAL QueryBuilder), `table('USERS')` (prefixed table name), `fetchAll()`/`fetchRow()`/`fetchValue()`/`execute()` (same counter, DB time, row count, insert id and debug log as the wrapper) and `updateRows()` (UPDATE from column maps: values, fixed expressions, increments; column names validated); see `docs/database.md`.
- Runtime-assembled SQL converted to the QueryBuilder with unchanged results: admin search (`MyCrazyLittleSearch` takes select list, search column and `[column, operator, value]` conditions), ban lists, analytics lists, autocomplete, message list (optional sender/receiver/date filters), account data, quick editor (loads and saves), account editor tabs (buildings/ships/defenses/research/personal/alliances/planets), send messages (audience filter; the former ``us.lang`` column never worked and now filters on `us.lang`), game search, statistics ranking and counts, battle hall filters, records build (`StatRecords::getHighestRows`), notes delete, message actions and read marks (bound id lists; delete-unmarked uses a subselect instead of `UPDATE ... JOIN`), fleet table watch levels, planet lists (`PlanetQueries::orderBySetting()` for empire, jump gate, menu), jump gate and missile silo writes, resources/repair dock/settings/tutorial rewards/reset/test data/planet creator writes, fleet launch cost write, clear planets, alliance rank reads and writes, session multi-account lookup, player charts, advanced stats, expedition deck lock, news and board listing.
- Left as static SQL (not expressible or no runtime assembly): message list page (window functions, `UNION ALL`), statistic rank updates (`UPDATE ... ORDER BY` with `@i`), `StatPointsWriter` and `StatRecords::insertQueries` (multi-row insert, `ON DUPLICATE KEY`), giveaway (`UPDATE ... JOIN`), `SQLDumper`/`StoredDataMigrator` (table names from the schema), and the e2 battle/mission files (`MissionFunctions`, `MissionCaseRecycling`, `MissionCasePiratebaseRecycling`, `BattleFleetHandling`, `MissionCaseMIP`).
- `tests/Integration/QueryBuilderQueriesTest.php`: old SQL as oracle for admin user/planet search (ordering, paging, match modes), battle hall (108 filter combinations incl. tied units), planet sort settings, mailbox id lists, `updateRows()` and the builder bookkeeping.

## 103. Long methods split (phase 4f)

- Admin controllers split into named steps, output and SQL order unchanged (HTML of all admin pages compared before/after): `BanController` (player/account modes, option lists, ban panel, `banPlayer`/`banAccount` with record and flag steps), `AccountDataController` (account, ban, statistics, alliance, planet blocks), `UniverseController` (delete in community/fleet/player data steps, create in support check + config insert), `SearchController` (selector options, match condition, one search definition per list, row formatting and row actions out of `MyCrazyLittleSearch`), `ResetController` (columns, players/planets, hangars, buildings, research, resources, general, form texts), `RightsController` (rights editor, authlevel change, shared player picker), `QuickEditorController` (planet/player forms, `elementRows`), `NewsController` (save, row store, image handling, delete, list), `MessageListController` (date filter, filter closure, count, page rows), `LogController` (data split, field labels, nested rows), `BotsController` (one method per POST action and per tab), `AdminShell` (menu groups, allowed tabs, universe options), `FlyingFleetController`, `MultiIPController`, `AnalyticsController`, `ConfigPlanetsController`, `SendMessagesController`, `ConfigUniController` (save + status-change side effects).
- Account and login controllers: registration (`Login`/`Account`: validation, duplicate counts, referral, pending row, mail/welcome message), account settings (`changePassword`, `changeEmail`), board list (filter, order, page, rows, controls), lost password (`storeResetRequest`, `sendResetMail`).
- `GameBootstrap::run()` split by mode: `initGuestState`, `bootPlayerMode` (`loadSessionUser`, `gateBannedUser`, `loadModeContext`, `gateUniverseStatus`, `logAnalytics`, `verifyRecaptcha`), `bootAccount`; `Installer::run()` (`setupLanguage`, `guardInstallTool`, `authorizeUpgrade`, `showUpgrade`, `doUpgrade` with PHP/SQL migration steps); `InstallService::prepareDatabase()` as one method per install step (input, server, database, free tables, config file, .env.local); `InstallCommand::execute()` (`collectDatabaseSettings`, `collectGameSettings`, `collectAdmin`, `runFinishPhase`).
- `ConfigFieldSchema`: `getBasicSchema()` / `getUniSchema()` merge one method per settings category (`basicSmtpSchema()`, `uniAttacksSchema()`, ...); the schema array is identical (`tests/Integration/ConfigFieldSchemaTest.php` pins categories, field order and a fingerprint of both schemas; the time-dependent start/end defaults are normalized).
- Left above 80 lines on purpose (purely declarative category arrays): `ConfigFieldSchema::uniServerParametersSchema`, `uniPlanetParametersSchema`, `uniAttacksSchema`, `uniGalaxyParametersSchema`, `basicServerParametersSchema`.
- Three old failure paths fixed (`tests/Integration/AdminNotFoundMessagesTest.php`): account data with an unknown player id shows the "player does not exist" message (was `isset(false)` and undefined keys); admin search `delete=planet` / `delete=user` with an unknown id (or a main planet) shows `se_delete_not_found` instead of an exception; a mass mail without any recipient sends nothing and shows `ma_no_recipients` (new keys in all admin locales).

- Game controllers (`src/Controller/Game/`): every method over ~80 lines split into private steps with docblocks, template variables, SQL order and side effects unchanged (rendered HTML of ~330 page/mode variants diffed against the old code).
- `StatisticsController::show`: `rankColumns()` (one table instead of two near-identical switches; spy-tech restricted mode appends ` ASC` as before), `pageRanges()`, `countRankedPlayers/Alliances()`, `rankingRows()`, `playerRows()`, `allyClass()`, `allianceRows()`, `typeSelector()`.
- `FleetStep2Controller::show`: `loadTarget()`, `possibleMissions()`, `preferredMission()`, `flightPlan()`, `guessMission()`, `confirmationQuestions()` (the duplicated diplomacy/buddy queries are `alliedAllianceOf()` / `buddyCount()`), `tradeMarketData()`, `formVariables()`, `checkShipsAvailable()`.
- `EmpireController::show`: `recalculatePlanets()` (locked transaction), `researchQueueHead()`, `addPlanetBasics()`, `addBuildingColumns()`, `addShipColumns()`, `addFlyingFleets()`, `addTechColumns()`, `averageBuildings()`; `isBuildableNow()` replaces the repeated accessible/price/buyable triple.
- `FleetAjaxController::show`: `fleetForMission()`, `recyclersFor()`, `checkAttackRestrictions()`, `dispatchFleet()`.
- `ResourcesController::show`: `basicIncome()`, `buildingProduction()` (`addBuildingProduction()`, `productionLevelOptions()`), `positionBonus()`, `productionTotals()`.
- `OverviewController::show`: `otherPlanets()`, `moonOf()`, `buildingEntry()` (three copies of the queue row), `hangarEntry()`, `researchEntry()`, `repairEntry()`, `adminsOnline()`, `referrals()`, `rankInfo()`, `outlawUntil()`.
- `FleetTableController`: `show()` -> `flyingFleets()`, `shipsOnPlanet()`, `cargoWatchList()` (`watchedColumns()`); `getACSPageData()` -> `renameAcs()`, `inviteToAcs()`.
- `BuildingsController`: `show()` -> `runQueueCommand()`, `buildingList()` / `buildingRow()` (`energyChange()`, `timeToAfford()`); `addBuildingToQueue()` -> `canQueueBuilding()`, `startBuilding()`, `appendToBuildQueue()`. `ResearchController` likewise (`runQueueCommand()`, `researchList()`, `startResearch()`, `appendResearch()`, `restartQueueAfterCancel()`).
- `ShipyardController::show`: `isShipyardUpgrading()`, `runHangarCommand()`, `hangarQueueData()`, `elementList()`, `filterSelectors()`, `unpinnedShipOptions()`.
- `BattleSimulatorController::send`: `recentSimulations()` (throttle), `simulatedFleets()`, `plunder()`, `moonChance()`, `simulationReportInfo()`, `storeReport()`.
- `GalaxyController::show`: `requestedSystem()`, `chargeForView()`, `missileSelector()`, `colonizablePositions()`, `pirateBaseIn()`, `shortStatusLabels()` (the `type` variable was never set, now an explicit `null`; the `require_once` of `planet_data.php` stays for its `random_int` calls).
- `RepairdockController::show`: `deployRepairedShips()`, `startRepairOrder()` (both end the request, return type `never`); `InformationController`: `show()` -> `addProductionLevels()`, `addStorageLevels()`, `shipInfo()`, `defenseInfo()`, `rapidFire()`; jump gate `sendFleet()` -> `checkJumpGates()`, `takeShipsForJump()`, `moveShips()`.
- `FleetMissileController::show`: `findTarget()`, `launchError()`, `loadOwnPoints()`, `protectionError()`, `launch()`. `SearchController::_getSearchList`: `searchPlayers()`, `searchPlanets()`, `searchAlliances()`, `planetRows()`. `RaportController::show`: `detailAccess()`, `reducedReport()`, `sortRustRounds()`.
- `FleetStep1Controller`: `show()` -> `selectedShips()`, `storeFleet()`, `universeGeometry()`, `checkShipsAvailable()`; `checkTarget()` -> `checkPirateBaseTarget()`, `checkPlanetTarget()`, `checkExpeditionTarget()`.
- `SettingsController::show`: option lists and value groups in `selectorOptions()`, `accountSettings()`, `galaxyAndSpySettings()`, `interfaceSettings()`, `hangarSettings()`. `RecordsController::show`: `firstTimes()`, `ownResearchLevels()`, `ownBuildingMaxLevels()`, `groupRecords()`. `PlayerCardController::show`: `identityVariables()`, `rankingVariables()`, `battleVariables()`, `honorVariables()`.
- `SpyReportPopupController` (`latestSpyReports()`), `FleetDealerController::send` (`backToDealer()`), `AdvancedStatsController::show` (group map + `formatStat()`), `AbstractGameController::navigationData` (`planetSelectOptions()`, `resourceBar()`, `allianceRequestCount()`, `vacationNotice()`, `deletionNotice()`).
- No exceptions to the 80-line rule needed; the longest remaining Game controller methods are 79-80 lines (`ResourcesController::show`, `AbstractGameController::navigationData`, `ShipyardController::elementList`, `FleetStep1Controller::show`).
- Two old failures fixed (`tests/Integration/FleetInputGuardsTest.php`): a missile launch at a non-existent planet shows the "planet does not exist" message (was a `ModelFactory::user(false)` TypeError, 503); fleet step 2 checks the speed setting before computing the flight (speed 0 or any disallowed value goes back to the fleet page instead of dividing by zero), and `FleetMath::missionDurationFor()` throws a `DomainException` for a non-positive speed setting or fleet speed.

- Game core (`src/Game/**`, 4f3): every method over ~80 lines split into private steps with docblocks; no exceptions to the rule. Mechanics, SQL order and seeded RNG order unchanged (PHPUnit goldens, exact seeded battle parity, rendered fleet lines and dumps compared against the old code).
- Missions: `MissionCaseSpy::TargetEvent` (`loadParticipants()`, `addHoldFleets()`, `visibleClasses()`, `collectSpyData()`, `addRepairDockShips()`, `buildReportData()`, `cargoNeeded()`, `sendReport()`, `notifyTargetOwner()`, `destroyProbes()`, `getDetectionChance()`), `MissionCasePiratebaseSpy`, `MissionCaseMIP` (`loadTarget()`, `resolveHit()`, `sendReports()`), `MissionCaseTransport` (`reportDelivery()`, `notifyReceiver()`), `MissionCaseTrade` (`rerouteToSellerMarketPlace()`, `holdAtMarketPlace()`), `MissionCaseColonisation` (`tryColonise()`, `consumeColonyShip()`), `MissionCaseStay`, `MissionCaseTransfer`, `MissionCaseDestruction`, `MissionCasePiratebaseAttack` (`buildAttackers()`, `buildPirateDefenders()`, `storeReport()`, `sendReports()`, `updateBase()`).
- Recycling: the two recycling missions share `MissionFunctions::lockDebrisField()`, `collectDebris()`, `takeDebris()`, `sendRecyclingReport()` (QueryBuilder instead of concatenated column lists). Bug fixed (test): the planet recycling report printed 0 as the total of the field (backticked column key), it now names the total before the collection like the pirate base report.
- `BattleFleetHandling`: `collectParticipants()` -> `loadIncomingFleets()`, `loadHoldingFleets()`, `battleFleetsOf()`, `planetBattleUnits()`; `applyLosses()` -> `saveFleetSurvivors()` (attacker and holding fleets shared one copy); the ACS select is a QueryBuilder (`$acsFleetsLocked` replaces the `$acsFleetsOrder` SQL tail), the planet unit update is `updateRows()`. `ExpeditionCombatReport::generate` -> `storeReport()`, `buildMessage()`, `MESSAGE_HTML`.
- Battle engines: `BattleEngineSteemNova::fightScaled` -> `tallyStartValues()`, `scaleDown()`, `fightRounds()`, `recordKills()`, `subtractSurvivors()`, `settleDefenders()`, `recordFightStats()`, `wreckfieldAllowed()`; `BattleEngineRust::parseRustJson` -> `collectKillStats()` (`addDestroyed()` replaces four copies), `applyAttackerLosses()`, `applyDefenderLosses()`, `recordFightStats()`, `resumeRandomStream()`, `outcomeOf()`; `BattleHandler::GenerateReport` -> `reportHeader()`, `reportPlayers()`, `reportRounds()`, `reportRoundFleet()`, `honorPoints()`; `calculateSteal` -> `raidCapacities()`, `protectedStock()`, `fillCapacity()`, `distributeLoot()` (loot is added with `updateRows()` increments instead of concatenated SQL).
- `FlyingFleetsTable`: `getEventData()` -> `eventText()` (`recyclingEventText()`, `missileEventText()`, `expeditionEventText()`, `flightEventText()`), `eventClass()`, `MISSION_STYLES`; `renderTable()` -> `logPhalanxScan()`; `getFleets()` is a QueryBuilder (bound mission IN list).
- Other: `Session::save` (`isRaportView()`, `writeSessionRow()`, `touchOwner()`) and `checkMultiIP` (`findGroupOfUsers()`, `createMultiGroup()`, `refreshMultiGroup()`), `SettingsUpdater::save` (`readForm()`, `decimalSeparatorFor()`, `lockPlayerRows()`, `changeUsername()`), `VarsBuildCache` (`buildUniverse()`, `combatCapsOf()`, `pricelistOf()`, `sortIntoLists()`), `SQLDumper` (`writeDumpHeader()`, `dumpTable()`, `dumpRows()`), `ErrorReporter::handleException` (`errorPage()`, `gameNameAndVersion()`, `logError()`, `openDebugTicket()`, `ERROR_TYPES`), `CleanerCronjob::run`, `ConfigRegistry::generateInstances` (`loadGlobalConfig()`, `loadUniverseConfigs()`, `loadModules()`), `ServerInfo::checks` and `ServerInfoGame::game`, `StatDataLoader::load`, `StatJsonExport::export`, `PlanetsTab::collectPositionChange` (`planPlanetMove()`, `planMoonSwap()`), `BuildFunctions::buildingTimeFor` (`constructionSeconds()`, `researchSeconds()`), `FleetDispatch::loadTargetPlanet`, `PlayerCharts::getChartPoints`, `MarketPurchase::rerouteSellerFleet` (`updateRows()`), bots: `BotBrain::candidates` (`buildingCandidates()`, `buildingScore()`, `researchCandidates()`, `unitCandidates()`), `BotEngine::processBot` (`playSession()`), `BotSpawner::spawnOne`, `LlmClient::complete` (`buildRequest()`, `errorMessage()`), `BotProfiles::defaults` (one method per profile).
- Dynamic SQL moved to the QueryBuilder / `updateRows()`: `MissionFunctions::SaveFleet` (generic fleet and log updates), recycling, `BattleFleetHandling`, `MissionCaseMIP`, `FlyingFleetsTable`, `PlayerDeletion::deleteSecondaryPlayerData` (OR over several columns), `BattleHandler` loot update, `MarketPurchase`. `$simulated`/`$fleetSimulate` dead code removed from both spy missions. The last reference aliases to the game state (`$x = & $this->state->...`, `$setup->fleetAttack`) are plain reads of the state.
- Bugs fixed (tests in `tests/Integration/MissionQueriesTest.php`): `/game?page=playerCard` without or with an unknown id showed the 503 error page (an exception), it now shows "This player does not exist!"; `/game?page=phalanx` without coordinates answered 503 (`ModelFactory::user(false)` TypeError), it now shows "Out of range"; a phalanx scan listing an incoming ACS group failed with "Singleton could not be converted to string" (`FlyingFleetsTable` built a URL by interpolating `$this->state->requestServer[...]`), it checks `REQUEST_URI` now. Not changed: `PhalanxController` selects the target with `AND :type` (always true) instead of `planet_type = :type`, so a moon at the coordinates could be picked.
- Tests: `MissionQueriesTest` (recycling, missiles, fleet list vs old SQL, phalanx with ACS, generic fleet update), `SpyMissionTest`, `PirateBaseSpyMissionTest`, `FlyingFleetsTableTextTest` (hash of 1152 event lines), a blocklist case in `PlayerLifecycleTest`. Test isolation: `GameTestCase` remembers the players a test creates and deletes the ones that survive a rollback (code under test that commits its own transaction, e.g. `AllianceApplicationService`), so `vendor/bin/phpunit` passes twice in a row on the same database (`SKIP_TEST_DB_SETUP=1`); `QueryBuilderQueriesTest` no longer depends on the generated system number.

- Phalanx: the target query filters on `planet_type = 1` (it used `AND :type`, always true, so a moon at the coordinates could be picked; audit INJ-3).

## 104. Configurable community links

- Global config fields `discord_url`, `wiki_url` (base URL, deep links are `wiki_url` + `/wiki/...`), `donate_url`, `userscripts_url` (category `community_links`, `se_*` keys in all `admin.*.yaml`); defaults empty, so a fresh install shows no pr0game links. Saved only when empty or an absolute http(s) URL (`'validate' => 'http_url'` in `ConfigFieldSchema`, applied by `ConfigFieldProcessor` via `AllianceText::httpUrlOrEmpty`).
- `DISCORD_URL` constant and the hard-coded Discord/Wiki/Ko-fi/userscripts addresses removed: navigation, login page links, settings (Scavengers Toolbox FAQ), fleet table expedition link and the Discord tutorial goal render only when the URL is set (nav entries come from `CommunityLinks::navLinks()` via the `communityNav` template variable: Wiki goes through `?page=wikiLogin` while `$wikiSsoUrl` is set in `config/game.php`, else straight to `wiki_url`, else hidden; donate also stays hidden during the tutorial).
- New `App\Game\Core\CommunityLinks`: texts use the tokens `{discord_url}`, `{wiki_url}`, `{donate_url}`, `{userscripts_url}`. Set: replaced by the URL. Empty: anchors with a token href become their link text, `<!--discord_url-->...<!--/discord_url-->` blocks (same for the other keys) are dropped, left-over tokens vanish. Applied in one place per path: `Language::withGameName()` (FAQ, tutorial, ingame, tech domains), `Language::getTemplate()` (rules, mails) and `ContentPages::defaultContent()` (editable rules page).
- Mail texts: the `{DISCORD}` line and its introducing sentence are dropped without a Discord URL (`CommunityLinks::prepareMail()`); controllers fill `{DISCORD}` from the config.
- Existing installs that relied on the pr0game links must set them in the admin (docs/INSTALL.md, Updating).
- `tests/Unit/Game/CommunityLinksTest.php`: field validation, token set/empty, all shipped FAQ/tutorial/ingame/tech/rules/mail texts with empty and set links, `Language::getTemplate()` from the config.
- Tutorial Discord step (`LinkTutorialSupport`): without a Discord URL the goal counts as fulfilled automatically (plain text, no link) and the step grants no reward; with a URL the click/reward flow is unchanged. `tests/Integration/LinkTutorialTest.php` covers both branches, `CommunityLinksTest` the nav conditions.
- PHP battle engine: the php-ds fast path is used only when the `Ds\Vector`/`Ds\Map` classes exist (a runner reported the extension loaded without them and every PHP fight crashed); the array path gives identical results.

## 105. Documentation and length checks enforced

- `tools/ci/docblock-check.php` (every class and every complex function — ≥ 15 lines, ≥ 4 branches or a non-trivial public/protected method — has a docblock) and `tools/ci/long-methods-check.php` (no method over 80 lines) run in CI; the last 17 undocumented helpers got docblocks, the five long settings-category methods are split into ordered parts (schema pinned identical by `ConfigFieldSchemaTest`).
- Wiki SSO hand-off works under the CSP: `CspNonce::allowSource()` opens the configured wiki origin as `form-action` for that response only, the auto-submit script carries the nonce and the submit button stays visible as fallback.

## 106. Responsive images

- `ImageProcessor::variants()/variantTree()/srcset()/removeVariants()`: width variants `<name>-<w>w.webp` next to a WebP original; `srcset()` lists the variants plus the original (cache-busted with the file mtime), null without variants.
- New `bin/console app:images:variants <folder below public/> [--widths=480,720] [--force] [--clean]` (backfill after dropping a file in; variants are skipped as images of their own).
- Start page memes: variants 480/720 (+960 original), `srcset` + `sizes="(max-width: 60rem) min(calc(100vw - 2rem), 40rem), min(50vw, 37rem)"`; `IndexController::memes()` ignores variant files. LCP hints (`fetchpriority=high`, width/height) unchanged.
- Screenshot gallery: variants 320/480/720 (+ full size) with `sizes="(max-width: 30rem) calc(100vw - 4.25rem), 20rem"`, `loading=lazy`; the separate `thumbnails/` folder is gone. Screenshots retaken in the Br0Game theme (1440x900, WebP Q82).
- Screenshot lightbox: own `<dialog class="lightbox">` (`public/scripts/login/lightbox.js`, styles at the end of `login.css`): near full viewport, full-size image, close/Esc/backdrop, prev/next buttons, arrow keys, swipe, `n / total` caption, focus returns to the thumbnail; labels `lightbox_close/prev/next` in `translations/public.*`. Replaces the unstyled `modal--image` dialog in `login/main.js`.
- News images: `storeUpload()` takes variant widths, admin uploads get 480/800/1200 variants (removed together with the image); list and article use `srcset` + `sizes="(max-width: 1200px) calc(100vw - 4.25rem), 1100px"`. Older uploads: `app:images:variants uploads/news --widths=480,800,1200`.
- Start page background: `--site-art` custom property, 1080 px wide copy (`background-1080w.webp`, 39 KB instead of 141 KB) up to 48rem viewport width; overlay, stars and position unchanged.
- Empire page planet images: `loading=lazy decoding=async`.

## 107. Tech tree

- Technologies page rebuilt as a graph: `App\Game\Core\TechTree` (pure, unit-testable) builds nodes (own level, requirements with met flag, status owned/available/locked, depth = longest requirement path) and one tab per category whose rows are the depth, with the transitive requirements of other categories as ghost cards and barycenter ordering against crossings; `TechtreeController::show()` only gathers levels/names.
- `public/scripts/game/techtree.js`: tabs (last one in localStorage), SVG bezier connectors behind the cards (redrawn via ResizeObserver, attachment points spread per card, level label at the lower end), hover/focus highlights the whole ancestor and descendant chain and dims the rest, click/Enter pins (Esc or click outside clears), detail panel (requirements own/needed, unlocks, info dialog link); data via the `techtree-data` island.
- CSS: new "tech tree" section in `content.css` on theme tokens with fallbacks (cards, edge colours, reduced motion, phones scroll the graph inside its container); the old collapsible `.techb/.techi` markup, JS and per-theme rules are gone. New `tt_*` keys (`tt_unlocks`, `tt_status_*`, `tt_hint`, `tt_foreign`, `tt_none`, `tt_graph`) in all `ingame.*` locales (de real, others en/de).
- Graph readability: cards are opaque and stacked above the SVG layer; idle edges are 1px and faint without labels, the highlighted chain is 2px with level pills; edges are drawn as a transitive reduction (chains are still traced on the full graph); edges that skip rows run through the gap columns between cards; foreign cards in a tab are limited to direct requirements of the category (plus nodes connecting two visible ones) and rendered compact.
- Compact layout, no horizontal scrolling: cards 5rem wide (36px image, 2-line name, "Lv N" badge on the image from the new `tt_lv_short` key, full name in title/aria-label), depth rows wrap into several lines (CSS flex-wrap), edge routing groups the cards by visual line and re-runs on resize; ghost cards smaller.

## 108. Resources page

- `game?page=resources` restyled: header with planet name and actions, per-resource column tints (metal/crystal/deuterium/energy), `+`/`-` marked production, tabular numbers, separated totals rows, energy deficit in the hourly total flagged (outline + warning sign), sticky first column in a `.table-scroll` (no page-wide horizontal scroll on phones); styles in `content.css` (`.prod-*`), table markup now `thead` + macros (`tip`, `val`) in `page.resources.default.html.twig`.
- New buttons "Ramp up" / "Shut down" (`public/scripts/game/resources.js`): set every `prod[<id>]` selector to 100 % / 0 % and `requestSubmit()` the normal `send()` form; keys `rs_ramp_up`/`rs_shut_down` in `translations/ingame.*`. "Save changes" moved from the bonus row to the header.

## 109. Start page, brand, charts, popups

- Default theme: migration `1794900500` switches `default_theme` from the old default (`nova`/empty) to `br0game`; players keep their own theme.
- Start page: corner glows removed; painted artwork `images/login/background.webp` (1920 px, 141 KB, from the old start screen) under a dark gradient and the star layer, intro text with a soft shadow; same background on all public pages.
- Start page memes replaced by six new comic panels (`public/memes/*.webp`, 960 px, quality 82) with per-image alt texts in every locale; `og:image` points to `memes/solo-scouting.webp`.
- Screenshot gallery retaken in the Br0Game theme (1440×900, WebP).
- Wordmark: the old `pr0game.webp` logo image is replaced by a text wordmark `Br<b>0</b>Game` (`.wordmark` in `base.css`, Michroma, "0" in the theme accent) in the game top bar, account lobby and admin header.
- Charts (`app/charts.js` `applyPageTheme()`): faint horizontal grid only, no axis borders, muted ticks, round legend markers, dotless lines (point on hover), dark padded tooltip; empire charts use one palette, solid category lines, rounded change bars; on phones the charts stay at the visible width (`container-type` on the empire table wrapper, sticky chart box).
- Sticky tooltips (`app/tooltip.js`): anchored under the trigger without a gap (flips above, clamped to the viewport), 250 ms close delay so the pointer can move in, click/tap pins, Esc/outside click closes, keyboard focusable; fixes the unreachable demolish confirm (galaxy, overview and fleet table popups use the same path). `.statictime` durations inside injected tooltip content are rendered.
- Demolish popup compact (`#tooltip .tt-destroy`: auto width, tight rows, right-aligned values, full-width button).

## 110. Translation overhaul

- Player-facing domains (ingame, fleet, tech, public, account, tutorial, faq) reworded in en and de: errors say what happened and what to do, short confirmations, friendly empty states, some flavour in achievements, plain-language settings; one term per concept; en uses US spelling in running text (object names keep their spelling); de always uses "du".
- Tech descriptions fact-checked against the game data and code (drive switch levels, jump gate cooldown, defense rebuild ~70 %, university bonus by default, alliance depot has no effect, Battle Transporter speed, rapid fire) and identical in en and de.
- es, fr, pl, pt, ru, tr reworked against the en/de baseline: informal address everywhere (tú, tu, ty, ты, sen), missing keys added, German/English leftovers translated, wrong meanings fixed (swapped fleet table columns, cancel refund 60 %, name change limit, dissolve vs leave alliance); pt unified to European Portuguese, tr with proper Turkish letters, fr with non-breaking spaces before `: ; ! ?`.
- Label fixes: fleet table columns (origin/departure/arrival), jump gate ship selection, arrival time in fleet step 3, missile launch message, market visibility option ("alliance, pact partners and buddies").
- pi and of untouched except new feature keys.

## 111. Six new languages, grouped language selector

- New packs in `config/languages.yaml`: nl (Nederlands), th (ไทย), tl (Filipino, two-letter code because lang columns are varchar(2); html tag `fil`, Accept-Language `fil` maps to `tl`), uk (Українська), vi (Tiếng Việt), zh (简体中文, Simplified); each with number format settings, the player-facing domains (ingame, fleet, tech, public, account, tutorial, faq), l18n locale data and all `templates/texts/<locale>/` files; admin/install fall back to English.
- Placeholder order: translations that move values use numbered placeholders (`%3$s`); swapped values fixed in tr (diplomacy end, fleet mission targets, market messages) and pl (enemy spy probe messages); broken `s%`/`%ss` placeholders in of fixed; fr inactivity mail had a translated `{LASTDATE}` placeholder.
- Language selectors: joke languages (`fun: true`: SetSails, Ogerfränkisch) are listed last in their own option group (`LanguageCatalog::getLanguageOptions()`, `Language::getLanguageOptions()`, label `lang_group_fun`), real languages sorted by code with native names (Español, Français, Polski, Türkçe).
- ConfigFieldSchemaTest: universe schema golden hash updated (language list).

## 112. Start page knows the login

- Login-area pages read an existing account session (`state->auth->account()`, no session started for guests): header shows account name (link to the lobby) and a POST logout link, the start page replaces the login form by a "logged in as" panel with continue/log out buttons; new `public` keys `home_logged_in_head`, `home_logged_in_as`, `home_continue`, `home_account_lobby` in all 16 locales.
- Screenshot gallery retaken in the Br0Game theme (10 shots incl. the tech tree with a highlighted chain); old set and its variants removed.
- Start page memes rendered at 1600 px from the originals (variants 480/720/1080, quality 85) and no longer tilted (rotating a bitmap blurred the comic text; only the caption sticker stays tilted).
- Language selectors (start page, public layout, installer) use the Lucide `languages` icon (added to the sprite) instead of the globe.

## 113. Database concurrency (stage 1)

- `Database` sets `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` right after connecting (shared DBAL connection included): no gap/next-key locks on range scans, which removes the deadlock between fleet dispatch (user row, then `INSERT fleets`) and the fleet handler (`SELECT ... FROM fleets FOR UPDATE`, then user rows). Needs `binlog_format=ROW` when binary logging is on (docs/database-concurrency.md).
- `Database::transactional($fn, $attempts = 3, $backoffMs = 25)`: transaction around a closure, rollback + jittered back-off + rerun on deadlock (1213) / lock wait timeout (1205), other errors roll back and rethrow; `Database::errorNumber()` / `isRetryable()` classify exceptions.
- Used by the fleet event claim and the fleet handler run in `GameBootstrap::processFleetEvents()` and `BotEngine::processFleetEvents()` (event locks are released when every attempt failed; a claim that stays deadlocked is still reported to Discord) and by `FleetDispatch::dispatch()` (form token read before the transaction, planet row / storage / mission / ACS group reset per attempt, `launch()` no longer commits itself).
- `tools/loadtest/` (seed.php, loadtest.mjs, run.sh, README): 60 test players, 600 short flights falling due in clusters, 60 concurrent sessions for 200 s; baseline 8 deadlocks and 6 HTTP 503 -> 0 deadlocks and 0 errors.
- `DatabaseTransactionalTest`: commit, retry, give up, no retry for other errors, real lock wait timeouts via a second connection.

## 114. Alliance Depot

- Building 34 (`ally_deposit`) now does something: the owner of the planet extends the stay of friendly holding fleets (mission hold, status `FLEET_HOLD`, owned by other players) by 1 to depot-level hours per supply, for deuterium from the depot planet. Page `game?page=allianceDepot` (`AllianceDepotController`, template `page.allianceDepot.default.html.twig`, `public/scripts/game/allianceDepot.js` shows the cost per chosen hours), link on the building page next to the depot.
- `App\Game\Core\AllianceDepot` (+ `AllianceDepotRefused`, accessor `allianceDepot()` in `GameUtilities`): hourly cost = `ceil(sum(count * base consumption) / 10)`, base consumption = first set of pricelist `consumption1..3` (the ship's own drive, independent of owner techs); stay left after a supply is capped at the longest hold duration of the fleet page (`FleetMissionRules::HOLD_STAY_HOURS`, 32 h, now one constant used by the stay select and the depot).
- `extend()` runs in `Database::transactional()`: depot planet row `FOR UPDATE`, then `fleets JOIN fleet_event ... FOR UPDATE`; re-checks planet owner, depot level, hours, fleet still holding at this planet, stay not over, `fleet_event.lock IS NULL` (not claimed by the event handler), stay cap and deuterium, then `ResourcePersistence::removeResources`, `fleets.stay_time`/`return_time` + seconds, `fleet_event.time` = new `stay_time` (the event of a holding fleet is its stay end, see `MissionFunctions::setState`), `log_fleets` copies. Arrival time, ships, cargo and status stay as they were.
- The fleet owner gets a system message (own language, keys `depot_msg_*` in domain `fleet`); UI and error texts `depot_*`, `bd_depot_action` in `ingame`; tech descriptions of building 34 rewritten in all 16 locales.
- `AllianceDepotTest`: cost formula, extension of fleet row/event/deuterium, repeated supply, message, refusals (deuterium, not the owner, no depot, own fleet, other planet, not holding, stay over, event locked, level cap, stay cap).

## 115. Raiding bots

- Bots raid like players: `App\Game\Bots\Raid\BotRaids` (per session tick, after the ordinary steps in `BotEngine::playSession()`), `RaidTargetFinder` (scouting), `RaidSpyReports` (reads reports), `RaidPlanner` (profit), `RaidDispatcher` (rules + send + recall), `RaidFollowUp` (re-spy, recall, hit settlement, streak, taunt), `RaidMemory` (state in the personality JSON: `_raid`, `farm`). Universe setting `bots_raids_enabled` (category bots, default off); profile switch `raid_enabled`.
- Scouting sees what the galaxy view shows: planets of the systems around the own planets (`scout_range`), points, alliance, planet/moon activity marker (15 min / minutes up to 60), `userStatus()` classes (inactive 7 d / long inactive 28 d, vacation, banned, noob / strong via `checkNoobProtectionFor`), diplomacy to the own alliance, accepted buddies. Never targets: own alliance, buddies, accepted pact partners (any level but war), vacation, banned, staff (always, also with `adm_attack` off), noob / strong protected, bots or humans when the profile excludes them, targets already hit `min(max_attacks_per_target_per_day, bash_per_user)` times in the bash window. Score: inactive weight (`prefer_inactive`), active-now penalty, weaker players, distance, farm list, noise.
- Spy: probes (2/3/6/12 by unclear reports, or from the target's spy tech once a report showed research with the game's visibility rule); the bot builds up to 4 probes when it starts raiding. Report read from the inbox (`message_group m_<planet>`, JSON): resources always, fleet / defense / research only when the section exists; a missing fleet or defense section = unknown, no attack, retry with more probes (3 tries).
- Decision: loot = `BattleHandler::calculateSteal()` simulation (capacity rule, half of each stock) x 0.9, discounted for targets active within 15 / 60 min; losses from `BattleHandler` fights (2 runs per candidate, virtual fleets, defender techs from the report or own + 1) ; fuel from `FleetFunctions`. Fleet = cargo for the loot + smallest escort share (0 / 15 / 30 / 50 / 75 / 100 % of the warships) that wins both runs; profit = loot - fuel - losses (metal 1, crystal 1.5, deuterium 2), attack when loot >= 3000 and loot / (fuel + losses) >= `min_profit_ratio`.
- Dispatch applies the player rules: own vacation, fleet slots, ships on the planet, `getFleetMissions`, target vacation, `checkBash`, staff, noob / strong protection (a bot never becomes an outlaw, it refuses); probes are not exempt from protection. `BotActions::freeFleetSlots()` is public now.
- Streak: a hit with loot >= 1500 starts `relentless_minutes` of raiding (session extended, at most one ordinary action per tick, probes and attacks every tick); it ends when time is up or after 3 idle ticks.
- Pre-impact re-spy: with `recall_check_chance` the bot wakes before the impact (`_reg` keeps the regular next check, `RaidMemory::nextWake()`), sends probes timed so the report is read 150-240 s before the hit even with two late cron runs (the re-spy plans with a 90 s cron gap; flights shorter than about 6 minutes get no re-spy), then keeps or recalls the fleet (`FleetFunctions::sendFleetBack`): recall when the report hides fleet / defense, the fight is not won any more, loot < 1500 or ratio < half the threshold; no report and probes gone = recall. Every decision is in the bot log (`raid_scout`, `raid_skip`, `raid_spy`, `raid_attack`, `raid_recheck`, `raid_keep`, `raid_recall`, `raid_result`, `raid_streak`, `raid_taunt`, `raid_prep`).
- After a hit: farm list in the personality (20 entries), 15 % chance of a short de / en taunt to a raided human (`BotSocialMessaging::taunt()`).
- Profiles: `raid_enabled`, `raid_chance`, `scout_range`, `min_profit_ratio`, `relentless_minutes`, `recall_check_chance`, `max_attacks_per_target_per_day`, `attack_bots`, `attack_humans`, `prefer_inactive` (clamped in `BotProfiles`, editable in the admin bots page, translations `bot_*` / `se_bots_raids_enabled*` in the ten admin locales); stored profiles without raid keys get the values of their default profile. Lunatic / Jobless raid a lot, Shift worker sometimes, Office worker rarely, Noob never.
- `BotRaidTest` (19 tests): target filter (alliance, buddy, pact, war, vacation, noob, staff, banned, bot / human settings, range), ranking, bash limit in scouting and dispatch, dispatch refusals, fleet slots, report parsing, profit decision, escort, recall decision, re-spy timing, a whole raid through the real espionage mission with recall and keep, streak and farm list. `ConfigFieldSchemaTest` golden updated.
- Dev helper `bin/console app:bots:fast-forward --minutes=N` (dev environment or `--force`): shifts the bots' fleets, events, schedule and raid memory back per step, ticks the bot engine and prints the new bot log lines (45 game minutes in about 3 s); documented in `tools/loadtest/README.md`.

## 116. Smarter bots

- Economy (`BotBrain`): goal planning. The goal is the build or research step with the best raw score for its cost (value = score / (1 + hours the planet's income needs / 12); goals more than 10 h away are ignored). While the goal is not affordable, a roll with `saving_discipline` percent keeps the bot from buying anything that would take more than 8 % of what the goal still lacks on that planet (other planets and resources about to overflow are exempt); with nothing compatible the bot waits ("saving for ..."). Stores at 85 % fill get a strong storage score. Solar satellites cover an energy deficit. Research boosts: astrophysics while the planet limit is reached (0.6 otherwise), computer technology while slots are scarce, espionage for raiding profiles, drives for profiles that fly. Cargo ships are scored by the capacity needed (half of the biggest stock) and are not over-built; a colony ship scores 86 while a colony is possible.
- Consolidation (`BotLogistics::consolidate()`): while saving for a build or research goal on one planet, cargo ships bring the missing resources from the other planet with the biggest surplus (15 % of the store stays), if the flight takes at most 3 h, fuel is under a tenth of the load and a slot is free; resources already on the way count. Log action `transport`.
- Colonies (`BotActions::colonyTarget()`): the best of up to 60 sampled free positions within 3 (10) systems, rated by what players know about positions (8 best, edges worst) minus distance; only free/occupied and the astrophysics limits are used.
- Fleetsave (`BotLogistics::fleetSave()`): at the end of a session (offline >= 20 min, roll `fleetsave_chance`) the ships (not satellites / colony ships) and 80 % of the resources leave on a transport to the nearest own planet at the fastest speed setting whose round trip covers the expected offline time; the fleet returns by itself. Less fuel than needed sends 70 / 50 / 30 / 15 % of the ships. Single-planet bots log `fleetsave_skip`. Threats (`threats()`): hostile attack / ACS fleets heading for an own planet within 3 h are judged once with what the overview shows (spy tech 2: ship count, 4: types, 8: exact); an unidentifiable fleet counts as a coin flip. If the planet would not hold (fight against the visible ships, attacker techs own + 1): fleetsave of that planet timed to return after the impact (roll `fleetsave_chance`: the Noob often does not react); otherwise defense units are queued. `RaidPlanner::planetHolds()` runs the fight.
- Expeditions (`BotExpeditions`): fleet by the game's find formula (`factor x max(min(points, cap), 200)`, limited by free cargo): large then small cargos until the expedition points reach `calculateMaxFactorRes()`, about a tenth of the points as warships, hold time 1 h (2 h in 30 % with expedition technology 2). Fleets that come home with more than 25 % of their value lost make the next ones half as big for 6 h (`expo_result`). `expedition_slots` (profile) keeps that many fleet slots free for expeditions (`BotActions::freeFleetSlots()` for every other flight, never more than total - 2). `BotActions::expedition()` was replaced by this class.
- Profiles: `fleetsave_chance`, `saving_discipline`, `expedition_slots` (clamped; defaults Lunatic 98 / 90 / 2, Jobless 85 / 55 / 1, Shift worker 70 / 65 / 1, Office worker 60 / 70 / 0, Noob 15 / 25 / 0; admin editor, ten admin locales).
- `RaidDispatcher::send()` takes cargo and a speed setting and sends transports to own planets (no protection or bash rule applies there); fixed a variable that overwrote the fleet id it returned.
- Performance: `RaidPlanner` caches simulated outcomes per fleet, report and techs and tries at most two origin planets. Measured with 100 spawned raiding bots (test database, 25 bots per tick, 120 ticks): 877 -> 621 ms per tick, worst tick 2015 -> 1415 ms, 80 -> 61 ms per bot, 102 -> 108 queries per bot (the new logic adds a few; most queries are the game's fleet missions); a cold `app:cron:run` with 25 raiding bots takes 1.6 s. Ten bots in the early game: 6 ms per bot, 16 queries.
- `app:bots:fast-forward` now also moves production, shipyard and build / research queues (`last_update`, queue end times) and has `--stats` (queries and ms per tick) and `--limit`.
- `BotSmartTest` (14 tests): saving rule, goal choice, consolidation, fleetsave (timing, cargo, chance, offline limit, single planet), threats (stronger, harmless, spy-tech visibility), expedition cap and loss reaction, colony positions, reserved slots, profile settings.

## 117. Bot alliances, cron timing, small fixes

- Bots and alliances: universe settings `bots_alliances_enabled` (default on; off = lone wolves: no applications or new alliances, members leave, bot-founded alliances stay) and `bots_join_human_alliances` (default off: bots only join bot-founded alliances, leave player alliances and withdraw applications there); joining is a low per-turn roll (`alliance_join_chance`), founding uses `alliance_chance`.
- Cron: `Cronjob::reCalculateCronjobs()` no longer adds 60 s before asking CronExpression for the next run (which already skips the current minute), so `* * * * *` jobs run every minute instead of every 2–3 minutes.
- Admin menu: a page opened without its mode (e.g. `admin?page=bots`) marks its first tab active, so title and menu highlight are right.
- Start page: meme caption sticker and the rocket icons (brand, register button) removed; four admin screenshots added to the gallery.
- Ship names: pl 217 "Transporter bojowy", 409 "Osłona atmosferyczna"; tr 216 "Kara Ay".

## 118. Security and bug review (round 2)

- Passwords are read raw (`HTTP::rawInput()`) in registration, account settings, quick editor, admin account creator and installer; before, the input filter HTML-encoded and trimmed them (and replaced non-ASCII by `?` on the settings page), so passwords with `& < > " '` could not log in. `PasswordSupport::legacyVariants()` rebuilds the old strings: a login that fails raw but matches the old form succeeds and rehashes the password. Password change enforces the registration minimum (6) via `PasswordSupport::MIN_LENGTH`.
- Login: lookup by e-mail only (`AccountProvider::loadByEmail()`, no numeric account ids, so leading-zero ids no longer dodge the throttle); unknown accounts spend the same bcrypt time (`PasswordSupport::burn()`), pending registrations too.
- Registration and verification serialise on the MySQL lock `registration` (`AbstractLoginController::withRegistrationLock()`); the verify link creates the account first and then deletes the pending row under that lock (single use, a failing insert keeps the row); migration `1794900750` adds UNIQUE `accounts.email` (skipped with a warning when duplicates exist), also in `install.sql`.
- Game session: a deleted `session` row now ends the login (no re-creation in `syncGameSession()`); `loadSessionUser()` joins `accounts_to_users` (account swap takes the player away from the old owner immediately). Lobby entry takes the universe from the player, not from the request.
- CSRF: `/logout` only logs out via POST + token (GET redirects to the start page, `CsrfPolicy::isLogoutRoute()`); GET rules for `account?page=register&mode=send` and `account?page=account&mode=send`.
- Registration, account register and settings validate `lang`, timezone and universe (fall back to defaults, friendly error for unknown universes) instead of storing garbage or answering 503.
- Lost password: mail link GET only shows a confirm form, the POST (CSRF) claims the key with a conditional UPDATE and sets the password; open keys die with the next request or a password change; a new request is allowed again after 10 minutes instead of 24 hours; key insert and mail run in `DeferredWork` (kernel.terminate) so known and unknown accounts answer alike.
- `CanonicalUrl::forPage()` never uses the Host header outside development (`kernel.debug`): without a real `DEFAULT_URI` it logs an error and returns null (the mail is skipped), the admin home page shows `ow_default_uri_missing` (10 admin locales); the web installer writes DEFAULT_URI from the address it is opened at, `app:install` warns when `--base-url` is missing; existing installs must set `DEFAULT_URI` in `.env.local` (docs/INSTALL.md). `TRUSTED_HOSTS` env (comma separated, `*.domain`) sets `Request::setTrustedHosts()` in `Kernel::boot()` (docs/INSTALL.md).
- `WikiSsoCodeStore` consumes codes atomically (file: `rename()` claim, redis: `GETDEL`), plain JSON files instead of the cache adapter.
- Session lifetime stays sliding: `Session::touchOwner()` no longer deletes the current session row 12 h after creation (idle time is checked in `syncGameSession()`/cleaner). Board: `saveComment()` refuses locked/deleted posts.
- Account settings: current-password checks limited per account (`limiter.account_settings_password`, 10/15 min); `Strict-Transport-Security` on HTTPS responses; TestDataCreator addresses include the universe (unique e-mail).
- Battle simulator: one simulation at a time per player (MySQL lock `<db>:sim:<uid>`, the stored-report throttle alone let parallel requests all pass), at most `MAX_SIMULATED_UNITS` (10 million) units in total with a message (`bs_too_many_units`, `bs_busy`); `BattleEngineRust::runEngine()` reads the Rust process non-blocking and kills it after `ENGINE_TIMEOUT` (60 s), the fight then falls back to the PHP engine.
- `HTTP::_GP()`: an array where a string is expected (`?x[]=1`) gives the default instead of a `TypeError`.
- Bots: tick run has a time budget (`BotEngine`: no LLM calls after 20 s via `BotPersona::limitLlmUntil()`, no new bot after 45 s), LLM HTTP timeout 8 s / 10 s total; greetings never go to staff, vacation or banned players; a spied target is probed again after 2 h (15 min inside a raid streak); spawning runs under the MySQL lock `<db>:bots_spawn_<uni>` with account, player and bot row in one transaction.
- `app:bots:fast-forward`: refuses outside the dev and test environment even with `--force` (which now only overrides "bots disabled"); shifts only fleets owned by bots (human fleets that target a bot keep their times).
- Admin reset of fleets also deletes the fleet events (they were left behind until the cleaner cron ran).
- Admin authorization is enforced on the server, not only by the menu: `AbstractAdminController::requireAdmin()` / `mayActOn()` (never user 1 unless you are user 1, never the same or a higher level than yours). Rights page ADM only and levels capped at the actor's own; creator clamps `authlevel` to the creator's level; account swap needs both players in the emulated universe and rejects higher-ranked ones; quick editor and ban checks refuse higher-ranked players (the account password also needs every player of the account below you); `AccountDataController` checks its page right again.
- Admin search delete is ADM only, POST + CSRF (`data-method="post"`, `CsrfPolicy` rule for `delete`), emulated universe only; flying fleets lock / unlock / mass unlock are POST + CSRF and universe-scoped; "send attackers back" binds mission 1, 2 and 9 (it matched only `'1, 2, 9'` = 1 before) and the current universe.
- Dump: `dbtables[]` must be exact prefixed table names (`SQLDumper::validTables()`), `--` before the names for mysqldump (option injection leaked the DB password), identifiers quoted in the PHP fallback.
- LLM providers: `LlmUrlGuard` (http(s) only, no credentials, every resolved address public: no loopback, private, link-local/metadata, CGNAT, IPv6 local; client pinned to the checked addresses via `resolve`); changing a provider's base URL clears its stored key and deactivates it.
- Installer: steps 5-8 refuse (redirect) once an administrator exists, whatever the tool file says (`InstallService::hasAdmin()`).
- Cron: the lock is taken with one conditional UPDATE (`lock IS NULL`, still due, rowCount 1), `finally` unlocks also after an `\Error`, a runner with a stale list skips jobs another runner already ran, `CronRunCommand` carries on after a skipped job; class names in the editor must be plain identifiers; the overview no longer shows the previous job's last run for a job without log.
- Admin reset runs in one transaction, "reset players" goes through `PlayerUtil::deletePlayer()` (fleets, messages, stats, account link) and "reset bans" uses `USERS_BANNED` (`%%BANNED%%` was no table).
- Config form: `smtp_pass`, `recaptcha_priv_key` and the Discord hooks are `secret` fields (password inputs with a "••••abcd" hint, empty keeps the value, admin log stores the hint).
- `/healthz` answers a plain `ok` / `fail` (no versions or counts); INSTALL.md checklist: `APP_ENV=prod`, `APP_DEBUG=0` for manual installs; pass encripter only reads a POST body; CSP report log stops at 5 MB.
- Fleets, missions, market (R2-FLEET): `MarketPurchase::loadOffer()` uses the offer list's conditions (mission trade, holding, stay not over, `trades.buyer_fleet_id IS NULL`), a sold offer can no longer be bought again (goods duplication / lost payment); `FlyingFleetHandler::run()` runs every event in its own transaction, a failing event is reported (`ErrorReporter::reportIsolated()`, game log + Discord) and quarantined (lock released, event time +`QUARANTINE_SECONDS`), GameBootstrap and BotEngine only claim and release; spent missiles that met a vacation target are deleted (`MissionCaseMIP::ReturnEvent()`); colonisation locks the user row first and treats a duplicate position key as "not free", migration `1794900600` (+ `install.sql`) makes the planet position UNIQUE (skipped with a warning when duplicates exist).
- Repair dock: an order without repairable ships is not stored and a stored `"[]"` order counts as free (self-heals); migration `1794900680` adds `planet_wreckfield.repair_order_rate`, deploy and display use the order's rate. Fleet ajax and jump gate run in a transaction on locked user/planet rows; trade offers carry one resource (step 3); phalanx pays after the checks (guarded UPDATE); recall refuses a claimed event (`fleets_event.lock`); step 1 tokens expire (10 min) and are capped (20), shortcuts are validated and capped (50); only the ACS creator invites/renames; FleetDispatch checks run on the locked planet row.
- XSS/client (R2-XSS): tooltip HTML escaping (`|tooltip_text`, double encoding for HTML inside `data-tooltip-content`) in the galaxy and statistics templates; `tooltip.js` sanitizes with an allowlist (`sanitizeTooltip()`, forms only with `data-tooltip-forms`, i.e. the demolish tooltip); `AllianceText::httpUrlOrEmpty()` refuses quotes, angle brackets and whitespace, the galaxy alliance link is filtered with it and opens with `rel="noopener noreferrer"` (rules texts, `open-url` too).
- Alliance settings: link fields over 255 characters get a message instead of a 500 (`al_link_too_long`), the leader title is cut on the typed text so it fits the column once encoded; message subjects are cut to 40 typed characters before encoding.
- Account register/settings load the `public` language domain (no raw `registerError*` keys); autocomplete highlights with DOM nodes (server sends plain labels); `game?page=report` stores at most 20 rows per hour and 200 per player; JSON in `<script>` (JSON-LD, page config, admin islands) goes through the `json_script` filter / `GameExtension::jsonForScript()` with all `JSON_HEX_*` flags.
- Refused admin actions (`deny()`: rights, level, reset, universe, ...) answer with the admin "permission denied" page and HTTP 403 instead of the 503 error page.
- Dump: native `mysqldump` works again with MySQL 8.4 clients: the password goes into a private temporary `--defaults-file` (MySQL 8.4 clients ignore `MYSQL_PWD`, and `~/.my.cnf` of the container overrode `--defaults-extra-file`), `--no-tablespaces` (no PROCESS privilege needed), a failed dump removes the partial file.
- Migration 1794901200 masks secret values (`smtp_pass`, `recaptcha_priv_key`, Discord hooks) in old admin log rows (mode 3) with the same hint the config form logs now.
- R2-ECO-01: leaving vacation mode clears `planets.eco_hash` (`VacationMode::resumeOtherPlanets`), so production and energy are rebuilt at the next update instead of staying 0 until the hash changed.
- R2-ECO-02: research `cmd=destroy` is refused like any unknown command (no cost, no queue entry); `addResearchToQueue()` has no destroy mode any more.
- R2-ECO-03: `InactiveMailCronjob::inactiveUsers()` takes the address from `accounts` (via `accounts_to_users`); the old query selected a non-existent `users.email`.
- R2-ECO-04: the daily reset of inactive players' levels first brings each affected planet up to now (`CalcResource`, locked rows), no retroactive production at 100 %.
- R2-ECO-05/10/13/14: `ResourcesController::send`, abandon, laboratory rule (buildings) and vacation entry lock the user row first and use it; shipyard orders re-check "shipyard upgrading" on the locked planet; vacation entry counts fleets and enables vacation in one transaction.
- R2-ECO-06: `AbstractGameController::initialize()` always builds `ecoObj` (XHR / `ajax=1` no longer ends in a 503 on galaxy, buildings, research, ...).
- R2-ECO-07: buddy requests: refused for players with private messages off, max 10 open and 5 new per 10 minutes (session), withdrawing a request sends no message (`bu_request_limit`).
- R2-ECO-08: notes max 100 per player and 10000 characters (`nt_limit_reached`), block list max 200 entries for existing other players, flags 0/1, search suggestions accept each of the 4 known modes once.
- R2-ECO-09: rename re-reads name/cooldown under the user lock; unique index `uniq_universe_username` (migration 1794901000, skipped with a warning when duplicates exist), a duplicate-key error becomes the "name exists" message.
- R2-ECO-10: abandoning a planet recalls every fleet heading there in its owner's name (foreign ones too), the check and the removal run with the planet row locked.
- R2-ECO-11: the silo cannot be demolished while missiles are queued in the hangar. R2-ECO-12: `QueueState::repaired` is reset after each save. R2-ECO-15: `createMoon()` ignores destroyed/missing parents. R2-ECO-16: shipyard `fmenge` keys are integer ids only.
- New `EconomyReviewFixesTest` (18 tests).
- Debug tickets (`ErrorReporter::openDebugTicket()`, debug setting on) are owned by `System` (staff only); the player who triggered the error is named in the text and can no longer read the stack trace.
- Admin rights page: without (or with an unknown) `mode` it shows the first tab (permissions) instead of an empty page.

## 119. Release 1.26.1

- `install/VERSION` 1.26.1 (tag `v1.26.1`): languages nl/th/tl/uk/vi/zh, translation overhaul, alliance depot, raiding and smarter bots, database concurrency (read committed + retries), session-aware start page and the round-2 security and bug fixes (steps 105–118).

## 120. Discord online box on the start page

- `App\Community\DiscordWidget`: online count from the public server widget (`/api/guilds/<id>/widget.json`), cached in `cache.app` (5 min, failures 2 min), 2 s timeout.
- New global setting `discord_widget_guild` (community links, validated as 17-20 digit server ID via `validate => discord_guild`); box hidden while empty or the widget is unreachable, links to `discord_url` or the widget invite.
- `i-discord` symbol in the icon sprite; start page box styles in `login.css`; rules and privacy policy text use the full card width (`rich-text--wide`).

## 121. Data-driven hall of fame

- New table `HALL_OF_FAME` (`universe` PK, `data` JSON, `updated_at`): migration `1794901300`, `install.sql`, `tables.php`.
- `App\Community\HallOfFame` (find, universesWithData, import with strict validation of format v1, export, clear); the three hardcoded `page.HoF3/4/7` templates are gone (archived as JSON outside the repo), a fresh install has no hall of fame data.
- `HofController` renders one template `page.hof.default.html.twig` from the DB (universe dropdown only for universes with data, empty-state notice `hof_empty`).
- Admin page `hallOfFame` (`HallOfFameController`, right `ShowHallOfFamePage`, menu Content): export download, import upload (replaces), clear; CSRF-protected POSTs.

## 122. Bots: relations, reply throttle, no unprompted contact

- Removed the bots' unprompted greeting (`stepMessage`, `GREETINGS`, profile field `message_chance` and its form/translations).
- New table `BOT_RELATIONS` (bot, player, balance -100..100, interactions, `player_contacted`, message pointer, `last_bot_message_at`) and column `bot_players.last_threat_check`: migration `1794901400`, `install.sql`, `tables.php`.
- `App\Game\Bots\BotRelations`: event deltas (message, buddy request/accepted, spied, attacked, bot raided), `stance()` hostile/neutral/friendly; internal only.
- `BotSocialMessaging::stepReply` answers per sender: all pending DMs in one reply once the oldest is `bot_reply_delay_hours` old (most overdue sender per turn), tone by stance (LLM prompt and canned sets).
- New universe settings `bot_reply_delay_hours` (default 3) and `bot_chatter` (default on) in category `bots`; `bot_chatter` off still reads/counts messages but sends no replies.
- `stepThreats` detects attacks and spy probes on a bot from `log_fleets` (mission 1/2/6/9, human sender, arrival since `last_threat_check`); attack reaction and raid taunt only go to contacted players and respect the delay.

## 123. Galaxy tooltips escaped for their attribute

- `page.galaxy.default.html.twig`: the 11 tooltip includes in `data-tooltip-content` go through `include(...)|e('html_attr')`; the player tooltip's double quotes closed the attribute and leaked `">` before player names.

## 124. Galaxy action icons

- Galaxy row actions (spy, spy report, message, buddy request, missile attack, phalanx) use Lucide icons (`scan-eye`, `file-search`, `mail`, `user-plus`, `crosshair`, `radar`; new symbols in the sprite) as `.galaxy-action` buttons instead of theme GIFs; the spy report freshness dot stays.

## 125. Board link opens in a new tab

- Game side panel: the board entry (account area) opens with `target="_blank" rel="noopener"`, like the other community links.

## 126. Installer legal step: hosting region, rules, board

- New global config category `legal` (`ConfigFieldSchema::basicLegalSchema()`): `privacy_region` (`eu`|`other`, default `eu`), `rules_default` (checkbox, default on), `board_enabled` (checkbox, default on); existing installs keep today's behaviour, all three are in the admin basic settings (`se_privacy_region*`, `se_rules_default*`, `se_board_enabled*`, de/en).
- Installer: new step 7 `Installer::stepLegal()` + `ins_legal.html.twig` (`InstallService::validateLegalSettings()/applyLegalSettings()`); admin form is now step 8, admin creation step 9 (progress list, form actions, `currentStep` of the admin error re-render, bots/lang still passed on).
- `ContentPages`: default privacy text comes from `templates/texts/<lang>/dataPrivacy_general.txt` (de, en; other languages fall back to en) when `privacy_region=other`, default rules are empty when `rules_default=0`; edited page rows still win. `/rules` shows an empty notice (`rulesEmpty`) for empty rules.
- Board off: links removed from the game side panel, account top bar and login header; `board` pages (login, game, account) answer with the standard "page does not exist" message.
- `InstallLegalSettingsTest` (validation, storage, default pages, step rendering); golden `basic_*` values of `ConfigFieldSchemaTest` updated.

## 127. Production by default

- Checked-in `.env` defaults to `APP_ENV=prod`; DDEV sets `APP_ENV=dev` via `web_environment` in `.ddev/config.yaml`, so a deploy without the installer never exposes the profiler. INSTALL.md checklist updated.

## 128. Clear the game log from the admin area

- `ClearLogController` (`admin?page=clearLog`, POST only via `CsrfPolicy`, right `ShowClearCachePage`) truncates `var/log/game.log`; the maintenance page shows its size and last change in a new card with a confirm button.

## 129. Themed modals instead of native popups

- `Dialog.confirm()` (Promise<boolean>) and `Dialog.prompt()` (Promise<string|null>) in `app/dialog.js`, same `modal--alert` look, Esc/backdrop = cancel, focus returns to the trigger; `danger` styles OK as destructive (`.modal__actions .btn--danger`). OK/Cancel labels from `dialog_ok` / `dialog_cancel` (l18n, de + en, others fall back to en) via `data-dialog-ok/-cancel` on `<html>` of the game, admin, account and login layouts.
- `data-confirm` (`app/actions.js`) now asks via `Dialog.confirm`: the click is held back and, on OK, replayed once on the same element with a one-shot bypass (data-action, `data-method="post"` links and the submit button's name/value keep working). New `data-confirm-danger` attribute, set on the destructive admin actions (`btn--danger`) and on alliance close/kick/diplomacy delete/leave, apply-request delete and board post delete.
- Every native `confirm()`/`alert()`/`prompt()` replaced: rte link URL, recaptcha errors (game + account), `handleErr`, galaxy (mission/missile confirm, missile alert, reset), acs rename, marketplace buy, fleet table (no-name alert, delete), fleet step 2 send question (cancel click, `requestSubmit` on OK), admin sendMessages / active, battlesim copy fallback.

## 130. Advanced stats as tabs

- `page.advancedStats` shows its seven groups (expedition, build, lost, destroyed, repaired, firsts, other) as ARIA tabs instead of one table with toggled rows; page title fixed (was "Records").
- Generic tab component: `public/scripts/app/tabs.js` (`[data-tabs]`, `.tabs__tab`/`.tabs__panel`, arrow keys, last tab remembered, `#tab-<key>` hash) and tab-strip styles in `base.css` (components layer, shared with the tech tree tabs, which lost against the button rule in the legacy layer).

## 131. In-game hall of fame (battle hall) redesign

- `page.battleHall`: heading with the top-100 line, the four filters as one labelled filter card (4 columns, 2 on phones, auto-submit, noscript button), listing with gold/silver/bronze place badges, attacker ⚔ defender in win/loss colours, zebra + hover rows, date folded under the names on phones, legend as colour chips; styles `.bh*` in `game.css`.

## 132. Config schema split, bcmath gone

- `ConfigFieldSchema` (1778 → 187 lines) delegates to 23 per-category field classes in `src/Game/Core/Config/Schema/` (`Basic*Fields`, `Uni*Fields`, each `fields(Language)`); output byte-identical (var_export dump before/after), public API unchanged.
- Shipyard queue count uses native `BigInt` (exact integer part of the string); `public/scripts/base/bcmath.js` and its script tag removed.
- `CommunityLinksTest` checks the field definitions instead of grepping the schema source.

## 133. PHPStan, dependency updates, test suite green

- PHPStan 2.3 (`phpstan.dist.neon`, level 5, symfony + phpunit extensions, `composer phpstan`) with `phpstan-baseline.neon` for the 261 known findings; new code must pass without additions to the baseline.
- Real bugs fixed: regex delimiters in `AccountUtil`, uninitialised `$THEMESETTINGS` (`Theme`), `$planetQuery` (`ResourcePersistence`), `$Modules` (`ModuleController`), Cronjob container type; `: never` on the request-ending controller helpers (`sendJSON`, `redirectTo`, `redirectToHome`).
- Deps: phpmailer 7.2 (mail via SMTP verified), doctrine/dbal 4.6, doctrine-bundle 3.4.2, cron-expression 3.6, php-cs-fixer 3.95.31.
- Tests: `MarketPurchaseTest` loads the TECH language domain, obsolete bot greeting test removed (feature removed in 122), universe schema golden values updated; 853 tests green, no warnings.

## 134. Tooltips escaped as attributes, `|raw` audit

- Tooltip markup is built in `{% set %}`/include blocks and printed with `|e('html_attr')` everywhere (~40 sites; new `game/tooltip.element.html.twig` for the element name/image/description tooltip); `|tooltip_text` dropped from templates (it double-encoded under html_attr). All 406 tooltips render identical content; names with `"`, `&`, `<` stay literal.
- `|raw` audit: 18 uses replaced by `Twig\Markup` built in controllers (sanitised CMS/news/board HTML, tutorial, questions, search badges, vacation/delete notices) or by plain escaping (`|nl2br` in tickets); the remaining 7 carry a `{# raw: why #}` comment. `docs/templates.md` updated.

## 135. Smarty leftovers: `php_iter` gone

- All ~270 `|php_iter` uses removed from the templates (Twig's `for` already iterates nothing for null/undefined); `|default([])` where a value can be null/false (repairdock ships, cronjob error, redirect buttons, mission priorities); the cronjob overview controller wraps single month/weekday values in a list (`overviewTimes()`), the only scalar the filter ever received (proved with a logging probe over the full page crawl). The filter itself is deleted from `TemplateExtension`.
- Legacy table attributes in tooltip and fleet-step markup replaced (`w-100`, no `border/cellpadding/cellspacing`). Remaining inline `style` attributes are JS-owned visibility state or dynamic values (custom properties, bar sizes) and stay on purpose.

## 136. Typed game state for static analysis, PHPStan level 6

- `Singleton` declares the game state it carries (`@property`: `USER`/`PLANET` rows, `LNG` Language, `THEME`, `resource`, `reslist`, `pricelist`), so PHPStan checks their use instead of treating them as `mixed`; runtime unchanged (still the dynamic bag). Two by-ref baseline findings disappeared with the sharper types.
- PHPStan raised to level 6: every new or changed method/property needs parameter, return and iterable value types; the 1398 existing `missingType.*` gaps are baselined (native types are added when code is touched, not in bulk, because adding return types to overridden methods can fatal subclasses).

## 137. Audit fixes: tutorial rewards, market purchase races and blocks

- F-01: tutorial goals can only be fulfilled by request for link steps (`AbstractTutorialSupport::fullfillGoalByRequest()`, overridden by `LinkTutorialSupport`); mission and rename goals stay derived from server facts (fleet log, planet name, buddy/alliance rows), so `mode=fullfill` + `claim` no longer pays out rewards for work not done.
- F-07: `block_trade` (both directions) is enforced on public market offers too (`MarketPurchase::validateOffer` always runs `checkDiplo`).
- F-04: `MarketPurchase::buy()` locks and re-reads the buyer's user row, then planet row (FleetDispatch order) before the vacation, slot, offer and payment checks — parallel purchases can no longer exceed the fleet slots.
- Tests: `TutorialFullfillRequestTest`, `MarketPurchaseTest` (blocked public offers, stale second purchase).

## 138. Audit fixes: missile duplication, combat dodge, fleets without page views

- F-03: `destroyMissiles` runs in a transaction, locks the planet row and deducts relatively (`GREATEST(0, col - n)`) from the locked counts — a concurrent launch can no longer be undone by an absolute write of a stale copy.
- F-05: outward fleets can't be recalled once their arrival time has passed (the arrival event has to run first; holds keep their rules); due fleet events are processed on AJAX/fragment requests too (`FlyingFleetHandler::processDue`).
- Fleets advance without page views: `bin/console app:fleets:run` (one pass; DDEV daemon `fleets` loops it every 5 s) and cronjob `fleets` (every minute, migration `1794901500`); operator setup in `docs/INSTALL.md`.
- Tests: `FleetRaceGuardsTest` (stale missile write, recall after arrival, hold recall, event driver).

## 139. CSS cascade: named legacy sub-layers, components file, dead rules gone

- Layer order is explicit: `@layer reset, base, legacy.content, legacy.theme, components, utilities;` — the game imports `content.css` into `legacy.content` and the player theme into `legacy.theme` (themes now always win over content.css, independent of selector specificity). New `app/components.css` (layer `components`) holds unthemed form/table components that used to lose against the shared button/control rules: resources production table, fleet inputs and `.form-page` forms, market place, stats filter, search bar, message search/bulk controls, advanced stats table.
- ~40 lines of dead CSS removed (unused `.cw-*` widths, `.sim-split`, `.share-row`, `.achievement-name`, `.stats-target-info`, `#resource`, `#menu`, `#team content`, …; scanned against templates, scripts, PHP, translations, texts and stored DB HTML).
- Styling on classes instead of ids: `.repair-infobox`, `.repair-list`, `.ratio-baselines`, `.form-page` (ids kept only where JS needs them); `#marketPlace` prefixes dropped. Floats replaced by flex for the fleet slot counters (`.fleet-slots`) and the repair dock.
- Verified with the visual diff on 53 pages in all 10 themes (desktop) and nova on phone: no change beyond the noise floor.

## 140. Cron job names

- Missing admin translations for the cron jobs `bots`, `disposable_email_domains` and the new `fleets` added (all admin locales); the cron list showed the raw `cronName_*` keys.

## 141. `html_options` macro, lz-string module, diplomacy form fix

- The Smarty port `html_options` (Twig function + `TemplateExtension::htmlOptions()`) is replaced by the Twig macro `templates/_macros/forms.html.twig` (`forms.html_options({...})`, same params incl. nested optgroups); 80 calls in 51 templates, markup byte-identical (crawl diff), the PHP helper is deleted.
- `public/scripts/base/battlesim/lz-string.js` is an ES module (`export default LZString`, angular/UMD tail dropped) imported by `battlesim.js`; the classic script tag is gone; share strings unchanged (round trip verified).
- Alliance diplomacy proposal form: the level select got `'range(1,6)'`/`'range(7,7)'` as literal strings since the Smarty transpile (a single bogus option, rejected server-side) — now real `range()` values (levels 1–6, peace 7).

## 142. Typed game data model

- `App\Game\Model\ElementCatalog` / `Element` / `ResourceCost` / `CombatStats`: read-only typed value objects over the unit data (`resource`, `reslist`, `pricelist`, `requeriments`, `CombatCaps`, `ProdGrid`) via `GameUtilities::elements()` (built lazily per request, reset by `GameData::load()`); `GameUtilities::player()` / `planet()` return the typed `User` / `Planet` snapshots of the state rows for reading (writes keep using the state arrays — their in-place by-reference semantics stay untouched).
- `BuildFunctions`, `ResourceProduction` and the buildings, research, shipyard, empire, techtree, records and advanced stats controllers read the game data through the catalog (~105 array reads); pages render byte-identical (crawl diff). `ElementCatalogTest` proves the catalog equals the arrays for every element; `docs/game-data-model.md` documents the API for new code.

## 143. Audit fixes: missile bash, message limits, alliance admin dispatch

- F-06: missile attacks count toward the bash limits (universe setting `bash_count_missiles`, default on); missiles at one's own planet/moon are refused (`ma_own_planet`); the missile launch checks bash first.
- F-09: private messages: at most 10 recipients and 5000 characters (refused before any query), recipients resolved in one set-based query, rate limit 20 per 10 minutes per player (`private_messages` limiter, parameters `app.pm_rate_limit` / `app.pm_rate_interval`).
- F-11: the alliance admin dispatcher uses an explicit action whitelist (empty action shows the overview instead of recursing).
- Tests: `AbuseGuardsTest`; `GameTestCase` clears the shared game session in `setUp()` (a session left by one test made `SessionManager::create()` reuse it in the next).

## 144. Audit fixes: account editor boundary, Discord webhooks, colour settings

- F-10: the admin account editor applies `mayActOn()` (root user, equal/higher authlevel; self allowed like the quick editor) before every tab, resolving planets to their owner and checking alliance members.
- F-21: Discord webhooks only go to `https://discord.com|discordapp.com/api/webhooks/…` (also ptb/canary), no redirects; validated on save (`op_discord_hook_invalid`) and in `Discord::send()`.
- F-24: colour settings are validated as hex on save and sanitised on read (`ColorSetting`), so inline CSS only ever gets a colour.
- Tests: `DiscordTest` allowlist, `ColorSettingTest`, `AdminAuthorizationTest` (moderator vs admin vs root in the account editor).

## 145. Audit lows: fleet steps, ACS recall, trade escrow

- F-08: an ACS group recall (only the creator's attack fleet recalls the group) re-routes all fleets in one transaction and deletes the group rows last; a joiner's recall only turns their own fleet.
- F-12: the fleet-step release beacon only acts on POST (central CSRF check applies). F-13: fleet step 1 checks ship availability before storing the prepared fleet. F-25: fleet step 3 only dispatches on POST, GET redirects to the fleet table and leaves the token intact.
- F-26: a market purchase is refused when the seller's start planet is gone or destroyed (checked under the offer lock).
- Tests: `FleetStepGuardsTest`, `FleetLauncherTest`, `MarketPurchaseTest`.

## 146. In-game rate limits, board caps

- F-14/F-23: `RateLimitListener` + `GameRateLimits`: per-account token buckets for state-changing requests (`game_actions`, burst 30, +1/s) and expensive reads (`game_reads`: galaxy, player card, search, statistics, battle hall, reports, records — burst 60, +2/s); over the limit a themed 429 page (`Retry-After`) or JSON 429 for scripts; parameters `app.game_rate_limit.*` (switchable), operator notes in `docs/INSTALL.md`. Defaults are generous: normal play and a full 90-page crawl never trip them.
- F-22: no votes on one's own board posts/comments; daily caps of 200 votes and 50 posts+comments per account (`app.board_votes_per_day`, `app.board_writes_per_day`).
- Tests: `GameRateLimitsTest`.

## 147. Balance settings for the audit's design findings (defaults unchanged)

- New universe settings whose defaults reproduce the previous behaviour exactly, so operators decide: `market_push_tolerance` (F-02, default 30 %, replaces the hard-coded market push tolerance), `transport_push_check` + `transport_push_limit` (F-02, default off: refuses large one-sided transports/holds without a return delivery in 7 days), `honor_pair_cap` (F-17, default 0 = unlimited honorable fights per pair and 24 h; table `honor_fights`, migration `1794902000`), `bash_war_exempt` (F-18, default on), `depot_cost_real_consumption` (F-20, default off: alliance depot charged by the fleet's real consumption).
- Tests: `BalanceSettingsTest` (defaults = old behaviour, each switch's effect, push guard cases).

## 148. Admin abuse report

- New read-only admin page `abuseReport` (Moderation → Abuse report, right `ShowAbuseReportPage`): one-sided transports/holds between player pairs, repeated market trades with ratio outliers, rebuild-protection grants with the opponents' loot, recalls at or after arrival — each with shared-IP / multi-group markers (audit F-02, F-15, F-16, F-19). Values in metal units at the stored market ratios; every query limited to 100 rows (~0.2 s on 667k fleet log rows, no new index).
- Tests: `AbuseReportQueryTest`.

## 149. PER-CS everywhere

- One code style for all PHP (`src`, `tests`, `config`, `install`, `bin`, `public`): `.php-cs-fixer.dist.php` with `@PER-CS` + short arrays; the separate legacy PSR-12 config is removed. 119 files reformatted (formatting only: tests, PHPStan, lint and the page crawl are unchanged).

## 150. Unit data only through the element catalog

- All reads of the former state arrays `resource`, `reslist`, `pricelist`, `requeriments`, `CombatCaps`, `ProdGrid` (about 810 accesses in about 90 files: game core, fleet, market, statistics, achievements, tutorials, cron, all controllers, admin, missions, bots, battle engines) go through `ElementCatalog`; statistics code uses per-universe catalogs (`fromVarsData`).
- `GameData::load()` stores only `$state->elementCatalog` (built straight from the vars cache); the six arrays are no longer published. `elements()` fails clearly when game data is not loaded.
- The catalog keeps the numeric type of the game data (`Model\Numeric`: ints stay ints, floats stay floats), so results are identical: both battle engines produce strictly identical results and Rust input JSON for three reference fleets before/after (only run times differ); 923 tests, the page crawl and the visual diff unchanged.
- Tests read the unit data through `GameTestCase::elements()`; `ElementCatalogTest` compares against a reference loader of the raw vars cache. Fixed on the way: galaxy rows with ship ids missing from a universe (tolerant lookups), the account editor ship-delete log value.

## 151. Typed player and planet rows

- `$USER` / `$PLANET` stay arrays at runtime (in-place writes and copy semantics unchanged) but are typed for static analysis: open array shapes `UserRow` / `PlanetRow` in `phpstan.dist.neon` (known columns with their real types, further keys open), declared on `Singleton` (`USER` read-typed, write `array<string, mixed>` for the guest row). PHPStan level 6 green without baseline additions; documented in `docs/game-data-model.md`.

## 152. Audit round 3

- Admin: ban form writes exactly the id the staff boundary checked (no `ban_id`/`unban_id` split); account bans flag the players in every universe and revoke the account's sessions; the in-game gate also refuses players of a banned account. Message list, flying fleets and analytics respect `mayActOn` (no reading staff PMs, no recalling/locking higher staff fleets, fleet return scoped to the universe); support modes whitelisted; quick-editor password-only changes are logged; universe create and cronjob edit change state on POST only.
- Request layer: `CsrfPolicy` compares query values the way `HTTP::_GP` reads them (NUL stripped, trimmed, case-folded), so padded `action`/`mode` no longer skip GET rules; `?mode=` dispatches only methods without required parameters; unknown `?uni=` falls back to the default universe instead of a 500.
- Players: alliance ranks enforce the hierarchy server-side (no editing/deleting/assigning away ranks that carry rights the actor lacks or the own rank); Discord webhooks send `allowed_mentions: none`; PM composer receivers capped, loaded in one universe-scoped query; recipient/player search escapes LIKE wildcards, autocomplete limited to 20; alliance info scoped to the universe.
- Economy: abandoning a colony with running research no longer moves its paid cost to the main planet on cancel; repair dock actions need dock level ≥ 1 server-side; multi-player fights apply the honor pair cap and the 1-vs-1 neutrality rules (ally, buddy, inactive defender); unknown `prod[]` ids are ignored.
- Fleets: missile launch serialised (user → planet lock, bash check and missile stock inside the transaction); phalanx never shows the owner view of the scanned player's fleets; pirate-base fleets no longer match planets with the same numeric id (phalanx list, target name, moon destruction re-homing).

## 153. Audit round 2 follow-ups (DS_AUDIT2)

- Push guard (`transport_push_check`): covers transfers (ships valued at cost), compares the 7-day value balance of the pair (sent − returned, this fleet included) against `transport_push_limit` instead of "any return delivery"; defaults unchanged. Admin overview warns about every protection that is off.
- ACS recall rolls back completely when a member's event was claimed (group survives); MIP arrival locks the target planet and deducts with `GREATEST(0, …)`; market purchase locks the seller planet; honor pair cap counts under user-row locks; missile slot exemption explicit (`MISSION_MISSILE`).
- Rate limits: anonymous `raport`/`board` per IP (`game_anonymous`, 30 + 1/2 s); read-only ajax modes (`fleetStep1:checkTarget`, `fleetStep2:checkResources`, `messages:loadUser`) in the reads bucket; tickets 3/h, 10/day, max 5 open; alliance applications 10/day; PM receivers ignore non-scalar ids.
- `trade_charge` bounded 0–90 % (float fields clamp min/max); `ref_max_referals` enforced at registration; rights page writes admin log entries; abuse report includes transfers (ships at cost), explains its thresholds and marks shared /24 (/64) subnets; reports without participant lists are reduced for outsiders, report ids from `random_bytes`.
- Collusion guards (`AccountLinks`: shared IP, multi group, optionally alliance/buddy): rebuild protection only after losses to at least one unlinked opponent; the war bash exemption does not apply between linked accounts.
- `docs/INSTALL.md`: anti-abuse settings in the security checklist plus a table with defaults and recommended values; the missile fleet-slot exemption is documented as a rule.
- Market reference ratio: each pair of traders counts with at most the combined volume of all other pairs (a lone pair leaves the stored ratio), so wash trades can't steer `isPush`.
- Balance switches default to the safe values (migration 1794902100 updates universes still on the old defaults): `transport_push_check` on with `transport_push_limit` 100000, `market_push_tolerance` 10, `honor_pair_cap` 3, `bash_war_exempt` off, `depot_cost_real_consumption` on; explicitly changed universes keep their choice.
- Device links: `br0_device` cookie, salted hash per player in `user_devices` (migration 1794902200, 90-day purge), shared devices link accounts (`AccountLinks`, abuse report marker). Abuse report: value-flow clusters over 30 days (transports, transfers, trades, loot) and a 24 h fleet-launch activity list. Per-account daily caps on fleet launches/attacks (`app.fleet_launches_per_day`, `app.attacks_per_day`, 0 = off).

## 154. Strip and modernize round

- Dead code out: 11 broken PHP migrations (+ stub), 52 unused constants, ~150 lines of zero-caller methods, legacy `serialize` queues (`StoredDataMigrator`), the legacy session object fallback, pre-r2321 report conversions, root `stats_*.json` output, Google Analytics keys, `users_settings.tcg_token` (migration 1794902300), `ext-curl` requirement, sendmail branch, dead JS globals/exports, `bsim.json`, `boilerplate.css`, unused CSS utilities. Shared `SpyMissionSupport` trait and one `BattleReportStore` for the four report inserts.
- Web layer: `.php` URLs gone (no LegacyUrlRedirectListener, no `_ext` routes; `/internal_sso_resolve.php` stays), `#[GamePage('<area>', '<page>')]` with one route per page and an optional `uniN/` prefix (routes 278 → 156), `#[RequireModule]` attribute, shared Team/BanList/Error/Logout logic in `src/Controller/Shared/` traits, board/rules redirects in one `PageRedirectController`, Twig helpers that duplicated built-ins removed (`count`, `round`, `json_encode`, `min`/`max`/`range`, …), unused template variables dropped.
- Voting rebuilt as a module (`MODULE_VOTING`, off by default): admin-managed `vote_sites` (reward, cooldown, verification `none`/`postback` with per-site secret) and `vote_log` (migration 1794902400 drops the old voting tables), game page, rate-limited `/vote/callback/{siteId}`, 90-day log purge.
- reCAPTCHA replaced by a self-hosted proof-of-work check (`ProofOfWork`, `/pow-challenge`, Web Worker solver, honeypot + minimum fill time, single-use challenges) on registration and lost password; RECAPTCHA table and settings dropped (migration 1794902500); privacy texts updated.
- Themes: gow, EpicBlueXIII and the community pr0gress copy removed (migration 1794902600 moves their users to the default), duplicate SetSail moons served through `ThemeAssets::moonAliases()`, unreferenced images removed, 753 animated planet variants re-encoded (121.9 → 71.4 MB, frames/timing unchanged); `public/styles` 139 → 83 MB.
- Translations: 460 dead keys and the `changelog.*` domain removed; all 15 locales complete against en (nl, th, tl, uk, vi, zh got admin + install), privacy texts translated everywhere.
- Changelog lives in `CHANGES.md` (rendered by the changelog page); `docs/CHANGELOG.md` is gone.
- Adaptive proof-of-work difficulty (`PowRisk`, admin switch `pow_adaptive`, default on): counters per IP and /24 (/64) for issued challenges, failures and successful checks raise the puzzle ×20 / ×100 (capped by `pow_max_cap`, ~25 s on a slow phone) and the minimum fill time; max and wait are signed into the challenge; base `pow_max_number` 25000. Tiers, factors and sensitivity are editable in the proof-of-work settings.

## 155. Release 1.26.2

- `install/VERSION` 1.26.2 (tag `v1.26.2`): three security audit rounds (ban bypass, CSRF padding, staff boundaries, push guard with value balance, collusion guards, safe anti-abuse defaults, device links, value-flow clusters, daily fleet caps, adaptive proof-of-work instead of reCAPTCHA), voting as an admin-managed module, `.php` URLs retired, typed game data model and PHPStan level 6, PER-CS, themed modals and hall of fame redesign, data-driven hall of fame, all 15 locales complete, `public/styles` 139 → 83 MB (steps 120–154). Upgrading runs migrations 1794901300–1794902600 (applied automatically by the upgrade check).

## 156. Modernization round: enums, services, typed state, path routes

- Enums `Mission`, `MessageType`, `AuthLevel`, `PlanetType` (incl. `PirateBase`), `DiploType` in `src/Game/Enum/`; the old constants are deprecated aliases, magic numbers replaced.
- `PlayerUtil` facade deleted: call sites use the `Player\*` services through `GameUtilities` accessors; deprecated array twins removed.
- `Singleton` state declared as typed properties (no magic `__get`/`__set`); PHPStan baseline 8776 → ~5100 lines.
- Twig: `empty()`, `isset()`, `php_const()` replaced by Twig built-ins (`not x`, `is defined`, `constant()`).
- JavaScript: fleet steps, fleet table, galaxy, market, simulator and messages modernized; duplicate config/logout scripts merged, globals replaced by imports.
- Controllers: every area uses `#[GameRoute]` path URLs (`/game/fleet/step1`, `/game/galaxy/{galaxy}/{system}`, `/admin/bans`, `/register`, `/account/board`, public reports at `/report/{rid}`; optional `uniN/` prefix), typed `RequestInput` instead of `_GP`, returned Responses instead of `EndOfRequest` in actions, render context; CSRF rules and rate limits keyed by route; old `?page=` URLs removed (stored report/application links rewritten by migration 1794902700). `PageModeDispatch`/`GamePage` deleted; conventions in `docs/controllers.md`.
- Fixes on the way: admin expedition deck save (503), overview fragment (503), account/login assets at deeper paths (`<base href>`), admin elevation limiter now resets on success, an order-dependent abuse report test.

## 157. Slimmer code, faster requests

- Slimming: enum alias constants and the `HTTP::_GP` input layer removed (all input through `RequestInput`), 46 empty `initialize()` overrides, unused imports in 111 files and other dead members gone.
- Request path: universe config cached in `var/game/cache.config.php` (invalidated by every writer), cached migration check, fleet-event precheck, merged counters, idle tutorial without queries, read-only GETs skip the resource-update transaction (writes keep the locks), one PDO init statement, lazy CSRF/translator/limiter services, import map built once. Overview 38 → 25 queries; medians about -15 % untuned, more with the documented production OPcache settings (`docs/PERFORMANCE.md`, `docs/INSTALL.md`).
- Galaxy: index `messages(message_group, message_type, message_time)` (migration 1794902800; system switch 168 → ~28 ms at 100k messages), system switching without page reload (`/game/galaxy-system` fragment, pushState, deuterium still charged once per displayed system), module flags and phalanx range computed once per page.
- Tooltips rendered once as `<template>` (`TooltipExtension`, `data-tooltip-ref`): galaxy 258 → 108 KB, buildings 121 → 63 KB, research 95 → 54 KB.
- Messages: one query for per-type/unread/favourite counts, one window pass, text loaded for the shown page only, recipients in one query, rendered spy reports cached; statistics page cut in SQL before the joins, `statpoints` rank indexes (migration 1794902900), indexed last-run lookup.
- Frontend: brotli/gzip for text/JSON/SVG and immutable caching for versioned assets in `public/.htaccess` and `docs/nginx.md`, font preload, lazy building images.

## 158. Release 1.26.3

- `install/VERSION` 1.26.3 (tag `v1.26.3`): path routes in every area, game enums, `PlayerUtil` replaced by services, typed `Singleton` state, `_GP` input layer gone, faster request path (cached universe config, fewer queries, galaxy without reload, tooltip templates, compressed/cached assets) (steps 156–157); README now names the `public/` document root for script installs too has a performance section (page/job numbers before → now) and screenshots (gallery retaken on the current UI); INSTALL troubleshooting covers 403 on `/install/` (wrong document root); admin bot spawn has a "mixed (all profiles)" option (`BotSpawner::spawnMixed()`, shared with the installer). Upgrading runs migrations 1794902700–1794902900 (applied automatically by the upgrade check).

## 159. Cron hints and a README for non-technical readers

- Admin home page warns when the cron job never ran or its last run is over 5 minutes old (`OverviewController::cronWarning()`, keys `adm_dash_cron_never`/`_stale`/`_help` in all admin locales), with a link to the cron section of `docs/INSTALL.md` (`InstallService::CRON_DOCS_URL`).
- Cron help link on the installer's last page (`ins_done_cron_help`, all install locales), in `app:install`'s summary and in `install.sh`'s closing steps.
- README reworked for non-technical readers: what the game is, a hosting check (PHP 8.4, database, cron every minute, warning about 15-minute cron plans), plain-language ZIP steps, "After installing" and "Something does not work?" sections, performance numbers moved below the setup parts; outdated lines fixed (seven themes, 16 locales, voting module, php-cs-fixer config, "Admin → Information", files to keep on ZIP updates).

## 160. Release 1.26.4

- `install/VERSION` 1.26.4 (tag `v1.26.4`): cron warning on the admin home page with setup help, cron help links in the installer, `app:install` and `install.sh`, README reworked for non-technical readers (step 159). No database migrations.

## 161. Session settings, stay logged in, migration runner, cookie notice, clock

- Session idle limit is the admin setting `session_lifetime` (hours, default 12, category user settings) instead of the `SESSION_LIFETIME` constant (kept as deprecated default); `App\Security\SessionPolicy`, used by `GameBootstrap::syncGameSession()`, `Session::isValidSession()` and the cleaner cronjob. Session cookie is a browser-session cookie now (`cookie_lifetime: 0`), `gc_maxlifetime` follows the setting (`App\Security\SessionStorageFactory` decorates the native storage factory). The admin universe cookie hack no longer passes a relative lifetime as expiry.
- "Stay logged in": firewall `remember_me` with `App\Security\ConfigurableRememberMeHandler` (Symfony's signed cookie `REMEMBERME`, signature over the password hash, lifetime from the setting `remember_me_days`, default 30, 0 = off), checkbox `_remember_me` on the login form (`loginRememberMe`, all public locales). An idle game session with the cookie is dropped (`Session::discard()`, `GameAuth::restartRememberedLogin()`) and the next request logs in again instead of showing "session expired".
- One migration runner (`App\Game\Core\MigrationRunner`) for the installer's upgrade mode and the new admin page `/admin/migrations` (pending list, run button, per-migration result; linked from maintenance, reachable while migrations are pending).
- Optional cookie notice (global setting `cookie_consent`, admin category legal, installer legal step): `templates/shared/cookie_notice.html.twig` in the login, account and game shells, choice in the `cookie_consent` cookie (`all`/`necessary`), `hasConsent()` in `scripts/app/consent.js`.
- In-game clock: server-rendered first text (no pop-in), seeded with millisecond render time (`dateMs`), ticks aligned to full server seconds instead of a free-running interval (no skipped or doubled seconds after a reload).
- Combat report redesigned (both engines): `shared.mission.raport{,.rust}.html.twig` plus new partials `.head`/`.extras`/`.macros`; result hero (winner/loser/draw with icon and text), summary cards (debris, loot, moon, rebuild, wreck field, honor points), collapsible rounds (`<details>`, last open) with per-side tables and survival bars; `.cr-*` section in `components.css` (container queries, fits page and modal), old `.raport-*`/`.stats-*` rules removed from `content.css`; `raport.js` statistics panel uses Lucide sprite icons instead of emoji; rust defence rebuild figures corrected; `cr_*` keys in all fleet locales.
- Admin: in-page `#…` links (settings jump list) no longer leave the page under `<base href>` (`scripts/app/anchors.js`); creating/deleting a universe returns to the universe list (`redirectToRoute('admin_universe_show')`, no `?reload=r`); `ttf_file` is a dropdown of the fonts in `public/styles/resource/fonts/`, read through `App\Game\Core\Fonts::resolveTtf()` (only fonts inside that folder, falls back to DroidSansMono).
- Admin audit (crawl of 231 admin pages, links and form actions): account editor alliances tab no longer 403s without an alliance id (`guardTarget()` skipped `ally_id = 0` members); tooltip triggers `href="#"` no longer navigate under `<base>` (`tooltip.js`).
- Missing config defaults are inserted with `INSERT IGNORE` (`ConfigRegistry`), so cron and page requests adding the same key no longer fail with a duplicate key; `AccountUtil::get_account()` returns `[]` for unknown accounts and the game layout's deletion notice skips guests (message pages on public battle reports threw a type error).
- FAQ rebuilt: 48 entries in 11 categories checked against the game code (en/de written, of/pi German, other locales fall back to English for entries), new index/article templates, `QuestionsController` builds catalog, TOC and prev/next; `faq.css`/`faq.js` (full-text search with highlights and `?q=`, `/` and Esc keys, heading anchors with copy link); fixes: moons start with 1 field, hold costs, moon destruction formulas, defence repair 60–80 %.
- Statistics JSON export writes directly to `public/stats/` (created on demand, files older than 7 days are deleted, universe name sanitized for the file name); the `stats/` folder and the `public/stats` symlink are gone from the repo, docs/README/install.sh updated (ZIP upgrades keep `public/stats/`).

## 162. UX review fixes, one type scale, consistent themes, update check

- In-game UX review (139 findings, `UX_REVIEW.md` outside the repo) fixed across all seven themes: build/research/hangar cards show only real deficits and say why an item is locked or maxed, queue title no longer requeues, stray build-time colon gone, info page with artwork/costs/requirements, empire without inner scrollbar (sticky header row / first column), resources "full in" timers and dirty Save, market/fleet trader/repair dock/achievements/techtree clarified; building 34 easter egg removed.
- Overview: fleet events first, as an accordion (`<details>`, +/− on the right, state in localStorage, collapsed header with count and next arrival, forced open with a warning header for hostile fleets; `FlyingFleetsTable` hostile flag); tutorial uses the same accordion.
- Fleet: summary bar on the destination and mission steps (`shared.fleetSummary.html.twig`), target shown instead of origin, "Send fleet", inline errors, speed/cargo columns, quick fleets (last mission, custom fleets), post/redirect/get on all steps incl. a result route (`game_fleet_step3_result`), recall confirmation with return time, flying fleets as cards on phones.
- Galaxy: action icons (spy, attack, transport, message, buddy, missile, phalanx), phone card layout, toast for spy/recycle results (`NotifyBox` options), legend, tab order and live-region announcements.
- Messages/notes/simulator/phalanx: delete confirmations, empty states, duplicate subjects hidden, spy report footer with Attack/Simulate, simulator without phone overflow, direct `/game/phalanx` links redirect into the game.
- Login, social, settings: contact hint when mail is off, register layout, lobby status, alliance search list, labelled danger actions with confirms, search button/Enter/no results, settings jump links + sticky save, player card header, statistics sub-nav, records/advanced stats empty-row toggles, readable changelog, rules table of contents, `<label for>` on forms, theme previews.
- Shell: phone resource bar with 3 significant digits, cap and fill bar, drawer close button, scroll hints, correct active nav entry; Hall of Fame and Battle Hall named consistently in all locales.
- One type scale for the game area (13/12/11 px tokens in `game.css`, nothing below 11 px), one corner-radius and border language per theme (`--radius*`, `--btn-radius`, `--pill-radius` tokens; square themes fully square), visible focus rings in every theme (`--focus-color`), shared `.empty-state`, `.reason`, `.hint` components.
- Admin update check: `App\Runtime\UpdateCheck` asks the GitHub latest-release API (HttpClient, 3 s timeout, generic User-Agent, app cache 6 h / failures 30 min, errors only logged) and compares with `install/VERSION`; the dashboard shows an update notice (release notes, update guide, POST "check now" at `admin/overview/update-check`) or an up-to-date line; global setting `update_check` (server parameters, default on) turns off all requests; endpoint parameter `app.update_check_url`.
- CI green again (step 161 broke it): docblocks for the remember-me handler and session storage factory, `FleetStep1/2Controller::show()` and `ResourcesController::show()` split below 80 lines (`selectionQuery()`, `pirateBase()`, `stepInput()`, `resolveMission()`, `storeFlight()`, `secondsUntilFull()`), tests follow post/redirect/get in the fleet steps, the phalanx GET bounce and the new config keys (golden `basic_fields`).

## 163. Release 1.26.5

- `install/VERSION` 1.26.5 (tag `v1.26.5`): configurable session lifetime and "stay logged in", shared migration runner with admin page, optional cookie notice, stable in-game clock, redesigned combat report, rebuilt FAQ, admin link fixes, stats export in `public/stats/` (steps 161), in-game UX review fixed in all seven themes, events-first overview, one type scale and per-theme radius/border/focus consistency, admin update check (step 162). No database migrations; ZIP updates keep `public/stats/` instead of `stats/`.

## 164. Console fleet runs fixed, honor ranking without N+1

- `Universe::getEmulated()` falls back to the current universe (uncached) when there is no request session: `app:fleets:run` and the fleet cronjob failed every transport arrival with "No session available (console run?)" (`MissionCaseTransport` → `Log` → emulated universe) and quarantined it for 300 s (test `ConsoleEmulatedUniverseTest`).
- `AdvancedStatsHandler::getHonorList()` joins the honorable/dishonorable fight counters instead of two queries per listed player (about 1.2 queries per player of the universe on every battle via `HonorPoints::get_honor_percent()`); same rows and order (test `HonorListTest`).
- `AdvancedStatsHandler::increment()` remembers each player's universe per handler instead of one `SELECT universe` per counter update.
- Effect on a seeded world (1500 players, bench of 2026-11-18): attack mission with medium fleets 524 → ~55 ms (2020 → ~155 queries), large fleets 663 → ~215 ms; 110 mixed fleet events (400 players) 6.6 → ~2.5 s (23.4k → 7k queries).

## 165. Mechanics checkup fixes: moon destruction, fleet market, satellites, shield domes

- `PlanetManager::deletePlanet()` no longer touches the session: moon destruction in console fleet processing threw "No session available", was rolled back and re-rolled until the moon survived; admins deleting a planet lost their own planet selection (test `ConsoleMoonDestructionTest`; regression tests for step 164: `ConsoleEmulatedUniverseTest`, `HonorListTest`).
- Market: fleet offers are buyable again (`MarketPurchase::planBuyerTrip` sized the buyer fleet from `resource_amount`, 0 for fleet offers, and the flight math threw); partner-only offers no longer count two alliance-less players as one alliance (`MarketRules::checkDiplo`); the seller's max flight time (`filter_flighttime`) is enforced at buy time and shown as "too far" in the listing (new keys `market_p_msg_too_far`, `market_buyable_too_far`, all locales; test `MarketOfferFiltersTest`).
- Solar satellites finished during an update only produce from their finish time: `HangarQueueProcessor::productionCheckpoints()` + stepped resource update in `ResourceUpdate` (at most 48 steps per queue entry, unchanged path without producing units in the queue; test `HangarProductionStepsTest`).
- Shield domes (407/408) are buildable defence again: their vars rows had class 0 and the class in `ship_type` (shifted columns, like the missiles in migration 1794787211); 409-411 get class 401 (not buildable). Migration 1795007572 + `install.sql` (test `ShieldDomeClassTest`, golden price of 407 now per unit).

## 166. Mechanics checkup fixes: display, loot split, statistics, notices

- Resources page: the fusion plant's deuterium burn is no longer scaled by the energy factor, and without energy consumers the factor is 1 (same rules as `ResourceProduction`).
- Expeditions that find no ships keep their ship list for the return flight (`ExpeditionShipEvent` wrote it empty).
- ACS loot: the rounding remainder goes to the first fleets with free capacity instead of always the first fleet (`BattleHandler`).
- Statistics: alliances keep their previous ranks (rank change was "-rank" for every alliance), hidden/banned players keep theirs, units on abandoned planets no longer count, records ignore banned players and hidden staff for maxima and holders, the player card shows no ranks for players the statistics hide, the rank-change tooltip no longer prints "--1" (test in `StatbuilderTest`, records golden updated).
- Trade hold message links the right fleet id (`MissionCaseTrade`, notice `fleet_id`).
- Notices: galaxy view (`GalaxyRows`, missing VOID_TITAN element), config schema labels in console runs (`ConfigFieldSchema` loads the TECH texts), console query timer (`Database`, `time['db']`).

## 167. Installer dependency, market success message

- `symfony/process` (8.1.*) is a runtime dependency: `app:install` uses it, but it only came in through php-cs-fixer (dev), so the CLI installer crashed on `composer install --no-dev` (lock: package moved from dev to runtime, same version).
- Market purchase success message counts the buyer's cargo ships instead of the seller fleet's (`MarketPurchase`; tests `MarketPurchaseTest`, `MarketOfferFiltersTest` follow).

## 168. Rename Br0Game → V0Game

- Brand renamed everywhere except the pr0game/2Moons/SteemNova credits: texts in all locales, wordmark `V<b>0</b>Game`, installer, README/docs, composer package `voidys/v0game`, GitHub links `github.com/Voidys/V0Game` (update check, issues, source, user agents), release ZIP `v0game-<version>.zip`, env vars `V0GAME_*`, backup files `v0gameBackup_*`, LLM client headers.
- Default theme folder `br0game` → `v0game` (`DEFAULT_THEME`, `install.sql` defaults); device cookie `br0_device` → `v0_device` (existing cookies are re-issued).
- DDEV project `v0game` (https://v0game.ddev.site), `.ddev/apache/v0game.conf`, `.ddev/php/v0game.*.ini`.
- Migration 1795033242: moves accounts/settings and `default_theme` from `br0game` to `v0game`, renames the game name `Br0Game`, points `source_url`/`git_issues_link` at the new repo, updates the column defaults.

## 169. QoL pass and phone shell

- Fixes: flying-fleet columns relabelled (arrival at target / return, they showed swapped meanings) + target countdown; number inputs select on focus instead of clearing (`ui.js`).
- Build pages keep the scroll position after build/cancel/order (sessionStorage); queue header "all done at … (in …)" for building/research/shipyard; resource tooltip with +X/h and live "full in", opens on tap; planet prev/next buttons and `[` / `]` keys.
- Fleet: target step skipped when target + mission come with the link (`direct=1`, step 2 revalidates, "change target" link); return time on the send step; noob/strong protection and empty-cargo checks before sending (`FleetTargetProtection` trait, same rules as dispatch); "spy again" on spy reports; resource inputs empty with the stock as placeholder (step 2 "selected resources" hidden when nothing was typed); carried resources column on flying fleets.
- Messages: one-click mark all read / delete read / delete older than 7 or 30 days (`MessageMailbox` actions `deletereadtype`, `deleteolder7/30`, favourites kept).
- Login enters the only universe directly (`account?enter=1`; the plain lobby URL always shows the lobby).
- Statistics open on the 100-block with the own rank, prev/next 100, rank-change column, selectors in one row on phones; settings unsaved-changes counter + leave warning; player card galaxy/note actions; buddy icons in stats and member lists; combat report: player names link to the card, participant actions (recycle, attack again, galaxy, simulate via `battleinput`).
- Phone shell (≤40rem): compact top bar (planet row scrolls away, resources stay), bottom tab bar (`main.tabbar.html.twig`: overview/buildings/galaxy/fleet/menu), drawer with planet list and account block, sticky page head with the running queue entry, denser build cards, 44 px tap targets; tablet top bar in one row. Overflow fixes for fleet, settings, simulator, notes; empire with sticky label column and folded charts; message chips scroll in one row.

## 170. Side menu diet, user menu, tabbed settings (1.26.7)

- Side menu 37 → ~16 links: grouped pages share one entry + a link tab strip above the content (`main.sectiontabs.html.twig`, included by `layout.full`). Groups and their module/tutorial gates live once in `App\Twig\SectionGroupsExtension` (`section_group()`, `section_group_of()`): empire+resources, research+techtree, shipyard+defense+repair dock, rankings (statistics, records, battle hall, achievements, advanced stats, ban list). The old rankings `subnav` macro and CSS are gone; help links are an icon row (`.nav__iconrow`), the personal group moved out.
- Top bar user menu (`.usermenu`, `ui.js userMenu()`): settings, account, notes, data privacy, admin, logout; Escape/outside click/focus-out close it. The phone drawer header got notes/account/privacy icons.
- Settings page as a tabbed form (generic `tabs.js`, `[data-tabs="settings"]`): one form, field names unchanged, tabs with unsaved fields get a dot, an invalid field in a hidden tab opens its tab; the jump list is gone.
- Research: techs that only lack this planet's buildings (no lab, lab too low) stay listed, locked with a reason and a disabled button (`blockedHere`, `researchRequirementsMet()`); the no-lab / lab-upgrading notices are visible infoboxes. Queue commands are still refused server-side.
- Hover prefetch of plain game pages via speculation rules (nonce'd inline script in `layout.base`; logout, phalanx, messages, query-string and JS-driven links excluded) and a cross-document view transition (`@view-transition`, shell parts keep their place, reduced motion respected).
- New ingame keys in all locales: `ui_nav_rankings`, `ui_nav_sections`, `ui_user_menu`, `bd_no_lab_here`, `bd_on_this_planet`.

## 171. Build pages list everything, navigation extras rolled back (1.26.8)

- Buildings, shipyard, defense and research always list every enabled element of the planet type; missing requirements, resources, queue space etc. keep their reason line and add a greyed-out button (`buildcard` macro `locked()`, `.build_submit--locked`); max level keeps only its reason. The "show all buildable elements" setting is gone from the settings page (column kept, no longer read).
- Shipyard/defense without a shipyard: the list is shown locked under a "build a shipyard first" notice instead of a message page; orders are still refused (`hangar()`).
- Rolled back the 1.26.7 speculation-rules prefetch and the cross-document view transition: hover prefetches hold the file session lock for a whole render, so clicks queued behind them while a page was loading.

## 172. UI 2026: new shell, strict layout, tokens-only themes

- Theme contract `css/app/theme-contract.css` (layer `contract`): ~25 `--theme-*` variables with the V0Game defaults, mapped onto the `--c-*`, button, nav, modal, progress and galaxy tokens; one radius `--r` (2px default, max 4px); spacing scale `--space-1..8`, `--gap`, `--pad`, motion tokens.
- Layer order (base.css): `reset, base, contract, theme, legacy.content, legacy.theme, components, pages, theme-extras, utilities`; themes are linked normally and declare `@layer theme` / `@layer theme-extras` themselves.
- All 7 themes rewritten to tokens only (50–60 lines each: palette, fonts, optional `body { background-image }` and a short decoration layer); their layout rules are gone. A page background image is a body rule in the theme file (a relative `url()` inside a custom property resolves against the stylesheet that uses it).
- Strict layout ruleset `css/app/ui.css` (page head, panels with head/body/foot, stacks, grids, stats, toolbar, segmented, badges/tags, level chips, meters, kv, empty states) + `_macros/ui.html.twig` (`page_head`, `panel_head`); rules in `docs/ui-layout.md`. The legacy `img`/nested-table 25 % rounding of content.css is reset in the game.
- Page CSS split into `css/app/pages/{overview,build,galaxy-fleet,messages-alliance,misc}.css` (layer `pages`); all game templates ported to the blocks (overview hero with orbiting moon, build cards with cost tiles and finish times, compact galaxy table, message rows, tables/forms on one row/control height).
- Shell: sticky top bar on the shell width (planet switcher popover, resources with storage meter, palette button, messages, clock, user menu popover), grouped side nav, right rail with the player's planets and moons (`planetList`, `planetSlots` from `AbstractGameController`), hostile-fleet alert bar (`hostileAlert`, one query), new footer; `main.tabbar` removed (drawer covers phones). Empire page context key renamed to `empireList`.
- Overlays: `scripts/app/{popovers,palette,shortcuts}.js` + `overlays.css`: shared tooltip bubble (`data-tip`, `data-tip-kbd`, title, aria-label), native popovers with anchor positioning + JS fallback, command palette (Ctrl+K, `/`, coordinates), shortcut sheet (`?`, `g o/b/g`), dialog/toast transitions; strings via `game-env.ui`.
- Shared components restyled on tokens (tables 40px rows/hairlines, 34px controls, buttons, queue strip, segmented tabs, modal, progress, combat and spy reports).
- Docs: `docs/themes.md` rewritten as theme creation guide (contract, folder layout, check list) + image lookup; new `docs/ui-layout.md`. Static design reference stays in `public/demo/ui-2026/`.
- Follow-ups: compact numbers (`ViewHelpers::millionsNumber()` top bar ≥ 1 bn, `compactNumber()` card costs ≥ 10k, ≤ 6 alphanumerics, Twig `millions_number`/`compact_number`, same rule in ticker.js); cost tiles with resource icons and exact amounts in tooltips; cards aligned per row via subgrid; no hover/press effect resizes or moves cards or buttons (press = brightness); text links get a growing 1px line instead of an underline; animated `<details>` accordions (`::details-content` + `interpolate-size`) with a drawn +/- sign; grids stretch to equal heights; footer moved into the shell grid (side menu column spans all rows, stays sticky on short pages); no needless inline scrollbars (popovers, tab strips, tables, galaxy on phones); rail moon sizing fix; queue cancel button in the item's top right corner; ranking/search row actions as `.row-action` icon buttons; old `img/[bemr].gif` icons replaced by Lucide.
- Phones (≤ 60rem), thumb first: bottom tab bar `main.tabbar` (overview, buildings, galaxy, fleet, menu with an alerts badge) instead of the top menu button; menu, planet switcher and account menu as bottom sheets above it; `viewport-fit=cover` + safe-area insets; fixed bottom elements (fleet dock, notify, cookie notice) clear the bar; `.page-fab` floats a page's main action (write message) above the bar; touch (`pointer: coarse`): 44px controls, 16px inputs, taller link hit areas, bigger galaxy/row actions; compact overview header and message categories on phones; Lucide `galaxy` icon for the galaxy; Lucide star for the build watchlist (was a hard-coded nova image), keys `ui_watch`/`ui_unwatch`; entry animations use `backwards` so no transform traps fixed children.
- Touch follow-up: cost-tile resource links stretched over the whole tile; checkboxes 24px with 44px labels (`label:has(> input)`, `label[for]`), single message selection wrapped in `.mail__selhit`; empire view toggles wrapped in labels. Verified in an iPhone 13 emulation: no tap target under 32px on overview, buildings, research, shipyard, defense, fleet, galaxy, messages (+ list), statistics, alliance, settings, empire, resources; no horizontal overflow; no-subgrid card fallback checked.
- Messages on phones: categories as one swipeable chip row (snap, end fade, active chip scrolled into view by message.js) instead of wrapping tiles; search/filters/bulk-delete in a `<details class="mail-tools">` (always open on wide screens, collapsed on phones unless a search is active; key `ui_message_tools`); bulk select shortens beside its confirm button. First message moves from ~940px to ~465px on a 390px phone.
- New theme VoidVerse (`public/styles/theme/voidverse/`): tokens from the VoidVerse game (slate 950/900/800, cyan accent, sky links, indigo info, 3px corners, Share Tech Mono via `fonts/`, faint cyan/violet nebula); 69 planet/moon slots (all names the game requests) filled from the VoidVerse planet art (512px → 300px WebP), building/UI art copied from the v0game theme, own preview.
- Contrast pass (all 8 themes, desktop + phone, incl. top bar, tab bar, popovers, palette and tooltips): filled buttons no longer get the green `.colorPositive` text on the green accent (accent-ink label); inactive states (disabled buttons, locked tech, empty categories/rows, watch star, info icons) dimmed with the muted colour instead of opacity; message type labels mix the category colour toward the text colour; the rules link uses the theme link colour. Audit script: every visible text ≥ 4.5:1 (large ≥ 3:1) against its composited background, 0 findings in every theme.
- Overview: community news of the last three days (published, pinned first, max 3) as their own row above the tutorial (`OverviewController::recentNews()`), linking to `/news/{id}`; the undated universe news text and its show/hide toggle left the info panel. Tutorial overhauled (`shared.tutorial`, block `.tut`): step x of y (`TutorialUtil::stepPosition()`), title, next open goal and overall progress in the head; text beside a goal checklist with per-goal meters and reward chips; the matching main action as primary button, skip/skip-all as quiet buttons (no more buttons nested in links). Keys `ov_news_all`, `tutorial_step_of` in all 16 locales.

## 173. Social login, installer mail step, admin in the new design language

- Social login (Discord, Facebook, GitHub, Google), all off by default: own admin section `/admin/config/social` (`ConfigSocialController`, basic category `social`: `social_<p>_enabled|client_id|client_secret`, secret never rendered, empty keeps the stored value; callback URL from `DEFAULT_URI`, never the Host header). OAuth2 code flow with state (single use, 10 min) + PKCE S256 for every provider (`src/Security/Social/*`, symfony/http-client, no new dependency); table `ACCOUNTS_SOCIAL` (migration 1795293400, removed with the account). Resolution: linked → login through `GameAuth::login()`; logged-in "connect" → link; verified e-mail of an existing account → link only after that account's password (rate limited); no account → short registration form (account name, rules, privacy) with an unusable random password; unverified e-mails (Facebook) are never used for matching. Buttons on the start/register page per enabled provider, "Connected services" in the account settings (disconnect blocked for the last login method). Rate limiters `social_flow`, link password, complete. Docs: `docs/social-login.md`. 52 tests.
- Installer: new optional step 8 "E-mail" (SMTP or PHP mail, sender, host/port/encryption/credentials, test mail with masked errors, "require e-mail activation" only with mail on) with a skip button; admin form/creation are steps 9/10. Legal step: optional imprint block (`disclamer_mail` falls back to the admin e-mail, `disclamer_address`, EU hint), board switch moved to admin-only, cookie banner kept. Game step: collapsed "more settings" with `update_check` and `discord_url`. Final step hints at Admin → Social login. Tests `InstallMailSettingsTest`, extended `InstallLegalSettingsTest`.
- Admin panel in the game's design language: loads `theme-contract.css` (V0Game tokens), top bar + side menu like the game shell (bottom sheet on phones), cards/tables/forms/switches/tabs/buttons on the game's ruleset, disabled states muted instead of faded, swipeable tab rows on phones, link hover line, 44px touch targets; 0 contrast findings on 23 admin pages. Admin login page shows the wordmark. `mod_info` without legacy markup.
- Installer credits thank all coders and maintainers of the lineage (2Moons, SteemNova, pr0game) instead of only the pr0game community.
- Secrets and licences: `App\Security\SecretAudit` + admin dashboard notices when `APP_SECRET` is a public repository default or shorter than 32 characters, or a live server runs with `APP_ENV` ≠ prod / debug on (not reported in DDEV); `.env.dev` no longer ships a secret, a DDEV post-start hook (`.ddev/config.app-secret.yaml`) writes a random one to `.env.local` (the installer already does for real installs). `licenses/`: GPLv2 dropped (nothing uses it), MIT text now for the bundled Chart.js (its jQuery plugin is gone), ISC for Lucide added, README index of which component needs which text; OFL for Share Tech Mono next to the font. `tools/` stays (CI, release, battle engine, images, load test; not part of the release ZIP).
- Galaxy on phones: one card per occupied position (pos | planet, moon / player / alliance, debris / actions in one evenly shared row), empty and pirate positions as slim single lines, own planets tinted; galaxy/system selectors are joined `‹ n ›` controls at every width, side by side with labels above on phones. Mobile pass over all game pages (390px, touch): plain data tables with ≤5 columns fit the panel (tighter cells, wrapping headers), ≥40px hit areas for icon/tooltip links, message tools, simulator controls, notes checkboxes (`label.check-hit`), no text under 12px on phones.
- Touch hit areas no longer swell bordered controls: the padding/negative-margin trick skips `.tag` (requirement chips on build cards overlapped) and the icon-link rule skips `.btn`/`.button`/`.tag`/`.row-action`/`.galaxy-action` (galaxy action buttons ran into one strip on phones).
- Audit round 4 (game logic), combat: Rust reports stored the full users/fleets row of every participant (IPs, queues, rights) in `rounds[0]` — `BattleHandler::scrubStartState()` keeps only id/name/combat techs and start coordinates, migration `1795351700` scrubs stored reports; own simulations open in the full view (no "lost contact"); PHP-engine reports keep wiped fleets; ACS honor rounded once and the ≤1 % share no longer counts as honorable fight; rebuild protection stored for the winners; FIRST_ATTACK/FIRST_MOON for every ACS member; moon follow-ups only when the moon was created; moon fights lock the parent debris; expedition fights survive an engine failure; simulator ignores tech-only slots. PHP-engine scaling of huge fights rounds stochastically (no small stack inflated to a full scaled ship), types that sat the fight out share their fleet's (or side's) loss ratio (`BattleEngineScalingTest`). Unchanged by decision: bounce rule (current shield), rebuilt defense counts as lost.
- Audit round 4, fleet: failed ACS join refuses instead of attacking solo; outlaw status only after a launch passed every check (spy shortcut, missiles); missiles at vacation targets tell the sender; allied/buddy attack question independent of the outlaw switch; dead "no moon/planet/pirate base" messages work (`empty()` on `false`); transfer return names the start planet; expedition "factor" translated; recall time for Hold/Trade; fuel preview uses the floored flight time; distinct refusals for vacation targets and taken colony slots; recycler shortcut on empty fields says so, wreck-only fields recyclable (step 3 selects `tf_active`); anti-push ignores Hold cargo and undelivered transports; no missions to slots of disabled modules; expedition depletion counts earlier fleets only, exact 25 % roll; counter-espionage counts defenses; phalanx range for galaxy_type 2; missiles not retargeted after a moon kill; a successful RIP explosion kills the whole ACS group; galaxy: no "colonizable" at the planet limit, missile button respects noob/vacation; step 2 subtitle and default target.
- Audit round 4, economy: unlimited research queue (`max_elements_tech = 0`) no longer locks research; Resources totals with an empty stock; `energy_basic_income` applied in production (planets) instead of only shown; energy hint/next level/times correct for queued and demolishing buildings, waiting queue entries re-timed when the queue advances; affordability against exact stock; "buildable in"/"full in" handle zero production; reopening a closed universe postpones building/research queues by the closed time; refused queue commands explain why, server blocks university/repair dock like the UI; terraformer/orbital energy against surplus; `energy_used` rounded; info dialog "Differenz" column; build "done at" clock in the player's time zone (`clock(timestamp, tz)`); stray "·" removed. `max_fleet_per_build = 0` unchanged by decision.
- Audit round 4, lifecycle/cron: daily recalculation keeps due jobs due (cleaner no longer skips a week after downtime); new uni setting `vmode_allow_incoming` (default on) — off refuses vacation while foreign hostile fleets are inbound; account deletion recalls foreign Hold fleets; inactivity mail honours `sendmail_inactive` (migration `1795353200` takes it over from `mail_active`), re-arms after a login, skips vacation/deleting players, console link from `DEFAULT_URI`; failed stat runs surface, `stat_settings` ≥ 1; cleaner auto-deletion as full uni settings (`del_user_automatic_on`, `del_user_vacation_on/_vacation`, `del_user_banned_on/_banned`; defaults = previous rule); diplomacy/stat rows of dissolved alliances removed; pirate spawner robust (no ValueError wipe, missiles aren't ships, skips ship-less players); records/stat config per universe; points per member over active members; "-" before the first stats run; achievement 26 reachable; home planets respect allowed positions; reshuffle checks fleets, removes wreckfields, saves config; production % kept across vacation and the inactivity reset (`planets.porcent_saved`, migration `1795400137`); default planet/moon names in the owner's language; only simulation reports purged after 3 days.
- Audit round 4, trade/social: no second alliance while a member; wars can't be deleted/rejected unilaterally; one (read) copy for senders of circulars/diplomacy/self PMs; buddy badge counts received requests; expired market offers hidden; partner-only offers accept diplomacy level 6; kick/disband notify members, applicants and partners and clear stale rank data; unread counters after mark-read; blocked recipients reported to the sender; no duplicate peace offers; market cargo ship sizing reads the cargo room from the fleet rules (`getFleetRoom`) instead of its own lookup.
- Audit round 4, UI: consistent numbers (top bar rate, cost tiles below 100k, fleet table cargo/ship counts via `pretty_number`); simulator grid no longer clips the defender column; phone action bars flush on the tab bar; dialog close button and info table on phones; notes/info pages render in the full shell when opened directly, notes dialog with "new note"; techtree badges/names, connector lines hidden on phones; empire: column fade, "Punkte (Planet)" label, points chart on a time-proportional x axis (`hoursBack`); logger minimum level `warning` (`APP_LOG_LEVEL`), no debug flood in game.log; top bar from 1e9 on in billions with three decimals (same unit as the phone figures, `millions_number`/ticker); phone page action button (`.page-fab`) rises above the footer (`--fab-lift`); records: staff viewers learn why they set no records, opted-out holders show as "hidden", an own level above the record is marked (not yet counted / staff); over-full fields (current > max) flagged on empire and the overview meter.

## 174. Six new languages, neutral footer, release 1.26.8

- Languages: six new locales `it` (Italian), `cs` (Czech), `hu` (Hungarian), `ro` (Romanian), `br` (Brazilian Portuguese, tag `pt-BR`) and `tw` (Traditional Chinese, tag `zh-Hant-TW`), all complete against en (10 domains + 11 text templates each), casual player address; two-letter codes because the lang columns are varchar(2). `LanguageCatalog::packCodeForTag()` maps browser tags (pt-BR → br, zh-TW/HK/Hant → tw, fil → tl) for the game and the Symfony `LocaleSubscriber`; `<html lang>` uses the pack's BCP 47 tag via the Twig function `html_lang()` (fun packs `of`/`pi` tagged `de`); `LanguageTagTest`; uni schema golden hash updated (language options).
- Footer: neutral credit line `shell_footer_credits` ("built on the work of many open-source projects", all 22 locales) instead of the hardcoded English "based on pr0game and 2Moons"; the right side shows only the version, linked to the configured source URL; tutorial kicker `tutorial_label` translated instead of a hardcoded "Tutorial". README: 20 languages, grouped contents (everyone / technical), developer credit Abraxis, voidverse.net link.
- Public site in the V0Game world: the start page frame (`login/layout.base`, `login/layout.html` for rules, `account/layout.site` for the board) loads `theme-contract.css` + `theme/v0game/theme.css`, `login.css` rewritten on the theme tokens in layer `pages` (no orange title bars, no photo background, panel heads like the game, page head + panel for content pages, news entries without nested cards, board tabs/chips as segmented controls). Start page reorganised: pitch + register/screenshots + universe status | login, then the comic (2/3) | latest news + explore list (`home_explore`, all 22 locales); both rows share one column grid; phones hide the step list. Language picker = header button + native `<dialog>` with `?lang=` links (`lang-grid`).
- Icon set: `public/favicon.svg` (V with a planetary ring) rendered to `favicon.ico` (16/32/48), `apple-touch-icon.png`, `icon-192/512.png`, maskable icon and `site.webmanifest`, included by every layout via `shared/icons.html.twig`; the stray root `favicon.ico` (and its `public/` symlink) removed, release allowlist updated.
- `composer update`: php-cs-fixer 3.95.32, PHPStan 2.3.7, PHPUnit 13.4.8, polyfill-php85 1.44, agent-detector 2.1.
- `install/VERSION` 1.26.8 (tag `v1.26.8`): UI 2026 shell (strict layout, tokens-only themes, thumb-first phones, VoidVerse as theme #8), build pages list every element, social login, installer SMTP step, admin in the new design, new planet and moon artwork, audit round 4 (combat, fleet, economy, lifecycle, trade, UI), six new languages (it, cs, hu, ro, pt-BR, zh-TW), public site in the V0Game look with a language modal, new icon set, English screenshots. Upgrading runs migrations 1795293400, 1795351700, 1795353200 and 1795400137 (applied automatically by the upgrade check).

## 175. Readable buttons everywhere, one skin for game and account, Portuguese redone

- Primary buttons rendered as links keep their accent text in every context (`@layer utilities` guard in base.css; the zero-specificity button rules lost against "links in X are muted" rules, e.g. the notes popup's "new note" button was grey on green); `.panel__head a` skips buttons. Danger buttons: lighter red text (≥ 4.5:1 in every theme), dark text when filled; duplicate rule in community.css aligned; account primary buttons use the accent ink. Audit tool: `tools-ui/buttons.mjs` (every visible control's contrast on 33 pages + 7 modal pages × 8 themes, now 0 findings).
- Account area (lobby, account settings, logged-in board, account popups) loads the theme contract, so the player's skin applies there as in the game; saving the skin in the game settings also writes it to the account (`SettingsUpdater`), migration `1795450000` takes the current game skin over for existing accounts. base.css defaults are the V0Game palette instead of pr0 orange (pages without a theme), installer and the PHP error page use the contract + V0Game theme, installer.css on theme tokens (it still read the removed `--nova-*` variables).
- European Portuguese (`pt`) redone from English: casual *tu*, Portugal vocabulary, 118 missing FAQ answers written, rules text translated (was English), Cyrillic/English leftovers and broken machine phrases removed, time zone names translated.
- `install/VERSION` 1.26.9 (tag `v1.26.9`): every button readable in every theme (also in popups), the player's skin also on the account pages and the logged-in board, V0Game palette as the default everywhere (installer, error page), European Portuguese redone. Upgrading runs migration 1795450000 (applied automatically by the upgrade check).

## 176. Modern quality of life: notifications, live events, raid tools, alliance tools, api, review queue

- Web push (`minishlink/web-push`, VAPID keys generated lazily into global config) + per-user Discord webhook: `NotificationDispatcher`, `NotificationDetector`, `NotificationCronjob` (every minute; hostile fleets, espionage, returned fleets, finished queues, farms that woke up), `PushController`, `public/sw.js`, `public/scripts/app/push.js`; prefs `users_settings.notify_*`, tables `push_subscriptions`, `notification_events`, `notification_watch` (migrations 1795500100, 1795500901).
- Settings: notifications section, `number_format` (abbreviated numbers via `ViewHelpers::displayNumber()` / `display_number`), vacation minimum/earliest end shown, link to the api page.
- Overview: `/game/events` JSON (`LiveEventsPayload`), `live-events.js` polls while visible, re-renders from a `<template>`, hostile count in the title.
- Fleet: templates (`fleet_templates`, `FleetTemplateStore`, migration 1795500300), `ships=id:n,…` step 1 preset (`ShipPreset`, `planettype` alias), fleet save suggestions (ajax `fleet-save`), "arrive at" speed picker in step 2 (`speedPlan()`).
- Spy reports: raid planner block (`SpyReportAnalysis`, `SpyReportTools`: loot, cargos, fuel, profit, values, age, send/re-spy links), `targetInactive` stored in new reports; farm list `/game/farms` with i/I and "woke up" markers.
- Galaxy: client filters (inactive, in range, debris, moon, vacation), player/alliance marks in localStorage, recyclers needed and wreck marker (`GalaxyRowFlags`).
- Empire: per-planet queues with countdowns, resource collection to one planet with preview and post/redirect/get (`ResourceCollector`, `ResourceCollectionDispatcher` through the regular `FleetDispatch`).
- Shipyard/defense: "build up to X" (`HangarOrderPlanner`, `orderMode=upto`), max button capped by `max_fleet_per_build`, cost/time/finish preview (`hangarOrder.js`).
- Combat reports: loss values per player/side, profit, copy public link, "simulate again" with attacker `boost` % (`CombatReportValue`).
- Alliance: target list with claims, ACS overview, activity buckets, wiki pages, diplomacy expiry (rights `TARGETS`/`ACTIVITY`/`WIKI`, tables `alliance_targets`, `alliance_pages`, `diplo.expires_at`, daily expiry step; migration 1795501000).
- Read-only api: personal tokens (`api_tokens`, sha256, max 5, scopes), `/game/api`, `/api/v1/{me,empire,reports/spy,reports/combat}` with bearer auth and a 60/min limiter (migration 1795500700).
- Universe rules: `UniverseRules` service, settings section on `/game/rules` and public `/rules/universe`.
- Admin: abuse review queue (`SuspicionScore`, `AbuseReviewService`, hourly `AbuseReviewCronjob`, `/admin/abuse/reviews`, universe setting `abuse_review_threshold`; migration 1795501700).
- Accessibility: drawer focus trap/inert/escape, more `g+…` shortcuts (g+o fixed), install-app button, reduced motion for shell parts.
- Tests: test players spread over 400 systems per galaxy (the old 100 ran out), new unit/integration tests per feature.

## 177. In-game chat module

- `MODULE_CHAT` (58, off by default): table `chat_messages` (separate from player messages) and `chat_reports`, migration 1795502000; `App\Community\Chat` (send/poll/report/block), `ChatRetention` (7 days, 2000 per channel, stale reports dismissed after 30 days) in the daily cronjob.
- Game `ChatController`: poll (JSON, no session write, `chat_poll` 30/min, 401 JSON when logged out), send (limiters `chat_gap` 1/3 s, `chat_burst` 5/30 s), report (`chat_report` 5/10 min, max 20 open), block, moderator delete/mute (right + admin log).
- Channels global + alliance; `chat_global_enabled`; global filters in `ChatFilter` (word masking, generic link rule with disguise normalisation and `chat_allowed_domains`, repeat/caps/char-run checks, min account age 24 h default, min points); invisible/filler characters stripped.
- UI: fixed desktop button + drawer entry on phones, `chat.js` short polling (4 s open, 30 s closed, paused when hidden), kebab popover per message; admin `/admin/chat` history (filters, deleted/original text, restore) and open-reports queue (oldest first, keyset paging).

## 178. Installer in five steps

- Web installer: requirements+licence, database (+ table build), game & admin, options (chat, global channel, link blocking, bots, mail, legal), done; inline field errors, optional fields folded, works without JS; upgrade mode unchanged.
- `InstallService::applyChatSettings()`, chat module row created off; `app:install --chat/--no-chat --chat-global/--no-chat-global --chat-block-links/--no-chat-block-links`; `install.sh` and `docs/INSTALL.md` updated.

## 179. Performance pass 3 (package A)

- Farm list: sargable join `p.id = CAST(SUBSTRING(message_group, 3) AS UNSIGNED)` (3.9 s → 15 ms on 5000 reports), honor boost/unit costs/fuel memoised per request (`FleetMath::fleetConsumptionFor(..., ?float $honorBoost)`), filters before row building, 200 rows per page.
- Notification cron: `finishedShipyards()` two batched queries instead of two per planet; a malformed `b_hangar_id` is logged instead of aborting the run.
- `/game/events` moved to `LiveEventsController` with `$disableEcoSystem` (no planet lock/write per poll).
- Scroll: `--fab-lift` set on the floating buttons only and only when it changes, back-to-top progress via `animation-timeline: scroll()` (JS fallback in 1 % steps, `scaleX` instead of width), `body:has()` rules replaced by sibling selectors / a class (phone 4× scroll restyle: buildings 1.6 → 0.08 s, empire 2.4 → 0.05 s, messages 2.6 → 0.6 s).
- Overview page scripts versioned (`?v=`), so they get the immutable cache headers.

## 180. Performance pass 3 (package B)

- `GameConfig` reads from the config cache file (no `config`/`config_universe` queries per request while it is valid).
- Indexes `log_fleets(universe,start_time)`, `planets(b_building)`, `users(b_tech)`, `chat_messages(universe,channel,deleted,id)`; `notification_watch.user_id` dropped; watch rows written only on change (migration 1795502100).
- Notifications: opted-in sets loaded once per run, batched dispatch (`IN` queries, one web-push flush, concurrent Discord posts).
- Chat poll early "nothing new" check (4 → 1–2 queries), `chat_poll` 60/min; admin chat reports view 71 → 4 queries; API token stamp skipped while fresh.
- `#[LeanBoot]` for `/game/events` and `/game/chat/poll`: no planet lock/list, element catalog and language loaded lazily; all gates unchanged.
- `#[PublicCache]` + `PublicCacheListener`: public non-game pages cacheable for anonymous visitors without session cookie, reports with ETag/304 (`-gzip`/`-br` ETag suffixes normalised).
- Front end: Chart.js imported on demand (empire/alliance), chart animation off, empire row groups via one `data-folded` attribute (phone 4× TBT empire 591 → 111 ms, alliance 226 → 2 ms); `module_preload()` emits `modulepreload` for the static import graph; content.css via `<link>` (wrapped in `@layer legacy.content`); 64/128 px planet thumbnails with `srcset` (902 variants); 27 animated tech GIFs as smaller animated WebP; `.htaccess` drops the gzip-mangled ETag of css/js/svg/json and marks versioned images/fonts immutable; `tools/build/precompress.sh`, `tools/images/animated-gif-to-webp.sh`; compression docs in `docs/nginx.md`.
- Test fix: `VoteSitesTest` second token no longer collides when the first starts with `b`.
- `install/VERSION` 1.26.10 (tag `v1.26.10`): player tools (push/Discord notifications, live event list, fleet templates and fleet save finder, raid planner and farm list, galaxy filters and marks, empire hub with resource collection, shipyard "build up to", combat report values, alliance targets/ACS board/activity/wiki, read-only player API, universe rules page), in-game chat module with filters, reports and admin review, abuse review queue, installer in five steps, accessibility pass, back-to-top button, performance pass 3 (server and browser). Upgrading runs migrations 1795500100, 1795500300, 1795500700, 1795500901, 1795501000, 1795501700, 1795502000 and 1795502100 (applied automatically by the upgrade check).

## 181. Head-to-head with pr0game, slow spots fixed

- Benchmark against pr0game 2b65627 on the same machine and the same seeded late-game universe (`docs/PERFORMANCE.md`, README performance section and highlights rewritten around it).
- Messages: unread counts adjusted in PHP after the listed messages are marked read (no second count query), covering index `users_to_messages.user_cover` (migration 1795502200); list 70 → 43 ms.
- Fleet table: tooltip/confirm attributes escaped with `html` instead of `html_attr` (-11 % HTML).
- Phone LCP: `content-in` animation slides only, no opacity fade (overview LCP 1024 → 360 ms at 4× CPU).
- Daily cronjob: indexes `buddy.owner` and `alliance_request.userID`, no second SELECT per inactive player (migration 1795502300); 1388 → 840 ms.

## 182. QoL review 2: fleet loop, planet status, queues page, message coords, alliance members

- Fixes: fleet step 2 no longer overflows on phones/under the right rail, sticky send bar and protection toast keep inputs and buttons free; destination step summary fills the return time (`flotten.js fleetTime()`); galaxy action icons no longer clip at 1024–1440 px; `/game/api` code blocks wrap; build-card requirement tags, market headers and empire toggles wrap instead of clipping.
- Fleet: "send again" per own flying fleet (step 1 prefilled via `ships=`, target, mission; not for ACS join, colonisation, missiles; `FleetTableController::repeatQuery()`); mission colour edge on fleet table and overview event rows (`shared.missionColors.html.twig`); flight time next to own planets/shortcuts in step 1, follows the speed (`showTargetTimes()`).
- Galaxy on phones: filters folded into `<details>` with an active-filter count, rows with name + alliance tag on one line.
- Planet list (side panel + top-bar popover): status icons for idle building queue, idle shipyard, full storage (stock projected from `last_update`) and the next build finish, from the existing planet-list query (`AbstractGameController::planetStatus()`).
- New page `/game/queues` (empire tabs): account research plus construction and shipyard queue of every planet and moon with countdowns; queue logic moved from `EmpireController` into `App\Game\Core\Player\QueueOverview` (`queueOverview()` accessor).
- Messages: open on the saved category or the first one with unread mail; unread chips first on phones with scroll-edge fades; coordinates get a "send fleet here" icon at render time, plain ones also a galaxy link (`CoordinateLinker`, Twig filter `link_coords`, stored text untouched).
- Alliance: members panel on the home page (online count, 8 most recently active, same ONLINESTATE + MEMBERLIST gate as the member list, one query); wiki labelled "Wiki" instead of "Notizen"; tools nav marks the current page.
- Tutorial: open by default for the first steps, two-line title on phones, goals link to their page, step 3 names the shipyard's defense tab.
- Remembered per browser: build/research/shipyard filter (per path), market ratios; unaffordable market offers dimmed. Locked ships/defense folded into "Locked (n)" on phones (`lockedFold.js`).
- Ban list and battle hall link player names to the card; the battle hall's own-fights filter is labelled "My battles".
- Lobby: closed universes show a disabled "closed" button (admins can still enter); the account language follows the player's game language (settings save, universe entry).
- New ingame/tutorial keys in all 22 locales.

## 183. Chat slow mode

- Global channel: universe setting `chat_global_slowmode` (seconds between two messages of a player, 0 = off, max 3600; Admin → universe settings, chat).
- Alliance channel: `alliance.chat_slowmode` (off, 10 s, 30 s, 1, 2, 5 min), set by the alliance leader only in the alliance settings (checked server-side, values from `Chat::ALLIANCE_SLOWMODES`); migration 1795502400 also adds index `chat_messages.channel_user` for the last-message lookup.
- `Chat::send()` refuses with `slow` + seconds to wait; moderators and the leader in his own alliance channel are exempt. Poll returns `slow` per channel; the widget shows a hint and counts the send button down.

## 184. Achievements: every one earnable, 41 new ones, SVG artwork

- The 17 achievements without a trigger now have one (`NOT_IMPLEMENTED_ACHIEVEMENTS` is empty): 1 (5 night attacks without a win, `users.night_attacks(_date)`), 2 (90 days of fleets in motion, `users.fleets_moving_since`), 13 (fleet leaves < 5 s before a hostile landing), 15 (10 fights at maximum moon chance without a first moon), 18 (5 M deuterium fuel from one planet in 24 h, table `fuel_log`), 29/30 (5-player ACS), 41 (market offer open 7 days), 42 (4 battle hall losses), 44 (7 days below 100 % energy, `planets.low_energy_since`), 46 (missiles hit the bash limit), 50 (satellites lost with graviton energy), 53 (top records in 5 researches, after the records build), 54 (attack at 01‑01 00:00:00); 8, 11 and 55 are granted by admins (new account editor tab "Achievements": grant/revoke with log entry, `Achievement::removeAchievement()`).
- 41 new achievements 56–96 (migration 1795502500): research (hyperspace drive 15, astrophysics 15, all researches ≥ 1 / ≥ 10), economy (1 bn on a planet, 1000 satellites, full 250-field planet, storages 15, 9 metal mines 30, 30-day build order, 50 market sales), combat (David vs Goliath, flawless victory, mutual destruction, ghost town, 1 % moon, 100 defenses by one missile strike, 100 probes shot down, 1 bn raided / recycled, 1000 ships repaired, 1000 honour points, outlaw, 25 ACS wins), expeditions (all outcomes, 1000 expeditions, large aliens beaten, 50 large finds), timing (24 h flight, leap day, Friday the 13th, two attacks in the same second, fleets to every galaxy), social (tutorial, 3 pacts, 5 wiki pages, 10 buddies) and long-term (rank 1 for 7 days, 100 Death Stars, all colony slots with 200 fields, 50 achievements).
- Hooks: `BattleAchievement` (battle, battle hall, moon roll, missiles, expedition fights), `PlanetRessAchievement` (research/building/shipyard completion, saved resource updates — in-memory checks with a per-request held cache), new `FleetAchievement` (dispatch, fleet events, market), `MiscAchievement::checkStatAchievements` (advanced stats thresholds), records/statistics/daily cronjobs, tutorial, diplomacy, wiki, buddies; Completionist inside `Achievement::setAchievement()`. New counters `ACS_WINS`, `ALLIANCE_PAGES_CREATED`, `users.market_sales` (migrations 1795502520/30/40).
- All 96 achievement images redrawn as SVG (white 12 px margin, monochrome ink), templates load `.svg`; the WebP files are gone.
- Names/texts in all 22 locales, admin keys in all admin locales.

## 185. Audit round 5: achievements guarded, web push fixed, SSRF closed

- Achievement checks run through `AchievementGuard::run()` (all ~45 call sites): a failing check is logged to `var/log/game.log` and swallowed so it can never roll back a battle, fleet event, purchase or cron job; deadlocks and lock wait timeouts (`Database::isRetryable`) are rethrown.
- Achievement fixes: Wreck diver counts only ship repairs (`REPAIRED_2xx`), index `users_to_topkb (uid, role)` for the battle hall check (migration 1795502550), achievement 15 evaluated after a failed moon roll, held-cache only caches DB-confirmed holds, level checks use ≥ (existing players qualify on their next completion), Full house planets only, Einstein needs records ≥ 10, Grand tour needs ≥ 3 galaxies.
- Anti-alt: Market maker ignores purchases between linked accounts, Pen pal ignores linked buddies (`AccountLinks::linked()`), Diplomat counts alliances with ≥ 5 members, Chronicler counts pages with ≥ 200 characters.
- Web push actually sends: `nyholm/psr7` added, explicit `Psr18Client` (timeout 5 s, no redirects); the old third constructor argument was a TypeError in web-push v11. Send failures are logged.
- SSRF: push endpoints must be on a known push service (`PushEndpoint::isAllowed`, no IP literals/ports/userinfo), stored rows are re-validated before sending; Discord webhooks match a strict pattern, 5 s timeout.
- `CoordinateLinker` leaves text with a stray `<` alone; `QueueOverview` guards malformed queue data.
- Follow-ups (after the 1.26.12 tag): push subscription takeover needs the same browser keys (`PushEndpoint::mayStore`), alliance targets ignore destroyed planets, API combat reports clamp `since` to 30 days with an extra `api_combat` limiter (10/min), chat sends serialize per player (`GET_LOCK`), ACS overview shows only groups led by alliance members or with the viewer invited, public combat reports cached 1 h; achievement 2 clock restarts on a fresh dispatch, `market_sales` counted after the purchase commits (`MarketPurchase::afterCommit`), held achievements cached per instance, Overachiever granted on completion, low-energy clock starts when the deficit is first seen.

## 186. Release 1.26.12

- Tag `v1.26.11` has no release: its ZIP build failed because `tools/release/build.sh` still pruned the local-only `docs/` (fixed in 00b86fa1).
- `install/VERSION` 1.26.12 (tag `v1.26.12`): QoL review 2 (fleet step 2 on phones, send again, mission colours, flight times, galaxy phone fold, planet status markers, all-planet queues page, message coordinate links, alliance members panel, tutorial links; step 182), chat slow mode (183), every achievement earnable plus 41 new ones with SVG artwork and an admin grant tab (184), audit round 5 fixes (185). Migrations 1795502400–1795502550 run on the first request after the update.

## 187. Bots: difficulty presets, fair rules, dangerous behaviours

- Config: `BotSettings` (all bot knobs, defaults = previous behaviour) + `BotPresets` (relaxed / normal / hard / brutal / custom, `bots_preset`); five admin setting cards (bots, raids, defense, growth, features); every former hard-coded threshold (min loot, escort shares, simulation runs, spy cooldowns, recall/streak thresholds, threat window, fleetsave, weights, growth, activity) is a knob; per-bot difficulty override (preset + aggression) in the admin bot list (`_cfg` in the personality JSON).
- Fairness: shared envelope across all bots (`Raid/BotEnvelope`: raids and probes per human per 24 h, shared cooldown, no second bot attack in flight), threats read like the overview event list, pact/buddy/alliance recheck at send and before impact, scout radius capped (`bots_scout_range_max`), re-spy jitter, personal timezone per bot (`BotSchedule`), fleetsave sized to the real offline time, single-planet fleetsave via slow expedition, defense only if it finishes before impact plus rebuild after hits, unidentified attack fleets treated as hostile; `llm_advice` wired.
- Behaviours (switches, on in hard/brutal): learning loss multiplier (`RaidLearning`), grudges + revenge (`bot_relations.grudge`, migration 1795502610), raids from the nearest planet/moon, recycling (`BotRecycling`), bot ACS (`BotAcs`, counted as one attack by the envelope), moons as fleetsave/launch points, missiles on blocking defense (`BotMissiles`), late-game warmonger, war-fleet goal scaling with aggression and points.
- Admin labels in all locales; tests `BotSettingsTest`, `BotScheduleTest`, `BotFairnessTest`, `BotBehavioursTest`.
