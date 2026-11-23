#!/usr/bin/env bash
# Writes an animated WebP next to every GIF that has none (foo.gif -> foo.webp; frame timing, size and loop kept).
# app:images:webp skips animated images, so this covers them. Needs ffmpeg with libwebp_anim.
# The templates serve the WebP through <picture> and keep the GIF as fallback (theme_picture_sources in TemplateExtension).
# usage: tools/images/animated-gif-to-webp.sh [dir ...]   (default: the shared theme artwork)
set -euo pipefail
cd "$(dirname "$0")/../.."
QUALITY="${QUALITY:-40}"
dirs=("$@")
[ ${#dirs[@]} -gt 0 ] || dirs=(public/styles/resource/theme-shared public/styles/theme)
command -v ffmpeg >/dev/null || { echo "ffmpeg (with libwebp_anim) is required" >&2; exit 1; }
before=0; after=0; n=0
while IFS= read -r -d '' gif; do
  webp="${gif%.gif}.webp"
  [ -e "$webp" ] && continue
  ffmpeg -nostdin -v error -y -i "$gif" -c:v libwebp_anim -q:v "$QUALITY" -compression_level 6 -fps_mode passthrough -loop 0 "$webp"
  before=$((before + $(stat -c %s "$gif"))); after=$((after + $(stat -c %s "$webp"))); n=$((n + 1))
done < <(find "${dirs[@]}" -name '*.gif' -print0)
echo "$n animated WebP written: $((before / 1024)) KB GIF -> $((after / 1024)) KB WebP"
