import { on } from '../app/core.js';

/** Copy-link button of a report: copies the public report URL (clipboard API, prompt as fallback). */
on(document, 'click', '[data-copy-url]', (event, button) => {
  const url = button.dataset.copyUrl;
  const label = button.querySelector('span');
  const done = () => {
    if (!label) return;
    const old = label.textContent;
    label.textContent = button.dataset.copied;
    setTimeout(() => { label.textContent = old; }, 2000);
  };
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(url).then(done, () => window.prompt('', url));
  } else {
    window.prompt('', url);
  }
});
