// Screenshots for the Endpoints (RMM) guide pages:
//   25-endpoints-rmm-overview.md          -> images/endpoints/25-*
//   26-rmm-devices-jobs-policies.md       -> images/endpoints/26-*
//   27-rmm-patching-software-remote.md    -> images/endpoints/27-*
//   28-alerts-backups-network-panel.md    -> images/endpoints/28-*
//
// Run:  cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/endpoints.cjs
//       (GUIDE_CLEAN=1 writes the call-out-free copies to images-clean/endpoints/; ONLY=25,27 limits the pages; DEMO_URL points at another copy)
//
// Needs seeds 65 to 69 (fake devices, automation, patching/software/logs/hypervisors, Mesh and RivetPanel, vendor RMM / UniFi / Comet rows).
// EXAMPLE DATA, NOT A LIVE CONNECTION: there is no real agent, MeshCentral, Tactical RMM, Sophos, UniFi or Comet server in the sandbox.
// READ-ONLY: it opens pages and dialogs, types illustrative values into a few forms and closes them again; nothing is saved.
// The Backups page and the Comet settings read a Comet server live, so this script starts a tiny mock on 127.0.0.1:18165 (the URL
// seed 69 stores) for the duration of the run and stops it again at the end. Hosts and clients are found by NAME, never by id.

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { launch, login, goto, callout, clearCallouts, settle, OUT, CLEAN } = require('../lib.cjs');

const G = 'endpoints';
const MOCK_PORT = 18165;
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const want = (p) => ONLY.length === 0 || ONLY.includes(String(p));
const failures = [];

// ------------------------------------------------------------------------------------------------ mock Comet server
const sha = (algo, s) => crypto.createHash(algo).update(s).digest('hex');
const cometDevId = (name) => sha('sha256', 'lakeshore-comet-' + name);
const nowS = () => Math.floor(Date.now() / 1000);
const GB = 1024 ** 3, MB = 1024 ** 2;
// Comet user -> devices: [name, registered days ago, job] where job = [status, minutes ago, bytes, error] or null (no backup yet)
const COMET = {
  'lakeshore-lakeview':  [['LT-LFC-01', 200, [5000, 190, 1.8 * GB, '']]],
  'lakeshore-birchwood': [['LT-BLP-02', 200, [5000, 300, 2.6 * GB, '']]],
  'lakeshore-northshore': [['DT-NSD-01', 200, [5000, 26 * 60, 640 * MB, '']]],
  'lakeshore-kestrel':   [['DT-KPM-03', 200, [5000, 74 * 60, 910 * MB, '']]],
  'lakeshore-granite':   [['PC-GPM-07', 200, [5000, 360, 420 * MB, '']], ['WS-GPM-11', 200, [6001, 10, 85 * MB, '']],
                          ['SRV-GPM-FILE01', 200, [7002, 380, 0, 'VSS snapshot failed while reading D:\\Shares (access is denied)']]],
  'lakeshore-ironwood':  [['DT-IWL-05', 200, [5000, 30 * 60, 510 * MB, '']], ['LT-IWL-12', 60, null]],
  'lakeshore-cedar':     [['LT-CPA-04', 200, [7001, 300, 14.2 * GB, '3 files were skipped because another program had them open']],
                          ['SRV-CPA-APP02', 200, [5000, 120, 22.7 * GB, '']], ['MAC-CPA-03', 90, [5000, 240, 3.1 * GB, '']]],
};
function cometUsers() {
  const out = {};
  for (const [user, devs] of Object.entries(COMET)) {
    out[user] = { Username: user, Devices: {} };
    for (const [name, regDays] of devs) out[user].Devices[cometDevId(name)] = { FriendlyName: name, RegistrationTime: nowS() - regDays * 86400 };
  }
  return out;
}
function cometJobs(user) {
  const jobs = [];
  for (const [name, , job] of COMET[user] || []) {
    if (!job) continue;
    const [status, minAgo, size, err] = job;
    const start = nowS() - minAgo * 60;
    jobs.push({ DeviceID: cometDevId(name), Username: user, Classification: 4001, Status: status, StartTime: start, EndTime: status === 6001 ? 0 : start + 420, UploadSize: Math.round(size), TotalSize: Math.round(size), ErrorString: err });
    jobs.push({ DeviceID: cometDevId(name), Username: user, Classification: 4001, Status: 5000, StartTime: start - 86400, EndTime: start - 86400 + 400, UploadSize: 400 * MB, TotalSize: 400 * MB, ErrorString: '' });
  }
  return jobs;
}
function startMock() {
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      const json = (o, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
      const url = req.url.split('?')[0];
      const form = new URLSearchParams(body);
      if (url === '/api/v1/admin/meta/version') return json({ Version: '24.9.1' });
      if (url === '/api/v1/admin/list-users-full') return json(cometUsers());
      if (url === '/api/v1/admin/get-jobs-for-user') return json(cometJobs(form.get('TargetUser')));
      if (url === '/api/v1/admin/get-jobs-recent') return json([]);
      if (url === '/api/v1/admin/account/session-start') return json({ SessionKey: 'demo-session-key' });
      if (url === '/agents/' || url === '/alerts/') return json([]);
      json({ detail: 'Not found' }, 404);
    });
  });
  return new Promise((resolve, reject) => {
    server.once('error', (e) => reject(new Error(`Cannot start the mock on port ${MOCK_PORT}: ${e.message}`)));
    server.listen(MOCK_PORT, '127.0.0.1', () => resolve(server));
  });
}

// ------------------------------------------------------------------------------------------------ helpers
async function clean(page, label) {
  const html = await page.content();
  if (/Fatal error|Parse error|<b>Warning<\/b>|<b>Notice<\/b>|Uncaught |mysqli_sql_exception|Stack trace|undefined index|Undefined (variable|array)/i.test(html)) throw new Error(`PHP error text on page: ${label}`);
}
async function open(page, url, expect) {
  await goto(page, url);
  await clean(page, url);
  if (expect) {
    const text = await page.locator('body').innerText();
    for (const s of [].concat(expect)) if (!text.includes(s)) throw new Error(`${url}: expected "${s}"`);
  }
}
async function mark(locator, name) {
  if ((await locator.count()) === 0) throw new Error(`mark(${name}): element not found`);
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), name);
  return `[data-ug="${name}"]`;
}
/** Screenshot: viewport, element (selector) or a clip rectangle. Honours GUIDE_CLEAN like lib.shot(). */
async function snap(page, rel, { selector = null, clip = null, fullPage = false, delay = 500 } = {}) {
  const file = path.join(OUT, G, rel + '.png');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.mouse.move(2, 2);
  await settle(page, delay);
  if (selector) await page.locator(selector).first().screenshot({ path: file });
  else if (clip) await page.screenshot({ path: file, clip, fullPage: true });
  else if (fullPage) {
    const vp = page.viewportSize();
    const h = await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body.scrollHeight));
    const target = Math.min(Math.max(h, vp.height), 4200);
    if (target > vp.height) { await page.setViewportSize({ width: vp.width, height: target }); await settle(page, 350); }
    await page.screenshot({ path: file });
    if (target > vp.height) { await page.setViewportSize(vp); await settle(page, 150); }
  } else await page.screenshot({ path: file });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), file));
}
async function step(label, fn) {
  try { await fn(); } catch (e) { failures.push(`${label}: ${e.message.split('\n')[0]}`); console.log('FAIL', label, e.message.split('\n')[0]); }
}
const MODAL = '.modal.show .modal-content';
async function waitModal(page, ms = 900) { await page.waitForSelector(MODAL, { timeout: 15000 }); await page.waitForTimeout(ms); }
async function closeModal(page) { await page.keyboard.press('Escape'); await page.waitForTimeout(500); await page.mouse.move(2, 2); }
async function tall(page, h, fn) {
  const vp = page.viewportSize();
  await page.setViewportSize({ width: vp.width, height: h });
  await page.waitForTimeout(400);
  try { await fn(); } finally { await page.setViewportSize(vp); await page.waitForTimeout(300); }
}
async function scrollTop(locator, offset = 80) {
  await locator.evaluate((el, o) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - o), offset);
  await locator.page().waitForTimeout(400);
}
const txt = (page, t, sel = '*') => page.locator(sel, { hasText: t });
/** Scroll an element to the top of the viewport (so a viewport screenshot starts at it). */
async function top(page, selector, offset = 70) {
  await page.evaluate(([s, o]) => { const el = document.querySelector(s); if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - o); }, [selector, offset]);
  await page.waitForTimeout(300);
}
/** The asset page of a device, found through the Agent fleet list by host name. */
async function deviceUrl(page, host) {
  await goto(page, '/agent/rmm_fleet.php');
  const href = await page.locator('a', { hasText: new RegExp('^\\s*' + host + '\\s*$') }).first().getAttribute('href');
  if (!href) throw new Error('no link for ' + host);
  return href;
}
async function pane(page, name) {
  await page.locator(`[data-bs-target="#rmm-pane-${name}"]`).first().click();
  await page.waitForTimeout(700);
}
async function eaTab(page, key) {
  await page.locator(`#ea-tab-${key}`).click();
  await page.waitForTimeout(500);
}

// ================================================================================================ page 25: overview, fleet, add device
async function p25(page) {
  await step('25-01 sidebar', async () => {
    await open(page, '/agent/rmm_dashboard.php', 'RMM Dashboard');
    await page.setViewportSize({ width: 1440, height: 1500 });
    await settle(page, 300);
    const side = page.locator('a', { hasText: /^\s*Endpoints\s*$/ }).first();
    await callout(page, [{ selector: await mark(side, 'sb-endpoints'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('a', { hasText: /^\s*Agent Fleet\s*$/ }).first(), 'sb-fleet'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('a', { hasText: /^\s*Alerts/ }).first(), 'sb-alerts'), n: 3, side: 'tr' }]);
    await snap(page, '25-01-sidebar', { clip: { x: 0, y: 0, width: 330, height: 1500 } });
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  });
  await step('25-02 module switch', async () => {
    await open(page, '/admin/settings_endpoint_agent.php', 'RMM module');
    await callout(page, [
      { selector: await mark(page.locator('.card', { hasText: 'RMM module' }).filter({ hasText: 'Switch the RMM module off' }).last(), 'ea-module'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('#ea-tablist'), 'ea-tabs'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.badge', { hasText: 'Online' }).first(), 'ea-counts'), n: 3 }]);
    await snap(page, '25-02-endpoint-agent-settings');
    await clearCallouts(page);
  });
  await step('25-03 service and defaults', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    const card = page.locator('.card', { hasText: 'Service and defaults' }).last();
    await card.scrollIntoViewIfNeeded();
    await callout(page, [
      { selector: await mark(page.locator('#ea_service_url, input[name=service_url]').first(), 'sd-url'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('input[name=offline_after_s], input[name=offline_after]').first(), 'sd-offline'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('select[name=unmatched_policy]'), 'sd-unmatched'), n: 3, side: 'tr' }]);
    await snap(page, '25-03-service-defaults', { selector: await mark(card, 'sd-card') });
    await clearCallouts(page);
  });
  await step('25-04 vendor rmm settings', async () => {
    await open(page, '/admin/settings_integrations.php?tab=rmm', 'RMM Integration Settings');
    await callout(page, [
      { selector: await mark(page.locator('.nav-tabs a, .nav-link', { hasText: /^\s*RMM\s*$/ }).first(), 'vr-tab'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('text=Enable RMM module').first().locator('xpath=ancestor::*[contains(@class,"form-check") or contains(@class,"card")][1]'), 'vr-enable'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Automatic Ticket Creation' }).last(), 'vr-auto'), n: 3, side: 'tr' }]);
    await snap(page, '25-04-vendor-rmm-settings');
    await clearCallouts(page);
  });
  await step('25-05 dashboard top', async () => {
    await open(page, '/agent/rmm_dashboard.php', 'RMM Dashboard');
    await callout(page, [
      { selector: await mark(page.locator('a.btn', { hasText: 'Add device' }).first(), 'd-add'), n: 1 },
      { selector: await mark(page.locator('.info-box').first(), 'd-tiles'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Fleet Health' }).last(), 'd-fleet'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Patch Compliance' }).last(), 'd-patch'), n: 4, side: 'tr' }]);
    await snap(page, '25-05-dashboard');
    await clearCallouts(page);
  });
  await step('25-06 dashboard lower', async () => {
    await open(page, '/agent/rmm_dashboard.php');
    await scrollTop(page.locator('.card', { hasText: 'Offline Assets' }).last());
    await callout(page, [
      { selector: await mark(page.locator('.card', { hasText: 'Offline Assets' }).last(), 'd-offline'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'New Alerts' }).last(), 'd-alerts'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Client Health' }).last(), 'd-clients'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Recent Script Runs' }).last(), 'd-runs'), n: 4, side: 'tr' }]);
    await snap(page, '25-06-dashboard-lower');
    await clearCallouts(page);
  });
  await step('25-07 fleet top', async () => {
    await open(page, '/agent/rmm_fleet.php', 'Agent fleet');
    await callout(page, [
      { selector: await mark(page.locator('a.btn', { hasText: 'Add device' }).first(), 'f-add'), n: 1 },
      { selector: await mark(page.locator('a.btn, button.btn', { hasText: 'Quick download' }).first(), 'f-quick'), n: 2 },
      { selector: await mark(page.locator('.card', { hasText: 'Devices needing approval' }).last(), 'f-approval'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Offline and stale' }).last(), 'f-offline'), n: 4, side: 'tr' }]);
    await snap(page, '25-07-fleet');
    await clearCallouts(page);
  });
  await step('25-08 fleet devices', async () => {
    await open(page, '/agent/rmm_fleet.php');
    const h = page.locator('.card', { hasText: 'Devices' }).filter({ has: page.locator('table') }).last();
    await scrollTop(h);
    await callout(page, [
      { selector: await mark(page.locator('input[placeholder="Hostname or serial"]'), 'f-filter'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('span.badge', { hasText: 'Approval' }).first(), 'f-badge'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('span.badge', { hasText: 'Never seen' }).first(), 'f-never'), n: 3, side: 'tr' }]);
    await snap(page, '25-08-fleet-devices');
    await clearCallouts(page);
  });
  await step('25-09 add device windows', async () => {
    await open(page, '/agent/rmm_add_device.php', 'Add device');
    await page.selectOption('select[name=client_id]', { label: 'Granite Peak Manufacturing' }).catch(() => {});
    await settle(page, 400);
    await callout(page, [
      { selector: await mark(page.locator('select').first(), 'ad-client'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('select', { hasText: 'Stable' }).first(), 'ad-ring'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('a, button', { hasText: 'Download .exe' }).first(), 'ad-exe'), n: 3, side: 'tr' }]);
    await snap(page, '25-09-add-device', { fullPage: true });
    await clearCallouts(page);
  });
  await step('25-10 add device linux', async () => {
    await open(page, '/agent/rmm_add_device.php');
    await page.selectOption('select[name=client_id]', { label: 'Ironwood Logistics' }).catch(() => {});
    await page.evaluate(() => { const h = [...document.querySelectorAll('h2,h3,h4,h5,.card-title')].find((e) => /^\s*3\.\s*Linux|Linux/.test(e.textContent)); if (h) window.scrollTo(0, h.getBoundingClientRect().top + window.scrollY - 80); });
    await page.waitForTimeout(500);
    await snap(page, '25-10-add-device-linux');
  });
  await step('25-11 waiting for approval', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    const card = page.locator('.card').filter({ has: page.locator('.card-title, h3, h4', { hasText: /^Waiting for approval/ }) }).first();
    await scrollTop(card, 90);
    await callout(page, [
      { selector: await mark(page.locator('select[name=asset_id], select').filter({ hasText: 'Choose an asset' }).first(), 'wa-select'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('button', { hasText: 'Link to the chosen asset' }), 'wa-link'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('button', { hasText: 'Create a new asset' }), 'wa-new'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('button', { hasText: 'Reject' }).first(), 'wa-reject'), n: 4, side: 'tr' }]);
    await snap(page, '25-11-waiting-approval', { selector: await mark(card, 'wa-card') });
    await clearCallouts(page);
  });
  await step('25-12 enrollment tokens', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'security');
    const card = page.locator('#tokens');
    await scrollTop(card, 70);
    await callout(page, [
      { selector: await mark(card.locator('table').first(), 'tk-table'), n: 1, side: 'tr' }]);
    await snap(page, '25-12-enrollment-tokens', { selector: await mark(card, 'tk-card') });
    await clearCallouts(page);
  });
  await step('25-13 agent updates tab', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'updates');
    await snap(page, '25-13-agent-updates-tab');
  });
  await step('25-14 checks tab', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'checks');
    await snap(page, '25-14-checks-tab');
  });
}

// ================================================================================================ page 26: devices, jobs, policies, scripts, schedules, alerts
async function p26(page) {
  let dev;
  await step('26-00 find device', async () => { dev = await deviceUrl(page, 'LT-CPA-31'); });
  if (!dev) return;
  await step('26-01 device overview', async () => {
    await open(page, dev, 'LT-CPA-31');
    await callout(page, [
      { selector: await mark(page.locator('a, span', { hasText: /^\s*Online\s*$/ }).first(), 'dv-status'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('button, a', { hasText: 'Run script' }).first(), 'dv-run'), n: 2 },
      { selector: await mark(page.locator('button, a', { hasText: /^\s*Reboot\s*$/ }).first(), 'dv-reboot'), n: 3 },
      { selector: await mark(page.locator('[data-bs-target="#rmm-pane-overview"]').first().locator('xpath=ancestor::ul[1]'), 'dv-tabs'), n: 4, side: 'tr' }]);
    await snap(page, '26-01-device-overview');
    await clearCallouts(page);
  });
  await step('26-02 device health and checks', async () => {
    await open(page, dev);
    await scrollTop(page.locator('text=LIVE HEALTH').first(), 120);
    await tall(page, 1300, async () => {
      await scrollTop(page.locator('text=LIVE HEALTH').first(), 120);
      await snap(page, '26-02-device-health');
    });
  });
  await step('26-03 device checks list', async () => {
    await open(page, dev);
    const card = page.locator('.card', { hasText: 'Checks' }).filter({ hasText: 'LAST RESULT' }).last();
    await scrollTop(card, 90);
    await callout(page, [{ selector: await mark(card, 'ck-card'), n: 1, side: 'tr' }]);
    await snap(page, '26-03-device-checks');
    await clearCallouts(page);
  });
  await step('26-04 jobs tab', async () => {
    await open(page, dev);
    await pane(page, 'jobs');
    await scrollTop(page.locator('#rmm-pane-jobs'), 150);
    await snap(page, '26-04-device-jobs');
  });
  await step('26-05 run script dialog', async () => {
    await open(page, dev);
    await page.locator('button, a', { hasText: 'Run script' }).first().click();
    await waitModal(page);
    await callout(page, [
      { selector: await mark(page.locator('#rmm-run-type'), 'rs-what'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('#rmm-run-go'), 'rs-go'), n: 2, side: 'tr' }]);
    await snap(page, '26-05-run-script-dialog', { selector: MODAL });
    await clearCallouts(page);
    await closeModal(page);
  });
  await step('26-06 policy and fields tab', async () => {
    await open(page, dev);
    await pane(page, 'policy');
    await scrollTop(page.locator('#rmm-pane-policy'), 150);
    await snap(page, '26-06-device-policy-fields');
  });
  await step('26-07 alerting tab', async () => {
    await open(page, dev);
    await pane(page, 'alerting');
    await scrollTop(page.locator('#rmm-pane-alerting'), 150);
    await snap(page, '26-07-device-alerting');
  });
  await step('26-08 policies list', async () => {
    await open(page, '/agent/rmm_policies.php', 'Policies');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New policy' }).first(), 'po-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Granite Peak plant floor' }).first().locator('td').nth(2), 'po-state'), n: 3 },
      { selector: await mark(page.locator('tr', { hasText: 'Granite Peak plant floor' }).first().locator('td').nth(3), 'po-assigned'), n: 4 }]);
    await snap(page, '26-08-policies');
    await clearCallouts(page);
  });
  await step('26-08b starter policies card', async () => {
    await open(page, '/agent/rmm_policies.php');
    await snap(page, '26-08b-starter-policies', { selector: '#policy-starter-pack' });
  });
  await step('26-09 policy edit', async () => {
    await open(page, '/agent/rmm_policies.php');
    await page.locator('tr', { hasText: 'Granite Peak plant floor' }).first().locator('a, button', { hasText: 'Edit' }).first().click();
    await page.waitForTimeout(900);
    await settle(page, 500);
    await snap(page, '26-09-policy-edit', { fullPage: true });
  });
  await step('26-10 scripts library', async () => {
    await open(page, '/agent/rmm_scripts.php', 'Scripts');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New script' }).first(), 'sc-new'), n: 1 },
      { selector: await mark(page.locator('a, button', { hasText: 'Starter pack' }).first(), 'sc-starter'), n: 2 },
      { selector: await mark(page.locator('button', { hasText: 'Filter' }).first(), 'sc-filter'), n: 3 },
      { selector: await mark(page.locator('th', { hasText: 'FLAGS' }).first(), 'sc-flags'), n: 4 }]);
    await snap(page, '26-10-script-library');
    await clearCallouts(page);
  });
  await step('26-11 starter pack', async () => {
    await open(page, '/agent/rmm_starter_pack.php', 'Starter');
    await snap(page, '26-11-starter-pack');
  });
  await step('26-12 script run page', async () => {
    await open(page, '/agent/rmm_scripts.php?tab=library&q=Disk+cleanup', 'Disk cleanup');
    await page.locator('tr', { hasText: 'Disk cleanup (temp files)' }).first().locator('a', { hasText: 'Run' }).first().click();
    await page.waitForTimeout(900);
    await settle(page, 600);
    await snap(page, '26-12-script-run', { fullPage: true });
  });
  await step('26-13 schedules', async () => {
    await open(page, '/agent/rmm_schedules.php', 'Script schedules');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New schedule' }).first(), 'sh-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Monthly support account rotation' }).first().locator('td').nth(4), 'sh-wait'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'Weekly temp cleanup' }).first().locator('td').nth(5), 'sh-active'), n: 3, side: 'tr' }]);
    await snap(page, '26-13-schedules');
    await clearCallouts(page);
  });
  await step('26-14 approvals', async () => {
    await open(page, '/agent/rmm_approvals.php', 'Approvals');
    await callout(page, [
      { selector: await mark(page.locator('select').first(), 'ap-state'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'Monthly support account rotation' }).first().locator('td').nth(5), 'ap-row'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('summary', { hasText: 'Details and decision' }).first(), 'ap-detail'), n: 3, side: 'tr' }]);
    await snap(page, '26-14-approvals');
    await clearCallouts(page);
  });
  await step('26-15 agent alerts', async () => {
    await open(page, '/agent/rmm_agent_alerts.php', 'Agent alerts');
    await callout(page, [
      { selector: await mark(page.locator('select[name=state]').first(), 'aa-state'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('a, button', { hasText: 'Grouped by device' }).first(), 'aa-group'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('table').first(), 'aa-table'), n: 3, side: 'tr' }]);
    await snap(page, '26-15-agent-alerts');
    await clearCallouts(page);
  });
  await step('26-16 maintenance', async () => {
    await open(page, '/agent/rmm_maintenance.php', 'Maintenance windows');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New window' }).first(), 'mw-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Patch Tuesday reboots' }).first().locator('td').nth(1), 'mw-mode'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'Patch Tuesday reboots' }).first().locator('td').nth(3), 'mw-sched'), n: 3, side: 'tr' }]);
    await snap(page, '26-16-maintenance');
    await clearCallouts(page);
  });
  await step('26-17 escalation', async () => {
    await open(page, '/agent/rmm_escalations.php', 'Escalation policies');
    await callout(page, [
      { selector: await mark(page.locator('tr', { hasText: 'Critical alerts' }).first().locator('td').nth(3), 'es-steps'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('text=Fallback contact').first(), 'es-fallback'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('text=Storm control').first(), 'es-storm'), n: 3, side: 'tr' }]);
    await snap(page, '26-17-escalation');
    await clearCallouts(page);
  });
  await step('26-18 custom fields', async () => {
    await open(page, '/agent/rmm_fields.php', 'Custom fields');
    await snap(page, '26-18-custom-fields');
  });
  await step('26-19 check policies (vendor)', async () => {
    await open(page, '/agent/rmm_checks.php', 'Alert Check Policies');
    await snap(page, '26-19-check-policies');
  });
}

// ================================================================================================ page 27: patching, software, logs, hypervisors, remote
async function p27(page) {
  await step('27-01 patch compliance', async () => {
    await open(page, '/agent/rmm_patch_compliance.php', 'Patch compliance');
    await callout(page, [
      { selector: await mark(page.locator('select').first(), 'pc-client'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.card, .info-box', { hasText: 'Compliant' }).first(), 'pc-tiles'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'NEVER SCANNED' }).first(), 'pc-never'), n: 3, side: 'tr' }]);
    await snap(page, '27-01-patch-compliance');
    await clearCallouts(page);
  });
  await step('27-02 patch approvals', async () => {
    await open(page, '/agent/rmm_patch_approvals.php', 'Patch approvals');
    await snap(page, '27-02-patch-approvals');
  });
  await step('27-03 patch policies', async () => {
    await open(page, '/agent/rmm_patch_policies.php', 'Patch policies');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New policy' }).first(), 'pp-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Pack patch: Standard' }).first().locator('td').nth(1), 'pp-state'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'Pack patch: Standard' }).first().locator('td').nth(3), 'pp-applies'), n: 3, side: 'tr' }]);
    await snap(page, '27-03-patch-policies');
    await clearCallouts(page);
  });
  await step('27-04 settings patching tab', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'patching');
    await snap(page, '27-04-settings-patching');
  });
  let dev;
  await step('27-05 find device', async () => { dev = await deviceUrl(page, 'WS-GPM-37'); });
  if (dev) {
    await step('27-05 device patches tab', async () => {
      await open(page, dev, 'WS-GPM-37');
      await pane(page, 'patches');
      await scrollTop(page.locator('#rmm-pane-patches'), 150);
      await snap(page, '27-05-device-patches');
    });
    await step('27-06 device software tab', async () => {
      await open(page, dev);
      await pane(page, 'software');
      await scrollTop(page.locator('#rmm-pane-software'), 150);
      await snap(page, '27-06-device-software');
    });
  }
  await step('27-07 software catalog', async () => {
    await open(page, '/agent/rmm_software_catalog.php', 'Software catalog');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New package' }).first(), 'sw-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Zoom Workplace' }).first().locator('td').nth(2), 'sw-two'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'downloads.lakeshoremsp.example' }).first(), 'sw-host'), n: 3, side: 'tr' }]);
    await snap(page, '27-07-software-catalog', { fullPage: true });
    await clearCallouts(page);
  });
  await step('27-08 software assignments', async () => {
    await open(page, '/agent/rmm_software_assignments.php', 'Software assignments');
    await snap(page, '27-08-software-assignments', { fullPage: true });
  });
  await step('27-09 software report', async () => {
    await open(page, '/agent/rmm_software_report.php', 'Software report');
    await snap(page, '27-09-software-report');
  });
  await step('27-10 logs', async () => {
    await open(page, '/agent/rmm_logs.php', 'Logs');
    await callout(page, [
      { selector: await mark(page.locator('select').first(), 'lg-device'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('button, input', { hasText: 'Search' }).first(), 'lg-search'), n: 2, side: 'tr' }]);
    await snap(page, '27-10-logs', { fullPage: true });
    await clearCallouts(page);
  });
  await step('27-11 log rules', async () => {
    await open(page, '/agent/rmm_log_rules.php', 'Log rules');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'New rule' }).first(), 'lr-new'), n: 1 },
      { selector: await mark(page.locator('tr', { hasText: 'Disk errors' }).first().locator('td').nth(3), 'lr-match'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'Disk errors' }).first().locator('td').nth(4), 'lr-alert'), n: 3, side: 'tr' }]);
    await snap(page, '27-11-log-rules');
    await clearCallouts(page);
  });
  await step('27-12 hypervisors', async () => {
    await open(page, '/agent/rmm_hypervisors.php', 'Hypervisors');
    await callout(page, [
      { selector: await mark(page.locator('select', { hasText: 'Proxmox' }).first(), 'hv-platform'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('tr', { hasText: 'PVE-IWL-01' }).first(), 'hv-row'), n: 2, side: 'tr' }]);
    await snap(page, '27-12-hypervisors');
    await clearCallouts(page);
  });
  // 27-13 (a hypervisor host's own page) is not captured: with the sample data it prints "Nodes: Array, Array" and shows the Last backup cell as escaped <time> markup (see the guide Note).
  await step('27-14 settings remote tab', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'remote');
    await snap(page, '27-14-settings-remote');
  });
  await step('27-15 mesh wizard', async () => {
    await open(page, '/admin/rmm_mesh_wizard.php');
    await snap(page, '27-15-mesh-wizard', { fullPage: true });
  });
  let dev2;
  await step('27-16 find device', async () => { dev2 = await deviceUrl(page, 'SRV-GPM-DC01'); });
  if (dev2) {
    await step('27-16 device remote tab', async () => {
      await open(page, dev2, 'SRV-GPM-DC01');
      await pane(page, 'remote');
      await scrollTop(page.locator('#rmm-pane-remote'), 150);
      await snap(page, '27-16-device-remote');
    });
    await step('27-17 device logs tab', async () => {
      await open(page, await deviceUrl(page, 'LT-BLP-32'));
      await pane(page, 'logs');
      await scrollTop(page.locator('#rmm-pane-logs'), 150);
      await snap(page, '27-17-device-logs');
    });
  }
  await step('27-18 settings inventory and logs tab', async () => {
    await open(page, '/admin/settings_endpoint_agent.php');
    await eaTab(page, 'inventory');
    await snap(page, '27-18-settings-inventory-logs');
  });
}

// ================================================================================================ page 28: alerts, backups, network, RivetPanel
async function p28(page) {
  await step('28-01 central alerts', async () => {
    await open(page, '/agent/alerts.php', 'New Alerts');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: /^\s*(RMM|Backup|Network)\s*$/ }).first(), 'al-source'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('a, button', { hasText: 'Ack All Shown' }).first(), 'al-ack'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'SOURCE' }).first(), 'al-src-col'), n: 3, side: 'tr' }]);
    await snap(page, '28-01-central-alerts');
    await clearCallouts(page);
  });
  await step('28-02 alerts table', async () => {
    await open(page, '/agent/alerts.php');
    await scrollTop(page.locator('table').first(), 90);
    await snap(page, '28-02-alerts-table');
  });
  await step('28-03 alerts row actions', async () => {
    await open(page, '/agent/alerts.php');
    const row = page.locator('tr', { hasText: 'Comet Backup reported a failure for device SRV-GPM-FILE01' }).first();
    await scrollTop(row, 200);
    await callout(page, [
      { selector: await mark(row.locator('a', { hasText: 'TCK-' }).first(), 'ar-ticket'), n: 1, side: 'tr' },
      { selector: await mark(row.locator('td').last(), 'ar-actions'), n: 2, side: 'tl' }]);
    await snap(page, '28-03-alert-row');
    await clearCallouts(page);
  });
  await step('28-04 rmm alerts', async () => {
    await open(page, '/agent/rmm_alerts.php', 'RMM Alerts');
    await snap(page, '28-04-rmm-alerts');
  });
  await step('28-05 rmm assets', async () => {
    await open(page, '/agent/rmm_assets.php', 'RMM Assets');
    await callout(page, [
      { selector: await mark(page.locator('select').first(), 'ra-int'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'LAST SYNC' }).first(), 'ra-sync'), n: 2, side: 'tr' }]);
    await snap(page, '28-05-rmm-assets');
    await clearCallouts(page);
  });
  await step('28-06 vendor rmm integrations', async () => {
    await open(page, '/admin/settings_integrations.php?tab=rmm', 'RMM Integrations');
    await scrollTop(page.locator('text=RMM Integrations').first(), 90);
    await snap(page, '28-06-rmm-integrations');
  });
  await step('28-07 backups', async () => {
    await open(page, '/agent/backups.php', 'Device Backup Status');
    await callout(page, [
      { selector: await mark(page.locator('.info-box, .card', { hasText: 'Total Devices' }).first(), 'bk-tiles'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('select').first(), 'bk-client'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'COMET USER' }).first(), 'bk-user'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'STATUS' }).first(), 'bk-status'), n: 4, side: 'tr' }]);
    await snap(page, '28-07-backups');
    await clearCallouts(page);
  });
  await step('28-08 backups table', async () => {
    await open(page, '/agent/backups.php');
    await scrollTop(page.locator('table').first(), 100);
    await snap(page, '28-08-backups-table');
  });
  await step('28-09 comet settings', async () => {
    await open(page, '/admin/settings_integrations.php?tab=backups', 'Comet Backup Integration');
    await callout(page, [
      { selector: await mark(page.locator('text=Enable Comet Backup integration').first().locator('xpath=ancestor::div[contains(@class,"form-check")][1]'), 'cm-enable'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('input[name*=url]').first(), 'cm-url'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('button', { hasText: 'Save & Test Connection' }).first(), 'cm-test'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Client → Comet User Mapping' }).last(), 'cm-map'), n: 4, side: 'tr' }]);
    await snap(page, '28-09-comet-settings', { fullPage: true });
    await clearCallouts(page);
  });
  await step('28-10 network', async () => {
    await open(page, '/agent/network.php', 'Network');
    await callout(page, [
      { selector: await mark(page.locator('a, button', { hasText: 'Sync Firewalls' }).first(), 'nw-sync'), n: 1 },
      { selector: await mark(page.locator('text=Total Devices').first().locator('xpath=ancestor::div[3]'), 'nw-tiles'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('select').first(), 'nw-client'), n: 3, side: 'tr' }]);
    await snap(page, '28-10-network');
    await clearCallouts(page);
  });
  await step('28-11 network lists', async () => {
    await open(page, '/agent/network.php');
    await scrollTop(page.locator('text=Switches').last(), 80);
    await snap(page, '28-11-network-switches');
  });
  await step('28-12 firewall details', async () => {
    await open(page, '/agent/network.php');
    const card = page.locator('.card', { hasText: 'FW-GPM-01' }).filter({ has: page.locator('a, button', { hasText: 'Details' }) }).last();
    await card.locator('a, button', { hasText: 'Details' }).first().click();
    await page.waitForTimeout(1200);
    await settle(page, 600);
    await clean(page, 'firewall details');
    await snap(page, '28-12-firewall-details');
  });
  await step('28-13 unifi settings', async () => {
    await open(page, '/admin/settings_integrations.php?tab=unifi', 'UniFi Integration Settings');
    await snap(page, '28-13-unifi-settings');
  });
  await step('28-14 unifi site mappings', async () => {
    await open(page, '/admin/settings_integrations.php?tab=unifi');
    await scrollTop(page.locator('text=Site').filter({ hasText: 'Client Mappings' }).first(), 90);
    await snap(page, '28-14-unifi-site-mappings');
  });
  await step('28-15 sophos firewalls tab', async () => {
    await open(page, '/admin/settings_integrations.php?tab=firewalls');
    await snap(page, '28-15-firewalls-settings');
  });
  await step('28-16 panel deploy', async () => {
    await open(page, '/agent/clients.php?q=Granite');
    const href = await page.locator('a[href*="client_id="]', { hasText: 'Granite Peak Manufacturing' }).first().getAttribute('href');
    const cid = new URL(href, 'http://x').searchParams.get('client_id');
    await open(page, `/agent/panel_deploy.php?client_id=${cid}`, 'RivetPanel');
    await callout(page, [
      { selector: await mark(page.locator('.card', { hasText: 'Client-level devices' }).first(), 'pn-toggle'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'New enrollment token' }).first(), 'pn-new'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Outstanding tokens' }).last(), 'pn-tokens'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card', { hasText: 'Enrolled panel devices' }).last(), 'pn-devs'), n: 4, side: 'tr' }]);
    await snap(page, '28-16-rivetpanel', { fullPage: true });
    await clearCallouts(page);
  });
  await step('28-17 client sidebar panel link', async () => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const link = page.locator('a', { hasText: /^\s*RivetPanel/ }).first();
    await callout(page, [{ selector: await mark(link, 'cs-panel'), n: 1, side: 'tr' }]);
    await snap(page, '28-17-client-sidebar', { clip: { x: 0, y: 0, width: 330, height: 900 } });
    await clearCallouts(page);
  });
}

/** The fake devices "check in" only when seed 65 runs, and a device is Offline after 15 minutes of silence. Before shooting, move the
 *  last-contact times of the devices that are meant to be online to "3 minutes ago" (the offline, stale and never-seen ones are re-aged). */
function refreshDevices() {
  const db = process.env.DEMO_DB || 'rivetmsp_demo';
  const sql = `UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() - INTERVAL 3 MINUTE, last_collected_at = UTC_TIMESTAMP() - INTERVAL 3 MINUTE
      WHERE last_checkin_at IS NOT NULL AND hostname NOT IN ('DT-KPM-34','DT-IWL-38');
    UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR, last_collected_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE hostname = 'DT-KPM-34';
    UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() - INTERVAL 9 DAY, last_collected_at = UTC_TIMESTAMP() - INTERVAL 9 DAY WHERE hostname = 'DT-IWL-38';`;
  try { require('child_process').execFileSync('mysql', ['-u', 'root', db, '-e', sql], { stdio: 'pipe' }); } catch (e) { console.log('could not refresh device times:', e.message.split('\n')[0]); }
}

(async () => {
  refreshDevices();
  const mock = await startMock();
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');
    if (want(25)) await p25(page);
    if (typeof p26 === 'function' && want(26)) await p26(page);
    if (typeof p27 === 'function' && want(27)) await p27(page);
    if (typeof p28 === 'function' && want(28)) await p28(page);
  } finally {
    await browser.close();
    mock.close();
  }
  if (failures.length) { console.log('\nFAILURES:\n' + failures.join('\n')); process.exitCode = 1; }
})();
