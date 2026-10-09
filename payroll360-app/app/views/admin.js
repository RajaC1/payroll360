// Administration: user accounts and the audit log.
import { html, raw, on, debounce, pageHeader, table, badge, pill, emptyState, errorState, skeleton, field, readForm, showFieldErrors, clearFieldErrors, formAlert, withBusy, openDrawer, toast, titleCase, fmtDateTime, icon, confirmDialog } from '../ui.js';

const ROLE_TEXT = {
  ADMIN: 'Full access, including users and settings.',
  PAYROLL_ADMIN: 'Runs payroll, approves it, manages payslips, pay components, tax rules and schedules.',
  HR_ADMIN: 'Manages employees and compensation. Cannot approve payroll.',
  MANAGER: 'Sees the people who report to them. Cannot see payroll.',
  EMPLOYEE: 'Sees only their own payslips and pay details.',
};

function genPassword() {
  const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const bytes = crypto.getRandomValues(new Uint8Array(14));
  return Array.from(bytes, (b) => abc[b % abc.length]).join('') + '7a';
}

export async function users(ctx, route) {
  const load = async () => (await ctx.api.get('/users')).items;
  let items = await load();
  let employees = [];
  try { employees = (await ctx.api.get('/employees', { limit: 200, sort: 'name' })).items; } catch { /* optional */ }
  if (route.stale()) return;
  const me = ctx.user;

  const paint = () => route.paint(html`${pageHeader({ title: 'Users and roles', sub: 'Who can sign in, and what each role is allowed to do. Permissions are enforced on the server.', actions: html`<button class="btn btn-primary" id="u-new">${icon('plus')} Add user</button>` })}
    <div class="card">${table({ columns: [
      { label: 'User', render: (u) => html`<div class="cell-main">${u.name}</div><div class="cell-sub">${u.email}</div>` },
      { label: 'Role', render: (u) => badge('blue', titleCase(u.role)) },
      { label: 'Linked employee', render: (u) => u.employee_name || '-' },
      { label: 'Last sign-in', render: (u) => (u.last_login_at ? fmtDateTime(u.last_login_at) : 'Never') },
      { label: 'Status', render: (u) => badge(u.active ? 'active' : 'inactive') },
      { label: '', render: (u) => html`<button class="btn btn-outline btn-sm" data-edit="${u.id}">Edit</button>
        ${u.id !== me.id ? html` <button class="btn btn-outline btn-sm" data-del="${u.id}" title="Delete user">${icon('trash')}</button>` : ''}` },
    ], rows: items })}</div>
    <div class="card card-pad" style="margin-top:16px"><h3 style="font-size:.95rem;margin-bottom:8px">What each role can do</h3>
      <dl style="display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0">${ctx.meta.roles.map((r) => html`<dt><b>${titleCase(r)}</b></dt><dd style="margin:0">${ROLE_TEXT[r] || ''}</dd>`)}</dl></div>`);
  paint();

  function editor(u) {
    const ed = !!u;
    const v = u || { role: 'EMPLOYEE', active: true };
    const d = openDrawer({
      title: ed ? `Edit ${u.name}` : 'Add user', size: 'md',
      body: html`<form id="uf" novalidate><div class="form-grid">
        ${field({ name: 'name', label: 'Full name', required: true, value: v.name })}${field({ name: 'email', label: 'Email', type: 'email', required: true, value: v.email, attrs: ed ? { readonly: true } : {} })}
        ${field({ name: 'role', label: 'Role', type: 'select', options: ctx.meta.roles.map((r) => ({ value: r, label: titleCase(r) })), value: v.role, help: ROLE_TEXT[v.role] })}
        ${field({ name: 'employee_id', label: 'Linked employee', type: 'select', blank: 'Not linked', options: employees.map((e) => ({ value: e.id, label: `${e.full_name} (${e.employee_no})` })), value: v.employee_id ?? '', help: 'Required for the Employee role. Managers use it to find their team.' })}
        ${ed ? field({ name: 'active', label: 'Account is active', type: 'checkbox', value: v.active }) : ''}
        ${field({ name: 'password', label: ed ? 'New password (leave blank to keep)' : 'Temporary password', required: !ed, value: '', attrs: { autocomplete: 'new-password' }, help: 'At least 10 characters with a letter and a number.' })}</div>
        <button type="button" class="link-btn" id="gen-pw">Generate a password</button><div id="pw-show" class="field-help"></div></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${ed ? 'Save changes' : 'Add user'}</button>`,
    });
    const form = d.body.querySelector('#uf');
    form.elements.role.addEventListener('change', () => { form.querySelector('[data-field=role] .field-help').textContent = ROLE_TEXT[form.elements.role.value] || ''; });
    d.body.querySelector('#gen-pw').addEventListener('click', () => { const p = genPassword(); form.elements.password.value = p; d.body.querySelector('#pw-show').textContent = `Generated: ${p} - share it securely; it is not shown again.`; });
    d.foot.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const body = readForm(form);
      body.employee_id = body.employee_id ? Number(body.employee_id) : null;
      if (!body.password) delete body.password;
      if (ed) delete body.email;
      await withBusy(b, 'Saving...', async () => {
        try {
          if (ed) await ctx.api.put(`/users/${u.id}`, body); else await ctx.api.post('/users', body);
          toast('User saved.'); d.close(); items = await load(); paint();
        } catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }
  on(route.view, 'click', '#u-new', () => editor());
  on(route.view, 'click', '[data-edit]', (e, b) => editor(items.find((x) => String(x.id) === b.dataset.edit)));
  on(route.view, 'click', '[data-del]', async (e, b) => {
    const u = items.find((x) => String(x.id) === b.dataset.del);
    if (!u) return;
    const r = await confirmDialog({
      title: 'Delete user', danger: true, confirmText: 'Delete',
      message: `Delete ${u.name} (${u.email})? This removes their sign-in permanently. It cannot be undone.`,
      reasonLabel: '',
    });
    if (!r.ok) return;
    await withBusy(b, '', async () => {
      try {
        await ctx.api.del(`/users/${u.id}`);
        toast('User deleted.');
        items = await load(); paint();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });
}

// ------------------------------------------------------------------------------------------------ demo leads (CRM)
// Everyone who has filled in the "Book a demo" form on the website, saved the moment they submit it - before
// Microsoft Bookings is even involved. See api/src/Crm.php.
export async function leads(ctx, route) {
  let rows = [];
  let q = '';
  route.paint(html`${pageHeader({ title: 'Demo leads', sub: 'Everyone who has filled in the "Book a demo" form on the website, and whether they went on to book a time.' })}
    <div class="card"><div class="toolbar collapsed"><div class="search"><span>${icon('search')}</span><input class="form-control" id="ld-q" type="search" placeholder="Search name, email or company" aria-label="Search"></div></div>
      <div id="ld-results">${skeleton(6)}</div></div>`);
  const out = route.view.querySelector('#ld-results');
  const paintRows = () => {
    const term = q.trim().toLowerCase();
    const filtered = term ? rows.filter((r) => [r.name, r.email, r.company].some((v) => (v || '').toLowerCase().includes(term))) : rows;
    out.innerHTML = (filtered.length ? table({ columns: [
      { label: 'Received', render: (r) => fmtDateTime(r.created_at) },
      { label: 'Name', render: (r) => html`<div class="cell-main">${r.name}</div><div class="cell-sub">${r.email}</div>` },
      { label: 'Company', render: (r) => r.company || '-' },
      { label: 'Phone', render: (r) => r.phone || '-' },
      { label: 'Status', render: (r) => (r.status === 'booked' ? pill('Booked', 'green') : pill('New', 'blue')) },
      { label: '', render: (r) => html`<button class="btn btn-outline btn-sm" data-detail="${r.id}">Details</button>` },
    ], rows: filtered }) : emptyState({ title: term ? 'No leads match that search.' : 'No one has filled in the demo form yet.', iconName: 'inbox' })).toString();
  };
  const load = async () => {
    try {
      const r = await ctx.api.get('/crm/leads');
      if (route.stale()) return;
      rows = r.leads;
      paintRows();
    } catch (err) {
      if (route.stale()) return;
      out.innerHTML = errorState(err).toString();
      out.querySelector('[data-action="retry"]')?.addEventListener('click', load);
    }
  };
  route.view.querySelector('#ld-q').addEventListener('input', debounce((e) => { q = e.target.value; paintRows(); }, 200));
  on(out, 'click', '[data-detail]', (e, b) => {
    const r = rows.find((x) => String(x.id) === b.dataset.detail);
    openDrawer({
      title: r.name, size: 'md',
      body: html`<p class="muted small">${fmtDateTime(r.created_at)} &middot; from ${titleCase((r.source || '').replace(/-/g, ' '))}</p>
        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:16px 0">
          <dt><b>Email</b></dt><dd style="margin:0"><a href="mailto:${r.email}">${r.email}</a></dd>
          <dt><b>Company</b></dt><dd style="margin:0">${r.company || '-'}</dd>
          <dt><b>Phone</b></dt><dd style="margin:0">${r.phone || '-'}</dd>
          <dt><b>Status</b></dt><dd style="margin:0">${r.status === 'booked' ? 'Booked a demo' : 'Not yet booked'}</dd>
        </dl>
        <h4 class="section-title">What they told us</h4>
        <pre style="white-space:pre-wrap;word-break:break-word;background:var(--surface-subtle);padding:10px;border-radius:8px;font-size:.85rem;margin:0">${r.message || 'Nothing entered.'}</pre>`,
      footer: '',
    });
  });
  await load();
}

// ------------------------------------------------------------------------------------------------ audit log
export async function audit(ctx, route) {
  const st = { user: '', action: '', entity_type: '', from: '', to: '', offset: 0, limit: 50 };
  let actions = [];
  route.paint(html`${pageHeader({ title: 'Audit log', sub: 'A permanent record of who did what and when. Entries cannot be edited or deleted.' })}
    <div class="card"><div class="toolbar collapsed" id="au-toolbar"><div class="search"><span>${icon('search')}</span><input class="form-control" id="au-user" type="search" placeholder="Filter by user email" aria-label="User"></div>
      <button class="btn btn-outline btn-sm filters-toggle" type="button" id="au-ft">Filters</button>
      <select class="form-select filter-item" data-f="action" id="au-action" aria-label="Action"><option value="">All actions</option></select>
      <select class="form-select filter-item" data-f="entity_type" aria-label="Record type"><option value="">All records</option>${['employee', 'compensation', 'payroll', 'payslip', 'user', 'legal_entity', 'component', 'tax_rule', 'schedule', 'report'].map((t) => html`<option value="${t}">${titleCase(t)}</option>`)}</select>
      <label class="filter-item small muted">From <input class="form-control" type="date" data-f="from"></label><label class="filter-item small muted">To <input class="form-control" type="date" data-f="to"></label></div>
      <div id="au-results">${skeleton(6)}</div></div>`);
  const out = route.view.querySelector('#au-results');
  let rows = [];
  const load = async () => {
    try {
      const r = await ctx.api.get('/audit', { ...st });
      if (route.stale()) return;
      rows = r.items;
      if (r.actions.join() !== actions.join()) {
        actions = r.actions;
        route.view.querySelector('#au-action').innerHTML = `<option value="">All actions</option>${actions.map((a) => `<option value="${a.replace(/"/g, '&quot;')}" ${a === st.action ? 'selected' : ''}>${a.replace(/</g, '&lt;')}</option>`).join('')}`;
      }
      const from = r.total ? st.offset + 1 : 0;
      const to = Math.min(st.offset + st.limit, r.total);
      out.innerHTML = (rows.length ? html`${table({ columns: [
        { label: 'When', render: (a) => fmtDateTime(a.at) }, { label: 'User', render: (a) => a.user_email || 'system' }, { label: 'Action', render: (a) => html`<b>${a.action}</b>` },
        { label: 'Record', render: (a) => `${titleCase(a.entity_type || '-')}${a.entity_id ? ' #' + a.entity_id : ''}` },
        { label: '', render: (a) => (a.before || a.after ? html`<button class="btn btn-outline btn-sm" data-detail="${a.id}">Details</button>` : '') },
      ], rows })}<div class="pager"><span>Showing ${from}-${to} of ${r.total}</span><span><button class="btn btn-outline btn-sm" data-page="prev" ${st.offset === 0 ? raw('disabled') : ''}>Previous</button> <button class="btn btn-outline btn-sm" data-page="next" ${to >= r.total ? raw('disabled') : ''}>Next</button></span></div>`
        : emptyState({ title: 'No audit entries match these filters.', iconName: 'shield' })).toString();
    } catch (err) { if (route.stale()) return; out.innerHTML = errorState(err).toString(); out.querySelector('[data-action="retry"]')?.addEventListener('click', load); }
  };
  route.view.querySelector('#au-user').addEventListener('input', debounce((e) => { st.user = e.target.value.trim(); st.offset = 0; load(); }, 300));
  route.view.querySelectorAll('[data-f]').forEach((el) => el.addEventListener('change', () => { st[el.dataset.f] = el.value; st.offset = 0; load(); }));
  route.view.querySelector('#au-ft').addEventListener('click', () => route.view.querySelector('#au-toolbar').classList.toggle('collapsed'));
  on(out, 'click', '[data-page]', (e, b) => { st.offset = Math.max(0, st.offset + (b.dataset.page === 'next' ? st.limit : -st.limit)); load(); });
  on(out, 'click', '[data-detail]', (e, b) => {
    const a = rows.find((x) => String(x.id) === b.dataset.detail);
    const pre = (o) => html`<pre style="white-space:pre-wrap;word-break:break-word;background:var(--surface-subtle);padding:10px;border-radius:8px;font-size:.78rem;margin:0">${o ? JSON.stringify(o, null, 2) : 'None'}</pre>`;
    openDrawer({ title: a.action, size: 'md', body: html`<p class="muted small">${fmtDateTime(a.at)} by ${a.user_email || 'system'}${a.ip ? ` from ${a.ip}` : ''}</p><h4 class="section-title">Before</h4>${pre(a.before)}<h4 class="section-title">After</h4>${pre(a.after)}`, footer: '' });
  });
  await load();
}
