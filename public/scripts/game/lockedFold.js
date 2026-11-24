import { qs, qsa } from '../app/core.js';

/**
 * Phones (up to 40rem): the hangar / defense cards whose requirements are missing are grouped into a collapsed
 * "Locked (n)" details block after the list; on wider screens they go back to their place.
 */
const grid = qs('.build-grid[data-locked-label]');
const locked = grid ? qsa('.card.is-reqlocked', grid) : [];

if (grid && locked.length > 0) {
  const all = qsa(':scope > .card', grid);
  const phone = window.matchMedia('(max-width: 40rem)');
  const details = document.createElement('details');
  details.className = 'locked-fold';
  const summary = document.createElement('summary');
  summary.textContent = grid.dataset.lockedLabel.replace('{n}', String(locked.length));
  const inner = document.createElement('div');
  inner.className = 'build-grid';
  details.append(summary, inner);

  const apply = () => {
    if (phone.matches) {
      inner.append(...locked);
      grid.after(details);
    } else {
      grid.append(...all);
      details.remove();
    }
  };
  apply();
  phone.addEventListener('change', apply);
}
