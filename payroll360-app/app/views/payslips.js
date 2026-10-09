// Payslips: history, on-screen payslip (rendered from the frozen document), PDF download, print and send.
import { html, raw, on, debounce, pageHeader, table, badge, emptyState, errorState, skeleton, money, moneyText, confirmDialog, withBusy, toast, saveBlob, fmtDate, fmtPeriod, fmtDateTime, icon, titleCase } from '../ui.js';

export async function downloadPayslipPdf(ctx, id, btn) {
  await withBusy(btn, 'Preparing PDF...', async () => {
    try {
      const { blob, filename } = await ctx.api.file(`/payslips/${id}/pdf`);
      saveBlob(blob, filename);
    } catch (err) { toast(err.message, 'error'); }
  });
}

export function sendPayslipDialog(ctx, p, onDone) {
  const mine = !ctx.can('payslips.send');
  const to = p.doc?.employee?.work_email || 'the employee\'s work email on file';
  confirmDialog({
    title: mine ? 'Email yourself this payslip?' : 'Send this payslip?', size: 'md',
    message: mine ? `A copy of payslip ${p.number} will be sent to ${to}.` : `Payslip ${p.number} for ${p.employee_name || p.doc?.employee?.name} will be emailed as a PDF to ${to}.`,
    confirmText: mine ? 'Email me a copy' : 'Send payslip',
  }).then(async (r) => {
    if (!r.ok) return;
    try {
      const res = await ctx.api.post(mine ? `/payslips/${p.id}/email-me` : `/payslips/${p.id}/send`);
      toast(res.message);
      onDone?.();
    } catch (err) { toast(err.message, 'error'); }
  });
}

/** The payslip as a document. Everything comes from the frozen payslip; nothing is calculated here. */
export function payslipSheet(doc) {
  const c = doc.currency;
  const M = (v) => money(v, c);
  const t = doc.totals;
  const group = (label, rows, cls = '') => (rows.length ? html`<div class="ps-table-row" style="font-weight:800;background:var(--surface-subtle);${cls}"><span>${label}</span><span></span></div>${rows.map((l) => html`<div class="ps-table-row"><span>${l.name}${l.note ? html`<small>${l.note}</small>` : ''}</span><span>${M(l.amount)}</span></div>`)}` : '');
  const e = doc.employee;
  const meta = [['Employee name', e.name], ['Employee ID', e.employee_no], ['Job title', e.job_title], ['Department', e.department], ['Country / region', [e.country, e.state_region].filter(Boolean).join(' / ')],
    ['Tax ID', e.tax_id], ['Pay date', fmtDate(doc.period.pay_date)], ['Paid to', [e.bank_name, e.bank_account].filter(Boolean).join(' ')]];
  return html`<div class="payslip-sheet" id="payslip-sheet">
    <div class="ps-header"><div class="ps-brand-wrap">${doc.employer.logo ? html`<div class="ps-logo-wrap"><img class="ps-company-logo" src="${doc.employer.logo}" alt=""></div>` : ''}
      <div><h2>${doc.employer.name}</h2><p>${doc.employer.address || ''}</p></div></div>
      <div class="ps-title-block"><h1>${String(doc.employer.title).toUpperCase()}</h1><div class="ps-period-tag">Period: ${fmtPeriod(doc.period.start, doc.period.end)}</div><div class="small" style="margin-top:4px;color:var(--ink-muted)">No. ${doc.number}</div></div></div>
    <div class="ps-meta-grid">${meta.map(([k, v]) => html`<div class="ps-meta-item"><small>${k}</small><b>${v || '-'}</b></div>`)}</div>
    <div class="ps-breakdown-grid">
      <div class="ps-table-card"><div class="ps-table-head"><span>Earnings</span><span>Amount</span></div><div class="ps-table-body">${doc.earnings.map((l) => html`<div class="ps-table-row"><span>${l.name}</span><span>${M(l.amount)}</span></div>`)}</div>
        <div class="ps-table-total"><span>Gross pay</span><span>${M(t.gross)}</span></div></div>
      <div class="ps-table-card"><div class="ps-table-head"><span style="color:var(--danger)">Taxes, benefits and deductions</span><span>Amount</span></div>
        <div class="ps-table-body">${group('Taxes', doc.taxes)}${group('Benefits (employee)', doc.benefits.filter((l) => l.amount !== '0.00'))}${group('Other deductions', doc.deductions)}</div>
        <div class="ps-table-total"><span>Total deductions</span><span>${M(t.total_deductions)}</span></div></div></div>
    <div class="ps-net-pay-banner"><div><div class="ps-net-label">Net pay</div><div class="ps-net-words">${t.employer_contrib && t.employer_contrib !== '0.00' ? `Employer contributions: ${moneyText(t.employer_contrib, c)}` : ''}</div></div><div class="ps-net-amount">${M(t.net)}</div></div>
    <div class="ps-footer-grid"><div><strong>Year to date (${String(doc.period.pay_date).slice(0, 4)})</strong>
        ${[['Gross', doc.ytd.gross], ['Taxes', doc.ytd.taxes], ['Deductions', doc.ytd.deductions], ['Net', doc.ytd.net]].map(([k, v]) => html`<div style="display:flex;justify-content:space-between;margin-top:3px"><span>${k}:</span><b>${M(v)}</b></div>`)}</div>
      <div><strong>Notes</strong><p style="margin-top:4px;line-height:1.4">Computer-generated statement. No signature required. Please keep this document for your records.</p>${(doc.notes || []).map((n) => html`<p class="ps-note">${n}</p>`)}</div></div>
    <div class="ps-security-note">Confidential - issued by ${doc.employer.name} - ${doc.run.name}</div></div>`;
}

// ------------------------------------------------------------------------------------------------ history
export async function list(ctx, route) {
  const st = { q: '', department: '', status: '', from: '', to: '', run_id: '', offset: 0, limit: 25 };
  let runs = [];
  if (ctx.can('payroll.read')) { try { runs = (await ctx.api.get('/payrolls')).items.filter((r) => ['PROCESSED', 'PAID'].includes(r.status)); } catch { /* optional filter */ } }
  route.paint(html`${pageHeader({ title: 'Payslips', sub: 'Every payslip that has been generated. Payslips are frozen when payroll is processed and never change afterwards.' })}
    <div class="card"><div class="toolbar collapsed" id="ps-toolbar">
      <div class="search"><span>${icon('search')}</span><input class="form-control" id="ps-q" type="search" placeholder="Search employee, ID or payslip number" aria-label="Search payslips"></div>
      <button class="btn btn-outline btn-sm filters-toggle" type="button" id="ps-ft">Filters</button>
      ${runs.length ? html`<select class="form-select filter-item" data-f="run_id" aria-label="Payroll period"><option value="">All payroll periods</option>${runs.map((r) => html`<option value="${r.id}">${r.name}</option>`)}</select>` : ''}
      <select class="form-select filter-item" data-f="department" aria-label="Department"><option value="">All departments</option>${ctx.meta.departments.map((d) => html`<option value="${d.name}">${d.name}</option>`)}</select>
      <select class="form-select filter-item" data-f="status" aria-label="Status"><option value="">All statuses</option><option value="generated">Generated</option><option value="sent">Sent</option></select>
      <label class="filter-item small muted">From <input class="form-control" type="date" data-f="from" aria-label="Pay date from"></label>
      <label class="filter-item small muted">To <input class="form-control" type="date" data-f="to" aria-label="Pay date to"></label></div>
      <div id="ps-results">${skeleton(6)}</div></div>`);
  const results = route.view.querySelector('#ps-results');
  const load = async () => {
    try {
      const r = await ctx.api.get('/payslips', { ...st });
      if (route.stale()) return;
      const from = r.total ? st.offset + 1 : 0;
      const to = Math.min(st.offset + st.limit, r.total);
      results.innerHTML = (r.items.length ? html`${table({ columns: [
        { label: 'Employee', render: (p) => html`<div class="cell-main">${p.employee_name}</div><div class="cell-sub">${p.employee_no} &middot; ${p.number}</div>` },
        { label: 'Payroll period', render: (p) => fmtPeriod(p.period_start, p.period_end) }, { label: 'Pay date', render: (p) => fmtDate(p.pay_date) },
        { label: 'Gross pay', align: 'right', render: (p) => money(p.gross, p.currency) }, { label: 'Deductions', align: 'right', render: (p) => money(p.deductions, p.currency) },
        { label: 'Net pay', align: 'right', render: (p) => html`<b>${money(p.net, p.currency)}</b>` }, { label: 'Status', render: (p) => badge(p.status, p.status === 'sent' ? 'Sent' : 'Generated') },
        { label: '', render: (p) => html`<div class="actions"><a class="btn btn-outline btn-sm" href="#/payslips/${p.id}">View</a><button class="btn btn-outline btn-sm" data-dl="${p.id}">Download</button><a class="btn btn-outline btn-sm" href="#/payslips/${p.id}?print=1">Print</a>${ctx.can('payslips.send') ? html`<button class="btn btn-outline btn-sm" data-send="${p.id}">Send</button>` : ''}</div>` },
      ], rows: r.items })}<div class="pager"><span>Showing ${from}-${to} of ${r.total}</span><span><button class="btn btn-outline btn-sm" data-page="prev" ${st.offset === 0 ? raw('disabled') : ''}>Previous</button> <button class="btn btn-outline btn-sm" data-page="next" ${to >= r.total ? raw('disabled') : ''}>Next</button></span></div>`
        : emptyState({ title: 'No payslips are available.', text: 'Payslips appear here after a payroll has been processed.', iconName: 'file' })).toString();
    } catch (err) { if (route.stale()) return; results.innerHTML = errorState(err).toString(); results.querySelector('[data-action="retry"]')?.addEventListener('click', load); }
  };
  route.view.querySelector('#ps-q').addEventListener('input', debounce((e) => { st.q = e.target.value.trim(); st.offset = 0; load(); }, 300));
  route.view.querySelectorAll('[data-f]').forEach((el) => el.addEventListener('change', () => { st[el.dataset.f] = el.value; st.offset = 0; load(); }));
  route.view.querySelector('#ps-ft').addEventListener('click', () => route.view.querySelector('#ps-toolbar').classList.toggle('collapsed'));
  on(results, 'click', '[data-page]', (e, b) => { st.offset = Math.max(0, st.offset + (b.dataset.page === 'next' ? st.limit : -st.limit)); load(); });
  on(results, 'click', '[data-dl]', (e, b) => downloadPayslipPdf(ctx, +b.dataset.dl, b));
  on(results, 'click', '[data-send]', async (e, b) => sendPayslipDialog(ctx, await ctx.api.get(`/payslips/${b.dataset.send}`), load));
  await load();
}

// ------------------------------------------------------------------------------------------------ viewer
export async function viewer(ctx, route) {
  const id = route.params.id;
  const p = await ctx.api.get(`/payslips/${id}`);
  if (route.stale()) return;
  const back = ctx.can('payslips.read') && !route.query.me ? '#/payslips' : '#/my-payrolls';
  const mine = !ctx.can('payslips.send');
  route.paint(html`${pageHeader({ crumbs: [{ label: ctx.can('payslips.read') && !route.query.me ? 'Payslips' : 'My payrolls', href: back }, { label: p.number }], title: `Payslip - ${p.doc.employee.name}`, sub: `${fmtPeriod(p.period_start, p.period_end)} - paid ${fmtDate(p.pay_date)}`,
      actions: html`<span class="no-print">${badge(p.status, p.status === 'sent' ? 'Sent' : 'Generated')}</span><button class="btn btn-outline no-print" id="btn-print">${icon('print')} Print</button><button class="btn btn-outline no-print" id="btn-send">${icon('mail')} ${mine ? 'Email me a copy' : 'Send'}</button><button class="btn btn-primary no-print" id="btn-pdf">${icon('download')} Download PDF</button>` })}
    ${p.sent_at ? html`<div class="banner banner-info no-print">${icon('mail', 18)}<div class="grow">Last sent to ${p.sent_to} on ${fmtDateTime(p.sent_at)} (${p.sent_count} time${p.sent_count === 1 ? '' : 's'}).</div></div>` : ''}
    <div class="sheet-wrap">${payslipSheet(p.doc)}</div>`);
  route.view.querySelector('#btn-print').addEventListener('click', () => window.print());
  route.view.querySelector('#btn-pdf').addEventListener('click', (e) => downloadPayslipPdf(ctx, id, e.currentTarget));
  route.view.querySelector('#btn-send').addEventListener('click', () => sendPayslipDialog(ctx, p, () => ctx.navigate(location.hash.split('?')[0])));
  if (route.query.print === '1') setTimeout(() => window.print(), 400);
}
