// V0Game UI 2026 demo: static mock behaviour (views, palette, ticker, queue, galaxy, popovers).
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const ICONS = '../../styles/resource/icons/lucide.svg';
const ART = '../../styles/theme/DarkNebula/gebaeude/';
const PLANETS = '../../styles/resource/theme-shared/sets/nebula/planeten/';
const icon = (name) => `<svg class="icon"><use href="${ICONS}#i-${name}"></use></svg>`;
const fmt = new Intl.NumberFormat('en-US');
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
const hm = (d) => d.toTimeString().slice(0, 5);
const human = (s) => {
  s = Math.max(0, Math.round(s));
  const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.ceil(s % 3600 / 60);
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
};
const clockFmt = (s) => {
  s = Math.max(0, Math.round(s));
  const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return h ? `${h}:${pad(m)}:${pad(sec)}` : `${pad(m)}:${pad(sec)}`;
};

/* ---------- resources ---------- */
const res = {
  metal: { value: 1284553, rate: 18420, cap: 2075000 },
  crystal: { value: 612090, rate: 9860, cap: 1490000 },
  deuterium: { value: 248771, rate: 4215, cap: 267000 },
};

const shownVal = {};
function animateNumber(el, from, to) {
  if (reduceMotion.matches) { el.textContent = fmt.format(Math.floor(to)); return; }
  const t0 = performance.now(), dur = 520;
  el.classList.remove('bump', 'drop'); void el.offsetWidth; el.classList.add(to > from ? 'bump' : 'drop');
  const step = (now) => {
    const k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 4);
    el.textContent = fmt.format(Math.floor(from + (to - from) * e));
    if (k < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
function renderResources() {
  for (const [key, r] of Object.entries(res)) {
    const el = $(`.res[data-res="${key}"]`);
    const fill = Math.min(1, r.value / r.cap);
    el.style.setProperty('--fill', fill.toFixed(3));
    el.classList.toggle('is-full', fill > .9);
    $('.res-meter', el).classList.toggle('is-warn', fill > .9);
    const v = $('[data-value]', el), prev = shownVal[key] ?? r.value;
    if (Math.abs(r.value - prev) > r.rate / 60) animateNumber(v, prev, r.value);
    else v.textContent = fmt.format(Math.floor(r.value));
    shownVal[key] = r.value;
  }
  if (tipFor) showTip(tipFor);
}

/* resource details on hover / focus */
const tip = $('[data-res-tip]');
let tipFor = null;
function tipHtml(key) {
  if (key === 'energy') {
    return `<strong>Energy</strong><dl><dt>Produced</dt><dd>3,980</dd><dt>Used</dt><dd>3,568</dd><dt>Spare</dt><dd class="pos">+412</dd></dl>
      <p class="tip-note">Enough for one more mine level.</p>`;
  }
  const r = res[key], fill = Math.min(1, r.value / r.cap), left = (r.cap - r.value) / r.rate * 3600;
  const note = fill >= 1 ? '<p class="tip-note warn">Storage full, production stopped.</p>'
    : `<p class="tip-note${fill > .9 ? ' warn' : ''}">Full in ${human(left)} (${hm(new Date(Date.now() + left * 1000))}).</p>`;
  return `<strong>${key[0].toUpperCase() + key.slice(1)}</strong>
    <dl><dt>Stored</dt><dd>${fmt.format(Math.floor(r.value))}</dd><dt>Capacity</dt><dd>${fmt.format(r.cap)}</dd>
    <dt>Per hour</dt><dd>+${fmt.format(r.rate)}</dd><dt>Per day</dt><dd>+${fmt.format(r.rate * 24)}</dd></dl>
    <div class="tip-bar${fill > .9 ? ' is-warn' : ''}" style="--fill:${fill.toFixed(3)}"></div>${note}`;
}
function showTip(el) {
  tipFor = el;
  tip.innerHTML = tipHtml(el.dataset.res);
  const r = el.getBoundingClientRect();
  tip.style.top = `${r.bottom + 8}px`;
  tip.style.left = `${Math.min(innerWidth - tip.offsetWidth - 8, Math.max(8, r.left))}px`;
  tip.classList.add('is-on');
  el.setAttribute('aria-describedby', 'res-tip');
}
function hideTip() { tipFor = null; tip.classList.remove('is-on'); }
$$('.res').forEach((el) => {
  el.addEventListener('pointerenter', () => showTip(el));
  el.addEventListener('pointerleave', hideTip);
  el.addEventListener('focus', () => showTip(el));
  el.addEventListener('blur', hideTip);
});

/* ---------- buildings ---------- */
const buildings = [
  { id: 1, name: 'Metal mine', type: 'res', lvl: 24, desc: 'Main supplier of raw materials for buildings and ships.', cost: [482100, 120500, 0], time: 5420 },
  { id: 2, name: 'Crystal mine', type: 'res', lvl: 21, desc: 'Produces crystal for electronics and alloys.', cost: [310800, 155400, 0], time: 4980 },
  { id: 3, name: 'Deuterium synthesizer', type: 'res', lvl: 17, desc: 'Extracts deuterium from the planet’s water.', cost: [205100, 68400, 0], time: 3610 },
  { id: 4, name: 'Solar plant', type: 'res', lvl: 22, desc: 'Generates energy from stellar radiation.', cost: [380200, 152100, 0], time: 4210 },
  { id: 12, name: 'Fusion reactor', type: 'res', lvl: 8, desc: 'Burns deuterium to produce energy.', cost: [492000, 196800, 98400], time: 6120 },
  { id: 22, name: 'Metal storage', type: 'res', lvl: 9, desc: 'Raises the storage cap for metal.', cost: [256000, 0, 0], time: 2400 },
  { id: 14, name: 'Robotics factory', type: 'fac', lvl: 10, desc: 'Speeds up construction of buildings.', cost: [409600, 122900, 204800], time: 7600 },
  { id: 21, name: 'Shipyard', type: 'fac', lvl: 12, desc: 'Builds ships and planetary defences.', cost: [819200, 409600, 204800], time: 9800 },
  { id: 31, name: 'Research lab', type: 'fac', lvl: 11, desc: 'Required to research new technologies.', cost: [409600, 819200, 409600], time: 8800 },
  { id: 15, name: 'Nanite factory', type: 'fac', lvl: 3, desc: 'Halves building and ship construction time per level.', cost: [8000000, 4000000, 800000], time: 42000 },
  { id: 34, name: 'Alliance depot', type: 'fac', lvl: 2, desc: 'Supplies allied fleets holding in orbit.', cost: [80000, 160000, 0], time: 1900 },
  { id: 24, name: 'Deuterium tank', type: 'res', lvl: 6, desc: 'Raises the storage cap for deuterium.', cost: [64000, 32000, 0], time: 1500 },
];
const RES_KEYS = ['metal', 'crystal', 'deuterium'];
const RES_SHORT = ['Metal', 'Crystal', 'Deut.'];
const queue = [{ b: buildings[1], total: 4980, left: 3122 }];
let filter = 'all';

const affordable = (b) => b.cost.every((c, i) => c <= res[RES_KEYS[i]].value);

// seconds until a building becomes affordable at current production
const waitFor = (b) => Math.max(0, ...b.cost.map((c, i) => (c - res[RES_KEYS[i]].value) / res[RES_KEYS[i]].rate * 3600));
const queueEnd = () => queue.reduce((t, q, i) => t + (i ? q.total : q.left), 0);

function renderBuildings(animate = true) {
  const grid = $('[data-build-grid]');
  const list = buildings.filter((b) => filter === 'all' || (filter === 'can' ? affordable(b) : b.type === filter));
  grid.classList.toggle('no-anim', !animate);
  grid.innerHTML = list.map((b, idx) => {
    const queued = queue.filter((q) => q.b === b).length;
    const can = affordable(b);
    const cost = b.cost.map((c, i) => {
      const have = res[RES_KEYS[i]].value;
      if (!c) return `<div><dt>${RES_SHORT[i]}</dt><dd class="muted">—</dd></div>`;
      return c > have
        ? `<div class="short" data-tip="Have ${fmt.format(Math.floor(have))} · missing ${fmt.format(Math.ceil(c - have))}, ready in ${human((c - have) / res[RES_KEYS[i]].rate * 3600)}"><dt>${RES_SHORT[i]}</dt><dd>${fmt.format(c)}<small>−${fmt.format(Math.ceil(c - have))}</small></dd></div>`
        : `<div data-tip="Have ${fmt.format(Math.floor(have))} · ${fmt.format(Math.floor(have - c))} left after"><dt>${RES_SHORT[i]}</dt><dd>${fmt.format(c)}</dd></div>`;
    }).join('');
    const doneAt = new Date(Date.now() + (queueEnd() + b.time) * 1000);
    const full = queue.length >= 5;
    const btn = can
      ? `<button class="btn primary" type="button" data-build="${b.id}" ${full ? 'disabled title="Queue full"' : ''}>${icon('hammer')}Upgrade to ${b.lvl + queued + 1}</button>`
      : `<span data-tip="Affordable at ${hm(new Date(Date.now() + waitFor(b) * 1000))} with current production"><button class="btn is-wait" type="button" disabled>${icon('clock')}In ${human(waitFor(b))}</button></span>`;
    return `<article class="card${queued ? ' is-queued' : ''}${can ? '' : ' is-locked'}" style="--i:${animate ? idx : 0}">
      <div class="card-art"><img src="${ART}${b.id}.webp" alt="" loading="lazy">
        <span class="card-level" data-tip="${queued ? `Level ${b.lvl}, ${queued} more in queue` : `Current level ${b.lvl}`}">LV <b>${b.lvl}</b>${queued ? ` → ${b.lvl + queued}` : ''}</span></div>
      <div class="card-body"><h3>${b.name}</h3><p>${b.desc}</p><dl class="cost">${cost}</dl></div>
      <div class="card-foot"><span class="when" data-tip="Build time · finishes after the current queue"><span class="mono">${icon('timer')} ${clockFmt(b.time)}</span><small>done ${doneAt.toDateString() === new Date().toDateString() ? '' : 'tomorrow '}${hm(doneAt)}</small></span>
        ${btn}</div>
    </article>`;
  }).join('');
}

function renderQueue() {
  $$('[data-queue-count]').forEach((c) => { c.textContent = `${queue.length} / 5`; });
  const html = !queue.length ? '<li class="empty">Queue is empty, pick a building.</li>' : queue.map((q, i) => `<li class="q" style="--p:${i ? 0 : (1 - q.left / q.total).toFixed(4)}">
    <img src="${ART}${q.b.id}.webp" alt="">
    <div><strong>${q.b.name} <span class="lvl">${q.b.lvl + 1 + queue.slice(0, i).filter((o) => o.b === q.b).length}</span></strong>
      <span class="mono muted">${i ? 'queued · ' + clockFmt(q.total) : clockFmt(q.left) + ' left'}</span></div>
    <button class="icon-btn q-cancel" type="button" data-cancel="${i}" aria-label="Cancel and refund">${icon('x')}</button>
    <span class="q-bar"></span></li>`).join('');
  $$('[data-queue]').forEach((el) => { el.innerHTML = html; });
}

document.addEventListener('click', (e) => {
  const build = e.target.closest('[data-build]');
  if (build) {
    const b = buildings.find((x) => x.id === +build.dataset.build);
    b.cost.forEach((c, i) => { res[RES_KEYS[i]].value -= c; });
    const entry = { b, total: b.time, left: b.time };
    queue.push(entry);
    toast(`${b.name} queued`, 'circle-check', { label: 'Undo', run: () => {
      const i = queue.indexOf(entry);
      if (i < 0) return;
      queue.splice(i, 1);
      b.cost.forEach((c, k) => { res[RES_KEYS[k]].value += c; });
      renderResources(); renderBuildings(false); renderQueue();
    } });
    renderResources(); renderBuildings(false); renderQueue();
    return;
  }
  const cancel = e.target.closest('[data-cancel]');
  if (cancel) {
    const [q] = queue.splice(+cancel.dataset.cancel, 1);
    q.b.cost.forEach((c, i) => { res[RES_KEYS[i]].value += c; });
    toast(`${q.b.name} cancelled, resources refunded`, 'circle-x');
    renderResources(); renderBuildings(false); renderQueue();
    return;
  }
  const t = e.target.closest('[data-toast]');
  if (t) toast(t.dataset.toast, 'info');
});

$$('[data-filter]').forEach((btn) => btn.addEventListener('click', () => {
  filter = btn.dataset.filter;
  $$('[data-filter]').forEach((b) => b.setAttribute('aria-checked', String(b === btn)));
  renderBuildings();
}));

/* ---------- galaxy ---------- */
const NAMES = ['Arcadia', 'Vesper', 'Nox', 'Halcyon', 'Orris', 'Tamsin', 'Cinder', 'Lumen', 'Quill', 'Brakk', 'Sable', 'Mire'];
const PLAYERS = [['Ravager', 'strong', 'IRON'], ['tiberius', '', 'NOVA'], ['quietfox', 'inactive', ''], ['m0ss', 'weak', ''], ['Helix', 'vacation', 'NOVA'], ['Abraxis', 'self', 'V0']];
const ARTS = ['wuestenplanet01', 'eisplanet02', 'gasplanet03', 'wasserplanet06', 'trockenplanet01', 'dschjungelplanet07', 'trockenplanet09', 'wuestenplanet03'];
let system = 42;

function rng(seed) { return () => ((seed = (seed * 16807) % 2147483647) / 2147483647); }

function renderGalaxy() {
  const r = rng(system * 7919);
  const rows = [];
  for (let pos = 1; pos <= 15; pos++) {
    const self = system === 42 && pos === 8;
    if (!self && r() < .42) {
      rows.push(`<tr class="empty-slot"><td class="ctr mono">${pos}</td><td colspan="5">—</td><td class="act"><span class="acts"><button class="icon-btn" type="button" aria-label="Colonize position ${pos}">${icon('plus')}</button></span></td></tr>`);
      continue;
    }
    const [player, state, ally] = self ? PLAYERS[5] : PLAYERS[Math.floor(r() * 5)];
    const name = self ? 'Kepler Prime' : NAMES[Math.floor(r() * NAMES.length)];
    const art = self ? 'dschjungelplanet04' : ARTS[Math.floor(r() * ARTS.length)];
    const moon = r() < .25 ? `<span data-tip="Moon · ${fmt.format(Math.floor(r() * 4000 + 4000))} km">${icon('moon')}</span>` : '';
    const debris = r() < .2 ? `<span class="mono muted" data-tip="Debris field · needs recyclers">${fmt.format(Math.floor(r() * 90 + 5) * 1000)}</span>` : '';
    const tag = { strong: '<span class="tag is-strong" data-tip="Strong player, more than 5× your points">s</span>', weak: '<span class="tag is-weak" data-tip="Noob protected, can\'t be attacked">n</span>', inactive: '<span class="tag is-inactive" data-tip="Inactive for 7+ days">i</span>', vacation: '<span class="tag is-vacation" data-tip="Vacation mode, protected">v</span>' }[state] || '';
    rows.push(`<tr class="${self ? 'is-self' : ''}"><td class="ctr mono">${pos}</td>
      <td data-tip="[1:${system}:${pos}]"><img src="${PLANETS}${art}.webp" alt="">${name}</td>
      <td class="ctr muted">${moon}</td><td class="ctr">${debris}</td>
      <td>${player} ${tag}</td><td class="mono muted">${ally ? `[${ally}]` : ''}</td>
      <td class="act"><span class="acts">${self ? '' : `
        <button class="icon-btn" type="button" aria-label="Send espionage probes">${icon('scan-eye')}</button>
        <button class="icon-btn" type="button" aria-label="Attack this planet">${icon('crosshair')}</button>
        <button class="icon-btn" type="button" aria-label="Message ${player}">${icon('mail')}</button>`}</span></td></tr>`);
  }
  $('[data-galaxy]').innerHTML = rows.join('');
  $('[data-view-panel="galaxy"] h1 .mono').textContent = `1 : ${system}`;
  $('[data-sys-input]').value = system;
}

$$('[data-sys]').forEach((b) => b.addEventListener('click', () => { system = Math.min(499, Math.max(1, system + +b.dataset.sys)); renderGalaxy(); }));
$('[data-sys-input]').addEventListener('change', (e) => { system = Math.min(499, Math.max(1, +e.target.value || 1)); renderGalaxy(); });

/* ---------- views + nav ---------- */
const VIEWS = ['overview', 'buildings', 'galaxy'];
let current = null;
function show(view) {
  if (!VIEWS.includes(view)) { toast('Not part of this demo', 'info'); return; }
  if (view === current) return;
  const swap = () => {
    $$('[data-view-panel]').forEach((p) => { p.hidden = p.dataset.viewPanel !== view; });
    $$('.sidenav a').forEach((a) => (a.dataset.view === view ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current')));
    if (view === 'buildings') renderBuildings(true);
  };
  document.body.classList.remove('drawer-open');
  if (current && document.startViewTransition && !reduceMotion.matches) document.startViewTransition(swap);
  else swap();
  current = view;
  if (location.hash !== `#${view}`) history.replaceState(null, '', `#${view}`);
}
addEventListener('hashchange', () => { const v = location.hash.slice(1); if (VIEWS.includes(v)) show(v); });
$$('.sidenav a, .brand').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); show(a.getAttribute('href').slice(1)); }));

$$('[data-action="drawer"]').forEach((b) => b.addEventListener('click', () => document.body.classList.toggle('drawer-open')));

/* ---------- planet popover ---------- */
const planets = $('[data-planets]');
$('[data-action="planets"]').addEventListener('click', (e) => {
  const r = e.currentTarget.getBoundingClientRect();
  planets.style.insetBlockStart = `${r.bottom + 6}px`;
  planets.style.insetInlineStart = `${r.left}px`;
  planets.showModal();
});
$$('.planet-list li').forEach((li) => li.addEventListener('click', (e) => {
  e.preventDefault();
  const moon = e.target.closest('.pl-moon');
  $$('.planet-list li').forEach((o) => { o.classList.toggle('is-current', o === li && !moon); o.toggleAttribute('aria-current', o === li); });
  $$('.pl-moon').forEach((m) => m.classList.toggle('is-current', m === moon));
  toast(moon ? `Switched to moon of ${$('strong', li).textContent} (demo)` : `Switched to ${$('strong', li).textContent} (demo)`, moon ? 'moon' : 'orbit');
}));
planets.addEventListener('click', (e) => {
  const li = e.target.closest('li');
  if (li) toast(`Switched to ${$('strong', li).textContent} (demo)`, 'orbit');
  planets.close();
});

/* ---------- native popovers: anchored below their invoker ----------
   CSS anchor positioning does it where supported; otherwise place them from JS
   on click, beforetoggle and toggle (browsers differ in which of these fire usefully). */
const cssAnchors = CSS.supports('anchor-name: --a');
function placePopover(pop) {
  if (cssAnchors) return;
  const btn = $(`[popovertarget="${pop.id}"]`);
  const r = btn.getBoundingClientRect();
  const w = pop.offsetWidth || parseFloat(getComputedStyle(pop).width) || 0;  // measurable once open
  pop.style.top = `${Math.round(r.bottom + 8)}px`;
  pop.style.left = `${Math.round(Math.max(8, Math.min(r.right - w, document.documentElement.clientWidth - w - 8)))}px`;
  pop.style.right = 'auto'; pop.style.bottom = 'auto';
}
$$('[popover]').forEach((pop) => {
  const btn = $(`[popovertarget="${pop.id}"]`);
  btn?.addEventListener('click', () => placePopover(pop), { capture: true });
  pop.addEventListener('beforetoggle', (e) => { if (e.newState === 'open') { placePopover(pop); hideTip(); } });
  pop.addEventListener('toggle', (e) => { if (e.newState === 'open') placePopover(pop); });
});
addEventListener('resize', () => $$('[popover]:popover-open').forEach(placePopover));
$('[data-mark-read]').addEventListener('click', () => {
  $$('#notif-pop .is-unread').forEach((li) => li.classList.remove('is-unread'));
  const badge = $('[data-notif-badge]');
  badge.animate([{ scale: 1 }, { scale: 0, opacity: 0 }], { duration: 200, easing: 'ease-in', fill: 'forwards' }).finished.then(() => badge.remove());
});

/* ---------- shortcuts sheet ---------- */
const sheet = $('[data-shortcuts]');
const openShortcuts = () => { $('#account-pop').hidePopover?.(); sheet.showModal(); };
$$('[data-action="shortcuts"]').forEach((b) => b.addEventListener('click', openShortcuts));
$('[data-close]', sheet).addEventListener('click', () => sheet.close());
sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); });

/* ---------- attack warning ---------- */
const alertBox = $('[data-alert]');
$('[data-alert-close]').addEventListener('click', () => {
  alertBox.style.blockSize = `${alertBox.offsetHeight}px`;
  requestAnimationFrame(() => { alertBox.classList.add('is-hidden'); alertBox.style.blockSize = ''; });
  alertBox.addEventListener('transitionend', () => { alertBox.hidden = true; }, { once: true });
});

/* ---------- planet cycling with [ and ] ---------- */
function cyclePlanet(dir) {
  const items = $$('.planet-list li');
  const i = items.findIndex((li) => li.hasAttribute('aria-current'));
  items[(i + dir + items.length) % items.length].querySelector('a').click();
}

/* ---------- command palette ---------- */
const palette = $('[data-palette]');
const pInput = $('[data-palette-input]');
const pList = $('[data-palette-list]');
const commands = [
  ...$$('.sidenav a').map((a) => ({ group: 'Pages', label: $('span', a).textContent, icon: $('use', a).getAttribute('href').split('#i-')[1], run: () => show(a.getAttribute('href').slice(1)) })),
  ...$$('.planet-menu li').map((li) => ({ group: 'Planets', label: $('strong', li).textContent, hint: $('.mono', li).textContent, icon: 'globe', run: () => toast(`Switched to ${$('strong', li).textContent} (demo)`, 'orbit') })),
  ...buildings.slice(0, 6).map((b) => ({ group: 'Build', label: `Upgrade ${b.name}`, hint: `lv ${b.lvl + 1}`, icon: 'hammer', run: () => { show('buildings'); } })),
];
let sel = 0, shown = [];

function renderPalette() {
  const q = pInput.value.trim().toLowerCase();
  const coord = q.match(/^(\d{1,2}):(\d{1,3})(?::(\d{1,2}))?$/);
  shown = coord
    ? [{ group: 'Coordinates', label: `Open system ${coord[1]}:${coord[2]}`, hint: coord[3] ? `pos ${coord[3]}` : '', icon: 'orbit', run: () => { system = +coord[2]; renderGalaxy(); show('galaxy'); } }]
    : commands.filter((c) => !q || c.label.toLowerCase().includes(q) || c.group.toLowerCase().includes(q));
  sel = Math.min(sel, Math.max(0, shown.length - 1));
  let last = '';
  pList.innerHTML = shown.map((c, i) => {
    const head = c.group !== last ? `<li class="group" role="presentation">${(last = c.group)}</li>` : '';
    return `${head}<li role="option" data-i="${i}" aria-selected="${i === sel}">${icon(c.icon)}<span>${c.label}</span>${c.hint ? `<small>${c.hint}</small>` : ''}</li>`;
  }).join('') || '<li class="group">No matches</li>';
  $('[aria-selected="true"]', pList)?.scrollIntoView({ block: 'nearest' });
}
function openPalette() { pInput.value = ''; sel = 0; renderPalette(); palette.showModal(); pInput.focus(); }
function runSel(i = sel) { const c = shown[i]; if (c) { palette.close(); c.run(); } }

$('[data-action="palette"]').addEventListener('click', openPalette);
pInput.addEventListener('input', () => { sel = 0; renderPalette(); });
pInput.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowDown') { sel = (sel + 1) % shown.length; renderPalette(); e.preventDefault(); }
  if (e.key === 'ArrowUp') { sel = (sel - 1 + shown.length) % shown.length; renderPalette(); e.preventDefault(); }
  if (e.key === 'Enter') runSel();
});
pList.addEventListener('click', (e) => { const li = e.target.closest('[data-i]'); if (li) runSel(+li.dataset.i); });
palette.addEventListener('click', (e) => { if (e.target === palette) palette.close(); });

// global shortcuts: Ctrl/Cmd+K, "/" and two-key "g o / g b / g g"
let gPending = 0;
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette.open ? palette.close() : openPalette(); return; }
  if (e.target.closest('input, textarea, dialog')) return;
  if (e.key === '/') { e.preventDefault(); openPalette(); return; }
  if (e.key === '?') { openShortcuts(); return; }
  if (e.key === '[' || e.key === ']') { cyclePlanet(e.key === ']' ? 1 : -1); return; }
  if (e.key === 'g') { gPending = Date.now(); return; }
  if (Date.now() - gPending < 800) {
    const view = { o: 'overview', b: 'buildings', g: 'galaxy' }[e.key];
    if (view) show(view);
    gPending = 0;
  }
});

/* ---------- hero: moon on its orbit (passes behind the planet on the far side) ---------- */
const orbit = $('[data-orbit]');
const moonBtn = $('.orbit-moon', orbit);
let orbitT = 0.9, orbitPaused = false;
function placeMoon() {
  const ring = $('.orbit-ring', orbit);
  const a = ring.offsetWidth / 2, b = ring.offsetHeight / 2, tilt = -14 * Math.PI / 180;
  const x = a * Math.cos(orbitT), y = b * Math.sin(orbitT);
  const rx = x * Math.cos(tilt) - y * Math.sin(tilt), ry = x * Math.sin(tilt) + y * Math.cos(tilt);
  const behind = Math.sin(orbitT) < 0;
  moonBtn.style.translate = `${rx.toFixed(1)}px ${ry.toFixed(1)}px`;
  moonBtn.classList.toggle('is-behind', behind);
}
let orbitLast = performance.now();
function spin(now) {
  const dt = (now - orbitLast) / 1000; orbitLast = now;
  if (!orbitPaused && !reduceMotion.matches && !orbit.closest('[hidden]')) { orbitT += dt * 0.22; placeMoon(); }
  requestAnimationFrame(spin);
}
moonBtn.addEventListener('pointerenter', () => { orbitPaused = true; });
moonBtn.addEventListener('pointerleave', () => { orbitPaused = false; });
moonBtn.addEventListener('focus', () => { orbitPaused = true; });
moonBtn.addEventListener('blur', () => { orbitPaused = false; });
moonBtn.addEventListener('click', () => toast('Switched to moon of Kepler Prime (demo)', 'moon'));
placeMoon(); requestAnimationFrame(spin);

/* ---------- tooltips: one shared bubble replaces native title/alt hover text ----------
   Source: data-tip (text), data-tip-kbd (shortcut chips), else aria-label of icon-only controls.
   Short delay on first hover, instant while moving between items ("warm" mode). */
const bubble = Object.assign(document.createElement('div'), { className: 'bubble', role: 'tooltip', id: 'bubble' });
document.body.append(bubble);
let tipTarget = null, tipTimer = 0, warmUntil = 0;
const tipSource = (el) => el.closest('[data-tip], button[aria-label], a[aria-label], [title]');
function tipText(el) {
  if (el.hasAttribute('title')) { el.dataset.tip = el.title; el.removeAttribute('title'); }  // retire native tooltip
  return el.dataset.tip || el.getAttribute('aria-label') || '';
}
function openBubble(el) {
  const text = tipText(el);
  if (!text || el.closest('.res')) return;
  const kbd = el.dataset.tipKbd ? `<span class="bubble-kbd">${el.dataset.tipKbd.split(' ').map((k) => `<kbd>${k}</kbd>`).join('')}</span>` : '';
  bubble.innerHTML = `<span>${text}</span>${kbd}`;
  bubble.classList.remove('is-below');
  const r = el.getBoundingClientRect(), w = bubble.offsetWidth, h = bubble.offsetHeight;
  const below = r.top - h - 10 < 8;
  bubble.classList.toggle('is-below', below);
  bubble.style.top = `${Math.round(below ? r.bottom + 8 : r.top - h - 8)}px`;
  bubble.style.left = `${Math.round(Math.min(innerWidth - w - 8, Math.max(8, r.left + r.width / 2 - w / 2)))}px`;
  bubble.classList.add('is-on');
  el.setAttribute('aria-describedby', 'bubble');
}
function closeBubble() {
  clearTimeout(tipTimer);
  if (tipTarget) { tipTarget.removeAttribute('aria-describedby'); warmUntil = performance.now() + 400; }
  tipTarget = null; bubble.classList.remove('is-on');
}
function armBubble(el) {
  if (el === tipTarget) return;
  closeBubble(); tipTarget = el;
  const delay = performance.now() < warmUntil ? 0 : 450;
  tipTimer = setTimeout(() => tipTarget === el && openBubble(el), delay);
}
document.addEventListener('pointerover', (e) => {
  if (e.pointerType === 'touch') return;
  const el = tipSource(e.target);
  if (el) armBubble(el); else if (tipTarget) closeBubble();
});
document.addEventListener('pointerout', (e) => { if (tipTarget && !tipTarget.contains(e.relatedTarget)) closeBubble(); });
document.addEventListener('focusin', (e) => { const el = tipSource(e.target); if (el && e.target.matches(':focus-visible')) armBubble(el); });
document.addEventListener('focusout', closeBubble);
document.addEventListener('pointerdown', closeBubble);
addEventListener('scroll', closeBubble, { passive: true });

/* ---------- toasts ---------- */
function toast(text, name = 'circle-check', action = null) {
  const el = document.createElement('div');
  el.className = 'toast';
  el.innerHTML = `${icon(name)}<span>${text}</span>`;
  const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 220); };
  if (action) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'toast-action'; b.textContent = action.label;
    b.addEventListener('click', () => { action.run(); close(); });
    el.append(b);
  }
  $('[data-toasts]').append(el);
  setTimeout(close, action ? 5000 : 2600);
}

/* ---------- one ticker for everything ---------- */
const events = $$('[data-eta]').map((li) => ({ li, eta: +li.dataset.eta, total: +li.dataset.total, hostile: li.classList.contains('is-hostile') }));
function renderEvents() {
  for (const ev of events) {
    $('[data-countdown]', ev.li).textContent = clockFmt(ev.eta);
    $('[data-arrival]', ev.li).textContent = new Date(Date.now() + ev.eta * 1000).toTimeString().slice(0, 8);
    ev.li.style.setProperty('--p', (1 - ev.eta / ev.total).toFixed(4));
    ev.li.dataset.tip = `${Math.round((1 - ev.eta / ev.total) * 100)} % of the way · arrives ${new Date(Date.now() + ev.eta * 1000).toTimeString().slice(0, 8)}`;
    if (ev.hostile) $('[data-alert-countdown]').textContent = clockFmt(ev.eta);
  }
}
let last = performance.now();
function tick(now) {
  const dt = (now - last) / 1000;
  if (dt >= 1) {
    last = now;
    for (const r of Object.values(res)) r.value = Math.min(r.cap, r.value + r.rate / 3600 * dt);
    renderResources();
    for (const ev of events) ev.eta = Math.max(0, ev.eta - dt);
    renderEvents();
    if (queue[0]) {
      queue[0].left -= dt;
      if (queue[0].left <= 0) { const done = queue.shift(); done.b.lvl++; toast(`${done.b.name} reached level ${done.b.lvl}`); renderBuildings(false); }
      renderQueue();
    }
    const d = new Date();
    const clock = $('[data-clock]');
    clock.textContent = d.toTimeString().slice(0, 8);
    clock.dataset.tip = `Server time · ${d.toLocaleDateString('en-GB')}`;
  }
  requestAnimationFrame(tick);
}

renderResources(); renderBuildings(); renderQueue(); renderGalaxy(); renderEvents();
show(VIEWS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'overview');
requestAnimationFrame(tick);
