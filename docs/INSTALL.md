# Installing V0Game

This guide starts simple and gets more technical further down. Pick your way first.

## Which way is for me?

| You have ... | Take this way | Command line needed? |
|---|---|---|
| Ordinary web hosting (shared hosting, "web space" with PHP and a database) or a server with a control panel | [ZIP download](#way-1-zip-download) | No |
| A VPS or root server where you log in over SSH | [Install script](#way-2-install-script-ssh) | Yes |
| Just want to look at it on your own PC | [DDEV](#way-3-ddev-try-it-on-your-pc) | A little |

You always need: PHP 8.4 with a few extensions, a MySQL 8.4 or MariaDB 10.11 database, and a way to run a command
every minute (cron). A domain with HTTPS is strongly recommended.

---

## Way 1: ZIP download

### 1. Download and check the file

Download `v0game-<version>.zip` **only** from the
[Releases page](https://github.com/Voidys/V0Game/releases) of the project. Download the matching
`v0game-<version>.zip.sha256` file too. The checksum proves the file was not damaged or changed on the way. Compare
it with your own calculation:

* Windows (Command Prompt): `certutil -hashfile v0game-1.26.4.zip SHA256`
* macOS: `shasum -a 256 v0game-1.26.4.zip`
* Linux: `sha256sum -c v0game-1.26.4.zip.sha256` (both files in the same folder; it prints `OK`)

On Windows and macOS, compare the printed value with the one in the `.sha256` file by eye. If they differ, download
again and do not use the file.

### 2. Check your hosting

Ask your hoster (or look in the panel) for:

* **PHP 8.4** with the extensions `pdo_mysql`, `intl`, `mbstring`, `gd`, `ctype`, `json`, `xml`, `zip`
  (and `opcache`, which most hosters have; `openssl` if mail goes through SMTP with TLS)
* **MySQL 8.4 or MariaDB 10.11** (a database, a user, a password)
* **Cron jobs** (run a command every minute) with PHP on the command line
* **HTTPS** (a free certificate, often "Let's Encrypt" in the panel)
* About 1 GB of disk space (the images are most of it)

Hosting that cannot offer PHP 8.4, or cannot run cron jobs every minute, is not suitable: the game's jobs
(statistics, fleet cleanup, bots) depend on them.

### 3. Upload

The ZIP is large (about 80 MB, mostly theme artwork), so an FTP upload of the unpacked files takes a while. If the
hoster's file manager supports it, upload the ZIP itself and extract it on the server ("Extract"/"Unzip"); that is much
faster. Otherwise unpack the ZIP on your computer and upload **the content of the folder** `v0game-<version>` (including the
hidden file `.env`) to your web space, ideally *next to* the public folder, not inside it, for
example `/home/you/v0game/`. Many panels can also unpack the ZIP on the server (file manager: "Extract"), which is
faster than uploading thousands of files.

### 4. Point the domain to the `public/` folder

The web address of your site must lead to the folder **`public/`** inside the project, not to the project folder.
Why: everything else (the database password in `config/game.php`, the source code, backups in `var/`) must never be
reachable from the internet. Only `public/` is meant to be served.

* In the hosting panel look for "Domains", "Document root" or "Web root" and set it to e.g. `v0game/public`.
* If the hoster cannot change the document root: do not upload the project into the public web folder. Instead put the
  project one level above and ask the support to point the domain to `public/`, or choose different hosting. Leaving
  the project folder itself web-accessible exposes your secrets.

### 5. Create the database

In the hosting panel open "Databases" (MySQL/MariaDB), create a database and a user with a strong password, and give
the user all rights on it. Write down host (often `localhost`), name, user and password.

### 6. Run the setup wizard

Open `https://your-domain/install/`. The wizard has five steps: server check and licence, database, game name and
administrator account, optional features (in-game chat, bots, e-mail, legal texts), and a summary with the next steps. If the check lists
something in red, fix it first (most often missing write permissions on `var/`, `config/`, `.env.local`: set the
folders to 775 / the files to 664 in the file manager, or ask the hoster).

**What the "install tool lock" means:** the wizard only works while the game is not installed yet. As soon as the
administrator account exists it closes itself; the game has to be unlocked with the file `var/ENABLE_INSTALL_TOOL`
for anything else (upgrade tool). If the admin overview warns that this file is present, delete it.

### 7. Set up the cron job

In the panel look for "Cron jobs" (also "Scheduled tasks", "Cronjobs"). Add a job that runs **every minute**
(`* * * * *`, or "every minute" in the drop-down) with this command (adjust the path to your project, and use the
PHP 8.4 binary if the panel has several):

```
cd /home/you/v0game && php bin/console app:cron:run --quiet
```

Some panels want only the program and arguments, then use `/usr/bin/php8.4 /home/you/v0game/bin/console app:cron:run --quiet`.
After a few minutes the admin panel (Information → health checks) should show the cron as running. As long as the
cron has never run or its last run is more than 5 minutes old, the admin home page shows a warning with a link to this section.

The minute cron also runs the cronjob `fleets`, which processes due fleet events (arrivals, returns), so fleets
advance at least once a minute without anyone loading a page. For on-time arrivals (important for PvP) a server
you control should additionally run the fleet driver every few seconds, as a systemd service or supervisor program
(it is safe next to the cron and page requests, event claims are atomic):

```
cd /var/www/v0game && while true; do php bin/console app:fleets:run --quiet; sleep 5; done
```

systemd: `ExecStart=/bin/sh -c 'while true; do php bin/console app:fleets:run --quiet; sleep 5; done'` with
`WorkingDirectory=/var/www/v0game`, `Restart=always`. Shared hosting without long-running processes keeps the
minute cron only. DDEV starts the loop by itself (daemon `fleets` in `.ddev/config.yaml`).

### 8. Turn on HTTPS

Activate the certificate in the panel and, if offered, "force HTTPS". Then open `https://your-domain/` and log in with
the administrator account. The admin area is at `https://your-domain/admin`.

---

## Way 2: Install script (SSH)

For a VPS where you are comfortable with a shell. Download the script, **read it**, then run it. Please do not pipe it
into a shell.

```sh
curl -fsSLO https://raw.githubusercontent.com/Voidys/V0Game/main/install.sh
less install.sh
bash install.sh
```

The script asks what you want (local try-out with DDEV, install on this server, or only check requirements), tests
git, PHP 8.4 and its extensions, Composer (it can download Composer into the project, verified against the official
signature), a database server and the disk space, and prints how to install what is missing for apt, dnf and pacman.
It does not use sudo on its own. Then it fetches the latest release with git, runs
`composer install --no-dev --optimize-autoloader --classmap-authoritative`, creates the folders, writes `.env.local`
(production mode, random `APP_SECRET`; the admin dashboard warns if the secret is a public default or too short, or if the server runs in development mode) and either runs `php bin/console app:install` in the terminal or points you to
the web wizard. At the end it prints the next steps (document root, cron line, HTTPS).

Useful options (`bash install.sh --help` lists everything):

```sh
bash install.sh --mode server --dir /var/www/v0game          # skip the menu
bash install.sh --version v1.26.4 ...                          # a specific release (default: latest)
bash install.sh --dry-run --mode server --dir /tmp/x --yes    # show what would happen
# unattended, secrets from the environment:
V0GAME_DB_PASSWORD=... V0GAME_ADMIN_PASSWORD=... bash install.sh --mode server --dir /var/www/v0game --yes \
    --db-name v0game --db-user v0game --admin-name admin --admin-email you@example.com --language en --speed classic
```

The `--db-*`, `--game-name`, `--language`, `--timezone`, `--speed` (`classic`, `fast`, `blitz`), `--registration`
(`open`, `closed`), `--admin-*`, `--bots`, `--chat`, `--chat-global`/`--no-chat-global`, `--chat-block-links`/`--no-chat-block-links` (the chat is off unless
`--chat`) and `--base-url` options go to `bin/console app:install`.

Afterwards:

* **Web server:** document root `<dir>/public`. nginx: see [nginx.md](nginx.md). Apache: `DocumentRoot <dir>/public`,
  `AllowOverride All` (the `.htaccess` in `public/` does the rewriting), PHP via PHP-FPM or `mod_php` 8.4.
* **Permissions:** the web server's user must write to `var/`, `public/uploads/`, `public/stats/`,
  `config/` (until installed) and `.env.local`. For example `sudo chgrp -R www-data var public/uploads public/stats config .env.local`.
* **Cron** (as the web server's user or your own): `* * * * * cd /var/www/v0game && php bin/console app:cron:run --quiet`;
  optional fleet driver every few seconds: `while true; do php bin/console app:fleets:run --quiet; sleep 5; done` (systemd/supervisor, see step 7)
* **HTTPS:** e.g. `certbot --nginx`.

Production OPcache settings (`opcache.validate_timestamps=0` with a PHP-FPM reload on every deploy, preloading, authoritative
class map) save about 20 % per request, see [PERFORMANCE.md](PERFORMANCE.md#production-tuning-measured-tuned-column-above).
After a deploy or after editing the config tables by hand, clear `var/game/` (admin "clear cache") so the cached configuration is read again.

---

## Way 3: DDEV (try it on your PC)

[Docker](https://www.docker.com/products/docker-desktop) and
[DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) are needed.

```sh
git clone https://github.com/Voidys/V0Game.git
cd V0Game
ddev start
ddev composer install
```

Open `https://v0game.ddev.site/install/`. The wizard asks for the database (in DDEV: host `db`, user `db`,
password `db`, name `db`), the game settings and your administrator account. The cron job runs by itself inside DDEV.
`bash install.sh --mode ddev` does the same steps with requirement checks. Remove everything again with `ddev delete`.

---

## Updating

**Always back up first** (see [Backups](#backups)).

* **ZIP installation:** download and verify the new ZIP. Replace all files of the project **except `config/` (your
  `game.php`), `.env.local` (written by the installer), `var/`, `public/uploads/` and `public/stats/`** (delete the old folders `src`, `templates`,
  `translations`, `vendor`, `public`, `install`, `bin` first, then upload the new ones; do not merge old and new
  `vendor/`). Then open the site once: pending database migrations run automatically on that request. Read
  `CHANGES.md` for notes on the version.
* **Script or git installation:** `cd` into the project and run `bash install.sh --dir . ` (it detects the checkout and
  updates), or by hand: `git pull` (or `git fetch --tags && git checkout v<new>`) and
  `composer install --no-dev --optimize-autoloader --classmap-authoritative`. Migrations run on the next request.
* If something looks odd after an update, clear the cache: `php bin/console cache:clear` (or empty `var/cache/` and
  `var/game/`).
* **Community links (new):** the game no longer ships links to the pr0game Discord, wiki, donation page or userscripts.
  A fresh install shows none. If your server relied on them, set them in the admin area under *Configuration >
  Community links* (`discord_url`, `wiki_url` without trailing slash, `donate_url`, `userscripts_url`); an empty field
  hides the link everywhere. Old values are not migrated.

## Backups

* **Database** (the game itself): `mysqldump --single-transaction -u USER -p DBNAME > v0game-$(date +%F).sql`, or
  "Export" in phpMyAdmin or the hosting panel. The admin panel can also create dumps into `var/backups/`.
* **Files:** `config/game.php` and `.env.local` (credentials; keep them private) and `public/uploads/`.
  Everything else can be re-downloaded.
* Store backups outside the web space and test a restore now and then.

## Security checklist

- [ ] HTTPS on, HTTP redirects to HTTPS
- [ ] Document root is `public/` (check: `https://your-domain/config/game.php` and `/.env` must give 404, not content)
- [ ] `config/game.php` and `.env.local` are not world-readable (640/660) and never uploaded anywhere public
- [ ] Strong, unique administrator password
- [ ] Mail configured (Admin → settings), so password resets work
- [ ] `var/ENABLE_INSTALL_TOOL` does not exist after installing
- [ ] Production mode: `APP_ENV=prod` (the checked-in `.env` defaults to `prod`, the installer also writes `APP_ENV=prod` and `APP_DEBUG=0` to `.env.local`; only DDEV switches to `dev` through `.ddev/config.yaml`). Never set `APP_ENV=dev` on a public server: in `dev` the Symfony profiler and debug pages are reachable.
- [ ] PHP, MySQL/MariaDB and the operating system receive updates; update V0Game when new versions appear
- [ ] Regular backups
- [ ] Behind a reverse proxy or load balancer: `SYMFONY_TRUSTED_PROXIES` set (see below)
- [ ] `DEFAULT_URI` in `.env.local` is your public address (the setup wizard writes it); e-mail links (password reset, account activation) use it. Without it (outside development) those mails are not sent (an error is logged per refused mail and the admin home page shows a warning): the Host header of a request is never used for links. The web installer writes it from the address you opened it at, `app:install` from `--base-url`; an existing install must add `DEFAULT_URI=https://your-domain` to `.env.local`
- [ ] `TRUSTED_HOSTS` in `.env.local` lists the host names of your site (see below)
- [ ] Anti-abuse settings reviewed per universe (Admin → universe settings, see below). Their defaults keep the classic, permissive rules; the admin home page lists every protection that is still off

### Reverse proxies and client IPs

The game stores each player's IP address (multi-account detection, login history, abuse limits). It trusts
only the address of the TCP connection by default; `X-Forwarded-For` and similar headers are ignored, so
nobody can fake an address with a request header.

If a reverse proxy, CDN or load balancer sits in front of the web server, every request comes from the
proxy and all players would share one IP. Tell the game which proxies to trust, in `.env.local`:

```
# comma separated IP addresses or CIDR ranges of your proxies
SYMFONY_TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8
# or: trust whatever connects directly (only if the web server is not reachable except through the proxy)
SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR
```

The proxy has to set `X-Forwarded-For` (and `X-Forwarded-Proto` for HTTPS). Behind Cloudflare use its published
IP ranges. DDEV: the router container is the proxy, so a local `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR` shows the
browser's address.

### Trusted hosts

The `Host` header comes from the visitor. To make sure the game only ever works with your own names, set in `.env.local`:

```
# comma separated host names; "*.example.com" admits sub-domains (uni2.example.com) and example.com itself
TRUSTED_HOSTS=example.com,www.example.com
```

Requests with any other `Host` header are answered with 400. Leave it empty during development (DDEV). Mail links do
not depend on it: they are built from `DEFAULT_URI`.

### Anti-abuse settings

These universe settings decide how hard pushing and collusion are. The defaults are the safe values (existing
universes still on the former permissive defaults were switched by migration 1794902100). Operators may relax them;
the admin overview warns while a setting is relaxed:

| Setting | Default | Recommended | Effect |
| --- | --- | --- | --- |
| `transport_push_check` + `transport_push_limit` | on, `100000` | on, e.g. `100000` | refuses transports, holds and transfers to another player when the 7-day value sent minus the value returned (deuterium units, transferred ships at cost) exceeds the limit; both must be set |
| `market_push_tolerance` | `10` % | `10` | allowed value skew of a market trade |
| `honor_pair_cap` | `3` | `3` (`0` = unlimited) | honourable fights per pair of players per day |
| `bash_war_exempt` | off | off | a declared war lifts the bash limit (never between accounts sharing an IP or a multi group) |
| `depot_cost_real_consumption` | on | on | alliance depot holds cost the fleet's real consumption |

Always on, no setting: no rebuild protection after losing a fleet only to linked accounts (shared IP, multi group,
alliance, buddy). By rule, interplanetary missile flights never occupy a fleet slot (they are still counted for bash
protection, `bash_count_missiles`). The admin page "abuse report" lists one-sided transfers, repeated trades, rebuild
protection grants and late recalls for review.

### What the game does for you

- Every state-changing request (all forms, fetch calls and action links) carries a per-session CSRF token
  (`<meta name="csrf-token">`, hidden field `_csrf` or header `X-CSRF-Token`); requests without it get `403`.
  Behind a cache, never cache logged-in or form pages.
- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS; the session id is renewed on login, logout and when
  the admin password is entered. A password change ends all other sessions of the account.
- Failed logins are throttled (5 per 15 minutes per IP and e-mail), registration, "lost password" and the admin
  password prompt have their own limits (`config/packages/framework.yaml`, `rate_limiter`). The counters live in the
  application cache (`var/cache`); clear them with `bin/console cache:pool:clear cache.rate_limiter`.
- In-game request limit per account (`App\EventListener\RateLimitListener`): token buckets for state-changing requests
  (POSTs and the action links) and for expensive pages (galaxy, player card, search, statistics, battle hall, battle
  reports, records). Beyond the budget the player gets a translated `429` page (JSON `429` for script calls) with a
  `Retry-After` header. The defaults are generous (actions: burst 30, 1 per second; reads: burst 60, 2 per second), so
  normal play never sees it. Tune or switch off with the `app.game_rate_limit.*` parameters in `config/services.yaml`
  (`enabled: false` turns it off); the board's daily caps are `app.board_votes_per_day` and `app.board_writes_per_day`.
  Counters live in the cache pool `cache.rate_limiter` (see above).
- Optional daily fleet caps per account (`App\Security\FleetDailyCaps`, fixed window of 24 h from the first counted
  launch), off by default (`0`): `app.fleet_launches_per_day` limits all fleet launches (including the galaxy spy and
  recycle shortcuts and missiles, but not market purchase fleets), `app.attacks_per_day` limits attacks (attack, ACS
  attack and join, destruction, missile). Set them in `config/services.yaml`; a refused launch is not counted.
- Responses carry `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` and `Permissions-Policy` headers.

## Troubleshooting

| Symptom | What to do |
|---|---|
| White page / "Error 500" right after upload | Look at `var/log/game.log` and the web server's error log. Most often: a **missing PHP extension** (compare with the list above, `php -m`), or PHP older than 8.4 for the web (not just the command line). |
| "403 Forbidden" on `/install/` (or on every page) | The document root points at the project folder instead of `public/`: `/install/` then opens the real `install/` folder, which has no start page and may not be listed. Point the domain to `public/` ([step 4](#4-point-the-domain-to-the-public-folder)); permissions are not the cause. If the root is already `public/`: the web server user must be able to read `public/index.php`, and Apache needs `AllowOverride All`. |
| "Not writable" in the wizard, or errors about `var/` | The web server user needs write access to `var/`, `config/`, `.env.local`, `public/uploads/`: 775/664 or `chgrp` to the web server group. |
| Pages show but images/CSS 404, or every URL except the front page 404s | Rewriting is missing: nginx `try_files` as in [nginx.md](nginx.md); Apache `AllowOverride All` and `mod_rewrite`. |
| Game works, but nothing happens in the background (no new statistics, fleets stuck) | The cron job is not running. Run the command by hand (`php bin/console app:cron:run`) and look at the output. In the admin panel open Information and read the health checks. Check the panel's cron logs and that the PHP binary is 8.4. |
| Wizard says it is closed | The game is already installed (`config/game.php` exists), or `var/` is not writable so the unlock file cannot be created. |
| Blank/odd after update | `php bin/console cache:clear`, empty `var/game/`. |

Still stuck? Open an issue at <https://github.com/Voidys/V0Game/issues> with the last lines of `var/log/game.log`
(remove passwords first).
