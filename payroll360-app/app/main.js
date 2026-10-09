// Payroll360 application shell: session, router, permission-driven navigation.
import { api, session, onUnauthorized } from './api.js';
import { html, raw, mount, on, toast, errorState, skeleton, esc } from './ui.js';
import { wirePromo } from './promos.js';
import * as auth from './views/auth.js';
import * as dashboard from './views/dashboard.js';
import * as employees from './views/employees.js';
import * as employee from './views/employee.js';
import * as payroll from './views/payroll.js';
import * as schedules from './views/schedules.js';
import * as payslips from './views/payslips.js';
import * as reports from './views/reports.js';
import * as settings from './views/settings.js';
import * as admin from './views/admin.js';
import * as portal from './views/portal.js';

const state = { user: null, perms: new Set(), meta: null, health: null };
const appEl = () => document.getElementById('app');

export function can(perm) {
  if (!perm) return true;
  if (Array.isArray(perm)) return perm.some(can);
  return state.perms.has('*') || state.perms.has(perm);
}
const ctx = {
  state, can, api,
  navigate: (hash) => { if (location.hash === hash) handleRoute(); else location.hash = hash; },
  refreshMeta: async () => { state.meta = await api.get('/meta'); },
  get user() { return state.user; },
  get meta() { return state.meta; },
};

// ----------------------------------------------------------------------------------------------- navigation model
const I = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
  users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
  chart: '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="8" y1="17" x2="8" y2="12"/><line x1="12" y1="17" x2="12" y2="8"/><line x1="16" y1="17" x2="16" y2="14"/>',
  admin: '<rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="m9 13 2 2 4-4"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  wallet: '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.2"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/>',
  help: '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
};
const svg = (k) => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[k]}</svg>`;

const NAV_TEAM = [
  { label: 'Dashboard', href: '#/dashboard', icon: 'home', perm: ['payroll.read', 'employees.read', 'team.read'], match: ['/dashboard'] },
  { label: 'Team', href: '#/employees', icon: 'users', perm: ['employees.read', 'team.read'], match: ['/employees'] },
  { group: 'payroll', label: 'Payroll', icon: 'cal', items: [
    { label: 'Payroll runs', href: '#/payrolls', perm: 'payroll.read', match: ['/payrolls'] },
    { label: 'Payroll schedules', href: '#/schedules', perm: 'schedules.read', match: ['/schedules'] },
    { label: 'Payslips', href: '#/payslips', perm: 'payslips.read', match: ['/payslips'] },
  ] },
  { label: 'Reports', href: '#/reports', icon: 'chart', perm: 'reports.read', match: ['/reports'] },
  { group: 'admin', label: 'Administration', icon: 'admin', items: [
    { label: 'Users & roles', href: '#/users', perm: 'users.manage', match: ['/users'] },
    { label: 'Demo leads', href: '#/leads', perm: 'leads.read', match: ['/leads'] },
    { label: 'Audit log', href: '#/audit', perm: 'audit.read', match: ['/audit'] },
  ] },
  { group: 'settings', label: 'Settings', icon: 'gear', items: [
    { label: 'Company', href: '#/settings/company', perm: 'entities.read', match: ['/settings/company'] },
    { label: 'Pay components', href: '#/settings/components', perm: 'components.read', match: ['/settings/components'] },
    { label: 'Tax rules', href: '#/settings/tax', perm: 'taxrules.read', match: ['/settings/tax'] },
    { label: 'Integration', href: '#/settings/integration', perm: 'entities.manage', match: ['/settings/integration'] },
  ] },
];
const NAV_PERSONAL = [
  { label: 'My pay', href: '#/me', icon: 'wallet', match: ['/me'] },
  { label: 'My payrolls', href: '#/my-payrolls', icon: 'file', match: ['/my-payrolls'] },
  { label: 'My profile', href: '#/profile', icon: 'users', match: ['/profile'] },
];
const visibleTeamNav = () => NAV_TEAM.filter((n) => (n.group ? n.items.some((i) => can(i.perm)) : can(n.perm)));
const hasTeam = () => visibleTeamNav().length > 0;
const hasPersonal = () => state.user && state.user.employee_id !== null;

// ----------------------------------------------------------------------------------------------- routes
const ROUTES = [
  { path: '/dashboard', perm: ['payroll.read', 'employees.read', 'team.read'], run: dashboard.render, title: 'Dashboard' },
  { path: '/employees', perm: ['employees.read', 'team.read'], run: employees.render, title: 'Team' },
  { path: '/employees/:id', perm: ['employees.read', 'team.read', '@self'], run: employee.render, title: 'Employee' },
  { path: '/payrolls', perm: 'payroll.read', run: payroll.list, title: 'Payroll runs' },
  { path: '/payrolls/new', perm: 'payroll.run', run: payroll.wizard, title: 'Run payroll' },
  { path: '/payrolls/:id', perm: 'payroll.read', run: payroll.detail, title: 'Payroll' },
  { path: '/schedules', perm: 'schedules.read', run: schedules.render, title: 'Payroll schedules' },
  { path: '/payslips', perm: 'payslips.read', run: payslips.list, title: 'Payslips' },
  { path: '/payslips/:id', perm: null, run: payslips.viewer, title: 'Payslip' },
  { path: '/reports', perm: 'reports.read', run: reports.render, title: 'Reports' },
  { path: '/settings/:tab', perm: null, run: settings.render, title: 'Settings' },
  { path: '/users', perm: 'users.manage', run: admin.users, title: 'Users & roles' },
  { path: '/leads', perm: 'leads.read', run: admin.leads, title: 'Demo leads' },
  { path: '/audit', perm: 'audit.read', run: admin.audit, title: 'Audit log' },
  { path: '/me', perm: '@employee', run: portal.render, title: 'My pay' },
  { path: '/my-payrolls', perm: '@employee', run: portal.payrolls, title: 'My payrolls' },
  { path: '/profile', perm: '@employee', run: portal.profile, title: 'My profile' },
];
function parseHash() {
  const h = location.hash.replace(/^#/, '') || '/';
  const [path, qs] = h.split('?');
  return { path: path || '/', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}
function matchRoute(path) {
  for (const r of ROUTES) {
    const names = [];
    const re = new RegExp('^' + r.path.replace(/:(\w+)/g, (_, n) => { names.push(n); return '([^/]+)'; }) + '$');
    const m = re.exec(path);
    if (m) return { route: r, params: Object.fromEntries(names.map((n, i) => [n, decodeURIComponent(m[i + 1])])) };
  }
  return null;
}
const isPersonalPath = (p) => p === '/me' || p.startsWith('/me/') || p === '/profile' || p === '/my-payrolls';
function allowed(route) {
  const perm = route.perm;
  if (perm === '@employee') return hasPersonal();
  if (Array.isArray(perm) && perm.includes('@self')) return can(perm.filter((x) => x !== '@self')) || hasPersonal();
  if (route.path === '/settings/:tab') return can(['entities.read', 'components.read', 'taxrules.read', 'entities.manage']);
  return can(perm);
}

let renderSeq = 0;
async function handleRoute() {
  if (!state.user) return;
  const { path, query } = parseHash();
  if (path === '/' || path === '') {
    location.replace(hasTeam() ? '#/dashboard' : '#/me');
    return;
  }
  const found = matchRoute(path);
  const main = document.querySelector('.main-viewport');
  if (!main) return;
  const old = main.querySelector('#view');
  const view = document.createElement('div');
  view.id = 'view';
  view.className = 'view';
  old ? old.replaceWith(view) : main.appendChild(view);
  const token = ++renderSeq;
  const stale = () => token !== renderSeq;
  document.body.classList.remove('sidebar-open');
  document.getElementById('app-sidebar')?.classList.remove('open');
  // Changing page (or pressing Back on a phone) closes any open dialog or drawer instead of leaving it on top.
  document.querySelectorAll('#overlay-root .p-close').forEach((b) => b.click());
  main.scrollTop = 0;
  window.scrollTo(0, 0);

  if (!found) {
    mount(view, html`<div class="empty-state"><h3>Page not found</h3><p>That page does not exist.</p><a class="btn btn-primary" href="#/">Go to the start page</a></div>`);
    return;
  }
  document.title = `${found.route.title} | Payroll360`;
  const personal = isPersonalPath(path) || (!hasTeam() && hasPersonal());
  renderNav(path, personal);
  if (!allowed(found.route)) {
    mount(view, html`<div class="empty-state"><h3>You do not have access to this page</h3><p>Ask an administrator if you need it.</p><a class="btn btn-primary" href="#/">Back to the start page</a></div>`);
    return;
  }
  mount(view, skeleton(5));
  const route = { params: found.params, query, view, stale, paint: (c) => { if (!stale()) mount(view, c); } };
  try {
    await found.route.run(ctx, route);
  } catch (err) {
    if (stale()) return;
    console.error(err);
    mount(view, errorState(err));
    view.querySelector('[data-action="retry"]')?.addEventListener('click', () => handleRoute());
  }
}

// ----------------------------------------------------------------------------------------------- shell
function initials(name) { return String(name || '?').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase(); }
const ROLE_LABEL = { ADMIN: 'Administrator', PAYROLL_ADMIN: 'Payroll admin', HR_ADMIN: 'HR admin', MANAGER: 'Manager', EMPLOYEE: 'Employee' };

function renderShell() {
  const u = state.user;
  mount(appEl(), html`
    <header class="top-nav-bar">
      <div class="nav-left">
        <button class="mobile-menu-btn" id="btn-menu" aria-label="Toggle navigation"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <a href="#/" class="brand-logo"><img src="assets/payroll360-logo.png" alt="Payroll360" style="height:68px;width:auto"></a>
      </div>
      <div class="nav-right">
        ${can(['employees.read', 'team.read']) ? html`<div class="nav-search-wrap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="global-search" class="nav-search-input" placeholder="Search employees..." aria-label="Search employees" >
        </div>`  : ''}
        <div class="user-menu">
          <button class="user-chip" id="user-btn" aria-haspopup="menu" aria-expanded="false">
            <div class="user-chip-avatar">${initials(u.name)}</div>
            <div class="user-chip-meta"><div class="user-chip-name-row"><span class="user-chip-name">${u.name}</span><span class="user-chip-role">${ROLE_LABEL[u.role] || u.role}</span></div><span class="user-chip-sub">${u.email}</span></div>
          </button>
          <div class="user-menu-pop hidden" id="user-pop" role="menu">
            <div class="who"><b>${u.name}</b><span>${u.email}</span></div>
            <button role="menuitem" data-act="password">Change password</button>
            <button role="menuitem" data-act="signout">Sign out</button>
          </div>
        </div>
      </div>
    </header>
    <div class="app-layout">
      <aside class="app-sidebar" id="app-sidebar"><div id="switch-slot"></div><nav class="sidebar-nav" id="nav-slot" aria-label="Main navigation"></nav>
        <div id="promo-slot"></div>
        <div class="sidebar-bottom"><div class="m365-status-pill"><span class="m365-dot"></span><span>Payroll360 v2</span></div><a href="https://www.appz360.com/contact.html" target="_blank" rel="noopener noreferrer" class="sidebar-suite-link">Contact us &#8599;</a></div>
      </aside>
      <main class="main-viewport"><div id="view" class="view"></div></main>
    </div>`);

  wirePromo(document.getElementById('promo-slot'));
  const menuBtn = document.getElementById('btn-menu');
  menuBtn.addEventListener('click', () => document.getElementById('app-sidebar').classList.toggle('open'));
  const pop = document.getElementById('user-pop');
  const userBtn = document.getElementById('user-btn');
  userBtn.addEventListener('click', (e) => { e.stopPropagation(); const open = pop.classList.toggle('hidden') === false; userBtn.setAttribute('aria-expanded', String(open)); });
  document.addEventListener('click', (e) => { if (!pop.contains(e.target) && e.target !== userBtn) { pop.classList.add('hidden'); userBtn.setAttribute('aria-expanded', 'false'); } });
  pop.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    pop.classList.add('hidden');
    if (b.dataset.act === 'signout') signOut();
    if (b.dataset.act === 'password') auth.changePasswordDialog();
  });
  document.getElementById('global-search')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.value.trim()) location.hash = '#/employees?q=' + encodeURIComponent(e.target.value.trim());
  });
  on(document.getElementById('nav-slot'), 'click', '[data-group-toggle]', (e, btn) => {
    const group = btn.closest('.nav-group');
    const collapsed = group.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', String(!collapsed));
  });
  on(document.getElementById('switch-slot'), 'click', '[data-switch]', (e, b) => {
    location.hash = b.dataset.switch === 'personal' ? '#/me' : '#/dashboard';
  });
}

function activeFor(path, entry) { return (entry.match || []).some((m) => path === m || path.startsWith(m + '/')); }

function renderNav(path, personal) {
  const slot = document.getElementById('nav-slot');
  const sw = document.getElementById('switch-slot');
  if (!slot) return;
  const team = hasTeam();
  const both = team && hasPersonal();
  mount(sw, both ? html`<div class="view-switch" role="tablist" aria-label="Switch view">
      <button type="button" class="view-switch-btn ${personal ? '' : 'active'}" role="tab" aria-selected="${!personal}" data-switch="team">Team view</button>
      <button type="button" class="view-switch-btn ${personal ? 'active' : ''}" role="tab" aria-selected="${personal}" data-switch="personal">Personal</button></div>` : '');
  const items = personal && hasPersonal() ? NAV_PERSONAL : visibleTeamNav();
  const link = (i, sub = false) => html`<a class="${sub ? 'nav-subitem' : 'nav-item'} ${activeFor(path, i) ? 'active' : ''}" href="${i.href}" ${activeFor(path, i) ? raw('aria-current="page"') : ''}>${sub ? '' : raw(svg(i.icon))}<span>${i.label}</span></a>`;
  mount(slot, [
    items.map((n) => {
      if (!n.group) return link(n);
      const kids = n.items.filter((i) => can(i.perm));
      const open = kids.some((k) => activeFor(path, k));
      // Sub-menus start closed and open only from the arrow, except the group that holds the current page.
      return html`<div class="nav-group ${open ? 'has-active' : 'collapsed'}" id="group-${n.group}">
        <button type="button" class="nav-item nav-group-toggle" data-group-toggle="${n.group}" aria-expanded="${open ? 'true' : 'false'}">${raw(svg(n.icon))}<span>${n.label}</span><svg class="nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div class="nav-sub">${kids.map((k) => link(k, true))}</div></div>`;
    }),
  ]);
}

// The next person to sign in must land on their own start page, not the previous user's last page.
const clearHash = () => history.replaceState(null, '', location.pathname + location.search);

async function signOut() {
  try { await api.post('/auth/logout', {}); } catch { /* token may already be invalid */ }
  session.clear();
  state.user = null;
  clearHash();
  showLogin();
}

function showLogin(message) {
  document.title = 'Sign in | Payroll360';
  auth.renderLogin(appEl(), state.health, boot, message);
}

async function boot() {
  try {
    state.health = await api.get('/health', undefined, { auth: false });
  } catch (err) {
    mount(appEl(), html`<div class="auth-shell"><div class="auth-card">${errorState(err)}</div></div>`);
    appEl().querySelector('[data-action="retry"]')?.addEventListener('click', boot);
    return;
  }
  if (!session.token) return showLogin();
  try {
    const me = await api.get('/auth/me');
    state.user = me.user;
    state.perms = new Set(me.permissions);
    state.meta = await api.get('/meta');
  } catch (err) {
    session.clear();
    return showLogin(err.status === 401 ? undefined : err.message);
  }
  renderShell();
  await handleRoute();
}

onUnauthorized(() => {
  if (!state.user) return;
  session.clear();
  state.user = null;
  clearHash();
  showLogin('Your session has ended. Please sign in again.');
});
window.addEventListener('hashchange', handleRoute);
window.addEventListener('unhandledrejection', (e) => {
  console.error(e.reason);
  if (state.user) toast(e.reason?.message || 'Something went wrong. Please try again.', 'error');
});

// Teams / SharePoint hosts need a handshake, but only when we are actually embedded.
if (window.parent && window.parent !== window) {
  window.addEventListener('load', () => {
    try { window.microsoftTeams?.app?.initialize().then(() => window.microsoftTeams.app.notifySuccess()).catch(() => {}); } catch { /* not in Teams */ }
  });
}

boot();
