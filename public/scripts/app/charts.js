// Shared Chart.js setup for the game's charts (empire, alliance). Chart.js itself is the reduced build in
// ../lib/chart.js (rebuild: tools/chartjs/build.sh). Charts take their text and grid colours from the theme.
import { Chart, Interaction, getRelativePosition } from '../lib/chart.js';
import { shortly_number } from './core.js';

export { Chart, Interaction, getRelativePosition };

/** chart data the template printed as <script type="application/json" id="…"> */
export function readChartData(id) {
  const el = document.getElementById(id);
  return el ? JSON.parse(el.textContent) : null;
}

function colorWithAlpha(color, alpha) {
  const match = String(color).match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i);
  return match ? `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${alpha})` : color;
}

/**
 * Calm chart look shared by all game charts: text and grid colours follow the theme's table text colour, faint
 * horizontal grid only, no axis borders, round legend markers, lines without dots (a dot appears on hover) and a
 * dark, padded tooltip with tabular digits.
 */
export function applyPageTheme() {
  const el = document.querySelector('.content td') || document.body;
  const style = getComputedStyle(el);
  const text = style.color;
  const muted = colorWithAlpha(text, 0.7);
  const defaults = Chart.defaults;
  const scale = defaults.scale;

  defaults.animation = false; // no entrance animation: it costs main-thread time for little gain
  defaults.color = muted;
  defaults.font.family = style.fontFamily;
  defaults.font.size = 12;
  scale.ticks.color = muted;
  scale.ticks.padding = 6;
  scale.grid.color = colorWithAlpha(text, 0.1);
  scale.grid.tickColor = 'transparent';
  scale.grid.tickLength = 4;
  scale.border.display = false;

  defaults.elements.line.borderWidth = 1.5;
  defaults.elements.line.borderCapStyle = 'round';
  defaults.elements.line.borderJoinStyle = 'round';
  defaults.elements.point.radius = 0;
  defaults.elements.point.hoverRadius = 4;
  defaults.elements.point.hitRadius = 12;
  defaults.elements.point.hoverBorderWidth = 2;
  defaults.elements.bar.borderRadius = 2;

  const legend = defaults.plugins.legend;
  legend.labels.color = text;
  legend.labels.usePointStyle = true;
  legend.labels.pointStyle = 'circle';
  legend.labels.boxWidth = 8;
  legend.labels.boxHeight = 8;
  legend.labels.padding = 16;

  const tip = defaults.plugins.tooltip;
  tip.backgroundColor = 'rgba(8, 11, 16, 0.94)';
  tip.borderColor = colorWithAlpha(text, 0.2);
  tip.borderWidth = 1;
  tip.titleColor = text;
  tip.bodyColor = text;
  tip.padding = 10;
  tip.cornerRadius = 6;
  tip.boxPadding = 4;
  tip.usePointStyle = true;
  tip.titleFont = { weight: '600' };
  tip.bodyFont = { family: style.fontFamily, size: 12 };
  tip.caretSize = 0;
}

/** #rrggbb + alpha as rgba() */
export function hexWithAlpha(hex, alpha) {
  const m = String(hex).match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);
  return m ? `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${alpha})` : hex;
}

/** points as the game prints them (12.3 K), with a real no-break space for the canvas */
export function formatPoints(value) {
  if (value == null || !isFinite(Number(value))) {
    return '';
  }
  return String(shortly_number(value)).replace(/&nbsp;/g, ' ');
}

/** x axis of "time ago" labels: horizontal, thinned out */
export function timeAgoTicks() {
  return { autoSkip: true, autoSkipPadding: 18, minRotation: 0, maxRotation: 0, maxTicksLimit: 9 };
}

/** tooltip helpers: largest value first, colour box = line colour, title = full date of the point */
export const tooltip = {
  sortDescending(a, b) {
    const av = a.parsed.y;
    const bv = b.parsed.y;
    if (av == null) return 1;
    if (bv == null) return -1;
    return bv - av;
  },
  lineColor(context) {
    const color = context.dataset.borderColor;
    return { borderColor: color, backgroundColor: color, borderWidth: 0, borderRadius: 4 };
  },
  titleFrom(times) {
    return (items) => (items.length ? times[items[0].dataIndex] : '');
  },
};
