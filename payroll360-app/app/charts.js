// Dependency-free SVG charts. They only draw the numbers they are given; nothing is decorative or invented.
import { html, raw, esc, group } from './ui.js';

export const COLORS = ['#14A841', '#0EA5E9', '#F59E0B', '#7C3AED', '#EF4444', '#0F766E'];
const W = 640;
const M = { l: 58, r: 10, t: 12, b: 30 };

function niceMax(v) {
  if (v <= 0) return 1;
  const p = 10 ** Math.floor(Math.log10(v));
  const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
}
export function compact(n) {
  const a = Math.abs(n);
  if (a >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
  if (a >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
  if (a >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
  return String(Math.round(n * 100) / 100);
}
const monthLabel = (p) => { const m = /^(\d{4})-(\d{2})/.exec(p); return m ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+m[2] - 1] + ' ' + m[1].slice(2) : p; };

function frame(height, max, body, labels, xStep) {
  const plotW = W - M.l - M.r;
  const plotH = height - M.t - M.b;
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => {
    const y = M.t + plotH - f * plotH;
    return `<line x1="${M.l}" x2="${W - M.r}" y1="${y}" y2="${y}" class="ch-grid"/><text x="${M.l - 6}" y="${y + 3}" text-anchor="end" class="ch-tick">${esc(compact(max * f))}</text>`;
  }).join('');
  const xl = labels.map((l, i) => (i % xStep === 0 ? `<text x="${M.l + (i + 0.5) * (plotW / labels.length)}" y="${height - 10}" text-anchor="middle" class="ch-tick">${esc(l)}</text>` : '')).join('');
  return `<svg class="chart" viewBox="0 0 ${W} ${height}" role="img" preserveAspectRatio="xMidYMid meet">${ticks}${body}${xl}</svg>`;
}

/** Grouped bar chart. series: [{ name, values: number[], color? }]. */
export function barChart({ labels, series, height = 240, unit = '' }) {
  const plotW = W - M.l - M.r;
  const plotH = height - M.t - M.b;
  const max = niceMax(Math.max(...series.flatMap((s) => s.values), 0));
  const gw = plotW / labels.length;
  const bw = Math.min(34, (gw * 0.72) / series.length);
  const bars = series.map((s, si) => s.values.map((v, i) => {
    const h = (v / max) * plotH;
    const x = M.l + i * gw + (gw - bw * series.length) / 2 + si * bw;
    return `<rect x="${x.toFixed(1)}" y="${(M.t + plotH - h).toFixed(1)}" width="${(bw - 1.5).toFixed(1)}" height="${Math.max(h, 0).toFixed(1)}" rx="2" fill="${s.color || COLORS[si % COLORS.length]}"><title>${esc(s.name)} - ${esc(labels[i])}: ${esc(unit)} ${esc(group(v.toFixed(2)))}</title></rect>`;
  }).join('')).join('');
  return raw(frame(height, max, bars, labels, Math.ceil(labels.length / 10)));
}

/** Line chart. series: [{ name, values: number[], color? }]. */
export function lineChart({ labels, series, height = 240, unit = '' }) {
  const plotW = W - M.l - M.r;
  const plotH = height - M.t - M.b;
  const max = niceMax(Math.max(...series.flatMap((s) => s.values), 0));
  const gw = plotW / labels.length;
  const lines = series.map((s, si) => {
    const c = s.color || COLORS[si % COLORS.length];
    const pts = s.values.map((v, i) => [M.l + (i + 0.5) * gw, M.t + plotH - (v / max) * plotH]);
    const path = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
    const dots = pts.map((p, i) => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.5" fill="${c}"><title>${esc(s.name)} - ${esc(labels[i])}: ${esc(unit)} ${esc(group(s.values[i].toFixed(2)))}</title></circle>`).join('');
    return `<path d="${path}" fill="none" stroke="${c}" stroke-width="2.2"/>${dots}`;
  }).join('');
  return raw(frame(height, max, lines, labels, Math.ceil(labels.length / 10)));
}

/** Horizontal bars for ranked categories. rows: [{ label, value }]. */
export function hBarChart({ rows, unit = '' }) {
  const max = niceMax(Math.max(...rows.map((r) => r.value), 0));
  const rowH = 30;
  const h = rows.length * rowH + 8;
  const labelW = 150;
  const barMax = W - labelW - 80;
  const bars = rows.map((r, i) => {
    const w = (r.value / max) * barMax;
    const y = 4 + i * rowH;
    return `<text x="${labelW - 8}" y="${y + 17}" text-anchor="end" class="ch-tick ch-label">${esc(r.label.length > 22 ? r.label.slice(0, 21) + '...' : r.label)}</text>
      <rect x="${labelW}" y="${y + 4}" width="${Math.max(w, 1).toFixed(1)}" height="18" rx="3" fill="${COLORS[0]}"><title>${esc(r.label)}: ${esc(unit)} ${esc(group(r.value.toFixed(2)))}</title></rect>
      <text x="${labelW + w + 6}" y="${y + 17}" class="ch-tick">${esc(compact(r.value))}</text>`;
  }).join('');
  return raw(`<svg class="chart" viewBox="0 0 ${W} ${h}" role="img">${bars}</svg>`);
}

export function legend(series) {
  return html`<div class="ch-legend">${series.map((s, i) => html`<span><i style="background:${s.color || COLORS[i % COLORS.length]}"></i>${s.name}</span>`)}</div>`;
}

export { monthLabel };
