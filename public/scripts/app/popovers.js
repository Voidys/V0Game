/**
 * Native popovers ([popover] with a [popovertarget] invoker): positioned below their invoker, aligned to its right
 * edge. Browsers with CSS anchor positioning get unique anchor names set from here (overlays.css does the rest);
 * the others get top/left measured and set on click, beforetoggle and toggle.
 */
const CSS_ANCHORS = typeof CSS !== 'undefined' && CSS.supports('anchor-name: --a');
let counter = 0;

const invokersOf = (pop) => (pop.id ? [...document.querySelectorAll(`[popovertarget="${CSS.escape(pop.id)}"]`)] : []);

/** JS fallback: place the opened popover under its invoker, clamped to the viewport. */
function placePopover(pop, invoker) {
  if (CSS_ANCHORS) return;
  const button = invoker ?? pop.__invoker ?? invokersOf(pop)[0];
  if (!button) return;
  pop.__invoker = button;
  const rect = button.getBoundingClientRect();
  const width = pop.offsetWidth || parseFloat(getComputedStyle(pop).width) || 0;
  const viewport = document.documentElement.clientWidth;
  pop.style.top = `${Math.round(rect.bottom + 8)}px`;
  pop.style.left = `${Math.round(Math.max(8, Math.min(rect.right - width, viewport - width - 8)))}px`;
  pop.style.right = 'auto';
  pop.style.bottom = 'auto';
}

/** Sets the anchor names of one popover and its invokers (idempotent). */
function preparePopover(pop) {
  if (pop.dataset.popReady) return;
  const invokers = invokersOf(pop);
  if (invokers.length === 0) return;
  pop.dataset.popReady = '1';
  invokers.forEach((button) => {
    const name = `--pop-${++counter}`;
    button.dataset.popAnchor = name;
    if (CSS_ANCHORS) button.style.setProperty('anchor-name', name);
    // several invokers: the popover follows the one that was used last
    button.addEventListener('click', () => {
      if (CSS_ANCHORS) pop.style.setProperty('position-anchor', name); else placePopover(pop, button);
    }, { capture: true });
  });
  if (CSS_ANCHORS) pop.style.setProperty('position-anchor', invokers[0].dataset.popAnchor);
  pop.addEventListener('beforetoggle', (event) => { if (event.newState === 'open') placePopover(pop); });
  pop.addEventListener('toggle', (event) => { if (event.newState === 'open') placePopover(pop); });
}

/** Prepares every popover on the page (also those added later) and keeps fallback placement on resize. */
export function initPopovers() {
  const scan = () => document.querySelectorAll('[popover]').forEach(preparePopover);
  scan();
  let queued = false;
  new MutationObserver((records) => {
    if (queued || !records.some((r) => [...r.addedNodes].some((n) => n instanceof Element))) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; scan(); });
  }).observe(document.body, { childList: true, subtree: true });
  window.addEventListener('resize', () => {
    try { document.querySelectorAll('[popover]:popover-open').forEach((pop) => placePopover(pop)); } catch { /* no :popover-open */ }
  });
}
