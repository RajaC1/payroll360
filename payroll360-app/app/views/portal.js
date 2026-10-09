// Employee self-service. Every call here is scoped to the signed-in employee by the server.
import { html, on, pageHeader, tabs, table, badge, emptyState, errorState, skeleton, kpi, money, moneyText, decAdd, fmtDate, fmtPeriod, titleCase, icon } from '../ui.js';
import { downloadPayslipPdf, sendPayslipDialog } from './payslips.js';
import { changePasswordDialog } from './auth.js';

/** My profile: the signed-in person's own record, read-only. The server returns only this person's data (tax ID and bank are masked). */
export async function profile(ctx, route) {
  let me;
  try { me = await ctx.api.get('/me'); } catch (err) { route.paint(errorState(err, false)); return; }
  if (route.stale()) return;
  const e = me.employee;
  const c = me.compensation;
  const kv = (k, v) => html`<div><dt>${k}</dt><dd>${v === null || v === undefined || v === '' ? '-' : v}</dd></div>`;
  const section = (title, rows) => html`<div class="card card-pad" style="margin-bottom:16px"><h3 style="font-size:.98rem;margin-bottom:12px">${title}</h3><dl class="kv">${rows}</dl></div>`;
  const initials = String(e.full_name || '?').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
  route.paint(html`${pageHeader({ title: 'My profile', sub: 'Your details as they are held for payroll. To change anything, contact HR.', actions: html`<button class="btn btn-outline" id="btn-pw">Change password</button>` })}
    <div class="card card-pad" style="margin-bottom:16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <div class="user-chip-avatar" style="width:56px;height:56px;font-size:1.2rem;flex:none">${initials}</div>
      <div style="flex:1;min-width:200px"><h3 style="font-size:1.15rem">${e.full_name}</h3><p class="muted" style="margin-top:2px">${e.job_title || ''}${e.department ? ' - ' + e.department : ''}</p></div>
      <div>${badge(String(e.employment_status || '').toLowerCase(), titleCase(e.employment_status))}</div></div>
    ${section('Contact', html`${kv('Work email', e.work_email)}${kv('Personal email', e.personal_email)}${kv('Phone', e.phone)}`)}
    ${section('Employment', html`${kv('Employee ID', e.employee_no)}${kv('Company', e.entity_name)}${kv('Department', e.department)}${kv('Job title', e.job_title)}${kv('Manager', e.manager_name)}${kv('Employment type', titleCase(e.employment_type))}${kv('Joining date', e.joining_date ? fmtDate(e.joining_date) : '')}${kv('Probation ends', e.probation_end ? fmtDate(e.probation_end) : '')}${kv('Work location', e.work_location)}`)}
    ${section('Payroll', html`${kv('Country', e.country)}${kv('State / region', e.state_region)}${kv('Pay currency', e.currency)}${kv('Pay frequency', titleCase(e.payroll_frequency))}${kv('Payroll schedule', e.schedule_name)}${c ? kv('Current pay', `${moneyText(c.pay_basis === 'hourly' ? c.hourly_rate : c.base, c.currency)} / ${titleCase(c.pay_basis)}`) : kv('Current pay', 'Not set yet')}${c ? kv('Pay effective from', fmtDate(c.effective_from)) : ''}`)}
    ${section('Tax and payment', html`${kv('Tax ID', e.tax_id)}${kv('Bank', e.bank_name)}${kv('Bank account', e.bank_account)}`)}
    <p class="muted small">For your security, tax ID and bank account numbers show only the last four digits.</p>`);
  route.view.querySelector('#btn-pw').addEventListener('click', () => changePasswordDialog());
}

export async function render(ctx, route) {
  let me;
  try { me = await ctx.api.get('/me'); } catch (err) { route.paint(errorState(err, false)); return; }
  if (route.stale()) return;
  const e = me.employee;
  const c = me.compensation;
  const TABS = ['compensation', 'deductions', 'benefits'];
  let tab = TABS.includes(route.query.tab) ? route.query.tab : 'compensation';

  const comps = (list, empty) => (list.length ? table({ columns: [
    { label: 'Name', render: (a) => a.component.name }, { label: 'Amount', align: 'right', render: (a) => (a.calc === 'percent' ? `${a.rate_pct}%` : money(a.amount, a.currency || c?.currency || e.currency)) },
    { label: 'Frequency', render: (a) => titleCase(a.frequency) }, { label: 'From', render: (a) => fmtDate(a.effective_from) },
  ], rows: list }) : emptyState({ title: empty, iconName: 'inbox' }));

  const panels = {
    compensation: () => (c ? html`<div class="card-pad"><div class="grid-kpi">${kpi('Pay basis', titleCase(c.pay_basis))}${kpi(c.pay_basis === 'hourly' ? 'Hourly rate' : 'Base pay', money(c.base, c.currency), ({ hourly: 'per hour', annual: 'per year', monthly: 'per month', semimonthly: 'twice a month', biweekly: 'every two weeks', weekly: 'per week' })[c.pay_basis] || '')}${kpi('Effective from', fmtDate(c.effective_from))}</div></div>${comps(me.earnings, 'No allowances or additional earnings.')}`
      : emptyState({ title: 'No compensation is on record yet.', text: 'Contact HR if you think this is a mistake.', iconName: 'inbox' })),
    deductions: () => html`${comps(me.deductions, 'No deductions are set up for you.')}<div class="card-pad" style="border-top:1px solid var(--line)"><h4 class="section-title" style="margin-top:0">Taxes that apply to your location</h4>
      ${me.taxes.length ? html`<ul style="margin:0;padding-left:18px">${me.taxes.map((t) => html`<li>${t.name} (${titleCase(t.type)})${t.verified ? '' : html` <span class="tag-unverified">Not verified</span>`}</li>`)}</ul>` : html`<p class="muted">No tax rules apply to your location.</p>`}</div>`,
    benefits: () => comps(me.benefits, 'No benefits are set up for you.'),
  };

  const draw = () => {
    route.view.querySelector('#pt-tabs').innerHTML = tabs([{ id: 'compensation', label: 'Compensation' }, { id: 'deductions', label: 'Deductions and taxes' }, { id: 'benefits', label: 'Benefits' }], tab).toString();
    route.view.querySelector('#pt-body').innerHTML = panels[tab]().toString();
  };
  route.paint(html`${pageHeader({ title: 'My pay', sub: `${e.full_name} - ${e.job_title || ''}${e.department ? ', ' + e.department : ''}`, actions: html`<a class="btn btn-primary" href="#/my-payrolls">View my payrolls</a>` })}
    <div class="card"><div id="pt-tabs"></div><div id="pt-body"></div></div>`);
  draw();
  on(route.view, 'click', '[data-tab]', (ev, b) => { tab = b.dataset.tab; draw(); });
}

/** My payrolls: every payroll I have been paid in, newest first, with the payslip for each. */
export async function payrolls(ctx, route) {
  let items;
  try { items = (await ctx.api.get('/me/payslips', { limit: 200 })).items; } catch (err) { route.paint(errorState(err, false)); return; }
  if (route.stale()) return;
  const years = [...new Set(items.map((p) => String(p.pay_date).slice(0, 4)))].sort().reverse();
  let year = '';
  const body = () => {
    const rows = items.filter((p) => !year || String(p.pay_date).startsWith(year));
    if (!rows.length) return emptyState({ title: items.length ? 'No payrolls in this year.' : 'You have no payrolls yet.', text: items.length ? '' : 'Your payrolls appear here after your pay has been processed.', iconName: 'file' });
    const cur = rows[0].currency;
    const same = rows.every((p) => p.currency === cur);
    return html`${same ? html`<div class="card-pad"><div class="grid-kpi">${kpi('Payrolls', String(rows.length))}${kpi('Total gross', money(rows.reduce((t, p) => decAdd(t, p.gross), '0.00'), cur))}${kpi('Total net pay', money(rows.reduce((t, p) => decAdd(t, p.net), '0.00'), cur))}</div></div>` : ''}
      ${table({ columns: [
        { label: 'Pay period', render: (p) => fmtPeriod(p.period_start, p.period_end) }, { label: 'Pay date', render: (p) => fmtDate(p.pay_date) },
        { label: 'Gross', align: 'right', render: (p) => money(p.gross, p.currency) }, { label: 'Deductions', align: 'right', render: (p) => money(p.deductions, p.currency) },
        { label: 'Net pay', align: 'right', render: (p) => html`<b>${money(p.net, p.currency)}</b>` },
        { label: '', render: (p) => html`<div class="actions"><a class="btn btn-outline btn-sm" href="#/payslips/${p.id}?me=1">View payslip</a><button class="btn btn-outline btn-sm" data-dl="${p.id}">Download</button><button class="btn btn-outline btn-sm" data-mail="${p.id}">Email me</button></div>` },
      ], rows })}`;
  };
  route.paint(html`${pageHeader({ title: 'My payrolls', sub: 'Your pay for every payroll, with the payslip for each. Payslips never change after they are issued.', actions: years.length > 1 ? html`<select class="form-select" id="yr" aria-label="Year"><option value="">All years</option>${years.map((y) => html`<option value="${y}">${y}</option>`)}</select>` : '' })}
    <div class="card" id="pr-card"></div>`);
  const card = route.view.querySelector('#pr-card');
  const draw = () => { card.innerHTML = body().toString(); };
  draw();
  route.view.querySelector('#yr')?.addEventListener('change', (ev) => { year = ev.target.value; draw(); });
  on(route.view, 'click', '[data-dl]', (ev, b) => downloadPayslipPdf(ctx, +b.dataset.dl, b));
  on(route.view, 'click', '[data-mail]', async (ev, b) => sendPayslipDialog(ctx, await ctx.api.get(`/payslips/${b.dataset.mail}`)));
}
