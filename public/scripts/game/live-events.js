import { qs, qsa, now, pad, getRestTimeFormat, getJSON, setVisibleInterval } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Live fleet events of the overview: polls the JSON feed (`data-events-url` of #events-accordion) every 20 s while
 * the tab is visible (60 s after an error, nothing while hidden), re-renders the list only when its hash changed,
 * removes events whose countdown reached 0, flashes newly appearing hostile fleets (not with prefers-reduced-motion)
 * and shows the number of hostile fleets in the document title ("(1) V0Game"). Rows are cloned from the Twig
 * <template id="live-event-row"> and filled with textContent only. The countdowns themselves are ticked by
 * game/overview.js (`.fleets[data-fleet-time]`, seconds since page render) and app/timers.js.
 */
const POLL_MS = 20000;
const BACKOFF_MS = 60000;
const ARRIVE_POLL_MS = 3000;

const section = qs('#events-accordion[data-events-url]');
const baseTitle = document.title.replace(/^\(\d+\)\s+/, '');
let lastHash = null;
let timer = null;
let failed = false;
let inFlight = false;

/** Seconds since the page was rendered (the same reference overview.js counts down against). */
const elapsed = () => (now().getTime() - env.startTime) / 1000;

/** Event keys currently listed, in order. */
const listedKeys = (list) => qsa('[data-event-key]', list).map((li) => li.dataset.eventKey);

/** Arrival clock text in the player's time zone ("2T 14:03:09" with a day prefix), as app/timers.js writes it. */
function arrivalText(timestamp) {
  const arrival = new Date(new Date(timestamp * 1000).toLocaleString('en-US', { timeZone: window.localtimezonestring || undefined }));
  const days = Math.floor((arrival - now()) / 864e5);
  return `${days > 0 ? `${days}T ` : ''}${pad(arrival.getHours(), 2)}:${pad(arrival.getMinutes(), 2)}:${pad(arrival.getSeconds(), 2)}`;
}

/** One list row from the template. */
function buildRow(template, event, highlight) {
  const row = template.content.firstElementChild.cloneNode(true);
  row.dataset.eventKey = event.key;
  if (event.hostile) {
    row.classList.add('event-list__hostile');
    if (highlight) row.classList.add('event-list__new');
  }
  row.querySelector(`[data-icon="${event.hostile ? 'own' : 'hostile'}"]`)?.remove();
  if (event.hostile) row.querySelector('[data-field="tag"]').hidden = false;
  const text = row.querySelector('[data-field="text"]');
  text.textContent = event.text;
  if (/^[A-Za-z0-9_ -]+$/.test(event.class)) {
    text.className = event.class;
    row.classList.add(...event.class.split(/\s+/).filter(Boolean));
  }
  const remaining = event.rest + elapsed();
  const left = row.querySelector('.fleets');
  left.dataset.fleetEndTime = String(event.arrival);
  left.dataset.fleetTime = String(remaining);
  left.textContent = getRestTimeFormat(event.rest);
  const clock = row.querySelector('.statictimer');
  clock.dataset.time = String(event.arrival);
  clock.textContent = arrivalText(event.arrival);
  return row;
}

/** Header: count, next countdown and the hostile tag; alert icon and hostile state of the panel. */
function updateHeader(count, hostile, nextRest) {
  const meta = qs('[data-live-meta]', section);
  meta.replaceChildren(String(count));
  if (count > 0) {
    meta.append(' · ');
    const next = document.createElement('span');
    next.className = 'fleets';
    next.dataset.fleetTime = String(nextRest + elapsed());
    next.textContent = getRestTimeFormat(nextRest);
    meta.append(next);
  }
  if (hostile > 0) {
    const tag = document.createElement('span');
    tag.className = 'tag tag--neg';
    tag.textContent = `${section.dataset.hostileLabel} ${hostile}`;
    meta.append(' · ', tag);
  }
  qs('[data-live-alert]', section).hidden = hostile === 0;
  section.classList.toggle('is-hostile', hostile > 0);
  document.title = hostile > 0 ? `(${hostile}) ${baseTitle}` : baseTitle;
}

/** Replaces the list with the events of the feed; fleets that were not listed before and are hostile flash once. */
function render(data) {
  const list = qs('[data-live-list]', section);
  const rowTemplate = document.getElementById('live-event-row');
  const known = new Set(listedKeys(list));
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const rows = data.events.map((event) => buildRow(rowTemplate, event, !reduced && !known.has(event.key)));
  if (rows.length === 0) rows.push(document.getElementById('live-event-empty').content.firstElementChild.cloneNode(true));
  list.replaceChildren(...rows);
  const nextRest = data.events.length ? Math.min(...data.events.map((event) => event.rest)) : 0;
  updateHeader(data.events.length, data.hostile, nextRest);
}

/** Drops rows whose countdown reached 0 (the next poll brings the return leg, if any). Returns whether one went. */
function dropArrived() {
  const list = qs('[data-live-list]', section);
  let removed = false;
  qsa('[data-event-key]', list).forEach((li) => {
    const left = li.querySelector('.fleets');
    if (left && Number(left.dataset.fleetTime) - elapsed() <= 0) {
      li.remove();
      removed = true;
    }
  });
  if (!removed) return false;
  const rows = qsa('[data-event-key]', list);
  const hostile = rows.filter((li) => li.classList.contains('event-list__hostile')).length;
  const next = rows.length ? Math.min(...rows.map((li) => Number(li.querySelector('.fleets').dataset.fleetTime) - elapsed())) : 0;
  if (rows.length === 0) list.append(document.getElementById('live-event-empty').content.firstElementChild.cloneNode(true));
  updateHeader(rows.length, hostile, Math.max(0, next));
  return true;
}

/** Fetches the feed once and schedules the next poll. */
async function poll() {
  window.clearTimeout(timer);
  if (document.hidden || inFlight) return;
  inFlight = true;
  try {
    const data = await getJSON(section.dataset.eventsUrl);
    failed = false;
    if (data.hash !== lastHash) {
      const list = qs('[data-live-list]', section);
      // first answer: keep the server markup (popups) when it lists the same events
      const same = lastHash === null && listedKeys(list).join() === data.events.map((event) => event.key).join();
      if (!same) render(data);
      else document.title = data.hostile > 0 ? `(${data.hostile}) ${baseTitle}` : baseTitle;
      lastHash = data.hash;
    }
  } catch (error) {
    failed = true;
  } finally {
    inFlight = false;
    schedule(failed ? BACKOFF_MS : POLL_MS);
  }
}

/** Next poll in `ms`; nothing is scheduled while the tab is hidden (visibilitychange resumes). */
function schedule(ms) {
  window.clearTimeout(timer);
  if (!document.hidden) timer = window.setTimeout(poll, ms);
}

if (section) {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) window.clearTimeout(timer);
    else poll();
  });
  setVisibleInterval(() => {
    if (dropArrived()) schedule(ARRIVE_POLL_MS);
  }, 1000);
  const initialHostile = qsa('.event-list__hostile', section).length;
  if (initialHostile > 0) document.title = `(${initialHostile}) ${baseTitle}`;
  schedule(POLL_MS);
}
