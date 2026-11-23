/**
 * Installer, "build the database": runs the tasks of the list one request each
 * (`<data-build-url>&task=<name>`, JSON {ok, message}) and ticks them off; starts by itself and continues when all are done.
 */
const list = document.querySelector('.install-build');
const start = document.querySelector('.install-build__start');
const errorBox = document.querySelector('.install-build__error');
const back = document.querySelector('.install-build__back');

/** Runs one build task on the server (GET with `task=<name>`), marks the list item running/ok/failed and shows the error with the back link on failure. Returns true on success. */
async function runTask(item) {
  item.className = 'is-running';
  try {
    const response = await fetch(`${list.dataset.buildUrl}&task=${encodeURIComponent(item.dataset.task)}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const result = await response.json();
    if (!result.ok) throw new Error(result.message || `HTTP ${response.status}`);
    item.className = 'is-ok';
    return true;
  } catch (error) {
    item.className = 'is-fail';
    errorBox.querySelector('div').textContent = error.message;
    errorBox.hidden = false;
    back.hidden = false;
    return false;
  }
}

if (list && start) {
  start.addEventListener('click', async () => {
    start.disabled = true;
    for (const item of list.querySelectorAll('li[data-task]')) {
      if (item.classList.contains('is-ok')) continue;
      if (!(await runTask(item))) {
        start.hidden = true;
        return;
      }
    }
    window.location.href = list.dataset.nextUrl;
  });
  // nothing to confirm: start right away (without JavaScript the <noscript> link runs all tasks)
  if (!document.querySelector('.install-build__back:not([hidden])')) start.click();
}
