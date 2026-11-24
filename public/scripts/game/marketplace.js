import { qs, qsa, on, now, pretty_number, getRestTimeFormat, setVisibleInterval, pageData } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { env } from '../app/env.js';

/**
 * Marketplace: reference ratios and the live ratio colouring, sortable offer tables, the resource /
 * fleet market switch, the flight column selector and the confirmed "buy" buttons. The confirmation
 * text comes from the data island #market-data ({confirmText}).
 */

/** Counts the remaining time of every offer row (`data-time` = seconds at render time). */
const refreshTimes = () => {
  qsa('[data-time]').forEach((el) => {
    const remaining = Number(el.dataset.time) - (now().getTime() - env.startTime) / 1000;
    el.textContent = getRestTimeFormat(remaining);
  });
};

/**
 * Number from a reference ratio input: tolerates spaces and, depending on the player's decimal separator, both comma and dot
 * notation. NaN when empty, not positive or not a number.
 */
const parseReferenceRatio = (value) => {
  if (typeof value === 'number') {
    return Number.isFinite(value) && value > 0 ? value : NaN;
  }
  let text = String(value ?? '').replace(/\s/g, '');
  if (text === '') {
    return NaN;
  }
  // decimalSeparator 0 is comma (same as pretty_number). A lone '.' stays a
  // decimal so machine-formatted data attributes still parse.
  const european = env.decimalSeparator !== undefined && String(env.decimalSeparator) === '0';
  if (european) {
    if (text.includes(',')) {
      text = text.replace(/\./g, '').replace(',', '.');
    }
  } else if (text.includes(',')) {
    text = text.replace(/,/g, '');
  }
  const ratio = parseFloat(text);
  return Number.isFinite(ratio) && ratio > 0 ? ratio : NaN;
};

const formatReferenceRatio = (value) => {
  const ratio = parseReferenceRatio(value);
  return Number.isFinite(ratio) ? pretty_number(ratio, 2) : value;
};

const officialReferenceRatios = () => {
  const box = qs('#ratioBaselines');
  return {
    metal: parseReferenceRatio(box?.dataset.metal),
    crystal: parseReferenceRatio(box?.dataset.crystal),
    deuterium: parseReferenceRatio(box?.dataset.deuterium),
  };
};

const inputReferenceRatios = () => ({
  metal: parseReferenceRatio(qs('input[name=ratio-metal]')?.value),
  crystal: parseReferenceRatio(qs('input[name=ratio-cristal]')?.value),
  deuterium: parseReferenceRatio(qs('input[name=ratio-deuterium]')?.value),
});

/** Official reference ratios divided by the chosen baseline resource (so the baseline becomes 1); null when the baseline ratio is invalid. */
const scaleOfficialReferenceTo = (resource) => {
  const official = officialReferenceRatios();
  const baseline = official[resource];
  if (!Number.isFinite(baseline) || baseline <= 0) {
    return null;
  }
  return {
    metal: official.metal / baseline,
    crystal: official.crystal / baseline,
    deuterium: official.deuterium / baseline,
  };
};

/** Writes the three ratios into their inputs (formatted). */
const setReferenceRatioInputs = (ratios) => {
  const set = (name, value) => {
    const input = qs(`input[name=${name}]`);
    if (input) input.value = formatReferenceRatio(value);
  };
  set('ratio-metal', ratios.metal);
  set('ratio-cristal', ratios.crystal);
  set('ratio-deuterium', ratios.deuterium);
};

let workingReferenceRatios = null;

const RATIO_STORAGE_KEY = 'market:ratios';

/** Remembers the reference ratios (and the chosen baseline) of this browser. */
const saveReferenceRatios = () => {
  const baseline = qs('.ratioBaseline.selected')?.dataset.resource ?? null;
  try {
    localStorage.setItem(RATIO_STORAGE_KEY, JSON.stringify({ baseline, ...inputReferenceRatios() }));
  } catch (e) { /* storage unavailable */ }
};

/** Puts the remembered baseline or own ratios back into the inputs. */
const restoreReferenceRatios = () => {
  let saved = null;
  try { saved = JSON.parse(localStorage.getItem(RATIO_STORAGE_KEY)); } catch (e) { /* ignore */ }
  if (!saved) return;
  if (saved.baseline && scaleOfficialReferenceTo(saved.baseline)) {
    normalizeReferenceTo(saved.baseline);
    return;
  }
  const ratios = { metal: saved.metal, crystal: saved.crystal, deuterium: saved.deuterium };
  if (![ratios.metal, ratios.crystal, ratios.deuterium].every((value) => Number.isFinite(value) && value > 0)) return;
  qsa('.ratioBaseline').forEach((el) => el.classList.remove('selected'));
  workingReferenceRatios = ratios;
  setReferenceRatioInputs(ratios);
};

/** Stock of the current planet from the resource bar (0 when not shown). */
const stockOf = (name) => Number(document.getElementById(`current_${name}`)?.dataset.real) || 0;

/**
 * Dims the offers whose price the current planet cannot pay (purely visual: the server decides on buying). The cost resource
 * ids come from the cell classes, 1 metal, 2 crystal, 3 deuterium.
 */
const markUnaffordable = () => {
  const names = { 1: 'metal', 2: 'crystal', 3: 'deuterium' };
  const hint = pageData('market-data').unaffordableText;
  qsa('table#tradeList tbody tr, table#tradeFleetList tbody tr').forEach((row) => {
    const amountCell = qs('.wanted-resource-amount', row);
    const typeCell = qs('[class*="wanted-resource-"][data-resource-id]', row);
    if (!amountCell || !typeCell || row.classList.contains('trade-disallowed')) return;
    const amount = parseInt(amountCell.textContent.replace(/\D/g, ''), 10) || 0;
    const short = amount > stockOf(names[typeCell.dataset.resourceId]);
    row.classList.toggle('trade-unaffordable', short);
    if (short) {
      row.setAttribute('aria-disabled', 'true');
      if (hint) row.title = hint;
    } else {
      row.removeAttribute('aria-disabled');
      row.removeAttribute('title');
    }
  });
};

/** Baseline button: sets the working ratios to the official ones expressed relative to `resource`, updates inputs and highlighting, repaints the offers. */
const normalizeReferenceTo = (resource) => {
  const ratios = scaleOfficialReferenceTo(resource);
  if (!ratios) {
    return;
  }
  workingReferenceRatios = ratios;
  setReferenceRatioInputs(ratios);
  qsa('.ratioBaseline').forEach((el) => el.classList.remove('selected'));
  qsa(`.ratioBaseline[data-resource="${resource}"]`).forEach((el) => el.classList.add('selected'));
  saveReferenceRatios();
  calculateRatios();
};

/** Ratios relative to deuterium (deuterium = 1); null when the deuterium ratio is invalid. */
const deuteriumRelativeRatios = (refs) => {
  if (!Number.isFinite(refs.deuterium) || refs.deuterium <= 0) {
    return null;
  }
  return {
    metal: refs.metal / refs.deuterium,
    crystal: refs.crystal / refs.deuterium,
    deuterium: 1,
  };
};

/** Value of an amount of the flagged resource kinds in deuterium, rounded up: metal and crystal are divided by their rate. */
const convertAmountToDeut = (amount, isMetal, isCrystal, isDeuterium, rates) => {
  let value = 0;
  if (isMetal) {
    value += amount / rates.metal;
  }
  if (isCrystal) {
    value += amount / rates.crystal;
  }
  if (isDeuterium) {
    value += amount;
  }
  return Math.ceil(value);
};

/** Official ratio of an offer row: offered deuterium value divided by the asked one (data attributes); 0 when not computable. */
const officialRowRatio = (ratioCell) => {
  const offerDeut = parseFloat(ratioCell.getAttribute('data-offer-deut'));
  const askDeut = parseFloat(ratioCell.getAttribute('data-ask-deut'));
  if (!Number.isFinite(offerDeut) || !Number.isFinite(askDeut) || askDeut <= 0) {
    return 0;
  }
  return offerDeut / askDeut;
};

/** Shows the ratio with two decimals, red below 1 (a bad deal for the buyer), green otherwise. */
const paintRatio = (ratioCell, ratio) => {
  ratioCell.textContent = pretty_number(ratio, 2);
  ratioCell.style.color = ratio < 1 ? '#F00' : '#0F0';
};

/** Colours the ratio column of the fleet market table. */
const paintFleetRatios = () => {
  qsa('table#tradeFleetList tbody tr').forEach((row) => {
    const ratioCell = qs('.ratio', row);
    if (ratioCell) paintRatio(ratioCell, officialRowRatio(ratioCell));
  });
};

/** Integer amount of an offer cell (digits only), 0 when empty. */
const cellAmount = (row, selector) => parseInt(qs(selector, row).innerHTML.replace(/\D/g, ''), 10) || 0;

/**
 * Recalculates the "ratio" column of the resource offers: with an official baseline selected from the rows' data attributes,
 * otherwise by converting offered and wanted amounts to deuterium with the player's own reference ratios. The fleet table is
 * repainted too. Does nothing when the reference ratios are invalid.
 */
const calculateRatios = () => {
  // Thanks to zb0oj for idea and a part of source code!
  paintFleetRatios();
  const useOfficial = qsa('.ratioBaseline.selected').length > 0;
  let rates = null;
  if (!useOfficial) {
    rates = deuteriumRelativeRatios(workingReferenceRatios || inputReferenceRatios());
    if (!rates || !Number.isFinite(rates.metal) || !Number.isFinite(rates.crystal)) {
      return;
    }
  }
  qsa('table#tradeList tbody tr')
    .filter((row) => !row.matches('.no-background.no-border.center'))
    .forEach((tradeOffer) => {
      const ratioCell = qs('.ratio', tradeOffer);
      if (!ratioCell) return;
      let ratio;
      if (useOfficial) {
        ratio = officialRowRatio(ratioCell);
      } else {
        const has = (selector) => qs(selector, tradeOffer) !== null;
        const offerDeut = convertAmountToDeut(
          cellAmount(tradeOffer, '.offered-resource-amount'),
          has('.offered-resource-1'),
          has('.offered-resource-2'),
          has('.offered-resource-3'),
          rates,
        );
        const costDeut = convertAmountToDeut(
          cellAmount(tradeOffer, '.wanted-resource-amount'),
          has('.wanted-resource-1'),
          has('.wanted-resource-2'),
          has('.wanted-resource-3'),
          rates,
        );
        ratio = costDeut > 0 ? offerDeut / costDeut : 0;
      }
      paintRatio(ratioCell, ratio);
    });
};

/**
 * Sort keys for the sortable tables: resource id and durations come from
 * data attributes, the shared table widget reads them from data-sort. Then the former default order is applied.
 */
const prepareSorting = () => {
  qsa('#tradeList, #resourceHistoryList, #tradeFleetList, #fleetHistoryList').forEach((table) => {
    qsa('td[data-resource-id], td[data-seconds], td[data-time]', table).forEach((cell) => {
      cell.dataset.sort = cell.dataset.resourceId ?? cell.dataset.seconds ?? cell.dataset.time;
    });
  });
  // [table id, column, descending]
  for (const [id, column, descending] of [['tradeList', 6, false], ['resourceHistoryList', 0, true], ['tradeFleetList', 6, false], ['fleetHistoryList', 0, true]]) {
    const table = document.getElementById(id);
    if (!table || !table.tBodies[0]?.rows.length) continue;
    const th = table.tHead.rows[0].cells[column];
    th.click();
    if (descending) th.click();
  }
};

/** shows the resource or the fleet market box, whichever switch is selected (the only one if just one exists) */
const reloadMarketBox = () => {
  const resBtn = document.getElementById('resourceMBtn');
  const fleetBtn = document.getElementById('fleetMBtn');
  const resSelected = resBtn?.classList.contains('selected');
  const fleetSelected = fleetBtn?.classList.contains('selected');

  document.getElementById('resourceMarketBox').hidden = !(resBtn && (resSelected || !fleetBtn));
  document.getElementById('fleetMarketBox').hidden = !(fleetBtn && (fleetSelected || !resBtn));
};

/** fleet market: the light / heavy cargo selector decides which flight column is shown */
const toggleFlightColumns = () => {
  const valueSelected = document.getElementById('shipT').value;
  const showLC = valueSelected == 1;
  const showHC = valueSelected == 2;
  if (showLC || showHC) {
    qsa('.HC').forEach((el) => { el.hidden = showLC; });
    qsa('.LC').forEach((el) => { el.hidden = !showLC; });
  }
};

/** Initial state of the resource/fleet box switch and the flight column selector. */
const initMarketBoxes = () => {
  reloadMarketBox();
  if (document.getElementById('shipT')) toggleFlightColumns();
};

window.onPageInit(() => {
  setVisibleInterval(refreshTimes, 1000);
  refreshTimes();

  for (const input of qsa('input[name=ratio-metal], input[name=ratio-cristal], input[name=ratio-deuterium]')) {
    input.addEventListener('input', () => {
      qsa('.ratioBaseline').forEach((el) => el.classList.remove('selected'));
      workingReferenceRatios = inputReferenceRatios();
      saveReferenceRatios();
      calculateRatios();
    });
    input.addEventListener('change', () => {
      input.value = formatReferenceRatio(input.value);
      workingReferenceRatios = inputReferenceRatios();
      saveReferenceRatios();
      calculateRatios();
    });
  }
  for (const button of qsa('.ratioBaseline')) {
    button.addEventListener('click', () => normalizeReferenceTo(button.dataset.resource));
  }
  restoreReferenceRatios();
  const selectedBaseline = qs('.ratioBaseline.selected')?.dataset.resource;
  const selectedRatios = selectedBaseline ? scaleOfficialReferenceTo(selectedBaseline) : null;
  if (selectedRatios) {
    workingReferenceRatios = selectedRatios;
  }
  calculateRatios();
  markUnaffordable();
  prepareSorting();
  initMarketBoxes();
});

on(document, 'click', '#resourceMBtn, #fleetMBtn', (event, button) => {
  qsa('.marketOption').forEach((el) => el.classList.remove('selected'));
  button.classList.add('selected');
  reloadMarketBox();
});

on(document, 'change', '#shipT', toggleFlightColumns);

/** buy button: asks, then submits its form with the chosen ship type (#shipT) as hidden field */
on(document, 'click', '.market_buy_button', async (event, button) => {
  if (!(await Dialog.confirm(pageData('market-data').confirmText))) return;
  const form = button.closest('form');
  form.querySelectorAll('input[name="shipType"]').forEach((el) => el.remove());
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = 'shipType';
  hidden.value = document.getElementById('shipT').value;
  form.append(hidden);
  form.submit();
});
