/**
 * Small UI widgets and the page-wide behaviours (formerly base.js, jQuery UI and tablesorter):
 * tabs, sortable tables, autocomplete, planet selector, warning banners, list filters,
 * missile destruction, mobile sidebar and the automated-click report.
 */
import { qs, qsa, on, show, hide, toggle, fadeOut, getText, getJSON, post, serialize, pretty_number, pageData } from './core.js';

// ---------------------------------------------------------------------------------------- tabs

/**
 * Tabs: a container with a list of anchor links (href="#panel-id") and the panels.
 * Markup of jQuery UI tabs works as is; auto-initialised for [data-tabs].
 */
export function tabs(container, { active = 0, onChange } = {}) {
  if (!container || container.dataset.tabsReady) return;
  container.dataset.tabsReady = '1';
  container.classList.add('tabs');
  const links = qsa(':scope > ul a[href^="#"], :scope > nav a[href^="#"]', container);
  const panels = links.map((link) => document.getElementById(link.hash.slice(1)));
  const select = (index) => {
    links.forEach((link, i) => {
      const selected = i === index;
      link.setAttribute('aria-selected', String(selected));
      link.parentElement.classList.toggle('is-active', selected);
      if (panels[i]) panels[i].hidden = !selected;
    });
    onChange?.(index, panels[index]);
  };
  links.forEach((link, index) => {
    link.setAttribute('role', 'tab');
    link.addEventListener('click', (event) => { event.preventDefault(); select(index); });
  });
  select(Math.min(active, links.length - 1));
}

// ---------------------------------------------------------------------------------------- sortable tables

/** Sort key of a cell: `data-sort` or its text; numbers with thousands separators ("1.234", "1,234,5") become numbers, everything else lower-case text. */
function cellValue(row, index) {
  const cell = row.cells[index];
  if (!cell) return '';
  const raw = (cell.dataset.sort ?? cell.textContent).trim();
  const numeric = raw.replace(/[.,\s](?=\d{3}\b)/g, '').replace(',', '.');
  return /^-?\d+(\.\d+)?$/.test(numeric) ? parseFloat(numeric) : raw.toLowerCase();
}

/** click a header cell to sort the table body by that column (formerly tablesorter) */
function sortableTable(table) {
  if (!table || table.dataset.sortableReady) return;
  table.dataset.sortableReady = '1';
  // last header row: tables may carry a title row above the column headings
  const head = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1] : table.rows[0];
  if (!head) return;
  Array.from(head.cells).forEach((th, index) => {
    if (th.classList.contains('no-sort')) return;
    th.classList.add('sortable');
    th.tabIndex = 0;
    const sort = () => {
      const body = table.tBodies[0];
      const rows = Array.from(body.rows).filter((row) => row !== head);
      const ascending = th.getAttribute('aria-sort') !== 'ascending';
      Array.from(head.cells).forEach((cell) => cell.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
      rows.sort((a, b) => {
        const x = cellValue(a, index);
        const y = cellValue(b, index);
        const result = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
        return ascending ? result : -result;
      });
      body.append(...rows);
    };
    th.addEventListener('click', sort);
    th.addEventListener('keydown', (event) => { if (event.key === 'Enter') sort(); });
  });
}

// ---------------------------------------------------------------------------------------- autocomplete

/** Fills `element` with `text`, the first (case-insensitive) occurrence of `term` in a <b> element; DOM nodes only, no HTML parsing. */
function highlight(element, text, term) {
  const at = term === '' ? -1 : text.toLowerCase().indexOf(term.toLowerCase());
  if (at < 0) {
    element.textContent = text;
    return;
  }
  const bold = document.createElement('b');
  bold.textContent = text.slice(at, at + term.length);
  element.append(text.slice(0, at), bold, text.slice(at + term.length));
}

/**
 * Suggestion list under an input: source(term) resolves to [{label, value, category?}];
 * onSelect(item) runs when one is chosen. Grouped by category like the former catcomplete widget.
 */
export function autocomplete(input, { source, onSelect, minLength = 2, delay = 250 }) {
  if (!input) return;
  const list = document.createElement('ul');
  list.className = 'autocomplete';
  list.hidden = true;
  input.after(list);
  input.setAttribute('autocomplete', 'off');
  let timer;
  let items = [];
  let active = -1;

  const render = () => {
    list.innerHTML = '';
    let category = null;
    items.forEach((item, index) => {
      if (item.category && item.category !== category) {
        category = item.category;
        const heading = document.createElement('li');
        heading.className = 'autocomplete__category';
        heading.textContent = category;
        list.append(heading);
      }
      const li = document.createElement('li');
      li.className = 'autocomplete__item' + (index === active ? ' is-active' : '');
      highlight(li, String(item.label ?? item.value), input.value.trim());
      li.addEventListener('mousedown', (event) => { event.preventDefault(); choose(index); });
      list.append(li);
    });
    list.hidden = items.length === 0;
  };
  const choose = (index) => {
    const item = items[index];
    if (!item) return;
    input.value = item.value ?? item.label;
    items = [];
    render();
    onSelect?.(item);
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    const term = input.value.trim();
    if (term.length < minLength) { items = []; render(); return; }
    timer = window.setTimeout(async () => {
      try { items = await source(term); } catch { items = []; }
      active = -1;
      render();
    }, delay);
  });
  input.addEventListener('keydown', (event) => {
    if (list.hidden) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      render();
    } else if (event.key === 'Enter' && active >= 0) {
      event.preventDefault();
      choose(active);
    } else if (event.key === 'Escape') {
      items = [];
      render();
    }
  });
  input.addEventListener('blur', () => window.setTimeout(() => { items = []; render(); }, 150));
}

// ---------------------------------------------------------------------------------------- page behaviours

/** "drop admin access" / "drop rebuild protection" links in the warning banners */
function warningBanners() {
  const drop = (url) => post(url, {}).then(() => qsa('.globalWarning').forEach((el) => fadeOut(el)));
  on(document, 'click', '#drop-admin', (event) => { event.preventDefault(); drop('admin/logout'); });
  on(document, 'click', '#drop-rebuild-protection', (event, link) => { event.preventDefault(); drop(link.dataset.url); });

  // "x new messages" notice after login: close button (it is shown once per login anyway)
  on(document, 'click', '[data-login-notice-close]', (event, button) => {
    const notice = button.closest('[data-login-notice]');
    notice?.classList.add('is-leaving');
    window.setTimeout(() => notice?.remove(), 200);
  });
}

/** Reloads the current page (path and query string kept) with `cp=<planet id>`. */
function gotoPlanet(id) {
  const url = new URL(window.location.href);
  url.searchParams.set('cp', id);
  document.location = url.href;
}

/**
 * Planet/moon dropdown: switching reloads the current page. The arrow buttons (`[data-planet-step="-1|1"]`) and the
 * keys `[` / `]` step to the previous/next entry of the dropdown (wrapping around, ignored while typing).
 */
function planetSelector() {
  on(document, 'change', '#planetSelector, [data-planet-selector]', (event, select) => gotoPlanet(select.value));

  // phones: the closed select shows the planet name only (the coordinates push it into an ellipsis); the drawer list has them
  const phone = window.matchMedia('(max-width: 40rem)');
  const shortLabels = () => qsa('#planetSelector option').forEach((option) => {
    option.label = phone.matches ? option.text.replace(/\s*\[\d+:\d+:\d+\]$/, '') : option.text;
  });
  shortLabels();
  phone.addEventListener('change', shortLabels);

  const step = (delta) => {
    const options = qsa('#planetSelector option');
    const index = options.findIndex((option) => option.selected);
    if (options.length < 2 || index < 0) return;
    gotoPlanet(options[(index + delta + options.length) % options.length].value);
  };
  on(document, 'click', '[data-planet-goto]', (event, button) => gotoPlanet(button.dataset.planetGoto));
  on(document, 'click', '[data-planet-step]', (event, button) => step(Number(button.dataset.planetStep)));
  document.addEventListener('keydown', (event) => {
    if (event.ctrlKey || event.metaKey || event.altKey || (event.key !== '[' && event.key !== ']')) return;
    const target = event.target;
    if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
    step(event.key === ']' ? 1 : -1);
  });
}

/**
 * List filters of the former header script: #gl1..#gl3 switch planet/moon rows (.planetb / .planetb1),
 * #<n>h / #<n>s hide/show the rows #h<n+1>..#h<n+99> of a section (buildings, fleet, defense, ...).
 */
function listFilters() {
  const planets = () => qsa('.planetb');
  const moons = () => qsa('.planetb1');
  const select = (button) => {
    qsa('#gl1, #gl2, #gl3').forEach((el) => el.classList.toggle('selected', el === button));
  };
  on(document, 'click', '#gl1', (event, button) => { planets().forEach(hide); moons().forEach((el) => show(el)); select(button); });
  on(document, 'click', '#gl2', (event, button) => { moons().forEach((el) => toggle(el)); planets().forEach((el) => show(el)); select(button); });
  on(document, 'click', '#gl3', (event, button) => { planets().forEach((el) => show(el)); moons().forEach((el) => show(el)); select(button); });

  on(document, 'click', '[id$="h"], [id$="s"]', (event, button) => {
    const match = /^(\d+)([hs])$/.exec(button.id);
    if (!match) return;
    const base = parseInt(match[1], 10);
    const hiding = match[2] === 'h';
    for (let i = base + 1; i <= base + 99; i++) {
      const row = document.getElementById(`h${i}`);
      if (row) hiding ? hide(row) : show(row);
    }
    hide(button);
    show(document.getElementById(`${base}${hiding ? 's' : 'h'}`));
  });
}

/** information page of the missile silo: destroy interplanetary/anti-ballistic missiles (`url`: the route of the button's data-url) */
export async function DestroyMissiles(url) {
  const data = await post(url, serialize(qsa('.missile')), 'json');
  const target502 = document.getElementById('missile_502');
  const target503 = document.getElementById('missile_503');
  if (target502) target502.textContent = pretty_number(data[0]);
  if (target503) target503.textContent = pretty_number(data[1]);
  qsa('.missile').forEach((input) => { input.value = ''; });
}

/** number inputs select their content on focus (players type a fresh amount, a click to fix a typo keeps the value) */
function selectNumberInputs() {
  let fresh = null;
  on(document, 'focusin', 'input[type="number"]', (event, input) => {
    input.select();
    fresh = input;
  });
  // the mouse-up of the focusing click would collapse the selection again
  on(document, 'mouseup', 'input[type="number"]', (event, input) => {
    if (fresh === input) event.preventDefault();
    fresh = null;
  });
}

/**
 * Forms marked `.build_form` / `[data-keep-scroll]` (build, cancel, dismantle, order) remember the scroll position
 * in sessionStorage; the page the server redirects back to restores it, so a second build is one click away.
 */
function keepScroll() {
  const KEY = 'keep-scroll';
  document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && event.target.matches('.build_form, [data-keep-scroll]')) {
      sessionStorage.setItem(KEY, JSON.stringify({ path: location.pathname, y: window.scrollY, at: Date.now() }));
    }
  });
  let saved = null;
  try {
    saved = JSON.parse(sessionStorage.getItem(KEY));
  } catch {
    // unreadable value: ignore
  }
  sessionStorage.removeItem(KEY);
  if (saved && saved.path === location.pathname && Date.now() - saved.at < 15000) {
    window.scrollTo(0, saved.y);
    window.addEventListener('load', () => window.scrollTo(0, saved.y), { once: true });
  }
}

/** mobile: the menu button opens the sidebar as a bottom sheet / drawer */
function sidebarToggle() {
  const setOpen = (open) => {
    document.body.classList.toggle('sidebar-open', open);
    qsa('[data-sidebar-toggle][aria-expanded]').forEach((button) => button.setAttribute('aria-expanded', String(open)));
  };
  on(document, 'click', '[data-sidebar-toggle]', () => setOpen(!document.body.classList.contains('sidebar-open')));
  on(document, 'click', '.sidebar-backdrop', () => setOpen(false));
  on(document, 'click', '.sidenav [data-action="popup"], .sidenav [data-dialog]', () => setOpen(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setOpen(false);
  });
}

/** top bar user menu: button toggles the panel; Escape, outside click and activating an item close it */
function userMenu() {
  const root = qs('[data-usermenu]');
  if (!root) return;
  const button = qs('.usermenu__button', root);
  const panel = qs('.usermenu__panel', root);
  const setOpen = (open, refocus = false) => {
    panel.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    if (!open && refocus) button.focus();
  };
  button.addEventListener('click', () => setOpen(panel.hidden));
  panel.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('a')) setOpen(false);
  });
  document.addEventListener('click', (event) => {
    if (!panel.hidden && !root.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) setOpen(false, true);
  });
  root.addEventListener('focusout', (event) => {
    if (!panel.hidden && event.relatedTarget instanceof Node && !root.contains(event.relatedTarget)) setOpen(false);
  });
}

/** phones: the tab bar hides while a text field is focused (on-screen keyboard) */
function tabbarKeyboard() {
  const typing = (el) => el instanceof HTMLElement && (el.isContentEditable || el.matches('textarea') || (el.matches('input') && !/^(checkbox|radio|button|submit|reset|range|color|file|hidden)$/.test(el.type)));
  document.addEventListener('focusin', (event) => {
    if (typing(event.target)) document.body.classList.add('kb-open');
  });
  document.addEventListener('click', (event) => {
    if (typing(event.target)) document.body.classList.add('kb-open');
  });
  document.addEventListener('focusout', () => {
    requestAnimationFrame(() => {
      if (!typing(document.activeElement)) document.body.classList.remove('kb-open');
    });
  });
}

/** phones: the floating page action (.page-fab) rises above the page footer while the footer is in view, so it never covers it */
function fabAboveFooter() {
  const fab = qs('.page-fab');
  const footer = qs('footer.footer');
  if (!fab || !footer) return;
  let queued = false;
  const update = () => {
    queued = false;
    const lift = Math.max(0, window.innerHeight - footer.getBoundingClientRect().top);
    document.documentElement.style.setProperty('--fab-lift', `${Math.round(lift)}px`);
  };
  const schedule = () => {
    if (!queued) {
      queued = true;
      requestAnimationFrame(update);
    }
  };
  window.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', schedule);
  // content loaded or folded later moves the footer without a scroll
  if ('ResizeObserver' in window) new ResizeObserver(schedule).observe(document.body);
  update();
}

/** keeps the active section tab visible in horizontal scroll strips on narrow screens */
function scrollActiveSectionTab() {
  const active = qs('.section-tabs__tab[aria-current="page"]');
  if (active && active.parentElement && active.parentElement.scrollWidth > active.parentElement.clientWidth) {
    active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
  }
}

/** the side panel sticks below the top bar; its height varies (resource row, wrapping), so measure it */
function topbarHeight() {
  const bar = qs('.topbar');
  if (!bar || !('ResizeObserver' in window)) return;
  new ResizeObserver(([entry]) => {
    document.documentElement.style.setProperty('--topbar-h', `${entry.borderBoxSize[0].blockSize}px`);
  }).observe(bar);
}

/**
 * Clicks that were not made by a person (synthetic events before any trusted click) are reported,
 * as before; the game uses this to spot automation.
 */
function automationReport() {
  let humanInteraction = false;
  document.addEventListener('click', (event) => {
    if (event.isTrusted) {
      humanInteraction = true;
    } else if (!humanInteraction && event.target instanceof Element) {
      post(pageData('game-routes').clientReport, {
        hi: humanInteraction,
        u: document.location.href,
        o: event.target.outerHTML,
        ts: event.timeStamp,
      }).catch(() => {});
    }
  }, true);
}

/** Starts all page-wide behaviours (delegated listeners, no per-page setup) and initialises tabs and sortable tables found in the DOM. */
export function initUi() {
  warningBanners();
  planetSelector();
  listFilters();
  selectNumberInputs();
  keepScroll();
  sidebarToggle();
  userMenu();
  tabbarKeyboard();
  fabAboveFooter();
  scrollActiveSectionTab();
  topbarHeight();
  automationReport();
  qsa('[data-tabs]').forEach((el) => tabs(el));
  qsa('table.sortable, table[data-sortable]').forEach(sortableTable);
}

export { qs };
