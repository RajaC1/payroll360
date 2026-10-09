// Sign-in, first-run setup, forgot / reset password, self-service sign-up, and change-password.
import { api, session } from '../api.js';
import { html, raw, mount, field, readForm, showFieldErrors, clearFieldErrors, formAlert, withBusy, toast, openModal } from '../ui.js';

const SETUP_COUNTRIES = [['US', 'United States'], ['GB', 'United Kingdom'], ['DE', 'Germany'], ['IN', 'India'], ['FR', 'France'], ['NL', 'Netherlands'], ['CA', 'Canada'], ['AU', 'Australia'], ['SG', 'Singapore'], ['AE', 'United Arab Emirates']]
  .map(([value, label]) => ({ value, label }));

const AUTH_FEATURES = [
  'Calculate and approve payroll in minutes',
  'Payslips generated and emailed automatically',
  'Role-based access for admins, managers and employees',
  'Free to use - no credit card required',
];
const CHECK_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>';

// The left-hand marketing panel. Same on every sign-in screen - only the form panel on the right changes.
function visualPanel() {
  return html`<div class="auth-visual">
    <div class="av-logo-wrap"><img class="av-logo" src="assets/payroll360-logo.png" alt="Payroll360"></div>
    <div>
      <h2>Payroll, built for Microsoft 365 teams.</h2>
      <p class="av-sub">Run payroll, manage compensation, and give employees self-service access to their payslips - all in one place.</p>
      <ul class="av-features">${AUTH_FEATURES.map((f) => html`<li>${raw(CHECK_SVG)}<span>${f}</span></li>`)}</ul>
    </div>
    <p class="av-foot">Part of the Appz360 family of Microsoft 365 apps.</p>
  </div>`;
}

// Shared card layout for every screen on the sign-in page: a marketing panel on the left, the form on the
// right, both floating over the same looping video that fills the whole page behind them.
function shell(root, { title, lead, body }) {
  mount(root, html`<div class="auth-shell">
    <video class="av-bg" autoplay muted loop playsinline preload="auto" disablepictureinpicture disableremoteplayback>
      <source src="assets/our-home-planet-clean.mp4" type="video/mp4">
    </video>
    <div class="av-shell-overlay"></div>
    <div class="auth-card">
      ${visualPanel()}
      <div class="auth-form-panel">
        <h1>${title}</h1>
        ${lead ? html`<p class="lead">${lead}</p>` : ''}
        ${body}
      </div>
    </div>
  </div>`);
  return root.querySelector('.auth-card');
}

// Switches between the sign-in screens. Every screen uses this for its tabs and "back" links.
function go(root, health, onSuccess, name, message) {
  if (name === 'forgot') return renderForgot(root, health, onSuccess);
  if (name === 'signup') return renderSignup(root, health, onSuccess);
  return renderSignIn(root, health, onSuccess, message);
}

// Entry point. Chooses the screen: first-run setup, a reset link from email, or sign-in.
export function renderLogin(root, health, onSuccess, message) {
  const setup = !!health?.setup_required;
  if (setup) return renderSetup(root, health, onSuccess);
  const resetToken = new URLSearchParams(location.search).get('reset');
  if (resetToken) return renderReset(root, resetToken, health, onSuccess);
  return renderSignIn(root, health, onSuccess, message);
}

function renderSetup(root, health, onSuccess) {
  const card = shell(root, {
    title: 'Set up Payroll360',
    lead: 'Create the first administrator account. You can add your company, employees and tax rules afterwards.',
    body: html`<form id="auth-form" novalidate>
      ${field({ name: 'name', label: 'Your name', required: true, attrs: { autocomplete: 'name' } })}
      ${field({ name: 'email', label: 'Work email', type: 'email', required: true, attrs: { autocomplete: 'username' } })}
      ${field({ name: 'password', label: 'Password', type: 'password', required: true, help: 'At least 10 characters, with a letter and a number.', attrs: { autocomplete: 'new-password' } })}
      ${field({ name: 'company_name', label: 'Company name (optional)', help: 'Creates your first legal entity.' })}
      ${field({ name: 'country', label: 'Company country', type: 'select', options: SETUP_COUNTRIES, blank: 'Select a country' })}
      ${field({ name: 'load_sample_tax_rules', label: 'Load sample tax rules for testing', type: 'checkbox', help: 'These are illustrative and are marked "not verified". Review or replace them before real payroll.' })}
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px">Create administrator</button>
    </form>`,
  });
  const form = card.querySelector('#auth-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    const d = readForm(form);
    const btn = form.querySelector('button[type=submit]');
    if (!d.email || !d.password) {
      showFieldErrors(form, { ...(d.email ? {} : { email: 'Enter your email.' }), ...(d.password ? {} : { password: 'Enter your password.' }) });
      return;
    }
    await withBusy(btn, 'Creating account...', async () => {
      try {
        const body = { email: d.email, name: d.name, password: d.password, company_name: d.company_name, country: d.country || undefined, load_sample_tax_rules: d.load_sample_tax_rules };
        const r = await api.post('/setup', body, { auth: false });
        session.set(r.token);
        await onSuccess();
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        formAlert(form, orphan.length ? orphan.join(' ') : err.message);
      }
    });
  });
  form.querySelector('input')?.focus();
}

function renderSignIn(root, health, onSuccess, message) {
  const card = shell(root, {
    title: 'Sign in',
    lead: 'Use your payroll account to continue.',
    body: html`${message ? html`<div class="banner banner-warn" role="status">${message}</div>` : ''}
      <form id="auth-form" novalidate>
        ${field({ name: 'email', label: 'Work email', type: 'email', required: true, attrs: { autocomplete: 'username' } })}
        ${field({ name: 'password', label: 'Password', type: 'password', required: true, attrs: { autocomplete: 'current-password' } })}
        <p style="text-align:right;margin:-6px 0 16px;font-size:.82rem"><a href="#" data-go="forgot">Forgot password?</a></p>
        <button type="submit" class="btn btn-primary" style="width:100%">Sign in</button>
      </form>
      <p class="muted small" style="margin-top:18px;text-align:center"><a href="#" data-go="signup">Create an account</a></p>`,
  });
  card.addEventListener('click', (e) => {
    const b = e.target.closest('[data-go]');
    if (!b) return;
    e.preventDefault();
    go(root, health, onSuccess, b.dataset.go);
  });
  const form = card.querySelector('#auth-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    const d = readForm(form);
    const btn = form.querySelector('button[type=submit]');
    if (!d.email || !d.password) {
      showFieldErrors(form, { ...(d.email ? {} : { email: 'Enter your email.' }), ...(d.password ? {} : { password: 'Enter your password.' }) });
      return;
    }
    await withBusy(btn, 'Signing in...', async () => {
      try {
        const r = await api.post('/auth/login', { email: d.email, password: d.password }, { auth: false });
        session.set(r.token);
        await onSuccess();
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        formAlert(form, orphan.length ? orphan.join(' ') : err.message);
      }
    });
  });
  form.querySelector('input')?.focus();
}

// Forgot password: ask for the email. The reply is the same whether or not the account exists.
function renderForgot(root, health, onSuccess) {
  const card = shell(root, {
    title: 'Reset your password',
    lead: 'Enter your work email. If an account exists, we will send a link that is valid for 60 minutes.',
    body: html`<form id="forgot-form" novalidate>
        ${field({ name: 'email', label: 'Work email', type: 'email', required: true, attrs: { autocomplete: 'username' } })}
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px">Send reset link</button>
      </form>
      <p class="muted small" style="margin-top:18px;text-align:center"><a href="#" data-go="signin">Back to sign in</a></p>`,
  });
  card.addEventListener('click', (e) => {
    const b = e.target.closest('[data-go]');
    if (!b) return;
    e.preventDefault();
    go(root, health, onSuccess, b.dataset.go);
  });
  const form = card.querySelector('#forgot-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    const d = readForm(form);
    if (!d.email) return showFieldErrors(form, { email: 'Enter your email.' });
    const btn = form.querySelector('button[type=submit]');
    await withBusy(btn, 'Sending...', async () => {
      try {
        const r = await api.post('/auth/forgot', { email: d.email }, { auth: false });
        form.style.display = 'none';
        const note = document.createElement('div');
        note.className = 'banner';
        note.setAttribute('role', 'status');
        note.textContent = r.message;
        form.after(note);
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        formAlert(form, orphan.length ? orphan.join(' ') : err.message);
      }
    });
  });
  form.querySelector('input')?.focus();
}

// Self-service sign-up creates a new company workspace with its first administrator. It only works when the
// server runs in multi-tenant mode; otherwise the form is shown but cannot be submitted.
function slugify(s) {
  return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
}

function renderSignup(root, health, onSuccess) {
  const enabled = !!health?.multitenant;
  const card = shell(root, {
    title: 'Create an administrator account',
    lead: enabled
      ? 'Start a new Payroll360 workspace for your company. You become its first administrator.'
      : 'Self-service sign-up is not turned on for this server.',
    body: html`${enabled ? '' : html`<div class="banner banner-warn" role="status">New companies cannot sign up here yet. Ask an existing administrator to add you under Administration → Users &amp; roles.</div>`}
      <form id="signup-form" novalidate>
        ${field({ name: 'company_name', label: 'Company name', required: true, attrs: { maxlength: 120 } })}
        ${field({ name: 'admin_name', label: 'Your name', required: true, attrs: { autocomplete: 'name', maxlength: 100 } })}
        ${field({ name: 'admin_email', label: 'Work email', type: 'email', required: true, attrs: { autocomplete: 'username', maxlength: 200 } })}
        ${field({ name: 'admin_password', label: 'Password', type: 'password', required: true, help: 'At least 10 characters, with a letter and a number.', attrs: { autocomplete: 'new-password', maxlength: 200 } })}
        <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px" ${enabled ? '' : 'disabled'}>Create my workspace</button>
      </form>
      <p class="muted small" style="margin-top:18px;text-align:center"><a href="#" data-go="signin">Back to sign in</a></p>`,
  });
  card.addEventListener('click', (e) => {
    const b = e.target.closest('[data-go]');
    if (!b) return;
    e.preventDefault();
    go(root, health, onSuccess, b.dataset.go);
  });
  const form = card.querySelector('#signup-form');
  if (!enabled) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    const d = readForm(form);
    const btn = form.querySelector('button[type=submit]');
    await withBusy(btn, 'Creating your workspace...', async () => {
      try {
        const r = await api.post('/signup', {
          company_name: d.company_name,
          slug: slugify(d.company_name),
          admin_name: d.admin_name,
          admin_email: d.admin_email,
          admin_password: d.admin_password,
          website: d.website, // honeypot: real people leave this empty
        }, { auth: false });
        form.style.display = 'none';
        const note = document.createElement('div');
        note.className = 'banner';
        note.setAttribute('role', 'status');
        note.textContent = r.message;
        form.after(note);
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        formAlert(form, orphan.length ? orphan.join(' ') : err.message);
      }
    });
  });
  form.querySelector('input')?.focus();
}

// Step 2: opened from the emailed link (index.html?reset=TOKEN). The server checks the token.
function renderReset(root, token, health, onSuccess) {
  const card = shell(root, {
    title: 'Choose a new password',
    lead: 'Enter a new password for your account.',
    body: html`<form id="reset-form" novalidate>
        ${field({ name: 'new_password', label: 'New password', type: 'password', required: true, help: 'At least 10 characters, with a letter and a number.', attrs: { autocomplete: 'new-password' } })}
        ${field({ name: 'confirm', label: 'Confirm new password', type: 'password', required: true, attrs: { autocomplete: 'new-password' } })}
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px">Set new password</button>
      </form>
      <p class="muted small" style="margin-top:18px;text-align:center"><a href="#" data-go="back">Back to sign in</a></p>`,
  });
  card.addEventListener('click', (e) => {
    const a = e.target.closest('[data-go]');
    if (!a) return;
    e.preventDefault();
    clearResetToken();
    renderSignIn(root, health, onSuccess);
  });
  const form = card.querySelector('#reset-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(form);
    const d = readForm(form);
    if (!d.new_password) return showFieldErrors(form, { new_password: 'Enter a new password.' });
    if (d.new_password !== d.confirm) return showFieldErrors(form, { confirm: 'The two passwords do not match.' });
    const btn = form.querySelector('button[type=submit]');
    await withBusy(btn, 'Saving...', async () => {
      try {
        const r = await api.post('/auth/reset', { token, new_password: d.new_password }, { auth: false });
        clearResetToken();
        renderSignIn(root, health, onSuccess, r.message);
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        formAlert(form, orphan.length ? orphan.join(' ') : err.message);
      }
    });
  });
  form.querySelector('input')?.focus();
}

// Removes the one-time token from the address bar so it is not left in history or shared by accident.
function clearResetToken() {
  try {
    const u = new URL(location.href);
    u.searchParams.delete('reset');
    history.replaceState(null, '', u.pathname + u.search + u.hash);
  } catch { /* URL API unavailable */ }
}

export function changePasswordDialog() {
  const m = openModal({
    title: 'Change password', size: 'sm',
    body: html`<form id="pw-form" novalidate>
      ${field({ name: 'current_password', label: 'Current password', type: 'password', required: true, attrs: { autocomplete: 'current-password' } })}
      ${field({ name: 'new_password', label: 'New password', type: 'password', required: true, help: 'At least 10 characters, with a letter and a number.', attrs: { autocomplete: 'new-password' } })}
      ${field({ name: 'confirm', label: 'Confirm new password', type: 'password', required: true, attrs: { autocomplete: 'new-password' } })}</form>`,
    footer: html`<button type="button" class="btn btn-outline" data-x="cancel">Cancel</button><button type="button" class="btn btn-primary" data-x="save">Change password</button>`,
  });
  const form = m.body.querySelector('#pw-form');
  m.foot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-x]');
    if (!b) return;
    if (b.dataset.x === 'cancel') return m.close();
    clearFieldErrors(form);
    const d = readForm(form);
    if (d.new_password !== d.confirm) return showFieldErrors(form, { confirm: 'The two passwords do not match.' });
    await withBusy(b, 'Saving...', async () => {
      try {
        await api.post('/auth/password', { current_password: d.current_password, new_password: d.new_password });
        toast('Your password has been changed.');
        m.close();
      } catch (err) {
        const orphan = showFieldErrors(form, err.fields || {});
        if (orphan.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message);
      }
    });
  });
}
