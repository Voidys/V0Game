/**
 * Command palette: a <dialog> built here (no markup needed). Opened by Ctrl/Cmd+K, "/" and [data-action="palette"]
 * (see shortcuts.js). Entries: pages from the side navigation, planets ([data-planet-goto]) and galaxy coordinates
 * typed as "1:42" or "1:42:8" (opens /game/galaxy/{galaxy}/{system}).
 * UI strings come from the `ui` object of the game-env island (palette_placeholder, palette_empty, palette_pages,
 * palette_planets, palette_go, palette_galaxy); English fallbacks otherwise.
 */
import { pageData } from './core.js';

const FALLBACK = {
  palette_placeholder: 'Go to a page, planet or coordinates (1:42)',
  palette_empty: 'No matches',
  palette_pages: 'Pages',
  palette_planets: 'Planets',
  palette_go: 'Go to',
  palette_galaxy: 'Galaxy',
};

/** UI string `key` of the page language, English fallback */
export const uiText = (key) => pageData('game-env').ui?.[key] ?? FALLBACK[key] ?? key;

const SVG = 'http://www.w3.org/2000/svg';
const NAV_LINKS = '.sidenav a[href], .sidebar nav a[href], aside nav a[href], .nav a[href]';
let dialog = null;
let input = null;
let list = null;
let shown = [];
let selected = 0;

/** hrefs that are real pages (no anchors, scripts, popups) */
const isPage = (a) => {
  const href = a.getAttribute('href') ?? '';
  return href !== '' && !href.startsWith('#') && !href.startsWith('javascript:') && !a.hasAttribute('data-action') && !a.hasAttribute('data-dialog') && !a.hasAttribute('data-method') && a.target !== '_blank';
};

/** sidebar link of a page, matched by the last path segment of its href (overview, buildings, galaxy, ...) */
export function navLinkFor(segment) {
  return [...document.querySelectorAll(NAV_LINKS)].find((a) => {
    try {
      return new URL(a.getAttribute('href'), document.baseURI).pathname.replace(/\/+$/, '').split('/').pop() === segment;
    } catch {
      return false;
    }
  }) ?? null;
}

/** Galaxy URL for a system, built from the sidebar's galaxy link; null when the page has none. */
function galaxyUrl(galaxy, system) {
  const link = navLinkFor('galaxy');
  if (!link) return null;
  const url = new URL(link.getAttribute('href'), document.baseURI);
  url.pathname = `${url.pathname.replace(/\/+$/, '')}/${galaxy}/${system}`;
  return url.href;
}

function iconFor(link) {
  const href = link.querySelector('svg use')?.getAttribute('href');
  if (!href) return null;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('class', 'icon');
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS(SVG, 'use');
  use.setAttribute('href', href);
  svg.append(use);
  return svg;
}

const clean = (text) => text.replace(/\s+/g, ' ').replace(/\s*!$/, '').trim();

/** All static entries of this page: {group, label, hint, icon, run} */
function collect() {
  const items = [];
  const seen = new Set();
  document.querySelectorAll(NAV_LINKS).forEach((a) => {
    if (!isPage(a)) return;
    const label = clean(a.querySelector('.nav__label')?.textContent || a.getAttribute('aria-label') || a.title || a.dataset.tip || a.textContent);
    if (!label || seen.has(a.href)) return;
    seen.add(a.href);
    items.push({ group: uiText('palette_pages'), label, icon: iconFor(a), run: () => { location.href = a.href; } });
  });
  const planets = new Set();
  document.querySelectorAll('[data-planet-goto]').forEach((el) => {
    const id = el.dataset.planetGoto;
    if (planets.has(id)) return;
    planets.add(id);
    let label = clean(el.dataset.name || el.textContent);
    if (!label) return;
    let hint = '';
    const match = /^(.*?)\s*(\[\d+:\d+:\d+\])$/.exec(label);
    if (match) [, label, hint] = match;
    items.push({ group: uiText('palette_planets'), label, hint, icon: null, run: () => el.click() });
  });
  return items;
}

/** Items for the typed query: coordinates first, then fuzzy-ish substring matches (all words, in any order). */
function filter(query) {
  const q = query.trim().toLowerCase();
  const items = [];
  const coords = /^(\d{1,2})\s*:\s*(\d{1,3})(?:\s*:\s*(\d{1,2}))?$/.exec(q);
  if (coords) {
    const url = galaxyUrl(coords[1], coords[2]);
    if (url) {
      const text = `${coords[1]}:${coords[2]}${coords[3] ? `:${coords[3]}` : ''}`;
      items.push({ group: uiText('palette_go'), label: `${uiText('palette_galaxy')} ${text}`, hint: text, icon: null, run: () => { location.href = url; } });
    }
  }
  const words = q.split(/\s+/).filter(Boolean);
  const rest = collect().filter((item) => {
    const hay = `${item.label} ${item.hint ?? ''}`.toLowerCase();
    return words.every((word) => hay.includes(word));
  });
  return items.concat(rest);
}

function build() {
  dialog = document.createElement('dialog');
  dialog.className = 'overlay-dialog palette';
  dialog.setAttribute('aria-label', 'Command palette');
  const head = document.createElement('div');
  head.className = 'palette-input';
  const lens = document.createElementNS(SVG, 'svg');
  lens.setAttribute('class', 'icon');
  lens.setAttribute('aria-hidden', 'true');
  const spriteHref = document.querySelector('svg.icon use')?.getAttribute('href');
  if (spriteHref) {
    const use = document.createElementNS(SVG, 'use');
    use.setAttribute('href', spriteHref.replace(/#.*$/, '#i-search'));
    lens.append(use);
    head.append(lens);
  }
  input = document.createElement('input');
  input.type = 'text';
  input.autocomplete = 'off';
  input.spellcheck = false;
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-expanded', 'true');
  input.setAttribute('aria-controls', 'palette-list');
  input.placeholder = uiText('palette_placeholder');
  input.setAttribute('aria-label', input.placeholder);
  const esc = document.createElement('kbd');
  esc.textContent = 'Esc';
  head.append(input, esc);
  list = document.createElement('ul');
  list.className = 'palette-list';
  list.id = 'palette-list';
  list.setAttribute('role', 'listbox');
  dialog.append(head, list);
  document.body.append(dialog);

  input.addEventListener('input', () => { selected = 0; render(); });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (shown.length) select((selected + (event.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      run(selected);
    }
  });
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  list.addEventListener('click', (event) => {
    const row = event.target instanceof Element ? event.target.closest('li[data-index]') : null;
    if (row) run(Number(row.dataset.index));
  });
  list.addEventListener('pointermove', (event) => {
    const row = event.target instanceof Element ? event.target.closest('li[data-index]') : null;
    if (row && Number(row.dataset.index) !== selected) select(Number(row.dataset.index), false);
  });
}

function select(index, scroll = true) {
  selected = index;
  list.querySelectorAll('li[data-index]').forEach((row) => {
    const on = Number(row.dataset.index) === index;
    row.setAttribute('aria-selected', String(on));
    if (on) {
      input.setAttribute('aria-activedescendant', row.id);
      if (scroll) row.scrollIntoView({ block: 'nearest' });
    }
  });
}

function render() {
  shown = filter(input.value);
  list.replaceChildren();
  if (shown.length === 0) {
    const none = document.createElement('li');
    none.className = 'palette-empty';
    none.setAttribute('role', 'presentation');
    none.textContent = uiText('palette_empty');
    list.append(none);
    return;
  }
  let group = null;
  shown.forEach((item, index) => {
    if (item.group !== group) {
      group = item.group;
      const heading = document.createElement('li');
      heading.className = 'group';
      heading.setAttribute('role', 'presentation');
      heading.textContent = group;
      list.append(heading);
    }
    const row = document.createElement('li');
    row.id = `palette-item-${index}`;
    row.dataset.index = String(index);
    row.setAttribute('role', 'option');
    if (item.icon) row.append(item.icon.cloneNode(true));
    const label = document.createElement('span');
    label.textContent = item.label;
    row.append(label);
    if (item.hint) {
      const hint = document.createElement('small');
      hint.textContent = item.hint;
      row.append(hint);
    }
    list.append(row);
  });
  select(Math.min(selected, shown.length - 1), false);
}

function run(index) {
  const item = shown[index];
  if (!item) return;
  dialog.close();
  item.run();
}

export const paletteIsOpen = () => dialog?.open === true;

/** Opens (or closes, when open) the palette. */
export function togglePalette() {
  if (!dialog) build();
  if (dialog.open) {
    dialog.close();
    return;
  }
  input.value = '';
  selected = 0;
  render();
  dialog.showModal();
  input.focus();
}

/** [data-action="palette"] buttons open it (delegated, so late markup works too). */
export function initPalette() {
  document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-action="palette"]')) {
      event.preventDefault();
      togglePalette();
    }
  });
}
