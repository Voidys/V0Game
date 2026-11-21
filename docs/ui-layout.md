# Game layout rules

Every game page is built from the same blocks, with the same spacing. Pages never set their own outer margins
or paddings; spacing comes only from the scale below. CSS: `public/styles/resource/css/app/ui.css` (blocks),
`tables-forms.css` (tables and forms), `components.css`, `game.css` (shell); colours come from the theme
(`docs/themes.md`).

## Scale

| Token | px | Use |
|---|---|---|
| `--space-1` | 4 | icon gaps, chip padding |
| `--space-2` | 8 | gaps inside controls and toolbars |
| `--space-3` | 12 | gaps between controls, form rows |
| `--space-4` = `--gap` = `--pad` | 16 | between blocks, panel padding, table cell padding |
| `--space-5` | 24 | between stat tiles |
| `--space-6` | 32 | empty states |
| `--space-7` / `--space-8` | 48 / 64 | rarely |

Type: 12 (labels, table headers) · 13 (meta, small text) · 14 (body) · 16 (stat values) · 20 · 24 (page title).
Numbers, coordinates and timers use the mono font with tabular figures. Radius: `var(--r)` everywhere (theme
`--theme-radius`, 0–4px); only planets, moons, avatars and status dots are round.

## Page anatomy

```twig
{% import '_macros/ui.html.twig' as ui %}
{% block pagehead %}{{ ui.page_head(LNG.lm_galaxy, 'orbit', meta, actions) }}{% endblock %}
{% block content %}
  <section class="panel">
    {{ ui.panel_head(LNG.something, count) }}
    <table class="data"> … </table>
  </section>
  <div class="grid-2">
    <section class="panel"> … </section>
    <section class="panel"><div class="panel__body"> … </div></section>
  </div>
{% endblock %}
```

- `.content` (main) is a vertical stack with `--gap` between its children.
- **Page head** first: title (24px, optional icon), meta line (13px, muted), actions on the right.
- **Panels** hold everything else. A panel has no padding: `.panel__head` (title bar), `.panel__body` (padded,
  for text and forms), `.panel__foot` (right-aligned actions). Tables, lists and grids sit flush in the panel.
- Wide tables: `.panel--flush` (scrolls sideways) or `<div class="table-scroll">`.

## Blocks

| Block | Markup | Notes |
|---|---|---|
| Stack | `.stack`, `.stack--tight` | vertical, gap 16 / 8 |
| Columns | `.grid-2` (3fr/2fr), `.grid-3`, `.grid-auto` (≥ 16rem) | collapse at 75rem / 40rem |
| Stats | `.stats > .stat > .stat__label + .stat__value` | label 12px upper muted, value 16px mono |
| Toolbar | `.toolbar` (+ `.toolbar__spacer`) | controls above a table: filters, paging |
| Filters / tabs | `.segmented > a/button` | active: `aria-current`, `aria-checked="true"`, `aria-selected="true"` or `.is-active` |
| Badge / tag | `.badge`, `.badge--accent`, `.tag.tag--pos/neg/warn/info/muted` | 12px mono, 1px border |
| Level chip | `<span class="lvl">24</span>` | after a name |
| Meter | `<div class="meter" style="--fill: .75">` (+ `--warn`, `--thin`) | 4px / 2px |
| Key/value | `<dl class="kv">` | facts lists |
| Empty state | `<p class="empty">` | inside a panel |

## Tables

`table.data` (and, for older markup, any table in the content area): full width, 40px rows, cell padding 0 16px,
header 12px uppercase muted on `bg-2`, hairlines between rows, hover row. Cell roles: `.num` (numbers: mono,
right), `.ctr` (icons, short values), `.act` (row buttons, as narrow as their content), `.top` (cells with blocks of
different heights).

## Forms

`table.form-table` (label | control rows) or `.form-grid`: labels 14rem, controls 34px high, rows 12px apart;
`.form-actions` closes a form (right aligned, 8px gaps, top hairline). Amount fields `input.num`, coordinates
`input.coord`. Buttons: 34px (`.btn-sm` 28px); primary = accent, destructive = negative.

## Phones (≤ 60rem) and touch

Thumb first: the main destinations live in a bottom tab bar (`templates/game/main.tabbar.html.twig`: overview,
buildings, galaxy, fleet, menu); the menu, the planet switcher and the account menu open as bottom sheets above
it. Everything fixed at the bottom clears the bar (`--tabbar-h` + `--safe-b`, the safe-area inset). A page's main
action can carry `.page-fab` and then floats bottom right above the bar. On touch screens (`pointer: coarse`)
controls are 44px, inputs use 16px text (no iOS focus zoom), and small links in rows, cards and toolbars get a
taller hit area that does not move the layout. Never put an essential action only at the top of a phone page,
never rely on hover, and never leave a transform on an ancestor of fixed elements (animations use
`animation-fill-mode: backwards`).

## Motion

140–240 ms (`--t-fast`, `--t-med`), ease-out for entrances; state changes only, no decoration loops.
`prefers-reduced-motion` turns animations off.
