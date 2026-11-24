/**
 * Relics section (templates/game/engagement/_relics.html.twig): client-side filter and sort of the storage grid, a
 * compare card for the selected relic and a picker dialog per slot. Without JS the server-rendered forms in every
 * tile keep working. All nodes come from clones of the server markup or textContent (no innerHTML).
 */
const STAT = '.rl-main, .rl-subs li';

/** @param {string} tag @param {string} [className] @param {string} [text] */
function el(tag, className, text) {
	const node = document.createElement(tag);
	if (className) node.className = className;
	if (text !== undefined) node.textContent = text;
	return node;
}

/** @param {Element} tile signed magnitude per stat key: {label, mag, unit} (reductions count as positive magnitude) */
function statMap(tile) {
	const map = new Map();
	tile.querySelectorAll(STAT).forEach((node) => {
		if (node.dataset.inactive) return;
		const d = node.dataset;
		map.set(d.stat, { label: d.label, mag: parseFloat(d.value) || 0, unit: d.text.replace(/^[+\-−]?[\d.,]+/, '').trim() });
	});
	return map;
}

/** copies of a tile's main stat line and substat list */
function statBlock(tile) {
	const frag = document.createDocumentFragment();
	const main = tile.querySelector('.rl-main');
	if (main) frag.append(main.cloneNode(true));
	const subs = tile.querySelector('.rl-subs');
	if (subs) frag.append(subs.cloneNode(true));
	return frag;
}

/** a read-only copy of a tile's head and stats */
function summary(tile) {
	const box = el('div', 'rl-cmp__card ' + [...tile.classList].find((c) => c.startsWith('rl-r-')));
	const head = tile.querySelector('.rl-tile__head').cloneNode(true);
	head.querySelector('.rl-lock')?.remove();
	box.append(head);
	box.append(statBlock(tile));
	return box;
}

/** stat differences selected - equipped, coloured by the sign of the difference of the magnitudes */
function diffList(selected, current, same) {
	const a = statMap(selected);
	const b = statMap(current);
	const list = el('ul', 'rl-diff');
	for (const key of new Set([...a.keys(), ...b.keys()])) {
		const x = a.get(key);
		const y = b.get(key);
		const delta = Math.round(((x?.mag ?? 0) - (y?.mag ?? 0)) * 100) / 100;
		const item = el('li', delta > 0 ? 'is-up' : delta < 0 ? 'is-down' : 'is-same');
		const unit = (x ?? y).unit;
		const value = delta === 0 ? same : `${delta > 0 ? '+' : '−'}${Math.abs(delta).toFixed(2)} ${unit}`.trim();
		item.append(el('span', '', (x ?? y).label), el('b', '', value));
		list.append(item);
	}
	return list;
}

function init() {
	const root = document.querySelector('[data-relics]');
	if (!root) return;
	const grid = root.querySelector('[data-rl-grid]');
	const labels = root.dataset;
	const tiles = grid ? [...grid.querySelectorAll('.rl-tile')] : [];
	const detail = root.querySelector('[data-rl-detail]');
	const filters = root.querySelector('[data-rl-filters]');
	const sortBox = root.querySelector('[data-rl-sort]');
	const nomatch = root.querySelector('[data-rl-nomatch]');
	let selected = null;

	const applyFilter = () => {
		const want = {};
		root.querySelectorAll('[data-rl-filter]').forEach((s) => { want[s.dataset.rlFilter] = s.value; });
		const order = sortBox?.value === 'newest'
			? (a, b) => Number(b.dataset.relic) - Number(a.dataset.relic)
			: (a, b) => Number(b.dataset.rank) - Number(a.dataset.rank) || Number(b.dataset.relic) - Number(a.dataset.relic);
		let shown = 0;
		for (const tile of [...tiles].sort(order)) {
			const ok = Object.entries(want).every(([key, value]) => !value || tile.dataset[key] === value);
			tile.hidden = !ok;
			if (ok) shown++;
			grid.append(tile);
		}
		if (nomatch) nomatch.hidden = shown > 0;
	};

	const select = (tile) => {
		selected?.classList.remove('is-selected');
		selected = selected === tile ? null : tile;
		if (!detail) return;
		detail.replaceChildren();
		detail.hidden = !selected;
		if (!selected) return;
		selected.classList.add('is-selected');
		const here = tiles.find((t) => t.dataset.here === '1' && t.dataset.slotno === selected.dataset.slotno);
		const wrap = el('div', 'rl-cmp');
		wrap.append(el('h4', '', labels.lSelected), summary(selected));
		if (here && here !== selected) {
			wrap.append(el('h4', '', labels.lHere), summary(here), diffList(selected, here, labels.lSame));
		} else if (!here) {
			const none = el('div', 'rl-cmp__card is-none', labels.lEmpty);
			wrap.append(el('h4', '', labels.lHere), none);
		}
		const acts = selected.querySelector('.rl-acts');
		if (acts) wrap.append(acts.cloneNode(true));
		detail.append(wrap);
	};

	if (grid) {
		filters.hidden = false;
		root.querySelectorAll('[data-rl-filter], [data-rl-sort]').forEach((s) => s.addEventListener('change', applyFilter));
		tiles.forEach((tile) => {
			const pick = tile.querySelector('.rl-tile__pick');
			pick.hidden = false;
			pick.addEventListener('click', () => select(tile));
		});
		applyFilter();
	}

	const dialog = root.querySelector('[data-rl-picker]');
	const list = root.querySelector('[data-rl-picker-list]');
	root.querySelectorAll('[data-rl-choose]').forEach((link) => {
		link.addEventListener('click', (event) => {
			if (!dialog || typeof dialog.showModal !== 'function') return;
			event.preventDefault();
			const slot = link.dataset.rlChoose;
			list.replaceChildren();
			const items = tiles.filter((t) => t.dataset.slotno === slot && t.dataset.here !== '1');
			items.sort((a, b) => Number(b.dataset.rank) - Number(a.dataset.rank) || Number(b.dataset.relic) - Number(a.dataset.relic));
			for (const tile of items) {
				const li = el('li', tile.className.replace('is-selected', '').trim());
				li.append(tile.querySelector('.rl-tile__head').cloneNode(true));
				const stats = el('div', 'rl-stats');
				stats.append(statBlock(tile));
				li.append(stats);
				const equip = tile.querySelector('.rl-act--equip');
				if (equip) li.append(equip.cloneNode(true));
				list.append(li);
			}
			if (items.length === 0) list.append(el('li', 'empty', labels.lNone));
			dialog.showModal();
		});
	});
	dialog?.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
}

window.onPageInit(init);
