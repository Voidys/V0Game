#!/usr/bin/env bash
#
# Build a release ZIP of V0Game.
#
#   tools/release/build.sh <version> [--dev] [--out <dir>]
#
#   <version>  e.g. 1.26.0 (the git tag is "v<version>"); only used for file names
#              and the top-level folder in the ZIP.
#   --dev      build from the current working tree (tracked + untracked, not
#              ignored files) instead of a clean `git archive` of the tag. For testing.
#   --out DIR  output directory (default: dist/ in the repository, gitignored)
#
# Result: dist/v0game-<version>.zip and dist/v0game-<version>.zip.sha256
# Needs: git, tar, composer (or composer.phar), php 8.4, sha256sum, zip (or python3).
set -euo pipefail

usage() { sed -n '3,15p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

VERSION="" DEV=0
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$REPO/dist"
while [ $# -gt 0 ]; do
    case "$1" in
        --dev) DEV=1 ;;
        --out) OUT="$(mkdir -p "$2" && cd "$2" && pwd)"; shift ;;
        -h|--help) usage 0 ;;
        -*) echo "unknown option: $1" >&2; usage 1 ;;
        *) VERSION="$1" ;;
    esac
    shift
done
[ -n "$VERSION" ] || usage 1
VERSION="${VERSION#v}"
NAME="v0game-$VERSION"

COMPOSER=(composer)
command -v composer >/dev/null 2>&1 || COMPOSER=(php "$REPO/composer.phar")

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/$NAME"
mkdir -p "$STAGE" "$OUT"

echo "==> collecting sources ($([ "$DEV" = 1 ] && echo 'working tree' || echo "tag v$VERSION"))"
if [ "$DEV" = 1 ]; then
    (cd "$REPO" && git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf - 2>/dev/null) | tar -xf - -C "$STAGE"
else
    git -C "$REPO" archive --format=tar "v$VERSION" | tar -xf - -C "$STAGE"
fi

echo "==> installing production dependencies"
(cd "$STAGE" && APP_ENV=prod "${COMPOSER[@]}" install --no-dev --optimize-autoloader --classmap-authoritative \
    --no-interaction --no-progress --no-scripts)

echo "==> removing development-only files"
# Allowlist: everything at the top level that is not listed here is dropped.
KEEP=" bin config install licenses public src templates translations vendor var CHANGES.md LICENSE README.md \
composer.json composer.lock symfony.lock .env install.sh stats "
for entry in "$STAGE"/* "$STAGE"/.[!.]*; do
    [ -e "$entry" ] || continue
    case "$KEEP" in *" $(basename "$entry") "*) ;; *) rm -rf "$entry" ;; esac
done
# vendor: tests, docs and VCS metadata of packages (not needed at runtime)
find "$STAGE/vendor" -type d \( -iname tests -o -iname test -o -iname .git -o -iname .github \) -prune -exec rm -rf {} + 2>/dev/null || true
rm -f "$STAGE/config/game.php"

echo "==> runtime directories and environment"
rm -rf "${STAGE:?}/var"
mkdir -p "$STAGE"/var/{cache,log,game,sessions,backups,share} "$STAGE/stats" \
    "$STAGE/public/uploads/news" "$STAGE/public/stats"
for d in var/cache var/log var/game var/sessions var/backups var/share stats public/uploads/news public/stats; do
    : > "$STAGE/$d/.gitkeep"
done
# production defaults in the packaged .env (the installer writes .env.local itself: DATABASE_URL, APP_SECRET).
# .env.dev (dev secret) is not shipped.
sed -i 's/^APP_ENV=.*/APP_ENV=prod/' "$STAGE/.env"
grep -q '^APP_DEBUG=' "$STAGE/.env" && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=0/' "$STAGE/.env" || echo 'APP_DEBUG=0' >> "$STAGE/.env"
echo "$VERSION" > "$STAGE/install/VERSION"

echo "==> permissions"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/bin/console" "$STAGE/bin/battlerust" 2>/dev/null || true
[ -f "$STAGE/install.sh" ] && chmod 755 "$STAGE/install.sh"
# the web server user must write here (the installer and the game create files)
chmod 775 "$STAGE"/var "$STAGE"/var/* "$STAGE/stats" "$STAGE/public/uploads" "$STAGE/public/uploads/news" \
    "$STAGE/public/stats" "$STAGE/config"
# the installer creates .env.local next to .env, so the project root must be writable during setup
chmod 775 "$STAGE"

echo "==> packing"
ZIP="$OUT/$NAME.zip"
rm -f "$ZIP" "$ZIP.sha256"
if command -v zip >/dev/null 2>&1; then
    (cd "$WORK" && zip -qr -X "$ZIP" "$NAME")
else
    # no zip binary: Python's zipfile keeps the Unix permission bits
    python3 -I - "$WORK" "$NAME" "$ZIP" <<'PY'
import os, sys, zipfile
work, name, out = sys.argv[1:4]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(os.path.join(work, name)):
        dirs.sort()
        for entry in sorted(dirs) + sorted(files):
            path = os.path.join(root, entry)
            arc = os.path.relpath(path, work)
            info = zipfile.ZipInfo(arc + ("/" if os.path.isdir(path) else ""), (2020, 1, 1, 0, 0, 0))
            info.external_attr = (os.stat(path).st_mode & 0xFFFF) << 16
            if os.path.isdir(path):
                z.writestr(info, "")
            else:
                info.compress_type = zipfile.ZIP_DEFLATED
                with open(path, "rb") as f:
                    z.writestr(info, f.read())
PY
fi
(cd "$OUT" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")

echo "==> done"
ls -lh "$ZIP" "$ZIP.sha256"
cat "$ZIP.sha256"
