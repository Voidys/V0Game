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

## Comparison with pr0game (2026-11-23)

V0Game 1.26.10 (39261fd6) plus the fixes below against pr0game at commit 2b65627 (codeberg.org/pr0game/pr0game), pr0game
unpatched.

**Setup**
- Both run in DDEV containers on the same machine: PHP 8.4 (8.4.27 and 8.4.28), MySQL 8.4.13, production mode, the same
  OPcache settings (DDEV defaults: 500 MB, `validate_timestamps=1`, `revalidate_freq=2`). pr0game ships PHP 8.2 and
  MySQL 8.0; it was switched to 8.4 locally and runs without warnings. pr0game was installed with its own installer.
- One seeded generator writes the same universe into both schemas (they are almost identical): 1001 players, 5000 planets,
  1500 moons with mines at level 25–32 and research at 10–18, 40 alliances, 3000 buddy entries, 800 fleets in flight and
  100k messages (8000 for the measured player, 45 % of them spy reports). Queues are stored as JSON in V0Game and as PHP
  serialize in pr0game; columns only V0Game has keep their defaults. Speeds and universe status are equal, bots are off.
  Statistics were built once before the dump. A spot check of planets, players, counts and an md5 over all planet levels
  and resources matched in both databases.
- Pages: 15 alternating requests per page and game (A/B/A/B), median and p90. Query count and DB time come from
  `performance_schema`. The requests go to the PHP built-in server inside each container, so the times are application
  time without the web server.
- "Tuned" adds the V0Game-only production settings (authoritative class map, preload; see "Production tuning" above).
- Background jobs: CLI, the seeded database restored before every run, median of 3. The due fleet events (about 270)
  are the same set in both games.
- Transfer: headless Chromium through Apache with TLS and php-fpm, each project with its shipped `.htaccess`. pr0game's
  `.htaccess` configures no compression, so it was measured as shipped and again with gzip enabled at server level.
- Phone: 390×844 viewport, 4× CPU throttle, median of 5 cold loads.

**Caveats**
- The seed has no combat report bodies, only the report messages; the fleet run fights real battles.
- V0Game does more per request (CSRF, rate limits, game-events data island), and its pages carry more HTML.
- V0Game's cron runner boots once a minute (`app:cron:run`, about 0.2 s with nothing due) and the fleet runner every 5 s;
  pr0game handles both inside page requests. That background cost is not part of the page times.
- The player card is measured as a full page in both games; V0Game opens it as a popup fragment in the game (5.8 KB,
  about 25 ms).
- One machine; the second round ran while the machine was noticeably slower (absolute times up to twice those of the
  first round), so only the ratios inside a round are comparable. Differences of about 10 % on the small pages are within
  noise (buildings: +12 % default, -6 % tuned).

**First round and fixes.** The first round (same setup) showed V0Game behind on the messages list (+26 %), the fleet ship
table (+16 %), the daily cronjob (+12 %) and the phone LCP of overview and empire (about 1.25 s against 0.57 s). Fixed:
- messages: the per-category counts are adjusted in PHP after the listed messages are marked read instead of being
  counted a second time, plus a covering index `users_to_messages.user_cover` (migration 1795502200);
- fleet table: the tooltip attributes are escaped with `html` instead of `html_attr` (no numeric entity per space and
  quote; the decoded value is the same), -11 % HTML;
- phone LCP: the `content-in` page animation only slides, no opacity fade from 0; Chromium counts no LCP candidate until
  the faded content has painted (overview LCP 1024 → 360 ms in a separate before/after measurement);
- daily cronjob: indexes `buddy.owner` and `alliance_request.userID` for the per-player removals (full scans before) and
  no second SELECT per inactive player (migration 1795502300), 1388 → 840 ms.

**Results in short (second round)**
- Game pages: geometric mean -28 % (default) and -30 % (tuned), 693 → 392 queries over all game pages.
- Galaxy -77 %, statistics -81 %, spy reports -45 %, messages -22 %, empire -15 %.
- Routines: statistics rebuild -77 % (7950 → 144 queries), daily cronjob -34 %, fleet events -26 %, resource update -23 %.
- Still slower: start page +40 % (about 5 ms of Symfony and game boot), player card +24 % (full page, see caveats),
  cleaner cronjob +35 % (deletes old messages from `users_to_messages` and `messages`, pr0game from one table).
- Phone (4× CPU): FCP earlier in V0Game, LCP on par (overview 1000 vs 1012 ms, empire 924 vs 1052 ms).

### Pages, profile "default" (median / p90 of 15 interleaved requests; queries and DB time per request)

| Page | pr0game median | V0Game median | change | p90 pr0game | p90 V0Game | queries pr0game | queries V0Game | DB ms pr0game | DB ms V0Game | HTML bytes pr0game | HTML bytes V0Game | gzip pr0game | gzip V0Game |
|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|
| login/start | 12.5 ms | 17.6 ms | +40 % | 14.1 | 19.8 | 5 | 4 | 1.8 | 1.3 | 10,158 | 19,994 | 3,165 | 5,069 |
| overview | 45.3 ms | 40.8 ms | -10 % | 51.3 | 48.9 | 46 | 28 | 23.1 | 10.7 | 220,638 | 250,728 | 19,077 | 22,606 |
| buildings | 28.8 ms | 32.3 ms | +12 % | 33.4 | 37.9 | 38 | 22 | 15.8 | 8.2 | 117,043 | 109,448 | 8,723 | 12,585 |
| research | 30.7 ms | 30.1 ms | -2 % | 33.8 | 31.7 | 39 | 23 | 18.0 | 8.9 | 86,398 | 101,431 | 7,363 | 11,808 |
| shipyard | 30.9 ms | 27.3 ms | -12 % | 33.7 | 31.6 | 37 | 21 | 16.2 | 7.8 | 76,324 | 91,838 | 7,068 | 11,294 |
| defense | 30.1 ms | 24.6 ms | -18 % | 34.4 | 32.2 | 37 | 21 | 16.2 | 7.7 | 44,300 | 76,326 | 6,040 | 10,437 |
| galaxy (own system) | 153.0 ms | 35.1 ms | -77 % | 165.0 | 41.7 | 69 | 27 | 125.8 | 11.9 | 71,819 | 98,435 | 9,019 | 13,239 |
| fleet step 1 (ship table) | 30.8 ms | 33.0 ms | +7 % | 36.7 | 39.4 | 41 | 25 | 16.5 | 8.5 | 168,352 | 259,962 | 14,516 | 20,989 |
| empire | 50.8 ms | 43.3 ms | -15 % | 68.4 | 58.0 | 119 | 66 | 29.9 | 17.2 | 237,859 | 291,598 | 21,415 | 21,432 |
| messages | 54.8 ms | 42.8 ms | -22 % | 58.5 | 51.1 | 47 | 29 | 41.6 | 23.7 | 35,038 | 57,608 | 6,282 | 8,924 |
| messages (spy reports) | 128.5 ms | 70.7 ms | -45 % | 139.3 | 75.3 | 48 | 40 | 105.2 | 43.6 | 422,708 | 569,959 | 35,808 | 64,128 |
| statistics | 191.9 ms | 36.3 ms | -81 % | 200.1 | 41.0 | 90 | 27 | 172.3 | 12.8 | 81,487 | 168,879 | 9,205 | 14,140 |
| alliance | 32.7 ms | 29.4 ms | -10 % | 36.0 | 33.9 | 48 | 32 | 19.5 | 11.8 | 30,967 | 60,419 | 5,844 | 8,722 |
| player card | 28.4 ms | 35.1 ms | +24 % | 32.2 | 37.5 | 34 | 31 | 15.9 | 11.2 | 12,732 | 54,421 | 3,101 | 8,445 |
| admin overview | 23.0 ms | 24.8 ms | +8 % | 26.0 | 29.2 | 13 | 11 | 10.7 | 6.6 | 8,827 | 26,219 | 2,321 | 5,323 |

Geometric mean of the time ratio V0Game/pr0game: all pages -23 %, game pages only -28 %. Sum of queries (game pages): pr0game 693, V0Game 392.

### Pages, profile "tuned" (median / p90 of 15 interleaved requests; queries and DB time per request)

| Page | pr0game median | V0Game median | change | p90 pr0game | p90 V0Game | queries pr0game | queries V0Game | DB ms pr0game | DB ms V0Game | HTML bytes pr0game | HTML bytes V0Game | gzip pr0game | gzip V0Game |
|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|
| login/start | 8.2 ms | 12.7 ms | +54 % | 9.3 | 14.3 | 5 | 4 | 1.1 | 0.9 | 10,158 | 20,011 | 3,165 | 5,086 |
| overview | 35.8 ms | 34.6 ms | -3 % | 38.3 | 35.7 | 46 | 28 | 20.7 | 11.5 | 217,137 | 247,346 | 18,884 | 22,426 |
| buildings | 32.8 ms | 30.9 ms | -6 % | 35.9 | 34.4 | 38 | 22 | 18.2 | 8.1 | 117,043 | 109,448 | 8,724 | 12,578 |
| research | 30.8 ms | 28.4 ms | -8 % | 32.8 | 30.4 | 39 | 23 | 17.6 | 8.8 | 86,398 | 101,438 | 7,361 | 11,811 |
| shipyard | 28.2 ms | 25.5 ms | -10 % | 30.9 | 27.0 | 37 | 21 | 15.6 | 7.5 | 76,324 | 91,854 | 7,068 | 11,311 |
| defense | 28.5 ms | 24.5 ms | -14 % | 30.4 | 28.4 | 37 | 28 | 15.6 | 11.4 | 44,300 | 76,331 | 6,041 | 10,438 |
| galaxy (own system) | 178.7 ms | 41.3 ms | -77 % | 326.7 | 83.5 | 69 | 47 | 137.2 | 18.7 | 71,819 | 98,408 | 9,019 | 13,211 |
| fleet step 1 (ship table) | 33.7 ms | 33.8 ms | +0 % | 36.3 | 35.8 | 41 | 25 | 17.5 | 9.8 | 166,413 | 256,566 | 14,384 | 20,851 |
| empire | 44.2 ms | 37.9 ms | -14 % | 45.1 | 42.0 | 119 | 66 | 37.2 | 17.0 | 237,862 | 291,595 | 21,403 | 21,438 |
| messages | 60.6 ms | 50.0 ms | -18 % | 64.4 | 52.9 | 47 | 29 | 52.3 | 27.8 | 35,038 | 57,609 | 6,283 | 8,924 |
| messages (spy reports) | 145.0 ms | 75.4 ms | -48 % | 152.9 | 83.7 | 48 | 40 | 192.9 | 57.0 | 422,708 | 569,965 | 35,805 | 64,134 |
| statistics | 201.8 ms | 38.0 ms | -81 % | 208.2 | 44.8 | 90 | 27 | 180.0 | 13.5 | 81,487 | 168,873 | 9,204 | 14,132 |
| alliance | 35.7 ms | 30.3 ms | -15 % | 37.9 | 36.5 | 48 | 32 | 18.8 | 12.8 | 30,967 | 60,407 | 5,839 | 8,717 |
| player card | 27.0 ms | 30.4 ms | +13 % | 30.1 | 36.3 | 34 | 31 | 13.6 | 9.6 | 12,732 | 54,432 | 3,101 | 8,459 |
| admin overview | 22.3 ms | 23.5 ms | +6 % | 25.0 | 26.2 | 13 | 11 | 12.4 | 6.0 | 8,827 | 26,224 | 2,320 | 5,331 |

Geometric mean of the time ratio V0Game/pr0game: all pages -24 %, game pages only -30 %. Sum of queries (game pages): pr0game 693, V0Game 419.

### Background routines (CLI, seeded DB restored before every run, median of 3)

| Routine | pr0game | V0Game | change |
|---|--:|--:|--:|
| Statistics rebuild (generate + archive + records) | 3372 ms, 7950 queries | 762 ms, 144 queries | -77 % time, -98 % queries |
| Daily cronjob | 1364 ms, 2823 queries | 907 ms, 2309 queries | -34 % time, -18 % queries |
| Cleaner cronjob | 1600 ms, 17 queries | 2158 ms, 20 queries | +35 % time, +18 % queries |
| Resource update of all 5000 planets (with save) | 18638 ms, 27337 queries | 14308 ms, 21382 queries | -23 % time, -22 % queries |
| Due fleet events (about 270 events incl. battles) | 6489 ms, 14683 queries | 4800 ms, 11566 queries | -26 % time, -21 % queries |

### Transfer per page, Chromium desktop, as shipped (pr0game: no compression configured in its .htaccess)

| Page | cold pr0game | cold V0Game | change | requests cold pr0game / V0Game | warm pr0game | warm V0Game | change | network requests warm pr0game / V0Game |
|---|--:|--:|--:|--:|--:|--:|--:|--:|
| login/start | 1032 KB | 230 KB | -78 % | 23 / 26 | 10.3 KB | 5.9 KB | -43 % | 1 / 1 |
| overview | 1640 KB | 219 KB | -87 % | 37 / 51 | 199.5 KB | 21.8 KB | -89 % | 2 / 1 |
| buildings | 1902 KB | 437 KB | -77 % | 55 / 61 | 115.3 KB | 13.3 KB | -88 % | 2 / 1 |
| research | 1767 KB | 505 KB | -71 % | 48 / 62 | 85.3 KB | 12.5 KB | -85 % | 2 / 1 |
| shipyard | 1713 KB | 460 KB | -73 % | 42 / 59 | 75.5 KB | 12.0 KB | -84 % | 2 / 1 |
| galaxy (own system) | 1522 KB | 258 KB | -83 % | 40 / 52 | 70.9 KB | 13.9 KB | -80 % | 2 / 1 |
| fleet step 1 (ship table) | 1459 KB | 239 KB | -84 % | 31 / 50 | 154.1 KB | 20.5 KB | -87 % | 2 / 1 |
| empire | 1873 KB | 271 KB | -86 % | 36 / 49 | 233.3 KB | 21.9 KB | -91 % | 2 / 1 |
| messages | 1284 KB | 199 KB | -85 % | 29 / 47 | 35.1 KB | 9.7 KB | -72 % | 2 / 1 |
| statistics | 1353 KB | 196 KB | -86 % | 30 / 47 | 80.2 KB | 14.8 KB | -82 % | 2 / 1 |
| alliance | 1260 KB | 190 KB | -85 % | 29 / 46 | 31.2 KB | 9.5 KB | -69 % | 2 / 1 |

Cold-load split (KB; doc / css / js / image / font / other):

| Page | pr0game | V0Game |
|---|---|---|
| login/start | 10 / 48 / 279 / 694 / 0 / 0 | 6 / 19 / 51 / 128 / 17 / 8 |
| overview | 199 / 99 / 1048 / 293 / 0 / 0 | 22 / 78 / 53 / 41 / 17 / 8 |
| buildings | 115 / 99 / 1047 / 640 / 0 / 0 | 13 / 78 / 49 / 272 / 17 / 8 |
| research | 85 / 99 / 1046 / 536 / 0 / 0 | 13 / 78 / 47 / 342 / 17 / 8 |
| shipyard | 75 / 99 / 1046 / 493 / 0 / 0 | 12 / 78 / 49 / 296 / 17 / 8 |
| galaxy (own system) | 70 / 99 / 1056 / 252 / 45 / 0 | 14 / 78 / 55 / 86 / 17 / 8 |
| fleet step 1 (ship table) | 154 / 99 / 1078 / 82 / 45 / 0 | 21 / 78 / 77 / 30 / 17 / 16 |
| empire | 233 / 99 / 1247 / 293 / 0 / 0 | 22 / 78 / 116 / 30 / 17 / 8 |
| messages | 35 / 99 / 1067 / 82 / 0 / 0 | 10 / 78 / 56 / 30 / 17 / 8 |
| statistics | 80 / 99 / 1046 / 83 / 45 / 0 | 15 / 78 / 48 / 30 / 17 / 8 |
| alliance | 31 / 99 / 1046 / 83 / 0 / 0 | 10 / 78 / 47 / 30 / 17 / 8 |

### Transfer per page, Chromium desktop, pr0game with gzip enabled at server level (equalised)

| Page | cold pr0game | cold V0Game | change | requests cold pr0game / V0Game | warm pr0game | warm V0Game | change | network requests warm pr0game / V0Game |
|---|--:|--:|--:|--:|--:|--:|--:|--:|
| login/start | 803 KB | 230 KB | -71 % | 23 / 26 | 3.5 KB | 5.9 KB | +70 % | 1 / 1 |
| overview | 620 KB | 219 KB | -65 % | 36 / 51 | 18.3 KB | 21.5 KB | +18 % | 2 / 1 |
| buildings | 959 KB | 437 KB | -54 % | 55 / 61 | 9.6 KB | 13.3 KB | +39 % | 2 / 1 |
| research | 853 KB | 505 KB | -41 % | 48 / 62 | 8.3 KB | 12.5 KB | +52 % | 2 / 1 |
| shipyard | 809 KB | 460 KB | -43 % | 42 / 59 | 8.0 KB | 12.0 KB | +51 % | 2 / 1 |
| galaxy (own system) | 618 KB | 258 KB | -58 % | 40 / 52 | 9.7 KB | 13.9 KB | +43 % | 2 / 1 |
| fleet step 1 (ship table) | 459 KB | 239 KB | -48 % | 31 / 50 | 14.3 KB | 20.4 KB | +42 % | 2 / 1 |
| empire | 692 KB | 271 KB | -61 % | 36 / 49 | 22.1 KB | 21.9 KB | -1 % | 2 / 1 |
| messages | 404 KB | 199 KB | -51 % | 29 / 47 | 7.2 KB | 9.7 KB | +35 % | 2 / 1 |
| statistics | 446 KB | 196 KB | -56 % | 30 / 47 | 9.8 KB | 14.8 KB | +51 % | 2 / 1 |
| alliance | 399 KB | 190 KB | -52 % | 29 / 46 | 6.7 KB | 9.5 KB | +41 % | 2 / 1 |

Cold-load split (KB; doc / css / js / image / font / other):

| Page | pr0game | V0Game |
|---|---|---|
| login/start | 3 / 20 / 86 / 694 / 0 / 0 | 6 / 19 / 51 / 128 / 17 / 8 |
| overview | 18 / 33 / 277 / 293 / 0 / 1 | 22 / 78 / 53 / 41 / 17 / 8 |
| buildings | 9 / 33 / 277 / 640 / 0 / 1 | 13 / 78 / 49 / 272 / 17 / 8 |
| research | 8 / 33 / 276 / 536 / 0 / 1 | 13 / 78 / 47 / 342 / 17 / 8 |
| shipyard | 7 / 33 / 276 / 493 / 0 / 1 | 12 / 78 / 49 / 296 / 17 / 8 |
| galaxy (own system) | 9 / 33 / 279 / 252 / 45 / 1 | 14 / 78 / 55 / 86 / 17 / 8 |
| fleet step 1 (ship table) | 14 / 33 / 285 / 82 / 45 / 1 | 20 / 78 / 77 / 30 / 17 / 16 |
| empire | 22 / 33 / 345 / 293 / 0 / 1 | 22 / 78 / 116 / 30 / 17 / 8 |
| messages | 7 / 33 / 282 / 82 / 0 / 1 | 10 / 78 / 56 / 30 / 17 / 8 |
| statistics | 9 / 33 / 276 / 83 / 45 / 1 | 15 / 78 / 48 / 30 / 17 / 8 |
| alliance | 6 / 33 / 276 / 83 / 0 / 1 | 10 / 78 / 47 / 30 / 17 / 8 |

### Front-end smoothness, phone viewport 390x844, 4x CPU throttle, median of 5 cold loads

| Page | FCP pr0game | FCP V0Game | LCP pr0game | LCP V0Game | TBT pr0game | TBT V0Game | load event pr0game | load event V0Game |
|---|--:|--:|--:|--:|--:|--:|--:|--:|
| overview | 836 ms | 744 ms | 1012 ms | 1000 ms | 683 ms | 838 ms | 1788 ms | 2646 ms |
| empire | 932 ms | 824 ms | 1052 ms | 924 ms | 1156 ms | 630 ms | 2138 ms | 1901 ms |
