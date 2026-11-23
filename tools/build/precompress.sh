#!/usr/bin/env bash
# Writes foo.css.br / foo.css.gz (and .js, .mjs, .svg, .json) next to the static text assets in public/, for
# nginx `brotli_static on;` / `gzip_static on;` (README.md, "nginx"). Existing up-to-date files are kept;
# re-run after every deploy. Skips brotli when the `brotli` tool is missing, gzip when `gzip` is.
# usage: tools/build/precompress.sh [dir ...]   (default: public/styles public/scripts)
set -euo pipefail
cd "$(dirname "$0")/../.."
dirs=("$@")
[ ${#dirs[@]} -gt 0 ] || dirs=(public/styles public/scripts)
have_br=0; have_gz=0
command -v brotli >/dev/null && have_br=1 || echo "brotli not found: .br files are skipped (apt install brotli)" >&2
command -v gzip >/dev/null && have_gz=1 || echo "gzip not found: .gz files are skipped" >&2
n=0
while IFS= read -r -d '' file; do
  if [ "$have_br" = 1 ] && [ ! "$file.br" -nt "$file" ]; then brotli -q 11 -f -k -o "$file.br" "$file"; n=$((n + 1)); fi
  if [ "$have_gz" = 1 ] && [ ! "$file.gz" -nt "$file" ]; then gzip -9 -n -f -k -c "$file" > "$file.gz"; n=$((n + 1)); fi
done < <(find "${dirs[@]}" -type f \( -name '*.css' -o -name '*.js' -o -name '*.mjs' -o -name '*.svg' -o -name '*.json' \) -size +512c -print0)
echo "$n precompressed files written"
