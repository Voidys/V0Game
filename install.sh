#!/usr/bin/env bash
#
# V0Game installer for the command line.
#
# Download, read, then run (please do not pipe it into a shell):
#
#   curl -fsSLO https://raw.githubusercontent.com/Voidys/V0Game/main/install.sh
#   less install.sh
#   bash install.sh
#
# What it does: checks your system, fetches V0Game with git, installs the PHP
# dependencies with Composer and prepares the folders. Then you either run the
# command line installer (bin/console app:install) or open the web wizard.
# It never uses sudo on its own; if a step needs it, it tells you why and asks.
# Full guide: README.md ("Quick start", "Running a server")
set -euo pipefail

REPO_URL="https://github.com/Voidys/V0Game.git"
API_LATEST="https://api.github.com/repos/Voidys/V0Game/releases/latest"
MIN_PHP="8.4.1"
MIN_DISK_MB=900

DIR="./v0game"
VERSION=""
MODE=""
ASSUME_YES=0
DRY_RUN=0
APP_ARGS=()     # options handed to `bin/console app:install`
DB_PASSWORD_ARG=""
ADMIN_PASSWORD_ARG=""

usage() {
    cat <<'EOF'
Usage: bash install.sh [options]

General
  -h, --help              show this help
  --dir <path>            target directory (default: ./v0game)
  --version <tag|main>    release tag such as v1.26.0, or main (default: latest release, else main)
  --mode ddev|server      ddev   = try it locally with Docker + DDEV
                          server = install on this machine (needs PHP 8.4, Composer, git)
                          without --mode a menu is shown
  --yes                   no questions; take values from the options below / the environment
  --dry-run               only print what would be done (requirement checks still run)

Options for the command line installer (mode server, passed to app:install)
  --db-host <h>  --db-port <p>  --db-user <u>  --db-password <pw>  --db-name <n>  --db-prefix <p>
  --game-name <name>  --language de|en  --timezone <tz>  --speed classic|fast|blitz
  --registration open|closed  --base-url <https://...>
  --admin-name <n>  --admin-email <e>  --admin-password <pw>  --bots <0..50>
  --chat  --chat-global|--no-chat-global  --chat-block-links|--no-chat-block-links
                          (in-game chat; off unless --chat, then global channel and link blocking default on)
  The admin password can instead be set in V0GAME_ADMIN_PASSWORD, the database
  password in V0GAME_DB_PASSWORD (a password given as an option is visible in
  the process list while the installer runs; the environment is safer).
  Without any of these options (and without --yes) the installer asks you.

Examples
  bash install.sh                                  # menu
  bash install.sh --mode ddev --dir ~/v0game      # local try-out
  bash install.sh --mode server --dir /var/www/v0game --yes --db-name v0 --db-user v0 \
       --admin-name admin --admin-email me@example.com   # password via V0GAME_* environment
EOF
}

# ---------------------------------------------------------------- output helpers
if [ -t 1 ]; then
    C_OK=$'\033[32m' C_BAD=$'\033[31m' C_WARN=$'\033[33m' C_B=$'\033[1m' C_0=$'\033[0m'
else
    C_OK="" C_BAD="" C_WARN="" C_B="" C_0=""
fi
say()   { printf '%s\n' "$*"; }
head1() { printf '\n%s== %s ==%s\n' "$C_B" "$*" "$C_0"; }
ok()    { printf '  [%sOK%s]      %s\n' "$C_OK" "$C_0" "$*"; }
miss()  { printf '  [%sMISSING%s] %s\n' "$C_BAD" "$C_0" "$*"; }
opt()   { printf '  [%soptional%s] %s\n' "$C_WARN" "$C_0" "$*"; }
warn()  { printf '%sWarning:%s %s\n' "$C_WARN" "$C_0" "$*" >&2; }
die()   { printf '%sError:%s %s\n' "$C_BAD" "$C_0" "$*" >&2; exit 1; }

# run a command, or only show it with --dry-run
run() {
    if [ "$DRY_RUN" = 1 ]; then
        printf '  [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

# ask "question" "default" -> answer in $REPLY (default in --yes mode)
ask() {
    if [ "$ASSUME_YES" = 1 ] || [ ! -t 0 ]; then REPLY="$2"; return; fi
    read -r -p "$1 [$2] " REPLY || REPLY=""
    REPLY="${REPLY:-$2}"
}
# confirm "question" y|n -> returns 0 for yes
confirm() {
    ask "$1 (y/n)" "$2"
    case "$REPLY" in y|Y|yes|YES|j|J|ja) return 0 ;; *) return 1 ;; esac
}

# ---------------------------------------------------------------- argument parsing
need_arg() { [ $# -ge 2 ] && [ -n "$2" ] || die "option $1 needs a value (see --help)"; }
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --dir) need_arg "$@"; DIR="$2"; shift ;;
        --version) need_arg "$@"; VERSION="$2"; shift ;;
        --mode) need_arg "$@"; MODE="$2"; shift ;;
        --yes|-y) ASSUME_YES=1 ;;
        --dry-run) DRY_RUN=1 ;;
        --db-host|--db-port|--db-user|--db-name|--db-prefix|--game-name|--language|--timezone|--speed|\
        --registration|--base-url|--admin-name|--admin-email|--bots)
            need_arg "$@"; APP_ARGS+=("$1=$2"); shift ;;
        --chat|--no-chat|--chat-global|--no-chat-global|--chat-block-links|--no-chat-block-links) APP_ARGS+=("$1") ;;
        --db-password) need_arg "$@"; DB_PASSWORD_ARG="$2"; shift ;;
        --admin-password) need_arg "$@"; ADMIN_PASSWORD_ARG="$2"; shift ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
    shift
done
case "$MODE" in ""|ddev|server) ;; *) die "--mode must be ddev or server" ;; esac

# ---------------------------------------------------------------- system helpers
have() { command -v "$1" >/dev/null 2>&1; }

# package manager, to print matching install hints
PKG=""
if   have apt-get; then PKG=apt
elif have dnf;     then PKG=dnf
elif have pacman;  then PKG=pacman
fi

# hint <what> -> how to install it on this distribution
hint() {
    local what="$1"
    case "$PKG:$what" in
        apt:git)      say "      sudo apt install git" ;;
        dnf:git)      say "      sudo dnf install git" ;;
        pacman:git)   say "      sudo pacman -S git" ;;
        apt:php)      say "      sudo apt install php8.4-cli php8.4-mysql php8.4-intl php8.4-mbstring php8.4-gd php8.4-curl php8.4-xml php8.4-zip"
                      say "      (PHP 8.4 is not in older Debian/Ubuntu releases; see https://deb.sury.org or https://launchpad.net/~ondrej/+archive/ubuntu/php)" ;;
        dnf:php)      say "      sudo dnf install php-cli php-mysqlnd php-intl php-mbstring php-gd php-xml php-pdo   (PHP 8.4 via Remi: https://rpms.remirepo.net)" ;;
        pacman:php)   say "      sudo pacman -S php php-gd php-intl" ;;
        apt:ext)      say "      sudo apt install php8.4-$2" ;;
        dnf:ext)      say "      sudo dnf install php-$2" ;;
        pacman:ext)   say "      enable extension=$2 in /etc/php/php.ini (pacman -S php-$2 where it is a separate package)" ;;
        *:docker)     say "      https://docs.docker.com/engine/install/" ;;
        *:ddev)       say "      https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/" ;;
        *:composer)   say "      this script can download Composer into the project (verified), or see https://getcomposer.org/download/" ;;
        *)            say "      install '$what' with your package manager" ;;
    esac
}

# version_ge A B -> true if A >= B
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }

FAILED=0
check_git() {
    if have git; then ok "git $(git --version | awk '{print $3}')"; else miss "git"; hint git; FAILED=1; fi
}

check_php() {
    if ! have php; then miss "PHP >= $MIN_PHP"; hint php; FAILED=1; return; fi
    local v; v="$(php -r 'echo PHP_VERSION;')"
    if version_ge "$v" "$MIN_PHP"; then ok "PHP $v"; else miss "PHP >= $MIN_PHP (found $v)"; hint php; FAILED=1; fi
    local mods ext
    mods="$(php -m | tr 'A-Z' 'a-z')"
    for ext in pdo_mysql intl mbstring gd curl ctype json; do
        if printf '%s\n' "$mods" | grep -qx "$ext"; then ok "PHP extension $ext"
        else miss "PHP extension $ext"; hint ext "${ext/pdo_mysql/mysql}"; FAILED=1; fi
    done
    for ext in opcache ffi ds; do
        # php -m lists Zend OPcache as "zend opcache"
        if printf '%s\n' "$mods" | grep -qx "$ext\|zend $ext"; then ok "PHP extension $ext (optional)"
        else opt "PHP extension $ext not loaded (opcache: faster; ffi/ds: not required)"; fi
    done
}

COMPOSER_CMD=()
find_composer() {
    if have composer; then COMPOSER_CMD=(composer)
    elif [ -f "$DIR/composer.phar" ]; then COMPOSER_CMD=(php "$DIR/composer.phar")
    fi
}
check_composer() {
    find_composer
    if [ "${#COMPOSER_CMD[@]}" -gt 0 ]; then
        ok "Composer ($(COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_CMD[@]}" --version 2>/dev/null | head -n1))"
    else
        miss "Composer"; hint composer
        return 1
    fi
}

# Download Composer into <dir> with the documented signature check
# (https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md)
install_composer_locally() {
    local target="$1" expected actual
    have curl || die "curl is needed to download Composer"
    say "Downloading the Composer installer and verifying its signature ..."
    if [ "$DRY_RUN" = 1 ]; then
        say "  [dry-run] fetch https://composer.github.io/installer.sig and installer, verify SHA-384, write $target/composer.phar"
        COMPOSER_CMD=(php "$target/composer.phar"); return
    fi
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    local tmp; tmp="$(mktemp -d)"
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
        rm -rf "$tmp"; die "Composer installer signature mismatch, not running it"
    fi
    php "$tmp/composer-setup.php" --quiet --install-dir="$target"
    rm -rf "$tmp"
    COMPOSER_CMD=(php "$target/composer.phar")
    ok "Composer downloaded to $target/composer.phar"
}

check_mysql() {
    local found=0
    if have mysql || have mariadb; then found=1; ok "MySQL/MariaDB client found"; fi
    if have mysqld || have mariadbd; then found=1; ok "MySQL/MariaDB server found"; fi
    if [ "$found" = 0 ] && have php && php -r '$s=@fsockopen("127.0.0.1",3306,$e,$m,1); exit($s?0:1);'; then
        found=1; ok "something listens on 127.0.0.1:3306"
    fi
    [ "$found" = 1 ] || opt "no local MySQL/MariaDB found (fine if the database lives on another host, or at your hoster)"
}

check_disk() {
    local base="$DIR"
    while [ ! -d "$base" ]; do base="$(dirname "$base")"; done
    local free_mb; free_mb=$(( $(df -Pk "$base" | awk 'NR==2 {print $4}') / 1024 ))
    if [ "$free_mb" -ge "$MIN_DISK_MB" ]; then ok "free disk space: ${free_mb} MB"
    else miss "free disk space: ${free_mb} MB (need about $MIN_DISK_MB MB)"; FAILED=1; fi
}

check_server_requirements() {
    head1 "Requirements (server)"
    FAILED=0
    check_git; check_php
    check_composer || true
    check_mysql; check_disk
    return "$FAILED"
}

# ---------------------------------------------------------------- version resolution
resolve_version() {
    [ -z "$VERSION" ] || return 0
    if have curl; then
        VERSION="$(curl -fsSL --max-time 10 "$API_LATEST" 2>/dev/null \
            | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1 || true)"
    fi
    if [ -z "$VERSION" ]; then
        warn "could not determine the latest release, using branch main"
        VERSION="main"
    fi
}

# ---------------------------------------------------------------- directory handling
ABS_DIR=""
abs_path() {
    case "$1" in /*) ABS_DIR="$1" ;; *) ABS_DIR="$PWD/${1#./}" ;; esac
}
is_checkout() { [ -f "$1/bin/console" ] && [ -f "$1/install/VERSION" ] && [ -f "$1/composer.json" ]; }

# sets UPDATE=1 for an existing checkout, refuses other non-empty directories
UPDATE=0
prepare_dir() {
    abs_path "$DIR"
    if [ -d "$DIR" ] && [ -n "$(ls -A "$DIR" 2>/dev/null)" ]; then
        if is_checkout "$DIR"; then
            say "$DIR already contains V0Game $(cat "$DIR/install/VERSION")."
            confirm "Update it (git pull + composer install)?" y || die "nothing to do"
            UPDATE=1
        else
            die "$DIR exists and is not empty (and not a V0Game checkout). Choose another --dir."
        fi
    fi
}

fetch_sources() {
    resolve_version
    say "Fetching V0Game $VERSION into $DIR ..."
    run git clone --depth 1 --branch "$VERSION" "$REPO_URL" "$DIR"
}

update_sources() {
    [ -d "$DIR/.git" ] || die "$DIR is not a git checkout, so it cannot be updated by git. See 'Updating' in README.md."
    say "Backup reminder: dump your database and copy config/game.php and .env.local before updating."
    confirm "Continue?" y || die "aborted"
    resolve_version
    run git -C "$DIR" fetch --tags --depth 1 origin "$VERSION"
    if [ "$VERSION" = main ]; then run git -C "$DIR" merge --ff-only FETCH_HEAD
    else run git -C "$DIR" checkout --detach FETCH_HEAD; fi
}

composer_install() {
    say "Installing PHP dependencies (this can take a minute) ..."
    if [ "$DRY_RUN" = 1 ]; then
        say "  [dry-run] (cd $DIR && APP_ENV=prod composer install --no-dev --optimize-autoloader --classmap-authoritative)"
        return
    fi
    (cd "$DIR" && APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_CMD[@]}" install --no-dev \
        --optimize-autoloader --classmap-authoritative --no-interaction)
}

# production mode for a git checkout (.env says dev). Only adds missing keys, never overwrites an
# existing .env.local; the installer adds DATABASE_URL and APP_SECRET (if missing) to the same file.
write_env_local() {
    local f="$DIR/.env.local"
    if [ "$DRY_RUN" = 1 ]; then say "  [dry-run] add APP_ENV=prod, APP_DEBUG=0 to $f if missing"; return; fi
    touch "$f"
    grep -q '^APP_ENV=' "$f" || echo 'APP_ENV=prod' >> "$f"
    grep -q '^APP_DEBUG=' "$f" || echo 'APP_DEBUG=0' >> "$f"
    chmod 660 "$f" 2>/dev/null || true
}

prepare_permissions() {
    say "Preparing folders ..."
    if [ "$DRY_RUN" = 1 ]; then
        say "  [dry-run] mkdir var/{cache,log,game,sessions,backups,share} public/uploads/news public/stats; chmod g+w"
        return
    fi
    (cd "$DIR" && mkdir -p var/cache var/log var/game var/sessions var/backups var/share public/uploads/news public/stats config \
        && chmod -R u+rwX,g+rwX var public/uploads public/stats config 2>/dev/null) || true
    say "  The web server's user (often www-data, nginx or apache) must be able to write to:"
    say "    var/  public/uploads/  public/stats/  config/  .env.local"
    say "  If PHP runs as another user than you, this needs root once, for example:"
    say "    sudo chgrp -R www-data $ABS_DIR/var $ABS_DIR/public/uploads $ABS_DIR/public/stats $ABS_DIR/config $ABS_DIR/.env.local"
    say "  (replace www-data with your web server user). After the installer has run,"
    say "  config/ can be made read-only again."
}

# ---------------------------------------------------------------- end-of-run notes
next_steps_server() {
    local url="${1:-https://your-domain}"
    head1 "Next steps"
    say "1. Web server: the document root must be the folder  $ABS_DIR/public"
    say "   (never the project folder: config and source code must not be reachable)."
    say "   nginx: example in $ABS_DIR/README.md (section nginx)   Apache: DocumentRoot $ABS_DIR/public, AllowOverride All"
    say "   (public/.htaccess does the rewriting; PHP-FPM or mod_php with PHP 8.4)."
    say "2. HTTPS: get a certificate (for example certbot --nginx / --apache) and serve the game over https."
    say "3. Cron: run  crontab -e  as the web server's user (or yours) and add"
    say "     * * * * * cd $ABS_DIR && php bin/console app:cron:run --quiet"
    say "   Without it fleets never arrive and nothing progresses. Help, also for hosting panels:"
    say "     https://github.com/Voidys/V0Game#cron-job"
    say "4. Open $url/ (the game) and $url/admin to log in as the administrator."
    say "Details, updating and backups: $ABS_DIR/README.md"
}

# ---------------------------------------------------------------- modes
mode_check() {
    check_server_requirements || { say; say "Some requirements are missing (see above)."; return 1; }
    say; say "Everything needed is available."
}

mode_ddev() {
    head1 "Requirements (DDEV)"
    FAILED=0
    check_git
    if have docker; then ok "Docker"; else miss "Docker"; hint docker; FAILED=1; fi
    if have ddev; then ok "DDEV $(ddev --version 2>/dev/null | awk '{print $NF}')"; else miss "DDEV"; hint ddev; FAILED=1; fi
    [ "$FAILED" = 0 ] || die "install the missing tools and run the script again"
    prepare_dir
    say
    say "This starts a local copy of V0Game in Docker containers (web server, PHP, database)."
    say "Nothing is installed on your system besides the project folder; remove it with 'ddev delete'."
    confirm "Continue?" y || die "aborted"
    if [ "$UPDATE" = 1 ]; then update_sources; else fetch_sources; fi
    run ddev start --skip-confirmation 2>/dev/null || run ddev start
    if [ "$DRY_RUN" = 1 ]; then say "  [dry-run] (cd $DIR && ddev composer install)"; else (cd "$DIR" && ddev composer install); fi
    local name="v0game"
    [ -f "$DIR/.ddev/config.yaml" ] && name="$(sed -n 's/^name:[[:space:]]*//p' "$DIR/.ddev/config.yaml" | head -n1)"
    head1 "Done"
    say "Open  https://$name.ddev.site/install/  in your browser. The wizard walks you through:"
    say "  1. requirements check  2. database (in DDEV: host db, user db, password db, name db)"
    say "  3. game name, language, speed  4. your administrator account"
    say "The cron job runs by itself inside DDEV. Stop the project with 'ddev stop' (in $DIR)."
}

mode_server() {
    check_server_requirements || true
    if [ "$FAILED" = 1 ]; then die "fix the missing requirements above and run the script again"; fi
    prepare_dir
    if [ "${#COMPOSER_CMD[@]}" = 0 ]; then
        say
        say "Composer is missing. It can be downloaded into the project folder (composer.phar, signature checked)."
        say "This needs no root rights and changes nothing outside $DIR."
        confirm "Download Composer there?" y || die "Composer is required"
        run mkdir -p "$DIR"
        install_composer_locally "$DIR"
    fi
    if [ "$UPDATE" = 1 ]; then
        update_sources
        composer_install
        head1 "Updated"
        say "Database migrations run automatically on the next request to the site."
        say "Check the changelog (CHANGES.md) and README.md ('Updating') for notes on this version."
        say "Clear caches if something looks odd:  cd $ABS_DIR && php bin/console cache:clear"
        return
    fi
    fetch_sources
    composer_install
    write_env_local
    prepare_permissions

    # CLI installer or web wizard?
    local choice="wizard"
    if [ "${#APP_ARGS[@]}" -gt 0 ] || [ -n "${DB_PASSWORD_ARG:-}" ] || [ -n "${ADMIN_PASSWORD_ARG:-}" ]; then
        choice="cli"
    elif [ "$ASSUME_YES" = 0 ] && [ -t 0 ]; then
        say
        say "How do you want to finish the setup?"
        say "  1) Here in the terminal (bin/console app:install asks for database and admin account)"
        say "  2) In the browser (web wizard at /install/)"
        ask "Choose" 2
        [ "$REPLY" = 1 ] && choice="cli"
    fi
    local url=""
    if [ "$choice" = cli ]; then
        local args=(${APP_ARGS[@]+"${APP_ARGS[@]}"})
        [ -n "${DB_PASSWORD_ARG:-}" ] && args+=("--db-password=$DB_PASSWORD_ARG")
        [ -z "${DB_PASSWORD_ARG:-}" ] && [ -n "${V0GAME_DB_PASSWORD:-}" ] && args+=("--db-password=$V0GAME_DB_PASSWORD")
        [ -n "${ADMIN_PASSWORD_ARG:-}" ] && export V0GAME_ADMIN_PASSWORD="$ADMIN_PASSWORD_ARG"
        local noint=()
        [ "$ASSUME_YES" = 1 ] && noint=(--no-interaction)
        say "Running the installer ..."
        if [ "$DRY_RUN" = 1 ]; then
            say "  [dry-run] (cd $DIR && php bin/console app:install ${APP_ARGS[*]:-} ${noint[*]:-}   # secrets hidden)"
        else
            (cd "$DIR" && php bin/console app:install ${args[@]+"${args[@]}"} ${noint[@]+"${noint[@]}"})
        fi
        # the base URL option, when given, is the best guess for the final address
        local a
        for a in ${APP_ARGS[@]+"${APP_ARGS[@]}"}; do case "$a" in --base-url=*) url="${a#--base-url=}" ;; esac; done
    fi
    next_steps_server "${url:-https://your-domain}"
    if [ "$choice" = wizard ]; then
        say "5. Setup wizard: once the web server serves the site, open https://your-domain/install/ and follow it"
        say "   (database, game name, language, speed, administrator account). It unlocks itself while no"
        say "   config/game.php exists and closes after the admin account is created."
    fi
}

# ---------------------------------------------------------------- main
main() {
    say "${C_B}V0Game installer${C_0}"
    [ "$DRY_RUN" = 1 ] && say "(dry run: nothing is changed)"
    if [ -z "$MODE" ]; then
        [ "$ASSUME_YES" = 0 ] && [ -t 0 ] || die "give --mode ddev|server (no terminal for the menu)"
        say
        say "What do you want to do?"
        say "  1) Try it locally with DDEV (Docker needed; for testing on your own computer)"
        say "  2) Install on this server (PHP 8.4, Composer, git, a database)"
        say "  3) Only check requirements"
        ask "Choose" 1
        case "$REPLY" in
            1) MODE=ddev ;;
            2) MODE=server ;;
            3) mode_check; exit $? ;;
            *) die "please answer 1, 2 or 3" ;;
        esac
    fi
    case "$MODE" in
        ddev) mode_ddev ;;
        server) mode_server ;;
    esac
}
main
