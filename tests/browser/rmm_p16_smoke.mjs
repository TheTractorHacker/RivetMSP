// Browser smoke for RivetCore 1.0.0-rc.16: the pushed MeshCentral agent install and removal on the device's Remote tab, the bulk install on the Agent fleet page, the Format
// selector (exe | msi) of the Add device dialog, and the MSI templates / Deployment cards on Administration > Endpoint agent: desktop and phone, light and dark.
//
//   tests/browser/rmm_p16_smoke.mjs  (through the same wrapper as the other smokes: <wrapper> <port> tests/browser/rmm_p16_smoke.mjs)
//
// There is NO real MeshCentral server and no Windows machine behind any of it: the MeshCentral address is made up, the device is simulated through the real device endpoints
// (tests/browser/rmm_p16_extra.php), and the MSI is the real msitools wixl fixture. Same driver, harness and safety rules as the other smokes: loopback only, zero npm
// dependencies, signs in through the real login form, every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke changes administration settings and converts a table; it is meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });


const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-p16', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); await sleep(500); };   // let fonts and layout settle before anything is clicked
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const ADMIN = '/admin/settings_endpoint_agent.php';


// Clicks that survive the late layout shifts of a page opened on a #fragment (the browser re-applies the scroll to the fragment while fonts and images arrive): the
// target's position must be the same twice, 200 ms apart, before the mouse goes down.
const click = async (sel) => {
  let a = await p.centre(sel);
  for (let i = 0; i < 8; i++) {
    await sleep(200);
    const b = await p.centre(sel);
    const same = Math.abs(a.x - b.x) < 1 && Math.abs(a.y - b.y) < 1;
    a = b;
    if (same) break;
  }
  await p.clickAt(a.x, a.y);
};
const nav = async (sel, timeout = 90000) => {
  const origin = await p.eval('performance.timeOrigin');
  await click(sel);
  const end = Date.now() + timeout;
  for (;;) {
    await sleep(200);
    try { if (await p.eval(`performance.timeOrigin !== ${origin} && document.readyState === 'complete'`)) break; } catch { /* the execution context is gone mid-navigation */ }
    if (Date.now() > end) throw new Error('page did not navigate after clicking ' + sel);
  }
  await sleep(600);
};

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
/** A screenshot of one element (a card), however far down the page it is. */
async function shotEl(sel, name) {
  const r = await p.evalWith((q) => { const e = document.querySelector(q); if (!e) return null; const b = e.getBoundingClientRect(); return { x: b.x + scrollX, y: b.y + scrollY, w: b.width, h: b.height }; }, sel);
  assert(r, 'no element ' + sel);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: Math.max(0, r.x), y: Math.max(0, r.y), width: Math.ceil(r.w), height: Math.min(Math.ceil(r.h), 6000), scale: 1 } });
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
  if (o.sw > o.vw + 1 && o.over.length === 0) { o.over = await p.eval(`[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>document.documentElement.clientWidth+1&&getComputedStyle(e).position!=='fixed'&&!e.closest('table')).slice(0,8).map(e=>e.tagName+'#'+e.id+'.'+String(e.className).slice(0,40)+' r='+Math.round(e.getBoundingClientRect().right)+' in '+(e.closest('.modal,.dropdown-menu,.navbar-vertical,.main-sidebar,[hidden],.table-responsive')||{className:'-'}).className)`); }
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await nav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
// every visible form control inside the new cards has an accessible name
const unlabeled = () => p.eval(`[...document.querySelectorAll('#mesh input, #mesh select, #mesh textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const msgIn = (card) => p.eval(`(()=>{const e=document.querySelector('#${card} [data-rmm-scale-msg]');return e?e.innerText.trim():''})()`);
const checked = (sel) => p.evalWith((q) => !!document.querySelector(q).checked, sel);
// a person clicks the label of a switch or box (the input itself is a few pixels)
const setChecked = async (sel, want) => {
  for (let i = 0; i < 3 && (await checked(sel)) !== want; i++) { await click(`label[for="${sel.slice(1)}"]`); await sleep(350); }
  assert((await checked(sel)) === want, 'could not set ' + sel);
};
const php = (args, extraEnv = {}) => execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p16_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env, ...extraEnv }, encoding: 'utf8' });



const phpJson = (args) => JSON.parse(php(args).split('\n').find((l) => l.startsWith('{') || l.startsWith('[')));   // the test library prints its own summary line after ours
const sp = (s) => s.replace(/\s+/g, ' ');
let fx = null;

// ------------------------------------------------------------------------------------------------ sign in and the fixture
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
  fx = phpJson(['setup']);
  assert(fx.dev.P1 > 0 && fx.dev.P2 > 0 && fx.dev.P3 > 0, 'could not enroll the test devices');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ the device's Remote tab
const DEV = (n) => `/agent/asset_details.php?asset_id=${fx.asset[n]}`;
const openRemote = async (n) => { await go(DEV(n) + '#rmm-remote'); await p.waitSel('#rmm-remote-tools'); await sleep(300); };
await T('Remote tab: Install MeshCentral agent is offered for a device that can take it; an older agent gets a disabled button and the reason', async () => {
  await openRemote('P1');
  await trapDialogs();
  assert(await p.exists('[data-rem-mesh-install="1"]'), 'no install button');
  assert(!(await p.eval(`document.querySelector('[data-rem-mesh-install="1"]').disabled`)), 'the install button should be enabled');
  assert(!(await p.exists('[data-rem-mesh-uninstall]')), 'no Remove button before the agent is installed');
  assert(/signed job/.test(sp(await p.eval(`document.querySelector('[data-rem-mesh-push]').innerText`))), 'the section should say it is a signed job');
  assert((await p.eval(`document.getElementById('rem-mesh-push-status').getAttribute('aria-live')`)) === 'polite', 'status is not a live region');
  await shotEl('[data-rem-mesh-push]', 'push-p1-desktop-light');
  await openRemote('P2');
  assert(await p.eval(`document.querySelector('[data-rem-mesh-install="1"]').disabled`), 'the install button should be disabled for an agent that did not announce the job');
  const help = sp(await p.eval(`document.getElementById('rem-mesh-push-help').innerText`));
  assert(/too old to take the install/.test(help) && /Update the agent first/.test(help), 'help: ' + help);
  assert(await p.eval(`document.querySelector('[data-rem-mesh-install="1"]').getAttribute('aria-describedby') === 'rem-mesh-push-help'`), 'help is not tied to the button');
});
await T('Remote tab: clicking Install queues the signed job, shows the status, and the page follows the device\'s result', async () => {
  await openRemote('P1');
  await trapDialogs();
  await click('[data-rem-mesh-install="1"]');
  await p.waitFor(`/Queued\\./.test(document.getElementById('rem-mesh-push-status').innerText)`, { timeout: 10000 });
  const jobs = phpJson(['jobs', 'P1']);
  assert(jobs.length === 1 && jobs[0].type === 'mesh_install', 'the device did not receive one mesh_install job: ' + JSON.stringify(jobs));
  assert(jobs[0].params && jobs[0].params.url === 'https://mesh.example.test' && typeof jobs[0].params.meshid === 'string' && jobs[0].params.meshid.length > 0, 'job parameters: ' + JSON.stringify(jobs[0].params).slice(0, 200));
  assert(!(await p.eval(`document.body.innerText`)).includes(jobs[0].params.meshid), 'the device group id must never be shown in the page');
  await click('[data-rem-mesh-install="1"]');   // a second click while it is open: the same job, nothing new
  await p.waitFor(`/already queued or running/.test(document.getElementById('rem-mesh-push-status').innerText)`, { timeout: 10000 });
  assert(phpJson(['count', 'P1']).open === 1, 'a second job was queued');
  php(['result', 'P1', 'failed', 'mesh install failed']);
  await p.waitFor(`/Failed/.test(document.getElementById('rem-mesh-push-result').innerText)`, { timeout: 30000 });
  await shotEl('[data-rem-mesh-push]', 'push-p1-failed-desktop-light');
  php(['report', 'P1', '{"installed":true,"version":"1.1.0","error":null,"node_id":"node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01"}']);
  await openRemote('P1');
  assert(await p.exists('[data-rem-mesh-uninstall="1"]') && await p.exists('[data-rem-mesh-install="force"]') && !(await p.exists('[data-rem-mesh-install="1"]')), 'installed: Remove and Run again, not Install');
  await shotEl('[data-rem-mesh-push]', 'push-p1-installed-desktop-light');
});
await T('Remote tab: Remove asks first in the page dialog (no native dialog), needs the box, then queues', async () => {
  await openRemote('P1');
  await trapDialogs();
  await click('[data-rem-mesh-uninstall="1"]');
  await p.waitSel('#rmmRemConfirm.show');
  assert(/MeshCentral agent's own uninstaller/.test(sp(await p.eval(`document.getElementById('rmmRemConfirmText').innerText`))), 'dialog text');
  assert(await p.eval(`document.getElementById('rmmRemConfirmGo').disabled`), 'Remove must be disabled until the box is ticked');
  await p.waitFor(`document.activeElement && document.activeElement.id === 'rmmRemConfirmCheck'`, { timeout: 4000, label: 'focus should move to the confirmation box' });
  await shot('push-confirm-desktop-light');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRemConfirm.show')`, { timeout: 5000 });
  assert(phpJson(['count', 'P1']).open === 0, 'cancelling queued something');
  await p.waitFor(`document.activeElement && document.activeElement.hasAttribute('data-rem-mesh-uninstall')`, { timeout: 4000, label: 'focus should return to the Remove button' });
  await click('[data-rem-mesh-uninstall="1"]');
  await p.waitSel('#rmmRemConfirm.show');
  await click('label[for=rmmRemConfirmCheck]');
  await sleep(300);
  await click('#rmmRemConfirmGo');
  await p.waitFor(`/Queued\\./.test(document.getElementById('rem-mesh-push-status').innerText)`, { timeout: 10000 });
  const jobs = phpJson(['jobs', 'P1']);
  assert(jobs.length === 1 && jobs[0].type === 'mesh_uninstall', 'no mesh_uninstall job: ' + JSON.stringify(jobs));
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
  php(['result', 'P1', 'succeeded', '']);
  php(['report', 'P1', '{"installed":false,"error":null}']);
  await p.waitFor(`/Done/.test(document.getElementById('rem-mesh-push-result').innerText)`, { timeout: 30000 });
});

// ------------------------------------------------------------------------------------------------ the fleet page: bulk
await T('Agent fleet: the bulk card counts first, asks for the box, then reports what it queued and skipped', async () => {
  await go('/agent/rmm_fleet.php');
  await trapDialogs();
  assert(await p.exists('#rmm-mesh-bulk'), 'no bulk card');
  await click('#rmm-mesh-bulk summary');
  await sleep(300);
  assert(await p.eval(`document.querySelector('#rmm-mesh-bulk details').open`), 'the card did not open');
  await click('#rmm-mb-count');
  await p.waitFor(`/Choose who gets/.test(document.getElementById('rmm-mb-msg').innerText)`, { timeout: 5000 });
  await p.eval(`(()=>{const s=document.getElementById('rmm-mb-target');s.value='client:1';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await click('#rmm-mb-count');
  await p.waitFor(`/This reaches up to \\d+ device/.test(document.getElementById('rmm-mb-msg').innerText)`, { timeout: 10000 });
  assert(!(await p.eval(`document.getElementById('rmm-mb-confirm-wrap').classList.contains('d-none')`)), 'the confirmation box should appear');
  assert(await p.eval(`document.getElementById('rmm-mb-go').disabled`), 'Install must be disabled until the box is ticked');
  const lab = await p.eval(`document.getElementById('rmm-mb-ok-label').innerText`);
  const reach = (/up to (\d+) devices/.exec(lab) || [])[1];
  assert(/I confirm: install the MeshCentral agent on up to \d+ devices/.test(lab) && Number(reach) >= 3, 'label: ' + lab);
  assert(phpJson(['count', 'P3']).open === 0, 'counting queued something');
  await shotEl('#rmm-mesh-bulk', 'bulk-counted-desktop-light');
  // changing the choice drops the confirmation
  await p.eval(`(()=>{const s=document.getElementById('rmm-mb-target');s.value='all:0';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  assert(await p.eval(`document.getElementById('rmm-mb-confirm-wrap').classList.contains('d-none')`), 'a changed choice must drop the confirmation');
  await p.eval(`(()=>{const s=document.getElementById('rmm-mb-target');s.value='client:1';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await click('#rmm-mb-count');
  await p.waitFor(`/This reaches up to/.test(document.getElementById('rmm-mb-msg').innerText)`, { timeout: 10000 });
  await click('label[for=rmm-mb-ok]');
  await sleep(300);
  await click('#rmm-mb-go');
  await p.waitFor(`/install jobs? queued of/.test(document.getElementById('rmm-mb-msg').innerText)`, { timeout: 15000 });
  const m = sp(await p.eval(`document.getElementById('rmm-mb-msg').innerText`));
  assert(new RegExp('\\d+ install jobs? queued of ' + reach + ' devices').test(m) && /run an agent too old/.test(m), 'result: ' + m);
  assert(phpJson(['count', 'P3']).open === 1, 'the Windows device did not get its job');
  assert(phpJson(['count', 'P2']).open === 0, 'the old agent must not get one');
  await shotEl('#rmm-mesh-bulk', 'bulk-done-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
}, { allowHttp: [/rmm_mesh_bulk\.php/] });

// ------------------------------------------------------------------------------------------------ Add device: the Format selector
await T('Add device: Format exe | msi; msi is disabled with the reason until a template is published', async () => {
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await trapDialogs();
  assert((await p.eval(`document.getElementById('rmm-inst-format').value`)) === 'exe', 'exe should be the default');
  assert(await p.eval(`document.querySelector('#rmm-inst-format option[value=msi]').disabled`), 'msi should be disabled without a template');
  const help = sp(await p.eval(`document.getElementById('rmm-inst-format-help').innerText`));
  assert(/No MSI template is published for x64/.test(help), 'help: ' + help);
  assert((await p.eval(`document.querySelector('label[for=rmm-inst-format]').innerText`)) === 'Format', 'the selector has no label');
  assert(await p.eval(`document.getElementById('rmm-inst-format').getAttribute('aria-describedby') === 'rmm-inst-format-help'`), 'help is not tied to the selector');
  await shot('format-nomsi-desktop-light');
  // the Linux tab has no format
  await click('#rmm-inst-tab-linux');
  await sleep(300);
  assert(await p.eval(`document.getElementById('rmm-inst-format-wrap').hidden`), 'the Format field should be hidden on the Linux tab');
  await click('#rmm-inst-tab-windows');
  await sleep(300);
  assert(!(await p.eval(`document.getElementById('rmm-inst-format-wrap').hidden`)), 'and back on Windows');
});
await T('Add device: with a template published msi is enabled for x64 only, the button says so, and the msi downloads', async () => {
  assert(phpJson(['msi']).ok === true, 'could not publish the MSI fixture');
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await trapDialogs();
  assert(!(await p.eval(`document.querySelector('#rmm-inst-format option[value=msi]').disabled`)), 'msi should be enabled for x64');
  await p.eval(`(()=>{const s=document.getElementById('rmm-inst-client');s.value='1';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.eval(`(()=>{const s=document.getElementById('rmm-inst-format');s.value='msi';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(300);
  assert(/Download MSI/.test(await p.eval(`document.getElementById('rmm-inst-go-label').innerText`)), 'the button should say Download MSI');
  // ARM64 has no template: msi falls back to exe and is disabled again, with the reason
  await p.eval(`(()=>{const s=document.getElementById('rmm-inst-arch');s.value='arm64';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(300);
  assert(await p.eval(`document.querySelector('#rmm-inst-format option[value=msi]').disabled && document.getElementById('rmm-inst-format').value === 'exe'`), 'msi should be disabled and exe selected for ARM64');
  assert(/ARM64/.test(sp(await p.eval(`document.getElementById('rmm-inst-format-help').innerText`))), 'ARM64 reason missing');
  await p.eval(`(()=>{const s=document.getElementById('rmm-inst-arch');s.value='amd64';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.eval(`(()=>{const s=document.getElementById('rmm-inst-format');s.value='msi';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(300);
  await shot('format-msi-desktop-light');
  await p.eval(`window.__dl = null; const oc = HTMLAnchorElement.prototype.click; HTMLAnchorElement.prototype.click = function () { if (this.download) { window.__dl = this.download; return; } return oc.call(this); }; true`);
  await click('#rmm-inst-go');
  await p.waitFor(`/was downloaded/.test(document.getElementById('rmm-inst-msg').innerText)`, { timeout: 30000 });
  const msg = sp(await p.eval(`document.getElementById('rmm-inst-msg').innerText`));
  assert(/RivetIT-Agent-client-a-x64\.msi/i.test(msg) && /msiexec \/i/.test(msg) && /unsigned/.test(msg), 'message: ' + msg);
  assert(/\.msi$/i.test(String(await p.eval('window.__dl'))), 'the downloaded file is not an .msi: ' + (await p.eval('window.__dl')));
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
});

// ------------------------------------------------------------------------------------------------ Administration
await T('Administration: MSI templates card (warnings, list, labelled controls) and the Deployment Format selector', async () => {
  await go('/admin/settings_endpoint_agent.php#msi-templates');
  await trapDialogs();
  const t = sp(await p.eval(`document.getElementById('msi-templates').innerText`));
  assert(/Unsigned, and not yet verified on Windows/.test(t) && /UNVERIFIED/.test(t) && /SmartScreen/.test(t), 'warning text missing');
  assert(/1\.0\.0/.test(t) && /Current/.test(t), 'the published template is not listed as Current: ' + t.slice(0, 400));
  assert(/x64 only/.test(t), 'the x64-only note is missing');
  for (const sel of ['#msi_file', '#msi_version', '#msi_arch']) assert(await p.exists(sel), 'missing ' + sel);
  const un = await p.eval(`[...document.querySelectorAll('#msi-templates input, #msi-templates select')].filter(e=>e.type!=='hidden'&&!(e.labels&&e.labels.length)&&!e.getAttribute('aria-label')).map(e=>e.name||e.id)`);
  assert(un.length === 0, 'unlabelled controls: ' + un.join(','));
  assert(await p.eval(`document.querySelectorAll('#msi-templates [onclick],#msi-templates [onchange],#msi-templates [onsubmit]').length === 0`), 'inline handlers');
  assert(!(await p.eval(`document.querySelector('#dp_format option[value=msi]').disabled`)), 'Deployment: msi should be enabled for x64');
  await p.eval(`(()=>{const s=document.getElementById('dp_arch');s.value='arm64';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(200);
  assert(await p.eval(`document.querySelector('#dp_format option[value=msi]').disabled`), 'Deployment: msi should be disabled for ARM64');
  assert(/ARM64/.test(sp(await p.eval(`document.getElementById('dp_format_help').innerText`))), 'ARM64 reason in the Deployment card');
  await shotEl('#msi-templates', 'card-msi-desktop-light');
});
await T('Administration: uploading a template through the real file input (version and architecture are read from the name)', async () => {
  await go('/admin/settings_endpoint_agent.php#msi-templates');
  const root = (await p.send('DOM.getDocument', {})).root.nodeId;
  const { nodeId } = await p.send('DOM.querySelector', { nodeId: root, selector: '#msi_file' });
  const path = resolve('tests/fixtures/msi/template-x64.msi');
  // a copy under a name that carries a version and an architecture
  const named = join(outDir, 'rivetit-agent-2.0.0-windows-arm64.msi');
  writeFileSync(named, (await import('node:fs')).readFileSync(path));
  await p.send('DOM.setFileInputFiles', { nodeId, files: [named] });
  await sleep(500);
  assert((await p.eval(`document.getElementById('msi_version').value`)) === '2.0.0' && (await p.eval(`document.getElementById('msi_arch').value`)) === 'arm64', 'version/arch not read from the file name: ' + (await p.eval(`document.getElementById('msi_version').value + '/' + document.getElementById('msi_arch').value`)));
  await p.eval(`document.getElementById('msi_arch').value='amd64'`);
  await p.eval(`document.getElementById('msi_version').value='2.0.0'`);
  await nav('button[name=upload_msi_template]');
  const t = sp(await p.eval(`document.getElementById('msi-templates').innerText`));
  assert(/2\.0\.0/.test(t), 'the uploaded template is not listed: ' + t.slice(0, 300));
});
await T('Administration: make current and deactivate from the card', async () => {
  await go('/admin/settings_endpoint_agent.php#msi-templates');
  await trapDialogs();
  await nav('#msi-templates button[value=make_current]');
  assert(/Current/.test(sp(await p.eval(`document.getElementById('msi-templates').innerText`))), 'no Current badge');
});

// ------------------------------------------------------------------------------------------------ dark mode and phone width
const pages = () => [['remote', DEV('P1') + '#rmm-remote'], ['fleet', '/agent/rmm_fleet.php'], ['admin-msi', '/admin/settings_endpoint_agent.php#msi-templates']];
const visit = async (name, path) => {
  await go(path);
  if (name === 'remote') { await p.waitSel('#rmm-remote-tools'); }
  if (name === 'fleet') { await p.eval(`document.querySelector('#rmm-mesh-bulk details').open = true`); }
  await sleep(300);
};
await T('dark mode: the new surfaces are dark and readable (screenshots in shots/)', async () => {
  await setTheme(1);
  for (const [name, path] of pages()) {
    await p.setViewport(1366, 900);
    await visit(name, path);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
  }
});
await T('phone width, dark and light: nothing overflows the page', async () => {
  await p.setViewport(390, 800, true);
  for (const [name, path] of pages()) { await visit(name, path); await noOverflow(`${name} dark phone`); await shot(`${name}-phone-dark`); }
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(390, 800, true);
  for (const [name, path] of pages()) { await visit(name, path); await noOverflow(`${name} light phone`); await shot(`${name}-phone-light`); }
  await visit('fleet', '/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await noOverflow('add device phone');
  await shot('add-device-phone-light');
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt and no inline handlers in the new markup', async () => {
  await visit('remote', DEV('P1') + '#rmm-remote');
  await trapDialogs();
  assert(await p.eval(`document.querySelectorAll('[data-rem-mesh-push] [onclick],[data-rem-mesh-push] [onchange],#rmm-mesh-bulk [onclick],#rmm-mesh-bulk [onchange],#rmm-mesh-bulk [onsubmit]').length === 0`), 'inline event handlers');
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
