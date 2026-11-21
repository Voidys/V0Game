import { qs, qsa, now, getRestTimeFormat, setVisibleInterval } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Overview page: live countdowns (fleet movements `.fleets`, building/research `.timer`, planet repair `.timerrepair`,
 * short form `.timershort`) and the event block toggle. Countdown elements carry the remaining
 * seconds at render time in data attributes; in vacation mode (`.timer[data-umode=1]`) they stay frozen.
 */
const elapsed = () => (now().getTime() - env.startTime) / 1000;

/** Remaining seconds of an element (data-time): absolute since page render, frozen in vacation mode. */
function remaining(el, umode) {
  const time = Number(el.dataset.time);
  return umode ? time : time - elapsed();
}

/** Accordions (<details class="accordion" data-key>): the open/closed choice is kept in localStorage (default open, data-default="closed" starts folded); data-force-open="1" (hostile fleet) keeps one open. */
function initAccordions() {
  document.querySelectorAll('details.accordion[data-key]').forEach((details) => {
    const key = 'accordion:' + details.dataset.key;
    const forced = details.dataset.forceOpen === '1';
    const closedByDefault = details.dataset.default === 'closed';
    try {
      const stored = localStorage.getItem(key);
      if (forced) details.open = true;
      else if (stored === 'closed') details.open = false;
      else if (stored === 'open') details.open = true;
      else details.open = !closedByDefault;
    } catch (e) { details.open = forced || !closedByDefault; }
    details.addEventListener('toggle', () => {
      if (forced) { details.open = true; return; }
      try { localStorage.setItem(key, details.open ? 'open' : 'closed'); } catch (e) { /* ignore */ }
    });
  });
}

/** Starts the two 1 s intervals and the accordions. A finished repair countdown restarts with the 3-day (259200 s) cycle and switches the label to its alternative text. */
function init() {
  const umode = qs('.timer')?.dataset.umode == 1;

  setVisibleInterval(() => {
    qsa('.fleets').forEach((el) => {
      const s = Number(el.dataset.fleetTime) - elapsed();
      el.textContent = s <= 0 ? '-' : getRestTimeFormat(s);
    });
  }, 1000);

  setVisibleInterval(() => {
    qsa('.timer').forEach((el) => {
      el.textContent = getRestTimeFormat(Math.max(0, remaining(el, umode)));
    });

    qsa('.timerrepair').forEach((el) => {
      let s = remaining(el, umode);
      if (s <= 0) {
        if (el.dataset.repairing === 'true' || el.dataset.repairing === '1') {
          el.dataset.repairing = 'false';
          el.dataset.time = Number(el.dataset.time) + 259200;
          const text = document.getElementById('repairtext');
          if (text) text.textContent = text.dataset.alttext;
          s = remaining(el, umode);
        } else {
          s = 0;
        }
      }
      el.textContent = getRestTimeFormat(Math.max(0, s));
    });

    qsa('.timershort').forEach((el) => {
      el.textContent = getRestTimeFormat(Math.max(0, remaining(el, umode)), true);
    });
  }, 1000);

  initAccordions();
}

window.onPageInit(init);
