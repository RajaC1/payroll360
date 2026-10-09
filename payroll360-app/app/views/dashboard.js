// Dashboard: "What is happening with payroll right now?"
import { html, raw, on, pageHeader, kpi, money, decAdd, badge, stageStepper, table, emptyState, fmtDate, fmtPeriod, icon } from '../ui.js';

const NEXT = {
  DRAFT: 'Confirm employees', PREPARING: 'Confirm employees and calculate', REVIEW: 'Review payroll', APPROVAL: 'Review and approve',
  APPROVED: 'Process payroll', PROCESSING: 'View payroll', PROCESSED: 'View payslips and mark as paid',
};

export async function render(ctx, route) {
  const d = await ctx.api.get('/dashboard');
  const p = d.payroll;
  const e = d.employees;
  const cur = p?.current;
  const canRun = ctx.can('payroll.run');

  const kpis = [];
  if (e) {
    kpis.push(kpi('Total employees', e.total, `${e.by_status.TERMINATED || 0} terminated`));
    kpis.push(kpi('Active employees', e.active, 'Active or on leave'));
  }
  if (p) {
    kpis.push(kpi('Current payroll', cur ? badge(cur.status) : raw('<span class="muted">None</span>'), cur ? cur.name : 'No open payroll'));
    kpis.push(kpi('Pending payroll', p.pending_count, 'Not yet processed', p.pending_count ? 'warn' : ''));
    kpis.push(kpi('Awaiting approval', p.awaiting_approval, 'Submitted for approval', p.awaiting_approval ? 'warn' : ''));
    kpis.push(kpi('Payslips generated', p.payslips_generated, 'All time'));
    if (cur && cur.calculated_at) {
      kpis.push(kpi('Gross payroll', money(cur.gross, cur.currency), cur.name));
      kpis.push(kpi('Total taxes', money(cur.taxes, cur.currency), 'Employee taxes'));
      kpis.push(kpi('Total deductions', money(decAdd(cur.benefits, cur.deductions), cur.currency), 'Benefits and deductions'));
      kpis.push(kpi('Net payroll', money(cur.net, cur.currency), 'To be paid', 'good'));
      kpis.push(kpi('Employer contributions', money(cur.employer_contrib, cur.currency), 'On top of gross pay'));
    }
  }

  const ex = p?.exceptions;
  route.paint(html`
    ${pageHeader({ title: 'Dashboard', sub: 'What is happening with payroll right now.', actions: canRun ? html`<a class="btn btn-primary" href="#/payrolls/new">${icon('plus')} Run payroll</a>` : '' })}

    ${cur ? html`<div class="card card-pad">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px">
          <div><div class="kpi-label">Current payroll</div><h3 style="font-size:1.15rem;margin-top:4px">${cur.name} ${badge(cur.status)}</h3>
            <p class="page-sub">Period ${fmtPeriod(cur.period_start, cur.period_end)} &middot; cut-off ${fmtDate(cur.cutoff_date)} &middot; pay date ${fmtDate(cur.pay_date)} &middot; ${cur.included_count} employee${cur.included_count === 1 ? '' : 's'}</p></div>
          <a class="btn btn-primary" href="#/payrolls/${cur.id}">${NEXT[cur.status] || 'Open payroll'} ${icon('chevron')}</a>
        </div>${stageStepper(cur.stages)}</div>` : ''}

    ${ex && ex.employees_with_errors > 0 ? html`<div class="banner banner-bad" role="alert">${icon('alert', 20)}<div class="grow"><b>${ex.message}</b><br><span class="small">Payroll cannot be approved until these are resolved or the employees are excluded.</span></div><a class="btn btn-outline btn-sm" href="#/payrolls/${cur.id}?tab=exceptions">Review exceptions</a></div>` : ''}
    ${ex && ex.employees_with_errors === 0 && ex.employees_with_warnings > 0 ? html`<div class="banner banner-info">${icon('alert', 18)}<div class="grow">${ex.employees_with_warnings} employee${ex.employees_with_warnings === 1 ? ' has' : 's have'} a note to review (for example prorated final pay).</div><a class="btn btn-outline btn-sm" href="#/payrolls/${cur.id}?tab=exceptions">View</a></div>` : ''}
    ${cur && cur.unverified_tax_lines > 0 ? html`<div class="banner banner-warn">${icon('alert', 18)}<div class="grow">This payroll uses tax rules that are marked <b>not verified</b>. Review your tax configuration before paying real employees.</div>${ctx.can('taxrules.read') ? html`<a class="btn btn-outline btn-sm" href="#/settings/tax">Tax rules</a>` : ''}</div>` : ''}

    ${!cur && p ? html`<div class="card">${emptyState({ title: 'No payroll has been created for this period.', text: canRun ? 'Choose a schedule and pay period to start the next payroll.' : 'A payroll administrator has not started the next payroll yet.', actionLabel: canRun ? 'Run payroll' : '', actionAttr: 'data-go="#/payrolls/new"', iconName: 'calendar' })}</div>` : ''}

    ${kpis.length ? html`<div class="grid-kpi">${kpis}</div>` : ''}

    ${!e && !p ? html`<div class="card">${emptyState({ title: 'Welcome', text: 'Open your team to see the people you manage.', actionLabel: ctx.can('team.read') ? 'Go to Team' : '', actionAttr: 'data-go="#/employees"', iconName: 'users' })}</div>` : ''}

    <div class="${d.setup && p ? 'two-col-wide' : ''}">
      ${p ? html`<div class="card"><div class="card-head"><div><h3>Recent payrolls</h3></div><a class="link-btn" href="#/payrolls">View all</a></div>
        ${p.recent.length ? table({
          columns: [
            { key: 'name', label: 'Payroll', render: (r) => html`<a class="cell-main" href="#/payrolls/${r.id}">${r.name}</a>` },
            { key: 'status', label: 'Status', render: (r) => badge(r.status) },
            { key: 'pay_date', label: 'Pay date', render: (r) => fmtDate(r.pay_date) },
            { key: 'employees', label: 'Employees', align: 'right', render: (r) => r.included_count },
            { key: 'net', label: 'Net pay', align: 'right', render: (r) => (r.calculated_at ? money(r.net, r.currency) : '-') },
          ], rows: p.recent }) : emptyState({ title: 'No payroll has been run yet.', iconName: 'calendar' })}</div>` : ''}
      ${d.setup ? html`<div class="card card-pad"><h3 style="font-size:.98rem;margin-bottom:8px">Payroll readiness</h3>
        <p class="page-sub" style="margin-bottom:8px">Active employees that are missing something payroll needs.</p>
        <ul class="checklist">${[['no_compensation', 'no salary set'], ['no_tax_id', 'no tax ID'], ['no_bank', 'no bank details'], ['no_schedule', 'no payroll schedule']].map(([k, label]) => html`<li>${d.setup[k] === 0 ? html`<span class="ok">${icon('check')}</span>` : html`<span class="bad">${icon('alert')}</span>`}<span><b>${d.setup[k]}</b> employee${d.setup[k] === 1 ? '' : 's'} with ${label}</span></li>`)}</ul>
        <a class="btn btn-outline btn-sm" style="margin-top:10px" href="#/employees">Open team</a></div>` : ''}
    </div>`);
  on(route.view, 'click', '[data-go]', (ev, b) => ctx.navigate(b.dataset.go));
}
