// Payroll360 UI toolkit: safe templating, formatting, dialogs, forms, tables and standard states.

// ---------------------------------------------------------------------------------------------- templating
export class Raw {
  constructor(s) { this.s = s; }
  toString() { return this.s; }
}
export const raw = (s) => new Raw(String(s));
const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
const part = (v) => (v instanceof Raw ? v.s : Array.isArray(v) ? v.map(part).join('') : v === null || v === undefined || v === false ? '' : esc(v));
/** Tagged template: every interpolated value is HTML-escaped unless it is already Raw (from html``). */
export function html(strings, ...vals) {
  let out = strings[0];
  for (let i = 0; i < vals.length; i++) out += part(vals[i]) + strings[i + 1];
  return new Raw(out);
}
export const str = part;
export function mount(el, content) { el.innerHTML = part(content); return el; }
export function on(root, type, selector, handler) {
  root.addEventListener(type, (e) => {
    const t = e.target.closest(selector);
    if (t && root.contains(t)) handler(e, t);
  });
}
export function debounce(fn, ms = 300) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ---------------------------------------------------------------------------------------------- icons
const ICONS = {
  plus: '<path d="M12 5v14M5 12h14"/>', search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  print: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>', eye: '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
  check: '<path d="m20 6-11 11-5-5"/>', alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>', trash: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', refresh: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
  users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', dollar: '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
  inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>', chevron: '<path d="m9 18 6-6-6-6"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>', shield: '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>',
  play: '<path d="M5 3l14 9-14 9z"/>', chart: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>', arrowleft: '<path d="M19 12H5M12 19l-7-7 7-7"/>',
};
export function icon(name, size = 16) {
  return raw(`<svg class="ico" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`);
}

// ---------------------------------------------------------------------------------------------- formatting
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function fmtDate(iso) {
  if (!iso) return '-';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  return m ? `${MONTHS[+m[2] - 1]} ${+m[3]}, ${m[1]}` : String(iso);
}
export function fmtDateTime(iso) {
  if (!iso) return '-';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
export function fmtPeriod(a, b) { return `${fmtDate(a)} - ${fmtDate(b)}`; }
export const titleCase = (s) => (/^[A-Z]{2,3}$/.test(String(s ?? '')) ? String(s) : String(s ?? '').replace(/_/g, ' ').toLowerCase().replace(/(^|\s)\S/g, (c) => c.toUpperCase()));

/** Group digits of a decimal string ("1234567.5" -> "1,234,567.5") without going through floating point. */
export function group(dec) {
  const s = String(dec);
  const neg = s.startsWith('-');
  const [i, f] = s.replace('-', '').split('.');
  return (neg ? '-' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (f !== undefined ? '.' + f : '');
}
/** Every monetary value is shown with its currency code. */
export function money(dec, cur) {
  if (dec === null || dec === undefined || dec === '') return raw('<span class="muted">-</span>');
  return html`<span class="money"><span class="cur">${cur}</span>${group(dec)}</span>`;
}
export const moneyText = (dec, cur) => `${cur} ${group(dec)}`;
/** Exact decimal addition using BigInt (no floats). */
export function decAdd(...vals) {
  let dp = 0;
  for (const v of vals) dp = Math.max(dp, (String(v).split('.')[1] || '').length);
  let sum = 0n;
  for (const v of vals) {
    const neg = String(v).startsWith('-');
    const [i, f = ''] = String(v).replace('-', '').split('.');
    const n = BigInt(i + f.padEnd(dp, '0'));
    sum += neg ? -n : n;
  }
  const neg = sum < 0n;
  let s = (neg ? -sum : sum).toString().padStart(dp + 1, '0');
  if (dp > 0) s = s.slice(0, -dp) + '.' + s.slice(-dp);
  return (neg ? '-' : '') + s;
}
export const num = (dec) => Number.parseFloat(dec) || 0; // display / sorting / charts only, never for payroll maths

const BADGE = {
  DRAFT: 'gray', PREPARING: 'blue', CALCULATING: 'blue', REVIEW: 'amber', APPROVAL: 'purple', APPROVED: 'green', PROCESSING: 'blue', PROCESSED: 'green', PAID: 'teal', CANCELLED: 'red',
  ACTIVE: 'green', ON_LEAVE: 'amber', SUSPENDED: 'amber', TERMINATED: 'red', INACTIVE: 'gray',
  generated: 'blue', sent: 'green', ok: 'green', exception: 'red', excluded: 'gray', pending: 'gray', active: 'green', inactive: 'gray',
  error: 'red', warning: 'amber',
};
export function badge(status, label) {
  return html`<span class="badge badge-${BADGE[status] || 'gray'}">${label ?? titleCase(status)}</span>`;
}
export const pill = (text, kind = 'gray') => html`<span class="badge badge-${kind}">${text}</span>`;

// ---------------------------------------------------------------------------------------------- toasts
export function toast(message, kind = 'success', ms = 5000) {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const t = document.createElement('div');
  t.className = `toast toast-${kind}`;
  t.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  t.textContent = message;
  c.appendChild(t);
  setTimeout(() => { t.classList.add('leaving'); setTimeout(() => t.remove(), 300); }, ms);
}

// ---------------------------------------------------------------------------------------------- dialogs
const layers = [];
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && layers.length) layers[layers.length - 1].requestClose();
});
function openLayer(kind, { title, subtitle, body = '', footer = '', size = 'md', dismissible = true, onClose }) {
  const root = document.getElementById('overlay-root');
  const overlay = document.createElement('div');
  overlay.className = `p-overlay p-overlay-${kind}`;
  overlay.innerHTML = `<div class="p-layer p-${kind} p-${size}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    <div class="p-layer-head"><div><h3></h3><p class="p-layer-sub"></p></div><button type="button" class="p-close" aria-label="Close">&times;</button></div>
    <div class="p-layer-body"></div><div class="p-layer-foot"></div></div>`;
  const box = overlay.firstElementChild;
  const bodyEl = box.querySelector('.p-layer-body');
  const footEl = box.querySelector('.p-layer-foot');
  const titleEl = box.querySelector('h3');
  const subEl = box.querySelector('.p-layer-sub');
  titleEl.textContent = title;
  subEl.textContent = subtitle || '';
  subEl.hidden = !subtitle;
  mount(bodyEl, body);
  mount(footEl, footer);
  footEl.hidden = !footer;
  const prev = document.activeElement;
  let closed = false;
  const ctl = {
    el: box, body: bodyEl, foot: footEl,
    setBody(c) { mount(bodyEl, c); },
    setFooter(c) { mount(footEl, c); footEl.hidden = !c; },
    setTitle(t, s) { titleEl.textContent = t; if (s !== undefined) { subEl.textContent = s; subEl.hidden = !s; } },
    requestClose() { if (dismissible) ctl.close(); },
    close(result) {
      if (closed) return;
      closed = true;
      layers.splice(layers.indexOf(ctl), 1);
      overlay.remove();
      if (prev && prev.focus) prev.focus();
      onClose?.(result);
    },
  };
  box.querySelector('.p-close').addEventListener('click', () => ctl.requestClose());
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) ctl.requestClose(); });
  root.appendChild(overlay);
  layers.push(ctl);
  const first = box.querySelector('input:not([type=hidden]), select, textarea, button.btn-primary');
  (first || box.querySelector('.p-close')).focus();
  return ctl;
}
export const openModal = (o) => openLayer('modal', o);
export const openDrawer = (o) => openLayer('drawer', { size: 'lg', ...o });

/** Resolves { ok, reason }. Use reasonLabel to require a typed reason. */
export function confirmDialog({ title, message = '', details = '', confirmText = 'Confirm', cancelText = 'Cancel', danger = false, reasonLabel = '', reasonRequired = true, size = 'sm' }) {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (v) => { if (!settled) { settled = true; resolve(v); } };
    const m = openModal({
      title, size,
      body: html`<div class="p-confirm">${message ? html`<p>${message}</p>` : ''}${details}
        ${reasonLabel ? html`<div class="form-group"><label class="form-label" for="cf-reason">${reasonLabel}</label><textarea id="cf-reason" class="form-control" rows="3"></textarea><div class="field-error" id="cf-err" role="alert"></div></div>` : ''}</div>`,
      footer: html`<button type="button" class="btn btn-outline" data-x="cancel">${cancelText}</button><button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-x="ok">${confirmText}</button>`,
      onClose: () => finish({ ok: false }),
    });
    m.foot.addEventListener('click', (e) => {
      const b = e.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return m.close();
      const ta = m.body.querySelector('#cf-reason');
      const reason = ta ? ta.value.trim() : '';
      if (ta && reasonRequired && reason.length < 3) {
        const slot = m.body.querySelector('#cf-err'); if (slot) slot.textContent = 'Please enter a reason.';
        ta.focus();
        return;
      }
      finish({ ok: true, reason });
      m.close();
    });
  });
}

// ---------------------------------------------------------------------------------------------- forms
export function field({ name, label, type = 'text', value = '', options, blank, help = '', required = false, placeholder = '', attrs = {}, wide = false, rows = 3 }) {
  const a = Object.entries(attrs).map(([k, v]) => (v === true ? k : `${k}="${esc(v)}"`)).join(' ');
  const id = `f-${name}`;
  const req = required ? ' aria-required="true"' : '';
  let control;
  if (type === 'select') {
    const opts = (options || []).map((o) => (typeof o === 'object' ? o : { value: o, label: titleCase(o) }));
    control = html`<select class="form-select" id="${id}" name="${name}"${raw(req)} ${raw(a)}>${blank !== undefined ? html`<option value="">${blank}</option>` : ''}${opts.map((o) => html`<option value="${o.value}" ${String(o.value) === String(value) ? raw('selected') : ''}>${o.label}</option>`)}</select>`;
  } else if (type === 'textarea') {
    control = html`<textarea class="form-control" id="${id}" name="${name}" rows="${rows}" placeholder="${placeholder}"${raw(req)} ${raw(a)}>${value ?? ''}</textarea>`;
  } else if (type === 'checkbox') {
    return html`<div class="form-group p-field ${wide ? 'span-2' : ''}" data-field="${name}"><label class="p-check"><input type="checkbox" id="${id}" name="${name}" ${value ? raw('checked') : ''} ${raw(a)}> <span>${label}</span></label>${help ? html`<div class="field-help">${help}</div>` : ''}<div class="field-error" data-error-for="${name}" role="alert"></div></div>`;
  } else if (type === 'money' || type === 'percent') {
    control = html`<input class="form-control" id="${id}" name="${name}" type="text" inputmode="decimal" autocomplete="off" value="${value ?? ''}" placeholder="${placeholder || (type === 'percent' ? '0.00' : '0.00')}"${raw(req)} ${raw(a)}>`;
  } else {
    control = html`<input class="form-control" id="${id}" name="${name}" type="${type}" value="${value ?? ''}" placeholder="${placeholder}"${raw(req)} ${raw(a)}>`;
  }
  return html`<div class="form-group p-field ${wide ? 'span-2' : ''}" data-field="${name}">
    <label class="form-label" for="${id}">${label}${required ? raw('<span class="req" aria-hidden="true"> *</span>') : ''}</label>${control}
    ${help ? html`<div class="field-help">${help}</div>` : ''}<div class="field-error" data-error-for="${name}" role="alert"></div></div>`;
}
export function readForm(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled || el.type === 'submit' || el.type === 'button') continue;
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = (el.value ?? '').trim();
  }
  return out;
}
export function clearFieldErrors(root) {
  root.querySelectorAll('[data-error-for]').forEach((e) => { e.textContent = ''; });
  root.querySelectorAll('.has-error').forEach((e) => e.classList.remove('has-error'));
  root.querySelector('.form-alert')?.remove();
}
/** Shows server field errors next to inputs. Returns the messages that had no matching field. */
export function showFieldErrors(root, fields = {}) {
  const orphan = [];
  let first = null;
  for (const [k, msg] of Object.entries(fields)) {
    const slot = root.querySelector(`[data-error-for="${k}"]`);
    if (slot) {
      slot.textContent = msg;
      slot.closest('.p-field')?.classList.add('has-error');
      first ??= slot.closest('.p-field');
    } else orphan.push(`${titleCase(k)}: ${msg}`);
  }
  first?.querySelector('input, select, textarea')?.focus();
  return orphan;
}
export function formAlert(root, message) {
  root.querySelector('.form-alert')?.remove();
  const d = document.createElement('div');
  d.className = 'form-alert';
  d.setAttribute('role', 'alert');
  d.textContent = message;
  root.prepend(d);
  d.scrollIntoView({ block: 'nearest' });
}
/** Disable a button while an async action runs; ignores repeat clicks (prevents double submission). */
export async function withBusy(btn, label, fn) {
  if (!btn || btn.dataset.busy === '1') return undefined;
  const original = btn.innerHTML;
  btn.dataset.busy = '1';
  btn.disabled = true;
  btn.classList.add('is-busy');
  btn.innerHTML = `<span class="spinner" aria-hidden="true"></span> ${esc(label)}`;
  try {
    return await fn();
  } finally {
    btn.dataset.busy = '';
    btn.disabled = false;
    btn.classList.remove('is-busy');
    btn.innerHTML = original;
  }
}

// ---------------------------------------------------------------------------------------------- tables & states
/**
 * columns: [{ key, label, render(row), align, sort, cls }]. Cells carry data-label so the table turns into cards on phones.
 * sortState: { key, dir } marks sortable headers (buttons with data-sort).
 */
export function table({ columns, rows, rowAttrs, className = '', sortState = null }) {
  const head = columns.map((c) => {
    const cls = [c.align === 'right' ? 'num' : '', c.cls || ''].join(' ').trim();
    if (c.sort) {
      const active = sortState && sortState.key === c.sort;
      return html`<th class="${cls}" aria-sort="${active ? (sortState.dir === 'desc' ? 'descending' : 'ascending') : 'none'}"><button type="button" class="th-sort" data-sort="${c.sort}">${c.label}${active ? raw(sortState.dir === 'desc' ? ' &#9660;' : ' &#9650;') : ''}</button></th>`;
    }
    return html`<th class="${cls}">${c.label}</th>`;
  });
  const body = rows.map((r) => {
    const attrs = rowAttrs ? rowAttrs(r) : '';
    return html`<tr ${raw(attrs)}>${columns.map((c) => {
      const cls = [c.align === 'right' ? 'num' : '', c.cls || ''].join(' ').trim();
      return html`<td class="${cls}" data-label="${c.label}">${c.render ? c.render(r) : (r[c.key] ?? '-')}</td>`;
    })}</tr>`;
  });
  return html`<div class="sp-table-wrap p-table-wrap"><table class="sp-table p-table ${className}"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}
export function emptyState({ title, text = '', actionLabel = '', actionAttr = '', iconName = 'inbox' }) {
  return html`<div class="empty-state">${icon(iconName, 34)}<h3>${title}</h3>${text ? html`<p>${text}</p>` : ''}${actionLabel ? html`<button type="button" class="btn btn-primary" ${raw(actionAttr)}>${actionLabel}</button>` : ''}</div>`;
}
export function skeleton(rows = 4) {
  return html`<div class="skeleton" aria-busy="true" aria-label="Loading">${Array.from({ length: rows }, (_, i) => html`<div class="sk-row" style="width:${100 - (i % 3) * 12}%"></div>`)}</div>`;
}
export const loadingBlock = (label = 'Loading...') => html`<div class="loading-block" role="status"><span class="spinner"></span> ${label}</div>`;
export function errorState(err, retry = true) {
  const msg = err?.message || 'Something went wrong.';
  return html`<div class="error-state" role="alert">${icon('alert', 30)}<h3>We could not load this</h3><p>${msg}</p>${retry ? html`<button type="button" class="btn btn-outline" data-action="retry">${icon('refresh')} Try again</button>` : ''}</div>`;
}
export function pageHeader({ title, sub = '', crumbs = [], actions = '' }) {
  return html`<div class="page-head">
    <div>${crumbs.length ? html`<nav class="crumbs" aria-label="Breadcrumb">${crumbs.map((c, i) => (c.href ? html`<a href="${c.href}">${c.label}</a>` : html`<span>${c.label}</span>`)).flatMap((x, i, a) => (i < a.length - 1 ? [x, raw('<span class="sep">/</span>')] : [x]))}</nav>` : ''}
      <h2 class="page-title">${title}</h2>${sub ? html`<p class="page-sub">${sub}</p>` : ''}</div>
    <div class="page-actions">${actions}</div></div>`;
}
export function tabs(items, active) {
  return html`<div class="p-tabs" role="tablist">${items.map((t) => html`<button type="button" role="tab" class="p-tab ${t.id === active ? 'active' : ''}" aria-selected="${t.id === active}" data-tab="${t.id}">${t.label}${t.count !== undefined ? html` <span class="p-tab-count">${t.count}</span>` : ''}</button>`)}</div>`;
}
export function stageStepper(stages) {
  return html`<ol class="stepper" aria-label="Payroll stages">${stages.map((s) => html`<li class="step step-${s.state}" ${s.state === 'current' ? raw('aria-current="step"') : ''}><span class="step-dot">${s.state === 'done' ? icon('check', 12) : s.n}</span><span class="step-name">${s.name}</span></li>`)}</ol>`;
}
export function kpi(label, value, sub = '', tone = '') {
  return html`<div class="kpi ${tone}"><div class="kpi-label">${label}</div><div class="kpi-value">${value}</div>${sub ? html`<div class="kpi-sub">${sub}</div>` : ''}</div>`;
}

// ---------------------------------------------------------------------------------------------- files
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
export function readFileText(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result));
    r.onerror = () => reject(new Error('Could not read that file.'));
    r.readAsText(file);
  });
}
export function readFileDataUri(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result));
    r.onerror = () => reject(new Error('Could not read that file.'));
    r.readAsDataURL(file);
  });
}
