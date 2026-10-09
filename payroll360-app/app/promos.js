// Internal marketing box shown at the bottom of the left menu.
// EDIT THIS LIST to promote your other products. Each entry needs a title, a short text, a button label and an https link.
// Add more entries and the box rotates between them (small dots let people switch). Set ENABLED to false to hide the box.
import { html } from './ui.js';

export const ENABLED = true;

export const PROMOS = [
  {
    tag: 'More from Appz360',
    title: 'Explore our other business apps',
    text: 'Tools for HR, finance and operations that work alongside Payroll360.',
    cta: 'Learn more',
    href: 'https://www.appz360.com',
  },
  // {
  //   tag: 'New',
  //   title: 'Product name',
  //   text: 'One short sentence about what it does for the customer.',
  //   cta: 'Try it',
  //   href: 'https://example.com/product',
  // },
];

const KEY = 'p360.promo.dismissed';
const store = {
  get() { try { return sessionStorage.getItem(KEY) === '1'; } catch { return false; } },
  set() { try { sessionStorage.setItem(KEY, '1'); } catch { /* private mode: it just shows again next time */ } },
};
const safeLinks = () => PROMOS.filter((p) => /^https:\/\//i.test(p.href));

/** Returns the box markup, or '' when disabled, empty or dismissed. */
export function promoBox(index = 0) {
  const list = safeLinks();
  if (!ENABLED || !list.length || store.get()) return '';
  const at = index % list.length;
  const p = list[at];
  return html`<div class="promo-box" role="complementary" aria-label="From our other products">
    <button type="button" class="promo-x" data-promo="dismiss" aria-label="Hide this message">&times;</button>
    <span class="promo-tag">${p.tag || 'Sponsored'}</span>
    <div class="promo-title">${p.title}</div>
    <p class="promo-text">${p.text}</p>
    <a class="promo-cta" href="${p.href}" target="_blank" rel="noopener noreferrer">${p.cta || 'Learn more'} &#8599;</a>
    ${list.length > 1 ? html`<div class="promo-dots">${list.map((_, i) => html`<button type="button" class="promo-dot ${i === at ? 'on' : ''}" data-promo="${i}" aria-label="Show message ${i + 1}"></button>`)}</div>` : ''}
  </div>`;
}

/** Wire clicks once. `slot` is the element that holds the box. */
export function wirePromo(slot) {
  if (!slot) return;
  slot.addEventListener('click', (e) => {
    const b = e.target.closest('[data-promo]');
    if (!b) return;
    if (b.dataset.promo === 'dismiss') { store.set(); slot.innerHTML = ''; return; }
    slot.innerHTML = promoBox(Number(b.dataset.promo)).toString();
  });
  slot.innerHTML = promoBox(0).toString();
}
