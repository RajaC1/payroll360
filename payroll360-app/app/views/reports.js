// Reports and analytics. Everything is computed on the server from finalised payroll; nothing here is sample data.
import { html, raw, on, pageHeader, tabs, table, emptyState, errorState, skeleton, field, readForm, withBusy, toast, saveBlob, money, fmtDate, num, titleCase, icon } from '../ui.js';
import { barChart, lineChart, hBarChart, legend, monthLabel, COLORS } from '../charts.js';

const TYPES = [
  ['summary', 'Payroll summary', 'Gross, taxes, deductions, net pay and employer cost for each payroll.'],
  ['gross_to_net', 'Gross-to-net', 'Employee-level calculation from gross pay to net pay.'],
  ['earnings', 'Earnings', 'All earnings by month and type.'],
  ['deductions', 'Deductions and benefits', 'All deductions and benefit contributions by month.'],
  ['taxes', 'Taxes', 'Tax totals by month, split into employee and employer amounts.'],
  ['department', 'Department payroll', 'Payroll grouped by department.'],
  ['employee_history', 'Employee payroll history', 'Every payroll for one employee.'],
  ['cost', 'Payroll cost', 'Employer cost and cost per employee by month.'],
];

export async function render(ctx, route) {
  let employees = [];
  if (ctx.can('employees.read')) { try { employees = (await ctx.api.get('/employees', { limit: 200, sort: 'name' })).items; } catch { /* optional */ } }
  const st = { tab: route.query.tab === 'analytics' ? 'analytics' : 'reports', type: TYPES.some((t) => t[0] === route.query.type) ? route.query.type : 'summary', f: { from: '', to: '', department: '', country: '', currency: '', employee_id: '' }, report: null, cur: '' };

  const filters = (withEmployee) => html`<form id="rf" class="toolbar" novalidate style="border-bottom:1px solid var(--line)">
    <label class="small muted filter-item">From <input class="form-control" type="date" name="from" value="${st.f.from}"></label>
    <label class="small muted filter-item">To <input class="form-control" type="date" name="to" value="${st.f.to}"></label>
    <select class="form-select filter-item" name="department" aria-label="Department"><option value="">All departments</option>${ctx.meta.departments.map((d) => html`<option value="${d.name}" ${d.name === st.f.department ? raw('selected') : ''}>${d.name}</option>`)}</select>
    <select class="form-select filter-item" name="country" aria-label="Country"><option value="">All countries</option>${ctx.meta.countries.map((c) => html`<option value="${c.code}" ${c.code === st.f.country ? raw('selected') : ''}>${c.name}</option>`)}</select>
    ${withEmployee ? html`<select class="form-select filter-item" name="employee_id" aria-label="Employee"><option value="">${st.type === 'employee_history' ? 'Choose an employee' : 'All employees'}</option>${employees.map((e) => html`<option value="${e.id}" ${String(e.id) === st.f.employee_id ? raw('selected') : ''}>${e.full_name}</option>`)}</select>` : ''}
    <span class="spacer"></span><button class="btn btn-outline btn-sm" type="button" data-act="clear">Clear</button></form>`;

  const cell = (col, row) => {
    const v = row[col.key];
    if (col.type === 'money') return money(v, row.currency);
    if (col.type === 'date') return fmtDate(v);
    if (col.type === 'int') return v;
    return v ?? '-';
  };

  const frame = () => route.paint(html`${pageHeader({ title: 'Reports', sub: 'Payroll reports and analytics from processed payroll.' })}
    ${tabs([{ id: 'reports', label: 'Reports' }, { id: 'analytics', label: 'Analytics' }], st.tab)}<div id="rep-body"></div>`);

  async function reportsTab() {
    const body = route.view.querySelector('#rep-body');
    body.innerHTML = html`<div class="two-col-wide"><div>
        <div class="card">${filters(true)}<div class="card-head"><div><h3 id="rep-title">${TYPES.find((t) => t[0] === st.type)[1]}</h3><p>${TYPES.find((t) => t[0] === st.type)[2]}</p></div>
          <div class="page-actions"><button class="btn btn-outline btn-sm" data-act="csv">${icon('download')} Export CSV</button><button class="btn btn-primary btn-sm" data-act="run">${icon('refresh')} Run report</button></div></div>
          <div id="rep-out">${skeleton(5)}</div></div></div>
      <div class="card card-pad"><h3 style="font-size:.98rem;margin-bottom:8px">Choose a report</h3><div style="display:flex;flex-direction:column;gap:4px">
        ${TYPES.map(([id, name]) => html`<button type="button" class="btn ${id === st.type ? 'btn-primary' : 'btn-outline'}" style="justify-content:flex-start" data-type="${id}">${name}</button>`)}</div></div></div>`.toString();
    await run();
  }

  const currentFilters = () => { const f = route.view.querySelector('#rf'); if (f) Object.assign(st.f, readForm(f)); return Object.fromEntries(Object.entries(st.f).filter(([, v]) => v)); };
  async function run() {
    const out = route.view.querySelector('#rep-out');
    if (!out) return;
    const q = currentFilters();
    if (st.type === 'employee_history' && !q.employee_id) { out.innerHTML = emptyState({ title: 'Choose an employee to see their payroll history.', iconName: 'users' }).toString(); return; }
    out.innerHTML = skeleton(5).toString();
    try {
      const r = await ctx.api.get(`/reports/${st.type}`, q);
      if (route.stale()) return;
      st.report = r;
      if (!r.rows.length) { out.innerHTML = emptyState({ title: 'No payroll data is available for the selected period.', text: 'Reports use processed payroll. Try a wider date range or clear the filters.', iconName: 'chart' }).toString(); return; }
      const totalKeys = r.totals.length ? Object.keys(r.totals[0]).filter((k) => k !== 'currency') : [];
      out.innerHTML = html`${table({ columns: r.columns.map((c) => ({ label: c.label, align: ['money', 'int'].includes(c.type) ? 'right' : undefined, render: (row) => cell(c, row) })), rows: r.rows })}
        <div class="card-pad" style="border-top:1px solid var(--line)"><div class="kpi-label" style="margin-bottom:8px">Totals by currency (amounts in different currencies are never added together)</div>
        ${table({ columns: [{ label: 'Currency', key: 'currency' }, ...totalKeys.map((k) => ({ label: (r.columns.find((c) => c.key === k)?.label) || titleCase(k), align: 'right', render: (t) => money(t[k], t.currency) }))], rows: r.totals })}</div>
        <div class="pager"><span>${r.row_count} row${r.row_count === 1 ? '' : 's'} &middot; generated ${new Date(r.generated_at).toLocaleString()}</span></div>`.toString();
    } catch (err) { if (route.stale()) return; out.innerHTML = errorState(err).toString(); out.querySelector('[data-action="retry"]')?.addEventListener('click', run); }
  }

  async function analyticsTab() {
    const body = route.view.querySelector('#rep-body');
    body.innerHTML = html`<div class="card">${filters(false)}<div id="an-out">${skeleton(6)}</div></div>`.toString();
    await drawAnalytics();
  }
  async function drawAnalytics() {
    const out = route.view.querySelector('#an-out');
    if (!out) return;
    const q = currentFilters();
    try {
      const a = await ctx.api.get('/analytics', q);
      if (route.stale()) return;
      if (!a.has_data) { out.innerHTML = emptyState({ title: 'No payroll data is available for the selected period.', text: 'Charts appear after at least one payroll has been processed.', iconName: 'chart' }).toString(); return; }
      if (!st.cur || !a.currencies.includes(st.cur)) st.cur = a.currencies[0];
      const m = a.monthly[st.cur];
      const labels = m.map((x) => monthLabel(x.period));
      const N = (k) => m.map((x) => num(x[k]));
      const depts = (a.departments[st.cur] || []).map((d) => ({ label: d.department, value: num(d.employer_cost) }));
      const S = (name, values, i) => ({ name, values, color: COLORS[i] });
      const box = (title, chart, leg) => html`<div class="card card-pad"><h3 style="font-size:.95rem;margin-bottom:8px">${title}</h3>${chart}${leg || ''}</div>`;
      out.innerHTML = html`<div class="card-pad">${a.currencies.length > 1 ? html`<div class="toolbar" style="padding:0 0 12px;border:0"><label class="small muted">Currency <select class="form-select" id="an-cur">${a.currencies.map((c) => html`<option ${c === st.cur ? raw('selected') : ''}>${c}</option>`)}</select></label><span class="muted small">Charts show one currency at a time.</span></div>` : html`<p class="muted small" style="margin-bottom:12px">Amounts in ${st.cur}.</p>`}
        <div class="two-col">
          ${box('Payroll cost trend (employer cost)', barChart({ labels, series: [S('Employer cost', N('employer_cost'), 0)], unit: st.cur }))}
          ${box('Gross vs net pay', barChart({ labels, series: [S('Gross', N('gross'), 0), S('Net', N('net'), 1)], unit: st.cur }), legend([S('Gross', [], 0), S('Net', [], 1)]))}
          ${box('Tax trend', lineChart({ labels, series: [S('Taxes', N('taxes'), 2)], unit: st.cur }))}
          ${box('Employee count', lineChart({ labels, series: [S('Employees paid', N('headcount'), 3)] }))}
          ${box('Payroll cost per employee', lineChart({ labels, series: [S('Cost per employee', N('cost_per_employee'), 4)], unit: st.cur }))}
          ${box('Department payroll (employer cost)', depts.length ? hBarChart({ rows: depts, unit: st.cur }) : html`<p class="muted">No department data.</p>`)}</div></div>`.toString();
      out.querySelector('#an-cur')?.addEventListener('change', (e) => { st.cur = e.target.value; drawAnalytics(); });
    } catch (err) { if (route.stale()) return; out.innerHTML = errorState(err).toString(); out.querySelector('[data-action="retry"]')?.addEventListener('click', drawAnalytics); }
  }

  const showTab = () => { frame(); return st.tab === 'reports' ? reportsTab() : analyticsTab(); };
  on(route.view, 'click', '[data-tab]', (e, b) => { st.tab = b.dataset.tab; showTab(); });
  on(route.view, 'click', '[data-type]', (e, b) => { currentFilters(); st.type = b.dataset.type; if (st.type !== 'employee_history') st.f.employee_id = st.f.employee_id; reportsTab(); });
  on(route.view, 'click', '[data-act]', async (e, b) => {
    const a = b.dataset.act;
    if (a === 'run') return st.tab === 'reports' ? run() : drawAnalytics();
    if (a === 'clear') { st.f = { from: '', to: '', department: '', country: '', currency: '', employee_id: '' }; return showTab(); }
    if (a === 'csv') {
      const q = currentFilters();
      if (st.type === 'employee_history' && !q.employee_id) return toast('Choose an employee first.', 'warn');
      await withBusy(b, 'Exporting...', async () => {
        try { const { blob, filename } = await ctx.api.file(`/reports/${st.type}`, { ...q, format: 'csv' }); saveBlob(blob, filename); } catch (err) { toast(err.message, 'error'); }
      });
    }
  });
  on(route.view, 'change', '#rf', () => { if (st.tab === 'analytics') drawAnalytics(); else run(); });
  await showTab();
}
