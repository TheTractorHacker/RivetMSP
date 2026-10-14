// Browser smoke for multi-select ticket filters and the default view (RivetMSP 26.10.21): tick two priorities and two agents with checkboxes on the Tickets page,
// save them as a view that is also the default, open Tickets and land on it, override with an explicit filter, "Reset to my default", "Show all open", kanban,
// the star toggle, Select all / Clear, keyboard use, phone width, dark theme. Seed first: php tests/browser/ticket_views_seed.php; then
// BASE_URL=... ADMIN_EMAIL=... ADMIN_PASSWORD=... node tests/browser/ticket_views_smoke.mjs   (screenshots in $SMOKE_OUT/shots)
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE)) { console.error(`Refusing to run against ${BASE}: loopback scratch installs only.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'ticket-views', base: BASE });
const T = (name, fn, o = {}) => H.check(name, fn, o);
const p = page;
const go = async (path) => { await p.goto(BASE + path); await sleep(600); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const shot = async (name) => { const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64')); };
const nav = async (js, timeout = 60000) => {
  const origin = await p.eval('performance.timeOrigin');
  await p.eval(js);
  const end = Date.now() + timeout;
  for (;;) {
    await sleep(200);
    try { if (await p.eval(`performance.timeOrigin !== ${origin} && document.readyState === 'complete'`)) break; } catch { /* navigating */ }
    if (Date.now() > end) throw new Error('page did not navigate');
  }
  await sleep(600);
};
const rows = () => p.eval(`[...new Set((document.body.innerText.match(/tvT\\d{2}/g)||[]))].sort().join(',')`);
const loc = () => p.eval(`decodeURIComponent(location.search)`);
// open a quick-filter dropdown by its toggle id, tick options by label text
const openDd = async (id) => { await p.eval(`document.getElementById(${JSON.stringify(id)}).click()`); await sleep(350); };
const tick = (label) => p.eval(`(()=>{const l=[...document.querySelectorAll('.dropdown-menu.show label.form-check-label, .modal.show label.form-check-label, #advancedFilter label.form-check-label')].find(x=>x.innerText.trim()===${JSON.stringify(label)});if(!l)throw new Error('no option '+${JSON.stringify(label)});l.click();})()`);
const closeDdAndWait = async (id) => nav(`document.getElementById(${JSON.stringify(id)}).click()`); // closing a changed menu submits the filter form

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav(`document.querySelector('button[name=login], button[type=submit]').click()`);
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('quick filter: tick High and Medium with checkboxes, closing the menu applies them', async () => {
  await go('/agent/tickets.php?status=Open&canned_date=alltime&view=list');
  await openDd('tvfPriority');
  await tick('High'); await tick('Medium');
  const badge = await p.eval(`document.querySelector('#tvfPriority .tvf-toggle-count').innerText`);
  assert(badge === '2', 'toggle badge should read 2, got ' + badge);
  await shot('01-priority-dropdown');
  await closeDdAndWait('tvfPriority');
  const l = await loc();
  assert(/priority\[\]=High/.test(l) && /priority\[\]=Medium/.test(l) && !/priority\[\]=Low/.test(l), 'URL lacks the two priorities: ' + l);
  const r = (await rows()).split(',');
  const exp = []; for (let i = 1; i <= 24; i++) if (['Low', 'Medium', 'High'][i % 3] !== 'Low') exp.push('tvT' + String(i).padStart(2, '0'));
  assert(r.join() === exp.join(), `rows differ: got ${r.length}, expected ${exp.length}`);
});

await T('quick filter: two agents with checkboxes (More Filters)', async () => {
  await p.eval(`document.querySelector('[data-bs-target="#advancedFilter"]').click()`);
  await sleep(500);
  await tick('Alex Agent'); await tick('Bo Agent');
  await shot('02-more-filters');
  await nav(`document.querySelector('#advancedFilter button[type=submit]').click()`);
  const l = await loc();
  assert(/assigned\[\]=2/.test(l) && /assigned\[\]=3/.test(l) && /priority\[\]=High/.test(l), 'URL lacks the agents or the priorities: ' + l);
  const r = (await rows()).split(',');
  const exp = []; for (let i = 1; i <= 24; i++) { const a = [0, 2, 3, 4][i % 4]; if (['Low', 'Medium', 'High'][i % 3] !== 'Low' && (a === 2 || a === 3)) exp.push('tvT' + String(i).padStart(2, '0')); }
  assert(r.join() === exp.join() && exp.length > 0, `rows differ: got ${r.join()} expected ${exp.join()}`);
});

let viewUrl = '';
await T('Save view modal arrives with both groups pre-ticked; save it as my default', async () => {
  viewUrl = await p.url();
  await p.click('button.ajax-modal[title="Save current view"]');
  await p.waitSel('.modal.show input[name=name]', { timeout: 15000 });
  const counts = await p.eval(`[...document.querySelectorAll('.modal.show .tvf-group')].map(g=>{const l=g.querySelector('.tvf-legend');return l?l.innerText.replace(/\\s+/g,' ').trim():''}).filter(t=>/Priority|Assigned/.test(t)).join('|')`);
  assert(/Assigned to 2/.test(counts) && /Priority 2/.test(counts), 'groups should show 2 selected each: ' + counts);
  await p.type('.modal.show input[name=name]', 'High and Medium - Alex and Bo');
  await p.eval(`document.querySelector('.modal.show #savedViewDefault').click()`);
  await shot('03-save-view-modal');
  await nav(`document.querySelector('.modal.show button[name=add_ticket_saved_view]').click()`);
  const t = await p.eval('document.body.innerText');
  assert(t.includes('High and Medium - Alex and Bo'), 'saved view not listed');
  assert(await p.exists('.ticket-saved-views .tvf-star.is-default'), 'no starred default in the list');
  assert(/priority\[\]=High/.test(await loc()), 'did not return to the filtered page: ' + await loc());
});

await T('Tickets with no filter opens the default view', async () => {
  await go('/agent/tickets.php');
  const l = await loc();
  assert(/priority\[\]=High/.test(l) && /priority\[\]=Medium/.test(l) && /assigned\[\]=2/.test(l) && /assigned\[\]=3/.test(l), 'not redirected to the default view: ' + l);
  const active = await p.eval(`document.querySelector('.ticket-saved-views .nav-link.active')?.innerText.trim()`);
  assert(active && /High and Medium/.test(active), 'the default view is not highlighted as active: ' + active);
  assert((await p.eval('document.body.innerText')).includes('Reset to my default'), 'no reset link');
});

await T('an explicit filter in the URL wins over the default', async () => {
  await go('/agent/tickets.php?status=Open&priority%5B%5D=Low&canned_date=alltime');
  const l = await loc();
  assert(/priority\[\]=Low/.test(l) && !/Medium/.test(l), 'the explicit filter was replaced: ' + l);
  const r = (await rows()).split(',');
  assert(r.length === 8, 'Low priority should be 8 tickets, got ' + r.length);
});

await T('"Reset to my default" returns to it; "Show all open" clears the filters', async () => {
  await nav(`[...document.querySelectorAll('.tvf-default-links a')].find(a=>/Reset to my default/.test(a.innerText)).click()`);
  assert(/priority\[\]=Medium/.test(await loc()), 'reset did not apply the default: ' + await loc());
  await nav(`[...document.querySelectorAll('.tvf-default-links a')].find(a=>/Show all open/.test(a.innerText)).click()`);
  const l = await loc();
  assert(/status=Open/.test(l) && !/priority/.test(l), 'show all open should be a plain open list: ' + l);
  assert((await rows()).split(',').length === 24, 'expected all 24 open tickets');
});

await T('kanban opens with the default filters too', async () => {
  await go('/agent/tickets.php?view=kanban');
  assert(/view=kanban/.test(await loc()) && /priority\[\]=Medium/.test(await loc()), 'kanban lost the view or the default: ' + await loc());
  await sleep(400);
  const n = await p.eval(`document.querySelectorAll('#kanban-board .task').length`);
  const exp = []; for (let i = 1; i <= 24; i++) { const a = [0, 2, 3, 4][i % 4]; if (['Low', 'Medium', 'High'][i % 3] !== 'Low' && (a === 2 || a === 3)) exp.push(i); }
  assert(n === exp.length, `kanban shows ${n} cards, expected ${exp.length}`);
  await shot('04-kanban');
});

await T('old style single-value view still opens and is highlighted', async () => {
  await go('/agent/tickets.php?status=Open&canned_date=alltime');
  await nav(`[...document.querySelectorAll('.ticket-saved-views a.nav-link')].find(a=>/Old style High/.test(a.innerText)).click()`);
  assert(/priority\[\]=High/.test(await loc()), 'view link is not canonical: ' + await loc());
  assert(/Old style High/.test(await p.eval(`document.querySelector('.ticket-saved-views .nav-link.active')?.innerText||''`)), 'old view not active');
});

await T('star toggle: make another view my default, then clear it', async () => {
  await nav(`document.querySelector('.ticket-saved-views a.tvf-star[aria-label^="Make Old style High"]').click()`);
  assert(/now your default/.test(await p.eval('document.body.innerText')), 'no confirmation');
  assert(await p.exists('.ticket-saved-views a.tvf-star.is-default[aria-label^="Old style High"]'), 'star not on the new default');
  await shot('05-star');
  await nav(`document.querySelector('.ticket-saved-views a.tvf-star.is-default').click()`);
  assert(!(await p.exists('.ticket-saved-views a.tvf-star.is-default')), 'default not cleared');
});

await T('Select all and Clear on a group, with the count badge', async () => {
  await go('/agent/tickets.php?status=Open&canned_date=alltime');
  await openDd('tvfBoard');
  await p.eval(`document.querySelector('.dropdown-menu.show [data-tvf-all]').click()`);
  let c = await p.eval(`document.querySelector('.dropdown-menu.show [data-tvf-count]').innerText`);
  assert(c === '2', 'Select all should give 2 boards, got ' + c);
  await p.eval(`document.querySelector('.dropdown-menu.show [data-tvf-clear]').click()`);
  c = await p.eval(`document.querySelector('.dropdown-menu.show [data-tvf-count]').innerText`);
  assert(c === 'Any', 'Clear should read Any, got ' + c);
  await p.eval(`document.getElementById('tvfBoard').click()`);
});

await T('keyboard: Space ticks a checkbox in the Save View modal and updates the badge', async () => {
  await go('/agent/tickets.php?status=Open&canned_date=alltime');
  await p.click('button.ajax-modal[title="Save current view"]');
  await p.waitSel('.modal.show input[name=name]', { timeout: 15000 });
  await p.focus('.modal.show input[name="f_priority[]"]');
  await p.press(' ');
  const checked = await p.eval(`document.querySelector('.modal.show input[name="f_priority[]"]').checked`);
  assert(checked, 'Space did not tick the focused checkbox');
  const hasLabel = await p.eval(`(()=>{const i=document.querySelector('.modal.show input[name="f_priority[]"]');return !!document.querySelector('label[for="'+i.id+'"]')})()`);
  assert(hasLabel, 'checkbox has no label');
  const legend = await p.eval(`document.querySelector('.modal.show fieldset.tvf-group legend')?.tagName`);
  assert(legend === 'LEGEND', 'groups are not fieldset/legend');
  await p.press('Escape');
  await sleep(400);
});

await T('phone width: the page and the open filter menu do not overflow', async () => {
  await p.setViewport(390, 800, true);
  await go('/agent/tickets.php?status=Open&canned_date=alltime');
  let o = await p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(o.sw <= o.vw + 1, `horizontal scroll at ${o.vw}px (scrollWidth ${o.sw})`);
  await openDd('tvfPriority');
  const r = await p.eval(`(()=>{const m=document.querySelector('.dropdown-menu.show');const b=m.getBoundingClientRect();return {l:b.left,r:b.right,vw:document.documentElement.clientWidth}})()`);
  assert(r.l >= -1 && r.r <= r.vw + 1, `menu leaves the screen: ${JSON.stringify(r)}`);
  await shot('06-phone-menu');
  await p.eval(`document.getElementById('tvfPriority').click()`);
  await sleep(300);
  await p.click('button.ajax-modal[title="Save current view"]');
  await p.waitSel('.modal.show input[name=name]', { timeout: 15000 });
  o = await p.eval(`(()=>{const m=document.querySelector('.modal.show .modal-dialog');const b=m.getBoundingClientRect();return {r:b.right,vw:document.documentElement.clientWidth,sw:document.querySelector('.modal.show').scrollWidth}})()`);
  assert(o.r <= o.vw + 1, 'modal wider than the phone screen');
  await shot('07-phone-modal');
  await p.press('Escape'); await sleep(400);
  await p.setViewport(1366, 900);
});

await T('dark theme: filter menu and modal stay readable', async () => {
  await go('/agent/tickets.php?status=Open&canned_date=alltime');
  await p.eval(`(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode');})()`);
  await openDd('tvfPriority');
  const c = await p.eval(`(()=>{const l=document.querySelector('.dropdown-menu.show .form-check-label');const s=getComputedStyle(l);const m=getComputedStyle(document.querySelector('.dropdown-menu.show'));return {fg:s.color,bg:m.backgroundColor}})()`);
  const lum = (css) => { const m = css.match(/\d+(\.\d+)?/g).map(Number); return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]; };
  assert(lum(c.fg) > lum(c.bg) + 60, `label does not contrast with the menu in dark mode: ${JSON.stringify(c)}`);
  await shot('08-dark-menu');
  await p.eval(`document.getElementById('tvfPriority').click()`);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
