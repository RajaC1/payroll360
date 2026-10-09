// Employee profile: everything about one person's payroll configuration in one place.
import { html, raw, on, pageHeader, tabs, table, badge, kpi, money, moneyText, decAdd, emptyState, errorState, skeleton, field, readForm, showFieldErrors, clearFieldErrors,
  formAlert, withBusy, openModal, openDrawer, confirmDialog, toast, titleCase, fmtDate, fmtDateTime, fmtPeriod, icon, esc } from '../ui.js';

const initials = (n) => String(n).split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase();

export async function render(ctx, route) {
  const id = route.params.id;
  const emp = await ctx.api.get(`/employees/${id}`);
  if (route.stale()) return;
  const level = emp.access_level;
  const full = ['full', 'limited', 'self'].includes(level);
  const canWrite = ctx.can('employees.write');
  const canComp = ctx.can('compensation.read') || level === 'self';
  const canPayslips = ctx.can('payslips.read') || level === 'self';
  const T = [{ id: 'overview', label: 'Overview' }];
  if (full) T.push({ id: 'employment', label: 'Employment' });
  if (canComp) T.push({ id: 'compensation', label: 'Compensation' });
  if (full) T.push({ id: 'earnings', label: 'Earnings' }, { id: 'deductions', label: 'Deductions' }, { id: 'benefits', label: 'Benefits' }, { id: 'tax', label: 'Tax' });
  if (ctx.can('reports.read')) T.push({ id: 'payroll', label: 'Payroll' });
  if (canPayslips) T.push({ id: 'payslips', label: 'Payslips' });
  let tab = T.some((t) => t.id === route.query.tab) ? route.query.tab : 'overview';
  let current = emp;

  const header = () => html`
    ${pageHeader({
      crumbs: [{ label: 'Team', href: '#/employees' }, { label: current.full_name }], title: current.full_name,
      sub: [current.job_title, current.department, current.entity_name].filter(Boolean).join(' - '),
      actions: html`${badge(current.employment_status)}${canWrite ? html`<button class="btn btn-outline" id="btn-edit">${icon('edit')} Edit</button>` : ''}`,
    })}`;

  route.paint(html`${header()}<div id="tabs-slot"></div><div id="tab-body"></div>`);
  const tabsSlot = route.view.querySelector('#tabs-slot');
  const body = route.view.querySelector('#tab-body');
  const drawTabs = () => { tabsSlot.innerHTML = tabs(T, tab).toString(); };

  async function show(t) {
    tab = t;
    drawTabs();
    history.replaceState(null, '', `#/employees/${id}?tab=${t}`);
    body.innerHTML = skeleton(5).toString();
    try {
      const content = await TAB[t]();
      if (route.stale() || tab !== t) return;
      body.innerHTML = content.toString();
      wire(t);
    } catch (err) {
      if (route.stale()) return;
      body.innerHTML = errorState(err).toString();
      body.querySelector('[data-action="retry"]')?.addEventListener('click', () => show(t));
    }
  }
  const reload = async () => { current = await ctx.api.get(`/employees/${id}`); route.view.querySelector('.page-head').outerHTML = header().toString(); bindEdit(); await show(tab); };

  // ------------------------------------------------------------------------------------------ tabs
  const kv = (k, v) => html`<div><dt>${k}</dt><dd>${v === null || v === undefined || v === '' ? '-' : v}</dd></div>`;
  const TAB = {
    async overview() {
      const c = current.current_compensation;
      let taxRules = null;
      if (full && ctx.can('taxrules.read')) {
        try { taxRules = (await ctx.api.get('/tax-rules', { country: current.country })).items.filter((r) => r.status === 'active' && (!r.region || r.region === current.state_region)); } catch { /* optional */ }
      }
      const ready = full ? [
        ['Salary is set', !!c, c ? `${moneyText(c.base, c.currency)} per ${c.pay_basis}` : 'Add compensation in the Compensation tab.'],
        ['Payroll frequency is set', !!current.payroll_frequency, titleCase(current.payroll_frequency)],
        ['On a payroll schedule', !!current.schedule_id, current.schedule_name || 'Choose a schedule in Edit, or create one under Payroll > Payroll schedules.'],
        ['Tax ID provided', current.tax_id_set, current.tax_id_set ? 'Provided' : 'Required before payroll can be approved.'],
        ['Bank details provided', current.bank_set, current.bank_set ? 'Provided' : 'Required before payroll can be approved.'],
        ...(taxRules ? [['Tax rules exist for this location', taxRules.length > 0, taxRules.length ? `${taxRules.length} rule${taxRules.length === 1 ? '' : 's'} apply` : `No tax rules are configured for ${current.country}.`]] : []),
      ] : [];
      return html`${full ? html`<div class="grid-kpi">
          ${kpi('Current pay', c ? money(c.base, c.currency) : raw('<span class="muted">Not set</span>'), c ? `per ${c.pay_basis}, from ${fmtDate(c.effective_from)}` : 'No compensation yet', c ? '' : 'warn')}
          ${kpi('Payroll frequency', titleCase(current.payroll_frequency), current.schedule_name || 'No schedule')}
          ${kpi('Currency', current.currency, `Tax jurisdiction ${current.tax_jurisdiction || current.country}`)}
          ${kpi('Joined', fmtDate(current.joining_date), titleCase(current.employment_type))}</div>` : ''}
        <div class="two-col"><div class="card card-pad"><h3 style="font-size:.98rem;margin-bottom:12px">Details</h3><dl class="kv">
            ${kv('Employee ID', current.employee_no)}${kv('Work email', current.work_email)}${kv('Department', current.department)}${kv('Job title', current.job_title)}
            ${kv('Work location', current.work_location)}${kv('Country', current.country)}${full ? kv('Manager', current.manager_name) : ''}${full ? kv('Legal entity', current.entity_name) : ''}</dl></div>
          ${ready.length ? html`<div class="card card-pad"><h3 style="font-size:.98rem;margin-bottom:8px">Payroll readiness</h3><ul class="checklist">${ready.map(([t, ok, note]) => html`<li>${ok ? html`<span class="ok">${icon('check')}</span>` : html`<span class="bad">${icon('alert')}</span>`}<span><b>${t}</b><br><span class="small muted">${note}</span></span></li>`)}</ul></div>` : ''}</div>`;
    },
    async employment() {
      const e = current;
      return html`<div class="card card-pad"><dl class="kv">
        ${kv('First name', e.first_name)}${kv('Middle name', e.middle_name)}${kv('Last name', e.last_name)}${kv('Work email', e.work_email)}${kv('Personal email', e.personal_email)}${kv('Phone', e.phone)}
        ${kv('Employee ID', e.employee_no)}${kv('Legal entity', e.entity_name)}${kv('Department', e.department)}${kv('Job title', e.job_title)}${kv('Manager', e.manager_name)}
        ${kv('Employment type', titleCase(e.employment_type))}${kv('Status', badge(e.employment_status))}${kv('Joining date', fmtDate(e.joining_date))}${kv('Probation ends', e.probation_end ? fmtDate(e.probation_end) : '')}
        ${kv('Termination date', e.termination_date ? fmtDate(e.termination_date) : '')}${kv('Work location', e.work_location)}${kv('Country', e.country)}${kv('State / region', e.state_region)}
        ${kv('Currency', e.currency)}${kv('Payroll frequency', titleCase(e.payroll_frequency))}${kv('Payroll schedule', e.schedule_name)}</dl></div>`;
    },
    async compensation() {
      const r = await ctx.api.get(`/employees/${id}/compensation`);
      const c = r.current;
      return html`<div class="card card-pad" style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start">
          <div><div class="kpi-label">Current compensation</div><div class="kpi-value" style="font-size:1.6rem">${c ? money(c.base, c.currency) : raw('<span class="muted">Not set</span>')}</div>
            ${c ? html`<p class="page-sub">per ${c.pay_basis === 'semimonthly' ? 'half-month' : c.pay_basis === 'biweekly' ? 'two weeks' : c.pay_basis.replace('annual', 'year').replace('monthly', 'month').replace('weekly', 'week').replace('hourly', 'hour')} &middot; annual equivalent ${moneyText(c.annual_equivalent, c.currency)} &middot; effective ${fmtDate(c.effective_from)}</p>` : html`<p class="page-sub">Payroll cannot be calculated until compensation is set.</p>`}</div>
          ${ctx.can('compensation.write') ? html`<button class="btn btn-primary" id="btn-change-comp">${icon('edit')} ${c ? 'Change compensation' : 'Set compensation'}</button>` : ''}</div>
        <div class="card"><div class="card-head"><div><h3>Compensation history</h3><p>Earlier salaries are kept. A change closes the previous record instead of overwriting it.</p></div></div>
        ${r.history.length ? table({ columns: [
          { label: 'Effective from', render: (h) => fmtDate(h.effective_from) },
          { label: 'Compensation', render: (h) => html`${money(h.base, h.currency)} <span class="muted small">/ ${h.pay_basis}</span>` },
          { label: 'Previous', render: (h) => (h.previous ? html`${money(h.previous.base, h.previous.currency)} <span class="muted small">/ ${h.previous.pay_basis}</span>` : html`<span class="muted">First record</span>`) },
          { label: 'Until', render: (h) => (h.effective_to ? fmtDate(h.effective_to) : badge('active', 'Current')) },
          { label: 'Reason', render: (h) => h.reason || '-' },
          { label: 'Updated by', render: (h) => html`${h.created_by_name || '-'}<div class="cell-sub">${fmtDateTime(h.created_at)}</div>` },
        ], rows: r.history }) : emptyState({ title: 'No compensation has been recorded.', iconName: 'dollar' })}</div>`;
    },
    earnings: () => assignmentsTab('earning'),
    deductions: () => assignmentsTab('deduction'),
    benefits: () => assignmentsTab('benefit'),
    async tax() {
      let rules = null;
      if (ctx.can('taxrules.read')) rules = (await ctx.api.get('/tax-rules', { country: current.country })).items.filter((r) => !r.region || r.region === current.state_region);
      return html`<div class="card card-pad"><dl class="kv">${kv('Country', current.country)}${kv('State / region', current.state_region)}${kv('Tax jurisdiction', current.tax_jurisdiction)}${kv('Tax ID', current.tax_id)}${kv('Currency', current.currency)}</dl></div>
        <div class="card"><div class="card-head"><div><h3>Tax rules that apply</h3><p>Taxes are calculated from these rules when payroll runs. Nothing here is a claim of legal compliance.</p></div></div>
        ${rules === null ? html`<div class="card-pad muted">You do not have permission to view tax rules.</div>` : rules.length ? table({ columns: [
          { label: 'Rule', render: (r) => html`<div class="cell-main">${r.name}${r.verified ? '' : html`<span class="tag-unverified">Not verified</span>`}</div><div class="cell-sub">${r.code}${r.region ? ' - ' + r.region : ''}</div>` },
          { label: 'Type', render: (r) => titleCase(r.tax_type) }, { label: 'Method', render: (r) => titleCase(r.method) },
          { label: 'Effective', render: (r) => `${fmtDate(r.effective_from)}${r.effective_to ? ' to ' + fmtDate(r.effective_to) : ''}` }], rows: rules })
          : emptyState({ title: `No tax rules are configured for ${current.country}.`, text: 'Payroll cannot be approved for this employee until tax rules exist for their location.', iconName: 'shield' })}</div>`;
    },
    async payroll() {
      const r = await ctx.api.get('/reports/employee_history', { employee_id: id });
      return html`<div class="card"><div class="card-head"><div><h3>Payroll history</h3><p>What was actually paid, from finalised payroll runs.</p></div></div>
        ${r.rows.length ? table({ columns: [
          { label: 'Pay period', key: 'period' }, { label: 'Pay date', render: (x) => fmtDate(x.pay_date) },
          { label: 'Gross', align: 'right', render: (x) => money(x.gross, x.currency) }, { label: 'Taxes', align: 'right', render: (x) => money(x.taxes, x.currency) },
          { label: 'Benefits', align: 'right', render: (x) => money(x.benefits, x.currency) }, { label: 'Deductions', align: 'right', render: (x) => money(x.deductions, x.currency) },
          { label: 'Net pay', align: 'right', render: (x) => html`<b>${money(x.net, x.currency)}</b>` }], rows: r.rows })
          : emptyState({ title: 'No payroll has been processed for this employee yet.', iconName: 'calendar' })}</div>`;
    },
    async payslips() {
      const r = await ctx.api.get(`/employees/${id}/payslips`);
      return html`<div class="card"><div class="card-head"><div><h3>Payslips</h3></div></div>
        ${r.items.length ? table({ columns: [
          { label: 'Payslip', render: (p) => html`<a class="cell-main" href="#/payslips/${p.id}">${p.number}</a>` }, { label: 'Pay period', render: (p) => fmtPeriod(p.period_start, p.period_end) },
          { label: 'Pay date', render: (p) => fmtDate(p.pay_date) }, { label: 'Net pay', align: 'right', render: (p) => money(p.net, p.currency) },
          { label: 'Status', render: (p) => badge(p.status) }, { label: '', render: (p) => html`<a class="btn btn-outline btn-sm" href="#/payslips/${p.id}">View</a>` }], rows: r.items })
          : emptyState({ title: 'No payslips are available.', iconName: 'file' })}</div>`;
    },
  };

  async function assignmentsTab(kind) {
    const r = await ctx.api.get(`/employees/${id}/components`);
    const items = r.items.filter((a) => a.component.kind === kind);
    const canAssign = ctx.can('assignments.write');
    const label = { earning: 'allowance or earning', deduction: 'deduction', benefit: 'benefit' }[kind];
    const cur = current.currency;
    const val = (a, side) => {
      const amt = side === 'employer' ? a.employer_amount : a.amount;
      const pct = side === 'employer' ? a.employer_rate_pct : a.rate_pct;
      return amt !== null ? money(amt, cur) : pct !== null ? html`${pct}% <span class="muted small">of ${a.component.basis.replace('_', ' ')}</span>` : html`<span class="muted">-</span>`;
    };
    return html`<div class="card"><div class="card-head"><div><h3>${titleCase(kind === 'earning' ? 'allowances and other earnings' : kind + 's')}</h3><p>${{ earning: 'Extra pay that is added to base salary when payroll is calculated.', deduction: 'Amounts taken from pay. Taxes are separate and come from tax rules.', benefit: 'Plans with an employee and an employer contribution.' }[kind]}</p></div>
        ${canAssign ? html`<button class="btn btn-primary btn-sm" data-add="${kind}">${icon('plus')} Add ${label}</button>` : ''}</div>
      ${items.length ? table({ columns: [
        { label: 'Name', render: (a) => html`<div class="cell-main">${a.component.name}</div><div class="cell-sub">${a.description || a.provider || a.component.code}</div>` },
        { label: kind === 'benefit' ? 'Employee' : 'Amount', render: (a) => val(a, 'employee') },
        ...(kind === 'benefit' ? [{ label: 'Employer', render: (a) => val(a, 'employer') }, { label: 'Total', render: (a) => (a.amount !== null && a.employer_amount !== null ? money(decAdd(a.amount, a.employer_amount), cur) : '-') }] : []),
        { label: 'Frequency', render: (a) => titleCase(a.frequency) },
        { label: 'Effective', render: (a) => `${fmtDate(a.effective_from)}${a.effective_to ? ' to ' + fmtDate(a.effective_to) : ''}` },
        { label: 'Tax treatment', render: (a) => (kind === 'earning' ? (a.taxable ? 'Taxable' : 'Non-taxable') : kind === 'deduction' ? (a.pre_tax ? 'Pre-tax' : 'After tax') : (a.pre_tax ? 'Pre-tax' : 'After tax')) },
        { label: 'Status', render: (a) => badge(a.status) },
        ...(canAssign ? [{ label: '', render: (a) => html`<div class="actions"><button class="btn btn-outline btn-sm" data-edit="${a.id}">Edit</button>${a.status === 'active' ? html`<button class="btn btn-outline btn-sm" data-end="${a.id}">End</button>` : ''}</div>` }] : []),
      ], rows: items })
        : emptyState({ title: `No ${label}s have been set up.`, text: canAssign ? 'Add one to include it in payroll calculations.' : '', iconName: 'inbox' })}</div>`;
  }

  // ------------------------------------------------------------------------------------------ actions
  function wire(t) {
    if (t === 'compensation') body.querySelector('#btn-change-comp')?.addEventListener('click', () => compensationDialog());
  }
  // Delegated handlers on the persistent tab body: attach once, not on every tab switch.
  on(body, 'click', '[data-add]', (e, b) => assignmentDialog(b.dataset.add));
  on(body, 'click', '[data-edit]', async (e, b) => {
    const items = (await ctx.api.get(`/employees/${id}/components`)).items;
    assignmentDialog(null, items.find((x) => String(x.id) === b.dataset.edit));
  });
  on(body, 'click', '[data-end]', async (e, b) => {
    const r = await confirmDialog({ title: 'End this item?', message: 'It stops applying from today. Past payroll is not changed and the record is kept.', confirmText: 'End it' });
    if (!r.ok) return;
    try { await ctx.api.del(`/employee-components/${b.dataset.end}`); toast('Ended.'); show(tab); } catch (err) { toast(err.message, 'error'); }
  });

  function compensationDialog() {
    const c = current.current_compensation;
    const m = openModal({
      title: c ? 'Change compensation' : 'Set compensation', subtitle: c ? 'The current record is closed the day before the new one starts.' : '', size: 'md',
      body: html`<form id="cf" novalidate class="form-grid">${field({ name: 'pay_basis', label: 'Salary type', type: 'select', options: ctx.meta.pay_bases, value: c?.pay_basis || 'annual', required: true })}
        ${field({ name: 'base', label: `${'Amount'} (${current.currency})`, type: 'money', required: true, value: '' , help: c ? `Currently ${c.base} per ${c.pay_basis}.` : ''})}
        ${field({ name: 'effective_from', label: 'Effective date', type: 'date', required: true, value: '' })}
        ${field({ name: 'overtime_multiplier_pct', label: 'Overtime rate (% of hourly)', type: 'percent', value: c?.overtime_multiplier_pct || '150' })}
        ${field({ name: 'reason', label: 'Reason for the change', type: 'textarea', required: true, wide: true, value: c ? '' : 'Initial compensation' })}</form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">Save</button>`,
    });
    const form = m.body.querySelector('#cf');
    m.foot.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return m.close();
      clearFieldErrors(form);
      await withBusy(b, 'Saving...', async () => {
        try {
          await ctx.api.post(`/employees/${id}/compensation`, readForm(form));
          toast('Compensation updated.');
          m.close();
          await reload();
        } catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }
  function assignmentDialog(kind, existing) {
    const editing = !!existing;
    kind = editing ? existing.component.kind : kind;
    const cur = current.currency;
    const comps = ctx.meta.components.filter((c) => c.kind === kind && c.status === 'active');
    const m = openModal({
      title: editing ? `Edit ${existing.component.name}` : `Add ${{ earning: 'allowance or earning', deduction: 'deduction', benefit: 'benefit' }[kind]}`, size: 'md',
      body: html`<form id="af" novalidate>${editing ? '' : html`<div class="form-grid">${field({ name: 'component_id', label: 'Item', type: 'select', required: true, options: comps.map((c) => ({ value: c.id, label: c.name })), blank: 'Choose...', wide: true })}</div>`}<div id="af-fields"></div></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${editing ? 'Save changes' : 'Add'}</button>`,
    });
    const form = m.body.querySelector('#af');
    const slot = m.body.querySelector('#af-fields');
    const compOf = () => (editing ? { ...existing.component, frequency: existing.frequency, provider: existing.provider } : comps.find((c) => String(c.id) === String(form.elements.component_id?.value)));
    const fields = () => {
      const c = compOf();
      if (!c) { slot.innerHTML = html`<p class="field-help">Choose an item to enter its amount.</p>`.toString(); return; }
      const pct = c.calc === 'percent';
      const v = editing ? existing : {};
      slot.innerHTML = html`<div class="form-grid">
        ${pct ? field({ name: 'rate_pct', label: kind === 'benefit' ? 'Employee percentage' : 'Percentage', type: 'percent', value: v.rate_pct ?? c.default_rate_pct ?? '' }) : field({ name: 'amount', label: `${kind === 'benefit' ? 'Employee contribution' : 'Amount'} (${cur})`, type: 'money', value: v.amount ?? '' })}
        ${kind === 'benefit' ? (pct ? field({ name: 'employer_rate_pct', label: 'Employer percentage', type: 'percent', value: v.employer_rate_pct ?? c.default_employer_rate_pct ?? '' }) : field({ name: 'employer_amount', label: `Employer contribution (${cur})`, type: 'money', value: v.employer_amount ?? '' })) : ''}
        ${field({ name: 'frequency', label: 'Frequency', type: 'select', options: ctx.meta.component_frequencies, value: v.frequency || c.frequency })}
        ${field({ name: 'effective_from', label: 'Effective date', type: 'date', required: true, value: v.effective_from || current.joining_date })}
        ${editing ? field({ name: 'effective_to', label: 'Ends on (optional)', type: 'date', value: v.effective_to || '' }) : ''}
        ${kind === 'benefit' ? field({ name: 'provider', label: 'Provider', value: v.provider || c.provider || '' }) : field({ name: 'description', label: 'Description', value: v.description || '' })}
        ${kind !== 'benefit' ? field({ name: 'taxable', label: kind === 'earning' ? 'Tax treatment' : 'Tax treatment', type: 'select', options: kind === 'earning' ? [{ value: '', label: 'Use the default' }, { value: 'true', label: 'Taxable' }, { value: 'false', label: 'Non-taxable' }] : [{ value: '', label: 'Use the default' }, { value: 'true', label: 'Pre-tax (reduces taxable pay)' }, { value: 'false', label: 'After tax' }], value: '' }) : ''}</div>`.toString();
    };
    form.addEventListener('change', (e) => { if (e.target.name === 'component_id') fields(); });
    fields();
    m.foot.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return m.close();
      clearFieldErrors(form);
      const d = readForm(form);
      const body = { ...d };
      if (kind === 'earning' && 'taxable' in body) body.taxable = body.taxable === '' ? null : body.taxable === 'true';
      if (kind === 'deduction' && 'taxable' in body) { body.pre_tax = body.taxable === '' ? null : body.taxable === 'true'; delete body.taxable; }
      await withBusy(b, 'Saving...', async () => {
        try {
          if (editing) await ctx.api.put(`/employee-components/${existing.id}`, body);
          else await ctx.api.post(`/employees/${id}/components`, body);
          toast('Saved.');
          m.close();
          await show(tab);
        } catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }

  function editDrawer() {
    const e = current;
    const sensitive = ctx.can('employees.sensitive');
    const opts = (arr, l) => arr.map((x) => ({ value: x.id ?? x, label: l ? l(x) : titleCase(x) }));
    const d = openDrawer({
      title: 'Edit employee', subtitle: e.full_name, size: 'lg',
      body: html`<form id="ef" novalidate><h4 class="section-title">Basic information</h4><div class="form-grid">
        ${field({ name: 'first_name', label: 'First name', value: e.first_name, required: true })}${field({ name: 'middle_name', label: 'Middle name', value: e.middle_name })}
        ${field({ name: 'last_name', label: 'Last name', value: e.last_name, required: true })}${field({ name: 'employee_no', label: 'Employee ID', value: e.employee_no })}
        ${field({ name: 'work_email', label: 'Work email', type: 'email', value: e.work_email, required: true })}${field({ name: 'personal_email', label: 'Personal email', type: 'email', value: e.personal_email })}
        ${field({ name: 'phone', label: 'Phone', value: e.phone })}</div>
        <h4 class="section-title">Employment</h4><div class="form-grid">
        ${field({ name: 'entity_id', label: 'Legal entity', type: 'select', options: opts(ctx.meta.entities, (x) => x.name), value: e.entity_id })}
        ${field({ name: 'department_id', label: 'Department', type: 'select', options: opts(ctx.meta.departments, (x) => x.name), blank: 'No department', value: e.department_id ?? '' })}
        ${field({ name: 'job_title', label: 'Job title', value: e.job_title })}${field({ name: 'employment_type', label: 'Employment type', type: 'select', options: ctx.meta.employment_types, value: e.employment_type })}
        ${field({ name: 'employment_status', label: 'Status', type: 'select', options: ctx.meta.employment_statuses, value: e.employment_status })}${field({ name: 'joining_date', label: 'Joining date', type: 'date', value: e.joining_date })}
        ${field({ name: 'probation_end', label: 'Probation ends', type: 'date', value: e.probation_end })}${field({ name: 'termination_date', label: 'Termination date', type: 'date', value: e.termination_date })}
        ${field({ name: 'work_location', label: 'Work location', value: e.work_location, wide: true })}</div>
        <h4 class="section-title">Location and payroll</h4><div class="form-grid">
        ${field({ name: 'country', label: 'Country', type: 'select', options: ctx.meta.countries.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })), value: e.country })}${field({ name: 'state_region', label: 'State / region', value: e.state_region })}
        ${field({ name: 'currency', label: 'Pay currency', type: 'select', options: ctx.meta.currencies, value: e.currency, help: 'Cannot change once compensation exists.' })}${field({ name: 'tax_jurisdiction', label: 'Tax jurisdiction', value: e.tax_jurisdiction })}
        ${field({ name: 'payroll_frequency', label: 'Payroll frequency', type: 'select', options: ctx.meta.frequencies, value: e.payroll_frequency })}
        ${field({ name: 'schedule_id', label: 'Payroll schedule', type: 'select', options: opts(ctx.meta.schedules, (x) => `${x.name} (${x.frequency}, ${x.currency})`), blank: 'None', value: e.schedule_id ?? '' })}
        ${field({ name: 'manager_id', label: 'Manager (employee ID number)', type: 'text', value: e.manager_id ?? '', help: 'Leave as is unless you are changing the manager.', wide: true })}</div>
        ${sensitive ? html`<h4 class="section-title">Tax and payment</h4><div class="form-grid">
        ${field({ name: 'tax_id', label: 'Tax ID', value: '', placeholder: e.tax_id || 'Not set', help: 'Leave blank to keep the current value.', attrs: { autocomplete: 'off' } })}
        ${field({ name: 'bank_name', label: 'Bank name', value: e.bank_name })}${field({ name: 'bank_account', label: 'Account number / IBAN', value: '', placeholder: e.bank_account || 'Not set', help: 'Leave blank to keep the current value.', wide: true, attrs: { autocomplete: 'off' } })}</div>` : ''}</form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">Save changes</button>`,
    });
    const form = d.body.querySelector('#ef');
    d.foot.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const vals = readForm(form);
      const body = {};
      for (const [k, v] of Object.entries(vals)) {
        if (['tax_id', 'bank_account'].includes(k)) { if (v) body[k] = v; continue; }
        const before = e[k] === null || e[k] === undefined ? '' : String(e[k]);
        if (v !== before) body[k] = v;
      }
      if (!Object.keys(body).length) return d.close();
      await withBusy(b, 'Saving...', async () => {
        try {
          await ctx.api.put(`/employees/${id}`, body);
          toast('Employee updated.');
          d.close();
          await reload();
        } catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }
  const bindEdit = () => route.view.querySelector('#btn-edit')?.addEventListener('click', editDrawer);

  on(tabsSlot, 'click', '[data-tab]', (e, b) => show(b.dataset.tab));
  bindEdit();
  await show(tab);
}
