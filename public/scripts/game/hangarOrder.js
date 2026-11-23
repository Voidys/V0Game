import { qsa, pageData, now, getRestTimeFormat, getFormatedDate, pretty_number } from '../app/core.js';
import { env } from '../app/env.js';

/**
 * Shipyard / defense order forms: "max" button, "build up to" mode and the live preview (total cost, build time and
 * finish time counting the running queue). The server recomputes everything on submit (field orderMode=upto).
 * Data island #hangar-order-data: {queueTime, umode, names, preview, current}.
 */
const { queueTime, umode, names, preview, current } = pageData('hangar-order-data');

/** Seconds of the running queue still left now (it was rendered at page load). */
function queueLeft() {
    if (Number(umode) === 1) {
        return Number(queueTime);
    }
    return Math.max(Number(queueTime) - (now().getTime() - env.startTime) / 1000, 0);
}

/** Units the current input actually orders: the amount itself, or target minus owned and queued in "up to" mode (never below 0, not above max). */
function ordered(form, input) {
    const typed = Math.max(Math.floor(Number(input.value) || 0), 0);
    const max = Number(form.dataset.max) || 0;
    let amount = typed;
    if (form.querySelector('[data-hangar-upto]').checked) {
        amount = Math.max(typed - Number(form.dataset.owned || 0) - Number(form.dataset.queued || 0), 0);
    }
    return Math.min(amount, max);
}

/** Fills the line under the form with the cost, duration and end time of the order (hidden for 0 units). */
function update(form) {
    const input = form.querySelector('input[type="number"]');
    const line = form.querySelector('[data-hangar-preview]');
    const n = ordered(form, input);
    if (n <= 0) {
        line.hidden = true;
        return;
    }
    const cost = JSON.parse(form.dataset.cost || '{}');
    const parts = Object.keys(cost).filter((id) => Number(cost[id]) > 0).map((id) => pretty_number(Number(cost[id]) * n) + ' ' + (names[id] ?? id));
    const seconds = Number(form.dataset.unitTime) * n;
    const total = queueLeft() + seconds;
    const end = now().getTime() + total * 1000;
    const sameDay = getFormatedDate(end, '[Y][m][d]') === getFormatedDate(now().getTime(), '[Y][m][d]');
    const clock = getFormatedDate(end, sameDay ? '[H]:[i]' : '[d].[m]. [H]:[i]');
    let text = preview.replace('%cost%', parts.join(', ')).replace('%time%', getRestTimeFormat(seconds, true)).replace('%at%', clock).replace('%in%', getRestTimeFormat(total, true));
    if (form.querySelector('[data-hangar-upto]').checked) {
        text = current.replace('%n%', pretty_number(n)).replace('%target%', pretty_number(Number(input.value) || 0)) + ' | ' + text;
    }
    line.textContent = text;
    line.hidden = false;
}

window.onPageInit(function () {
    qsa('form[data-hangar-order]').forEach((form) => {
        const input = form.querySelector('input[type="number"]');
        const upto = form.querySelector('[data-hangar-upto]');
        form.querySelector('[data-hangar-max]').addEventListener('click', () => {
            const max = Number(form.dataset.max) || 0;
            input.value = upto.checked ? String(max + Number(form.dataset.owned || 0) + Number(form.dataset.queued || 0)) : String(max);
            update(form);
        });
        input.addEventListener('input', () => update(form));
        upto.addEventListener('change', () => update(form));
    });
});
