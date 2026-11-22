/**
 * Generic tabs (ARIA tablist): every `[data-tabs]` root holds `.tabs__tab` buttons (data-tab="key", aria-controls)
 * and `.tabs__panel` sections (data-tab="key"). Click or Arrow Left/Right/Home/End switches; the last tab is
 * remembered per root in localStorage under `tabs:<data-tabs value>`, and a URL hash `#tab-<key>` wins over it.
 * Panels without JS stay readable: the template renders all panels, the first one visible.
 */
import { ready } from './core.js';

function select(root, key, focus) {
  for (const tab of root.querySelectorAll('.tabs__tab')) {
    const active = tab.dataset.tab === key;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
    if (active) {
      if (focus) tab.focus();
      if (tab.parentElement && tab.parentElement.scrollWidth > tab.parentElement.clientWidth) {
        tab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
      }
    }
  }
  for (const panel of root.querySelectorAll('.tabs__panel')) {
    panel.hidden = panel.dataset.tab !== key;
  }
  try { localStorage.setItem(`tabs:${root.dataset.tabs}`, key); } catch { /* storage unavailable */ }
}

function init(root) {
  const tabs = [...root.querySelectorAll('.tabs__tab')];
  if (!tabs.length) return;
  const keys = tabs.map((tab) => tab.dataset.tab);
  let start = keys[0];
  try {
    const saved = localStorage.getItem(`tabs:${root.dataset.tabs}`);
    if (keys.includes(saved)) start = saved;
  } catch { /* storage unavailable */ }
  const hash = location.hash.startsWith('#tab-') ? location.hash.slice(5) : '';
  if (keys.includes(hash)) start = hash;
  select(root, start, false);

  root.addEventListener('click', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('.tabs__tab') : null;
    if (tab && root.contains(tab)) select(root, tab.dataset.tab, false);
  });
  root.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('.tabs__tab') : null;
    if (!tab) return;
    const i = tabs.indexOf(tab);
    const target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[event.key];
    if (target === undefined) return;
    select(root, tabs[(target + tabs.length) % tabs.length].dataset.tab, true);
    event.preventDefault();
  });
}

ready(() => document.querySelectorAll('[data-tabs]').forEach(init));
