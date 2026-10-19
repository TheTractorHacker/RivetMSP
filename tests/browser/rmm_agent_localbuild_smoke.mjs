// Browser smoke for Administration > Endpoint agent > Agent updates > "Build on my build host" (26.10.33). Same driver, harness and safety rules as rmm_agent_build_smoke.mjs
// (loopback only, zero npm dependencies, real login form, every check fails on any uncaught error, console.error or failed first-party request).
// A MOCK build host (this script, over fetch) answers the real endpoints so the page's live progress can be watched.
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/rmm_agent_localbuild_smoke.mjs
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE)) { console.error('Refusing to run against ' + BASE); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'rmm-localbuild', base: BASE });
const T = (name, fn, o = {}) => H.check(name, fn, o);
const assert = (c, m) => { if (!c) throw new Error(m); };
const go = async (path) => { await p.goto('about:blank'); await p.goto(BASE + path); };
const URLP = '/admin/settings_endpoint_agent.php';
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 3200), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const flash = async (re, label) => {
  try { await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) }); } catch (e) {
    throw new Error(e.message + ' | the page says: ' + String((await p.eval('document.body.innerText'))).replace(/\s+/g, ' ').slice(0, 500));
  }
};
const vis = (sel) => p.evalWith((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.closest('[hidden]'); }, sel);
let TOKEN = '';
const api = async (sub, body, tok = TOKEN) => { const r = await fetch(BASE + '/api/v1/agent_build_host/' + sub, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify(body) }); return [r.status, await r.json().catch(() => ({}))]; };
const caps = { host_version: '1.0', capabilities: { docker: true, msi: true } };

await T('login', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL); await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('provider choice: GitHub by default; the build host option switches the panel', async () => {
  await go(URLP + '#tab-updates');
  await p.waitSel('[data-bp-choice]');
  assert(await vis('[data-bp-panel=github]') && !(await vis('[data-bp-panel=local]')), 'github panel first');
  await p.click('label[for=bp_lo]');
  await p.waitSel('[data-bp-panel=local]');
  assert(!(await vis('[data-bp-panel=github]')), 'github panel hidden');
  assert(/Local build/.test(await p.eval(`document.querySelector('#agent-localbuild').innerText`)), 'Local build heading');
  assert(/No build host yet/.test(await p.eval(`document.querySelector('#agent-localbuild').innerText`)), 'empty state in words');
  await shot('localbuild-empty');
  await p.click('label[for=bp_gh]');
  await p.waitSel('[data-bp-panel=github]');
  assert(!(await vis('[data-bp-panel=local]')), 'local panel hidden again');
});

await T('add a build host: the token is shown once, with a copy button and the exact config', async () => {
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-bp-panel=local]');
  await p.type('input[name=bh_name]', 'smoke-host-1');
  await p.clickNav('button[name=agent_bh_add]');
  await flash(/The build host was added/, 'added');
  await p.waitSel('[data-lb-token-box]');
  TOKEN = await p.eval(`document.getElementById('lb_new_token').value`);
  assert(/^rbh_[0-9a-f]{16}_[0-9a-f]{48}$/.test(TOKEN), 'token shape: ' + TOKEN);
  const cfg = await p.eval(`document.querySelector('[data-lb-config]').textContent`);
  assert(cfg.includes('SERVER_URL="' + BASE + '/api/v1/agent_build_host"') && cfg.includes('TOKEN="' + TOKEN + '"') && /ALLOWED_REFS=/.test(cfg), 'config snippet: ' + cfg);
  await p.click('[data-lb-copy=lb_new_token]');
  await p.waitFor(`document.querySelector('[data-lb-copy=lb_new_token]').textContent.trim() === 'Copied'`, { label: 'copy feedback' });
  await shot('localbuild-token');
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-bp-panel=local]');
  const html = await p.eval('document.documentElement.outerHTML');
  assert(!html.includes(TOKEN.slice(21)), 'the token is on the page a second time');
  assert(await p.exists('[data-lb-host] [data-lb-dot=never]'), 'host listed, never connected');
});

await T('status dot turns green after the host polls; setup steps link the Core docs', async () => {
  assert((await api('poll', caps))[0] === 200, 'poll ok');
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-lb-host] [data-lb-dot=ok]');
  await p.evalWith(() => { document.getElementById('agent-localbuild-setup').open = true; });
  const href = await p.eval(`[...document.querySelectorAll('#agent-localbuild-setup a')].map(a => a.href).join(' ')`);
  assert(/rivet-core\/blob\/main\/docs\/rmm\/BUILD_HOST\.md/.test(href), 'docs link: ' + href);
  const cmd = await p.eval(`document.querySelector('[data-lb-onecmd-input]').value`);
  assert(/^sudo \/.+\/deploy\/setup-build-host\.sh$/.test(cmd), 'one-line setup command: ' + cmd);
  await p.click('[data-lb-copy=lb_onecmd]');
  await p.waitFor(`document.querySelector('[data-lb-copy=lb_onecmd]').textContent.trim() === 'Copied'`, { label: 'copy feedback on the setup command' });
  await shot('localbuild-host');
}, { allowHttp: [/agent_localbuild_poll/] });

await T('request a build; the host takes it; progress is live without reloading', async () => {
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-lb-request-form]');
  await p.evalWith(() => { document.getElementById('lb_ver').value = '31.1.0'; });
  await p.clickNav('button[name=agent_bh_request]');
  await flash(/The build of agent 31\.1\.0 is queued/, 'queued');
  await p.waitSel('[data-lb-job]');
  const row = '[data-lb-job] [data-lb-label]';
  assert(/Queued/.test(await p.eval(`document.querySelector('${row}').textContent`)), 'queued label');
  const [c, d] = await api('poll', caps);
  assert(c === 200 && d.job && d.job.version === '31.1.0', 'host claimed: ' + JSON.stringify(d));
  const id = d.job.id;
  await api('status', { job_id: id, state: 'building', note: 'compiling <b>windows</b> amd64' });
  await p.waitFor(`/Building/.test(document.querySelector('${row}').textContent)`, { timeout: 15000, label: 'live: Building' });
  const note = await p.eval(`document.querySelector('[data-lb-job] [data-lb-note]').textContent`);
  assert(note === 'compiling <b>windows</b> amd64', 'note rendered as text: ' + note);
  assert(!(await p.exists('[data-lb-job] [data-lb-note] b')), 'the note was not interpreted as HTML');
  await shot('localbuild-building');
  // build progress (stage / pct / detail / elapsed_s): bar, percent, plain-words stage, detail line and the checklist update live
  assert((await api('status', { job_id: String(id), state: 'building', stage: 'build_windows_arm64', pct: 58, detail: 'go build <i>arm64</i>', elapsed_s: 95 }))[0] === 200, 'progress accepted');
  await p.waitFor(`document.querySelector('[data-lb-job] [data-lb-pct]').textContent === '58'`, { timeout: 15000, label: 'live: 58 percent' });
  const prog = await p.evalWith(() => {
    const r = document.querySelector('[data-lb-job]');
    return { w: r.querySelector('[data-lb-bar]').style.width, now: r.querySelector('[role=progressbar]').getAttribute('aria-valuenow'), text: r.querySelector('[data-lb-text]').textContent,
      detail: r.querySelector('[data-lb-detail]').textContent, el: r.querySelector('[data-lb-elapsed]').textContent,
      steps: [...r.querySelectorAll('[data-lb-steps] li')].map((li) => li.getAttribute('data-stage') + ':' + li.getAttribute('data-status')), italic: !!r.querySelector('[data-lb-detail] i') };
  });
  assert(prog.w === '58%' && prog.now === '58', 'bar width and aria-valuenow: ' + JSON.stringify(prog));
  assert(/Building Windows ARM64/.test(prog.text), 'stage in plain words: ' + prog.text);
  assert(prog.detail === 'go build <i>arm64</i>' && !prog.italic, 'detail is text, not HTML: ' + prog.detail);
  assert(/\d/.test(prog.el), 'elapsed shown: ' + prog.el);
  assert(prog.steps.join(',') === 'prepare:done,clone:done,build_windows_amd64:done,build_windows_arm64:active,msi:pending,verify:pending,upload:pending', 'checklist: ' + prog.steps.join(','));
  await api('status', { job_id: id, state: 'building', stage: 'msi', pct: 20 });   // pct never goes down
  await api('status', { job_id: id, state: 'building', stage: 'msi', pct: 70 });
  await p.waitFor(`document.querySelector('[data-lb-job] [data-lb-pct]').textContent === '70' && document.querySelector('[data-lb-steps] li[data-stage=msi]').getAttribute('data-status') === 'active'`, { timeout: 15000, label: 'live: msi active, 70 percent' });
  assert((await api('status', { job_id: id, state: 'building', stage: 'nonsense' }))[0] === 422, 'a bad stage is refused');
  await shot('localbuild-progress');
  await api('status', { job_id: id, state: 'failed', note: 'docker build failed', log_tail: 'step 3/9\n<script>alert(1)</script>\nerror: boom' });
  await p.waitFor(`document.querySelector('[data-lb-job]').getAttribute('data-lb-state') === 'failed'`, { timeout: 15000, label: 'live: failed' });
  await sleep(2500);   // the page reloads once a build ends so the log tail is there
  await p.waitSel('[data-lb-log]');
  const log = await p.eval(`document.querySelector('[data-lb-log]').textContent`);
  assert(/error: boom/.test(log) && /<script>alert\(1\)<\/script>/.test(log), 'log tail shown as text: ' + log);
  await p.evalWith(() => { document.querySelector('[data-lb-log]').closest('details').open = true; });
  await shot('localbuild-failed');
}, { allowHttp: [/agent_localbuild_poll/] });

await T('cancel a queued build', async () => {
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-lb-request-form]');
  await p.evalWith(() => { document.getElementById('lb_ver').value = '31.2.0'; });
  await p.clickNav('button[name=agent_bh_request]');
  await flash(/The build of agent 31\.2\.0 is queued/, 'queued');
  await p.clickNav('[data-lb-job] button[name=agent_bh_cancel]');
  await flash(/The build of 31\.2\.0 was cancelled/, 'cancelled');
  assert(await p.exists('[data-lb-job][data-lb-state=cancelled]'), 'cancelled row');
}, { allowHttp: [/agent_localbuild_poll/] });

await T('disable and delete the host from the list', async () => {
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-lb-host]');
  await p.clickNav('[data-lb-host] button[name=agent_bh_disable]');
  await flash(/disabled\. Its token is refused/, 'disabled');
  assert((await api('poll', caps))[0] === 401, 'a disabled host is refused');
  await p.clickNav('[data-lb-host] button[name=agent_bh_enable]');
  await flash(/enabled\./, 'enabled');
  assert((await api('poll', caps))[0] === 200, 'enabled again');
  await p.clickNav('[data-lb-host] button[name=agent_bh_rotate]');
  await flash(/A new token was created/, 'rotated');
  await p.waitSel('[data-lb-token-box]');
  const t2 = await p.eval(`document.getElementById('lb_new_token').value`);
  assert(t2 !== TOKEN && (await api('poll', caps))[0] === 401, 'the old token stops working');
  TOKEN = t2;
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-lb-host]');
  await p.clickNav('[data-lb-host] button[name=agent_bh_delete]');
  await flash(/was deleted/, 'deleted');
  assert(!(await p.exists('[data-lb-host]')) && (await api('poll', caps))[0] === 401, 'host gone, token refused');
});

await T('accessibility basics, phone width, dark mode', async () => {
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-bp-panel=local]');
  const un = await p.evalWith(() => [...document.querySelectorAll('#agent-localbuild input, #agent-localbuild select, [data-bp-choice] input')].filter((e) => {
    if (e.type === 'hidden' || e.disabled) return false;
    return !((e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('title'));
  }).map((e) => e.name || e.id));
  assert(un.length === 0, 'unlabeled controls: ' + un.join(','));
  await p.setViewport(390, 800, true);
  await go(URLP + '#agent-localbuild');
  await p.waitSel('[data-bp-panel=local]');
  const o = await p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(o.sw <= o.vw + 1, `horizontal scroll ${o.sw} > ${o.vw}`);
  await shot('localbuild-mobile');
  await p.setViewport(1366, 900, false);
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark'); true`);
  await shot('localbuild-dark');
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','light'); true`);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
