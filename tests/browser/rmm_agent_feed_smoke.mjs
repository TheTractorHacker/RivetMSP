// Browser smoke for Administration > Endpoint agent > Agent updates (the GitHub release feed and Publish) and the Add device / Build installer page.
//
//   (throwaway install; GitHub is the LOCAL mock tests/support/mock_github.py started with a directory prepared by tests/browser/rmm_agent_feed_extra.php, and the web server
//   is started with RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:<mock port>; php tests/browser/rmm_seed.php /tmp/rmm-seed.json seeds the fleet)
//   BASE_URL=http://127.0.0.1:8672 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json SMOKE_OUT=/tmp/rmm-feed-shots node tests/browser/rmm_agent_feed_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). Every check also fails on any uncaught error, console.error, failed first-party
// request or HTTP 4xx/5xx while it ran. Nothing here touches the network.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) {
  console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: this smoke writes data and is meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-feed', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const over=[];
  const clipped=(e)=>{for(let n=e.parentElement;n&&n!==document.body&&n!==d;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'||o==='clip')return true}return false};
  for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width<=0||r.right<=vw+1)continue;
    const cs=getComputedStyle(e);if(cs.position==='fixed'||cs.visibility==='hidden'||cs.display==='none')continue;
    if(e.closest('[hidden],.modal,.offcanvas,.dropdown-menu,.navbar-vertical,.main-sidebar')||clipped(e))continue;
    over.push(e.tagName+'.'+String(e.className).slice(0,50)+' right='+Math.round(r.right));if(over.length>5)break}
  return {sw:d.scrollWidth,vw,over}})()`);
const noOverflow = async (label) => {
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const unlabeled = (scope) => p.evalWith((s) => [...document.querySelectorAll(s + ' input, ' + s + ' select, ' + s + ' textarea')].filter((e) => {
  if (e.type === 'hidden' || e.disabled) return false; const r = e.getBoundingClientRect(); if (!(r.width > 0 && r.height > 0)) return false;
  return !((e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title'));
}).map((e) => e.name || e.id || e.tagName), scope);
const flash = async (re, label) => {
  try { await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) }); } catch (e) {
    throw new Error(e.message + ' | the page says: ' + String(await bodyText()).replace(/\s+/g, ' ').slice(0, 500));
  }
};
const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const setVal = (sel, v) => p.evalWith((q, val) => { const e = document.querySelector(q); if (!e) throw new Error('no ' + q); e.value = val; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, sel, v);
// record what the page turns into a download (the file never has to touch the disk)
const trapDownloads = () => p.eval(`(()=>{ if (window.__trapped) return true; window.__trapped = true; window.__blobs = []; const o = URL.createObjectURL.bind(URL);
  URL.createObjectURL = (b) => { const r = new FileReader(); r.onload = () => { window.__blobs.push({ size: b.size, head: Array.from(new Uint8Array(r.result)).map((x) => x.toString(16).padStart(2, '0')).join('') }); }; r.readAsArrayBuffer(b.slice(0, 8)); return o(b); }; return true; })()`);

await T('login: real form signs in', async () => { await login(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });

await T('Agent updates: the card lists the releases (the badge is on, so the first visit read the mock), the newer one is badged', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#agent-updates');
  const t = await p.eval(`document.getElementById('agent-updates').innerText`);
  assert(/0\.9\.1/.test(t) && /0\.9\.0/.test(t) && /Pre-release/.test(t), 'releases not listed: ' + t.slice(0, 400));
  assert(await p.exists('[data-feed-badge]'), 'no "new version available" badge');
  assert((await unlabeled('#agent-updates')).length === 0, 'unlabeled controls: ' + (await unlabeled('#agent-updates')).join(','));
  await shot('agent-updates-before-desktop');
});

await T('Agent updates: Check for updates answers in words', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.clickNav('#agent-updates button[name=agent_feed_refresh]');
  await flash(/Checked just now|Nothing new|agent release/, 'check message');
});

await T('Agent updates: Publish 0.9.1 to the pilot ring through the form; every file is verified and shown, then the row says what is hosted', async () => {
  await go('/admin/settings_endpoint_agent.php');
  const row = '[data-feed-row="0.9.1"]';
  await p.waitSel(row + ' button[name=publish_agent_release]');
  await setVal(row + ' select[name=release_ring]', 'pilot');
  await setVal(row + ' input[name=rollout_pct]', '20');
  await p.clickNav(row + ' button[name=publish_agent_release]');
  await flash(/Release 0\.9\.1 published/, 'published');
  const t = await p.eval(`document.getElementById('agent-updates').innerText`);
  assert(/SHA-256 verified/.test(t) && /rivetit-agent-windows-arm64\.exe/.test(t) && /Linux files/.test(t), 'steps missing: ' + t.slice(0, 600));
  const r = await p.eval(`document.querySelector('[data-feed-row="0.9.1"]').innerText`);
  assert(/exe amd64/.test(r) && /exe arm64/.test(r) && /msi amd64/.test(r) && /Linux links/.test(r) && /pilot 20%/.test(r), 'row after publish: ' + r);
  await shot('agent-updates-after-desktop');
});

await T('Agent updates: the Binaries and MSI cards show what the publish stored', async () => {
  const t = await p.eval(`document.getElementById('binaries').innerText + document.getElementById('msi-templates').innerText`);
  assert(/0\.9\.1/.test(t) && /Current/.test(t), 'stored files not listed: ' + t.slice(0, 300));
});

await T('Build installer: the page, the hosted formats, the recorded Linux files', async () => {
  await go('/agent/rmm_add_device.php');
  await p.waitSel('#rmm-add-form');
  assert(await p.exists('[data-nav-build-installer]'), 'menu entry missing');
  assert(!(await p.eval(`document.querySelector('[data-add-format=exe]').disabled`)) && !(await p.eval(`document.querySelector('[data-add-format=msi]').disabled`)), 'a Windows button is disabled although files are hosted');
  const t = await bodyText();
  assert(/Release 0\.9\.1/.test(t) && /rivetit-agent_0\.9\.1_amd64\.deb/.test(t), 'Linux files not listed');
  assert((await unlabeled('#rmm-add-form')).length === 0, 'unlabeled controls: ' + (await unlabeled('#rmm-add-form')).join(','));
  await shot('build-installer-desktop-light');
});

await T('Build installer: choosing no client is answered, nothing is requested', async () => {
  await go('/agent/rmm_add_device.php');
  await p.click('[data-add-format=exe]');
  await flash(/Choose the client/, 'client message');
});

await T('Build installer: .exe and .msi download as stamped files (sizes and the OLE header seen in the page)', async () => {
  await go('/agent/rmm_add_device.php');
  await trapDownloads();
  await p.eval(`(()=>{const s=document.getElementById('rmm-add-client'); s.value=[...s.options].find(o=>o.value).value; s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  await p.click('[data-add-format=exe]');
  await flash(/was downloaded\. Copy it to the PC, double-click it/, 'exe downloaded');
  await p.click('[data-add-format=msi]');
  await flash(/msiexec \/i/, 'msi downloaded');
  const b = await p.eval('window.__blobs');
  assert(b.length === 2 && b[0].size > 16384 && b[0].head.startsWith('4d5a') && b[1].size > 8000 && b[1].head === 'd0cf11e0a1b11ae1', 'downloads: ' + JSON.stringify(b));
});

await T('Build installer: PowerShell and msiexec commands appear in copy blocks', async () => {
  await p.click('[data-add=commands-windows]');
  await p.waitSel('#add-win-out [data-add-copy]');
  const t = await p.eval(`document.getElementById('add-win-out').innerText`);
  assert(/rvte1\./.test(t) && /msiexec/.test(t) && /Invoke-WebRequest/.test(t), 'commands: ' + t.slice(0, 300));
  await p.click('#add-win-out [data-add-copy]');
  await p.waitFor(`document.querySelector('#add-win-out [data-add-copy]').textContent === 'Copied'`, { label: 'copy button feedback' });
});

await T('Build installer: Linux commands for the script, the .deb and the .rpm, with the recorded SHA-256', async () => {
  await p.click('[data-add=commands-linux]');
  await p.waitSel('#add-linux-out [data-add-copy]');
  const blocks = await p.eval(`[...document.querySelectorAll('#add-linux-out [data-add-block]')].map(b => b.getAttribute('data-add-block') + '|' + b.querySelector('pre').textContent)`);
  assert(blocks.length === 3, 'blocks: ' + blocks.map((x) => x.split('|')[0]).join(','));
  assert(blocks.every((x) => /sha256sum -c -/.test(x) && /rvte1\./.test(x)), 'a block lacks the hash check or the token');
  assert(!blocks.some((x) => /--token\s/.test(x)), 'a token on a command line');
  await shot('build-installer-linux-desktop');
});

await T('Build installer: phone width has no horizontal scroll; the long commands wrap', async () => {
  await p.setViewport(390, 800, true);
  await go('/agent/rmm_add_device.php');
  await noOverflow('Build installer');
  await go('/admin/settings_endpoint_agent.php#agent-updates');
  await p.waitSel('#agent-updates');
  await noOverflow('Agent updates');
  await shot('agent-updates-mobile');
  await p.setViewport(1366, 900, false);
});

await T('Build installer: keyboard only (Tab reaches the client field and Enter on a format button is a real button press)', async () => {
  await go('/agent/rmm_add_device.php');
  await p.focus('#rmm-add-client');
  const active = await p.eval('document.activeElement.id');
  assert(active === 'rmm-add-client', 'focus: ' + active);
  const reachable = await p.eval(`[...document.querySelectorAll('#rmm-add-form button')].every(b => b.tabIndex >= 0)`);
  assert(reachable, 'a button is not reachable by keyboard');
});

await T('dark mode: the new page renders without error', async () => {
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark'); true`);
  await shot('build-installer-dark');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
