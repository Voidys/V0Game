# Themes

A theme gives the game its colours, fonts and images. **It does not change the layout**: spacing, sizes and
component shapes come from the shared CSS (`public/styles/resource/css/app/`, rules in `docs/ui-layout.md`), so every
theme gets the same strict layout and a new theme is a short file of values.

## Quick start: a new theme in five minutes

1. Copy the reference theme: `cp -r public/styles/theme/v0game public/styles/theme/<name>` (lowercase folder name,
   letters/digits/dashes). Delete the artwork folders you don't want to override (`planeten/`, `gebaeude/`, …):
   missing images fall back to the declared artwork sets (see "Images" below).
2. Edit `style.cfg`: `name` (shown in the settings), `tag` (= folder name), `author`, `assetSets`.
3. Edit `theme.css`: change the values in `@layer theme { :root { … } }`. Keep every variable; the list is the
   contract below.
4. Replace `preview.png` (shown in the theme picker; a 16:10 screenshot of the overview, about 640×400).
5. Clear the caches (`ddev exec rm -rf var/game/*`), pick the theme under Settings → Appearance and check the pages
   listed under "Check list".

## Folder layout

```
public/styles/theme/<name>/
  style.cfg        name, tag, author, flags, artwork sets (PHP array, read by App\Game\Core\Theme)
  settings.cfg     legacy row counts for a few pages (keep the copied values)
  theme.css        the tokens (+ optional fonts and decoration)
  preview.png      theme picker image
  img/             images theme.css refers to (background, textures); relative url("img/…")
  fonts/           optional font files for @font-face
  planeten/ gebaeude/ images/ …   optional own artwork, overrides the sets (see "Images")
```

`style.cfg` keys: `name`, `tag`, `author`, `cssTheme` (true = offered as a colour theme), `imageTheme` (true =
offered as an image theme; players can combine the colours of one theme with the artwork of another),
`assetSets` (ordered list of shared artwork sets), `disabled` (set it to hide the theme without deleting it).

## theme.css

Three parts, in this order (see `public/styles/theme/v0game/theme.css`):

```css
@font-face { font-family: "MyFont"; src: url("fonts/myfont.woff2") format("woff2"); font-display: swap; }  /* optional */

@layer theme {
  :root {
    --theme-bg: #06080b;
    /* … every contract variable … */
  }

  /* optional page background image */
  body { background-image: url("img/background.webp"); }
}

@layer theme-extras {      /* optional, keep it short */
  .topbar { background-image: linear-gradient(…); }
}
```

- **`@layer theme`** holds the contract variables and, optionally, one `body { background-image: url(…) }` rule
  for a page background. Nothing else. (The image is a rule, not a variable: a relative `url()` inside a custom
  property would be resolved against the shared stylesheet that uses it, not against the theme folder.)
- **`@layer theme-extras`** is for decoration tokens can't express (a texture, a glow on the wordmark, a gradient in
  the top bar). It sits above the components, so it can recolour them, but it must not change layout: no `width`,
  `height`, `margin`, `padding`, `display`, `grid-*`, `position`, `font-size`. Aim for under 30 lines.
- Images and fonts use paths relative to the theme folder.

## The contract

Defaults (= V0Game) live in `public/styles/resource/css/app/theme-contract.css`, which also maps them onto every
token the components use. Set all of them in your theme:

| Variable | What it colours | Rule of thumb |
|---|---|---|
| `--theme-bg` | page ground | darkest colour |
| `--theme-bg-2` | top bar, table headers, inputs, footer | a step above `bg` |
| `--theme-bg-size` | size of the page background image | `cover` |
| `--theme-panel` | panels, cards, menus | may be translucent over a background image |
| `--theme-raised` | hover rows, buttons, raised surfaces | a step above `panel` |
| `--theme-hover` | hover on raised elements | a step above `raised` |
| `--theme-line` | hairlines between rows and around panels | subtle |
| `--theme-line-strong` | control and button borders, dividers that must be seen | |
| `--theme-field-line` | input borders | ≥ 3:1 against `panel` |
| `--theme-text` | body text | ≥ 7:1 against `panel` |
| `--theme-muted` | labels, secondary text | ≥ 4.5:1 against `panel` |
| `--theme-accent` | **state only**: current menu item, badges, focus ring, primary buttons, progress | one colour |
| `--theme-accent-ink` | text on accent backgrounds | ≥ 4.5:1 against `accent` |
| `--theme-link` | links in text | |
| `--theme-pos` / `--theme-neg` / `--theme-warn` / `--theme-info` | gains, losses/danger, warnings, information | players may override pos/neg/neutral in their settings |
| `--theme-font-ui` | all text | a full font stack |
| `--theme-font-display` | page titles | often `var(--theme-font-ui)` |
| `--theme-font-mono` | numbers, coordinates, timers | must have tabular figures |
| `--theme-radius` | panels, controls, badges | `0`–`4px`; planets, moons, avatars stay round |
| `--theme-topbar-bg` | top bar | `bg-2`, slightly translucent (the bar blurs what's behind) |
| `--theme-sidebar-bg` | side menu | `transparent` or a surface colour |

Derived tokens (`--tint`, `--c-*`, `--btn-*`, `--progress-*`, …) are computed from these; don't set them in a
theme.

## Check list

- Overview, buildings (cards and queue), galaxy, fleet step 1, messages, alliance, statistics, settings: readable,
  the accent appears only on state (current item, badges, focus, primary buttons, progress).
- Contrast values from the table above (any WCAG contrast checker).
- Desktop (1600 px), tablet (1100 px), phone (390 px).
- Keyboard focus is visible (accent ring).
- No 404s for your images or fonts in the browser console.

## Images: shared artwork and lookup

Every theme lives in `public/styles/theme/<name>/` (`style.cfg`, `settings.cfg`, `theme.css` and the image folders
`planeten/`, `gebaeude/`, `achievements/`, `img/`, `images/`, `pic/`). Most artwork is the same in several themes, so
identical files are stored once in `public/styles/resource/theme-shared/`:

```
theme-shared/common/          files every theme (or at least five) shares
theme-shared/sets/<set>/      artwork of a group of themes, e.g. sets/nebula (DarkNebula, MinimalNoir, community, ...)
                              and sets/classic (nova)
```

A theme declares the sets it can draw from in its `style.cfg`: `'assetSets' => ['nebula']` (an ordered list).

## Lookup order

Templates, PHP and JS never build theme image paths themselves; they ask for a relative path:

- Twig: `{{ theme_asset('gebaeude/' ~ id ~ '.gif') }}`
- PHP: `$THEME->asset('planeten/' . $image . '.jpg')` (or `App\Game\Core\ThemeAssets::resolve($theme, $path)`)
- JS: gets resolved URLs from the template (see `messages` and `fleetDealer`).

`App\Game\Core\ThemeAssets` returns the first hit of:

1. the theme's own folder
2. each declared set, in order
3. `common/`
4. nothing found: the theme path (a missing image shows up as a 404 like before)

Each folder is tried with the exact path first, then with the extension replaced by `.webp` (images are shipped as
WebP, so `gebaeude/229.gif` is answered by `gebaeude/229.webp`). Own files always win: a theme overrides any image by
placing a file at the same relative path in its folder; a new theme can reuse artwork by declaring a set.

The lookup map of a theme is cached in `var/game/theme-assets-<theme>.php` and rebuilt when the modification time
of one of the folders it was built from changes (file added or removed) or `style.cfg` changes. Replacing a file in
place keeps its URL; the long cache lifetime of images then needs a new file name.

`theme.css` is loaded from the css theme folder, so images it refers to with a relative `url()` have to stay in that
theme folder (or the url must point to `../../resource/theme-shared/...`). Files hard-coded as
`styles/theme/<name>/...` in the code stay too. The dedupe script respects both.

## Tools

- `tools/images/convert-theme-images.sh`: JPG/PNG/GIF to WebP (q90, animated GIFs stay animated, size limits per
  category), deletes the originals, rewrites `theme.css` references. Needs the libwebp tools (see the script header).
- `tools/images/dedupe-theme-assets.php [--apply]`: moves files that are identical across themes into
  `theme-shared/`, deletes the theme copies and verifies that every theme x path still resolves to the same bytes.
  Run both after adding or changing a theme.
