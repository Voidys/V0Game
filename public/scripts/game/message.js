import { qs, qsa, show, hide, post, cookie, NotifyBox, pageData, pretty_number } from '../app/core.js';
import { Dialog } from '../app/dialog.js';
import { registerActions } from '../app/actions.js';

/**
 * Messages: category preferences and message actions (favourite, delete, reply, forward, spy report toggle,
 * grouping), the write / forward dialog (receiver search, sending) and the spy report conclusions.
 * Controls are data-action / data-on-* markup (app/actions.js; links carry their route as data-url). The write
 * dialog's state arrives in the data island #message-write-data ({action, sendUrl, forwardUrl, usersUrl, searchNote,
 * searchNoUser, emptyText, messageGroup, messageID, receiverList}), read each time a dialog is loaded.
 */

const CATEGORY_KEY = 'messages.lastCategory';
const OPEN_ALL_KEY = 'messages.openAllWithoutCategory';
const REMEMBER_CATEGORY_KEY = 'messages.rememberLastCategory';

/** Stores the shown category id in localStorage (numeric ids only). */
const rememberCategory = (category) => {
  if (/^\d+$/.test(String(category))) {
    localStorage.setItem(CATEGORY_KEY, String(category));
  }
};

/**
 * Category preferences of the message page (localStorage): "open all categories" and "remember last category". Without a
 * `category` in the URL it redirects to the remembered category or to 100 (all) according to the switches; with one it
 * remembers it. The two switches exclude each other. Reads/writes localStorage, may replace the location.
 */
const initCategoryPreferences = () => {
  const openAll = localStorage.getItem(OPEN_ALL_KEY) === '1';
  const rememberLast = localStorage.getItem(REMEMBER_CATEGORY_KEY) === '1';
  const openAllSwitch = document.getElementById('message-open-all');
  const rememberSwitch = document.getElementById('message-remember-category');
  const url = new URL(window.location.href);

  openAllSwitch.checked = openAll;
  rememberSwitch.checked = rememberLast;

  if (!url.searchParams.has('category')) {
    const lastCategory = localStorage.getItem(CATEGORY_KEY);
    const categoryItem = /^\d+$/.test(String(lastCategory))
      ? qs(`[data-message-category="${lastCategory}"]`)
      : null;

    if (rememberLast && categoryItem) {
      url.searchParams.set('category', lastCategory);
      window.location.replace(url.toString());
      return;
    }

    if (openAll) {
      url.searchParams.set('category', '100');
      window.location.replace(url.toString());
      return;
    }
  } else {
    rememberCategory(url.searchParams.get('category'));
  }

  openAllSwitch.addEventListener('change', () => {
    localStorage.setItem(OPEN_ALL_KEY, openAllSwitch.checked ? '1' : '0');
    if (openAllSwitch.checked) {
      rememberSwitch.checked = false;
      localStorage.setItem(REMEMBER_CATEGORY_KEY, '0');
    }
  });

  rememberSwitch.addEventListener('change', () => {
    localStorage.setItem(REMEMBER_CATEGORY_KEY, rememberSwitch.checked ? '1' : '0');
    if (rememberSwitch.checked) {
      openAllSwitch.checked = false;
      localStorage.setItem(OPEN_ALL_KEY, '0');
    }
  });
};

/** Removes tags from a string. */
const stripHTML = (string) => string.replace(/<(.|\n)*?>/g, '');

/** Reply subject: prefixes "Re: " or raises an existing "Re[n]: " to "Re[n+1]: "; tags are stripped. */
const createAnswer = (subject) => {
  const answer = stripHTML(subject).trim();
  const prefixes = answer.match(/^(?:Re(?:\[(\d+)\])?:\s*)+/i);

  if (!prefixes) {
    return `Re: ${answer}`;
  }

  const [prefix] = prefixes;
  const levels = (prefix.match(/Re\[(\d+)\]/gi) || []).map((level) => parseInt(level.match(/\d+/)[0], 10));
  const replyLevel = Math.max(1, ...levels);

  return `Re[${replyLevel + 1}]: ${answer.substr(prefix.length)}`;
};

/** Deletes a message (ajax POST to the delete route), removes its row and the conversation block when it became empty, shows the server's notice. */
const deleteMessage = async (id, groupId, url) => {
  show(qs('#loading'));

  const data = await post(`${url}?delMessID=${id}&ajax=1`, {}, 'json');
  hide(qs('#loading'));

  qs(`#message_${id}`)?.remove();

  const group = document.getElementById(groupId);
  if (group && group.querySelectorAll(':scope > div').length === 0) {
    group.remove();
  }

  NotifyBox(data.mess);
};

/** Toggles the favourite mark of a message (ajax POST to the favorite route): sets the icon state from the answer code (1 = favourite); `favUrl`/`nofavUrl` are image URLs for the old image-based icon. */
const toggleFavourite = async (id, url, favUrl, nofavUrl) => {
  show(qs('#loading'));

  const data = await post(`${url}?favMessID=${id}&ajax=1`, {}, 'json');
  hide(qs('#loading'));

  const img = qs(`#imgfav_${id}`);
  if (img?.classList.contains('msg-fav')) {
    img.classList.toggle('is-on', data.code == 1); // icon: the state is a class
  } else if (img) {
    img.setAttribute('src', data.code == 1 ? favUrl : nofavUrl);
  }
};

let selectBox;
let request = null;

// state of the write / forward dialog (from the data island, see initWriteDialog)
let write = { searchNote: '', searchNoUser: '', emptyText: '', messageGroup: '', messageID: 0 };
let receiver = [];

/** (re)reads the write dialog's data island; receiver = ids of the chosen players */
const initWriteDialog = () => {
  if (!document.getElementById('message-write-data')) return;
  write = pageData('message-write-data');
  receiver = write.action === 'write' ? Object.values(write.receiverList ?? {}).map((r) => r.id) : [];
  selectBox = qs('.select-box');
};
document.addEventListener('modal:load', initWriteDialog);

window.onPageInit(() => {
  initWriteDialog();
  selectBox = qs('.select-box');
  if (qs('#message-open-all')) {
    initCategoryPreferences();
  }
  // bulk form: every delete* action asks first (the question lives on the form, the choice is in the select)
  for (const form of qsa('form.msg-bulk-form')) {
    form.addEventListener('submit', async (event) => {
      if (form.dataset.confirmed === '1' || !String(form.elements.action?.value).startsWith('delete')) return;
      event.preventDefault();
      if (await Dialog.confirm(form.dataset.confirmDelete, { danger: true })) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  }
  // "select all" boxes (above and below the list) tick every message on the page
  for (const all of qsa('[data-message-select-all]')) {
    all.addEventListener('change', () => {
      for (const box of qsa('input[name^="messageID["], [data-message-select-all]')) {
        box.checked = all.checked;
      }
    });
  }
});

/** POSTs a message form to the server (JSON answer), shows the answer text in an alert and closes the modal afterwards. */
const sendAndClose = async (url, data) => {
  const result = await post(url, data, 'json');
  Dialog.alert(result, () => Dialog.close());
};

/** Send button of the write dialog: refuses an empty text, otherwise sends subject, text, group and the chosen receivers. */
const check = () => {
  if (receiver.length === 0) {
    Dialog.alert(write.noReceiver);
    return false;
  }
  const text = qs('#text').value;
  if (text.length === 0) {
    Dialog.alert(write.emptyText);
    return false;
  }
  sendAndClose(`${write.sendUrl}?ajax=1`, {
    'to[]': receiver,
    subject: qs('#subject').value,
    text,
    groupid: write.messageGroup,
  });
};

/** Forward dialog: sends the message id to the chosen receivers. */
const checkForward = () => {
  if (receiver.length === 0) {
    Dialog.alert(write.noReceiver);
    return false;
  }
  sendAndClose(`${write.forwardUrl}?ajax=1`, {
    id: write.messageID,
    'to[]': receiver,
  });
};

/** Collapses/expands a spy report message (content and details versus the title line); `collapseUrl`/`expandUrl` are image URLs for the old image-based toggle. */
const toggleSpyReport = (messageId, collapseUrl, expandUrl) => {
  const content = document.getElementById(`msgcontent_${messageId}`);
  const details = document.getElementById(`msgdetails_${messageId}`);
  const title = document.getElementById(`spytitle_${messageId}`);
  const icon = document.getElementById(`imgtoggle_${messageId}`);

  const isIcon = icon.classList.contains('msg-toggle'); // icon: the state is a class, else swap the image
  if (content.style.display === 'none') {
    content.style.display = '';
    details.style.display = '';
    title.style.display = 'none';
    if (isIcon) icon.classList.remove('is-collapsed'); else icon.src = collapseUrl;
  } else {
    content.style.display = 'none';
    details.style.display = 'none';
    title.style.display = '';
    if (isIcon) icon.classList.add('is-collapsed'); else icon.src = expandUrl;
  }
};

/** Flips the conversation grouping setting (cookie `msgGrouping` 0/1) and reloads the category. */
const toggleGrouping = (category, fav, url) => {
  const grouped = parseInt(cookie.get('msgGrouping') || 0);
  cookie.set('msgGrouping', grouped === 1 ? 0 : 1);
  window.location.href = `${url}?category=${category}&fav=${fav}`;
};

/** A line in the receiver search box. */
const searchNote = (text) => `<div class="select-box-option">${text}</div>`;

/** Player search of the write dialog: from 3 characters asks the server (`usersUrl` route, JSON), cancelling an older request, and lists the matches that are not yet receivers. */
const loadUser = async () => {
  const searchtext = qs('#to').value.trim();

  request?.abort();

  if (searchtext.length < 3) {
    selectBox.innerHTML = searchNote(write.searchNote);
    return;
  }

  request = new AbortController();
  let data;
  try {
    const query = new URLSearchParams({ searchtext, ajax: 1 });
    const response = await fetch(`${write.usersUrl}?${query}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: request.signal,
    });
    data = await response.json();
  } catch (error) {
    return; // aborted by a newer search
  }

  if (data.error || data.length === 0) {
    selectBox.innerHTML = searchNote(write.searchNoUser);
  } else {
    selectBox.innerHTML = '';
    for (const user of data) {
      if (receiver.includes(user.id)) {
        continue;
      }
      const option = document.createElement('div');
      option.className = 'select-box-option';
      option.textContent = user.username;
      option.addEventListener('click', () => addUser(user.id, user.username));
      selectBox.append(option);
    }
  }
  if (selectBox.innerHTML === '') {
    selectBox.innerHTML = searchNote(write.searchNoUser);
  }
};

/** Adds a receiver (once) to the list and the form. */
const addUser = (id, username) => {
  hide(selectBox);
  qs('#to').value = '';
  selectBox.innerHTML = '';

  if (receiver.includes(id)) {
    return;
  }
  receiver.push(id);
  const entry = document.createElement('div');
  entry.className = 'receiver';
  entry.id = `receiver-${id}`;
  entry.append(`${username} `);
  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'receiver__remove';
  remove.setAttribute('aria-label', write.removeReceiver);
  remove.title = write.removeReceiver;
  remove.innerHTML = '<svg class="icon" aria-hidden="true"><use href="./styles/resource/icons/lucide.svg#i-x"></use></svg>';
  remove.addEventListener('click', () => removeUser(id));
  entry.append(remove);
  qs('#receiver-list').append(entry);
};

/** Removes a receiver from the list and the form. */
const removeUser = (id) => {
  receiver = receiver.filter((value) => value !== id);
  qs(`#receiver-${id}`)?.remove();
};

/** Hides the search result box after a short delay, so a click on a result still registers. */
const hideSearch = () => {
  setTimeout(() => hide(selectBox), 100);
};

const showSearch = () => {
  // .select-box is display:none in the stylesheet, so an empty inline value would keep it hidden
  show(selectBox, 'block');
  loadUser();
};

/** Sends the player's spy probes to the target of a spy report (the galaxy spy action: `fleetAjax`, mission 6); code 602 asks for confirmation first. The answer comes as a toast. */
const spyAgain = async (url, planetID) => {
  const send = (confirmed) => {
    const query = new URLSearchParams({ ajax: 1, mission: 6, planetID });
    if (confirmed) query.set('confirm', 1);
    return post(`${url}?${query}`, {}, 'json');
  };
  let answer = await send(false);
  if (answer.code === 602) {
    if (!(await Dialog.confirm(answer.mess))) return;
    answer = await send(true);
  }
  NotifyBox(answer.mess, { kind: answer.code === 600 ? 'success' : 'error', top: true, ms: 3500 });
};

registerActions({
  'msg-spy-again': (link) => spyAgain(link.dataset.url, link.dataset.planet),
  'msg-toggle-grouping': (link) => toggleGrouping(link.dataset.category, link.dataset.fav, link.dataset.url),
  'msg-fav': (link) => toggleFavourite(link.dataset.id, link.dataset.url, link.dataset.favUrl, link.dataset.nofavUrl),
  'msg-reply': (link) => {
    Dialog.PM(JSON.parse(link.dataset.receivers), createAnswer(link.dataset.subject), '', link.dataset.group);
    return false;
  },
  'msg-toggle-spy': (link) => toggleSpyReport(link.dataset.id, '', ''),
  'msg-delete': (link) => deleteMessage(link.dataset.id, link.dataset.group, link.dataset.url),
  'msg-send': () => check(),
  'msg-forward': () => checkForward(),
  'msg-load-user': () => loadUser(),
  'msg-hide-search': () => hideSearch(),
  'msg-show-search': () => showSearch(),
  'msg-remove-user': (span) => removeUser(Number(span.dataset.id)),
});

/**
 * Spy report conclusions (shown only for reports with a "totalRes" element): for each report it computes the loot (half of
 * the resources), the capacity needed, the debris value of the ships (`recycleList` = debris per ship type, scaled by
 * the debris percentage), the number of recyclers, the market value of the resources and the value per second of a round
 * trip with small cargos from the closest own planet, then writes them into the report and colours them with the
 * thresholds of the player's settings (`stbSettings`). Input comes from the `jsthings` attribute of #stuff. Estimates
 * only, they do not use the fleet send code.
 */
function scavengers() {
  let stuff = document.getElementById("stuff");
  if (stuff == null) {
    return;
  }

  // TODO debris values übergeben
  const recycleList = {
    "202": 4000, //KT
    "203": 12000, //GT
    "204": 4000, //LJ
    "205": 10000, //SJ
    "206": 27000, //Xer
    "207": 60000, //SS
    "208": 30000, //Colo
    "209": 16000, //Rec
    "210": 1000, //Spio
    "211": 75000, //B
    "212": 2000, //Sat
    "213": 110000, //Zer
    "214": 9000000, //RIP
    "215": 70000, //SXer
  };

  let frombody = JSON.parse(stuff.getAttribute("jsthings"))
  const impulseEngineTech = frombody.impulseEngineTech
  const combustionEngineTech = frombody.combustionEngineTech
  const tfpercFleet = frombody.tfpercFleet
  const tfpercDeff = frombody.tfpercDeff
  const marketRatios = frombody.marketRatios
  const fleetspeed = frombody.fleetspeed
  const stbSettings = frombody.stbSettings
  const uniType = frombody.uniType
  const maxGalaxy = frombody.maxGalaxy

  /** Reads coordinates and the amount of every resource/ship/building (`data-info`) from a report element. */
  function spyReportParser(spyReportElement) {
    let spyReport = {};
    let spyReportHead = spyReportElement.querySelector(".spyRaportHead");
    let cords = spyReportHead.getAttribute('coords').split(':');
    spyReport.head = {
      galaxy: cords[0],
      system: cords[1],
      planet: cords[2],
    };

    spyReport.content = {}
    for (let id of [901, 902, 903]) {
      spyReport.content[id] = 0;
    }
    for (let field of spyReportElement.querySelectorAll('div[data-info]')) {
      // Only read the amount itself: ships in the repair dock are appended as "(+ X)" in a child span
      let amount = field.firstChild ? field.firstChild.textContent : '';
      spyReport.content[field.getAttribute("data-info").split('_')[1]] = parseInt(amount.replace(/\D/g, ''))
    }
    return spyReport;
  }

  function sumUpResources(spyReport) {
    return spyReport.content[901] + spyReport.content[902] + spyReport.content[903];
  }

  /** Cargo capacity for the loot of a raid: 0.5 * max(sum, min(0.75 * (2 * metal + crystal + deuterium), 2 * metal + deuterium)). */
  function calculateNeededCapacity(metal, crystal, deuterium) {
    return 0.5 * Math.max(metal + crystal + deuterium, Math.min(0.75 * (2 * metal + crystal + deuterium), 2 * metal + deuterium));
  }

  /** Resources converted to a single value with the market ratios (metal : crystal : deuterium), rounded up. */
  function calculateResourceMarketValue(metal, crystal, deuterium) {
    return Math.ceil(metal / marketRatios[901] + crystal / marketRatios[902] + deuterium / marketRatios[903])
  }

  /** Estimated recyclers for a debris amount (20000 per recycler, plus one). */
  function estimateRecyclers(recycleValue) {
    return Math.ceil(recycleValue / 20000) + 1;
  }


  /**
   * Value per second of a raid with small cargos: market value of the loot divided by the round-trip time, from the active
   * planet and from the closest planet. The flight time uses a fixed 100 % speed approximation (see bestLocation()).
   * @param {Array} raidLocation [galaxy, system, planet] of the target
   */
  function raidTimeKT(met, kris, deut, raidLocation) {
    let bestPlanet,
      flytime = '',
      flytimeBestPlanet = '',
      flightSpeedKT = flightSpeed("202"),
      calculateResourceMarketValuePerSecond,
      calculateResourceMarketValuePerSecondBestPlanet,
      galaJump,
      galaJumpPlanet;

    let bestLocationForRaid = bestLocation(raidLocation);
    bestPlanet = bestLocationForRaid.bestPlanet;
    galaJump = bestLocationForRaid.galaJump;
    galaJumpPlanet = bestLocationForRaid.galaJumpPlanet;

    // Raid value estimator only — not the fleet send path (flotten.js GetDuration / getMissionDuration).
    flytime = Math.max(Math.round((3500 / (100 * 0.1) * Math.pow(bestLocationForRaid.activPlanetdistance * 10 / flightSpeedKT, 0.5) + 10) / 1 * Math.max(0, 1 + 100) * 1, 5));
    flytime = flytime / 10;

    flytimeBestPlanet = Math.max(Math.round((3500 / (100 * 0.1) * Math.pow(bestLocationForRaid.bestPlanetdistance * 10 / flightSpeedKT, 0.5) + 10) / 1 * Math.max(0, 1 + 100) * 1, 5));
    flytimeBestPlanet = flytimeBestPlanet / 10;

    calculateResourceMarketValuePerSecond = (calculateResourceMarketValue(met, kris, deut) / (flytime * 2));
    calculateResourceMarketValuePerSecond = calculateResourceMarketValuePerSecond.toFixed(2);

    calculateResourceMarketValuePerSecondBestPlanet = (calculateResourceMarketValue(met, kris, deut) / (flytimeBestPlanet * 2));
    calculateResourceMarketValuePerSecondBestPlanet = calculateResourceMarketValuePerSecondBestPlanet.toFixed(2);

    return {
      'calculateResourceMarketValuePerSecond': calculateResourceMarketValuePerSecond,
      'calculateResourceMarketValuePerSecondBestPlanet': calculateResourceMarketValuePerSecondBestPlanet,
      'bestPlanet': bestPlanet,
      'galaJumpPlanet': galaJumpPlanet,
      'galaJump': galaJump
    }

  }

  /**
   * Distance from every own planet (read from the planet selector options) to the target, with the default distance
   * constants (galaxy 20000 per step, system 95 per step + 2700, planet 5 per step + 1000), for linear and circular universes
   * (circular wraps around). Returns the active planet's and the closest planet's distance and whether the closest one needs a galaxy jump.
   * Estimate for the report only; does not read the universe's configured distance factors.
   */
  function bestLocation(raidLocation) {

    let activPlanet,
      activPlanetdistance,
      bestPlanet,
      bestPlanetdistance,
      galaJump,
      galaJumpPlanet,
      planetList,
      planetListLenght,
      bestPlanetGalaJump;

    planetList = document.getElementById("planetSelector");
    activPlanet = planetList.options[planetList.selectedIndex].text;
    planetListLenght = planetList.length;

    for (let index = 0; index < planetListLenght; index++) {

      let regexCoordinates = /\[(?<galaxy>[1-9]):(?<system>[0-9]{1,3}):(?<planet>[0-9]{1,2})\]/,
        planetCoordinates = planetList.options[index].text.match(regexCoordinates),
        tempPlananet = [planetCoordinates.groups.galaxy, planetCoordinates.groups.system, planetCoordinates.groups.planet],
        tempDiff,
        tempDistance,
        isActive = 0;

      if (activPlanet == planetList.options[index].text) {
        isActive = 1
      }

      // 0 = Lineares
      // 1 = Ring
      // 2 = Kreis
      if (uniType == 0){
        if (raidLocation[0] - tempPlananet[0] != 0) {
          tempDiff = Math.abs(raidLocation[0] - tempPlananet[0]);
          tempDistance = tempDiff * 20000;
          galaJump = 1;
        } else {
          if (raidLocation[1] - tempPlananet[1] != 0) {
            tempDiff = Math.abs(raidLocation[1] - tempPlananet[1]);
            tempDistance = tempDiff * 95 + 2700;
            galaJump = 0;
          } else {
            tempDiff = Math.abs(raidLocation[2] - tempPlananet[2]);
            tempDistance = tempDiff * 5 + 1000;
            galaJump = 0;
          }
        }
      } else if (uniType == 1){
        // alert("RING SYSTEM")
        if (raidLocation[0] - tempPlananet[0] != 0) {
          const max = maxGalaxy;
          let dist = 0;

          if (Math.abs(tempPlananet[0] - raidLocation[0]) < Math.ceil(max / 2)) {
            dist = Math.abs(tempPlananet[0] - raidLocation[0]);
          } else {
            dist = Math.floor(max / 2) - (Math.abs(tempPlananet[0] - raidLocation[0]) - Math.ceil(max / 2));
          }
          dist = Math.abs(dist);
          tempDistance = dist * 20000;
          galaJump = 1;
        } else {
          if (raidLocation[1] - tempPlananet[1] != 0) {
            const max = 400;
            let dist = 0;
            if (Math.abs(tempPlananet[1] - raidLocation[1]) < Math.ceil(max / 2)) {
              dist = Math.abs(tempPlananet[1] - raidLocation[1]);
            } else {
              dist = Math.floor(max / 2) - (Math.abs(tempPlananet[1] - raidLocation[1]) - Math.ceil(max / 2));
            }
            dist = Math.abs(dist);
            tempDistance = dist * 95 + 2700;
            galaJump = 0;
          } else {
            tempDiff = Math.abs(raidLocation[2] - tempPlananet[2]);
            tempDistance = tempDiff * 5 + 1000;
            galaJump = 0;
          }
        }
      }

      if (index == 0) {
        bestPlanetdistance = tempDistance;
        bestPlanet = tempPlananet;
        if (galaJump == 1) {
          bestPlanetGalaJump = 1
        }
      } else if (bestPlanetdistance > tempDistance) {
        bestPlanetdistance = tempDistance;
        bestPlanet = tempPlananet;
        if (galaJump == 0) {
          bestPlanetGalaJump = 0
        }
      }

      if (isActive == 1) {
        activPlanetdistance = tempDistance;
      }

      if (bestPlanetGalaJump == 1) {
        galaJumpPlanet = "Galajump " + bestPlanet[0]
      } else {
        galaJump = 0;
      }

    }

    return {
      'galaJump': galaJump,
      'galaJumpPlanet': galaJumpPlanet,
      'activPlanetdistance': activPlanetdistance,
      'bestPlanet': bestPlanet,
      'bestPlanetdistance': bestPlanetdistance
    }

  }

  /** Speed of a small cargo (202) with the player's drive techs (combustion, or impulse from level 5) times the fleet speed factor of the universe. */
  function flightSpeed(shiptype) {
    let speed;


    if (shiptype === "202") {
      if (impulseEngineTech < 5) {
        speed = 5000 * (1 + (0.1 * combustionEngineTech));
      } else {
        speed = 10000 * (1 + (0.2 * impulseEngineTech));
      }
    }

    return fleetspeed * speed;

  }

  /** Debris the ships of the report would leave: ships * debris per ship type (`recycleList`), times the fleet debris percentage. */
  function determineRecycleValue(spyReport) {
    let summedUpRecycleValue = 0;
    Object.keys(recycleList).forEach(recycleKey => {
      let singleRecycleValue = recycleList[recycleKey];
      if (spyReport.content[recycleKey] !== undefined) {
        summedUpRecycleValue += spyReport.content[recycleKey] * singleRecycleValue;
      }
    });
    return summedUpRecycleValue * tfpercFleet / 100;
  }


  document.querySelectorAll(".spyRaport").forEach(spyReportElement => {

    if (spyReportElement.querySelector('*[name="totalRes"]') == null) {
      return;
    }

    let spyReport = spyReportParser(spyReportElement);

    let raidLocation = [spyReport.head.galaxy, spyReport.head.system, spyReport.head.planet];
    spyReport.conclusions = {};

    spyReport.conclusions.summedUpResources = sumUpResources(spyReport);
    spyReport.conclusions.potentialResources = sumUpResources(spyReport) / 2;
    spyReport.conclusions.neededCapacity = calculateNeededCapacity(
      spyReport.content[901],
      spyReport.content[902],
      spyReport.content[903]
    );
    spyReport.conclusions.recycleValue = determineRecycleValue(spyReport);
    spyReport.conclusions.recyclersNeeded = estimateRecyclers(spyReport.conclusions.recycleValue);
    spyReport.conclusions.marketValue = calculateResourceMarketValue(
      spyReport.content[901],
      spyReport.content[902],
      spyReport.content[903]
    );


    spyReport.conclusions.MarketValuePerSecond = raidTimeKT(spyReport.content[901], spyReport.content[902], spyReport.content[903], raidLocation);
    let bestrespertime = spyReport.conclusions.MarketValuePerSecond.calculateResourceMarketValuePerSecondBestPlanet
    let bestRessPerTimeClass = "lowRess";
    if (bestrespertime > stbSettings['stb_big_time']) {
      bestRessPerTimeClass = "realHighRess";
    } else if (bestrespertime > stbSettings['stb_med_time']) {
      bestRessPerTimeClass = "highRess";
    } else if (bestrespertime > stbSettings['stb_small_time']) {
      bestRessPerTimeClass = "midRess";
    }


    let ressourcesByMarketValue = spyReport.conclusions.marketValue
    let mkvaluetime = "lowRess";
    if (ressourcesByMarketValue > stbSettings['stb_big_ress']) {
      mkvaluetime = "realHighRess";
    } else if (ressourcesByMarketValue > stbSettings['stb_med_ress']) {
      mkvaluetime = "highRess";
    } else if (ressourcesByMarketValue > stbSettings['stb_small_ress']) {
      mkvaluetime = "midRess";
    }
    for (let d of spyReportElement.querySelectorAll('*[classadd="marketClass"]')) {
      d.classList.add(mkvaluetime);
    }

    for (let d of spyReportElement.querySelectorAll('*[classadd="timePerResClass"]')) {
      d.classList.add(bestRessPerTimeClass);
    }
    spyReportElement.querySelector('*[name="totalRes"]').innerText = pretty_number(spyReport.conclusions.summedUpResources);
    spyReportElement.querySelector('*[name="resToRaid"]').innerText = pretty_number(Math.floor(spyReport.conclusions.potentialResources));
    spyReportElement.querySelector('*[name="resToRec"]').innerText = pretty_number(spyReport.conclusions.recycleValue);
    spyReportElement.querySelector('*[name="recNeeded"]').innerText = pretty_number(spyReport.conclusions.recyclersNeeded);
    spyReportElement.querySelector('*[name="marketValue"]').innerText = pretty_number(Math.floor(spyReport.conclusions.marketValue));
    spyReportElement.querySelector('*[name="resPerSec"]').innerText = pretty_number(spyReport.conclusions.MarketValuePerSecond.calculateResourceMarketValuePerSecondBestPlanet, 2);
    spyReportElement.querySelector('*[name="bestPlanet"]').innerText = "[" + spyReport.conclusions.MarketValuePerSecond.bestPlanet.join(":") + "]";
  });

}


// spy report popup content arrives as modal fragment after the page init (scripts/app/dialog.js)
document.addEventListener('modal:load', () => scavengers());

window.onPageInit(() => {
  scavengers();
  // phones: search, filters and bulk actions start collapsed (kept open while a search is active)
  const tools = document.querySelector('.mail-tools');
  if (tools && !tools.hasAttribute('data-keep-open') && window.matchMedia('(max-width: 40rem)').matches) tools.open = false;
  // phones: the category row scrolls sideways; bring the active category into view (sideways only)
  const cats = document.querySelector('.mail-cats');
  const active = cats?.querySelector('.mail-cat.is-active');
  if (cats && active && cats.scrollWidth > cats.clientWidth) {
    cats.scrollLeft = active.offsetLeft - (cats.clientWidth - active.offsetWidth) / 2;
  }
  for (const checkbox of document.querySelectorAll('input[name*="messageID"]')) {
    checkbox.addEventListener('change', () => {
      for (const same of document.querySelectorAll(`input[name="${checkbox.name}"]`)) {
        same.checked = checkbox.checked;
      }
    });
  }
});
