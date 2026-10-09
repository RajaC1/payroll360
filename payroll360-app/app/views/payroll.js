// Payroll: runs list, "Run payroll" wizard, and the run workspace (review, exceptions, approval, processing).
import { html, raw, on, debounce, pageHeader, table, badge, kpi, money, moneyText, decAdd, stageStepper, emptyState, errorState, skeleton, field, readForm, showFieldErrors, clearFieldErrors,
  formAlert, withBusy, openModal, openDrawer, confirmDialog, tabs, toast, titleCase, fmtDate, fmtDateTime, fmtPeriod, icon, esc } from '../ui.js';
import { downloadPayslipPdf, sendPayslipDialog } from './payslips.js';

const STATUS_TEXT = {
  DRAFT: 'Draft', PREPARING: 'Confirm employees, then calculate', CALCULATING: 'Calculating', REVIEW: 'Review the calculated payroll', APPROVAL: 'Waiting for approval',
  APPROVED: 'Approved - ready to process', PROCESSING: 'Processing', PROCESSED: 'Processed - payslips generated', PAID: 'Paid', CANCELLED: 'Cancelled',
};

// ------------------------------------------------------------------------------------------------ list
export async function list(ctx, route) {
  const st = { status: route.query.status || '' };
  const canRun = ctx.can('payroll.run');
  route.paint(html`${pageHeader({ title: 'Payroll runs', sub: 'Every payroll, from draft to paid.', actions: canRun ? html`<a class="btn btn-primary" href="#/payrolls/new">${icon('plus')} Run payroll</a>` : '' })}
    <div class="card"><div class="toolbar"><select class="form-select" id="pl-status" aria-label="Status"><option value="">All statuses</option>${ctx.meta.run_statuses.map((s) => html`<option value="${s}" ${s === st.status ? raw('selected') : ''}>${titleCase(s)}</option>`)}</select></div><div id="pl-results">${skeleton(5)}</div></div>`);
  const results = route.view.querySelector('#pl-results');
  const load = async () => {
    try {
      const r = await ctx.api.get('/payrolls', { status: st.status });
      if (route.stale()) return;
      results.innerHTML = (r.items.length ? table({
        rowAttrs: (p) => `class="clickable" data-href="#/payrolls/${p.id}" tabindex="0"`,
        columns: [
          { label: 'Payroll', render: (p) => html`<div class="cell-main">${p.name}</div><div class="cell-sub">${p.entity_name}</div>` },
          { label: 'Period', render: (p) => fmtPeriod(p.period_start, p.period_end) },
          { label: 'Pay date', render: (p) => fmtDate(p.pay_date) },
          { label: 'Status', render: (p) => badge(p.status) },
          { label: 'Employees', align: 'right', render: (p) => p.included_count },
          { label: 'Gross', align: 'right', render: (p) => (p.calculated_at ? money(p.gross, p.currency) : '-') },
          { label: 'Net pay', align: 'right', render: (p) => (p.calculated_at ? money(p.net, p.currency) : '-') },
          { label: 'Attention', render: (p) => (p.exception_count && !['PROCESSED', 'PAID', 'CANCELLED'].includes(p.status) ? badge('error', `${p.exception_count} to fix`) : '') },
        ], rows: r.items,
      }) : emptyState({ title: st.status ? 'No payroll has this status.' : 'No payroll has been created yet.', text: canRun && !st.status ? 'Start your first payroll to calculate pay, taxes and payslips.' : '', actionLabel: canRun && !st.status ? 'Run payroll' : '', actionAttr: 'data-go="#/payrolls/new"', iconName: 'calendar' })).toString();
    } catch (err) {
      if (route.stale()) return;
      results.innerHTML = errorState(err).toString();
      results.querySelector('[data-action="retry"]')?.addEventListener('click', load);
    }
  };
  route.view.querySelector('#pl-status').addEventListener('change', (e) => { st.status = e.target.value; load(); });
  on(results, 'click', 'tr[data-href]', (e, tr) => { if (!e.target.closest('a,button')) ctx.navigate(tr.dataset.href); });
  on(results, 'keydown', 'tr[data-href]', (e, tr) => { if (e.key === 'Enter') ctx.navigate(tr.dataset.href); });
  on(route.view, 'click', '[data-go]', (e, b) => ctx.navigate(b.dataset.go));
  await load();
}

// ------------------------------------------------------------------------------------------------ wizard
export async function wizard(ctx, route) {
  const scheds = ctx.meta.schedules.filter((s) => s.status === 'active');
  const st = { step: 0, schedule: null, periods: [], period: null };
  const NAMES = ['Payroll schedule', 'Pay period', 'Confirm'];
  const bar = () => html`<div class="wizard-steps">${NAMES.map((n, i) => html`<span class="ws ${i === st.step ? 'active' : i < st.step ? 'done' : ''}"><b>${i < st.step ? raw('&#10003;') : i + 1}</b>${n}</span>`)}</div>`;
  const frame = (inner, footer) => html`${pageHeader({ crumbs: [{ label: 'Payroll runs', href: '#/payrolls' }, { label: 'Run payroll' }], title: 'Run payroll', sub: 'Choose a schedule and pay period. Employees are added from the schedule and you can review them before anything is calculated.' })}
    <div class="card card-pad">${bar()}<div id="wz-alert"></div>${inner}<div style="display:flex;gap:8px;justify-content:space-between;margin-top:18px;flex-wrap:wrap">${footer}</div></div>`;
  const paint = () => {
    if (!scheds.length) {
      route.paint(html`${pageHeader({ crumbs: [{ label: 'Payroll runs', href: '#/payrolls' }, { label: 'Run payroll' }], title: 'Run payroll' })}<div class="card">${emptyState({ title: 'There is no payroll schedule yet.', text: 'A schedule says how often you pay, when the cut-off is and which employees are on it.', actionLabel: ctx.can('schedules.manage') ? 'Create a schedule' : '', actionAttr: 'data-go="#/schedules"', iconName: 'calendar' })}</div>`);
      return;
    }
    if (st.step === 0) {
      route.paint(frame(html`<div class="grid-kpi" role="radiogroup" aria-label="Payroll schedule">${scheds.map((s) => html`<button type="button" class="kpi" style="text-align:left;cursor:pointer;${st.schedule?.id === s.id ? 'border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-light)' : ''}" role="radio" aria-checked="${st.schedule?.id === s.id}" data-sched="${s.id}">
          <div class="kpi-label">${titleCase(s.frequency)} - ${s.currency}</div><div class="kpi-value" style="font-size:1.05rem">${s.name}</div><div class="kpi-sub">${s.entity_name}<br>${s.employee_count} active employee${s.employee_count === 1 ? '' : 's'}</div></button>`)}</div>`,
      html`<a class="btn btn-outline" href="#/payrolls">Cancel</a><button class="btn btn-primary" data-act="next" ${st.schedule ? '' : raw('disabled')}>Continue</button>`));
    } else if (st.step === 1) {
      route.paint(frame(html`<p class="page-sub" style="margin-bottom:10px">Periods for <b>${st.schedule.name}</b> that have no payroll yet:</p>
        <div role="radiogroup" aria-label="Pay period">${st.periods.map((p) => html`<label class="card card-pad" style="display:flex;gap:12px;align-items:center;cursor:pointer;margin-bottom:8px;${st.period?.period_start === p.period_start ? 'border-color:var(--accent)' : ''}"><input type="radio" name="period" value="${p.period_start}" ${st.period?.period_start === p.period_start ? raw('checked') : ''}>
          <div><b>${fmtPeriod(p.period_start, p.period_end)}</b><div class="small muted">Cut-off ${fmtDate(p.cutoff_date)} &middot; pay date ${fmtDate(p.pay_date)}</div></div></label>`)}</div>`,
      html`<button class="btn btn-outline" data-act="back">Back</button><button class="btn btn-primary" data-act="next" ${st.period ? '' : raw('disabled')}>Continue</button>`));
    } else {
      route.paint(frame(html`<div class="summary-list"><div><span>Schedule</span><span>${st.schedule.name}</span></div><div><span>Legal entity</span><span>${st.schedule.entity_name}</span></div>
        <div><span>Pay period</span><span>${fmtPeriod(st.period.period_start, st.period.period_end)}</span></div><div><span>Cut-off date</span><span>${fmtDate(st.period.cutoff_date)}</span></div>
        <div><span>Pay date</span><span>${fmtDate(st.period.pay_date)}</span></div><div><span>Currency</span><span>${st.schedule.currency}</span></div></div>
        <p class="page-sub">Employees on this schedule are added automatically. You will confirm them on the next screen before calculating.</p>`,
      html`<button class="btn btn-outline" data-act="back">Back</button><button class="btn btn-primary" data-act="create">Create payroll</button>`));
    }
  };
  paint();
  on(route.view, 'click', '[data-go]', (e, b) => ctx.navigate(b.dataset.go));
  on(route.view, 'click', '[data-sched]', (e, b) => { st.schedule = scheds.find((s) => String(s.id) === b.dataset.sched); paint(); });
  on(route.view, 'change', 'input[name=period]', (e, i) => { st.period = st.periods.find((p) => p.period_start === i.value); paint(); });
  on(route.view, 'click', '[data-act]', async (e, b) => {
    const a = b.dataset.act;
    if (a === 'back') { st.step -= 1; return paint(); }
    if (a === 'next') {
      if (st.step === 0) {
        await withBusy(b, 'Loading periods...', async () => {
          try { st.periods = (await ctx.api.get(`/schedules/${st.schedule.id}/periods`)).items; st.period = st.periods[0] || null; st.step = 1; paint(); } catch (err) { toast(err.message, 'error'); }
        });
      } else { st.step = 2; paint(); }
    }
    if (a === 'create') {
      await withBusy(b, 'Creating payroll...', async () => {
        try {
          const run = await ctx.api.post('/payrolls', { schedule_id: st.schedule.id, period_start: st.period.period_start });
          toast(`${run.name} was created.`);
          ctx.navigate(`#/payrolls/${run.id}`);
        } catch (err) { route.view.querySelector('#wz-alert').innerHTML = html`<div class="form-alert">${err.message}</div>`.toString(); }
      });
    }
  });
}

// ------------------------------------------------------------------------------------------------ detail
export async function detail(ctx, route) {
  const id = route.params.id;
  const st = { run: null, ex: null, tab: route.query.tab || null, f: { q: '', department: '', country: '', status: '', sort: 'name', dir: 'asc' }, review: null };
  const cur = () => st.run.currency;
  const mny = (v) => money(v, cur());
  const loadRun = async () => { [st.run, st.ex] = await Promise.all([ctx.api.get(`/payrolls/${id}`), ctx.api.get(`/payrolls/${id}/exceptions`)]); };
  const editable = () => ['PREPARING', 'REVIEW'].includes(st.run.status);
  const defaultTab = () => (['DRAFT', 'PREPARING'].includes(st.run.status) ? 'employees' : 'review');

  const tabDefs = () => [
    { id: 'employees', label: 'Employees' }, { id: 'review', label: 'Review' },
    { id: 'exceptions', label: 'Exceptions', count: (st.ex.employees_with_errors || 0) + st.ex.run_level.filter((x) => x.severity === 'error').length || undefined },
    { id: 'activity', label: 'Activity' }, ...(st.run.payslip_count ? [{ id: 'payslips', label: 'Payslips', count: st.run.payslip_count }] : []),
  ];
  const ACT = {
    calculate: { label: () => (st.run.status === 'PREPARING' ? 'Calculate payroll' : 'Recalculate'), cls: 'btn-primary', ico: 'refresh' },
    submit: { label: () => 'Submit for approval', cls: 'btn-primary', ico: 'check' },
    approve: { label: () => 'Approve payroll', cls: 'btn-primary', ico: 'check' },
    process: { label: () => 'Process payroll', cls: 'btn-primary', ico: 'play' },
    mark_paid: { label: () => 'Mark as paid', cls: 'btn-primary', ico: 'dollar' },
    reopen: { label: () => 'Reopen', cls: 'btn-outline', ico: 'lock' },
    back_to_preparation: { label: () => 'Back to preparation', cls: 'btn-outline', ico: 'arrowleft' },
    cancel: { label: () => 'Cancel payroll', cls: 'btn-outline', ico: 'x' },
  };

  const frame = () => {
    const r = st.run;
    const acts = r.actions || [];
    const primary = acts.filter((a) => ['calculate', 'submit', 'approve', 'process', 'mark_paid'].includes(a));
    const secondary = acts.filter((a) => !primary.includes(a));
    const calculated = !!r.calculated_at;
    const ex = st.ex;
    route.paint(html`
      ${pageHeader({
        crumbs: [{ label: 'Payroll runs', href: '#/payrolls' }, { label: r.name }], title: html`${r.name} ${badge(r.status)}`,
        sub: `${STATUS_TEXT[r.status]} - ${fmtPeriod(r.period_start, r.period_end)} - cut-off ${fmtDate(r.cutoff_date)} - pay date ${fmtDate(r.pay_date)}`,
        actions: html`${secondary.map((a) => html`<button class="btn ${ACT[a].cls}" data-act="${a}">${icon(ACT[a].ico)} ${ACT[a].label()}</button>`)}${primary.map((a) => html`<button class="btn ${ACT[a].cls}" data-act="${a}">${icon(ACT[a].ico)} ${ACT[a].label()}</button>`)}`,
      })}
      ${r.status === 'CANCELLED' ? html`<div class="banner banner-bad">This payroll was cancelled${r.cancelled_reason ? ': ' + r.cancelled_reason : ''}.</div>` : stageStepper(r.stages)}
      ${r.locked ? html`<div class="banner banner-info">${icon('lock', 18)}<div class="grow">This payroll is ${r.status === 'APPROVED' ? 'approved' : 'finalised'} and locked. ${r.status === 'APPROVED' ? 'To change it, reopen it with a reason.' : 'Processed payroll cannot be changed; corrections go in the next payroll.'}</div></div>` : ''}
      ${r.unverified_tax_lines > 0 && !['CANCELLED'].includes(r.status) ? html`<div class="banner banner-warn">${icon('alert', 18)}<div class="grow">This payroll uses tax rules that are <b>not verified</b>. Confirm your tax configuration before paying real employees.</div>${ctx.can('taxrules.read') ? html`<a class="btn btn-outline btn-sm" href="#/settings/tax">Tax rules</a>` : ''}</div>` : ''}
      ${r.stale ? html`<div class="banner banner-warn">${icon('alert', 18)}<div class="grow">Employee or pay data has changed since this payroll was calculated. Recalculate before continuing.</div></div>` : ''}
      ${ex.blocking && r.calculated_at && !['PROCESSED', 'PAID', 'CANCELLED'].includes(r.status) && !r.stale ? html`<div class="banner banner-bad" role="alert">${icon('alert', 20)}<div class="grow"><b>${ex.message}</b></div><button class="btn btn-outline btn-sm" data-tab="exceptions">Review exceptions</button></div>` : ''}
      ${!ex.blocking && calculated && r.status === 'REVIEW' ? html`<div class="banner banner-good">${icon('check', 18)}<div class="grow">No blocking exceptions. This payroll can be submitted for approval.</div></div>` : ''}
      ${calculated ? html`<div class="grid-kpi">${kpi('Employees', r.included_count)}${kpi('Gross payroll', mny(r.gross))}${kpi('Taxes', mny(r.taxes))}${kpi('Benefits and deductions', mny(decAdd(r.benefits, r.deductions)))}${kpi('Net payroll', mny(r.net), '', 'good')}${kpi('Employer cost', mny(r.employer_cost), `incl. ${money(r.employer_contrib, cur()).toString().replace(/<[^>]+>/g, '')} contributions`)}</div>` : ''}
      <div id="tabs-slot"></div><div id="tab-body"></div>`);
    drawTabs();
  };
  const drawTabs = () => { route.view.querySelector('#tabs-slot').innerHTML = tabs(tabDefs(), st.tab).toString(); };
  const tabBody = () => route.view.querySelector('#tab-body');

  const paintTab = async () => {
    const body = tabBody();
    if (!body) return;
    body.innerHTML = skeleton(5).toString();
    try {
      const out = await TAB[st.tab]();
      if (route.stale()) return;
      body.innerHTML = out.toString();
      if (st.tab === 'review') await loadReview();
    } catch (err) {
      if (route.stale()) return;
      body.innerHTML = errorState(err).toString();
      body.querySelector('[data-action="retry"]')?.addEventListener('click', paintTab);
    }
  };
  const refresh = async () => { await loadRun(); if (route.stale()) return; if (!tabDefs().some((t) => t.id === st.tab)) st.tab = defaultTab(); frame(); await paintTab(); };

  // ---------------------------------------------------------------------------------------- tabs
  const empRows = (items, cols) => table({ rowAttrs: (r) => `class="clickable ${r.errors ? 'row-error' : ''} ${r.included ? '' : 'row-muted'}" data-emp="${r.employee_id}" tabindex="0"`, columns: cols, rows: items });
  const TAB = {
    async employees() {
      const r = await ctx.api.get(`/payrolls/${id}/employees`);
      const ed = editable();
      return html`<div class="card"><div class="card-head"><div><h3>Employees in this payroll</h3><p>Confirm who is being paid. Excluded employees are skipped and can be added back until the payroll is approved.</p></div>
        ${ed ? html`<button class="btn btn-outline btn-sm" data-act="add-employees">${icon('plus')} Add employees</button>` : ''}</div>
        ${r.items.length ? empRows(r.items, [
          { label: 'Employee', render: (e) => html`<div class="cell-main">${e.name}</div><div class="cell-sub">${e.employee_no}</div>` },
          { label: 'Department', render: (e) => e.department || '-' }, { label: 'Country', render: (e) => e.country || '-' },
          { label: 'Included', render: (e) => (e.included ? badge('ok', 'Included') : badge('excluded', 'Excluded')) },
          { label: 'Attention', render: (e) => (e.included && e.errors ? badge('error', `${e.errors} to fix`) : e.included && e.warnings ? badge('warning', `${e.warnings} note`) : '') },
          ...(ed ? [{ label: '', render: (e) => html`<div class="actions"><button class="btn btn-outline btn-sm" data-toggle="${e.employee_id}" data-include="${e.included ? '0' : '1'}">${e.included ? 'Exclude' : 'Include'}</button></div>` }] : []),
        ]) : emptyState({ title: 'No employees are in this payroll.', text: ed ? 'Add employees to include them in the calculation.' : '', iconName: 'users' })}
        ${st.run.status === 'PREPARING' ? html`<div class="pager"><span>${r.items.filter((e) => e.included).length} employee${r.items.filter((e) => e.included).length === 1 ? '' : 's'} included. Calculating checks every employee's pay, tax and payment details.</span><button class="btn btn-primary" data-act="calculate">${icon('refresh')} Calculate payroll</button></div>` : ''}</div>`;
    },
    async review() {
      const meta = await ctx.api.get(`/payrolls/${id}/employees`);
      st.review = meta.filters;
      const f = st.f;
      return html`<div class="card"><div class="toolbar collapsed" id="rv-toolbar">
          <div class="search"><span>${icon('search')}</span><input class="form-control" id="rv-q" type="search" placeholder="Search employee or ID" aria-label="Search" value="${f.q}"></div>
          <button class="btn btn-outline btn-sm filters-toggle" type="button" id="rv-ft">Filters</button>
          <select class="form-select filter-item" data-rf="department" aria-label="Department"><option value="">All departments</option>${meta.filters.departments.map((d) => html`<option value="${d}" ${d === f.department ? raw('selected') : ''}>${d}</option>`)}</select>
          <select class="form-select filter-item" data-rf="country" aria-label="Location"><option value="">All locations</option>${meta.filters.countries.map((d) => html`<option value="${d}" ${d === f.country ? raw('selected') : ''}>${d}</option>`)}</select>
          <select class="form-select filter-item" data-rf="status" aria-label="Status"><option value="">All statuses</option>${['ok', 'exception', 'excluded'].map((s) => html`<option value="${s}" ${s === f.status ? raw('selected') : ''}>${titleCase(s === 'ok' ? 'Ready' : s)}</option>`)}</select></div>
        <div id="rv-results">${skeleton(5)}</div></div>`;
    },
    async exceptions() {
      const ex = st.ex;
      const any = ex.run_level.length || ex.employees.length;
      return html`${any ? html`<div class="card"><div class="card-head"><div><h3>${ex.message || 'Notes and exceptions'}</h3><p>Errors block approval. Fix the data, or exclude the employee from this payroll. Warnings are for your information.</p></div></div>
        ${ex.run_level.length ? html`<div class="card-pad"><ul class="exception-list">${ex.run_level.map((x) => html`<li><span class="sev-${x.severity}">${icon('alert')}</span><span>${x.message}</span></li>`)}</ul></div>` : ''}
        ${ex.employees.map((e) => html`<div class="card-pad" style="border-top:1px solid var(--line)"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><div><b>${e.name}</b> <span class="muted small">${e.employee_no}</span></div>
            <div class="actions"><a class="btn btn-outline btn-sm" href="#/employees/${e.employee_id}" target="_blank" rel="noopener">Open profile</a>${editable() ? html`<button class="btn btn-outline btn-sm" data-toggle="${e.employee_id}" data-include="0">Exclude from payroll</button>` : ''}</div></div>
          <ul class="exception-list">${e.exceptions.map((x) => html`<li><span class="sev-${x.severity}">${icon('alert')}</span><span>${x.message}</span></li>`)}</ul></div>`)}</div>`
        : html`<div class="card">${emptyState({ title: 'Nothing needs attention.', text: st.run.calculated_at ? 'Every included employee has complete pay, tax and payment details.' : 'Calculate the payroll to check every employee.', iconName: 'check' })}</div>`}`;
    },
    async activity() {
      const r = st.run;
      return html`<div class="two-col"><div class="card"><div class="card-head"><div><h3>Status history</h3></div></div>
          <div class="card-pad"><ul class="checklist">${[...r.history].reverse().map((h) => html`<li><span class="ok">${icon('check')}</span><span><b>${h.to_status === h.from_status ? 'Recalculated' : titleCase(h.to_status)}</b>${h.note ? html` - ${h.note}` : ''}<br><span class="small muted">${h.user_name || 'System'} &middot; ${fmtDateTime(h.at)}</span></span></li>`)}</ul></div></div>
        <div class="card"><div class="card-head"><div><h3>Approvals</h3><p>Who approved or reopened this payroll, and what the totals were.</p></div></div>
          <div class="card-pad">${r.approvals.length ? html`<ul class="checklist">${r.approvals.map((a) => html`<li><span class="${a.action === 'approved' ? 'ok' : 'bad'}">${icon(a.action === 'approved' ? 'check' : 'lock')}</span><span><b>${titleCase(a.action)}</b> by ${a.user_email}<br><span class="small muted">${fmtDateTime(a.at)}${a.note ? ' - ' + a.note : ''}</span>
            ${a.totals ? html`<br><span class="small">${a.totals.employees} employees &middot; gross ${moneyText(a.totals.gross, a.totals.currency)} &middot; net ${moneyText(a.totals.net, a.totals.currency)}</span>` : ''}</span></li>`)}</ul>` : html`<p class="muted">Nobody has approved this payroll yet.</p>`}</div></div></div>`;
    },
    async payslips() {
      const r = await ctx.api.get('/payslips', { run_id: id, limit: 200 });
      return html`<div class="card"><div class="card-head"><div><h3>Payslips generated</h3></div></div>${r.items.length ? table({ columns: [
        { label: 'Employee', render: (p) => html`<div class="cell-main">${p.employee_name}</div><div class="cell-sub">${p.number}</div>` }, { label: 'Net pay', align: 'right', render: (p) => money(p.net, p.currency) },
        { label: 'Status', render: (p) => badge(p.status) },
        { label: '', render: (p) => html`<div class="actions"><a class="btn btn-outline btn-sm" href="#/payslips/${p.id}">View</a><button class="btn btn-outline btn-sm" data-dl="${p.id}">Download</button>${ctx.can('payslips.send') ? html`<button class="btn btn-outline btn-sm" data-send="${p.id}">Send</button>` : ''}</div>` }], rows: r.items })
        : emptyState({ title: 'No payslips are available.', iconName: 'file' })}</div>`;
    },
  };

  async function loadReview() {
    const box = route.view.querySelector('#rv-results');
    if (!box) return;
    const f = st.f;
    try {
      const r = await ctx.api.get(`/payrolls/${id}/employees`, { q: f.q, department: f.department, country: f.country, status: f.status, sort: f.sort, dir: f.dir });
      if (route.stale()) return;
      const sortState = { key: f.sort, dir: f.dir };
      box.innerHTML = (r.items.length ? table({
        sortState, rowAttrs: (e) => `class="clickable ${e.errors ? 'row-error' : ''} ${e.included ? '' : 'row-muted'}" data-emp="${e.employee_id}" tabindex="0"`,
        columns: [
          { label: 'Employee', sort: 'name', render: (e) => html`<div class="cell-main">${e.name}</div><div class="cell-sub">${e.employee_no}</div>` },
          { label: 'Department', sort: 'department', render: (e) => e.department || '-' },
          { label: 'Base pay', align: 'right', render: (e) => mny(e.base) }, { label: 'Earnings', align: 'right', render: (e) => mny(e.earnings) }, { label: 'Allowances', align: 'right', render: (e) => mny(e.allowances) },
          { label: 'Gross pay', sort: 'gross', align: 'right', render: (e) => mny(e.gross) }, { label: 'Taxes', sort: 'taxes', align: 'right', render: (e) => mny(e.taxes) },
          { label: 'Benefits', align: 'right', render: (e) => mny(e.benefits) }, { label: 'Deductions', align: 'right', render: (e) => mny(e.deductions) },
          { label: 'Net pay', sort: 'net', align: 'right', render: (e) => html`<b>${mny(e.net)}</b>` },
          { label: 'Status', sort: 'status', render: (e) => (!e.included ? badge('excluded', 'Excluded') : e.errors ? badge('error', `${e.errors} to fix`) : e.warnings ? badge('warning', 'Note') : badge('ok', 'Ready')) },
          { label: '', render: (e) => html`<button class="btn btn-outline btn-sm" data-emp="${e.employee_id}">Details</button>` },
        ], rows: r.items,
      }) : emptyState({ title: 'No employees match your filters.', iconName: 'search' })).toString();
    } catch (err) { box.innerHTML = errorState(err).toString(); box.querySelector('[data-action="retry"]')?.addEventListener('click', loadReview); }
  }

  // ---------------------------------------------------------------------------------------- employee drawer
  async function openEmployee(eid) {
    const d = openDrawer({ title: 'Payroll details', size: 'xl', body: skeleton(6) });
    const draw = async () => {
      let x;
      try { x = await ctx.api.get(`/payrolls/${id}/employees/${eid}`); } catch (err) { d.setBody(errorState(err, false)); return; }
      const c = x.run.currency;
      const M = (v) => money(v, c);
      const lines = (rows, cols) => rows.length ? html`<table class="line-table"><thead><tr>${cols.map((h) => html`<th class="${h.num ? 'num' : ''}">${h.t}</th>`)}</tr></thead><tbody>${rows.map((l) => html`<tr>${cols.map((h) => html`<td class="${h.num ? 'num' : ''}">${h.r(l)}</td>`)}</tr>`)}</tbody></table>` : html`<p class="muted small" style="margin-bottom:12px">None.</p>`;
      d.setTitle(x.employee.name || 'Employee', `${x.employee.employee_no || ''} - ${x.employee.department || ''} - ${x.run.name}`);
      d.setBody(html`
        ${!x.included ? html`<div class="banner banner-info">This employee is excluded from the payroll.</div>` : ''}
        ${x.exceptions.length ? html`<div class="banner ${x.exceptions.some((e) => e.severity === 'error') ? 'banner-bad' : 'banner-warn'}"><div class="grow"><b>${x.exceptions.some((e) => e.severity === 'error') ? 'Needs attention' : 'Notes'}</b><ul class="exception-list">${x.exceptions.map((e) => html`<li><span class="sev-${e.severity}">${icon('alert')}</span><span>${e.message}</span></li>`)}</ul>
          <a class="btn btn-outline btn-sm" href="#/employees/${eid}" target="_blank" rel="noopener">Open employee profile to fix</a></div></div>` : ''}
        <h4 class="section-title">Earnings</h4>${lines(x.earnings, [{ t: 'Earning', r: (l) => html`${l.name}${l.note ? html`<div class="calc">${l.note}</div>` : ''}` }, { t: 'Calculation', r: (l) => html`<span class="calc">${l.calculation}</span>` }, { t: 'Amount', num: 1, r: (l) => M(l.amount) }])}
        <h4 class="section-title">Taxes</h4>${lines(x.taxes, [{ t: 'Tax', r: (l) => html`${l.name}${l.note ? html`<span class="tag-unverified">Not verified</span>` : ''}` }, { t: 'Rate / method', r: (l) => html`<span class="calc">${l.calculation}</span>` }, { t: 'Employee', num: 1, r: (l) => M(l.amount) }, { t: 'Employer', num: 1, r: (l) => (l.employer ? M(l.employer) : '-') }])}
        <h4 class="section-title">Benefits</h4>${lines(x.benefits, [{ t: 'Benefit', r: (l) => html`${l.name}${l.note ? html`<div class="calc">${l.note}</div>` : ''}` }, { t: 'Calculation', r: (l) => html`<span class="calc">${l.calculation}</span>` }, { t: 'Employee', num: 1, r: (l) => M(l.amount) }, { t: 'Employer', num: 1, r: (l) => (l.employer ? M(l.employer) : '-') }])}
        <h4 class="section-title">Deductions</h4>${lines(x.deductions, [{ t: 'Deduction', r: (l) => l.name }, { t: 'Calculation', r: (l) => html`<span class="calc">${l.calculation}</span>` }, { t: 'Amount', num: 1, r: (l) => M(l.amount) }])}
        <h4 class="section-title">Summary</h4><table class="line-table"><tbody>
          <tr><td>Gross pay</td><td class="num">${M(x.summary.gross)}</td></tr><tr><td>Total taxes</td><td class="num">${M(x.summary.taxes)}</td></tr>
          <tr><td>Benefits (employee)</td><td class="num">${M(x.summary.benefits)}</td></tr><tr><td>Other deductions</td><td class="num">${M(x.summary.deductions)}</td></tr>
          <tr><td>Total deductions</td><td class="num">${M(x.summary.total_deductions)}</td></tr><tr><td>Employer contributions</td><td class="num">${M(x.summary.employer_contrib)}</td></tr></tbody></table>
        <div class="net-banner"><div><div class="label">Net pay</div><div class="small" style="opacity:.8">Employer cost ${moneyText(x.summary.employer_cost, c)}</div></div><div class="amount">${M(x.summary.net)}</div></div>
        <h4 class="section-title" style="margin-top:20px">Adjustments</h4>
        <p class="page-sub" style="margin-bottom:8px">One-off changes for this payroll only (a bonus, overtime hours or an extra deduction). Every adjustment needs a reason and is recorded in the audit log.</p>
        ${x.adjustments.length ? html`<table class="line-table"><thead><tr><th>Adjustment</th><th>Reason</th><th class="num">Amount</th><th></th></tr></thead><tbody>${x.adjustments.map((a) => html`<tr><td>${a.name}${a.kind === 'overtime_hours' ? html`<div class="calc">${a.hours} hours</div>` : ''}</td><td>${a.reason}</td><td class="num">${a.amount ? M(a.amount) : '-'}</td>
          <td>${x.editable ? html`<button class="btn btn-outline btn-sm" data-rm-adj="${a.id}">Remove</button>` : ''}</td></tr>`)}</tbody></table>` : html`<p class="muted small">No adjustments.</p>`}
        ${x.editable && x.included ? html`<form id="adj-form" class="card card-pad" novalidate><div class="form-grid">
          ${field({ name: 'kind', label: 'Type', type: 'select', options: [{ value: 'earning', label: 'Bonus or other earning' }, { value: 'deduction', label: 'One-off deduction' }, { value: 'overtime_hours', label: 'Overtime hours' }], value: 'earning' })}
          ${field({ name: 'name', label: 'Description', value: '' })}
          <div data-amt>${field({ name: 'amount', label: `Amount (${c})`, type: 'money' })}</div><div data-hrs class="hidden">${field({ name: 'hours', label: 'Hours', type: 'money', help: 'For example 8 or 7.5' })}</div>
          ${field({ name: 'taxable', label: 'Taxable', type: 'checkbox', value: true })}${field({ name: 'reason', label: 'Reason', type: 'textarea', rows: 2, wide: true, required: true })}</div>
          <button class="btn btn-outline" type="submit">${icon('plus')} Add adjustment</button></form>` : ''}`);
      const form = d.body.querySelector('#adj-form');
      if (form) {
        const sync = () => { const k = form.elements.kind.value; form.querySelector('[data-amt]').classList.toggle('hidden', k === 'overtime_hours'); form.querySelector('[data-hrs]').classList.toggle('hidden', k !== 'overtime_hours'); form.querySelector('[data-error-for=name]')?.closest('.p-field').classList.toggle('hidden', k === 'overtime_hours'); };
        form.elements.kind.addEventListener('change', sync); sync();
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          clearFieldErrors(form);
          const v = readForm(form);
          const body = { employee_id: eid, kind: v.kind, reason: v.reason, taxable: v.taxable };
          if (v.kind === 'overtime_hours') body.hours = v.hours; else { body.amount = v.amount; body.name = v.name; }
          await withBusy(form.querySelector('button[type=submit]'), 'Recalculating...', async () => {
            try { await ctx.api.post(`/payrolls/${id}/adjustments`, body); toast('Adjustment added and payroll recalculated.'); await draw(); await refresh(); }
            catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
          });
        });
      }
    };
    d.body.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-rm-adj]');
      if (!b) return;
      await withBusy(b, 'Removing...', async () => { try { await ctx.api.del(`/payrolls/${id}/adjustments/${b.dataset.rmAdj}`); toast('Adjustment removed.'); await draw(); await refresh(); } catch (err) { toast(err.message, 'error'); } });
    });
    await draw();
  }

  // ---------------------------------------------------------------------------------------- workflow actions
  const summary = (r) => html`<div class="summary-list"><div><span>Employees</span><span>${r.included_count}</span></div><div><span>Gross payroll</span><span>${money(r.gross, r.currency)}</span></div><div><span>Taxes</span><span>${money(r.taxes, r.currency)}</span></div>
    <div><span>Deductions and benefits</span><span>${money(decAdd(r.benefits, r.deductions), r.currency)}</span></div><div><span>Employer contributions</span><span>${money(r.employer_contrib, r.currency)}</span></div><div><span>Net payroll</span><span>${money(r.net, r.currency)}</span></div></div>`;
  const unverifiedNote = () => (st.run.unverified_tax_lines > 0 ? html`<div class="banner banner-warn" style="margin-top:10px">Tax figures use sample rules that are not verified.</div>` : '');

  const run = async (btn, busyLabel, fn, doneMsg) => {
    await withBusy(btn, busyLabel, async () => {
      try { await fn(); toast(doneMsg); await refresh(); } catch (err) { toast(err.message, 'error'); if (err.code === 'exceptions_block') { st.tab = 'exceptions'; } await refresh(); }
    });
  };
  const ACTIONS = {
    calculate: (b) => run(b, 'Calculating payroll...', () => ctx.api.post(`/payrolls/${id}/calculate`), 'Payroll calculated.'),
    back_to_preparation: (b) => run(b, 'Going back...', () => ctx.api.post(`/payrolls/${id}/back`), 'Returned to preparation.'),
    async submit(b) {
      await loadRun();
      if (st.ex.blocking) { toast(st.ex.message, 'error'); st.tab = 'exceptions'; frame(); return paintTab(); }
      const r = await confirmDialog({ title: `Submit ${st.run.name} for approval?`, size: 'md', details: html`${summary(st.run)}<div class="banner banner-good" style="margin-top:10px">Payroll is ready for approval.</div>${unverifiedNote()}`, confirmText: 'Submit for approval' });
      if (r.ok) run(b, 'Submitting...', () => ctx.api.post(`/payrolls/${id}/submit`), 'Submitted for approval.');
    },
    async approve(b) {
      const r = await confirmDialog({ title: `Approve ${st.run.name}?`, size: 'md', details: html`<p>Approving records your name, the time and these totals, and locks the payroll. Changing it later requires a controlled reopen.</p>${summary(st.run)}${unverifiedNote()}`, reasonLabel: 'Note (optional)', reasonRequired: false, confirmText: 'Approve payroll' });
      if (r.ok) run(b, 'Approving...', () => ctx.api.post(`/payrolls/${id}/approve`, { note: r.reason || '' }), 'Payroll approved.');
    },
    async process(b) {
      const r = await confirmDialog({ title: `Process ${st.run.name}?`, size: 'md', message: `This generates ${st.run.included_count} payslip${st.run.included_count === 1 ? '' : 's'} and finalises the payroll. It cannot be undone.`, details: summary(st.run), confirmText: 'Process payroll' });
      if (r.ok) run(b, 'Processing payroll...', () => ctx.api.post(`/payrolls/${id}/process`), 'Payroll processed and payslips generated.');
    },
    async mark_paid(b) {
      const r = await confirmDialog({ title: 'Mark this payroll as paid?', message: 'Do this once the money has been sent to employees.', confirmText: 'Mark as paid' });
      if (r.ok) run(b, 'Saving...', () => ctx.api.post(`/payrolls/${id}/pay`), 'Payroll marked as paid.');
    },
    async reopen(b) {
      const r = await confirmDialog({ title: 'Reopen this payroll?', message: 'It goes back to review so it can be corrected and approved again. The reopen is recorded.', reasonLabel: 'Why is it being reopened?', confirmText: 'Reopen' });
      if (r.ok) run(b, 'Reopening...', () => ctx.api.post(`/payrolls/${id}/reopen`, { note: r.reason }), 'Payroll reopened.');
    },
    async cancel(b) {
      const r = await confirmDialog({ title: 'Cancel this payroll?', message: 'Nothing is paid. You can create a new payroll for the same period afterwards.', reasonLabel: 'Reason', confirmText: 'Cancel payroll', danger: true });
      if (r.ok) run(b, 'Cancelling...', () => ctx.api.post(`/payrolls/${id}/cancel`, { note: r.reason }), 'Payroll cancelled.');
    },
    async 'add-employees'() {
      const r = (await ctx.api.get(`/payrolls/${id}/available-employees`)).items;
      const m = openModal({ title: 'Add employees', size: 'md', body: r.length ? html`<p class="page-sub" style="margin-bottom:8px">Employees who are not in this payroll. Their pay currency and frequency must match the payroll or they will show as exceptions.</p>
        <ul class="checklist">${r.map((e) => html`<li><label class="p-check"><input type="checkbox" value="${e.id}"> <span>${e.name} <span class="muted small">${e.employee_no} - ${e.currency}, ${e.frequency}</span></span></label></li>`)}</ul>` : emptyState({ title: 'Everyone eligible is already included.', iconName: 'users' }),
        footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button>${r.length ? html`<button class="btn btn-primary" data-x="add">Add selected</button>` : ''}` });
      m.foot.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-x]');
        if (!b) return;
        if (b.dataset.x === 'cancel') return m.close();
        const ids = [...m.body.querySelectorAll('input:checked')].map((i) => +i.value);
        if (!ids.length) return toast('Select at least one employee.', 'warn');
        await withBusy(b, 'Adding...', async () => { try { await ctx.api.post(`/payrolls/${id}/employees`, { employee_ids: ids, include: true }); m.close(); toast('Employees added.'); await refresh(); } catch (err) { toast(err.message, 'error'); } });
      });
    },
  };

  // ---------------------------------------------------------------------------------------- wiring (once)
  on(route.view, 'click', '[data-act]', (e, b) => ACTIONS[b.dataset.act]?.(b));
  on(route.view, 'click', '[data-tab]', (e, b) => { st.tab = b.dataset.tab; history.replaceState(null, '', `#/payrolls/${id}?tab=${st.tab}`); drawTabs(); paintTab(); });
  on(route.view, 'click', '[data-toggle]', async (e, b) => {
    e.stopPropagation();
    await withBusy(b, '...', async () => { try { await ctx.api.post(`/payrolls/${id}/employees`, { employee_ids: [+b.dataset.toggle], include: b.dataset.include === '1' }); await refresh(); } catch (err) { toast(err.message, 'error'); } });
  });
  on(route.view, 'click', '[data-emp]', (e, el) => { if (el.tagName === 'TR' && e.target.closest('button, a')) return; openEmployee(+el.dataset.emp); });
  on(route.view, 'keydown', 'tr[data-emp]', (e, tr) => { if (e.key === 'Enter') openEmployee(+tr.dataset.emp); });
  on(route.view, 'click', '[data-sort]', (e, b) => { st.f.dir = st.f.sort === b.dataset.sort && st.f.dir === 'asc' ? 'desc' : 'asc'; st.f.sort = b.dataset.sort; loadReview(); });
  on(route.view, 'change', '[data-rf]', (e, s) => { st.f[s.dataset.rf] = s.value; loadReview(); });
  on(route.view, 'input', '#rv-q', debounce((e) => { st.f.q = e.target.value.trim(); loadReview(); }, 300));
  on(route.view, 'click', '#rv-ft', () => route.view.querySelector('#rv-toolbar').classList.toggle('collapsed'));
  on(route.view, 'click', '[data-dl]', (e, b) => downloadPayslipPdf(ctx, +b.dataset.dl, b));
  on(route.view, 'click', '[data-send]', async (e, b) => { const p = (await ctx.api.get(`/payslips/${b.dataset.send}`)); sendPayslipDialog(ctx, p, () => paintTab()); });

  await loadRun();
  if (route.stale()) return;
  if (!st.tab || !tabDefs().some((t) => t.id === st.tab)) st.tab = defaultTab();
  frame();
  await paintTab();
}
