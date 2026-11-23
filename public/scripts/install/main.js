/** Installer: the language select in the top bar reloads the page with ?lang=<code>. */
document.addEventListener('change', (event) => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || !select.hasAttribute('data-lang-select')) return;
  const url = new URL(window.location.href);
  url.searchParams.set('lang', select.value);
  window.location.href = url.toString();
});

/** E-mail step: "require e-mail activation" is only available while mail is switched on. */
document.addEventListener('change', (event) => {
  const toggle = event.target;
  if (!(toggle instanceof HTMLInputElement) || !toggle.hasAttribute('data-mail-toggle')) return;
  const dependent = toggle.form?.querySelector('[data-mail-dependent]');
  if (!dependent) return;
  dependent.disabled = !toggle.checked;
  if (!toggle.checked) dependent.checked = false;
});

/** Options step: the sub-options of a switch (data-toggle-group="x", members data-toggle-member="x") are only available while it is on. */
function syncToggleGroup(toggle) {
  for (const member of toggle.form?.querySelectorAll(`[data-toggle-member="${toggle.dataset.toggleGroup}"]`) ?? []) {
    member.disabled = !toggle.checked;
  }
}
document.addEventListener('change', (event) => {
  if (event.target instanceof HTMLInputElement && event.target.hasAttribute('data-toggle-group')) syncToggleGroup(event.target);
});
document.querySelectorAll('[data-toggle-group]').forEach(syncToggleGroup);
