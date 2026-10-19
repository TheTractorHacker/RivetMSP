// Browser smoke for RMM software deployment and reporting (RivetCore 1.0.0-rc.21, Phase 5): the catalog, versions, allowed hosts, assignments, the Deployment tab of the asset
// page, the report and its CSV, a second person's approval, the administration card, and a technician limited to Client B.
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json                     # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8671 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_p21_software_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). Every check also fails on any uncaught error, console.error, failed first-party
// request or HTTP 4xx/5xx that occurred while it ran. The agent side (a device's answer to a detection) is written straight into the package tables by
// tests/browser/rmm_p21_software_extra.php; nothing is ever installed anywhere.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve, join } from 'node:path';
import { Browser } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: this smoke writes data and is meant for a throwaway install.`);
  process.exit(2);
}
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-p21', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const winAsset = SEED.asset.WIN1, lnxAsset = SEED.asset.LNX1;

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
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
const setVal = (sel, v) => p.evalWith((q, val) => { const e = document.querySelector(q); if (!e) throw new Error('no ' + q); e.value = val; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); return e.value; }, sel, String(v));
const optionByText = (sel, text) => p.evalWith((q, t) => { const o = [...document.querySelector(q).options].find((x) => x.textContent.includes(t)); if (!o) throw new Error('no option ' + t + ' in ' + q); return o.value; }, sel, text);
const unlabeled = (scope) => p.evalWith((s) => [...document.querySelectorAll(s + ' input, ' + s + ' select, ' + s + ' textarea')].filter((e) => {
  if (e.type === 'hidden' || e.disabled) return false; const r = e.getBoundingClientRect(); if (!(r.width > 0 && r.height > 0)) return false;
  return !((e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title'));
}).map((e) => e.name || e.id || e.tagName), scope);
const flash = async (re, label) => {
  try { await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) }); } catch (e) {
    throw new Error(e.message + ' | the page says: ' + String(await bodyText()).replace(/\s+/g, ' ').slice(0, 500));
  }
};
const extra = (mode) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p21_software_extra.php', process.env.SEED_JSON, mode], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const dialogGo = async () => { await p.waitSel('#rmmAutoConfirmGo'); await p.click('#rmmAutoConfirmGo'); };
const dialogText = () => p.eval(`document.getElementById('rmmAutoConfirmText').textContent`);
let fx = null;
const HOST = 'downloads.example.com';
const SHA = 'ab'.repeat(32);

await T('login: real form signs in', async () => { await login(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: software deployment switched on, the three software pages are in the menu', async () => {
  fx = extra('setup');
  assert(fx.ok, 'setup failed');
  await go('/agent/rmm_fleet.php');
  for (const h of ['rmm_software_catalog', 'rmm_software_assignments', 'rmm_software_report']) assert(await p.exists(`a[href="/agent/${h}.php"]`), 'menu entry missing: ' + h);
});

// ------------------------------------------------------------------------------------------------ catalog
await T('catalog: empty states; an administrator allows a download host through the form (stored in lower case)', async () => {
  await go('/agent/rmm_software_catalog.php');
  await trapDialogs();
  const t = await bodyText();
  assert(/No package yet/.test(t) && /No host is allowed yet/.test(t), 'empty states: ' + t.slice(0, 300));
  assert((await unlabeled('main, .page-body')).length === 0, 'unlabeled controls: ' + (await unlabeled('main, .page-body')).join(','));
  await shot('catalog-empty-desktop-light');
  await p.type('#sh_host', 'Downloads.Example.com');
  await p.type('#sh_note', 'Vendor CDN');
  await p.clickNav('#hosts form button[type=submit]');
  await flash(/Host allowed/, 'host allowed');
  const h = await p.eval(`document.getElementById('hosts').innerText`);
  assert(new RegExp(HOST.replace(/\./g, '\\.')).test(h) && /Vendor CDN/.test(h), 'host list: ' + h);
});
await T('catalog: a package is created, then an apt and an MSI version through the form; the format decides which fields show', async () => {
  await go('/agent/rmm_software_catalog.php?new=1');
  await p.waitSel('#rmm-sw-package-form');
  await p.type('#sp_name', 'Acme <b>Agent</b>');
  await p.type('#sp_pub', 'Acme "Inc" & Co');
  await p.clickNav('#rmm-sw-package-form button[type=submit]');
  await flash(/Package created/, 'package created');
  assert(/package_id=\d+/.test(await p.url()), 'did not land on the package: ' + (await p.url()));
  assert((await p.eval(`document.querySelectorAll('main b, .page-body b').length`)) === 0, 'the name became markup');
  // apt: only the package name fields
  await p.waitSel('#rmm-sw-version-form');
  assert(await p.visible('#sv_pkg') && !(await p.visible('#sv_url')) && !(await p.visible('#sv_sha')), 'apt shows the package name and no address or hash');
  await setVal('#sv_fmt', 'apt');
  await p.type('#sv_ver', '3.3.0');
  await p.type('#sv_pkg', 'htop');
  await p.clickNav('#rmm-sw-version-form button[type=submit]');
  await flash(/Version added/, 'apt version added');
  // msi: address, hash, size and product code; the package name fields are gone
  await setVal('#sv_fmt', 'msi');
  assert(await p.visible('#sv_url') && await p.visible('#sv_sha') && await p.visible('#sv_pc') && !(await p.visible('#sv_pkg')) && !(await p.visible('#sv_args')), 'msi shows address, hash and product code only');
  await p.type('#sv_ver', '2.4.1');
  await p.type('#sv_url', `http://${HOST}/app.msi`);
  await p.type('#sv_sha', SHA.toUpperCase());
  await p.type('#sv_size', '1048576');
  await p.type('#sv_pc', '{12345678-abcd-1234-abcd-1234567890ab}');
  await p.clickNav('#rmm-sw-version-form button[type=submit]');
  await flash(/https/, 'plain http refused with Core\'s words');
  assert(/plain https address/.test(await bodyText()), 'refusal words: ' + (await bodyText()).slice(0, 200));
  await setVal('#sv_fmt', 'msi');
  await p.type('#sv_ver', '2.4.1');
  await p.type('#sv_url', `https://${HOST}/app.msi`);
  await p.type('#sv_sha', SHA.toUpperCase());
  await p.type('#sv_size', '1048576');
  await p.type('#sv_pc', '{12345678-abcd-1234-abcd-1234567890ab}');
  await shot('catalog-version-form-desktop-light');
  await p.clickNav('#rmm-sw-version-form button[type=submit]');
  await flash(/Version added/, 'msi version added');
  const v = await p.eval(`document.getElementById('versions').innerText`);
  assert(/2\.4\.1/.test(v) && /3\.3\.0/.test(v) && /1048576 bytes/.test(v) && /\{12345678-ABCD-1234-ABCD-1234567890AB\}/.test(v) && /htop/.test(v), 'versions card: ' + v.slice(0, 400));
  assert(await p.exists(`#versions [data-rmm-copy="${SHA}"]`), 'the whole hash is not available to copy');
  await shot('catalog-package-desktop-light');
});
await T('catalog: Retire asks first (dialog), cancelling keeps the version; confirming retires it, Restore brings it back', async () => {
  await trapDialogs();
  const retire = '#versions form button[data-rmm-confirm]';
  await p.waitSel(retire);
  await p.click(retire);
  assert(/never installed again/.test(await dialogText()), 'the dialog does not say what retiring does');
  await p.eval(`document.querySelector('#rmmAutoConfirm .btn-outline-secondary').click()`);
  await p.waitFor(`!document.getElementById('rmmAutoConfirm').classList.contains('show')`, { label: 'dialog closed' });
  assert(!/Retired/.test(await p.eval(`document.getElementById('versions').innerText`)), 'cancelling retired the version');
  assert((await p.eval(`window.__dialogs`)) === 0, 'a native dialog was used');
  await p.click(retire);
  await dialogGo();
  await flash(/Version retired/, 'retired');
  assert(/Retired/.test(await p.eval(`document.getElementById('versions').innerText`)), 'no Retired pill');
  await p.clickNav('#versions form button:not([data-rmm-confirm])');
  await flash(/Version restored/, 'restored');
  assert(!/Retired/.test(await p.eval(`document.getElementById('versions').innerText`)), 'still retired');
});

// ------------------------------------------------------------------------------------------------ assignments
await T('assignments: ring delays are shown; Assign asks first; the assignment is live and listed', async () => {
  await go('/agent/rmm_software_assignments.php');
  await trapDialogs();
  const t = await bodyText();
  assert(/Nothing is assigned yet/.test(t) && /pilot: at once/.test(t) && /broad: after 7 days/.test(t) && /last: after 14 days/.test(t), 'empty state and rings: ' + t.slice(0, 400));
  assert((await unlabeled('#rmm-sw-assign-form')).length === 0, 'unlabeled controls: ' + (await unlabeled('#rmm-sw-assign-form')).join(','));
  await setVal('#sa_pkg', await optionByText('#sa_pkg', 'Acme'));
  await setVal('#sa_type', 'client');
  await p.waitSel('#sa_id_client');
  await setVal('#sa_id_client', await optionByText('#sa_id_client', 'Client A'));
  await shot('assignments-form-desktop-light');
  await p.click('#rmm-sw-assign-form button[type=submit]');
  assert(/Assign this package/.test(await dialogText()), 'the dialog does not ask');
  await p.eval(`document.querySelector('#rmmAutoConfirm .btn-outline-secondary').click()`);
  await p.waitFor(`!document.getElementById('rmmAutoConfirm').classList.contains('show')`, { label: 'cancelled' });
  assert(!/Client Client A/.test(await bodyText()), 'cancelling assigned');
  await p.click('#rmm-sw-assign-form button[type=submit]');
  await dialogGo();
  await flash(/Assigned\./, 'assigned');
  const l = await bodyText();
  assert(/Client Client A/.test(l) && /Live/.test(l) && /newest that fits/.test(l), 'listed: ' + l.slice(0, 300));
  await shot('assignments-desktop-light');
});

// ------------------------------------------------------------------------------------------------ one device
await T('asset page: #rmm-deploy opens the Deployment tab; Check now queues a read-only job; the device answers; the package shows as missing', async () => {
  await go(`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`);
  await trapDialogs();
  await p.waitFor(`document.getElementById('rmm-tab-deploy') && document.getElementById('rmm-tab-deploy').getAttribute('aria-selected') === 'true'`, { label: 'Deployment tab from the hash' });
  let t = await p.eval(`document.getElementById('rmm-pane-deploy').innerText`);
  assert(/Live installs are off/.test(t) && /Check now/.test(t), 'tab: ' + t.slice(0, 300));
  await p.clickNav('#rmm-pane-deploy form button.btn-outline-primary');
  await flash(/Check requested/, 'check requested');
  const a = extra('answer');
  assert(a.jobs.some((j) => j.startsWith('software_detect')) && a.expected === 1, 'the detection was not queued / evaluated: ' + JSON.stringify(a));
  extra('clear');
  extra('answer');
  await go(`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`);
  await p.waitSel('#rmm-pane-deploy table');
  t = await p.eval(`document.getElementById('rmm-pane-deploy').innerText`);
  assert(/Acme/.test(t) && /Missing/.test(t) && /Not installed/.test(t), 'missing state: ' + t.slice(0, 400));
  assert((await unlabeled('#rmm-pane-deploy')).length === 0, 'unlabeled controls in the tab: ' + (await unlabeled('#rmm-pane-deploy')).join(','));
  assert(await p.exists('#rmm-pane-deploy table caption.visually-hidden') && (await p.eval(`document.querySelectorAll('#rmm-pane-deploy th[scope=col]').length`)) >= 6, 'table is not accessible');
  await noOverflow('Deployment tab (desktop)');
  await shot('deploy-tab-desktop-light');
});
await T('asset page: a dry run is queued as a dry run; Run now needs its box and a dialog, then queues a real install of the MSI', async () => {
  await go(`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`);
  await p.waitSel('#rmm-sw-form');
  await setVal('#sw_pkg', await optionByText('#sw_pkg', 'Acme'));
  await p.clickNav('#rmm-sw-form button[value=dry_run]');
  await flash(/Dry run queued/, 'dry run');
  let a = extra('answer');
  assert(a.jobs.includes('software_install:dry_run:msi'), 'dry run job: ' + JSON.stringify(a));
  extra('clear');
  extra('answer');
  await go(`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`);
  await p.waitSel('#rmm-sw-form button[value=run]');
  await setVal('#sw_pkg', await optionByText('#sw_pkg', 'Acme'));
  await p.click('#rmm-sw-form button[value=run]');
  assert(/never receives an address or argument typed here/.test(await dialogText()), 'the dialog does not say what is installed');
  await shot('deploy-run-dialog-desktop-light');
  await dialogGo();
  await flash(/Tick the confirmation box/, 'refusal without the box');
  await p.waitSel('#sw_confirm');
  await setVal('#sw_pkg', await optionByText('#sw_pkg', 'Acme'));
  await p.click('#sw_confirm');
  await p.click('#rmm-sw-form button[value=run]');
  await dialogGo();
  await flash(/Queued\./, 'run queued');
  a = extra('answer');
  assert(a.jobs.includes('software_install:run:msi') && !a.jobs.includes('software_install:dry_run:msi'), 'real install job: ' + JSON.stringify(a));
  extra('clear');
});

// ------------------------------------------------------------------------------------------------ report
await T('report page: every grouping renders, the CSV downloads as text/csv with its header, no horizontal scroll on a phone', async () => {
  extra('answer');
  for (const by of ['package', 'client', 'ring', 'device']) {
    await go(`/agent/rmm_software_report.php?group_by=${by}`);
    const t = await bodyText();
    assert(/Software report/.test(t) && /Acme/.test(t), `group by ${by}: ` + t.slice(0, 300));
  }
  await go('/agent/rmm_software_report.php?group_by=package');
  assert((await unlabeled('main, .page-body')).length === 0, 'unlabeled controls: ' + (await unlabeled('main, .page-body')).join(','));
  const csv = await p.eval(`fetch('/agent/rmm_software_report_csv.php?group_by=package', {credentials:'same-origin'}).then(async r => ({status:r.status, type:r.headers.get('content-type'), disp:r.headers.get('content-disposition'), text: await r.text()}))`);
  assert(csv.status === 200 && /^text\/csv/.test(csv.type) && /attachment/.test(csv.disp) && /^key,label,expected,/.test(csv.text) && /Acme/.test(csv.text), 'csv: ' + JSON.stringify(csv).slice(0, 300));
  assert(await p.exists('a[href^="/agent/rmm_software_report_csv.php"]'), 'no Download CSV link');
  await noOverflow('report (desktop)');
  await shot('report-desktop-light');
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_software_report.php?group_by=device');
  await noOverflow('report (phone)');
  await shot('report-phone-light');
  await p.setViewport(1366, 900);
});

// ------------------------------------------------------------------------------------------------ two people
await T('two people: the package is flagged; a technician assigns and it waits; the administrator approves on the Approvals page', async () => {
 try {
  await go('/agent/rmm_software_catalog.php');
  await go((await p.eval(`document.querySelector('a[href*="package_id="]').getAttribute('href')`)));
  await p.waitSel('#sp_two');
  await p.click('#sp_two');
  await p.clickNav('#rmm-sw-package-form button[type=submit]');
  await flash(/Package saved/, 'flag saved');
  await login('tech@scratch.test', 'Scratch-Tech-1234');
  await go('/agent/rmm_software_assignments.php');
  await setVal('#sa_pkg', await optionByText('#sa_pkg', 'Acme'));
  await setVal('#sa_type', 'device');
  await p.waitSel('#sa_id_device');
  await setVal('#sa_id_device', await optionByText('#sa_id_device', 'WIN1'));
  await p.click('#rmm-sw-assign-form button[type=submit]');
  await dialogGo();
  await flash(/second person/, 'sent for approval');
  assert(/Waiting for a second person/.test(await bodyText()), 'the list does not say it is waiting');
  await go('/agent/rmm_approvals.php?state=pending_approval&kind=software');
  await p.waitSel('.rmm-approval-detail summary');
  await p.eval(`document.querySelectorAll('.rmm-approval-detail details').forEach(d=>d.open=true)`);
  assert(!(await p.exists('button[name=decision][value=approve]:not([disabled])')) && /Cancel this request/.test(await bodyText()), 'the requester is offered an Approve button, or cannot cancel their own request');
  await login(EMAIL, PASSWORD);
  await go('/agent/rmm_approvals.php?state=pending_approval&kind=software');
  await p.waitSel('.rmm-approval-detail summary');
  await p.eval(`document.querySelectorAll('.rmm-approval-detail details').forEach(d=>d.open=true)`);
  const t = await bodyText();
  assert(/Software/.test(t) && /Acme/.test(t) && /What approving does/.test(t), 'approval row: ' + t.slice(0, 400));
  await shot('approvals-software-desktop-light');
  await p.click('button[name=decision][value=approve]');
  assert(/Approve this request/.test(await dialogText()), 'the dialog does not ask');
  await dialogGo();
  await flash(/Approved/, 'approved');
  await go('/agent/rmm_software_assignments.php');
  assert(!/Waiting for a second person/.test(await bodyText()), 'still waiting after approval');
 } finally { await login(EMAIL, PASSWORD).catch(() => {}); }
}, { allowHttp: [/\/agent\/clients\.php/, /\/agent\/rmm_/] });

// ------------------------------------------------------------------------------------------------ phone, dark
await T('phone width and dark mode keep their layout on the catalog, assignments and the Deployment tab', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const [path, label] of [['/agent/rmm_software_catalog.php', 'catalog'], ['/agent/rmm_software_assignments.php', 'assignments'], [`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`, 'Deployment tab']]) {
    await go(path);
    await noOverflow(label + ' (phone)');
  }
  await shot('assignments-phone-light');
  await p.setViewport(1366, 900);
  await setTheme('1');
  await go(`/agent/asset_details.php?asset_id=${winAsset}#rmm-deploy`);
  await p.waitSel('#rmm-pane-deploy');
  await shot('deploy-tab-desktop-dark');
  await go('/agent/rmm_software_catalog.php');
  await shot('catalog-desktop-dark');
  await setTheme('0');
});

// ------------------------------------------------------------------------------------------------ administration
await T('administration: the Software deployment card; live installs need the acknowledgement, the limits save', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-software');
  await trapDialogs();
  await p.waitSel('#software-deployment');
  assert(/about once a day/.test(await p.eval(`document.getElementById('software-deployment').innerText`)) && /has not been run on a real machine/.test(await p.eval(`document.getElementById('software-deployment').innerText`)), 'cost or Windows text missing');
  await p.eval(`(()=>{const c=document.querySelector('#ea_sd_live');c.scrollIntoView();c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.clickNav('#software-deployment button[name=save_software_settings]');
  await flash(/tick the confirmation box/, 'live install refusal');
  await go('/admin/settings_endpoint_agent.php#tab-software');
  await p.waitSel('#ea_sd_h');
  await p.type('#ea_sd_h', '12');
  await p.clickNav('#software-deployment button[name=save_software_settings]');
  await flash(/Software deployment settings saved/, 'saved');
  assert((await p.eval(`document.querySelector('#ea_sd_h').value`)) === '12' && !(await p.eval(`document.querySelector('#ea_sd_live').checked`)), 'saved values');
  await shot('admin-card-desktop-light');
});

// ------------------------------------------------------------------------------------------------ a technician limited to Client B
await T('Client B technician: sees nothing of Client A on the assignments page, the report or a Client A asset', async () => {
 try {
  await login('clientb@scratch.test', 'Scratch-Clientb-1234');
  await go('/agent/rmm_software_assignments.php');
  let t = await bodyText();
  assert(!/Client Client A/.test(t) && !/WIN1/.test(t), 'assignments leak Client A: ' + t.slice(0, 300));
  await go('/agent/rmm_software_report.php?group_by=device');
  t = await bodyText();
  assert(!/WIN1/.test(t) && !/Client A/.test(t.replace(/All clients you can see/, '')), 'report leaks Client A');
  await go(`/agent/asset_details.php?asset_id=${winAsset}`);
  assert(!(await p.exists('#rmm-tab-deploy')) && !/Acme/.test(await bodyText()), 'a Client A asset page shows deployment to a Client B user');
  await go(`/agent/asset_details.php?asset_id=${lnxAsset}#rmm-deploy`);
  assert(await p.exists('#rmm-tab-deploy'), 'their own device has no Deployment tab');
  await shot('clientb-deploy-desktop-light');
 } finally { await login(EMAIL, PASSWORD).catch(() => {}); }
}, { allowHttp: [/\/agent\/asset_details\.php/, /\/agent\/clients\.php/, /\/agent\/rmm_/] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
