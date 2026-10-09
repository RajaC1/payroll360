// Settings: legal entities, pay components, tax rules and integration status.
import { html, raw, on, pageHeader, tabs, table, badge, emptyState, errorState, skeleton, field, readForm, showFieldErrors, clearFieldErrors, formAlert, withBusy, openDrawer, toast,
  titleCase, fmtDate, icon, readFileDataUri, money } from '../ui.js';

export async function render(ctx, route) {
  const all = [
    { id: 'company', label: 'Company', perm: 'entities.read' }, { id: 'components', label: 'Pay components', perm: 'components.read' },
    { id: 'tax', label: 'Tax rules', perm: 'taxrules.read' }, { id: 'integration', label: 'Integration', perm: 'entities.manage' },
  ].filter((t) => ctx.can(t.perm));
  let tab = all.some((t) => t.id === route.params.tab) ? route.params.tab : all[0]?.id;
  if (!tab) { route.paint(emptyState({ title: 'Nothing to configure here.' })); return; }
  route.paint(html`${pageHeader({ title: 'Settings', sub: 'Company details, pay components, tax rules and connections.' })}<div id="st-tabs"></div><div id="st-body"></div>`);
  const body = route.view.querySelector('#st-body');
  const drawTabs = () => { route.view.querySelector('#st-tabs').innerHTML = tabs(all, tab).toString(); };
  const VIEWS = { company, components, tax, integration };
  async function show() {
    drawTabs();
    history.replaceState(null, '', `#/settings/${tab}`);
    body.innerHTML = skeleton(5).toString();
    try { const out = await VIEWS[tab](); if (route.stale()) return; body.innerHTML = out.toString(); body.querySelector('#tax-country')?.addEventListener('change', (e) => { taxCountry = e.target.value; show(); }); }
    catch (err) { if (route.stale()) return; body.innerHTML = errorState(err).toString(); body.querySelector('[data-action="retry"]')?.addEventListener('click', show); }
  }
  on(route.view.querySelector('#st-tabs'), 'click', '[data-tab]', (e, b) => { tab = b.dataset.tab; show(); });

  let cache = {};
  const after = async () => { await ctx.refreshMeta(); await show(); };

  // ------------------------------------------------------------------------------------- company (legal entities)
  async function company() {
    const r = await ctx.api.get('/entities');
    cache.entities = r.items;
    const manage = ctx.can('entities.manage');
    return html`<div class="card"><div class="card-head"><div><h3>Legal entities</h3><p>Each entity has its own name, address, currency, payslip title, logo and email sender. Payslips use these details.</p></div>
        ${manage ? html`<button class="btn btn-primary btn-sm" data-add-entity>${icon('plus')} Add legal entity</button>` : ''}</div>
      ${r.items.length ? table({ columns: [
        { label: 'Entity', render: (e) => html`<div style="display:flex;gap:10px;align-items:center">${e.logo ? html`<img src="${e.logo}" alt="" style="width:36px;height:36px;object-fit:contain;border:1px solid var(--line);border-radius:6px">` : html`<span style="width:36px;height:36px;border:1px dashed var(--line-strong);border-radius:6px;display:inline-block"></span>`}<div><div class="cell-main">${e.name}</div><div class="cell-sub">${e.legal_name || ''}</div></div></div>` },
        { label: 'Country', render: (e) => e.country }, { label: 'Currency', render: (e) => e.currency }, { label: 'Payslip title', render: (e) => e.payslip_title || 'Payslip' },
        { label: 'Email sender', render: (e) => html`${e.sender_name || '-'}<div class="cell-sub">${e.sender_email || ''}</div>` }, { label: 'Status', render: (e) => badge(e.active ? 'active' : 'inactive') },
        ...(manage ? [{ label: '', render: (e) => html`<button class="btn btn-outline btn-sm" data-edit-entity="${e.id}">Edit</button>` }] : []),
      ], rows: r.items }) : emptyState({ title: 'No legal entity has been created yet.', text: manage ? 'Add your company so employees, schedules and payslips can be set up.' : '', actionLabel: manage ? 'Add legal entity' : '', actionAttr: 'data-add-entity', iconName: 'shield' })}</div>`;
  }
  function entityDrawer(e) {
    const ed = !!e;
    const v = e || { country: ctx.meta.countries[0].code, active: true };
    let logo = v.logo || null;
    let logoTouched = false;
    const d = openDrawer({
      title: ed ? 'Edit legal entity' : 'Add legal entity', size: 'md',
      body: html`<form id="ef" novalidate><div class="form-grid">
        ${field({ name: 'name', label: 'Display name', required: true, value: v.name })}${field({ name: 'legal_name', label: 'Legal name (shown on payslips)', value: v.legal_name })}
        ${field({ name: 'country', label: 'Country', type: 'select', required: true, options: ctx.meta.countries.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })), value: v.country })}
        ${field({ name: 'currency', label: 'Currency', type: 'select', options: ctx.meta.currencies, value: v.currency || '', blank: 'Same as the country' })}
        ${field({ name: 'address', label: 'Registered address', type: 'textarea', rows: 2, value: v.address, wide: true })}
        ${field({ name: 'payslip_title', label: 'Payslip title', value: v.payslip_title, placeholder: 'Payslip' })}${ed ? field({ name: 'active', label: 'Active', type: 'checkbox', value: v.active }) : ''}</div>
        <h4 class="section-title">Logo</h4><div style="display:flex;gap:12px;align-items:center;margin-bottom:8px"><div id="logo-prev" style="width:64px;height:64px;border:1px dashed var(--line-strong);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden"></div>
        <div><input type="file" id="logo-file" accept="image/png,image/jpeg"><div class="field-help">PNG or JPEG under 300 KB. It appears on the payslip you view on screen.</div><div class="field-error" data-error-for="logo"></div>
        <button type="button" class="link-btn small" id="logo-clear">Remove logo</button></div></div>
        <h4 class="section-title">Payslip email sender</h4><div class="form-grid">
        ${field({ name: 'sender_name', label: 'Sender name', value: v.sender_name })}${field({ name: 'sender_email', label: 'Sender email', type: 'email', value: v.sender_email })}
        ${field({ name: 'reply_to', label: 'Reply-to', type: 'email', value: v.reply_to })}${field({ name: 'cc', label: 'Copy every payslip to', type: 'email', value: v.cc })}</div>
        <p class="field-help">Emails are sent from the mailbox configured on the server. The name and reply-to above are what employees see.</p></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${ed ? 'Save changes' : 'Add entity'}</button>`,
    });
    const form = d.body.querySelector('#ef');
    const prev = d.body.querySelector('#logo-prev');
    const drawLogo = () => { prev.innerHTML = logo ? `<img src="${logo}" alt="" style="max-width:100%;max-height:100%">` : '<span class="muted small">No logo</span>'; };
    drawLogo();
    d.body.querySelector('#logo-file').addEventListener('change', async (ev) => {
      const f = ev.target.files[0];
      if (!f) return;
      const slot = d.body.querySelector('[data-error-for=logo]');
      slot.textContent = '';
      if (!['image/png', 'image/jpeg'].includes(f.type)) { slot.textContent = 'Choose a PNG or JPEG image.'; return; }
      if (f.size > 300000) { slot.textContent = 'That image is over 300 KB.'; return; }
      logo = await readFileDataUri(f); logoTouched = true; drawLogo();
    });
    d.body.querySelector('#logo-clear').addEventListener('click', () => { logo = null; logoTouched = true; drawLogo(); });
    d.foot.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const body = readForm(form);
      if (logoTouched || !ed) body.logo = logo || '';
      await withBusy(b, 'Saving...', async () => {
        try { if (ed) await ctx.api.put(`/entities/${e.id}`, body); else await ctx.api.post('/entities', body); toast('Legal entity saved.'); d.close(); await after(); }
        catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }

  // ------------------------------------------------------------------------------------- pay components
  async function components() {
    const r = await ctx.api.get('/components');
    cache.components = r.items;
    const manage = ctx.can('components.manage');
    const calcText = (c) => (c.calc === 'percent' ? `Percentage of ${c.basis.replace('_', ' ')}` : 'Fixed amount');
    return html`<div class="card"><div class="card-head"><div><h3>Pay components</h3><p>The catalogue of earnings, deductions and benefits you can assign to employees. Amounts are set per employee.</p></div>
        ${manage ? html`<button class="btn btn-primary btn-sm" data-add-comp>${icon('plus')} Add component</button>` : ''}</div>
      ${table({ columns: [
        { label: 'Component', render: (c) => html`<div class="cell-main">${c.name}</div><div class="cell-sub">${c.code}</div>` }, { label: 'Type', render: (c) => badge(c.kind === 'earning' ? 'blue' : c.kind === 'benefit' ? 'green' : 'amber', titleCase(c.kind)) },
        { label: 'Category', render: (c) => titleCase(c.category || '-') }, { label: 'Calculation', render: (c) => calcText(c) },
        { label: 'Tax treatment', render: (c) => (c.kind === 'earning' ? (c.taxable ? 'Taxable' : 'Non-taxable') : c.pre_tax ? 'Pre-tax' : 'After tax') },
        { label: 'Default frequency', render: (c) => titleCase(c.frequency) }, { label: 'Status', render: (c) => badge(c.status) },
        ...(manage ? [{ label: '', render: (c) => html`<button class="btn btn-outline btn-sm" data-edit-comp="${c.id}">Edit</button>` }] : []),
      ], rows: r.items })}</div>`;
  }
  function componentDrawer(c) {
    const ed = !!c;
    const v = c || { kind: 'earning', calc: 'fixed', basis: 'base', frequency: 'monthly', taxable: true, pre_tax: false, status: 'active' };
    const d = openDrawer({
      title: ed ? `Edit ${c.name}` : 'Add pay component', size: 'md',
      body: html`<form id="cf" novalidate><div class="form-grid">
        ${field({ name: 'name', label: 'Name', required: true, value: v.name })}${field({ name: 'code', label: 'Code', required: true, value: v.code, help: 'Capital letters, digits and underscores.', attrs: ed ? { readonly: true } : {} })}
        ${field({ name: 'kind', label: 'Type', type: 'select', options: ctx.meta.component_kinds, value: v.kind })}${field({ name: 'category', label: 'Category', value: v.category, help: 'For example allowance, bonus, retirement, health.' })}
        ${field({ name: 'calc', label: 'Calculation', type: 'select', options: [{ value: 'fixed', label: 'Fixed amount' }, { value: 'percent', label: 'Percentage' }], value: v.calc })}
        ${field({ name: 'basis', label: 'Percentage of', type: 'select', options: [{ value: 'base', label: 'Base pay' }, { value: 'gross', label: 'Gross pay' }, { value: 'taxable_gross', label: 'Taxable gross pay' }], value: v.basis })}
        ${field({ name: 'frequency', label: 'Default frequency', type: 'select', options: ctx.meta.component_frequencies, value: v.frequency })}${field({ name: 'provider', label: 'Provider', value: v.provider })}
        ${field({ name: 'default_rate_pct', label: 'Default employee %', type: 'percent', value: v.default_rate_pct ?? '' })}${field({ name: 'default_employer_rate_pct', label: 'Default employer %', type: 'percent', value: v.default_employer_rate_pct ?? '' })}
        ${field({ name: 'taxable', label: 'Taxable (earnings)', type: 'checkbox', value: v.taxable })}${field({ name: 'pre_tax', label: 'Pre-tax (deductions and benefits)', type: 'checkbox', value: v.pre_tax })}
        ${ed ? field({ name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], value: v.status }) : ''}</div></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${ed ? 'Save changes' : 'Add component'}</button>`,
    });
    const form = d.body.querySelector('#cf');
    d.foot.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const body = readForm(form);
      if (ed) delete body.code;
      await withBusy(b, 'Saving...', async () => {
        try { if (ed) await ctx.api.put(`/components/${c.id}`, body); else await ctx.api.post('/components', body); toast('Component saved.'); d.close(); await after(); }
        catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }

  // ------------------------------------------------------------------------------------- tax rules
  let taxCountry = '';
  async function tax() {
    const r = await ctx.api.get('/tax-rules', { country: taxCountry });
    cache.rules = r.items;
    const manage = ctx.can('taxrules.manage');
    const unverified = r.items.filter((x) => !x.verified && x.status === 'active').length;
    const summary = (x) => (x.method === 'bracket' ? `${x.brackets.length} bands${x.threshold !== '0.00' ? `, allowance ${x.threshold}` : ''}` : x.method === 'fixed' ? `Fixed ${x.fixed} a year` : `${x.rate_pct}%${x.cap ? `, ceiling ${x.cap}` : ''}`);
    return html`${unverified ? html`<div class="banner banner-warn">${icon('alert', 18)}<div class="grow"><b>${unverified} active rule${unverified === 1 ? ' is' : 's are'} marked "not verified".</b> They are illustrative samples and are not a statement of current law. Review each one against official sources, then mark it verified.</div></div>` : ''}
      <div class="card"><div class="toolbar"><select class="form-select" id="tax-country" aria-label="Country"><option value="">All countries</option>${ctx.meta.countries.map((c) => html`<option value="${c.code}" ${c.code === taxCountry ? raw('selected') : ''}>${c.name}</option>`)}</select><span class="spacer"></span>
        ${manage ? html`<button class="btn btn-primary btn-sm" data-add-rule>${icon('plus')} Add tax rule</button>` : ''}</div>
      ${r.items.length ? table({ columns: [
        { label: 'Rule', render: (x) => html`<div class="cell-main">${x.name}${x.verified ? '' : html`<span class="tag-unverified">Not verified</span>`}</div><div class="cell-sub">${x.code}</div>` },
        { label: 'Location', render: (x) => `${x.country}${x.region ? ' / ' + x.region : ''}` }, { label: 'Type', render: (x) => titleCase(x.tax_type) },
        { label: 'Method', render: (x) => html`${titleCase(x.method)}<div class="cell-sub">${summary(x)}</div>` }, { label: 'Employer', render: (x) => (x.employer_rate_pct !== '0.00' ? x.employer_rate_pct + '%' : '-') },
        { label: 'Effective', render: (x) => `${fmtDate(x.effective_from)}${x.effective_to ? ' to ' + fmtDate(x.effective_to) : ''}` }, { label: 'Status', render: (x) => badge(x.status) },
        ...(manage ? [{ label: '', render: (x) => html`<button class="btn btn-outline btn-sm" data-edit-rule="${x.id}">Edit</button>` }] : []),
      ], rows: r.items }) : emptyState({ title: taxCountry ? `No tax rules for ${taxCountry}.` : 'No tax rules have been configured.', text: 'Payroll cannot be approved for employees in a location without tax rules.', actionLabel: manage ? 'Add tax rule' : '', actionAttr: 'data-add-rule', iconName: 'shield' })}</div>`;
  }
  function taxDrawer(x) {
    const ed = !!x;
    const v = x || { country: 'US', tax_type: 'income_tax', method: 'flat', base_kind: 'taxable_income', cap_scope: 'ytd', currency: 'USD', effective_from: `${new Date().getFullYear()}-01-01`, status: 'active', verified: false, brackets: [{ up_to: '', rate_pct: '' }], threshold: '0.00', employer_rate_pct: '0.00', employer_threshold: '0.00' };
    const bands = (v.brackets && v.brackets.length ? v.brackets : [{ up_to: '', rate_pct: '' }]).map((b) => ({ ...b }));
    const d = openDrawer({
      title: ed ? `Edit ${x.name}` : 'Add tax rule', size: 'lg',
      body: html`<div class="banner banner-info"><div class="grow">Tax amounts are annual figures. Payroll converts them to each pay period. This screen does not check a rule against the law.</div></div><form id="tf" novalidate><div class="form-grid">
        ${field({ name: 'name', label: 'Name', required: true, value: v.name })}${field({ name: 'code', label: 'Code', required: true, value: v.code })}
        ${field({ name: 'country', label: 'Country', type: 'select', options: ctx.meta.countries.map((c) => ({ value: c.code, label: `${c.name} (${c.code})` })), value: v.country })}${field({ name: 'region', label: 'State / region (optional)', value: v.region })}
        ${field({ name: 'tax_type', label: 'Tax type', type: 'select', options: ctx.meta.tax_types, value: v.tax_type })}${field({ name: 'currency', label: 'Currency', type: 'select', options: ctx.meta.currencies, value: v.currency })}
        ${field({ name: 'method', label: 'Method', type: 'select', options: [{ value: 'flat', label: 'Flat rate' }, { value: 'bracket', label: 'Progressive bands' }, { value: 'capped', label: 'Rate up to a ceiling' }, { value: 'fixed', label: 'Fixed annual amount' }], value: v.method })}
        ${field({ name: 'base_kind', label: 'Applied to', type: 'select', options: [{ value: 'taxable_income', label: 'Taxable income (after pre-tax deductions)' }, { value: 'gross_taxable', label: 'Gross taxable pay' }], value: v.base_kind })}
        <div data-m="flat capped">${field({ name: 'rate_pct', label: 'Rate %', type: 'percent', value: v.rate_pct ?? '' })}</div>
        <div data-m="capped">${field({ name: 'cap', label: 'Annual ceiling', type: 'money', value: v.cap ?? '' })}</div>
        <div data-m="capped">${field({ name: 'cap_scope', label: 'Ceiling applies', type: 'select', options: [{ value: 'ytd', label: 'To year-to-date pay' }, { value: 'period', label: 'To each pay period' }], value: v.cap_scope })}</div>
        <div data-m="fixed">${field({ name: 'fixed', label: 'Fixed annual amount', type: 'money', value: v.fixed ?? '' })}</div>
        <div data-m="flat capped bracket">${field({ name: 'threshold', label: 'Annual allowance / threshold', type: 'money', value: v.threshold ?? '0.00' })}</div>
        ${field({ name: 'employer_rate_pct', label: 'Employer rate %', type: 'percent', value: v.employer_rate_pct })}${field({ name: 'employer_threshold', label: 'Employer annual threshold', type: 'money', value: v.employer_threshold })}
        <div data-m="bracket" class="span-2"><label class="form-label">Bands (annual amounts above the allowance)</label><div id="bands"></div><button type="button" class="btn btn-outline btn-sm" id="add-band">${icon('plus')} Add band</button><div class="field-error" data-error-for="brackets"></div></div>
        ${field({ name: 'effective_from', label: 'Effective from', type: 'date', required: true, value: v.effective_from })}${field({ name: 'effective_to', label: 'Effective to', type: 'date', value: v.effective_to })}
        ${field({ name: 'tax_year', label: 'Tax year (optional)', value: v.tax_year ?? '' })}${field({ name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], value: v.status })}
        ${field({ name: 'source_note', label: 'Source / review note', type: 'textarea', rows: 2, value: v.source_note, wide: true })}
        ${field({ name: 'verified', label: 'I have verified this rule against official sources', type: 'checkbox', value: v.verified, wide: true })}</div></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${ed ? 'Save changes' : 'Add rule'}</button>`,
    });
    const form = d.body.querySelector('#tf');
    const drawBands = () => {
      d.body.querySelector('#bands').innerHTML = bands.map((b, i) => `<div class="form-grid" style="margin-bottom:4px"><div class="form-group"><input class="form-control" data-b="${i}" data-k="up_to" placeholder="Up to (blank = no limit)" value="${(b.up_to || '').replace(/"/g, '')}" inputmode="decimal"></div><div class="form-group" style="display:flex;gap:6px"><input class="form-control" data-b="${i}" data-k="rate_pct" placeholder="Rate %" value="${(b.rate_pct || '').replace(/"/g, '')}" inputmode="decimal"><button type="button" class="btn btn-outline btn-sm" data-rm-band="${i}" aria-label="Remove band">&times;</button></div></div>`).join('');
    };
    const sync = () => { const m = form.elements.method.value; form.querySelectorAll('[data-m]').forEach((el) => el.classList.toggle('hidden', !el.dataset.m.split(' ').includes(m))); };
    drawBands(); sync();
    form.elements.method.addEventListener('change', sync);
    d.body.addEventListener('input', (e) => { const el = e.target.closest('[data-b]'); if (el) bands[+el.dataset.b][el.dataset.k] = el.value.trim(); });
    d.body.addEventListener('click', (e) => {
      if (e.target.closest('#add-band')) { bands.push({ up_to: '', rate_pct: '' }); drawBands(); }
      const rm = e.target.closest('[data-rm-band]'); if (rm && bands.length > 1) { bands.splice(+rm.dataset.rmBand, 1); drawBands(); }
    });
    d.foot.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const body = readForm(form);
      body.tax_year = body.tax_year || null;
      if (body.method === 'bracket') body.brackets = bands.filter((x) => x.up_to || x.rate_pct);
      await withBusy(b, 'Saving...', async () => {
        try { if (ed) await ctx.api.put(`/tax-rules/${x.id}`, body); else await ctx.api.post('/tax-rules', body); toast('Tax rule saved.'); d.close(); await show(); }
        catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }

  // ------------------------------------------------------------------------------------- integration
  async function integration() {
    const s = await ctx.api.get('/integration/status');
    const embedded = window.parent && window.parent !== window;
    const card = (name, state, desc, detail) => html`<div class="kpi" style="padding:16px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start"><h3 style="font-size:.95rem">${name}</h3>${badge(state === 'ok' ? 'green' : state === 'warn' ? 'amber' : 'gray', state === 'ok' ? 'Connected' : state === 'warn' ? 'Needs setup' : 'Not connected')}</div><p class="page-sub" style="margin-top:6px">${desc}</p><p style="font-size:.8rem;margin-top:8px;padding-top:8px;border-top:1px dashed var(--line)">${detail}</p></div>`;
    const mailDetail = { file: 'Test mode: emails are saved to a folder on the server and are not delivered.', mail: 'Uses the server mail agent (PHP mail).', smtp: s.mail.configured ? 'Sends through Microsoft 365 (SMTP) with the mailbox from payslip-config.php.' : 'No mailbox is configured. Copy payslip-config.sample.php to payslip-config.php and fill it in.' }[s.mail.transport];
    return html`<div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
      ${card('Email delivery', s.mail.configured ? (s.mail.transport === 'file' ? 'warn' : 'ok') : 'warn', 'Payslips are emailed as a PDF to the employee on record.', mailDetail)}
      ${card('Database', 'ok', 'Employees, payroll, payslips and the audit log are stored on the server.', `${s.entities} legal entit${s.entities === 1 ? 'y' : 'ies'}, ${s.schedules} active schedule${s.schedules === 1 ? '' : 's'}.`)}
      ${card('Tax rules', s.tax_rules.active === 0 ? 'warn' : s.tax_rules.verified === s.tax_rules.active ? 'ok' : 'warn', 'Rules used to calculate taxes and statutory contributions.', `${s.tax_rules.active} active, ${s.tax_rules.verified} marked verified.`)}
      ${card('Microsoft Teams and SharePoint', embedded ? 'ok' : 'off', 'Run Payroll360 as a Teams tab or a SharePoint page.', embedded ? 'Running inside an embedding frame.' : 'Opened on its own. Add it as a Teams tab or SharePoint page to embed it (see config.html).')}
      ${card('Microsoft Entra ID single sign-on', 'off', 'Sign in with your Microsoft 365 account.', 'Not connected. Sign-in currently uses Payroll360 accounts managed under Administration.')}
      ${card('SharePoint document library', 'off', 'Store generated payslips in SharePoint.', 'Not connected. Payslips are stored in the Payroll360 database.')}</div>`;
  }

  function wire() {
    on(body, 'click', '[data-add-entity]', () => entityDrawer());
    on(body, 'click', '[data-edit-entity]', (e, b) => entityDrawer(cache.entities.find((x) => String(x.id) === b.dataset.editEntity)));
    on(body, 'click', '[data-add-comp]', () => componentDrawer());
    on(body, 'click', '[data-edit-comp]', (e, b) => componentDrawer(cache.components.find((x) => String(x.id) === b.dataset.editComp)));
    on(body, 'click', '[data-add-rule]', () => taxDrawer());
    on(body, 'click', '[data-edit-rule]', (e, b) => taxDrawer(cache.rules.find((x) => String(x.id) === b.dataset.editRule)));
  }
  wire();
  await show();
}
