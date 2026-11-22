/**
 * Live resource counters of the top bar (formerly topnav.js). The layout lists the resources in the data
 * island #resource-tickers ({tickers: [{available, limit, production, valueElem}]}), initResourceTickers()
 * starts one ticker per entry; elements are matched by data-ticker="current_<name>" (or the legacy #id).
 */
import { qsa, now, pretty_number, shortly_number, setVisibleInterval, pageData, getRestTimeFormat } from './core.js';

/** Full number below 1e9, from 1e9 on billions with three decimals and the language's billion suffix (numberSuffixes[3]); same rule as the Twig millions_number(). */
function millionsNumber(amount) {
  if (Math.abs(amount) < 1e9) return pretty_number(amount);
  const suffix = Array.isArray(window.numberSuffixes) && window.numberSuffixes[3] ? window.numberSuffixes[3] : 'B';
  return `${pretty_number(Math.floor(amount / 1e6) / 1000, 3)}&nbsp;${suffix}`;
}

/**
 * Refreshes the tooltip HTML of a resource (`[data-ticker-tip]`): the live amount (`.res_current`, skipped when `amount` is
 * null), and the "full in" row (`.res_full`): time until the storage limit at the current production, or the full text
 * (the element's data-full-text) once it is reached.
 */
function updateTooltip(element, amount, full, limit, production) {
  const holder = document.createElement('div');
  holder.innerHTML = element.getAttribute('data-tooltip-content') ?? '';
  const current = holder.querySelector('.res_current');
  if (current) {
    if (amount !== null) current.textContent = pretty_number(amount);
    current.classList.toggle('colorNegative', full);
  }
  const row = holder.querySelector('.res_full');
  if (row && limit > 0 && production > 0) {
    if (full) {
      row.textContent = element.dataset.fullText ?? '';
    } else if (amount !== null) {
      const value = row.querySelector('.res_full_val');
      if (value) value.textContent = getRestTimeFormat((limit - amount) / (production / 3600), true);
    }
  }
  element.setAttribute('data-tooltip-content', holder.innerHTML);
}

/**
 * Updates the displayed amount of one resource from absolute time: available + production/3600 * seconds since the page
 * was rendered, never negative. Marks the value with `res_current_warn` from 90 % of the storage limit and
 * `res_current_max` / `colorNegative` once the limit is reached (then the amount stops counting). DOM only, no network.
 * @param {{valueElem: string, available: number|string, production: number|string, limit: Array}} config
 *        `production` is per hour, `limit[1]` the storage capacity
 * @param {boolean} [init] true on the first call: also starts the 1 s refresh interval
 */
export function resourceTicker(config, init) {
  if (init === true) {
    setVisibleInterval(() => resourceTicker(config), 1000);
  }

  let elements = qsa(`[data-ticker="${config.valueElem}"]`);
  if (!elements.length) {
    elements = qsa(`#${CSS.escape(config.valueElem)}`);
  }

  const seconds = (now().getTime() - (window.startTime ?? now().getTime())) / 1000;
  const amount = Math.max(0, Math.floor(parseFloat(config.available) + parseFloat(config.production) / 3600 * seconds));
  const limit = config.limit[1];

  // fill bar under the value (top bar): share of the storage capacity
  const bar = document.querySelector(`[data-ticker-bar="${config.valueElem}"]`);
  if (bar && limit > 0) {
    const fill = Math.min(100, amount / limit * 100);
    bar.style.setProperty('--fill', `${fill.toFixed(1)}%`);
    bar.classList.toggle('is-warn', fill >= 90 && fill < 100);
    bar.classList.toggle('is-full', fill >= 100);
  }

  for (const element of elements) {
    if (element.classList.contains('res_current_max')) continue;
    if (amount < limit) {
      if (amount >= limit * 0.9) element.classList.add('res_current_warn');
      if (window.viewShortlyNumber || element.classList.contains('resource__value--short')) {
        element.innerHTML = shortly_number(amount, true);
      } else {
        element.innerHTML = millionsNumber(amount);
      }
    } else {
      element.classList.add('colorNegative', 'res_current_max');
    }
  }

  const full = limit > 0 && amount >= limit;
  for (const tip of qsa(`[data-ticker-tip="${config.valueElem}"]`)) {
    updateTooltip(tip, full ? null : amount, full, limit, parseFloat(config.production));
  }
}

/** starts the tickers listed in the #resource-tickers island (none in vacation mode) */
export function initResourceTickers() {
  for (const config of pageData('resource-tickers').tickers ?? []) {
    resourceTicker(config, true);
  }
}

/** current amount of a resource as rendered (data-real of #current_<name>) */
export function getRessource(name) {
  return parseInt(document.getElementById(`current_${name}`)?.dataset.real ?? '0', 10);
}
