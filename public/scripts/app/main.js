/**
 * Entry module of the game UI. Loads the native core (no jQuery), starts the page-wide widgets and
 * runs the per-page init code that templates register with window.onPageInit(fn).
 *
 * Page scripts are ES modules too (loaded after this one, in document order) and import what they
 * need from ./core.js, ./dialog.js, ./ui.js, ...; behaviour that markup triggers (data-action="…") is
 * registered with registerActions() from ./actions.js, template data arrives as a JSON data island
 * (pageData() in ./core.js). There are no inline handlers or inline scripts (Content-Security-Policy).
 */
import './csrf.js';
import './consent.js';
import './anchors.js';
import './core.js';
import './env.js';
import './dialog.js';
import './actions.js';
import { initTooltips } from './tooltip.js';
import { initTimers } from './timers.js';
import { initResourceTickers } from './ticker.js';
import { initUi } from './ui.js';
import { initPopovers } from './popovers.js';
import { initPalette } from './palette.js';
import { initShortcuts } from './shortcuts.js';

const queue = [];

/** run fn once the DOM is ready and all modules are loaded */
window.onPageInit = (fn) => {
  if (queue.started) {
    fn();
  } else {
    queue.push(fn);
  }
};

// DOMContentLoaded fires after all deferred page modules ran (ready() would fire too early here,
// the document is already "interactive" while modules execute)
const boot = () => {
  initTooltips();
  initTimers();
  initUi();
  initPopovers();
  initPalette();
  initShortcuts();
  initResourceTickers();
  queue.started = true;
  for (const fn of queue.splice(0)) {
    try {
      fn();
    } catch (error) {
      console.error(error);
    }
  }
};

if (document.readyState === 'complete') {
  boot();
} else {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
}
