# Performance

Measurements and changes of the performance pass (2026-11-15), done after the game logic bug sweep
([GAMEBUGS.md](GAMEBUGS.md)). Game results are unchanged: every change was checked with a full crawl diff (HTML identical
except volatile values) and, for the statistics and resource routines, by running old and new code side by side on a
cloned database and comparing the written rows.

## Method

**Pages.** A PHP built-in server runs inside the DDEV web container in production mode (`APP_ENV=prod`,
`APP_DEBUG=0`, OPcache on, no profiler, prod cache warmed), so the numbers show the application and not the Symfony
debug toolbar. A script logs in as the admin account, enters universe 1, opens the admin area, then requests each page
twice to warm up and 15 times measured (curl `time_total`; median and p90). Queries and database time per request come
from MySQL's `performance_schema.events_statements_summary_by_digest`, truncated before each page. Each scenario ran
twice; the table shows the mean of the two medians. Same database content before and after (dev data: 4 players,
10 planets, about 240 messages). Variation between passes: about ±3 %.

**Game routines.** A CLI script boots the Symfony kernel in prod mode against a throwaway clone of the database seeded
with 1000 players, 5000 planets, 40 alliances, 800 fleets (600 due), 100k messages and tutorial rows, and times each
routine with `hrtime()` and the game's query counter (median of 3 runs before, 5 runs after).

## Pages: before → after

| Page | Median before | Median after | Change | Queries before → after | DB time before → after | With deploy tuning |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `/` | 14.9 ms | 13.3 ms | -10 % | 8 → 6 | 1.76 → 1.34 ms | 11.0 ms |
| `/?page=news` | 14.9 ms | 13.4 ms | -10 % | 9 → 7 | 1.72 → 1.38 ms | 10.7 ms |
| `/?page=hoF` | 14.3 ms | 12.9 ms | -9 % | 7 → 5 | 1.39 → 1.04 ms | 10.0 ms |
| `/account` | 18.8 ms | 16.4 ms | -13 % | 14 → 10 | 2.81 → 2.11 ms | 13.6 ms |
| `/game?page=overview` | 28.3 ms | 23.5 ms | -17 % | 48 → 36 | 8.38 → 6.08 ms | 20.0 ms |
| `/game?page=buildings` | 26.6 ms | 22.9 ms | -14 % | 40 → 28 | 7.27 → 4.95 ms | 19.5 ms |
| `/game?page=research` | 26.9 ms | 22.1 ms | -18 % | 40 → 28 | 7.27 → 4.98 ms | 19.0 ms |
| `/game?page=shipyard` | 25.9 ms | 21.6 ms | -17 % | 39 → 27 | 7.00 → 4.87 ms | 18.2 ms |
| `/game?page=shipyard&mode=defense` | 26.0 ms | 21.8 ms | -16 % | 39 → 27 | 7.10 → 4.83 ms | 18.1 ms |
| `/game?page=resources` | 25.8 ms | 21.5 ms | -17 % | 39 → 27 | 7.04 → 4.76 ms | 17.9 ms |
| `/game?page=empire` | 50.0 ms | 26.1 ms | -48 % | 116 → 37 | 21.10 → 6.22 ms | 23.6 ms |
| `/game?page=galaxy` | 35.5 ms | 24.5 ms | -31 % | 71 → 33 | 12.84 → 6.32 ms | 21.1 ms |
| `/game?page=fleetTable` | 27.0 ms | 22.4 ms | -17 % | 43 → 31 | 7.65 → 5.30 ms | 19.5 ms |
| `/game?page=messages` | 29.6 ms | 25.1 ms | -15 % | 49 → 37 | 9.70 → 7.35 ms | 22.9 ms |
| `/game?page=messages&category=0` | 44.2 ms | 38.9 ms | -12 % | 50 → 38 | 11.18 → 8.68 ms | 36.2 ms |
| `/game?page=statistics` | 28.5 ms | 22.6 ms | -21 % | 47 → 31 | 8.82 → 5.50 ms | 20.0 ms |
| `/game?page=alliance` | 26.2 ms | 21.8 ms | -17 % | 41 → 29 | 7.46 → 5.03 ms | 18.6 ms |
| `/game?page=techtree` | 27.1 ms | 22.5 ms | -17 % | 39 → 27 | 7.11 → 4.67 ms | 19.3 ms |
| `/game?page=information&id=1` | 20.8 ms | 18.4 ms | -11 % | 23 → 18 | 4.29 → 3.38 ms | 14.9 ms |
| `/game?page=marketPlace` | 29.0 ms | 24.5 ms | -16 % | 48 → 36 | 8.96 → 6.70 ms | 21.7 ms |
| `/game?page=settings` | 26.9 ms | 22.5 ms | -17 % | 41 → 29 | 7.35 → 5.02 ms | 19.0 ms |
| `/game?page=battleSimulator` | 26.1 ms | 21.4 ms | -18 % | 39 → 27 | 7.11 → 4.80 ms | 18.2 ms |
| `/game?page=playerCard&id=2` | 22.9 ms | 20.8 ms | -9 % | 31 → 26 | 5.81 → 4.69 ms | 17.5 ms |
| `/game?page=records` | 27.6 ms | 23.3 ms | -15 % | 44 → 32 | 8.07 → 5.62 ms | 20.1 ms |
| `/admin?page=overview` | 20.9 ms | 17.8 ms | -15 % | 22 → 13 | 4.31 → 2.84 ms | 14.8 ms |
| `/admin?page=flyingFleet` | 19.9 ms | 16.9 ms | -15 % | 19 → 10 | 4.07 → 2.45 ms | 14.1 ms |

"With deploy tuning" adds an authoritative Composer class map and OPcache preloading on top of the code changes (see below).

Summary: every page got faster; a typical game page went from about 26 ms / 40 queries to about 22 ms / 28 queries
(-15 to -20 %), the empire overview from 50 ms / 116 queries to 26 ms / 37 queries (-48 %), the galaxy from 36 ms / 71
queries to 25 ms / 33 queries (-33 %), public pages by about 10 %.

## Game routines: before → after

| Routine | Before | After | Change |
| --- | ---: | ---: | ---: |
| Statistics build (`Statbuilder::generateStats`) | 600.6 ms, 1090 queries | 149.5 ms, 52 queries | -75 % |
| Records (`Statbuilder::buildRecords`) | 975.9 ms, 3281 queries | 35.2 ms, 10 queries | -96 % |
| Statistics cronjob, total | 1559.5 ms, 4375 queries | 213.2 ms, 66 queries | -86 % |
| Fleet events, 600 due transport/stay fleets | 2686.9 ms, 9012 queries | 2048.0 ms, 8651 queries | -24 % |
| Resource update, 5000 planets, no save | 2581.0 ms, 10000 queries | 1129.8 ms, 5000 queries | -56 % |
| Resource update, 1000 planets, with save | 951.1 ms, 3000 queries | 644.1 ms, 2000 queries | -32 % |
| Resource update, 1000 planets, no eco hash | 464.1 ms, 2000 queries | 216.9 ms, 1000 queries | -53 % |
| Daily cronjob (sum of steps) | about 145 ms | about 138 ms | unchanged (runs once a day) |
| Cleaner cronjob | 12.4 ms | 12.4 ms (163 ms with 100k seeded messages) | unchanged, data dependent |

## What changed

Per request (every page):
- The global and universe configuration is read once per request instead of twice (`App\Runtime\GameConfig` reuses the
  rows `ConfigRegistry` loaded): -2 queries on every page.
- Session bookkeeping (`Session::save()`): the session row, `onlinetime`/`user_lastip` and the multi-IP check are written
  only when something changed or at most every 30 s (online displays may lag up to 30 s): -4 to -5 queries.
- The ban-expiry `UPDATE` runs only when a ban actually expired.
- The pending-migration scan is cached (`var/game/cache.migrations.php`, rebuilt when `install/migrations` changes).
- Tutorial bookkeeping syncs missing rows at most once a minute per player; the tutorial box is skipped when the module is
  off; alliance application/diplomacy counters are skipped for players without an alliance; the account id is looked
  up once per controller.
- New indexes (migration 1794787218): `accounts_to_users` (user/account), the tutorial tables, and
  `users_to_messages (user_id, message_unread, deleted_at)` for the unread-message count in the per-request user query
  (100 rows examined → 1 on the seeded data; full scans of 13k–22k rows → index lookups for the tutorial queries).

Pages:
- Empire: the chart points are one query instead of one per data point (65 → 1), fleets one query instead of one per
  planet.
- Galaxy: colonisation limits are checked once per distance tier (15 → 4 counts), the noob-protection check reads the
  outlaw statistic only when that rule is enabled, planet counts only when they can matter.

Game routines:
- Statistics: per-user statistic rows written in batches of 200, record maxima one query per type, record rows batched,
  level costs memoised (same float summation order), redundant `DISTINCT` removed.
- Resource update: repair job and wreck field check share one `SELECT … FOR UPDATE`; production and storage formulas
  are compiled once per process into closures instead of `eval()` on every call.
- Fleet events: language packs loaded once per language code and reused.

## Battle engines

The game has two engines: the Rust engine and the PHP engine as fallback (`BattleHandler`). Until GB-80 (a regression from the port, not a pr0game bug) the Rust
library was never found (wrong path), so every battle ran on the PHP engine. The Julia engine (a separate HTTP service)
and the in-process FFI call of the Rust library were removed: the Rust engine always runs through `bin/battlerust`,
a 16 KB launcher (`tools/battlerust-cli/`, built with `tools/battlerust-cli/build.sh` in a throwaway Ubuntu 20.04 container)
that loads `bin/libbattlerust.so` and exchanges the battle JSON over stdin/stdout. No PHP extension is needed; Linux
x86_64 only (elsewhere the PHP engine is used).

**The library now has source** (phase 5 of the legacy renewal): `tools/battlerust/` (Rust, edition 2021, only serde/serde_json) is a
port of the PHP engine with its own bit-exact `mt_rand`. With the same seed it plays exactly the fight of the PHP engine
(`BattleEngineTest` compares 300 generated battles plus the golden fleets field by field; `BATTLE_PARITY_RUNS=5000` soaks longer).
`sh tools/battlerust/build.sh` builds it in a pinned `rust:1.91.1-bullseye` container (glibc 2.31, so the library loads on glibc >= 2.30:
Ubuntu 20.04+, Debian 11+, RHEL/Alma 9+; the newest symbol it needs is `GLIBC_2.30`), `build.sh test` runs `cargo test`, `build.sh check`
rebuilds and compares the sha256 with the committed `bin/libbattlerust.so` (the build is reproducible: same bytes on another host path and
with an empty cargo cache). Input/output formats and the differences to the first library: [battle-engine-contract.md](battle-engine-contract.md).

Same fights through the engines (simulation mode, techs 10/10/10, DDEV web container PHP 8.4 with the `ds` extension; time of
`calculateAttack()` incl. JSON, process start and result parsing; median of 30/3/3/2 runs):

| Fight | Units | PHP engine | Rust, first library | Rust, `tools/battlerust` |
| --- | ---: | ---: | ---: | ---: |
| S: 50 light fighters + 20 cruisers vs 100 rocket launchers + 50 light lasers | 220 | 0.6 ms | 2.3 ms | 1.1 ms |
| M: 5k cruisers + 2k battleships vs 20k RL + 10k LL + 2k gauss | 39 000 | 178 ms | 6.1 ms | 4.8 ms |
| L: 100k cruisers + 30k battleships vs 300k RL + 150k LL + 20k gauss | 600 000 | 5 246 ms¹ | 50 ms | 60 ms |
| XL: 900k cruisers + 100k battleships vs 1.5M RL + 800k LL + 100k gauss | 3 400 000 | 11 634 ms¹ | 429 ms | 555 ms |

¹ The PHP engine runs fights above its ship budget (at most 500 000 units) on proportionally scaled counts (GB-21), so L and XL are an
approximation there; without scaling it would need several GB of memory.

The new library is faster than the first one for small and medium fights and about 1.2x slower for fights of a few hundred thousand to a few
million ships (it fights every ship individually like the PHP engine, so every shot is a random memory access). What closed most of the gap:
16 bytes per ship (armor and shield; the unit type comes from the ship's position in its fleet, "destroyed" is the sign bit of the shield,
compaction keeps the order), loops over the unit-type ranges instead of per-ship type lookups, precomputed ranges for the random draws (no
divisions), and software prefetching of the victims of the next shots: the generator is deterministic, so the next shots' picks can be peeked
without consuming anything (only for fights over about 1M ships; a wrong guess costs nothing but a prefetch). Raw launcher timing and peak RSS
(`/usr/bin/time`, same input for both libraries):

| Ships | first library | `tools/battlerust` |
| ---: | ---: | ---: |
| 7.2 million | 1.12 s, 88 MB | 1.36 s, 115 MB |
| 36.1 million | 8.9 s, 426 MB | 8.8-9.1 s, 567 MB |
| 59.6 million | 16.4 s, 701 MB | 15.6 s, 933 MB |

So large fights are on par in time; memory is 1.3x the first library's (12 bytes per ship there, 15.7 here): both armor and shield have to be exact
doubles for the results to equal the PHP engine's. The remaining 20-30 % at 0.6-7 million ships is per-shot bookkeeping the first library does not
have (the statistics maps of the report, the exact rapid-fire/explosion rules). The library refuses fights above 60 million ships (`maxShips`,
about 1 GB peak: 16 bytes x 60M = 960 MB of state, the measured 59.6 million ship fight peaked at 933 MB); the PHP engine, which scales them
down, takes over. The launcher costs about 1-2 ms per battle (process start).

## Deployment settings (not code)

Measured on top of the code changes, same harness:

| Setting | Effect per request |
| --- | --- |
| `composer install --no-dev --optimize-autoloader --classmap-authoritative` | about -2 to -2.5 ms |
| OPcache preload of the container (`opcache.preload=config/preload.php`) | about -2 to -2.5 ms |
| both | about -3 ms (game page 22.7 → 19.5 ms, start page 13.2 → 11.0 ms) |

Both are documented in the README (production install). They are not enabled in the DDEV development setup, where new
classes must autoload without re-dumping and code changes must apply without restarting PHP.

## Not changed / follow-ups

- Spy reports in the message list are rendered on every view (about 0.8 ms each including the whitespace filter;
  20 reports ≈ 15 ms on `messages&category=0`). Caching the rendered HTML needs a key over template, language, theme
  and timezone.
- `planet_wreckfield` is still read twice on the buildings page (repair order + navigation), and the tutorial support
  classes run one extra main-planet query.
- `Daily::emptyInactiveAllianceAndBuddy` is an N+1 (about 600 queries for 200 players); left as is because it runs once
  a day.
- `log_fleets` has no index on `start_time` for the daily cleanup; skipped because it would add write cost to every
  fleet log entry.
- PHP boot (Symfony kernel + game bootstrap) is now the largest share of a request (about 15 of 22 ms); the deployment
  settings above are the remaining cheap win.

## Per-request overhead pass (2026-11-17)

Same harness as above (PHP built-in server, `APP_ENV=prod`, interleaved A/B requests, 21 per page, median ms), dev data. The
machine is slower than in the first pass, compare the columns, not the absolute values. Game results are unchanged: crawl
diff of 60 pages (game, account, admin) is HTML-identical except CSRF tokens, the full test suite passes, and 20 concurrent
shipyard orders (plus parallel page views) on one planet gave the same queue length and resource deduction before and after.

| Page | default before → after | tuned before → after | queries before → after |
| --- | ---: | ---: | ---: |
| `/` | 21.8 → 18.0 | 16.1 → 12.9 | 9 → 5 |
| `/news` | 21.7 → 18.1 | 14.0 → 10.9 | 10 → 6 |
| `/account` | 22.4 → 19.7 | 16.3 → 12.9 | 12 → 8 |
| game overview | 34.5 → 29.5 | 27.0 → 21.2 | 38 → 25 |
| buildings | 36.7 → 31.7 | 27.3 → 23.1 | 33 → 20 |
| research | 34.2 → 28.7 | 25.5 → 21.2 | 33 → 21 |
| shipyard | 33.8 → 26.8 | 23.8 → 19.4 | 32 → 19 |
| galaxy (own system) | 43.8 → 37.5 | 30.5 → 25.5 | 38 → 25 |
| messages | 36.5 → 30.8 | 27.3 → 22.4 | 42 → 30 |
| statistics | 40.8 → 34.5 | 25.9 → 21.3 | 36 → 24 |
| empire | 41.3 → 36.1 | 32.6 → 28.0 | 42 → 28 |
| alliance | 35.9 → 30.0 | 27.2 → 23.3 | 43 → 31 |
| admin overview | 26.6 → 22.4 | 17.5 → 15.0 | 16 → 11 |

Seeded database (1000 players, 100k messages), tuned: overview 25.6 → 20.4, buildings 26.9 → 21.9, messages 36.2 → 32.0,
statistics 35.1 → 29.7, empire 32.2 → 27.2 ms; default: overview 35.5 → 30.5, messages 44.5 → 38.7 ms.

The query counts are for requests within the same second (the harness). With a second or more between requests (real play)
the planet update writes, so the saving is smaller: overview 41 → 31, buildings 35 → 27 queries (26.9 → 23.3 ms, 31.2 → 23.7 ms tuned).

Changes:
- **Config cache** (`ConfigCache`, `var/game/cache.config.php`): the parsed `config`, `config_universe` and module rows are
  read from an opcache-friendly PHP file; the field schema (and the admin language texts it loaded) is built only when
  something needs it. Every writer calls `ConfigRegistry::invalidateCache()` (`Config::save*`, `reload()`, installer,
  universe create/delete, clear cache), which writes a new generation stamp. A reader takes the stamp before it reads
  the database and stores under it, so a request that read old rows cannot publish them after a save. Rows edited by
  hand in the database need `var/game/` cleared (admin "clear cache"). Off in the test environment (`app.config_cache`).
- **Migration check** cached ("nothing pending", `cache.dbstatus.php`, keyed by database, migration directory mtime and
  `config/game.php`): no `system` scan per request. Pending migrations are never cached.
- **One init round trip**: charset/sql_mode and `READ COMMITTED` in the same init command (`Database::INIT_COMMAND`).
- **Resource update**: a plain GET page view that would not change the planet (same second as the last update, vacation
  mode, closed universe) opens no transaction and takes no row locks (`ResourceUpdate::wouldUpdate()`); POST requests and
  every request that writes keep the locking exactly as before.
- **Boot queries**: fleet events claim with one read when nothing is due (instead of transaction + UPDATE + commit);
  account row of the ban check taken from the security user refresh; alliance, diplomacy and buddy counters in one
  query; the tutorial popup needs no query while the player has no open step (remembered with the once-a-minute sync).
- **Symfony pipeline**: CSRF token manager, translator and the three rate limiters are lazy services (a plain GET built
  them for nothing): about -0.9 ms.
- **Import map** built once into `var/game/cache.importmap.php` (key: git version and script directory mtimes) outside debug,
  instead of stat-ing 67 files per request.

Not changed: the firewall itself (session, user refresh, `lazy: true` already), the router.

### Production tuning (measured, tuned column above)

The DDEV default (`opcache.validate_timestamps=1`, `revalidate_freq=0`) stats every file on every request. In production:

```ini
opcache.enable=1
opcache.validate_timestamps=0        ; reload PHP-FPM (or opcache_reset()) on every deploy
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.preload=/path/to/v0game/config/preload.php
opcache.preload_user=www-data
```

plus `composer install --no-dev --optimize-autoloader --classmap-authoritative`. Effect on this machine: overview 34.5 →
27.0 ms (-22 %), start page 21.8 → 16.1 ms. Deploy steps: `git pull`, `composer install ...`, `bin/console cache:clear`,
clear `var/game/`, reload PHP-FPM. With `validate_timestamps=0` PHP never notices edited files by itself; the game's own
cache files are safe because they are compared by key, not by file time. `config/preload.php` loads the container preload
file Symfony writes on cache warmup (`var/cache/prod/*.preload.php`); run `bin/console cache:warmup` before reloading.

## Performance pass 3

Measured on the DDEV dev box (dev mode, debug toolbar on, so absolute times are higher than in the tuned column above;
the counts and the before/after differences are what matters). Query counts come from the MySQL general log.

| Item | Before | After |
|---|---|---|
| `GameConfig` readers (`SessionPolicy`, `RateLimit`, `BotProtection`) on the start page | 7 queries (`config` 69 rows + `config_universe` 133 rows read again) | 5 queries: they read the `var/game/cache.config.php` file (`ConfigCache::loadRaw()`, same stamp and a module-free base fingerprint); tables only as the fallback (cache off, tests, cold cache) |
| Notification detector | 6 users_settings joins, ~800 identical `notification_watch` rewrites per minute (profiled, seeded DB) | 1 opted-in query for all seven flags; watch rows are written only when new or the return time changed; index `planets.b_building`, `users.b_tech` for the queue finders |
| Notification dispatch (cron) | per event: user + settings + subscriptions (3 selects), then one blocking push (or Discord, 5 s timeout) after the other | one users query for the batch, `NotificationDispatcher::notifyBatch()` loads settings and subscriptions with `IN`, pushes go through `WebPush::queueNotification()` + `flush()`, Discord posts start together and are collected afterwards; expired (404/410) subscriptions are deleted, delivered ones get one `last_used_at` UPDATE. `notify()` still works for a single call (it calls the batch path) |
| Chat poll, idle (nothing new) | messages + deleted + 2 x unread = 4 chat queries | `latestIds()` (one grouped MAX(id) query on the `(universe, channel, id)` key) decides: no new messages -> no messages query, nothing unread -> no unread query, deleted ids only when the newest id reaches `from`; 2 chat queries on a poll with a deleted-range check, 1 without. Same JSON. Index `(universe, channel, deleted, id)`; `chat_poll` limiter 30 -> 60 per minute (two open tabs poll 15 per minute each) |
| Admin chat reports view | 3 queries per reported message (71 for a full page) | 4 queries per page (heads, reports, messages with `IN`, one UNION for the before/after context) |
| API `touch()` | conditional UPDATE per call (no row written within a minute, but a statement sent) | the lookup's `last_used_at` is passed in, the statement is skipped while the stamp is under `TOUCH_INTERVAL` (60 s) old; the UPDATE stays conditional for racing requests |
| Indexes | `log_fleets` abuse aggregates scan by universe/time; users/planets queue filters scan | `log_fleets (universe, start_time)`, `planets (b_building)`, `users (b_tech)`; unused `notification_watch.user_id` dropped (migration 1795502100) |

### Lean boot of the polling endpoints

`#[LeanBoot(...)]` (src/Routing/LeanBoot.php) on an action lets `GameBootstrap` skip what the action does not read; every
gate still runs (session and login, ban, universe status, `#[RequireModule]`, rate limit, CSRF). Parts: `PLANET` (no planet
`FOR UPDATE` and no planet list), `DATA` (the element catalog is loaded only when due fleet events must be processed or
code asks for `elements()`), `LANGUAGE` (packs are read on first access; the chat poll only returns error codes). A request
that ends up rendering a full page after all (module off, ban, closed universe) completes the boot
(`Singleton::$completeLean`), so those pages are the same as before. `/game/events` uses `PLANET` + `DATA`,
`/game/chat/poll` uses `PLANET` + `DATA` + `LANGUAGE`. The payloads are byte-identical to the full boot (diffed for events
with two flights, and for four chat poll variants).

Measured (dev mode, repeated curl, median of 15 requests 1.1 s apart, logged in, `perf3/bench.sh` style: cookie jar from a
headless login): events 55.3 -> 47.5 ms, chat poll 51.2 -> 46.0 ms (-8 / -5 ms; the dev-mode Symfony toolbar and profiler
dominate what is left; the saving is the planet lock + list, the catalog and the language packs).

### Public pages without a session (HTTP cache)

`#[PublicCache(seconds)]` (src/Routing/PublicCache.php, `App\EventListener\PublicCacheListener`) marks pages that are
the same for every anonymous visitor: news, rules, universe rules, hall of fame, battle hall, team, disclaimer, privacy
(300 s) and the combat report `/report/{rid}` (86400 s). Anonymous means: GET/HEAD, no `2Moons` session cookie, no
`REMEMBERME`, no `?lang=`, no fragment/XHR request. They get `Cache-Control: public, max-age=N, s-maxage=N`, an ETag (the
page without CSP nonces and the server clock of the game env; 304 on a matching `If-None-Match`, without the CSP header so the
browser keeps the one that matches its stored body), `Vary: Accept-Language, Cookie`, no `Set-Cookie` and no CSRF meta tag.
The start page, login, registration and lost password carry the CSRF token and the proof-of-work form and stay private;
error/message pages withdraw themselves (`PublicCache::withdraw()`). The anonymous report no longer starts a game session
(it used to answer `Set-Cookie: 2Moons=deleted`).

### Cron boot

The CLI cron (`bin/console app:cron:run`, every minute) boots the container each run. `opcache.file_cache` keeps the
compiled scripts between runs of the short-lived CLI process: measured 190 ms -> 115 ms per run (boot only, no job due):

```sh
* * * * * cd /path/to/v0game && php -d opcache.enable_cli=1 -d opcache.file_cache=/var/cache/v0game-opcache -d opcache.file_cache_only=0 bin/console app:cron:run
```

(the directory must exist and be writable for the cron user). The web/FPM settings listed under "Production tuning" above
(`validate_timestamps=0`, 256 MB, `max_accelerated_files=20000`, `preload`) apply to the FPM pool only; the CLI has its own
php.ini section.
