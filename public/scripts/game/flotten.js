import { qsa, on, getJSON, post, serialize, pretty_number, dezInt, pad, getFormatedDate, setVisibleInterval, NotifyBox, now, show, hide, pageData } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { registerActions } from '../app/actions.js';
import { env } from '../app/env.js';
import { getRessource } from '../app/ticker.js';

/**
 * Fleet dispatch, steps 1 and 2 (target and speed, then mission and cargo) and the ship selection of the
 * fleet table. The server's numbers arrive in the data island #fleet-data (templates page.fleetStep1/
 * fleetStep2): {step, data (fleet), honorboost, config, token, questionList, tradeMarket, targetMission}.
 * The controls are data-action / data-on-* markup (see app/actions.js). Changing ship amounts is announced
 * with the document event "fleet:amounts" (the fleet table shows the sums).
 */
const fleetPage = pageData('fleet-data');
const { data, honorboost, config, token, questionList = {}, tradeMarket, urls = {}, texts = {} } = fleetPage;

/** First form control with this name (the page has several fields of a name only in trade mode, where the first one counts). */
const field = (name) => document.getElementsByName(name)[0];

let dataFlyDistance = 0;
let dataFlyTime = 0;
let dataFlyConsumption = 0;
let dataFlyCargoSpace = 0;

/** Recomputes distance, duration, fuel and free cargo space after the target or speed changed and refreshes the form display. Resets the ACS group unless `resetAcs` is false. Targets beyond the last planet slot (expedition position) force the planet type to 1. */
const updateVars = (resetAcs = true) => {
  if (resetAcs) {
    field('group').value = 0;
  }
  dataFlyDistance = getDistance();
  dataFlyTime = getDuration();
  dataFlyConsumption = getConsumption();
  dataFlyCargoSpace = storage();
  validateTarget();
  if (config.max_planet < field('planet').value) {
    field('type').value = 1;
    field('type').disabled = true;
  } else {
    field('type').disabled = false;
  }
  refreshFormData();
};

/** Distance along a ring (linear distance below half of `max`, else the way around), in steps. */
const ringSteps = (from, to, max) => {
  const diff = Math.abs(from - to);
  return diff < Math.ceil(max / 2) ? diff : Math.floor(max / 2) - (diff - Math.ceil(max / 2));
};

/**
 * Calculates the distance to the target
 *
 * Changes needs to be done in src/Game/Core/FleetMath.php getTargetDistance() as well (and the preview in admin/pages/configUni.js)
 *
 * @returns int
 */
const getDistance = () => {
  const thisGalaxy = Number(data.planet.galaxy);
  const thisSystem = Number(data.planet.system);
  const thisPlanet = Number(data.planet.planet);
  const targetGalaxy = Number(field('galaxy').value);
  const targetSystem = Number(field('system').value);
  const targetPlanet = Number(field('planet').value);

  if (thisGalaxy !== targetGalaxy) {
    if (config.uni_type == 2) {
      return config.galaxy_distance_factor;
    }
    if (config.uni_type == 1) {
      return ringSteps(thisGalaxy, targetGalaxy, Number(config.max_galaxy)) * config.galaxy_distance_factor;
    }
    return Math.abs(thisGalaxy - targetGalaxy) * config.galaxy_distance_factor;
  }
  if (thisSystem !== targetSystem) {
    if (config.galaxy_type == 2) {
      return config.system_distance_factor + config.system_distance;
    }
    if (config.galaxy_type == 1) {
      return ringSteps(thisSystem, targetSystem, Number(config.max_system)) * config.system_distance_factor + config.system_distance;
    }
    return Math.abs(thisSystem - targetSystem) * config.system_distance_factor + config.system_distance;
  }
  if (thisPlanet !== targetPlanet) {
    return Math.abs(thisPlanet - targetPlanet) * config.planet_distance_factor + config.planet_distance;
  }
  return 5;
};

/**
 * Unrounded travel time, including fleetSpeedFactor.
 * Floored by getDuration(); the floored seconds feed the deuterium preview, like FleetMath::missionDurationFor().
 * Keep in sync with FleetMath::getMissionDuration().
 */
const getRawDuration = () => {
  const sp = Number(field('speed').value);
  const duration = ((3500 / (sp * 0.1) * Math.pow(dataFlyDistance * 10 / data.maxspeed, 0.5) + 10) / data.gamespeed) * data.fleetspeedfactor;
  return Math.max(duration, data.fleetMinDuration);
};

/**
 * Whole seconds shown in the UI and stored at send.
 * Floor after the speed factor matches FleetDispatch: floor($duration) + request time.
 * Keep in sync with FleetMath::getMissionDuration() and admin/pages/configUni.js (durationFor()).
 */
const getDuration = () => Math.floor(getRawDuration());

/** Deuterium needed for the whole fleet (sum per ship type from base consumption, distance and flight speed, times the honor boost, +1), same formula as the server. */
const getConsumption = () => {
  let consumption = 0;
  for (const ship of Object.values(data.ships)) {
    const spd = 35000 / Math.max(dataFlyTime * data.gamespeed - 10, 1) * Math.sqrt(dataFlyDistance * 10 / ship.speed);
    const basicConsumption = ship.consumption * ship.amount;
    consumption += basicConsumption * dataFlyDistance / 35000 * (spd / 10 + 1) * (spd / 10 + 1);
  }
  return Math.round(consumption * honorboost) + 1;
};

/** Free cargo space after the fuel is loaded (negative when the fleet cannot carry its own fuel). */
const storage = () => data.fleetroom - dataFlyConsumption;

/** Writes duration (h:mm:ss), distance, max speed, fuel and free cargo into the step 1 form; fuel and cargo are red when the cargo space is negative. */
const refreshFormData = () => {
  const hours = Math.floor(dataFlyTime / 3600);
  const minutes = Math.floor((dataFlyTime - hours * 3600) / 60);
  const seconds = dataFlyTime - hours * 3600 - minutes * 60;
  document.getElementById('duration').textContent = `${hours}:${dezInt(minutes, 2)}:${dezInt(seconds, 2)}`;
  document.getElementById('distance').textContent = pretty_number(dataFlyDistance);
  document.getElementById('maxspeed').textContent = pretty_number(data.maxspeed);
  const signal = dataFlyCargoSpace >= 0 ? 'colorPositive' : 'colorNegative';
  setSignal(document.getElementById('consumption'), pretty_number(dataFlyConsumption), signal);
  setSignal(document.getElementById('storage'), pretty_number(dataFlyCargoSpace), signal);
  refreshSummary();
};

/** Puts `text` into the element as a span with the theme's positive / negative signal colour (colorPositive / colorNegative). */
const setSignal = (element, text, signal) => {
  const span = document.createElement('span');
  span.className = signal;
  span.textContent = text;
  element.replaceChildren(span);
};

const summary = (id) => document.getElementById(`sum-${id}`);

/** Keeps the summary bar (target, fuel, duration) in step with the form of the destination step. */
const refreshSummary = () => {
  if (!summary('target') || fleetPage.step !== 1) {
    return;
  }
  const type = field('type');
  summary('target').textContent = `[${field('galaxy').value}:${field('system').value}:${field('planet').value}] ${type.selectedOptions[0]?.textContent ?? ''}`;
  summary('fuel').textContent = pretty_number(dataFlyConsumption);
  summary('duration').textContent = document.getElementById('duration').textContent;
};

/** Return time (round trip, without a stay time) in the summary bar. */
const setSummaryReturn = (text) => {
  if (summary('return')) {
    summary('return').textContent = text;
  }
};

/** Arrival time in the summary bar. */
const setSummaryArrival = (text) => {
  if (summary('eta')) {
    summary('eta').textContent = text;
  }
};

/**
 * Marks coordinates outside the universe (red field, message under the row) before the player submits; true when the target is valid.
 * Positions beyond the last planet slot are the expedition and marketplace slots (and the pirate base when its link is offered).
 */
const validateTarget = () => {
  const error = document.getElementById('target-error');
  if (!error) {
    return true;
  }
  const maxPosition = Number(config.max_planets) + (document.querySelector(`[data-action="set-target"][data-planet="${Number(config.max_planets) + 3}"]`) ? 3 : 2);
  const limits = { galaxy: Number(config.max_galaxy), system: Number(config.max_system), planet: maxPosition };
  let valid = true;
  for (const [name, max] of Object.entries(limits)) {
    const value = Number(field(name).value);
    const bad = !Number.isInteger(value) || value < 1 || value > max;
    field(name).setAttribute('aria-invalid', bad ? 'true' : 'false');
    valid = valid && !bad;
  }
  error.hidden = valid;
  error.textContent = valid ? '' : String(texts.coordRange ?? '').replace('%d', limits.galaxy).replace('%d', limits.system).replace('%d', limits.planet);
  return valid;
};

/** Writes target coordinates and planet type (1 planet, 2 debris field, 3 moon) into the form. */
const setTarget = (galaxy, solarsystem, planet, type) => {
  field('galaxy').value = galaxy;
  field('system').value = solarsystem;
  field('planet').value = planet;
  field('type').value = type;
};

/** Takes the target of an ACS group (fleet union) and remembers its id in the form. */
const setACSTarget = (galaxy, solarsystem, planet, type, tacs) => {
  setTarget(galaxy, solarsystem, planet, type);
  updateVars();
  field('group').value = tacs;
};

/** Shows arrival and return time of the planned flight (now + duration, return = twice the duration without a stay). */
const fleetTime = () => {
  const endtime = dataFlyTime + dataFlyTime;
  document.getElementById('arrival').innerHTML = getFormatedDate(now().getTime() + 1000 * dataFlyTime, env.tdformat);
  document.getElementById('return').innerHTML = getFormatedDate(now().getTime() + 1000 * endtime, env.tdformat);
  setSummaryArrival(document.getElementById('arrival').textContent);
};

/** Integer of a cargo field (NaN when empty). */
const cargoAmount = (name) => parseInt(field(name).value);

/** "Max" for one resource in step 2: fills the field with as much as the free cargo space and the planet's stock allow (deuterium minus the fuel). */
const maxResource = (id) => {
  const chosen = parseInt(field(id).value) || 0;
  let stock = getRessource(id) || 0;

  const storCap = data.fleetroom - data.consumption;

  if (id === 'deuterium') {
    stock -= data.consumption;
  }
  const loaded = ['metal', 'crystal', 'deuterium'].reduce((sum, name) => sum + (cargoAmount(name) || 0), 0);
  const freeCapacity = Math.max(storCap - loaded, 0);
  field(id).value = Math.min(freeCapacity + chosen, stock);
  calculateTransportCapacity();
};

const maxResources = () => {
  maxResource('metal');
  maxResource('crystal');
  maxResource('deuterium');
};

/** Applies the resources preselected by the previous page (`#selectedres` data: metal, crystal, deuterium, reserve), capped by the planet's stock. */
const selectedResources = () => {
  const amt = document.getElementById('selectedres').getAttribute('data').split(',');
  const wanted = (index) => parseInt(amt[index]);
  const stock = (name) => parseInt(document.getElementById(`current_${name}`).getAttribute('data-real'));

  field('metal').value = Number.isNaN(wanted(0)) ? 0 : Math.min(wanted(0), stock('metal'));
  field('crystal').value = Number.isNaN(wanted(1)) ? 0 : Math.min(wanted(1), stock('crystal'));
  field('deuterium').value = Number.isNaN(wanted(2)) ? 0 : Math.min(wanted(2), stock('deuterium'), stock('deuterium') - wanted(3));
  calculateTransportCapacity();
  applySelectedResourcesToTrade();
};

/** Shows the cargo space left after fuel and the typed resources (red when overloaded), updates the trade ratio and returns the value. */
/** Missions the dispatch checks against the noob protection rules (attack, ACS, hold, spy, destruction). */
const PROTECTED_MISSIONS = [1, 2, 5, 6, 9];

/** Step 2: disables Send with an inline notice when the dispatch would be refused anyway (protected target for a hostile mission, a transport without cargo). */
const updateSendGate = () => {
  const radio = document.querySelector('input[name="mission"]:checked');
  const button = document.querySelector('.btn-send');
  const notice = document.getElementById('send-notice');
  if (fleetPage.step !== 2 || !radio || !button || !notice) return;
  const mission = Number(radio.value);
  let message = '';
  if (fleetPage.protection && PROTECTED_MISSIONS.includes(mission)) {
    message = fleetPage.protection;
  } else if (mission === 3 && ['metal', 'crystal', 'deuterium'].every((name) => !(Number(field(name)?.value) > 0))) {
    message = texts.noCargo;
  }
  button.disabled = message !== '';
  notice.textContent = message;
  notice.hidden = message === '';
};

const calculateTransportCapacity = () => {
  updateSendGate();
  const loaded = ['metal', 'crystal', 'deuterium'].reduce((sum, name) => sum + Math.abs(field(name).value), 0);
  const transportCapacity = data.fleetroom - data.consumption - loaded;
  setSignal(document.getElementById('remainingresources'), pretty_number(transportCapacity), transportCapacity < 0 ? 'colorNegative' : 'colorPositive');
  updateTradeRatio();
  return transportCapacity;
};

// ---------------------------------------------------------------------------------------- trade mission

const TRADE_KEYS = { 1: 'metal', 2: 'crystal', 3: 'deuterium' };

/** Positive whole number from an input value, else 0. */
const parseTradeAmount = (value) => {
  const amount = parseInt(value, 10);
  return Number.isFinite(amount) && amount > 0 ? amount : 0;
};

const tradeResourceKey = (resourceId) => TRADE_KEYS[resourceId];

const tradeReferenceDecimals = () => tradeMarket?.decimals ? tradeMarket.decimals : 2;

/** Value of an amount in deuterium with the market `rates` (metal/crystal rate = amount per deuterium), rounded up; 0 when invalid. */
const convertTradeAmountToDeut = (amount, resourceId, rates) => {
  const key = tradeResourceKey(resourceId);
  if (!key || amount <= 0 || !rates) {
    return 0;
  }
  if (key === 'deuterium') {
    return Math.ceil(amount);
  }
  const rate = parseFloat(rates[key]);
  if (!Number.isFinite(rate) || rate <= 0) {
    return 0;
  }
  return Math.ceil(amount / rate);
};

const setTradeRatioDisplay = (ratio) => {
  const ratioEl = document.getElementById('tradeRelativeRatio');
  if (!ratioEl) {
    return;
  }
  if (!Number.isFinite(ratio) || ratio <= 0) {
    ratioEl.textContent = '-';
    ratioEl.className = '';
    return;
  }
  ratioEl.textContent = pretty_number(ratio, tradeReferenceDecimals());
  ratioEl.className = ratio < 1 ? 'colorNegative' : 'colorPositive';
};

/** The resources with an amount above 0 in the cargo fields, as [{id: 1 metal | 2 crystal | 3 deuterium, amount}]. */
const getLoadedTradeResources = () => [
  { id: 1, amount: parseTradeAmount(field('metal')?.value) },
  { id: 2, amount: parseTradeAmount(field('crystal')?.value) },
  { id: 3, amount: parseTradeAmount(field('deuterium')?.value) },
].filter((res) => res.amount > 0);

const getTradeElement = (name) => field(name) || null;

const getTradeResourceId = (name) => {
  const el = getTradeElement(name);
  return el ? parseInt(el.value, 10) : 0;
};

const isFleetMarket = () => getTradeResourceId('markettype') === 1;

const otherTradeResource = (resourceId) => (resourceId === 1 ? 2 : 1);

const setTradeOptionsDisabled = (selectEl, disabledId) => {
  if (!selectEl) {
    return;
  }
  for (const option of selectEl.options) {
    option.disabled = parseInt(option.value, 10) === disabledId;
  }
};

const setTradeCargo = (metal, crystal, deuterium) => {
  for (const [name, value] of [['metal', metal], ['crystal', crystal], ['deuterium', deuterium]]) {
    const el = getTradeElement(name);
    if (el) {
      el.value = value;
    }
  }
};

/** Trade mission: offered and requested resource must differ (the requested one is switched and the offered one disabled in the other list); fleet market offers may not be "nothing". */
const ensureTradePairExclusive = () => {
  const offerEl = getTradeElement('resOffer');
  const askEl = getTradeElement('resEx');
  if (!offerEl || !askEl) {
    return;
  }
  setTradeOptionsDisabled(offerEl, 0);
  if (isFleetMarket()) {
    setTradeOptionsDisabled(askEl, 0);
    return;
  }
  const offerId = parseInt(offerEl.value, 10);
  if (offerId === parseInt(askEl.value, 10)) {
    askEl.value = String(otherTradeResource(offerId));
  }
  setTradeOptionsDisabled(askEl, offerId);
};

/** Trade mission: puts the offered amount into the cargo field of the offered resource and zeroes the others (no cargo for the fleet market type). */
const syncTradeOfferToCargo = () => {
  if (isFleetMarket()) {
    setTradeCargo(0, 0, 0);
    return;
  }
  const amountEl = document.getElementById('tradeOfferAmount');
  if (!amountEl) {
    return;
  }
  const offerId = getTradeResourceId('resOffer');
  const amount = parseTradeAmount(amountEl.value);
  setTradeCargo(offerId === 1 ? amount : 0, offerId === 2 ? amount : 0, offerId === 3 ? amount : 0);
};

/** Switches the form between resource market and fleet market offer (hides the offer rows for fleets) and syncs cargo and capacity. */
const applyTradeMarketType = () => {
  const isFleet = isFleetMarket();
  const offerRow = document.getElementById('tradeOfferRow');
  const selectedResRow = document.getElementById('selectedResRow');
  if (offerRow) {
    offerRow.style.display = isFleet ? 'none' : '';
  }
  if (selectedResRow && getTradeElement('resOffer')) {
    selectedResRow.style.display = isFleet ? 'none' : '';
  }
  if (isFleet) {
    const amountEl = document.getElementById('tradeOfferAmount');
    if (amountEl) {
      amountEl.value = '';
    }
  }
  ensureTradePairExclusive();
  syncTradeOfferToCargo();
  calculateTransportCapacity();
};

/** "Max" for the offered amount: the largest amount of the offered resource that fits into the cargo space. */
const maxTradeOffer = () => {
  if (isFleetMarket()) {
    return;
  }
  const key = tradeResourceKey(getTradeResourceId('resOffer'));
  if (!key || !getTradeElement(key)) {
    return;
  }
  for (const name of ['metal', 'crystal', 'deuterium']) {
    if (name !== key && getTradeElement(name)) {
      getTradeElement(name).value = 0;
    }
  }
  maxResource(key);
  const amountEl = document.getElementById('tradeOfferAmount');
  if (amountEl) {
    amountEl.value = getTradeElement(key).value;
  }
};

const onTradeOfferChanged = () => {
  ensureTradePairExclusive();
  syncTradeOfferToCargo();
  calculateTransportCapacity();
};

const onTradeAskChanged = () => {
  ensureTradePairExclusive();
  updateTradeRatio();
};

/** Takes a preselected single resource as the trade offer (first loaded resource), otherwise clears the offer. */
const applySelectedResourcesToTrade = () => {
  if (!getTradeElement('resOffer')) {
    return;
  }
  if (isFleetMarket()) {
    syncTradeOfferToCargo();
    calculateTransportCapacity();
    return;
  }
  const amountEl = document.getElementById('tradeOfferAmount');
  const loaded = getLoadedTradeResources();
  if (!amountEl || loaded.length === 0) {
    if (amountEl) {
      amountEl.value = '';
    }
    syncTradeOfferToCargo();
    calculateTransportCapacity();
    return;
  }
  getTradeElement('resOffer').value = String(loaded[0].id);
  amountEl.value = loaded[0].amount;
  ensureTradePairExclusive();
  syncTradeOfferToCargo();
  calculateTransportCapacity();
};

/** Shows the offered/requested value ratio (both converted to deuterium with the market rates), red below 1; for fleet offers the fleet's deuterium value is used. */
const updateTradeRatio = () => {
  if (!tradeMarket) {
    return;
  }
  const markettypeEl = field('markettype');
  const resExEl = field('resEx');
  const exchangeEl = field('exchange');
  if (!markettypeEl || !resExEl || !exchangeEl) {
    return;
  }

  const markettype = parseInt(markettypeEl.value, 10) || 0;
  const askId = parseInt(resExEl.value, 10);
  const askAmount = parseTradeAmount(exchangeEl.value);
  const rates = tradeMarket.rates;

  if (markettype === 1) {
    const fleetAskDeut = convertTradeAmountToDeut(askAmount, askId, rates);
    setTradeRatioDisplay(fleetAskDeut > 0 ? tradeMarket.fleetDeut / fleetAskDeut : 0);
    return;
  }

  const loaded = getLoadedTradeResources();
  if (loaded.length !== 1 || loaded[0].id === askId) {
    setTradeRatioDisplay(0);
    return;
  }

  const [offer] = loaded;
  const offerDeut = convertTradeAmountToDeut(offer.amount, offer.id, rates);
  const askDeut = convertTradeAmountToDeut(askAmount, askId, rates);
  setTradeRatioDisplay(askDeut > 0 ? offerDeut / askDeut : 0);
};

/** Hooks the trade fields (market type, offered and requested resource, amounts) to their update handlers and applies the current state. */
const initTradeMarket = () => {
  if (!tradeMarket) {
    return;
  }
  const handler = (event) => {
    const control = event.currentTarget;
    if (control.name === 'markettype') {
      applyTradeMarketType();
    } else if (control.name === 'resOffer' || control.id === 'tradeOfferAmount') {
      onTradeOfferChanged();
    } else if (control.name === 'resEx') {
      onTradeAskChanged();
    } else {
      updateTradeRatio();
    }
  };
  for (const control of qsa('#tradeOfferAmount, input[name=exchange], select[name=resOffer], select[name=resEx], select[name=markettype]')) {
    control.addEventListener('input', handler);
    control.addEventListener('change', handler);
  }
  applyTradeMarketType();
};

// ---------------------------------------------------------------------------------------- ships

/** tells the page that the ship amounts changed (fleetTable.js recomputes its sums) */
const amountsChanged = () => document.dispatchEvent(new Event('fleet:amounts'));

const maxShip = (id) => {
  const input = field(id);
  const cell = document.getElementById(`${id}_value`);
  if (input && cell) {
    const raw = cell.getAttribute('data-count');
    input.value = (raw !== null && raw !== '') ? raw : cell.textContent.replace(/\D/g, '');
  }
  amountsChanged();
};

/** Fills every ship field with the maximum available. */
const maxShips = () => {
  qsa('input[name^="ship"]').forEach((input) => maxShip(input.name));
  amountsChanged();
};

const noShip = (id) => {
  if (field(id)) {
    field(id).value = 0;
  }
  amountsChanged();
};

/** Sets every ship field to 0. */
export const noShips = () => {
  qsa('input[name^="ship"]').forEach((input) => noShip(input.name));
  amountsChanged();
};

const setNumber = (name, number) => {
  const input = field(`ship${name}`);
  if (input) {
    input.value = number;
  }
};

/**
 * Cargo preset links ("send resources to ..."): chooses small and/or large cargos so that `total` units are carried
 * (the preferred type first, capped by what the planet has, the rest covered by the other type), fills the resource
 * storage fields and, if given, the target coordinates.
 * @param {number} type 202 (small cargo) or 203 (large cargo): the preferred ship
 * @param {number} total resources to carry; neededNNN = ships of that type needed alone; shipNNNCapacity = cargo per ship
 */
const setCargoPreset = (type, galaxy, system, planet, planetType, metal, crystal, deuterium, total, ship202, ship203, needed202, needed203, ship202Capacity, ship203Capacity) => {
  let ship202Needed = 0;
  let ship203Needed = 0;

  switch (type) {
    case 202: // Small Cargo
      ship202Needed = needed202 > ship202 ? ship202 : needed202;

      if (needed202 > ship202) {
        needed203 = Math.ceil((total - (ship202 * ship202Capacity)) / ship203Capacity);
        ship203Needed = needed203 > ship203 ? ship203 : needed203;
      }
      break;

    case 203: // Large Cargo
      ship203Needed = needed203 > ship203 ? ship203 : needed203;

      if (needed203 > ship203) {
        needed202 = Math.ceil((total - (ship203 * ship203Capacity)) / ship202Capacity);
        ship202Needed = needed202 > ship202 ? ship202 : needed202;
      }
      break;
  }

  setNumber(202, ship202Needed);
  setNumber(203, ship203Needed);

  field('met_storage').value = metal;
  field('krist_storage').value = crystal;
  field('deut_storage').value = deuterium;

  if (galaxy !== null && system !== null && planet !== null && planetType !== null) {
    setTarget(galaxy, system, planet, planetType);
  }
};

// ---------------------------------------------------------------------------------------- templates and fleet save (step 1)

/** Replaces the children of `list` with entries built by `build` (text only, never markup). */
const fillList = (list, items, build) => list.replaceChildren(...items.map(build));

/** Draws the stored templates: name (applies it: step 1 reloads with `ships=` and the current target) and a delete button. */
const renderTemplates = (templates) => {
  const list = document.getElementById('tpl-list');
  if (templates.length === 0) {
    const none = document.createElement('li');
    none.textContent = texts.tplNone;
    list.replaceChildren(none);
    return;
  }
  fillList(list, templates, (template) => {
    const item = document.createElement('li');
    const apply = document.createElement('a');
    apply.href = '#';
    apply.textContent = template.name;
    apply.title = texts.tplApply;
    apply.dataset.action = 'tpl-apply';
    apply.dataset.ships = template.ships;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'link';
    remove.textContent = texts.tplDelete;
    remove.dataset.action = 'tpl-delete';
    remove.dataset.id = template.id;
    item.append(apply, ' ', remove);
    return item;
  });
};

/** Applies a template: reload step 1 with its ships (the server caps them at what the planet holds), keeping target and mission. */
const applyTemplate = (link) => {
  removeBeforeUnload();
  const query = new URLSearchParams({
    ships: link.dataset.ships, galaxy: field('galaxy').value, system: field('system').value, planet: field('planet').value,
    type: field('type').value, target_mission: document.querySelector('#form input[name="target_mission"]')?.value ?? -1,
  });
  document.location.href = `${new URL(urls.step1, document.location.href).pathname}?${query}`;
};

const showTemplateError = (message) => {
  const error = document.getElementById('tpl-error');
  error.hidden = !message;
  error.textContent = message ?? '';
};

/** Saves the current selection under the typed name (the CSRF token is added by app/csrf.js). Returns false (no native submit). */
const saveTemplate = () => {
  const name = document.getElementById('tpl-name');
  post(urls.tplSave, { token, name: name.value }, 'json').then((answer) => {
    showTemplateError(answer.ok ? '' : answer.error);
    if (answer.ok) {
      name.value = '';
      renderTemplates(answer.templates);
    }
  });
  return false;
};

const deleteTemplate = (button) => {
  post(urls.tplDelete, { id: button.dataset.id }, 'json').then((answer) => renderTemplates(answer.templates));
};

/** Asks the server for the slowest safe parking places and lists them; a click fills target, mission and speed 10 %. */
const findFleetSave = () => {
  const list = document.getElementById('save-choices');
  getJSON(`${urls.fleetSave}?token=${encodeURIComponent(token)}`).then(({ choices }) => {
    if (choices.length === 0) {
      const none = document.createElement('li');
      none.textContent = texts.saveNone;
      list.replaceChildren(none);
      return;
    }
    fillList(list, choices, (choice) => {
      const item = document.createElement('li');
      const link = document.createElement('a');
      link.href = '#';
      link.dataset.action = 'fleet-save-use';
      Object.assign(link.dataset, { galaxy: choice.galaxy, system: choice.system, planet: choice.planet, type: choice.type, mission: choice.mission, speed: choice.speed });
      link.textContent = fill(texts.saveChoice, choice.name, `[${choice.galaxy}:${choice.system}:${choice.planet}]`, clock(choice.duration), pretty_number(choice.fuel));
      item.append(link);
      return item;
    });
  });
};

/** Takes a fleet save suggestion over: target, speed and the deploy mission (the mission list of step 2 then preselects it). */
const useFleetSave = (link) => {
  setTarget(link.dataset.galaxy, link.dataset.system, link.dataset.planet, link.dataset.type);
  field('speed').value = link.dataset.speed;
  document.querySelector('#form input[name="target_mission"]').value = link.dataset.mission;
  updateVars();
};

// ---------------------------------------------------------------------------------------- arrival time picker (step 2)

/** Seconds as h:mm:ss. */
const clock = (seconds) => `${Math.floor(seconds / 3600)}:${dezInt(Math.floor(seconds % 3600 / 60), 2)}:${dezInt(Math.floor(seconds % 60), 2)}`;

/** `%s` placeholders of a translated text filled in order. */
const fill = (text, ...values) => values.reduce((out, value) => out.replace('%s', value), String(text ?? ''));

/** Speed step (1 = 10 % ... 10 = 100 %) picked for the wanted arrival, null without a usable input. */
let arriveStep = null;

/**
 * "Arrive at": from the per-speed durations of the server (`speedPlan`, same formula as the dispatch) picks the highest affordable speed step
 * that arrives no earlier than the wanted time and shows its arrival and the difference. When no step is late enough the slowest one is
 * shown, when even the fastest is too late the fastest.
 */
const updateArriveAt = () => {
  const input = document.getElementById('arrive-at');
  const apply = document.getElementById('arrive-apply');
  const row = document.getElementById('arrive-result-row');
  const wantedMs = input.value ? new Date(input.value).getTime() : NaN;
  arriveStep = null;
  apply.hidden = true;
  row.hidden = Number.isNaN(wantedMs);
  if (Number.isNaN(wantedMs)) {
    return;
  }
  const wait = (wantedMs - now().getTime()) / 1000;
  const steps = Object.keys(fleetPage.speedPlan).map(Number).filter((step) => fleetPage.speedPlan[step].affordable).sort((a, b) => b - a);
  const late = steps.filter((step) => fleetPage.speedPlan[step].duration >= wait);
  const notes = [];
  if (steps.length === 0) {
    document.getElementById('arrive-result').textContent = '-';
    return;
  }
  arriveStep = late.length > 0 ? late[0] : steps[steps.length - 1];
  if (late.length === 0) {
    notes.push(texts.arriveTooEarly);
  } else if (late[0] === steps[0] && fleetPage.speedPlan[late[0]].duration > wait + 1) {
    notes.push(texts.arriveTooLate);
  }
  const plan = fleetPage.speedPlan[arriveStep];
  const arrival = new Date(now().getTime() + plan.duration * 1000);
  const diff = Math.round(plan.duration - wait);
  const diffText = diff === 0 ? texts.arriveExact : fill(diff > 0 ? texts.arriveLater : texts.arriveEarlier, clock(Math.abs(diff)));
  document.getElementById('arrive-result').textContent = [fill(texts.arriveSpeed, arriveStep * 10, arrival.toLocaleString(), diffText), ...notes].join(' ');
  if (arriveStep !== Number(fleetPage.speedStep)) {
    apply.hidden = false;
  }
};

/** Reloads step 2 with the picked speed (same token, target and mission; the server recomputes everything). */
const applyArriveSpeed = () => {
  if (arriveStep === null) {
    return;
  }
  removeBeforeUnload();
  const url = new URL(urls.step2, document.location.href);
  const query = new URLSearchParams({ token, galaxy: fleetPage.target[0], system: fleetPage.target[1], planet: fleetPage.target[2], type: fleetPage.target[3],
    target_mission: document.querySelector('input[name="mission"]:checked')?.value ?? fleetPage.targetMission, speed: arriveStep, group: fleetPage.group ?? 0 });
  document.location.href = `${url.pathname}?${query}`;
};

// ---------------------------------------------------------------------------------------- steps

/** Fleet steps reserve the fleet on the server; leaving the page without sending releases it again through a beacon (`fleetStepAjax`). These three functions manage that listener. */
const handleBeforeUnload = () => {
  navigator.sendBeacon(`${urls.release}?ajax=1&token=${token}`);
};
const addBeforeUnload = () => window.addEventListener('beforeunload', handleBeforeUnload);
const removeBeforeUnload = () => window.removeEventListener('beforeunload', handleBeforeUnload);

/** native submit of #form; step 1 re-enables disabled fields first so they are posted */
const submitForm = (enableDisabled = false) => {
  const form = document.getElementById('form');
  if (enableDisabled) {
    form.querySelectorAll(':disabled').forEach((control) => { control.disabled = false; });
  }
  form.submit();
};

/** Step 1 submit: validates the target on the server (`checkTarget`); "OK" submits the form, otherwise the message is shown. Returns false (the real submit happens afterwards). */
const checkTarget = () => {
  if (!validateTarget()) {
    field('galaxy').closest('form').querySelector('[aria-invalid="true"]')?.focus();
    return false;
  }
  removeBeforeUnload();
  const kolo = (typeof data.ships[208] === 'object') ? 1 : 0;
  const query = new URLSearchParams({
    galaxy: field('galaxy').value,
    system: field('system').value,
    planet: field('planet').value,
    planet_type: field('type').value,
    lang: env.lang,
    kolo,
    mission: document.querySelector('#form input[name="target_mission"]')?.value ?? -1,
  });

  getJSON(`${urls.checkTarget}?${query}`).then((answer) => {
    if (answer === 'OK') {
      submitForm(true);
    } else {
      addBeforeUnload();
      NotifyBox(answer);
    }
  });
  return false;
};

/** swap the submit row for the "please wait" row and back */
const showWait = () => {
  hide(document.getElementById('submit'));
  show(document.getElementById('wait'));
};

const showSubmit = () => {
  hide(document.getElementById('wait'));
  show(document.getElementById('submit'));
};

/** Step 2 submit: validates cargo, mission and trade fields on the server (`checkResources`); "OK" submits, an error object ends the dispatch with a dialog (back to the fleet table), other text is shown as notice. Returns false. */
const checkResources = () => {
  removeBeforeUnload();
  const query = new URLSearchParams({
    metal: field('metal').value,
    crystal: field('crystal').value,
    deuterium: field('deuterium').value,
    mission: document.querySelector('input[name="mission"]:checked').value,
    markettype: field('markettype')?.value ?? 0,
    resEx: field('resEx')?.value ?? 0,
    exchange: field('exchange')?.value ?? 0,
    token: field('token').value,
  });

  getJSON(`${urls.checkResources}?${query}`).then((answer) => {
    if (answer === 'OK') {
      submitForm();
    } else if (typeof answer.error !== 'undefined' && answer.error) {
      addBeforeUnload();
      Dialog.alert(answer.message, () => {
        document.location.href = urls.fleetTable;
      });
    } else {
      addBeforeUnload();
      showSubmit();
      NotifyBox(answer);
    }
  });
  return false;
};

// ---------------------------------------------------------------------------------------- shortcuts

/** Appends an empty shortcut entry cloned from the template row, numbering its field names `shortcut[<n>-new]`. */
const addShortcut = () => {
  const children = Array.from(document.querySelector('.shortcut-new td').cloneNode(true).children);
  const count = document.querySelectorAll('.shortcut-link').length;
  for (const child of children) {
    child.querySelectorAll('input, select').forEach((control) => {
      control.name = control.name.replace('shortcut[]', `shortcut[${count}-new]`);
    });
  }

  const newShortcut = document.createElement('div');
  newShortcut.className = 'shortcut-colum shortcut-isset';
  newShortcut.append(...children);
  document.querySelector('.shortcut-container').append(newShortcut);
  document.querySelectorAll('.shortcut-none').forEach((el) => el.remove());
  document.querySelector('.shortcut-row').hidden = false;
};

/** Switches the shortcut list into edit mode (adds an empty entry when there is none yet). */
const editShortcuts = () => {
  qsa('.shortcut-link').forEach(hide);
  // the stylesheet hides .shortcut-edit, so the display value has to be set explicitly
  qsa('.shortcut-edit:not(.shortcut-new)').forEach((el) => show(el, el.tagName === 'TR' ? 'table-row' : 'block'));
  if (document.querySelectorAll('.shortcut-isset').length === 0) {
    addShortcut();
  }
};

/** Saves the shortcut fields (ajax POST `saveShortcuts`) and reloads the page. */
const saveShortcuts = () => {
  post(`${urls.saveShortcuts}?ajax=1`, serialize(qsa('.shortcut-row input, .shortcut-row select'))).then(() => {
    qsa('.shortcut-link').forEach((el) => show(el));
    qsa('.shortcut-edit').forEach(hide);

    window.location.reload();
  });
};

on(document, 'click', '.shortcut-delete', (event, button) => {
  button.closest('.shortcut-colum')?.remove();
});

/** Shows the stay-time selector only for missions with a hold time (5, 15, 16, 17 and -1). */
const checkHold = (mission) => {
  const display = [5, 15, 16, 17, -1].includes(mission) ? 'initial' : 'none';
  for (const id of ['stay_head', 'stay']) {
    const el = document.getElementById(id);
    if (el) {
      el.style.display = display;
    }
  }
};

/**
 * Send button of step 2: shows the wait row; for missions with a warning text (`questionList`) the click is cancelled
 * (returns false), the question asked in the modal and, when accepted, the form submitted (requestSubmit, with the
 * button as submitter); declined restores the button row.
 */
const sendFleet = (button) => {
  const mission = document.querySelector('input[name="mission"]:checked').value;
  const message = questionList[parseInt(mission)] || null;
  showWait();
  if (message) {
    Dialog.confirm(message).then((ok) => {
      if (ok) {
        rememberFleet(mission);
        button.form.requestSubmit(button);
      } else showSubmit();
    });
    return false;
  }
  rememberFleet(mission);
  return true;
};

/** Remembers the fleet being sent (ships, target, mission name) in localStorage for the "last mission" chip of the fleet page. */
const rememberFleet = (mission) => {
  try {
    const label = document.querySelector(`label[for="radio_${mission}"]`)?.textContent.trim() ?? '';
    localStorage.setItem('last_fleet', JSON.stringify({ ships: fleetPage.fleet, target: fleetPage.target, mission: Number(mission), label }));
  } catch (error) {
    // storage disabled or full: the chip is a convenience only
  }
};

/** Shows the chosen mission in the summary bar. */
const showMissionInSummary = (radio) => {
  const label = document.querySelector(`label[for="${radio.id}"]`);
  if (summary('mission') && label) {
    summary('mission').textContent = label.textContent.trim();
    document.getElementById('sum-mission-item').hidden = false;
    const returnItem = document.getElementById('sum-return-item');
    if (returnItem) {
      returnItem.hidden = Number(radio.dataset.mission) === 4;
    }
  }
};

/** Live arrival time of the flight in the player's timezone (page element #arr_time, `duration` attribute in seconds), with a day prefix "nT" for more than a day. */
const updateArrival = () => {
  const arrival = document.getElementById('arr_time');
  const current = new Date(Date.now());
  const serverOffsetBase = new Date(current.toLocaleString('en-us', { timeZone: env.serverTimezone })).getTime();
  const localBase = new Date(current.toLocaleString('en-us', { timeZone: env.localTimezone })).getTime();
  const timeOffset = localBase - serverOffsetBase;
  const formatAt = (seconds) => {
    const nd = new Date(now().getTime() + timeOffset + seconds * 1000);
    const days = Math.floor((nd - now()) / (1000 * 60 * 60 * 24));
    const prefix = days > 0 ? `${days}T ` : '';
    return `${prefix}${nd.getHours()}:${pad(nd.getMinutes(), 2)}:${pad(nd.getSeconds(), 2)}`;
  };
  const duration = parseInt(arrival.getAttribute('duration'));
  arrival.innerText = formatAt(duration);
  setSummaryArrival(arrival.innerText);
  const back = formatAt(duration * 2);
  document.getElementById('ret_time').innerText = back;
  setSummaryReturn(back);
};

const coords = (link) => [link.dataset.galaxy, link.dataset.system, link.dataset.planet, link.dataset.type];

registerActions({
  'set-target': (link) => { setTarget(...coords(link)); updateVars(); },
  'set-acs-target': (link) => setACSTarget(...coords(link), link.dataset.acs),
  'update-vars': () => updateVars(),
  'update-vars-speed': () => updateVars(false),
  'check-target': () => checkTarget(),
  'edit-shortcuts': () => editShortcuts(),
  'save-shortcuts': () => saveShortcuts(),
  'add-shortcut': () => addShortcut(),
  'check-resources': () => checkResources(),
  'tpl-save': () => saveTemplate(),
  'tpl-apply': (link) => applyTemplate(link),
  'tpl-delete': (button) => deleteTemplate(button),
  'fleet-save': () => findFleetSave(),
  'fleet-save-use': (link) => useFleetSave(link),
  'arrive-at': () => updateArriveAt(),
  'arrive-apply': () => applyArriveSpeed(),
  'check-hold': (radio) => { checkHold(Number(radio.dataset.mission)); showMissionInSummary(radio); updateSendGate(); },
  'calc-capacity': () => { calculateTransportCapacity(); },
  'max-trade-offer': () => maxTradeOffer(),
  'max-resource': (link) => maxResource(link.dataset.resource),
  'max-resources': () => maxResources(),
  'selected-resources': () => selectedResources(),
  'send-fleet': (button) => sendFleet(button),
  'max-ship': (link) => maxShip(link.dataset.ship),
  'no-ship': (link) => noShip(link.dataset.ship),
  'max-ships': () => maxShips(),
  'no-ships': () => noShips(),
  'cargo-preset': (span) => {
    const d = span.dataset;
    const n = (key) => Number(d[key]);
    setCargoPreset(n('type'), n('galaxy'), n('system'), n('planet'), n('planetType'), n('metal'), n('crystal'), n('deuterium'), n('total'),
      n('ship202'), n('ship203'), n('needed202'), n('needed203'), n('capacity202'), n('capacity203'));
  },
});

window.onPageInit(() => {
  updateSendGate();
  for (const name of ['metal', 'crystal', 'deuterium']) {
    field(name)?.addEventListener('input', updateSendGate);
  }
  if (document.getElementById('arr_time')) {
    updateArrival();
    setVisibleInterval(updateArrival, 1000);
  }
});

window.onPageInit(() => {
  if (fleetPage.step === 1) {
    renderTemplates(fleetPage.templates ?? []);
    addBeforeUnload();
    updateVars();
    fleetTime();
    setVisibleInterval(fleetTime, 1000);
  } else if (fleetPage.step === 2) {
    addBeforeUnload();
    calculateTransportCapacity();
    checkHold(Number(fleetPage.targetMission));
    initTradeMarket();
  }
});
