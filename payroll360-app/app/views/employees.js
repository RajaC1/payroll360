// Team: employee list, guided "Add employee" wizard and CSV import.
import { html, raw, on, debounce, pageHeader, table, badge, emptyState, errorState, skeleton, field, readForm, showFieldErrors, clearFieldErrors, formAlert, withBusy,
  openModal, toast, titleCase, fmtDate, icon, saveBlob, readFileText, money, moneyText, esc } from '../ui.js';

// ------------------------------------------------------------------------------------------------ list
export async function render(ctx, route) {
  const m = ctx.meta;
  const st = { q: route.query.q || '', status: '', department_id: '', country: '', entity_id: '', employment_type: '', sort: 'name', dir: 'asc', offset: 0, limit: 25 };
  const canWrite = ctx.can('employees.write');
  const opt = (arr, get) => arr.map((x) => get(x));
  route.paint(html`
    ${pageHeader({
      title: 'Team', sub: 'Everyone on your payroll, with their employment and pay setup.',
      actions: canWrite ? html`<button class="btn btn-outline" id="btn-import">${icon('download')} Import CSV</button><button class="btn btn-primary" id="btn-add">${icon('plus')} Add employee</button>` : '',
    })}
    <div class="card">
      <div class="toolbar collapsed" id="emp-toolbar">
        <div class="search"><span>${icon('search')}</span><input class="form-control" id="emp-q" type="search" placeholder="Search name, ID or email" aria-label="Search employees" value="${st.q}"></div>
        <button class="btn btn-outline btn-sm filters-toggle" id="emp-filters-toggle" type="button">Filters</button>
        <select class="form-select filter-item" data-f="status" aria-label="Status"><option value="">All statuses</option>${opt(m.employment_statuses, (s) => html`<option value="${s}">${titleCase(s)}</option>`)}</select>
        <select class="form-select filter-item" data-f="department_id" aria-label="Department"><option value="">All departments</option>${opt(m.departments, (d) => html`<option value="${d.id}">${d.name}</option>`)}</select>
        <select class="form-select filter-item" data-f="country" aria-label="Country"><option value="">All countries</option>${opt(m.countries, (c) => html`<option value="${c.code}">${c.name}</option>`)}</select>
        ${m.entities.length ? html`<select class="form-select filter-item" data-f="entity_id" aria-label="Legal entity"><option value="">All entities</option>${opt(m.entities, (e) => html`<option value="${e.id}">${e.name}</option>`)}</select>` : ''}
        <select class="form-select filter-item" data-f="employment_type" aria-label="Employment type"><option value="">All types</option>${opt(m.employment_types, (t) => html`<option value="${t}">${titleCase(t)}</option>`)}</select>
      </div>
      <div id="emp-results">${skeleton(6)}</div>
    </div>`);
  const results = route.view.querySelector('#emp-results');

  const load = async () => {
    results.innerHTML = '';
    results.append(...skeletonNodes());
    try {
      const r = await ctx.api.get('/employees', { q: st.q, status: st.status, department_id: st.department_id, country: st.country, entity_id: st.entity_id, employment_type: st.employment_type, sort: st.sort, dir: st.dir, limit: st.limit, offset: st.offset });
      if (route.stale()) return;
      const from = r.total ? st.offset + 1 : 0;
      const to = Math.min(st.offset + st.limit, r.total);
      results.innerHTML = '';
      results.insertAdjacentHTML('beforeend', (r.items.length ? html`${table({
        sortState: { key: st.sort, dir: st.dir },
        rowAttrs: (e) => `class="clickable" data-href="#/employees/${e.id}" tabindex="0"`,
        columns: [
          { label: 'Employee', sort: 'name', render: (e) => html`<div class="cell-main">${e.full_name}</div><div class="cell-sub">${e.job_title || e.work_email}</div>` },
          { label: 'ID', sort: 'employee_no', render: (e) => html`<code>${e.employee_no}</code>` },
          { label: 'Department', sort: 'department', render: (e) => e.department || '-' },
          { label: 'Country', render: (e) => e.country },
          { label: 'Type', render: (e) => titleCase(e.employment_type) },
          { label: 'Pay frequency', render: (e) => (e.payroll_frequency ? titleCase(e.payroll_frequency) : '-') },
          { label: 'Status', sort: 'status', render: (e) => badge(e.employment_status) },
          { label: 'Joined', sort: 'joining_date', render: (e) => fmtDate(e.joining_date) },
        ], rows: r.items })}
        <div class="pager"><span>Showing ${from}-${to} of ${r.total}</span><span><button class="btn btn-outline btn-sm" data-page="prev" ${st.offset === 0 ? raw('disabled') : ''}>Previous</button> <button class="btn btn-outline btn-sm" data-page="next" ${to >= r.total ? raw('disabled') : ''}>Next</button></span></div>`
        : (st.q || st.status || st.department_id || st.country || st.entity_id || st.employment_type
          ? emptyState({ title: 'No employees match your filters.', text: 'Try a different search or clear the filters.', iconName: 'search' })
          : emptyState({ title: 'No employees have been added yet.', text: canWrite ? 'Add your first employee to start setting up payroll.' : 'Employees will appear here once they are added.', actionLabel: canWrite ? 'Add employee' : '', actionAttr: 'data-add="1"', iconName: 'users' }))).toString());
    } catch (err) {
      if (route.stale()) return;
      results.innerHTML = errorState(err).toString();
      results.querySelector('[data-action="retry"]')?.addEventListener('click', load);
    }
  };
  function skeletonNodes() { const d = document.createElement('div'); d.innerHTML = skeleton(6).toString(); return [d.firstElementChild]; }

  route.view.querySelector('#emp-q').addEventListener('input', debounce((e) => { st.q = e.target.value.trim(); st.offset = 0; load(); }, 300));
  route.view.querySelectorAll('[data-f]').forEach((sel) => sel.addEventListener('change', () => { st[sel.dataset.f] = sel.value; st.offset = 0; load(); }));
  route.view.querySelector('#emp-filters-toggle')?.addEventListener('click', () => route.view.querySelector('#emp-toolbar').classList.toggle('collapsed'));
  on(results, 'click', '[data-sort]', (e, b) => { st.dir = st.sort === b.dataset.sort && st.dir === 'asc' ? 'desc' : 'asc'; st.sort = b.dataset.sort; st.offset = 0; load(); });
  on(results, 'click', '[data-page]', (e, b) => { st.offset = Math.max(0, st.offset + (b.dataset.page === 'next' ? st.limit : -st.limit)); load(); });
  on(results, 'click', 'tr[data-href]', (e, tr) => { if (!e.target.closest('a, button')) ctx.navigate(tr.dataset.href); });
  on(results, 'keydown', 'tr[data-href]', (e, tr) => { if (e.key === 'Enter') ctx.navigate(tr.dataset.href); });
  on(results, 'click', '[data-add]', () => openEmployeeWizard(ctx, load));
  route.view.querySelector('#btn-add')?.addEventListener('click', () => openEmployeeWizard(ctx, load));
  route.view.querySelector('#btn-import')?.addEventListener('click', () => openImport(ctx, load));
  await load();
}

// ------------------------------------------------------------------------------------------------ wizard
const STEPS = ['Basic information', 'Employment', 'Location', 'Compensation', 'Allowances', 'Benefits', 'Deductions', 'Tax and payment', 'Review'];
const FIELD_STEP = {
  first_name: 0, middle_name: 0, last_name: 0, work_email: 0, personal_email: 0, phone: 0, employee_no: 0,
  entity_id: 1, department_id: 1, manager_id: 1, job_title: 1, employment_type: 1, employment_status: 1, joining_date: 1, probation_end: 1, termination_date: 1, work_location: 1,
  country: 2, state_region: 2, currency: 2, tax_jurisdiction: 2,
  pay_basis: 3, base: 3, effective_from: 3, overtime_multiplier_pct: 3, payroll_frequency: 3, schedule_id: 3, components: 4, tax_id: 7, bank_name: 7, bank_account: 7,
};
const KIND_STEP = { earning: 4, benefit: 5, deduction: 6 };
const KIND_TEXT = {
  earning: { title: 'Allowances and other earnings', help: 'Recurring or one-off pay on top of base salary, for example housing, transport or a signing bonus. Skip this step if there are none.', add: 'Add allowance' },
  benefit: { title: 'Benefits', help: 'Health, dental, retirement and similar plans. Employee contributions reduce net pay; employer contributions are an extra cost to the company.', add: 'Add benefit' },
  deduction: { title: 'Deductions', help: 'Amounts taken from pay, such as retirement savings or loan repayments. Taxes are calculated automatically from the tax rules for the employee\'s location.', add: 'Add deduction' },
};

export async function openEmployeeWizard(ctx, onDone) {
  const meta = ctx.meta;
  if (!meta.entities.length) {
    toast('Create a legal entity in Settings > Company before adding employees.', 'warn');
    return;
  }
  let managers = [];
  try { managers = (await ctx.api.get('/employees', { limit: 200, sort: 'name' })).items; } catch { /* manager list is optional */ }
  const data = { employment_status: 'ACTIVE', employment_type: 'FULL_TIME', pay_basis: 'annual', overtime_multiplier_pct: '150', payroll_frequency: 'monthly', components: [] };
  let step = 0;
  const m = openModal({ title: 'Add employee', subtitle: 'Set up payroll information in stages. Nothing is saved until you confirm on the last step.', size: 'lg', dismissible: false });
  const entity = () => meta.entities.find((e) => String(e.id) === String(data.entity_id));
  const countryCur = (c) => meta.countries.find((x) => x.code === c)?.currency || '';
  const currency = () => data.currency || countryCur(data.country) || entity()?.currency || 'USD';

  const readStep = () => {
    const f = m.body.querySelector('#wz-form');
    if (f) Object.assign(data, readForm(f));
  };
  const stepBar = () => html`<div class="wizard-steps" aria-label="Progress">${STEPS.map((s, i) => html`<span class="ws ${i === step ? 'active' : i < step ? 'done' : ''}"><b>${i < step ? raw('&#10003;') : i + 1}</b>${s}</span>`)}</div>`;

  const assignStep = (kind) => {
    const tx = KIND_TEXT[kind];
    const comps = meta.components.filter((c) => c.kind === kind && c.status === 'active');
    const items = data.components.filter((c) => c._kind === kind);
    return html`<h4 class="section-title">${tx.title}</h4><p class="page-sub" style="margin-bottom:12px">${tx.help}</p>
      ${items.length ? html`<div class="p-table-wrap"><table class="sp-table p-table"><thead><tr><th>Name</th><th>Employee</th>${kind === 'benefit' ? html`<th>Employer</th>` : ''}<th>Frequency</th><th>From</th><th></th></tr></thead><tbody>
        ${items.map((c) => html`<tr><td data-label="Name">${c._label}</td><td data-label="Employee">${c.amount ? moneyText(c.amount, currency()) : c.rate_pct ? c.rate_pct + '%' : '-'}</td>${kind === 'benefit' ? html`<td data-label="Employer">${c.employer_amount ? moneyText(c.employer_amount, currency()) : c.employer_rate_pct ? c.employer_rate_pct + '%' : '-'}</td>` : ''}<td data-label="Frequency">${titleCase(c.frequency)}</td><td data-label="From">${fmtDate(c.effective_from)}</td><td><button type="button" class="btn btn-outline btn-sm" data-remove="${data.components.indexOf(c)}">Remove</button></td></tr>`)}</tbody></table></div>` : ''}
      <form id="as-form" class="card card-pad" style="margin-top:12px" novalidate>
        <div class="form-grid">${field({ name: 'component_id', label: kind === 'earning' ? 'Allowance / earning' : kind === 'benefit' ? 'Benefit' : 'Deduction', type: 'select', options: comps.map((c) => ({ value: c.id, label: c.name })), blank: 'Choose...', wide: true })}</div>
        <div id="as-fields"></div>
        <button type="button" class="btn btn-outline" id="as-add">${icon('plus')} ${tx.add}</button></form>`;
  };
  const assignFields = (kind, comp) => {
    if (!comp) return html`<p class="field-help">Choose an item to enter its amount.</p>`;
    const cur = currency();
    const pct = comp.calc === 'percent';
    return html`<div class="form-grid">
      ${pct ? field({ name: 'rate_pct', label: kind === 'benefit' ? 'Employee percentage' : 'Percentage', type: 'percent', value: comp.default_rate_pct ?? '', help: 'Of ' + (comp.basis === 'base' ? 'base pay' : comp.basis === 'gross' ? 'gross pay' : 'taxable gross pay') })
        : field({ name: 'amount', label: kind === 'benefit' ? `Employee contribution (${cur})` : `Amount (${cur})`, type: 'money' })}
      ${kind === 'benefit' ? (pct ? field({ name: 'employer_rate_pct', label: 'Employer percentage', type: 'percent', value: comp.default_employer_rate_pct ?? '' }) : field({ name: 'employer_amount', label: `Employer contribution (${cur})`, type: 'money' })) : ''}
      ${field({ name: 'frequency', label: 'Frequency', type: 'select', options: meta.component_frequencies, value: comp.frequency })}
      ${field({ name: 'effective_from', label: 'Effective date', type: 'date', value: data.joining_date || '' })}
      ${kind === 'benefit' ? field({ name: 'provider', label: 'Provider', value: comp.provider || '' }) : field({ name: 'description', label: 'Description (optional)' })}</div>`;
  };

  const body = () => {
    const cur = currency();
    switch (step) {
      case 0: return html`<form id="wz-form" novalidate class="form-grid">
        ${field({ name: 'first_name', label: 'First name', required: true, value: data.first_name })}${field({ name: 'middle_name', label: 'Middle name', value: data.middle_name })}
        ${field({ name: 'last_name', label: 'Last name', required: true, value: data.last_name })}${field({ name: 'work_email', label: 'Work email', type: 'email', required: true, value: data.work_email })}
        ${field({ name: 'personal_email', label: 'Personal email', type: 'email', value: data.personal_email })}${field({ name: 'phone', label: 'Phone', value: data.phone, attrs: { autocomplete: 'off' } })}
        ${field({ name: 'employee_no', label: 'Employee ID', value: data.employee_no, help: 'Leave blank to generate one automatically.' })}</form>`;
      case 1: return html`<form id="wz-form" novalidate class="form-grid">
        ${field({ name: 'entity_id', label: 'Legal entity', type: 'select', required: true, options: meta.entities.map((e) => ({ value: e.id, label: e.name })), blank: 'Choose...', value: data.entity_id })}
        ${field({ name: 'department_id', label: 'Department', type: 'select', options: meta.departments.map((d) => ({ value: d.id, label: d.name })), blank: 'No department', value: data.department_id })}
        ${field({ name: 'job_title', label: 'Job title', value: data.job_title })}
        ${field({ name: 'manager_id', label: 'Manager', type: 'select', options: managers.map((x) => ({ value: x.id, label: `${x.full_name} (${x.employee_no})` })), blank: 'No manager', value: data.manager_id })}
        ${field({ name: 'employment_type', label: 'Employment type', type: 'select', required: true, options: meta.employment_types, value: data.employment_type })}
        ${field({ name: 'employment_status', label: 'Employment status', type: 'select', options: meta.employment_statuses, value: data.employment_status })}
        ${field({ name: 'joining_date', label: 'Joining date', type: 'date', required: true, value: data.joining_date })}
        ${field({ name: 'probation_end', label: 'Probation ends', type: 'date', value: data.probation_end, help: 'Only if a probation period applies.' })}
        ${data.employment_status === 'TERMINATED' ? field({ name: 'termination_date', label: 'Termination date', type: 'date', required: true, value: data.termination_date }) : ''}
        ${field({ name: 'work_location', label: 'Work location', value: data.work_location, wide: true })}</form>`;
      case 2: return html`<form id="wz-form" novalidate class="form-grid">
        <p class="page-sub span-2" style="margin-bottom:10px">Payroll rules depend on where the employee works. The country decides the currency and which tax rules apply.</p>
        ${field({ name: 'country', label: 'Country', type: 'select', required: true, options: meta.countries.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })), blank: 'Choose...', value: data.country })}
        ${data.country === 'US' ? field({ name: 'state_region', label: 'State', type: 'select', options: meta.us_states, blank: 'Choose...', value: data.state_region }) : field({ name: 'state_region', label: 'State / province / region', value: data.state_region })}
        ${field({ name: 'currency', label: 'Pay currency', type: 'select', options: meta.currencies, value: data.currency || countryCur(data.country) })}
        ${field({ name: 'tax_jurisdiction', label: 'Tax jurisdiction', value: data.tax_jurisdiction, placeholder: data.country ? data.country + (data.state_region ? '-' + data.state_region : '') : 'Set automatically', help: 'Leave blank to use country and state.' })}</form>`;
      case 3: {
        const sched = ctx.meta.schedules.filter((s) => s.status === 'active' && String(s.entity_id) === String(data.entity_id) && s.frequency === data.payroll_frequency && s.currency === cur);
        return html`<form id="wz-form" novalidate class="form-grid">
          ${field({ name: 'pay_basis', label: 'Salary type', type: 'select', required: true, options: meta.pay_bases, value: data.pay_basis })}
          ${field({ name: 'base', label: `${data.pay_basis === 'hourly' ? 'Hourly rate' : 'Base salary'} (${cur})`, type: 'money', required: true, value: data.base, help: 'Per ' + (data.pay_basis === 'annual' ? 'year' : data.pay_basis === 'hourly' ? 'hour' : data.pay_basis.replace('semimonthly', 'half-month').replace('biweekly', 'two weeks')) + '.' })}
          ${field({ name: 'effective_from', label: 'Effective date', type: 'date', required: true, value: data.effective_from || data.joining_date })}
          ${field({ name: 'overtime_multiplier_pct', label: 'Overtime rate (% of hourly)', type: 'percent', value: data.overtime_multiplier_pct, help: '150 means time-and-a-half.' })}
          ${field({ name: 'payroll_frequency', label: 'Payroll frequency', type: 'select', required: true, options: meta.frequencies, value: data.payroll_frequency })}
          ${field({ name: 'schedule_id', label: 'Payroll schedule', type: 'select', options: sched.map((s) => ({ value: s.id, label: s.name })), blank: sched.length ? 'Choose automatically' : 'No matching schedule yet', value: data.schedule_id, help: sched.length ? '' : 'Create a schedule for this entity, frequency and currency to include the employee in payroll runs.' })}</form>`;
      }
      case 4: return assignStep('earning');
      case 5: return assignStep('benefit');
      case 6: return assignStep('deduction');
      case 7: return html`<form id="wz-form" novalidate class="form-grid">
        <p class="page-sub span-2" style="margin-bottom:10px">Tax ID and bank details are stored encrypted and shown masked to most people. Payroll cannot be approved for an employee who is missing them.</p>
        ${field({ name: 'tax_id', label: 'Tax ID', value: data.tax_id, attrs: { autocomplete: 'off' }, help: data.country === 'US' ? 'For example an SSN or ITIN.' : data.country === 'GB' ? 'National Insurance number.' : data.country === 'IN' ? 'PAN.' : '' })}
        ${field({ name: 'bank_name', label: 'Bank name', value: data.bank_name })}${field({ name: 'bank_account', label: 'Account number / IBAN', value: data.bank_account, attrs: { autocomplete: 'off' }, wide: true })}</form>`;
      default: {
        const kv = (k, v) => html`<div><dt>${k}</dt><dd>${v || '-'}</dd></div>`;
        const dept = meta.departments.find((d) => String(d.id) === String(data.department_id))?.name;
        const mgr = managers.find((x) => String(x.id) === String(data.manager_id))?.full_name;
        return html`<div id="wz-review"><div class="banner banner-info"><div class="grow">Review the details, then create the employee. You can change everything later from the employee profile.</div></div>
          <h4 class="section-title">Employee</h4><dl class="kv">${kv('Name', [data.first_name, data.middle_name, data.last_name].filter(Boolean).join(' '))}${kv('Work email', data.work_email)}${kv('Employee ID', data.employee_no || 'Generated automatically')}</dl>
          <h4 class="section-title">Employment</h4><dl class="kv">${kv('Legal entity', entity()?.name)}${kv('Department', dept)}${kv('Job title', data.job_title)}${kv('Manager', mgr)}${kv('Type', titleCase(data.employment_type))}${kv('Status', titleCase(data.employment_status))}${kv('Joined', fmtDate(data.joining_date))}</dl>
          <h4 class="section-title">Location</h4><dl class="kv">${kv('Country', data.country)}${kv('State / region', data.state_region)}${kv('Currency', cur)}${kv('Tax jurisdiction', data.tax_jurisdiction || data.country + (data.state_region ? '-' + data.state_region : ''))}</dl>
          <h4 class="section-title">Pay</h4><dl class="kv">${kv('Salary', data.base ? moneyText(data.base, cur) + ' / ' + data.pay_basis : '')}${kv('Effective', fmtDate(data.effective_from || data.joining_date))}${kv('Payroll frequency', titleCase(data.payroll_frequency))}</dl>
          <h4 class="section-title">Allowances, benefits and deductions</h4>${data.components.length ? html`<ul class="checklist">${data.components.map((c) => html`<li><span class="ok">${icon('check')}</span><span>${c._label} <span class="muted small">(${titleCase(c._kind)}${c.amount ? ', ' + moneyText(c.amount, cur) : c.rate_pct ? ', ' + c.rate_pct + '%' : ''})</span></span></li>`)}</ul>` : html`<p class="muted">None added.</p>`}
          <h4 class="section-title">Tax and payment</h4><dl class="kv">${kv('Tax ID', data.tax_id ? 'Provided' : 'Not provided')}${kv('Bank', data.bank_name)}${kv('Account', data.bank_account ? 'Provided' : 'Not provided')}</dl></div>`;
      }
    }
  };
  const paint = () => {
    m.setBody(html`${stepBar()}<div id="wz-alert"></div>${body()}`);
    const last = step === STEPS.length - 1;
    m.setFooter(html`<button type="button" class="btn btn-outline" data-x="cancel">Cancel</button><span style="flex:1"></span>${step > 0 ? html`<button type="button" class="btn btn-outline" data-x="back">Back</button>` : ''}
      ${last ? html`<button type="button" class="btn btn-primary" data-x="create">Create employee</button>` : html`<button type="button" class="btn btn-primary" data-x="next">${step >= 4 && step <= 6 ? 'Continue' : 'Next'}</button>`}`);
    if (step >= 4 && step <= 6) {
      const kind = ['earning', 'benefit', 'deduction'][step - 4];
      const sel = m.body.querySelector('[name=component_id]');
      const slot = m.body.querySelector('#as-fields');
      const fill = () => { const c = meta.components.find((x) => String(x.id) === String(sel.value)); slot.innerHTML = assignFields(kind, c).toString(); };
      sel.addEventListener('change', fill);
      fill();
    }
  };

  const requireFields = (names) => {
    const form = m.body.querySelector('#wz-form');
    const errs = {};
    names.forEach((n) => { if (!String(data[n] ?? '').trim()) errs[n] = 'This field is required.'; });
    if (Object.keys(errs).length) { showFieldErrors(form, errs); return false; }
    return true;
  };
  const validate = () => {
    if (step === 0) return requireFields(['first_name', 'last_name', 'work_email']);
    if (step === 1) return requireFields(['entity_id', 'employment_type', 'joining_date'] .concat(data.employment_status === 'TERMINATED' ? ['termination_date'] : []));
    if (step === 2) return requireFields(['country']);
    if (step === 3) return requireFields(['pay_basis', 'base', 'payroll_frequency']);
    return true;
  };
  const submit = async (btn) => {
    const payload = {};
    for (const k of ['first_name', 'middle_name', 'last_name', 'work_email', 'personal_email', 'phone', 'employee_no', 'entity_id', 'department_id', 'manager_id', 'job_title', 'employment_type',
      'employment_status', 'joining_date', 'probation_end', 'termination_date', 'work_location', 'country', 'state_region', 'tax_jurisdiction', 'payroll_frequency', 'schedule_id', 'tax_id', 'bank_name', 'bank_account']) {
      if (data[k] !== undefined && data[k] !== '') payload[k] = data[k];
    }
    payload.currency = currency();
    payload.compensation = { pay_basis: data.pay_basis, base: data.base, effective_from: data.effective_from || data.joining_date, overtime_multiplier_pct: data.overtime_multiplier_pct || '150' };
    payload.components = data.components.map(({ _label, _kind, ...c }) => Object.fromEntries(Object.entries(c).filter(([, v]) => v !== '')));
    await withBusy(btn, 'Creating employee...', async () => {
      try {
        const emp = await ctx.api.post('/employees', payload);
        toast(`${emp.full_name} was added.`);
        m.close();
        onDone?.();
        ctx.navigate(`#/employees/${emp.id}`);
      } catch (err) {
        const keys = Object.keys(err.fields || {});
        if (keys.length) {
          step = Math.min(...keys.map((k) => FIELD_STEP[k] ?? 8));
          paint();
          const form = m.body.querySelector('#wz-form');
          const orphan = form ? showFieldErrors(form, err.fields) : keys.map((k) => err.fields[k]);
          m.body.querySelector('#wz-alert').innerHTML = html`<div class="form-alert">${err.message} ${orphan.join(' ')}</div>`.toString();
        } else {
          m.body.querySelector('#wz-alert').innerHTML = html`<div class="form-alert">${err.message}</div>`.toString();
        }
      }
    });
  };

  m.foot.addEventListener('click', (e) => {
    const b = e.target.closest('[data-x]');
    if (!b) return;
    const a = b.dataset.x;
    if (a === 'cancel') return m.close();
    if (a === 'back') { readStep(); step -= 1; return paint(); }
    if (a === 'next') { readStep(); if (!validate()) return; step += 1; return paint(); }
    if (a === 'create') return submit(b);
  });
  m.body.addEventListener('change', (e) => {
    if (e.target.name === 'country') { readStep(); data.currency = countryCur(data.country); data.state_region = ''; paint(); }
    if (e.target.name === 'employment_status' || e.target.name === 'pay_basis' || e.target.name === 'payroll_frequency') { readStep(); paint(); }
    if (e.target.name === 'entity_id') { readStep(); const ent = entity(); if (ent && !data.country) { data.country = ent.country; data.currency = ent.currency; } }
  });
  on(m.body, 'click', '[data-remove]', (e, b) => { data.components.splice(+b.dataset.remove, 1); paint(); });
  on(m.body, 'click', '#as-add', () => {
    const kind = ['earning', 'benefit', 'deduction'][step - 4];
    const form = m.body.querySelector('#as-form');
    clearFieldErrors(form);
    const d = readForm(form);
    const comp = meta.components.find((x) => String(x.id) === String(d.component_id));
    if (!comp) return showFieldErrors(form, { component_id: 'Choose an item.' });
    const pct = comp.calc === 'percent';
    const errs = {};
    if (kind === 'benefit') { if (pct ? !d.rate_pct && !d.employer_rate_pct : !d.amount && !d.employer_amount) errs[pct ? 'rate_pct' : 'amount'] = 'Enter the employee and/or employer contribution.'; }
    else if (pct ? !d.rate_pct : !d.amount) errs[pct ? 'rate_pct' : 'amount'] = pct ? 'Enter a percentage.' : 'Enter an amount.';
    if (!d.effective_from) errs.effective_from = 'Choose a date.';
    if (Object.keys(errs).length) return showFieldErrors(form, errs);
    const item = { component_id: comp.id, _label: comp.name, _kind: kind };
    for (const k of ['amount', 'rate_pct', 'employer_amount', 'employer_rate_pct', 'frequency', 'effective_from', 'provider', 'description']) if (d[k]) item[k] = d[k];
    data.components.push(item);
    paint();
  });
  paint();
}

// ------------------------------------------------------------------------------------------------ CSV import
export function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = '';
  let q = false;
  const s = text.replace(/^﻿/, '');
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (q) {
      if (c === '"') { if (s[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += c;
    } else if (c === '"') q = true;
    else if (c === ',') { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && s[i + 1] === '\n') i++; row.push(cell); cell = ''; if (row.some((x) => x.trim() !== '')) rows.push(row); row = []; }
    else cell += c;
  }
  row.push(cell);
  if (row.some((x) => x.trim() !== '')) rows.push(row);
  return rows;
}
const TEMPLATE = 'first_name,last_name,work_email,entity,department,job_title,employment_type,joining_date,country,state_region,payroll_frequency,pay_basis,base,tax_id,bank_name,bank_account\n'
  + 'Jamie,Rivera,jamie.rivera@example.com,Your Company Inc.,Engineering,Software Engineer,FULL_TIME,2026-01-15,US,CA,monthly,annual,96000.00,DEMO-TAX-9001,Example Bank,DEMO-ACCT-9001\n';

function openImport(ctx, onDone) {
  let rows = [];
  const m = openModal({
    title: 'Import employees from CSV', size: 'lg',
    body: html`<p class="page-sub" style="margin-bottom:12px">Upload a CSV with one employee per row. The first row must be the column names. Rows are validated by the server, and any that fail are listed so you can fix and re-import them.</p>
      <div class="banner banner-info"><div class="grow">Use the exact <b>entity</b> name from Settings > Company. Departments that do not exist yet are created for you.</div><button type="button" class="btn btn-outline btn-sm" data-x="template">${icon('download')} Download template</button></div>
      <div class="form-group"><label class="form-label" for="csv-file">CSV file</label><input type="file" id="csv-file" accept=".csv,text/csv" class="form-control"></div><div id="imp-out"></div>`,
    footer: html`<button type="button" class="btn btn-outline" data-x="cancel">Close</button><button type="button" class="btn btn-primary" data-x="import" disabled>Import</button>`,
  });
  const out = m.body.querySelector('#imp-out');
  m.body.addEventListener('click', (e) => { if (e.target.closest('[data-x=template]')) saveBlob(new Blob([TEMPLATE], { type: 'text/csv' }), 'payslip360-employee-import-template.csv'); });
  m.body.querySelector('#csv-file').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
      const grid = parseCsv(await readFileText(file));
      if (grid.length < 2) throw new Error('The file needs a header row and at least one employee.');
      const head = grid[0].map((h) => h.trim().toLowerCase().replace(/[\s-]+/g, '_'));
      rows = grid.slice(1).map((r) => {
        const o = {};
        head.forEach((h, i) => { const v = (r[i] ?? '').trim(); if (v !== '') o[h] = v; });
        if (o.employment_type) o.employment_type = o.employment_type.toUpperCase().replace(/[\s-]+/g, '_');
        if (o.payroll_frequency) o.payroll_frequency = o.payroll_frequency.toLowerCase().replace(/[\s_-]+/g, '');
        if (o.pay_basis) o.pay_basis = o.pay_basis.toLowerCase().replace(/[\s_-]+/g, '');
        if (o.country) o.country = o.country.toUpperCase();
        return o;
      });
      out.innerHTML = html`<p><b>${rows.length}</b> employee${rows.length === 1 ? '' : 's'} found. Preview of the first rows:</p>${table({ columns: [
        { label: 'Name', render: (r) => `${r.first_name || ''} ${r.last_name || ''}` }, { label: 'Email', key: 'work_email' }, { label: 'Entity', key: 'entity' }, { label: 'Country', key: 'country' }, { label: 'Salary', render: (r) => r.base || '-' }], rows: rows.slice(0, 6) })}`.toString();
      m.foot.querySelector('[data-x=import]').disabled = false;
    } catch (err) {
      out.innerHTML = html`<div class="form-alert">${err.message}</div>`.toString();
      m.foot.querySelector('[data-x=import]').disabled = true;
    }
  });
  m.foot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-x]');
    if (!b) return;
    if (b.dataset.x === 'cancel') return m.close();
    if (b.dataset.x !== 'import') return;
    await withBusy(b, 'Importing...', async () => {
      try {
        const r = await ctx.api.post('/employees/import', { rows });
        const failed = r.results.filter((x) => !x.ok);
        out.innerHTML = html`<div class="banner ${r.failed ? 'banner-warn' : 'banner-good'}"><div class="grow"><b>${r.created}</b> employee${r.created === 1 ? '' : 's'} created${r.failed ? html`, <b>${r.failed}</b> could not be imported` : ''}.</div></div>
          ${failed.length ? html`<ul class="exception-list">${failed.map((f) => html`<li><span class="sev-error">${icon('alert')}</span><span>Row ${f.row}: ${String(f.error).replace("entity_id: This field is required.", "The entity name was not found. Use the exact name from Settings > Company.")}</span></li>`)}</ul>` : ''}`.toString();
        b.disabled = true;
        if (r.created) { toast(`${r.created} employee${r.created === 1 ? '' : 's'} imported.`); onDone?.(); }
      } catch (err) {
        out.innerHTML = html`<div class="form-alert">${err.message}</div>`.toString();
      }
    });
  });
}
