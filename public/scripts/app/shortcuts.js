/**
 * Global keyboard shortcuts and the shortcut sheet (a <dialog> built here, opened by "?" and
 * [data-action="shortcuts"]): Ctrl/Cmd+K and "/" open the command palette, "g" then "o" / "e" / "b" / "r" / "s" / "g" / "f" /
 * "m" follows the overview / empire / buildings / research / shipyard / galaxy / fleet / messages link of the side navigation. "[" and "]" (previous/next planet) live in ui.js.
 * Descriptions come from the `ui` object of the game-env island (shortcuts_title, shortcuts_palette, shortcuts_go_overview,
 * shortcuts_go_empire, shortcuts_go_buildings, shortcuts_go_research, shortcuts_go_shipyard, shortcuts_go_galaxy, shortcuts_go_fleet,
 * shortcuts_go_messages, shortcuts_planets, shortcuts_help), English fallbacks otherwise.
 */
import { pageData } from './core.js';
import { togglePalette, paletteIsOpen, navLinkFor } from './palette.js';

const FALLBACK = {
  shortcuts_title: 'Keyboard shortcuts',
  shortcuts_palette: 'Command palette',
  shortcuts_go_overview: 'Go to overview',
  shortcuts_go_empire: 'Go to empire',
  shortcuts_go_buildings: 'Go to buildings',
  shortcuts_go_research: 'Go to research',
  shortcuts_go_shipyard: 'Go to shipyard',
  shortcuts_go_galaxy: 'Go to galaxy',
  shortcuts_go_fleet: 'Go to fleet',
  shortcuts_go_messages: 'Go to messages',
  shortcuts_planets: 'Previous / next planet',
  shortcuts_help: 'This sheet',
};
const text = (key) => pageData('game-env').ui?.[key] ?? FALLBACK[key];

// last path segment of the nav link; the overview lives at /game
const GO = { o: 'game', e: 'empire', b: 'buildings', r: 'research', s: 'shipyard', g: 'galaxy', f: 'fleet', m: 'messages' };
const ROWS = [
  ['shortcuts_palette', [['Ctrl', 'K'], ['/']]],
  ['shortcuts_go_overview', [['G', 'O']]],
  ['shortcuts_go_empire', [['G', 'E']]],
  ['shortcuts_go_buildings', [['G', 'B']]],
  ['shortcuts_go_research', [['G', 'R']]],
  ['shortcuts_go_shipyard', [['G', 'S']]],
  ['shortcuts_go_galaxy', [['G', 'G']]],
  ['shortcuts_go_fleet', [['G', 'F']]],
  ['shortcuts_go_messages', [['G', 'M']]],
  ['shortcuts_planets', [['['], [']']]],
  ['shortcuts_help', [['?']]],
];
let sheet = null;

/** true while the user types into a field (shortcuts without modifier must stay quiet) */
function typing(target) {
  return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

function buildSheet() {
  sheet = document.createElement('dialog');
  sheet.className = 'overlay-dialog sheet';
  const title = document.createElement('h2');
  title.className = 'sheet-title';
  title.textContent = text('shortcuts_title');
  title.id = 'shortcuts-title';
  sheet.setAttribute('aria-labelledby', title.id);
  const rows = document.createElement('dl');
  rows.className = 'shortcuts';
  for (const [key, combos] of ROWS) {
    const row = document.createElement('div');
    const dt = document.createElement('dt');
    dt.textContent = text(key);
    const dd = document.createElement('dd');
    combos.forEach((combo, i) => {
      if (i > 0) dd.append(' / ');
      const keys = document.createElement('span');
      keys.className = 'bubble-kbd';
      combo.forEach((name) => {
        const chip = document.createElement('kbd');
        chip.textContent = name;
        keys.append(chip);
      });
      dd.append(keys);
    });
    row.append(dt, dd);
    rows.append(row);
  }
  sheet.append(title, rows);
  sheet.addEventListener('click', (event) => { if (event.target === sheet) sheet.close(); });
  document.body.append(sheet);
}

/** Opens (or closes, when open) the shortcut sheet. */
export function toggleShortcuts() {
  if (!sheet) buildSheet();
  if (sheet.open) sheet.close(); else sheet.showModal();
}

/** Registers the global key handling and the [data-action="shortcuts"] buttons. */
export function initShortcuts() {
  let goUntil = 0;
  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || event.isComposing) return;
    const key = event.key.toLowerCase();
    if ((event.ctrlKey || event.metaKey) && !event.altKey && key === 'k') {
      event.preventDefault();
      togglePalette();
      return;
    }
    if (event.ctrlKey || event.metaKey || event.altKey || typing(event.target)) return;
    // other modals (game dialogs, the palette itself) keep their keys
    if (document.querySelector('dialog[open]')) {
      if (event.key === '?' && sheet?.open) { event.preventDefault(); sheet.close(); }
      return;
    }
    if (event.key === '/') {
      event.preventDefault();
      if (!paletteIsOpen()) togglePalette();
    } else if (event.key === '?') {
      event.preventDefault();
      toggleShortcuts();
    } else if (key === 'g' && !event.shiftKey) {
      goUntil = performance.now() + 1200;
    } else if (GO[key] && performance.now() < goUntil) {
      goUntil = 0;
      const link = navLinkFor(GO[key]);
      if (link) {
        event.preventDefault();
        link.click();
      }
    } else {
      goUntil = 0;
    }
  });
  document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-action="shortcuts"]')) {
      event.preventDefault();
      toggleShortcuts();
    }
  });
}
