/**
 * In-game chat widget (module MODULE_CHAT; native ES module, loaded only while the module is on). Plain HTTP polling, no
 * push: panel open and tab visible = every 4 s (messages of the open channel plus the unread counts), panel closed = every
 * 30 s (counts only), tab hidden = no polling, after errors 15 s and then 60 s. Open state, tab and last-read ids live in
 * localStorage. Messages are plain text and are only ever written with textContent.
 * Markup: templates/game/chat.widget.html.twig (data island "chat-config" with URLs and texts).
 */
import { qs, qsa, on, pageData, post } from '../app/core.js';

const config = pageData('chat-config');
const panel = qs('#chat-panel');
if (panel && config.poll) {
  const OPEN_MS = 4000;
  const CLOSED_MS = 30000;
  const RETRY_MS = [15000, 60000];
  const KEEP = 200;
  const list = qs('[data-chat-list]', panel);
  const input = qs('[data-chat-input]', panel);
  const status = qs('[data-chat-status]', panel);
  const form = qs('[data-chat-form]', panel);
  const phone = window.matchMedia('(max-width: 60rem)');
  const storeKey = `v0chat:${config.uid}`;

  const saved = (() => {
    try {
      return JSON.parse(localStorage.getItem(storeKey) ?? '{}') ?? {};
    } catch {
      return {};
    }
  })();
  const state = {
    open: false,
    channel: (saved.channel === 'alliance' && config.alliance) || !config.global ? 'alliance' : 'global',
    read: { global: Number.isInteger(saved.read?.global) ? saved.read.global : null, alliance: Number.isInteger(saved.read?.alliance) ? saved.read.alliance : null },
    unread: { global: 0, alliance: 0 },
    mod: false,
    slow: { global: 0, alliance: 0 },
  };
  /** per channel: epoch ms until which the next message is refused (slow mode countdown) */
  const until = { global: 0, alliance: 0 };
  let ticker = null;
  const messages = { global: [], alliance: [] };
  let timer = null;
  let busy = false;
  let failures = 0;
  let stopped = false;
  let throttled = 0;
  let opener = null;

  const persist = () => {
    try {
      localStorage.setItem(storeKey, JSON.stringify({ open: state.open, channel: state.channel, read: state.read }));
    } catch {
      /* storage blocked: the chat still works for this page */
    }
  };

  // ------------------------------------------------------------------------------------ rendering

  /** an icon of the sprite the page already loads (the sprite URL is copied from an existing icon) */
  const icon = (name) => {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS(ns, 'use');
    const base = qs('svg.icon use')?.getAttribute('href')?.split('#')[0] ?? '';
    use.setAttribute('href', `${base}#i-${name}`);
    svg.append(use);
    return svg;
  };

  /** the "more" button of a message: opens the shared menu (block, report, and for moderators delete / mute) */
  const kebab = (message) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'chat__more';
    button.dataset.chatMore = String(message.id);
    button.dataset.own = message.own ? '1' : '0';
    button.setAttribute('aria-label', config.texts.more);
    button.setAttribute('aria-haspopup', 'menu');
    button.append(icon('ellipsis-vertical'));
    return button;
  };

  const timeText = (seconds) => new Date(seconds * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  /** one message as a list item; every user-supplied string goes in through textContent */
  const item = (message) => {
    const li = document.createElement('li');
    li.className = `chat__msg${message.own ? ' is-own' : ''}`;
    li.dataset.id = String(message.id);
    const time = document.createElement('time');
    time.className = 'chat__time mono';
    time.dateTime = new Date(message.time * 1000).toISOString();
    time.textContent = timeText(message.time);
    const user = document.createElement('b');
    user.className = message.cls ? `chat__user ${message.cls}` : 'chat__user'; // cls: whitelisted name colour class from the server
    user.textContent = message.ally ? `[${message.ally}] ${message.user}` : message.user;
    const text = document.createElement('span');
    text.className = 'chat__text';
    text.textContent = message.text;
    if (message.uid === 0) {
      // system line (battle feed): the report link is built from the whitelisted id and the route template of the island
      li.classList.add('is-system');
      user.textContent = '\u2694';
      if (message.report && config.battle) {
        const link = document.createElement('a');
        link.href = config.battle.replace('RID', encodeURIComponent(message.report));
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = config.texts.report;
        text.append(' ', link);
      }
    }
    li.append(time, user, text);
    if (message.uid !== 0 ? !message.own || state.mod : state.mod) li.append(kebab(message));
    return li;
  };

  const nearBottom = () => list.scrollHeight - list.scrollTop - list.clientHeight < 48;
  const toBottom = () => { list.scrollTop = list.scrollHeight; };

  /** whole list of the active channel (tab switch, first load) */
  const renderAll = () => {
    list.setAttribute('aria-live', 'off');
    list.replaceChildren(...messages[state.channel].map(item));
    if (messages[state.channel].length === 0) {
      const empty = document.createElement('li');
      empty.className = 'chat__empty muted';
      empty.textContent = config.texts.empty;
      list.append(empty);
    }
    toBottom();
    requestAnimationFrame(() => list.setAttribute('aria-live', 'polite'));
  };

  const setStatus = (text) => { status.textContent = text; };

  const slowHint = qs('[data-chat-slow]', panel);
  const sendButton = qs('button[type=submit]', form);
  const sendLabel = qs('span', sendButton);
  const sendText = sendLabel.textContent;

  /** slow mode hint of the open channel and the send button countdown */
  const renderSlow = () => {
    const seconds = state.slow[state.channel] ?? 0;
    slowHint.hidden = seconds <= 0;
    slowHint.textContent = seconds > 0 ? config.texts.slowHint.replace('%s', String(seconds)) : '';
    const left = Math.ceil((until[state.channel] - Date.now()) / 1000);
    sendButton.disabled = left > 0;
    sendLabel.textContent = left > 0 ? `${left} s` : sendText;
    if (left <= 0 && ticker !== null && Object.values(until).every((t) => t <= Date.now())) {
      clearInterval(ticker);
      ticker = null;
    }
  };

  /** the next message of a channel is allowed in `seconds` seconds: disable the button and count down */
  const startCooldown = (channel, seconds) => {
    until[channel] = Date.now() + seconds * 1000;
    if (ticker === null) ticker = setInterval(renderSlow, 500);
    renderSlow();
  };

  const lastId = (channel) => messages[channel].at(-1)?.id ?? 0;

  const renderBadges = () => {
    const total = Object.entries(state.unread).filter(([name]) => name !== state.channel || !state.open).reduce((sum, [, n]) => sum + n, 0);
    const label = total > 99 ? '99+' : String(total);
    qsa('[data-chat-badge]').forEach((badge) => {
      badge.hidden = total === 0;
      badge.textContent = label;
    });
    qsa('[data-chat-toggle]:not(.chat-fab)').forEach((link) => {
      let count = qs('.count', link);
      if (total > 0 && !count) {
        count = document.createElement('span');
        count.className = 'count';
        link.append(count);
      }
      if (count) {
        count.hidden = total === 0;
        count.textContent = label;
      }
      link.setAttribute('aria-label', total > 0 ? `${config.texts.unread}: ${label}` : '');
      if (total === 0) link.removeAttribute('aria-label');
    });
    qsa('[data-chat-tab-badge]').forEach((badge) => {
      const n = badge.dataset.chatTabBadge === state.channel && state.open ? 0 : (state.unread[badge.dataset.chatTabBadge] ?? 0);
      badge.hidden = n === 0;
      badge.textContent = n > 99 ? '99+' : String(n);
    });
  };

  // ------------------------------------------------------------------------------------ polling

  const delay = () => {
    if (failures > 0) return RETRY_MS[Math.min(failures, RETRY_MS.length) - 1];
    return state.open ? OPEN_MS : CLOSED_MS;
  };

  const schedule = (ms = delay()) => {
    clearTimeout(timer);
    if (!document.hidden && !stopped) timer = setTimeout(poll, ms);
  };

  /** the session is gone (401, or the login page came back instead of JSON): stop polling and ask for a reload */
  const expire = () => {
    stopped = true;
    clearTimeout(timer);
    setStatus(config.texts.errSession);
    form.querySelectorAll('input, button').forEach((el) => { el.disabled = true; });
  };

  /** one poll request; resolves to the parsed answer, or null when the session ended or the server asked to slow down */
  const fetchPoll = async () => {
    const response = await fetch(query(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (response.status === 401) return expire();
    if (response.status === 429) {
      throttled = Number(response.headers.get('Retry-After')) || 30;
      return null;
    }
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    if (response.redirected || !(response.headers.get('Content-Type') ?? '').includes('json')) return expire();
    return response.json();
  };

  const query = () => {
    const params = new URLSearchParams();
    if (state.open) {
      params.set('channel', state.channel);
      params.set('after', String(lastId(state.channel)));
      const first = messages[state.channel][0]?.id;
      if (first) params.set('from', String(first));
    }
    if (config.global && state.read.global !== null) params.set('rg', String(state.read.global));
    if (state.read.alliance !== null && config.alliance) params.set('ra', String(state.read.alliance));
    return `${config.poll}?${params}`;
  };

  /** merge a poll answer: new messages, deleted ids, unread counts, starting points of channels never read */
  const apply = (data, channel, wasOpen) => {
    state.mod = Boolean(data.mod);
    state.slow = { global: data.slow?.global ?? 0, alliance: data.slow?.alliance ?? 0 };
    renderSlow();
    for (const [name, id] of Object.entries(data.latest ?? {})) {
      if (state.read[name] === null) state.read[name] = id;
    }
    for (const name of Object.keys(messages)) state.unread[name] = data.unread?.[name] ?? 0;

    if (wasOpen && channel === state.channel && state.open) {
      const gone = new Set(data.deleted ?? []);
      if (gone.size > 0) {
        messages[channel] = messages[channel].filter((message) => !gone.has(message.id));
        qsa('.chat__msg', list).forEach((li) => { if (gone.has(Number(li.dataset.id))) li.remove(); });
      }
      const fresh = (data.messages ?? []).filter((message) => !messages[channel].some((known) => known.id === message.id));
      if (fresh.length > 0) {
        const wasEmpty = messages[channel].length === 0;
        const stick = nearBottom() || wasEmpty;
        messages[channel] = [...messages[channel], ...fresh].slice(-KEEP);
        if (wasEmpty) {
          renderAll();
        } else {
          list.append(...fresh.map(item));
          qsa('.chat__msg', list).slice(0, -KEEP).forEach((li) => li.remove());
          if (stick) toBottom();
        }
      } else if (messages[channel].length === 0 && !qs('.chat__empty', list)) {
        renderAll();
      }
      state.read[channel] = Math.max(state.read[channel] ?? 0, lastId(channel));
      state.unread[channel] = 0;
    }
    persist();
    renderBadges();
    return (data.messages ?? []).length >= 50;
  };

  async function poll() {
    if (busy || document.hidden) return;
    busy = true;
    const channel = state.channel;
    const wasOpen = state.open;
    let more = false;
    try {
      throttled = 0;
      const data = await fetchPoll();
      if (data) {
        if (data.error) throw new Error(data.error);
        failures = 0;
        more = apply(data, channel, wasOpen);
        if (wasOpen) setStatus('');
      }
    } catch {
      failures += 1;
      if (state.open) setStatus(config.texts.errNetwork);
    } finally {
      busy = false;
    }
    schedule(more ? 300 : Math.max(delay(), throttled * 1000));
  }

  // ------------------------------------------------------------------------------------ panel

  const behind = () => qsa('.topbar, .notices, .shell, .tabbar, .sidebar-backdrop');
  const syncInert = () => behind().forEach((el) => { el.inert = state.open && phone.matches; });

  const selectTab = (channel, focus = false) => {
    state.channel = channel;
    qsa('[data-chat-tab]', panel).forEach((tab) => {
      const on = tab.dataset.chatTab === channel;
      tab.setAttribute('aria-selected', String(on));
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    renderAll();
    renderSlow();
    persist();
    renderBadges();
    schedule(0);
  };

  const setOpen = (open, refocus = true) => {
    if (open === state.open) return;
    state.open = open;
    panel.hidden = !open;
    qsa('[data-chat-toggle]').forEach((button) => button.setAttribute('aria-expanded', String(open)));
    syncInert();
    if (open) {
      // the menu drawer of phones closes first
      if (document.body.classList.contains('sidebar-open')) qs('.tabbar [data-sidebar-toggle]')?.click();
      selectTab(state.channel);
      requestAnimationFrame(() => input.focus({ preventScroll: true }));
    } else {
      setStatus('');
      if (refocus && opener?.isConnected) opener.focus({ preventScroll: true });
    }
    persist();
    renderBadges();
    schedule(0);
  };

  on(document, 'click', '[data-chat-toggle]', (event, button) => {
    event.preventDefault();
    if (!state.open) opener = button;
    setOpen(!state.open);
  });
  on(panel, 'click', '[data-chat-close]', () => setOpen(false));
  on(panel, 'click', '[data-chat-tab]', (event, tab) => selectTab(tab.dataset.chatTab));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.open && !document.querySelector('dialog[open], :popover-open')) setOpen(false);
  });
  panel.addEventListener('keydown', (event) => {
    if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && event.target instanceof Element && event.target.matches('[data-chat-tab]')) {
      const tabs = qsa('[data-chat-tab]', panel);
      selectTab(tabs[(tabs.indexOf(event.target) + 1) % tabs.length].dataset.chatTab, true);
    }
  });
  phone.addEventListener('change', syncInert);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      clearTimeout(timer);
    } else {
      schedule(0);
    }
  });

  // ------------------------------------------------------------------------------------ sending, moderation

  const errorText = (code, wait = 0) => ({
    empty: config.texts.errEmpty,
    rate: config.texts.errRate,
    muted: config.texts.errMuted,
    banned: config.texts.errBanned,
    channel: config.texts.errChannel,
    link: config.texts.errLink,
    caps: config.texts.errCaps,
    duplicate: config.texts.errDuplicate,
    age: config.texts.errAge,
    points: config.texts.errPoints,
    global_off: config.texts.errGlobalOff,
    reported: config.texts.errReported,
    unknown: config.texts.errUnknown,
    limit: config.texts.errLimit,
    report_limit: config.texts.errReportLimit,
    session: config.texts.errSession,
    slow: config.texts.errSlow,
  })[code]?.replace('%s', String(wait)) ?? config.texts.errNetwork;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (text === '' || form.dataset.sending || sendButton.disabled) return;
    form.dataset.sending = '1';
    try {
      const channel = state.channel;
      const data = await post(config.send, { channel, message: text }, 'json');
      if (data.ok) {
        input.value = '';
        setStatus('');
        const seconds = state.slow[channel] ?? 0;
        if (seconds > 0) startCooldown(channel, seconds);
        schedule(0);
      } else {
        if (data.error === 'slow' && data.wait > 0) startCooldown(channel, data.wait);
        setStatus(errorText(data.error, data.wait));
      }
    } catch {
      setStatus(config.texts.errNetwork);
    } finally {
      delete form.dataset.sending;
      input.focus({ preventScroll: true });
    }
  });

  // ---- per-message menu (native popover, positioned under the button) and the report dialog
  const menu = qs('[data-chat-menu]');
  const dialog = qs('[data-chat-report]');
  let target = null;

  const openMenu = (button) => {
    target = { id: button.dataset.chatMore, own: button.dataset.own === '1', button };
    qsa('[data-chat-menu-action]', menu).forEach((item) => {
      const action = item.dataset.chatMenuAction;
      item.hidden = target.own ? action !== 'delete' : (item.hasAttribute('data-chat-mod') && !state.mod);
    });
    const rect = button.getBoundingClientRect();
    const width = 272;
    menu.style.top = `${Math.round(Math.min(rect.bottom + 4, window.innerHeight - 180))}px`;
    menu.style.left = `${Math.round(Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)))}px`;
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';
    menu.showPopover();
    qsa('button:not([hidden])', menu)[0]?.focus();
  };

  on(list, 'click', '[data-chat-more]', (event, button) => openMenu(button));

  /** after a block: the author's messages leave the lists at once (the server hides them from the next poll on) */
  const dropAuthor = (messageId) => {
    const uid = Object.values(messages).flat().find((message) => String(message.id) === String(messageId))?.uid;
    if (uid === undefined) return;
    for (const name of Object.keys(messages)) messages[name] = messages[name].filter((message) => message.uid !== uid);
    renderAll();
  };

  const errorOf = (data) => (data.error ? errorText(data.error) : '');

  on(menu, 'click', '[data-chat-menu-action]', async (event, item) => {
    const action = item.dataset.chatMenuAction;
    const current = target;
    menu.hidePopover();
    if (!current) return;
    if (action === 'report') {
      dialog.dataset.id = current.id;
      qs('[data-chat-report-status]', dialog).textContent = '';
      qs('[name=comment]', dialog).value = '';
      dialog.showModal();
      return;
    }
    if (action === 'mute' && !window.confirm(config.texts.muteConfirm)) return;
    const urls = { block: config.block, delete: config.delete, mute: config.mute };
    try {
      const data = await post(urls[action], action === 'mute' ? { id: current.id, hours: 24 } : { id: current.id }, 'json');
      setStatus(action === 'block' && data.ok ? config.texts.blocked : errorOf(data));
      if (action === 'block' && data.ok) dropAuthor(current.id);
      schedule(0);
    } catch {
      setStatus(config.texts.errNetwork);
    }
  });

  qs('[data-chat-report-cancel]', dialog).addEventListener('click', () => dialog.close());
  qs('[data-chat-report-form]', dialog).addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const box = qs('[data-chat-report-status]', dialog);
    try {
      const data = await post(config.report, { id: dialog.dataset.id, reason: form.reason.value, comment: form.comment.value }, 'json');
      if (data.ok) {
        dialog.close();
        setStatus(config.texts.reported);
      } else {
        box.textContent = errorText(data.error);
      }
    } catch {
      box.textContent = config.texts.errNetwork;
    }
  });

  // ------------------------------------------------------------------------------------ start

  if (saved.open === true && !phone.matches) {
    setOpen(true, false);
  } else {
    schedule(0);
  }
}
