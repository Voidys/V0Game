/**
 * Declarative page behaviour without inline handlers (the Content-Security-Policy forbids on*="" attributes
 * and javascript: links). Markup names an action, a page module registers what it does:
 *
 *   <button data-action="galaxy-submit" data-direction="systemLeft">      click
 *   <select data-on-change="submit-form">                                 change
 *   <input  data-on-input="update-vars">  data-on-keyup  data-on-focus  data-on-blur
 *   <form   data-on-submit="check-target">                                submit
 *   <img    data-on-error="remove">                                       error (does not bubble, captured)
 *
 *   registerActions({ 'galaxy-submit': (element, event) => { ... } });
 *
 * One listener per event type sits on the document, so markup added later (tooltips, dialog fragments,
 * rows built by scripts) works without re-binding. The handler gets the element that carries the attribute
 * and the event, and reads its arguments from the element's data-* attributes.
 *
 * Return value (the old "return false" semantics): a click action returns false to cancel the default
 * (a link or submit button); anchors with href="#" or no href are always cancelled. A submit action returns
 * false to stop the submission. For the other events the result is ignored.
 *
 * data-confirm="question" on a link, button or submit input asks first in the themed modal (Dialog.confirm);
 * declining cancels the click and every action on that element. The click is stopped while the question is
 * open; on OK it is replayed once on the same element (a one-shot bypass skips the question), so actions,
 * the data-method="post" link handler and form submission (a replayed click on a submit button submits
 * with it as submitter, so its name/value is sent) run exactly once. data-confirm-danger styles the OK button as destructive.
 *
 * Built-in actions (below) cover what many pages need: the dialogs, popups, auto-submitting selects,
 * collapsible table sections, character counters.
 */
import { qsa, ready, OpenPopup } from './core.js';
import { Dialog } from './dialog.js';
import { DestroyMissiles } from './ui.js';

/** @type {Map<string, (element: Element, event: Event) => (boolean|void|Promise<unknown>)>} */
const registry = new Map();

/**
 * Registers named actions; later registrations of a name replace earlier ones.
 * @param {Record<string, (element: Element, event: Event) => (boolean|void|Promise<unknown>)>} actions
 */
export function registerActions(actions) {
  for (const [name, handler] of Object.entries(actions)) {
    registry.set(name, handler);
  }
}

/** the element the event concerns that carries the attribute, or null */
const carrier = (event, attribute) => (event.target instanceof Element ? event.target.closest(`[${attribute}]`) : null);

/** Finds the carrier of `attribute` for the event and calls its registered handler; null when there is none or the name is not registered (other areas reuse data-action for their own purposes). Returns {element, result}. */
function run(event, attribute) {
  const element = carrier(event, attribute);
  if (!element) return null;
  const name = element.getAttribute(attribute);
  const handler = registry.get(name);
  if (!handler) return null; // other areas use data-action with their own meaning
  return { element, result: handler(element, event) };
}

// confirmation first: a declined one stops the click before any action or the csrf link handler sees it
let confirmed = null; // element whose replayed click skips the question

/** replays the click the confirmation held back; a synthetic click on a submit button submits with that button as submitter (name/value kept) */
function replay(element) {
  confirmed = element;
  try {
    element.click();
  } finally {
    confirmed = null;
  }
}

document.addEventListener('click', (event) => {
  // only links, buttons and submit inputs ask (not a form or a wrapper that merely contains them)
  const element = event.target instanceof Element ? event.target.closest('a[data-confirm], button[data-confirm], input[data-confirm]') : null;
  if (!element || element === confirmed) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  Dialog.confirm(element.dataset.confirm, { danger: element.hasAttribute('data-confirm-danger') }).then((ok) => {
    if (ok) replay(element);
  });
}, true);

document.addEventListener('click', (event) => {
  const ran = run(event, 'data-action');
  if (!ran) return;
  const { element, result } = ran;
  const href = element.getAttribute('href');
  if (result === false || (element.tagName === 'A' && (href === null || href === '' || href.startsWith('#')))) {
    event.preventDefault();
  }
});

document.addEventListener('submit', (event) => {
  if (run(event, 'data-on-submit')?.result === false) event.preventDefault();
});

for (const [type, attribute] of [['change', 'data-on-change'], ['input', 'data-on-input'], ['keyup', 'data-on-keyup'],
  ['focusin', 'data-on-focus'], ['focusout', 'data-on-blur']]) {
  document.addEventListener(type, (event) => { run(event, attribute); });
}
document.addEventListener('error', (event) => { run(event, 'data-on-error'); }, true);
// images that failed before this module ran have fired their error event already
ready(() => {
  qsa('img[data-on-error]').forEach((image) => {
    if (image.complete && image.naturalWidth === 0) registry.get(image.getAttribute('data-on-error'))?.(image, new Event('error'));
  });
});

// ---------------------------------------------------------------------------------------- legacy handlers

/**
 * Safety net for markup that did not come through the templates: rows stored before the policy carry inline
 * handlers, e.g. <a href="#" onclick="return Dialog.Playercard(12, 'name');">. Stored message pages are
 * rewritten server-side (Twig filter legacy_markup), so this only catches what that misses; the browser
 * blocks the handler (and may report it) but the attribute stays readable. New texts use data-action.
 */
const LEGACY_DIALOG = /^\s*(?:return\s+)?Dialog\.(Playercard|PM|Buddy|info|ach)\(\s*(\d+)/;

document.addEventListener('click', (event) => {
  const link = event.target instanceof Element ? event.target.closest('a[onclick]') : null;
  const match = link ? LEGACY_DIALOG.exec(link.getAttribute('onclick')) : null;
  if (!match) return;
  event.preventDefault();
  Dialog[match[1]](match[2]);
});

// ---------------------------------------------------------------------------------------- built-ins

const id = (element) => element.dataset.id;

registerActions({
  // dialogs (scripts/app/dialog.js); data-id is the player / element / achievement id
  'dialog-info': (element) => { Dialog.info(id(element)); return false; },
  'dialog-pm': (element) => { Dialog.PM(id(element)); return false; },
  'dialog-buddy': (element) => { Dialog.Buddy(id(element)); return false; },
  'dialog-playercard': (element) => { Dialog.Playercard(id(element)); return false; },
  'dialog-forward': (element) => { Dialog.Forward(id(element)); return false; },
  'dialog-ach': (element) => { Dialog.ach(id(element)); return false; },
  /** data-target: id of hidden markup on the page that is shown as modal, data-width in px */
  'dialog-show': (element) => { Dialog.show(document.getElementById(element.dataset.target), { width: Number(element.dataset.width) || undefined }); return false; },
  /** data-url (game URL, e.g. rendered with path()), data-width: a game page as modal */
  popup: (element) => { OpenPopup(element.dataset.url, '', element.dataset.width); return false; },
  /** auto-submitting form control (select) */
  'submit-form': (element) => { element.form?.requestSubmit(); },
  /** data-target: class of the rows to collapse/expand, #<target>-toggle shows the arrow */
  'toggle-rows': (element) => toggleRows(element.dataset.target),
  /** data-target: id of the element that shows the length of this field's text */
  'char-count': (element) => {
    const out = document.getElementById(element.dataset.target);
    if (out) out.textContent = element.value.length;
  },
  /** data-target: id of an input, data-value: what to put into it (the "max" buttons) */
  'fill-input': (element) => { document.getElementById(element.dataset.target).value = element.dataset.value; },
  /**
   * Segmented filter buttons of the buildings, research and fleet lists. data-hide: rows the filter
   * covers; data-show: the rows to keep (the attribute missing = show everything); data-filter-group
   * marks the buttons that belong together, the chosen one gets the "selected" class.
   */
  'filter-rows': (button) => {
    const { hide, show, filterGroup } = button.dataset;
    qsa(hide).forEach((row) => { row.style.display = button.hasAttribute('data-show') ? 'none' : ''; });
    if (show) qsa(show).forEach((row) => { row.style.display = ''; });
    qsa(`[data-filter-group="${filterGroup}"]`).forEach((other) => other.classList.toggle('selected', other === button));
    if (button.id && filterGroup) {
      try { localStorage.setItem(`filter:${window.location.pathname}:${filterGroup}`, button.id); } catch (e) { /* storage unavailable */ }
    }
  },
  /** missile silo information page: destroy the missiles typed into .missile fields */
  'destroy-missiles': (button) => { DestroyMissiles(button.dataset.url); },
  /** data-url: opened in a new window; the click itself carries on (a submit button still submits) */
  'open-url': (element) => { window.open(element.dataset.url, '_blank', 'noopener,noreferrer'); return true; },
  /** reloads the page */
  reload: () => { window.location.reload(); return false; },
  /** "back" button of message pages */
  'history-back': () => { window.history.back(); return false; },
  /** broken image: drop it */
  remove: (element) => element.remove(),
});

/**
 * Setup for markup that is already on the page (or was just loaded into a dialog): live character
 * counters start at the field's length, [data-auto-close="<ms>"] closes the topmost dialog after that time
 * (result messages of dialog forms).
 */
function initMarkup(root) {
  qsa('[data-on-keyup="char-count"]', root).forEach((field) => registry.get('char-count')(field));
  qsa('[data-auto-close]', root).forEach((element) => {
    window.setTimeout(() => Dialog.close(), Number(element.dataset.autoClose));
  });
}

/** brings back the filter button chosen last on this page (see filter-rows) */
function restoreRowFilters() {
  const groups = new Set(qsa('[data-action="filter-rows"][data-filter-group]').map((button) => button.dataset.filterGroup));
  groups.forEach((group) => {
    let stored = null;
    try { stored = localStorage.getItem(`filter:${window.location.pathname}:${group}`); } catch (e) { /* storage unavailable */ }
    const button = stored ? document.getElementById(stored) : null;
    if (button && button.dataset.filterGroup === group && !button.classList.contains('selected')) {
      registry.get('filter-rows')(button);
    }
  });
}

ready(() => { initMarkup(document); restoreRowFilters(); });
document.addEventListener('modal:load', (event) => initMarkup(event.detail.root));

/** collapse/expand the rows with class `target` ("hidden" class), the arrow ▲/▼ is #<target>-toggle */
export function toggleRows(target) {
  const rows = qsa(`.${target}`);
  if (rows.length === 0) return;
  const collapse = !rows[0].classList.contains('hidden');
  rows.forEach((row) => row.classList.toggle('hidden', collapse));
  const arrow = document.getElementById(`${target}-toggle`);
  if (arrow) arrow.textContent = collapse ? '▲' : '▼';
}
