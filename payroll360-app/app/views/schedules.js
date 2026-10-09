// Payroll schedules: how often you pay, the cut-off, the pay date and who is on the schedule.
import { html, raw, on, pageHeader, table, badge, emptyState, field, readForm, showFieldErrors, clearFieldErrors, formAlert, withBusy, openModal, openDrawer, toast, titleCase, fmtDate, fmtPeriod, icon } from '../ui.js';

const offsetText = (n) => (n === 0 ? 'On the last day of the period' : n < 0 ? `${-n} day${n === -1 ? '' : 's'} before period end` : `${n} day${n === 1 ? '' : 's'} after period end`);
function addDays(iso, n) {
  const d = new Date(iso + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

export async function render(ctx, route) {
  const canManage = ctx.can('schedules.manage');
  const r = await ctx.api.get('/schedules');
  if (route.stale()) return;
  const paint = (items) => route.paint(html`
    ${pageHeader({ title: 'Payroll schedules', sub: 'A schedule sets the pay frequency, the cut-off date and the pay date for a group of employees.', actions: canManage ? html`<button class="btn btn-primary" id="btn-new">${icon('plus')} New schedule</button>` : '' })}
    <div class="card">${items.length ? table({ columns: [
      { label: 'Schedule', render: (s) => html`<div class="cell-main">${s.name}</div><div class="cell-sub">${s.entity_name}</div>` },
      { label: 'Frequency', render: (s) => titleCase(s.frequency) }, { label: 'Currency', render: (s) => s.currency },
      { label: 'Cut-off', render: (s) => offsetText(s.cutoff_offset_days) }, { label: 'Pay date', render: (s) => offsetText(s.pay_offset_days) },
      { label: 'Employees', align: 'right', render: (s) => s.employee_count }, { label: 'Status', render: (s) => badge(s.status) },
      { label: '', render: (s) => html`<div class="actions"><button class="btn btn-outline btn-sm" data-periods="${s.id}">Upcoming periods</button>${canManage ? html`<button class="btn btn-outline btn-sm" data-edit="${s.id}">Edit</button>` : ''}</div>` },
    ], rows: items }) : emptyState({ title: 'No payroll schedule has been created yet.', text: canManage ? 'Create one to start running payroll.' : '', actionLabel: canManage ? 'New schedule' : '', actionAttr: 'data-new="1"', iconName: 'calendar' })}</div>`);
  let items = r.items;
  paint(items);

  const reload = async () => { await ctx.refreshMeta(); items = (await ctx.api.get('/schedules')).items; paint(items); };

  function editor(existing) {
    const s = existing || { frequency: 'monthly', entity_id: ctx.meta.entities[0]?.id, cutoff_offset_days: -10, pay_offset_days: 0, status: 'active', anchor_date: '2026-01-05' };
    const d = openDrawer({
      title: existing ? 'Edit schedule' : 'New schedule', size: 'md',
      body: html`<form id="sf" novalidate><div class="form-grid">
        ${field({ name: 'name', label: 'Schedule name', required: true, value: s.name, wide: true })}
        ${field({ name: 'entity_id', label: 'Legal entity', type: 'select', required: true, options: ctx.meta.entities.map((e) => ({ value: e.id, label: e.name })), value: s.entity_id })}
        ${field({ name: 'frequency', label: 'Pay frequency', type: 'select', required: true, options: ctx.meta.frequencies, value: s.frequency, help: existing ? 'Cannot change once payroll has been run.' : '' })}
        <div id="anchor-slot" class="span-2">${field({ name: 'anchor_date', label: 'A date a pay period starts on', type: 'date', value: s.anchor_date, help: 'Weekly and biweekly periods repeat from this date.' })}</div>
        ${field({ name: 'cutoff_offset_days', label: 'Cut-off (days from period end)', type: 'text', value: String(s.cutoff_offset_days), help: 'Use a negative number for before the period ends, for example -10.' })}
        ${field({ name: 'pay_offset_days', label: 'Pay date (days from period end)', type: 'text', value: String(s.pay_offset_days), help: '0 = last day of the period, -5 = five days before, 5 = five days after.' })}
        ${existing ? field({ name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], value: s.status }) : ''}</div>
        <div class="banner banner-info" id="preview"></div></form>`,
      footer: html`<button class="btn btn-outline" data-x="cancel">Cancel</button><button class="btn btn-primary" data-x="save">${existing ? 'Save changes' : 'Create schedule'}</button>`,
    });
    const form = d.body.querySelector('#sf');
    const preview = () => {
      const v = readForm(form);
      form.querySelector('#anchor-slot').classList.toggle('hidden', !['weekly', 'biweekly'].includes(v.frequency));
      const c = Number.parseInt(v.cutoff_offset_days, 10);
      const p = Number.parseInt(v.pay_offset_days, 10);
      form.querySelector('#preview').innerHTML = (Number.isInteger(c) && Number.isInteger(p)
        ? html`<div class="grow">Example: for a period ending <b>Sep 30, 2026</b>, the cut-off is <b>${fmtDate(addDays('2026-09-30', c))}</b> and the pay date is <b>${fmtDate(addDays('2026-09-30', p))}</b>.</div>`
        : html`<div class="grow">Enter whole numbers of days to see an example.</div>`).toString();
    };
    form.addEventListener('input', preview);
    form.addEventListener('change', preview);
    preview();
    d.foot.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-x]');
      if (!b) return;
      if (b.dataset.x === 'cancel') return d.close();
      clearFieldErrors(form);
      const v = readForm(form);
      const body = { ...v, cutoff_offset_days: v.cutoff_offset_days, pay_offset_days: v.pay_offset_days };
      for (const k of ['cutoff_offset_days', 'pay_offset_days']) { if (!/^-?\d+$/.test(body[k])) return showFieldErrors(form, { [k]: 'Enter a whole number.' }); body[k] = Number(body[k]); }
      if (!['weekly', 'biweekly'].includes(body.frequency)) delete body.anchor_date;
      await withBusy(b, 'Saving...', async () => {
        try {
          if (existing) await ctx.api.put(`/schedules/${existing.id}`, body); else await ctx.api.post('/schedules', body);
          toast('Schedule saved.');
          d.close();
          await reload();
        } catch (err) { const o = showFieldErrors(form, err.fields || {}); if (o.length || !Object.keys(err.fields || {}).length) formAlert(form, err.message); }
      });
    });
  }

  on(route.view, 'click', '#btn-new, [data-new]', () => editor());
  on(route.view, 'click', '[data-edit]', (e, b) => editor(items.find((x) => String(x.id) === b.dataset.edit)));
  on(route.view, 'click', '[data-periods]', async (e, b) => {
    try {
      const p = (await ctx.api.get(`/schedules/${b.dataset.periods}/periods`)).items;
      openModal({ title: 'Upcoming pay periods', subtitle: items.find((x) => String(x.id) === b.dataset.periods)?.name, size: 'md',
        body: table({ columns: [{ label: 'Period', render: (x) => fmtPeriod(x.period_start, x.period_end) }, { label: 'Cut-off', render: (x) => fmtDate(x.cutoff_date) }, { label: 'Pay date', render: (x) => fmtDate(x.pay_date) }], rows: p }) });
    } catch (err) { toast(err.message, 'error'); }
  });
}
