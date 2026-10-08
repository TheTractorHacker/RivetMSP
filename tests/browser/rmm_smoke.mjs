// Browser smoke for the RMM asset panel and the agent fleet page (RivetMSP).
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json            # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_smoke.mjs
//
// Same driver, harness and safety rules as suite.mjs (loopback only, zero npm dependencies, README.md has the install recipe). RivetMSP has no
// Metrics subsystem: the Performance section is an explanation card (checked below), not charts. It signs in through the
// real login form, then checks both pages at desktop and phone width, light and dark, and writes full-page screenshots to SMOKE_OUT/shots.
// Every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx that occurred while it ran.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke switches the signed-in user's theme and queues no jobs, but it is meant for a throwaway install.`);
  process.exit(2);
}
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
// a hash-only change is not a navigation (no load event): leave the page first so the deep link is really loaded
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const asset = (n) => `/agent/asset_details.php?asset_id=${SEED.asset[n]}`;

// full-page screenshot: grow the capture to the document height
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
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
// count window.confirm / alert calls (the panel must use in-page dialogs only)
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });   // the landing page of a freshly wiped scratch database may fail for reasons unrelated to RMM

// ------------------------------------------------------------------------------------------------ asset page, desktop, light
await T('asset page: strip, gauges, alerts, checks and performance render (desktop, light)', async () => {
  await go(asset('WIN1'));
  await trapDialogs();
  await p.waitSel('#rmm-strip');
  const t = await bodyText();
  assert(/WIN1/.test(t) && /Online with critical alerts/.test(t), 'strip text missing');
  assert((await p.eval(`document.querySelectorAll('#rmm-panel svg.rmm-gauge[role=img]').length`)) >= 4, 'fewer than four gauges (CPU, memory, two volumes)');
  const labels = await p.eval(`[...document.querySelectorAll('#rmm-panel svg.rmm-gauge')].map(s=>s.getAttribute('aria-label'))`);
  assert(labels.includes('CPU 14 percent, OK') && labels.includes('Disk C: 91 percent, Critical'), 'gauge labels: ' + labels.join(' | '));
  assert(await p.visible('#rmm-checks table'), 'checks table not visible');
  assert(await p.visible('#rmm-alerts'), 'alerts card not visible');
  assert(/disk_c/.test(await p.eval(`document.querySelector('#rmm-checks').innerText`)), 'failing check not listed');
  assert(/No performance history in RivetMSP/.test(await p.eval(`document.getElementById('rmm-pane-overview').innerText`)), 'the Performance explanation card is missing');
  assert(!(await p.exists('#rmm-panel canvas')), 'a chart canvas is drawn although RivetMSP keeps no metric history');
  assert(await p.exists('.rmm-strip-crit'), 'strip edge class missing');
  assert(!(await p.exists('#rmmDetailTabs')), 'the vendor RMM card is drawn for an agent link');
  await shot('asset-overview-desktop-light');
});
await T('asset page: the gauges are the latest check-in and the Performance card says there is no history', async () => {
  const t = await p.eval(`document.getElementById('rmm-pane-overview').innerText`);
  assert(/live health/i.test(t) && /performance/i.test(t) && /latest reading the agent sent/.test(t), 'Live health / Performance text missing');
  assert(!(await p.exists('#rmm-panel .ifm-chart')), 'a chart section is drawn');
});

// ------------------------------------------------------------------------------------------------ keyboard and tabs
await T('keyboard: the tab strip is a tablist and arrow keys move between tabs', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-tab-overview');
  assert((await p.eval(`document.querySelector('#rmm-panel [role=tablist]').getAttribute('aria-label')`)) !== null, 'tablist has no label');
  await p.focus('#rmm-tab-overview');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-tab-inventory').getAttribute('aria-selected') === 'true'`, { label: 'Inventory tab selected after ArrowRight' });
  assert(await p.visible('#rmm-pane-inventory'), 'inventory pane not shown');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-tab-jobs').getAttribute('aria-selected') === 'true'`, { label: 'Jobs tab selected' });
  await p.press('ArrowLeft'); await p.press('ArrowLeft');
  await p.waitFor(`document.getElementById('rmm-tab-overview').getAttribute('aria-selected') === 'true'`, { label: 'back to Overview' });
});
await T('tab deep link: #rmm-jobs opens the Jobs tab', async () => {
  await go(asset('WIN1') + '#rmm-jobs');
  await p.waitFor(`document.getElementById('rmm-tab-jobs').getAttribute('aria-selected') === 'true'`, { label: 'Jobs tab from the hash' });
  assert(await p.visible('#rmm-pane-jobs'), 'jobs pane hidden');
});
await T('inventory tab: hardware, OS, disks (with used bars), adapters; software and services are honest empty states', async () => {
  await go(asset('WIN1') + '#rmm-inventory');
  await p.waitSel('#rmm-pane-inventory table');
  const t = await p.eval(`document.getElementById('rmm-pane-inventory').innerText`);
  for (const s of ['Intel i7-1355U', 'Windows 11 23H2', 'C:', 'NTFS', 'Ethernet', 'aa:bb:cc:dd:ee:01', 'ACME\\alex']) assert(t.includes(s), 'inventory lacks ' + s);
  assert(/Not collected yet/.test(t) && /Phase 1/.test(t) && /Phase 6/.test(t), 'software/services empty states missing');
  assert((await p.eval(`document.querySelectorAll('#rmm-pane-inventory .progress[role=progressbar]').length`)) >= 2, 'disk used bars missing');
  await shot('asset-inventory-desktop-light');
});
await T('jobs tab: history, lazy output, hostile output stays text, no window.confirm', async () => {
  await go(asset('WIN1') + '#rmm-jobs');
  await trapDialogs();
  await p.waitSel('#rmm-pane-jobs table');
  const t = await p.eval(`document.getElementById('rmm-pane-jobs').innerText`);
  assert(/Collect inventory/.test(t) && /Failed/.test(t) && /Succeeded/.test(t) && /Queued/.test(t), 'job states missing: ' + t.slice(0, 200));
  assert(!/boom|collected: 2 disks/.test(await p.eval(`document.documentElement.outerHTML.replace(/<script[\\s\\S]*?<\\/script>/g,'')`)), 'job output is inlined in the page before it is requested');
  const failedId = SEED.job_failed;
  await p.click(`.rmm-output-btn[data-job="${failedId}"]`);
  await p.waitFor(`document.querySelector('#rmm-out-${failedId} pre').textContent.includes('boom')`, { label: 'output loaded' });
  const pre = await p.eval(`document.querySelector('#rmm-out-${failedId} pre').textContent`);
  assert(pre === 'boom <script>alert(1)</script>', 'output text: ' + pre);
  assert((await p.eval(`document.querySelectorAll('#rmm-out-${failedId} script, #rmm-out-${failedId} pre *').length`)) === 0, 'output created child elements (it must be text only)');
  assert((await p.eval(`document.querySelector('.rmm-output-btn[data-job="${failedId}"]').getAttribute('aria-expanded')`)) === 'true', 'aria-expanded not set');
  await shot('asset-jobs-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});
await T('reboot: in-page dialog needs the checkbox, closes with Escape, never window.confirm', async () => {
  await go(asset('WIN1'));
  await trapDialogs();
  await p.waitSel('#rmm-act-reboot');
  await p.click('#rmm-act-reboot');
  await p.waitSel('#rmmRebootModal.show');
  assert(await p.eval(`document.getElementById('rmm-reboot-go').disabled`), 'the Reboot button is enabled before the confirmation');
  await sleep(500);
  await p.click('#rmm-reboot-confirm');
  assert(!(await p.eval(`document.getElementById('rmm-reboot-go').disabled`)), 'the Reboot button stays disabled after confirming');
  await shot('asset-reboot-dialog-desktop-light');
  await sleep(300);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRebootModal.show')`, { label: 'dialog closed by Escape' });
  await sleep(600);
  assert((await p.eval(`document.activeElement && document.activeElement.id`)) === 'rmm-act-reboot', 'focus did not return to the Reboot button');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
  // nothing was queued
  const t = await bodyText();
  assert(!/Reboot queued/.test(t), 'a reboot was queued by cancelling');
});
await T('run script dialog: saved script list, destructive confirmation appears', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-act-script');
  await p.click('#rmm-act-script');
  await p.waitSel('#rmmRunModal.show');
  assert((await p.eval(`document.getElementById('rmm-run-saved').options.length`)) >= 1, 'saved script missing');
  assert(!(await p.visible('#rmm-run-confirm-wrap')), 'confirmation shown before the destructive box is ticked');
  await sleep(500);
  await p.click('#rmm-run-destructive');
  await p.waitSel('#rmm-run-confirm-wrap');
  await sleep(300);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRunModal.show')`, { label: 'dialog closed' });
});
await T('remote access: button disabled with the reason when no Mesh node is mapped', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-act-remote');
  assert(await p.eval(`document.getElementById('rmm-act-remote').disabled`), 'remote enabled without a node');
  assert(/MeshCentral node/.test(await p.eval(`document.getElementById('rmm-act-remote').title`)), 'no reason in the tooltip');
  assert(/MeshCentral node first/.test(await p.eval(`document.querySelector('.rmm-reasons').innerText`)), 'no visible reason text');
});

// ------------------------------------------------------------------------------------------------ other states
await T('states: offline (banner, dimmed gauges), never checked in, Linux, bare readings', async () => {
  await go(asset('OFFL'));
  await p.waitSel('#rmm-strip');
  let t = await bodyText();
  assert(/Offline/.test(t) && /This device is offline/.test(t), 'offline banner missing');
  assert(await p.exists('#rmm-panel .rmm-dim'), 'gauges are not dimmed');
  assert(/last reading/.test(t), '"last reading" chip missing');
  await shot('asset-offline-desktop-light');
  await go(asset('NEVER'));
  await p.waitSel('#rmm-strip');
  t = await bodyText();
  assert(/Waiting for first check-in/.test(t) && /Waiting for the first check-in/.test(t), 'never-checked-in empty state missing');
  assert(!(await p.exists('#rmm-checks')), 'checks drawn for a device that never reported');
  assert(await p.eval(`document.getElementById('rmm-act-reboot').disabled`), 'Reboot enabled before the first check-in');
  await shot('asset-never-desktop-light');
  await go(asset('LNX1'));
  await p.waitSel('#rmm-strip');
  assert(await p.eval(`document.getElementById('rmm-act-script').disabled`), 'Run script enabled on Linux');
  assert(!(await p.eval(`document.getElementById('rmm-act-reboot').disabled`)), 'Reboot disabled on Linux');
  assert(!!(await p.exists('#rmm-strip .fa-linux')) && !(await p.exists('#rmm-strip .fa-windows')), 'Linux device shows a Windows icon');
  await shot('asset-linux-desktop-light');
  await go(asset('BARE'));
  await p.waitSel('#rmm-strip');
  const n = await p.eval(`document.querySelectorAll('#rmm-panel svg.rmm-gauge[aria-label$="no data"]').length`);
  assert(n >= 2, 'a device with no readings must show "no data" gauges, got ' + n);
  await shot('asset-nodata-desktop-light');
});
await T('hostile device strings are inert (hostname, model, CPU, check detail)', async () => {
  await go(asset('HOST'));
  await p.waitSel('#rmm-strip');
  assert((await p.eval(`document.querySelectorAll('#rmm-panel script:not([type="application/json"]), #rmm-panel img[src="x"], #rmm-panel img[onerror]').length`)) === 0, 'markup was injected into the panel');
  assert(/<script>alert\(9\)/.test(await p.eval(`document.getElementById('rmm-strip').innerText`)), 'hostile hostname should be visible as text');
});

// ------------------------------------------------------------------------------------------------ fleet page
await T('fleet page: KPIs, health donut, approvals, offline list, capacity, device table (desktop, light)', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  const t = await bodyText();
  for (const s of ['Agent fleet', 'Online', 'Offline', 'Stale', 'Never seen', 'Need approval', 'Open alerts', 'Fleet health', 'Devices needing approval', 'Offline and stale', 'Recent job failures', 'Agent versions and rings', 'Performance and capacity']) {
    assert(t.includes(s), 'fleet page lacks ' + s);
  }
  const label = await p.eval(`document.querySelector('#rmm-devices').parentElement.querySelector('svg[role=img]').getAttribute('aria-label')`);
  assert(/4 online, 1 offline, 1 stale, 2 never seen/.test(label), 'donut label: ' + label);
  assert(await p.visible('#rmm-capacity'), 'capacity panel hidden for the administrator');
  assert((await p.eval(`document.querySelectorAll('#rmm-devices tbody tr').length`)) === 8, 'device rows');
  assert(/PEND/.test(await p.eval(`document.getElementById('rmm-approvals').innerText`)), 'PEND missing from approvals');
  assert(await p.exists('a[href="/agent/rmm_fleet.php"]'), 'nav entry missing');
  await shot('fleet-desktop-light');
});
await T('fleet page: status filter and text filter use the real form', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-f-status');
  await p.eval(`(()=>{const s=document.getElementById('rmm-f-status');s.value='offline';})()`);
  await p.clickNav('#rmm-devices form button[type=submit]');
  assert(/status=offline/.test(await p.url()), 'url: ' + (await p.url()));
  const rows = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows.length === 1 && /OFFL/.test(rows[0]), 'offline filter rows: ' + rows.length);
  await go('/agent/rmm_fleet.php?q=lnx');
  await p.waitSel('#rmm-devices table');
  const rows2 = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows2.length === 1 && /LNX1/.test(rows2[0]), 'text filter rows: ' + rows2.length);
  await go('/agent/rmm_fleet.php?q=zzz-none');
  assert(/No device matches/.test(await bodyText()), 'empty filter result message');
});
await T('fleet page: keyboard reaches the device links', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  await p.focus('#rmm-f-q');
  for (let i = 0; i < 4; i++) await p.press('Tab');
  const tag = await p.eval(`document.activeElement.tagName + ':' + (document.activeElement.closest('#rmm-devices')?'in':'out')`);
  assert(/^(A|BUTTON|SELECT|INPUT):in$/.test(tag), 'focus left the device card: ' + tag);
});

// ------------------------------------------------------------------------------------------------ "Add device" installer flow
// a minimal valid Windows executable (MZ header, PE signature, the machine type the server checks), padded to 4 KiB
const fakePe = (machine, fill) => {
  const b = new Uint8Array(4096).fill(fill);
  b.fill(0, 0, 160);
  b[0] = 0x4d; b[1] = 0x5a; b[0x3c] = 128;
  b.set([0x50, 0x45, 0, 0], 128);
  b[132] = machine & 255; b[133] = machine >> 8; b[134] = 3;
  b[148] = 0xe0; b[150] = 0x22;
  return Buffer.from(b).toString('base64');
};
const openInstaller = async (sel = '[data-rmm-installer-open]') => {
  await p.waitSel(sel);
  await p.click(sel);
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(450);   // the fade
};
const closeInstaller = async () => {
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'dialog closed by Escape' });
  await sleep(500);
};
const setClient = (id) => p.eval(`(()=>{const s=document.getElementById('rmm-inst-client');s.value='${id}';s.dispatchEvent(new Event('change',{bubbles:true}));return s.value})()`);
// the page's download (a blob link; the page's CSP forbids fetch() of blob: URLs): capture the Blob instead of writing a file
const trapDownload = () => p.eval(`window.__dl = null; const mk = URL.createObjectURL.bind(URL); const blobs = new Map(); URL.createObjectURL = (b) => { const u = mk(b); blobs.set(u, b); return u; }; HTMLAnchorElement.prototype.click = function () { if (this.download) { const name = this.download; blobs.get(this.href).arrayBuffer().then(b => { const u = new Uint8Array(b); window.__dl = { name, size: u.length, head: String.fromCharCode(u[0], u[1]), text: new TextDecoder('latin1').decode(u) }; }); } }; true`);

await T('Add device: a prominent button on the Agent Fleet page; with no Windows agent uploaded the dialog says so and links to Agent binaries', async () => {
  await go('/agent/rmm_fleet.php');
  await trapDialogs();
  await p.waitSel('[data-rmm-installer-open]');
  const b = await p.eval(`(()=>{const e=document.querySelector('[data-rmm-installer-open]');const r=e.getBoundingClientRect();return {cls:e.className,text:e.innerText.trim(),top:r.top,primary:getComputedStyle(e).backgroundColor}})()`);
  assert(/btn-primary/.test(b.cls) && /Add device/.test(b.text) && b.top < 200, 'the button is not a prominent primary button near the top: ' + JSON.stringify(b));
  await openInstaller();
  const t = await p.eval(`document.getElementById('rmmInstallerModal').innerText`);
  assert(/No Windows agent is uploaded yet/.test(t), 'empty-state sentence missing: ' + t.slice(0, 300));
  assert((await p.eval(`document.querySelector('[data-rmm-installer-notice=binary] a').getAttribute('href')`)) === '/admin/settings_endpoint_agent.php#binaries', 'link to Agent binaries missing');
  assert(await p.eval(`document.getElementById('rmm-inst-go').disabled`), 'the download button is enabled without a Windows agent');
  await shot('installer-empty-desktop-light');
  await closeInstaller();
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});
await T('Agent binaries: dropping both builds reads version and architecture from the file names and uploads them in one go', async () => {
  await go('/admin/settings_endpoint_agent.php#binaries');
  await p.waitSel('#bn_drop');
  await p.eval(`(()=>{const mk=(name,b64,type)=>{const bin=atob(b64);const u=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)u[i]=bin.charCodeAt(i);return new File([u],name,{type})};
    const dt=new DataTransfer();dt.items.add(mk('rivetit-agent-1.4.2-windows-amd64.exe',${JSON.stringify(fakePe(0x8664, 7))},'application/octet-stream'));dt.items.add(mk('rivetit-agent-1.4.2-windows-arm64.exe',${JSON.stringify(fakePe(0xaa64, 9))},'application/octet-stream'));
    document.getElementById('bn_drop').dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:dt}));})()`);
  await p.waitFor(`document.querySelectorAll('#bn_rows .row').length === 2`, { label: 'two rows for the two dropped files' });
  const rows = await p.eval(`[...document.querySelectorAll('#bn_rows .row')].map(r=>[r.querySelector('input[name="version[]"]').value,r.querySelector('select[name="arch[]"]').value])`);
  assert(JSON.stringify(rows) === JSON.stringify([['1.4.2', 'amd64'], ['1.4.2', 'arm64']]), 'detected: ' + JSON.stringify(rows));
  assert(await p.eval(`document.getElementById('bn_cur').checked`), '"Make current" is not on by default');
  await p.eval(`document.getElementById('bn_drop').scrollIntoView({block:'center'})`);
  await shot('binaries-dropped-desktop-light');
  await p.clickNav('#bn_go');
  const rowsAfter = await p.eval(`[...document.querySelectorAll('#binaries tbody tr')].map(r=>r.innerText.replace(/\\s+/g,' '))`);
  assert(rowsAfter.length === 2 && rowsAfter.every((r) => /1\.4\.2/.test(r) && /Current/.test(r)), 'binaries table: ' + JSON.stringify(rowsAfter));
  assert(/amd64/.test(rowsAfter.join()) && /arm64/.test(rowsAfter.join()), 'both architectures expected');
  await shot('binaries-uploaded-desktop-light');
});
await T('Add device: defaults, client choice, and the stamped exe streams (Windows tab, desktop, light)', async () => {
  await go('/agent/rmm_fleet.php');
  await trapDialogs();
  await trapDownload();
  await openInstaller();
  const d = await p.eval(`(()=>{const v=(id)=>document.getElementById(id);return {os:v('rmm-inst-os').value,tab:document.querySelector('#rmm-inst-tab-windows').getAttribute('aria-selected'),arch:v('rmm-inst-arch').value,ring:v('rmm-inst-ring').value,
    ttl:v('rmm-inst-ttl').value,multi:v('rmm-inst-multi').checked,adv:v('rmm-inst-adv').classList.contains('show'),client:v('rmm-inst-client').value,disabled:v('rmm-inst-go').disabled,notice:!!document.querySelector('[data-rmm-installer-notice]'),label:v('rmm-inst-go-label').textContent}})()`);
  assert(d.os === 'windows' && d.tab === 'true' && d.arch === 'amd64' && d.ring === 'stable' && d.ttl === '24' && d.multi === false && d.adv === false && d.notice === false && d.label === 'Download installer', 'defaults: ' + JSON.stringify(d));
  assert(/double-click it, and accept the administrator prompt/.test(await p.eval(`document.getElementById('rmm-inst-pane-windows').innerText`)) && /setup --silent/.test(await p.eval(`document.getElementById('rmm-inst-pane-windows').innerText`)), 'next-step text missing');
  // no client chosen: the form says so, nothing is requested
  await p.click('#rmm-inst-go');
  await p.waitFor(`/Choose the client/.test(document.getElementById('rmm-inst-msg').textContent)`, { label: 'choose-a-client message' });
  assert((await p.eval('window.__dl')) === null, 'a download started without a client');
  await p.click('[data-bs-target="#rmm-inst-adv"]');
  await p.waitFor(`document.getElementById('rmm-inst-adv').classList.contains('show')`, { label: 'Advanced opens' });
  await setClient('1');
  await shot('installer-advanced-desktop-light');
  await p.click('#rmm-inst-go');
  await p.waitFor(`window.__dl !== null`, { timeout: 20000, label: 'the exe download' });
  const dl = await p.eval('window.__dl');
  assert(/client-a-x64\.exe$/.test(dl.name) && dl.head === 'MZ' && dl.text.includes('RIVETIT-EMBED-v1') && dl.text.includes('Client A') && dl.size > 4096, 'download: ' + JSON.stringify({ name: dl.name, head: dl.head, size: dl.size }));
  await p.waitFor(`/was downloaded/.test(document.getElementById('rmm-inst-msg').textContent)`, { label: 'success message' });
  const msg = await p.eval(`document.getElementById('rmm-inst-msg').textContent`);
  assert(msg.includes(dl.name) && /administrator prompt/.test(msg) && /setup --silent/.test(msg), 'next-step message: ' + msg);
  assert(await p.eval(`document.querySelector('[data-rmm-inst-file]').textContent`) === dl.name, 'the silent-install example does not name the real file');
  await shot('installer-downloaded-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
  await closeInstaller();
});
await T('Add device: Linux tab creates the one-time install command, with a copy button; arrow keys switch tabs', async () => {
  await go('/agent/rmm_fleet.php');
  await openInstaller();
  await p.focus('#rmm-inst-tab-windows');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-inst-tab-linux').getAttribute('aria-selected') === 'true' && !document.getElementById('rmm-inst-pane-linux').hidden`, { label: 'Linux tab by ArrowRight' });
  assert((await p.eval(`document.getElementById('rmm-inst-go-label').textContent`)) === 'Create install command', 'primary button label');
  await setClient('2');
  await p.click('#rmm-inst-go');
  await p.waitSel('#rmm-inst-cmd-text');
  const cmd = await p.eval(`document.getElementById('rmm-inst-cmd-text').textContent`);
  assert(/install-linux\.sh/.test(cmd) && /--token-file/.test(cmd) && /printf '%s' '[^']+'/.test(cmd) && /Client B/.test(cmd), 'command: ' + cmd.slice(0, 200));
  assert(/valid until/.test(await p.eval(`document.getElementById('rmm-inst-msg').textContent`)), 'validity message missing');
  await p.click('#rmm-inst-copy');
  await p.waitFor(`/Copied/.test(document.getElementById('rmm-inst-copy').textContent) || /Ctrl\\+C/.test(document.getElementById('rmm-inst-msg').textContent)`, { label: 'copy feedback' });
  await shot('installer-linux-desktop-light');
  await p.press('Escape');   // focus is on the copy button inside the dialog
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'dialog closed by Escape' });
  await sleep(500);
  await openInstaller();
  assert((await p.eval(`document.getElementById('rmm-inst-cmd').hidden`)) && (await p.eval(`document.getElementById('rmm-inst-cmd-text').textContent`)) === '', 'the one-time command is still in the page after reopening');
  await closeInstaller();
});
await T('Add device: keyboard opens the dialog, focus moves into it, Esc closes it and focus returns to the button', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('[data-rmm-installer-open]');
  await p.focus('[data-rmm-installer-open]');
  await p.press('Enter');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(500);
  assert(await p.eval(`document.getElementById('rmmInstallerModal').contains(document.activeElement)`), 'focus did not move into the dialog');
  for (let i = 0; i < 12; i++) await p.press('Tab');
  assert(await p.eval(`document.getElementById('rmmInstallerModal').contains(document.activeElement)`), 'focus escaped the dialog');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
  await sleep(600);
  assert(await p.eval(`document.activeElement.hasAttribute('data-rmm-installer-open')`), 'focus did not return to the opener');
});
await T('Add device: the client page action preselects its client; the Endpoints menu entry opens the dialog', async () => {
  await go('/agent/client_overview.php?client_id=1');
  await p.waitSel('[data-rmm-installer-open][data-client-id="1"]');
  await openInstaller('[data-rmm-installer-open][data-client-id="1"]');
  assert((await p.eval(`document.getElementById('rmm-inst-client').value`)) === '1', 'client not preselected');
  assert((await p.eval(`document.getElementById('rmm-inst-client').options.length`)) === 2, 'the client page should offer only its client');
  await shot('installer-clientpage-desktop-light');
  await closeInstaller();
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(500);
  await closeInstaller();
  await go('/agent/rmm_fleet.php');
  await p.waitSel('a[href="/agent/rmm_fleet.php?add=1"]', { timeout: 8000 }).catch(() => {});
  assert(await p.exists('a[href="/agent/rmm_fleet.php?add=1"]'), 'Endpoints menu entry missing');
});
await T('Add device: phone width (390 px) and dark mode, fleet and client page', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_fleet.php');
  await openInstaller();
  const o = await p.eval(`(()=>{const m=document.querySelector('#rmmInstallerModal .modal-content').getBoundingClientRect();const d=document.documentElement;return {right:m.right,left:m.left,sw:d.scrollWidth,vw:d.clientWidth,go:document.getElementById('rmm-inst-go').getBoundingClientRect().right}})()`);
  assert(o.left >= 0 && o.right <= o.vw + 1 && o.sw <= o.vw + 1 && o.go <= o.vw, 'dialog overflows the phone width: ' + JSON.stringify(o));
  await setClient('1');
  await shot('installer-phone-light');
  await closeInstaller();
  await noOverflow('fleet with the Add device button');
  await go('/agent/client_overview.php?client_id=1');
  await p.waitSel('[data-rmm-installer-open]');
  await noOverflow('client page with the Add device button');
  await shot('installer-clientpage-phone-light');
  await setTheme(1);
  await go('/agent/rmm_fleet.php');
  await openInstaller();
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const bg = await p.eval(`getComputedStyle(document.querySelector('#rmmInstallerModal .modal-content')).backgroundColor`);
  const [r, g, b] = bg.match(/\d+/g).map(Number);
  assert(r + g + b < 3 * 110, 'the dialog is not dark in dark mode: ' + bg);
  await shot('installer-phone-dark');
  await closeInstaller();
  await p.setViewport(1366, 900);
  await go('/agent/rmm_fleet.php');
  await openInstaller();
  await setClient('1');
  await shot('installer-desktop-dark');
  await closeInstaller();
  await go('/agent/client_overview.php?client_id=1');
  await p.waitSel('[data-rmm-installer-open]');
  await shot('installer-clientpage-desktop-dark');
  await setTheme(0);
});

// ------------------------------------------------------------------------------------------------ phone width
await T('phone width (390 px): asset page has no horizontal scroll; tabs scroll inside their container', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go(asset('WIN1'));
  await p.waitSel('#rmm-strip');
  await sleep(600);
  await noOverflow('asset overview');
  assert(await p.eval(`(()=>{const t=document.querySelector('.rmm-tabbar');const s=getComputedStyle(t);return s.overflowX==='auto'||s.overflowX==='scroll'})()`), 'tab bar does not scroll in its own container');
  await shot('asset-overview-phone-light');
  await go(asset('WIN1') + '#rmm-inventory'); await p.waitSel('#rmm-pane-inventory table'); await sleep(300);
  await noOverflow('asset inventory');
  await shot('asset-inventory-phone-light');
  await go(asset('WIN1') + '#rmm-jobs'); await p.waitSel('#rmm-pane-jobs table'); await sleep(300);
  await noOverflow('asset jobs');
  await shot('asset-jobs-phone-light');
  // the visible reasons for disabled actions exist on a narrow screen (a tooltip cannot be hovered)
  await go(asset('NEVER')); await p.waitSel('#rmm-strip');
  assert(await p.visible('.rmm-reasons'), 'reason text for disabled buttons is not visible on a phone');
  await noOverflow('asset never');
});
await T('phone width (390 px): fleet page has no horizontal scroll', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  await sleep(400);
  await noOverflow('fleet');
  await shot('fleet-phone-light');
});

// ------------------------------------------------------------------------------------------------ dark mode
await T('dark mode: asset panel and fleet page (desktop and phone)', async () => {
  await p.setViewport(1366, 900);
  await setTheme(1);
  await go(asset('WIN1'));
  await p.waitSel('#rmm-strip');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const gaugeInk = await p.eval(`getComputedStyle(document.querySelector('#rmm-panel svg.rmm-gauge text')).fill`);
  const [r, g, b] = gaugeInk.match(/\d+/g).map(Number);
  assert(r + g + b > 3 * 120, 'gauge value text is not light in dark mode: ' + gaugeInk);
  await sleep(500);
  await shot('asset-overview-desktop-dark');
  await go(asset('WIN1') + '#rmm-jobs'); await p.waitSel('#rmm-pane-jobs table'); await shot('asset-jobs-desktop-dark');
  await go(asset('WIN1') + '#rmm-inventory'); await p.waitSel('#rmm-pane-inventory table'); await shot('asset-inventory-desktop-dark');
  await go('/agent/rmm_fleet.php'); await p.waitSel('#rmm-devices table'); await sleep(300); await shot('fleet-desktop-dark');
  await p.setViewport(MOBILE_W, 800, true);
  await go(asset('WIN1')); await p.waitSel('#rmm-strip'); await sleep(500); await noOverflow('asset dark phone'); await shot('asset-overview-phone-dark');
  await go('/agent/rmm_fleet.php'); await p.waitSel('#rmm-devices table'); await sleep(300); await noOverflow('fleet dark phone'); await shot('fleet-phone-dark');
  await p.setViewport(1366, 900);
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
