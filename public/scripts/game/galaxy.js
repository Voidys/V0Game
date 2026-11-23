import { qsa, post, pretty_number, pageData, NotifyBox } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { registerActions } from '../app/actions.js';
import { closeTooltip, tooltipHtml } from '../app/tooltip.js';

/**
 * Galaxy page: mission buttons (spy, recycle, ...), the missile form, system navigation (buttons, keyboard,
 * coordinate input, browser history) and the local galaxy data store (sync / export / import / reset).
 * Navigation does not reload the page: the server renders one system as an HTML fragment (the `#galaxy-view` block,
 * GalaxyController::rows()) that replaces the shown one, so every shown system is requested (and charged) exactly once.
 * Server data (labels, urls) comes from the data island #galaxy-data (page.galaxy.default.html.twig), the shown
 * system from #galaxy-system inside the view (galaxy.system.html.twig); the controls are data-action markup
 * (see app/actions.js).
 */
const galaxyData = pageData('galaxy-data');
// "galaxy:system" of the shown system and its position -> planet data (null: empty or destroyed), as stored in the local galaxy data
let galakey;
let systemdata;

/** Reads the shown system from its data island. */
const readSystem = () => {
  const system = pageData('galaxy-system');
  galakey = system.galakey;
  systemdata = {};
  for (const { position, data } of system.systemRows ?? []) systemdata[position] = data;
  systemdata.timepoint = Date.now();
};
readSystem();
const maxFleetSetting = galaxyData.maxFleetSetting;
const planetlng = galaxyData.planetLng;
const moonshortlng = galaxyData.moonShortLng;
const confirmlng = galaxyData.confirmLng;

/** POSTs a mission request of the galaxy view to `fleetAjax` (JSON answer); `confirmed` repeats it after the player agreed. */
const sendMission = (missionID, planetID, confirmed = false) => {
  const query = new URLSearchParams({ ajax: 1, mission: missionID, planetID });
  if (confirmed) query.set('confirm', 1);
  return post(`${galaxyData.urls.fleetAjax}?${query}`, {}, 'json');
};

/** Runs a mission request; code 602 asks the player to confirm first (the question is the server's message), then repeats it. Resolves to the final answer, or null when declined. */
const requestMission = async (missionID, planetID) => {
  const answer = await sendMission(missionID, planetID);
  if (answer.code === 602) {
    //602 = needs confirmation
    if (!(await Dialog.confirm(answer.mess))) {
      return null;
    }
    return sendMission(missionID, planetID, true);
  }
  return answer;
};

/** sends a fleet mission (spy, recycle, ...) of the galaxy view to the server; asks first when the server wants a confirmation */
const doit = async (missionID, planetID) => {
  const answer = await requestMission(missionID, planetID);
  if (answer) handleDoIt(answer);
};

/** Shows the result of a mission request: updates free slots and ship counts, appends the server message to the status list (oldest dropped at `maxFleetSetting`), green for code 600, red otherwise. */
const handleDoIt = (data) => {
  document.getElementById('slots').textContent = data.slots;
  if (typeof data.ships !== 'undefined') {
    for (const [elementID, value] of Object.entries(data.ships)) {
      const cell = document.getElementById(`elementID${elementID}`);
      if (cell) cell.textContent = pretty_number(value);
    }
  }

  const statustable = document.getElementById('fleetstatusrow');
  const messages = statustable.querySelectorAll(':scope > div');
  if (messages.length === maxFleetSetting) {
    messages[0].remove();
  }
  const element = document.createElement('div');
  element.className = `galaxy-grid-item centered span6 ${data.code === 600 ? 'success' : 'error'}`;
  element.textContent = data.mess;
  statustable.hidden = false;
  statustable.append(element);
  // the result as a toast under the top bar; the list below the grid keeps the log
  NotifyBox(element.innerHTML, { kind: data.code === 600 ? 'success' : 'error', top: true, ms: 3500 });
};

/** Fills the interplanetary missile form with the target (galaxy, system, position, planet/moon type) and flight time (seconds), shows it and focuses the amount field. Returns false (cancels the link). */
const showMissileForm = (galaxy, system, planetPos, duration, type) => {
  const form = document.getElementById('missileForm')?.closest('form');
  if (!form) return false;
  form.querySelector('input[name="galaxy"]').value = galaxy;
  form.querySelector('input[name="system"]').value = system;
  form.querySelector('input[name="planet"]').value = planetPos;
  form.querySelector('input[name="type"]').value = type;
  form.querySelector('input[name="duration"]').value = duration;
  const flyTime = document.getElementById('irakFlyTime');
  if (flyTime) flyTime.textContent = duration;
  const table = document.getElementById('missileForm');
  if (table) {
    table.hidden = false;
    table.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  form.querySelector('input[name="SendMI"]')?.focus();
  return false;
};

/** Answer of the missile pre-check (`mission=10`): code 600 opens the form, anything else is shown as an alert. */
const handleMissileAjax = (data, galaxy, system, planetPos, duration, type) => {
  if (data.code !== 600) {
    Dialog.alert(data.mess);
    return;
  }
  showMissileForm(galaxy, system, planetPos, duration, type);
};

/** Pre-checks a missile attack on the planet via `fleetAjax` (mission 10); code 602 asks the player to confirm first (outlaw rules), then opens the form on success. Returns false (cancels the link). */
const openMissileForm = (planetID, galaxy, system, planetPos, duration, type) => {
  requestMission(10, planetID).then((answer) => {
    if (answer) handleMissileAjax(answer, galaxy, system, planetPos, duration, type);
  });
  return false;
};

const navForm = () => document.getElementById('galaxy_form');

/** Coordinates of the form fields, with `direction` (`systemLeft`, `systemRight`, `galaxyLeft`, `galaxyRight`) as the server's one-step move from them. */
const formQuery = (direction) => {
  const form = navForm();
  const query = { galaxy: form.elements.galaxy.value, system: form.elements.system.value };
  if (direction) query[direction] = 1;
  return query;
};

let loading = false;
/** the navigation asked for while one was loading (only the newest one counts): [query function, push history entry] */
let queued = null;

/**
 * Tells screen readers which system is shown now (live region #galaxy-status, no focus is taken from the buttons). When the
 * move came from the keyboard shortcuts (nothing focused), focus goes to the new view so the next Tab starts at its first row.
 */
const announceSystem = (galaxy, system) => {
  const labels = qsa('.galaxy-nav__label').map((label) => label.textContent.trim());
  const status = document.getElementById('galaxy-status');
  if (status) status.textContent = `${labels[0] ?? ''} ${galaxy}, ${labels[1] ?? ''} ${system}`;
  if (document.activeElement === document.body) document.getElementById('galaxy-view')?.focus({ preventScroll: true });
};

/** Shows the fetched `#galaxy-view`: replaces the shown one, updates the coordinate fields and the history. */
const showSystem = (next, push) => {
  closeTooltip();
  document.getElementById('galaxy-view').replaceWith(next);
  const { galaxy, system } = next.dataset;
  const form = navForm();
  form.elements.galaxy.value = galaxy;
  form.elements.system.value = system;
  readSystem();
  bindView();
  announceSystem(galaxy, system);
  document.dispatchEvent(new Event('galaxy:shown'));
  if (push) history.pushState({ galaxy, system }, '', `${galaxyData.urls.galaxy}/${galaxy}/${system}`);
};

/**
 * Loads one system without a page load. `makeQuery` is evaluated when the request starts (the fields may have changed while
 * another one was running). Anything unexpected (no deuterium, rate limit, session end, network) falls back to the
 * normal page request, which shows the server's message.
 */
const navigate = async (makeQuery, push = true) => {
  if (loading) {
    queued = [makeQuery, push];
    return;
  }
  loading = true;
  const query = new URLSearchParams(makeQuery());
  try {
    const response = await fetch(`${galaxyData.urls.rows}?${query}`, { credentials: 'same-origin' });
    if (!response.ok || !response.headers.has('X-Galaxy-Fragment')) throw new Error(`galaxy: ${response.status}`);
    const holder = document.createElement('template');
    holder.innerHTML = await response.text();
    const next = holder.content.querySelector('#galaxy-view');
    if (!next) throw new Error('galaxy: no view');
    showSystem(next, push);
  } catch (error) {
    window.location.assign(`${galaxyData.urls.galaxy}?${query}`);
    return;
  } finally {
    loading = false;
  }
  if (queued) {
    const [nextQuery, nextPush] = queued;
    queued = null;
    navigate(nextQuery, nextPush);
  }
};

/** Moves the galaxy view one step: `direction` is `systemLeft`, `systemRight`, `galaxyLeft` or `galaxyRight`. */
const galaxySubmit = (direction) => navigate(() => formQuery(direction));

/** "uniN" of the current URL path (default "uni1"); prefix of the local storage keys. */
const parseUni = () => window.location.pathname.match('uni?.')?.[0] || 'uni1';

/**
 * Per-universe galaxy data store on localStorage (keys "<uniN>_<name>", JSON values).
 * The key format is unchanged so galaxy data saved by earlier versions still loads.
 * Storage errors (quota, disabled storage, corrupt JSON) fall back to the default / are ignored.
 */
const galaxyStore = {
  get(key, defaultValue) {
    try {
      const raw = localStorage.getItem(`${parseUni()}_${key}`);
      return raw === null ? defaultValue : JSON.parse(raw);
    } catch (e) {
      return defaultValue;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(`${parseUni()}_${key}`, JSON.stringify(value));
    } catch (e) {
      console.warn('galaxy data could not be saved', e);
    }
  },
};

/** Local galaxy data store: `universe` ("g:s" -> planets by position, with a timepoint), `playerids` and `allyids` (id -> name + timepoint). Entries with the newer timepoint win when data is merged. */
const universe = galaxyStore.get('universe', {});
const playerids = galaxyStore.get('playerids', {});
const allyids = galaxyStore.get('allyids', {});

const saveStore = () => {
  galaxyStore.set('universe', universe);
  galaxyStore.set('playerids', playerids);
  galaxyStore.set('allyids', allyids);
};

/** Offers `text` as a file download in the browser (data URL link, no server call). */
const download = (filename, text) => {
  const element = document.createElement('a');
  element.setAttribute('href', `data:text/plain;charset=utf-8,${encodeURIComponent(text)}`);
  element.setAttribute('download', filename);
  element.style.display = 'none';
  document.body.appendChild(element);
  element.click();
  document.body.removeChild(element);
};

/** Sort order of "galaxy:system" keys: numeric by galaxy, then system. */
const compareSystemKeys = (a, b) => {
  const [galaxyA, systemA] = a.split(':');
  const [galaxyB, systemB] = b.split(':');
  if (parseInt(galaxyA) < parseInt(galaxyB)) return -1;
  if (galaxyA !== galaxyB) return 1;
  if (parseInt(systemA) < parseInt(systemB)) return -1;
  if (systemA !== systemB) return 1;
  return 0;
};

/** Merges `source` into `target`: unknown keys are taken over, known ones when their timepoint is newer. */
const mergeNewer = (target, source) => {
  for (const key in source) {
    if (!(key in target) || target[key].timepoint < source[key].timepoint) {
      target[key] = source[key];
    }
  }
};

/** Reads the chosen JSON file (as written by exportJson) for onImportLoaded(). */
const onImportChange = (event) => {
  const reader = new FileReader();
  reader.onload = onImportLoaded;
  reader.readAsText(event.target.files[0]);
};

/** Merges an exported galaxy file ([universe, playerids, allyids]) into the local store; per entry the newer timepoint wins, then saves. */
const onImportLoaded = (event) => {
  const [importedUniverse, importedPlayers, importedAllies] = JSON.parse(event.target.result);
  mergeNewer(universe, importedUniverse);
  mergeNewer(playerids, importedPlayers);
  mergeNewer(allyids, importedAllies);
  saveStore();
};

/** Downloads the stored data as CSV: coordinates, moon flag, player and alliance (id in brackets), status; systems sorted by galaxy and system. */
const exportCsv = () => {
  let csv = 'coords,moon?,name,ally,status,\n';
  for (const key of Object.keys(universe).sort(compareSystemKeys)) {
    for (let position = 1; position < 16; position++) {
      const planet = universe[key][position];
      if (planet !== null) {
        csv += `${key}:${position},${planet.hasmoon ? 'X' : ''},${playerids[planet.playerid].name}(${planet.playerid}),${allyids[planet.allianceid].name}(${planet.allianceid}),${planet.special},\n`;
      }
    }
  }
  download('universe.csv', csv);
};

/** Downloads the stored data as JSON ([universe, playerids, allyids]), the format import understands. */
const exportJson = () => download('universe.json', JSON.stringify([universe, playerids, allyids]));

/** After confirmation deletes all locally stored galaxy data. */
const resetStore = async () => {
  if (!(await Dialog.confirm(confirmlng, { danger: true }))) {
    return;
  }
  for (const store of [universe, playerids, allyids]) {
    Object.keys(store).forEach((key) => delete store[key]);
  }
  saveStore();
};

/** Stores the currently viewed system, with its players and alliances, in the local galaxy data. */
const syncSystem = () => {
  universe[galakey] = systemdata;
  for (const position in systemdata) {
    const planet = systemdata[position];
    if (position === 'timepoint' || planet === null) {
      continue;
    }
    playerids[planet.playerid] = { name: planet.name, timepoint: systemdata.timepoint };
    allyids[planet.allianceid] = { name: planet.alliancename, timepoint: systemdata.timepoint };
  }
  saveStore();
};

const coordString = ([galaxy, system, position, moon]) => `[${galaxy}:${system}:${position}] ${moon}`.trim();

/** Appends a list of the player's known planets (from the local data, own system highlighted, links within the current galaxy emphasised) to the tooltip HTML of `element`. */
const annotateHover = (id, element) => {
  const content = tooltipHtml(element);
  if (!content) return;
  // a template's HTML comes back with its implicit <tbody>: leave that open so the added rows join the table body
  let html = content.replace('</tbody></table>', '').replace('</table>', '');
  html += `<tr><th colspan='2'>${planetlng}</th></tr>`;
  const baseurl = `${galaxyData.urls.galaxy}/`;
  const known = [];
  for (const key in universe) {
    for (let position = 1; position < 16; position++) {
      const planet = universe[key][position];
      if (planet === null) {
        continue;
      }
      if (planet.playerid == id) {
        const [galaxy, system] = key.split(':').map((part) => parseInt(part));
        known.push([galaxy, system, position, planet.hasmoon ? moonshortlng : '']);
      }
    }
  }
  known.sort();
  const [currentGalaxy, currentSystem] = galakey.split(':');
  for (const coord of known) {
    const link = `<a href='${baseurl}${coord[0]}/${coord[1]}' data-action='galaxy-go' data-galaxy='${coord[0]}' data-system='${coord[1]}'>${coordString(coord)}</a>`;
    if (coord[0] == currentGalaxy && coord[1] == currentSystem) {
      html += `<tr><td colspan='2' style='text-align: center'><span>${coordString(coord)}</span></td></tr>`;
    } else if (coord[0] == currentGalaxy) {
      html += `<tr><th colspan='2' style='text-align: center'>${link}</th></tr>`;
    } else {
      html += `<tr><td colspan='2' style='text-align: center'>${link}</td></tr>`;
    }
  }

  element.setAttribute('data-tooltip-content', `${html}</table>`);
};

/** Number of mark colours (CSS: [data-mark="1".."5"] in galaxy-fleet.css). */
const MARK_COLORS = 5;
const defaultFilters = { inactive: false, range: false, moon: false, hideVacation: false, debris: 0, hlMine: false, hlBuddies: false };
let filters = { ...defaultFilters, ...galaxyStore.get('filters', {}) };
/** Marked players / alliances: { p: { id: colour 1..5 }, a: { id: colour } }, per universe. */
const marks = { p: {}, a: {}, ...galaxyStore.get('marks', {}) };

/** Whether a row (data-flags token list, data-debris sum) passes every active filter. */
const rowMatches = (row) => {
  const flags = (row.dataset.flags ?? '').split(' ');
  if (filters.inactive && !flags.includes('inactive')) return false;
  if (filters.range && !flags.includes('range')) return false;
  if (filters.moon && !flags.includes('moon')) return false;
  if (filters.hideVacation && flags.includes('vacation')) return false;
  if (filters.debris > 0 && Number(row.dataset.debris ?? 0) < filters.debris) return false;
  return true;
};

/** Dims the non-matching rows (they stay in place) and applies the marks and the alliance / buddy highlight of the shown system. */
const applyView = () => {
  const anyFilter = filters.inactive || filters.range || filters.moon || filters.hideVacation || filters.debris > 0;
  for (const row of qsa('#galaxy-view tr[data-filterable]')) {
    row.classList.toggle('is-dim', anyFilter && !rowMatches(row));
    const flags = (row.dataset.flags ?? '').split(' ');
    row.classList.toggle('is-hl', (filters.hlMine && flags.includes('mine')) || (filters.hlBuddies && flags.includes('buddy')));
    const player = row.querySelector('.galaxy-player');
    const ally = row.querySelector('.galaxy-alliance-cell');
    if (player) player.dataset.mark = marks.p[row.dataset.player] ?? '';
    if (ally) ally.dataset.mark = marks.a[row.dataset.ally] ?? '';
  }
};

/** Writes the filter controls from the stored state (the controls sit outside the swapped view). */
const syncFilterControls = () => {
  for (const input of qsa('#galaxy-filters [data-filter]')) {
    const key = input.dataset.filter;
    if (input.type === 'checkbox') input.checked = !!filters[key];
    else input.value = filters[key] > 0 ? filters[key] : '';
  }
};

const initFilters = () => {
  const bar = document.getElementById('galaxy-filters');
  if (!bar) return;
  syncFilterControls();
  bar.addEventListener('input', (event) => {
    const input = event.target.closest('[data-filter]');
    if (!input) return;
    filters[input.dataset.filter] = input.type === 'checkbox' ? input.checked : Math.max(0, parseInt(input.value, 10) || 0);
    galaxyStore.set('filters', filters);
    applyView();
  });
  applyView();
};

/** Cycles the mark colour of a player / alliance: none, 1 .. MARK_COLORS, none. */
const cycleMark = (kind, id) => {
  const next = ((marks[kind][id] ?? 0) + 1) % (MARK_COLORS + 1);
  if (next) marks[kind][id] = next;
  else delete marks[kind][id];
  galaxyStore.set('marks', marks);
  applyView();
};

/** Adds the stored positions to the player tooltips of the shown system and hooks its sync / export / import / reset buttons. */
const bindView = () => {
  document.getElementById('gala_upload').addEventListener('change', onImportChange);
  document.getElementById('gala_exportjson').addEventListener('click', exportJson);
  document.getElementById('gala_exportcsv').addEventListener('click', exportCsv);
  document.getElementById('gala_reset').addEventListener('click', resetStore);

  // adds the known planet locations to the tooltip of every player name on the page
  for (const username of qsa('.galaxy-username')) {
    annotateHover(username.getAttribute('playerid'), username.parentElement);
  }
  applyView();
};

/** One-time page setup: the sync button (it sits in the navigation form, outside the swapped view), the form submit, history. */
const init = () => {
  const syncButton = document.getElementById('gala_sync');
  const syncLabel = syncButton.value;
  syncButton.addEventListener('click', () => {
    syncSystem();
    syncButton.value = 'Synced';
  });
  navForm().addEventListener('submit', (event) => {
    event.preventDefault();
    navigate(() => formQuery());
  });
  document.addEventListener('galaxy:shown', () => { syncButton.value = syncLabel; });
  // the first entry knows its system, so going back to it needs no page load
  const { galaxy, system } = document.getElementById('galaxy-view').dataset;
  history.replaceState({ galaxy, system }, '');
  window.addEventListener('popstate', (event) => {
    if (event.state?.galaxy) navigate(() => ({ galaxy: event.state.galaxy, system: event.state.system }), false);
    else window.location.reload();
  });
  bindView();
  initFilters();
};

window.onPageInit(init);

/** Keyboard navigation of the galaxy view: A / D or left / right arrows change the system, W / S or up / down the galaxy (not while focus is in a text, number or select field). */
const KEY_DIRECTIONS = {
  a: 'systemLeft',
  ArrowLeft: 'systemLeft',
  d: 'systemRight',
  ArrowRight: 'systemRight',
  w: 'galaxyLeft',
  ArrowUp: 'galaxyLeft',
  s: 'galaxyRight',
  ArrowDown: 'galaxyRight',
};

/** true while the player types text (message dialogs, search fields): the letters are not navigation then */
const isTyping = (target) => target instanceof Element
  && (target.closest('textarea, select, [contenteditable]') !== null
    || (target instanceof HTMLInputElement && !['button', 'submit', 'checkbox', 'radio'].includes(target.type)));

document.addEventListener('keydown', (event) => {
  const direction = event.repeat || event.ctrlKey || event.metaKey || event.altKey || isTyping(event.target) ? undefined : KEY_DIRECTIONS[event.key];
  if (direction) galaxySubmit(direction);
});

registerActions({
  'galaxy-submit': (button) => galaxySubmit(button.dataset.direction),
  /** a coordinate link of a player tooltip: open that system in place */
  'galaxy-go': (link) => {
    document.getElementById('galaxy_form').elements.galaxy.value = link.dataset.galaxy;
    document.getElementById('galaxy_form').elements.system.value = link.dataset.system;
    navigate(() => formQuery());
    return false;
  },
  'galaxy-mark': (button) => cycleMark(button.dataset.kind, button.dataset.id),
  'galaxy-marks-clear': () => {
    marks.p = {};
    marks.a = {};
    galaxyStore.set('marks', marks);
    applyView();
  },
  'galaxy-filter-reset': () => {
    filters = { ...defaultFilters, hlMine: filters.hlMine, hlBuddies: filters.hlBuddies };
    galaxyStore.set('filters', filters);
    syncFilterControls();
    applyView();
  },
  'galaxy-mission': (link) => doit(link.dataset.mission, link.dataset.planet),
  'missile-form': (link) => {
    const { planet, galaxy, system, position, duration, type } = link.dataset;
    return openMissileForm(planet, galaxy, system, position, duration, type);
  },
  /** "max" next to the missile count: all available missiles */
  'missiles-max': () => {
    document.getElementsByName('SendMI')[0].value = document.getElementById('missiles').textContent.replace(/\D/g, '');
  },
});
