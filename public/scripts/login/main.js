/**
 * Start page: language select, menu toggle (narrow screens), universe start countdowns, universe
 * based form urls, screenshot lightbox (lightbox.js), auto-submitting selects ([data-autosubmit]), browser time zone
 * preselection on the register form. Native ES module; the configuration comes from the JSON island
 * #login-config ({isMultiUniverse, unisWildcast, referralEnable, basePath}) of the layout.
 */
import '../app/csrf.js';
import { qs, qsa, on, ready, cookie, pageData } from '../app/core.js';
import { initLightbox } from './lightbox.js';

const config = pageData('login-config');

function fbLinks() {
  return qsa('.fb_login');
}

/** Points the login form and the social login links at the selected universe: `uni<N>.` subdomain (wildcard setups) or `uni<N>/` path prefix; universe 0 keeps the base path. */
function updateUrls(select) {
  const universe = select.value;
  const form = select.closest('form');
  let base = config.basePath;
  if (config.unisWildcast && universe != 0) {
    base = config.basePath.replace('://', `://uni${universe}.`);
  } else if (universe != 0) {
    base = `${config.basePath}uni${universe}/`;
  }
  if (form) form.setAttribute('action', base + form.dataset.action);
  for (const link of fbLinks()) {
    link.setAttribute('href', base + link.dataset.href);
  }
}

/** "In: 3d 04h 12m 09s" until the universe opens (texts from data attributes) */
function countdowns() {
  for (const element of qsa('.uni-countdown[data-start-timestamp]')) {
    const target = Number(element.dataset.startTimestamp);
    const render = () => {
      let rest = target - Math.floor(Date.now() / 1000);
      if (rest <= 0) {
        element.textContent = element.dataset.textDone ?? '';
        element.classList.add('is-open');
        return false;
      }
      const days = Math.floor(rest / 86400); rest %= 86400;
      const hours = Math.floor(rest / 3600); rest %= 3600;
      const pad = (value) => String(value).padStart(2, '0');
      element.textContent = `${element.dataset.textIn ?? ''} ${days}d ${pad(hours)}h ${pad(Math.floor(rest / 60))}m ${pad(rest % 60)}s`.trim();
      return true;
    };
    if (render()) {
      const timer = setInterval(() => { if (!render()) clearInterval(timer); }, 1000);
    }
  }
}

ready(() => {
  // language picker: the header button opens the modal list; a click on the backdrop closes it, a choice stores the cookie
  on(document, 'click', '[data-lang-open]', () => {
    const dialog = document.getElementById('lang-dialog');
    if (dialog && !dialog.open) dialog.showModal();
    dialog?.querySelector('[aria-current="true"]')?.focus();
  });
  on(document, 'click', '#lang-dialog', (event, dialog) => {
    if (event.target === dialog) dialog.close();
  });
  on(document, 'click', '#lang-dialog a[href*="lang="]', (event, link) => {
    const code = new URL(link.href).searchParams.get('lang');
    if (code) cookie.set('lang', code);
  });

  // narrow screens: the toggle opens the menu; Escape, a click outside or a wider window closes it
  const menuButton = qs('[data-site-menu]');
  const setMenu = (open) => {
    document.body.classList.toggle('nav-open', open);
    menuButton?.setAttribute('aria-expanded', String(open));
  };
  on(document, 'click', '[data-site-menu]', () => setMenu(!document.body.classList.contains('nav-open')));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
      setMenu(false);
      menuButton?.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (document.body.classList.contains('nav-open') && !event.target.closest('.site-header')) setMenu(false);
  });
  window.matchMedia('(width > 60rem)').addEventListener('change', (event) => { if (event.matches) setMenu(false); });

  on(document, 'change', '[data-autosubmit]', (event, select) => select.form.submit());

  const timezone = document.getElementById('timezone');
  if (timezone) timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone;

  countdowns();

  initLightbox();

  if (config.isMultiUniverse) {
    for (const select of qsa('.changeAction')) {
      updateUrls(select);
      select.addEventListener('change', () => updateUrls(select));
    }
  } else {
    for (const link of fbLinks()) {
      link.setAttribute('href', config.basePath + link.dataset.href);
    }
  }
});
