/**
 * Admin area behaviour (native module, no jQuery). The shared UI modules (dialog, tooltips, timers,
 * sortable tables, sidebar drawer) are booted by ../app/main.js; this adds:
 *
 *   [data-menu-filter]      filters the side navigation as you type; "/" focuses it, Enter opens the
 *                           first match, Esc clears it
 *   [data-admin-universe]   switches the emulated universe and reloads the current page with ?uni=
 *   [data-confirm]          question before a link / submit button acts, asked in the themed modal
 *                           (Dialog.confirm via app/actions.js; an empty value is replaced by the generic admin
 *                           question); [data-confirm-danger] styles its OK button as destructive
 *   form[data-track-dirty]  marks the form while it has unsaved changes and warns before leaving
 *   [data-table-filter]     input that filters the rows of the table given by its value (a selector)
 *   [data-open-edit]        opens the quick editor in the dialog (data-edit-url, the quick editor route of the player or planet)
 *   [data-autosubmit]       submits its form when the control changes
 *   [data-set-checked]      button: sets every checkbox matching the selector to data-checked ("1"/"0")
 *   [data-check-all]        checkbox: mirrors its state onto all other checkboxes of its form
 *   [data-count-chars]      textarea: writes its length (or, with data-count-max, the characters left)
 *                           into the element given by the selector value
 *   [data-toggle-rows]      checkbox: shows/hides the elements matching the selector (class "hidden")
 *   [data-reveal]           link: reveals (and focuses) the element given by the selector, hides itself
 *   select[data-filter-select] and friends: see filterSelect.js
 *   form[data-ajax-dialog]  background POST with the answer in the dialog: see ajaxForm.js
 */
import { Dialog } from '../app/dialog.js';
import { initFilterSelect } from './filterSelect.js';
import { initAjaxForms } from './ajaxForm.js';

const confirmText = () => window.confirmText || '';

/**
 * Side navigation filter ([data-menu-filter]): hides entries that match neither their label nor their `data-keywords`
 * (tab names), highlights the match, hides empty groups and shows the "no result" line. Enter opens the first match, Esc
 * clears; "/" anywhere (not while typing) opens the sidebar drawer and focuses the field.
 */
function menuFilter() {
  const input = document.querySelector('[data-menu-filter]');
  if (!input) return;
  const links = [...document.querySelectorAll('.admin-nav__link')];
  const groups = [...document.querySelectorAll('.admin-nav__group')];
  const empty = document.querySelector('.admin-nav__empty');
  const labels = new Map(links.map((link) => [link, link.querySelector('.admin-nav__label')]));
  const original = new Map(links.map((link) => [link, labels.get(link).textContent]));

  const apply = () => {
    const term = input.value.trim().toLowerCase();
    let visible = 0;
    links.forEach((link) => {
      const text = original.get(link);
      const index = term ? text.toLowerCase().indexOf(term) : -1;
      // an entry also matches by the names of its tabs (e.g. "moon" finds the players entry)
      const match = !term || index !== -1 || (link.dataset.keywords || '').toLowerCase().includes(term);
      link.parentElement.hidden = !match;
      const label = labels.get(link);
      label.textContent = text;
      if (term && index !== -1) {
        const mark = document.createElement('mark');
        mark.textContent = text.slice(index, index + term.length);
        label.replaceChildren(text.slice(0, index), mark, text.slice(index + term.length));
      }
      if (match) visible += 1;
    });
    groups.forEach((group) => { group.hidden = !group.querySelector('li:not([hidden])'); });
    if (empty) empty.hidden = visible > 0;
  };

  input.addEventListener('input', apply);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      const first = links.find((link) => !link.parentElement.hidden);
      if (first) first.click();
    } else if (event.key === 'Escape') {
      input.value = '';
      apply();
      input.blur();
    }
  });
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      document.body.classList.add('sidebar-open');
      input.focus();
    }
  });
}

/** The universe selector reloads the current admin page with `?uni=<value>` (the emulated universe). */
function universeSwitch() {
  const select = document.querySelector('[data-admin-universe]');
  if (!select) return;
  select.addEventListener('change', () => {
    const url = new URL(window.location.href);
    url.searchParams.set('uni', select.value);
    window.location.href = url.toString();
  });
}

/**
 * Confirmation questions are asked by ../app/actions.js in the themed modal (data-confirm on a link or
 * button, text = the attribute; data-confirm-danger = destructive OK button); an empty attribute means the generic admin question, which is filled in just before the
 * click reaches it. Put data-confirm on the submit button, not on the form.
 */
function confirmations() {
  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-confirm=""]') : null;
    if (trigger) trigger.dataset.confirm = confirmText();
  }, true);
}

/** Marks `form[data-track-dirty]` as `form-dirty` on the first edit and asks the browser to confirm leaving the page, unless a form is being submitted. */
function dirtyForms() {
  const forms = [...document.querySelectorAll('form[data-track-dirty]')];
  if (!forms.length) return;
  let submitting = false;
  forms.forEach((form) => {
    const mark = () => form.classList.add('form-dirty');
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', () => { submitting = true; });
  });
  window.addEventListener('beforeunload', (event) => {
    if (!submitting && forms.some((form) => form.classList.contains('form-dirty'))) {
      event.preventDefault();
    }
  });
}

/** `[data-table-filter=<selector>]`: live filter of the table's body rows by their text (case-insensitive substring). */
function tableFilters() {
  document.querySelectorAll('[data-table-filter]').forEach((input) => {
    const table = document.querySelector(input.dataset.tableFilter);
    if (!table) return;
    const rows = [...table.tBodies].flatMap((body) => [...body.rows]);
    input.addEventListener('input', () => {
      const term = input.value.trim().toLowerCase();
      rows.forEach((row) => { row.hidden = term !== '' && !row.textContent.toLowerCase().includes(term); });
    });
  });
}

/** quick editor of a player or planet in the shared modal dialog */
function openEdit() {
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-open-edit]') : null;
    if (!button) return;
    event.preventDefault();
    Dialog.open(button.dataset.editUrl, 880, 640);
  });
}

/** small declarative behaviours of forms (see the list in the file header) */
function formHelpers() {
  document.addEventListener('change', (event) => {
    const control = event.target;
    if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement)) return;
    if (control.hasAttribute('data-autosubmit')) control.form.submit();
    if (control instanceof HTMLInputElement && control.hasAttribute('data-check-all')) {
      control.form.querySelectorAll('input[type=checkbox]').forEach((box) => { box.checked = control.checked; });
    }
    if (control instanceof HTMLInputElement && control.dataset.toggleRows) {
      const rows = document.querySelectorAll(control.dataset.toggleRows);
      if (rows.length) {
        const hide = !rows[0].classList.contains('hidden');
        rows.forEach((row) => row.classList.toggle('hidden', hide));
      }
    }
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const setter = target?.closest('[data-set-checked]');
    if (setter) {
      document.querySelectorAll(setter.dataset.setChecked).forEach((box) => { box.checked = setter.dataset.checked === '1'; });
    }
    const reveal = target?.closest('[data-reveal]');
    if (reveal) {
      event.preventDefault();
      const field = document.querySelector(reveal.dataset.reveal);
      if (field) {
        field.hidden = false;
        field.focus();
      }
      reveal.hidden = true;
    }
  });

  document.querySelectorAll('textarea[data-count-chars]').forEach((area) => {
    const out = document.querySelector(area.dataset.countChars);
    const max = Number(area.dataset.countMax ?? 0);
    area.addEventListener('input', () => { if (out) out.textContent = max ? max - area.value.length : area.value.length; });
  });
}

/** Phones: tab strips scroll sideways; bring the current tab into view (sideways only, the page does not move). */
function tabsIntoView() {
  document.querySelectorAll('.tabs, .page-tabs').forEach((strip) => {
    const current = strip.querySelector('[aria-current="page"]');
    if (!current || strip.scrollWidth <= strip.clientWidth) return;
    strip.scrollLeft = current.offsetLeft - (strip.clientWidth - current.offsetWidth) / 2;
  });
}

/** Starts every behaviour of this file and of filterSelect.js / ajaxForm.js. */
const init = () => {
  menuFilter();
  universeSwitch();
  confirmations();
  dirtyForms();
  tableFilters();
  openEdit();
  formHelpers();
  tabsIntoView();
  initFilterSelect();
  initAjaxForms();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
