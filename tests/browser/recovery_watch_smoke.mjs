// Browser smoke for the integration sync watcher panel (Admin > Backup / Recovery, RivetMSP 26.10.20): the card renders every state, an administrator edits an
// override (expected interval + note), mutes, unmutes and forgets a dormant integration's history through the real forms; desktop and phone, light and dark.
// Run through a wrapper that seeds the integrations first (tests/browser/recovery_watch_seed.php): <wrapper> <port> tests/browser/recovery_watch_smoke.mjs
// Same driver and safety rules as the other smokes: loopback only, no npm dependencies, signs in through the real login form, every check also fails on any
// uncaught error, console.error, failed first-party request or HTTP 4xx/5xx.
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
const H = new Harness({ page, outDir, edition: 'recovery-watch', base: BASE });
const T = (name, fn, o = {}) => H.check(name, fn, o);
const p = page;
const go = async (path) => { await p.goto(BASE + path); await sleep(500); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const nav = async (sel, timeout = 60000) => {
  const origin = await p.eval('performance.timeOrigin');
  await p.evalWith((q) => document.querySelector(q).click(), sel);
  const end = Date.now() + timeout;
  for (;;) {
    await sleep(200);
    try { if (await p.evalWith((o) => performance.timeOrigin !== o && document.readyState === 'complete', origin)) break; } catch { /* mid-navigation */ }
    if (Date.now() > end) throw new Error('page did not navigate after clicking ' + sel);
  }
  await sleep(500);
};
async function shotEl(sel, name) {
  const r = await p.evalWith((q) => { const e = document.querySelector(q); if (!e) return null; const b = e.getBoundingClientRect(); return { x: b.x + scrollX, y: b.y + scrollY, w: b.width, h: b.height }; }, sel);
  assert(r, 'no element ' + sel);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: Math.max(0, r.x), y: Math.max(0, r.y), width: Math.ceil(r.w), height: Math.min(Math.ceil(r.h), 6000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const stateOf = (name) => p.evalWith((n) => { const r = [...document.querySelectorAll('#sync-watch tbody tr:not(.collapse)')].find((x) => x.innerText.includes(n)); return r ? r.querySelector('.badge').innerText.trim() : null; }, name);
const flash = () => p.eval(`[...document.querySelectorAll('.alert, .toast-message')].map(a=>a.innerText).join(' | ')`);

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('the watcher card shows every state, with last run and expected interval', async () => {
  await go('/admin/backup.php');
  await p.waitSel('#sync-watch');
  const want = { 'SW Healthy': 'healthy', 'SW Failing': 'failing', 'SW Stale': 'stale', 'SW Dormant': 'dormant', 'SW Muted': 'muted', 'SW Daily': 'healthy' };
  for (const [n, s] of Object.entries(want)) assert((await stateOf(n)) === s, `${n} should be ${s}, got ${await stateOf(n)}`);
  const text = await p.eval(`document.querySelector('#sync-watch').innerText`);
  assert(/dormant since \d{4}-\d{2}-\d{2}/.test(text), 'no "dormant since <date>"');
  assert(/every 1 day/.test(text) && /declared/.test(text), 'the declared 24 h interval is not shown');
  assert(!/SW Agent/.test(text), 'the rivetit_agent integration must not be listed');
  await shotEl('#sync-watch', 'watch-card');
});

await T('Edit: declare "every 24 hours" with a note through the real form', async () => {
  await go('/admin/backup.php');
  await p.waitSel('#sync-watch');
  const id = await p.evalWith((n) => [...document.querySelectorAll('#sync-watch tbody tr:not(.collapse)')].find((x) => x.innerText.includes(n)).querySelector('[data-bs-target]').getAttribute('data-bs-target'), 'SW Stale');
  await p.evalWith((i) => document.querySelector('#sync-watch [data-bs-target="' + i + '"]').click(), id);
  await sleep(600);
  await p.evalWith((i) => { const f = document.querySelector(i); f.querySelector('input[name=watch_expected_hours]').value = '24'; f.querySelector('input[name=watch_note]').value = 'synced by /opt/scripts/x.py'; }, id);
  await nav(`${id} button[name=save_watch_override]`);
  assert(/Watcher setting saved/.test(await flash()), 'no confirmation: ' + await flash());
  assert((await stateOf('SW Stale')) === 'healthy', 'a run 5 h ago against a declared 24 h interval should be healthy');
  assert(/synced by \/opt\/scripts\/x\.py/.test(await p.eval(`document.querySelector('#sync-watch').innerText`)), 'note not shown');
});

await T('Mute and Unmute one-click, state follows', async () => {
  await go('/admin/backup.php');
  await p.waitSel('#sync-watch');
  await nav2('SW Failing', 'mute_watch');
  assert((await stateOf('SW Failing')) === 'muted', 'not muted');
  await nav2('SW Failing', 'unmute_watch');
  assert((await stateOf('SW Failing')) === 'failing', 'not failing again after unmute');
});
async function nav2(name, btn) {
  const origin = await p.eval('performance.timeOrigin');
  await p.evalWith((n, b) => [...document.querySelectorAll('#sync-watch tbody tr:not(.collapse)')].find((x) => x.innerText.includes(n)).querySelector('button[name=' + b + ']').click(), name, btn);
  for (let i = 0; i < 300; i++) { await sleep(200); try { if (await p.evalWith((o) => performance.timeOrigin !== o && document.readyState === 'complete', origin)) break; } catch { /* navigating */ } }
  await sleep(500);
}

await T('Forget history needs its confirmation box (native validation), then removes the runs', async () => {
  await go('/admin/backup.php');
  await p.waitSel('#sync-watch');
  await p.evalWith((n) => [...document.querySelectorAll('#sync-watch tbody tr:not(.collapse)')].find((x) => x.innerText.includes(n)).querySelector('button[name=forget_watch_history]').click(), 'SW Dormant');
  await sleep(800);
  assert((await stateOf('SW Dormant')) === 'dormant' && /backup\.php/.test(await p.url()) && !/Forgot/.test(await flash()), 'it should not submit without the box ticked');
  await p.evalWith((n) => { [...document.querySelectorAll('#sync-watch tbody tr:not(.collapse)')].find((x) => x.innerText.includes(n)).querySelector('input[name=watch_confirm]').checked = true; }, 'SW Dormant');
  await nav2('SW Dormant', 'forget_watch_history');
  assert(/Forgot the sync history/.test(await flash()), 'no confirmation: ' + await flash());
});

await T('phone width: the card does not overflow the page (the table scrolls inside its card)', async () => {
  await p.setViewport(390, 800, true);
  await go('/admin/backup.php');
  await p.waitSel('#sync-watch');
  const o = await p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(o.sw <= o.vw + 1, `horizontal scroll at ${o.vw}px (scrollWidth ${o.sw})`);
  await shotEl('#sync-watch', 'watch-card-phone');
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
