// Browser smoke for Administration > Endpoint agent > Agent updates > Build a new version (26.10.31). Same driver, harness and safety rules as rmm_agent_feed_smoke.mjs
// (loopback only, zero npm dependencies, GitHub is the LOCAL mock tests/support/mock_github.py). Needs the mock directory (MOCK_DIR) prepared by
// tests/browser/rmm_agent_feed_extra.php, and the same MOCK_GH_BEARER the mock was told to require ("Bearer <token>") plus the token itself in GH_TOKEN.
//   BASE_URL=http://127.0.0.1:8673 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... MOCK_DIR=/tmp/x GH_TOKEN=github_pat_... SMOKE_OUT=/tmp/shots EXTRA_PHP=tests/browser/rmm_agent_feed_extra.php node tests/browser/rmm_agent_build_smoke.mjs
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve, join } from 'node:path';
import { Browser } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD, MOCK = process.env.MOCK_DIR, TOKEN = process.env.GH_TOKEN;
if (!BASE || !EMAIL || !PASSWORD || !MOCK || !TOKEN) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, MOCK_DIR and GH_TOKEN.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE)) { console.error('Refusing to run against ' + BASE); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-build', base: BASE });
const T = (name, fn, o = {}) => H.check(name, fn, o);
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const card = () => p.eval(`document.getElementById('agent-updates').innerText`);
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 9000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const flash = async (re, label) => {
  try { await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) }); } catch (e) {
    throw new Error(e.message + ' | the page says: ' + String((await p.eval('document.body.innerText'))).replace(/\s+/g, ' ').slice(0, 500));
  }
};
const setVal = (sel, v) => p.evalWith((q, val) => { const e = document.querySelector(q); if (!e) throw new Error('no ' + q); e.value = val; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, sel, v);
const runs = () => JSON.parse(readFileSync(join(MOCK, 'runs.json'), 'utf8'));
const setRun = (id, status, conclusion) => writeFileSync(join(MOCK, 'runs.json'), JSON.stringify(runs().map((r) => (r.id === id ? { ...r, status, conclusion } : r))));
const unlabeled = (scope) => p.evalWith((s) => [...document.querySelectorAll(s + ' input, ' + s + ' select')].filter((e) => {
  if (e.type === 'hidden' || e.disabled) return false; const r = e.getBoundingClientRect(); if (!(r.width > 0 && r.height > 0)) return false;
  return !((e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('title'));
}).map((e) => e.name || e.id), scope);

await T('login', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL); await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('no token: the fallback shows the GitHub button and three steps, and no Build form', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#agent-build');
  const t = await card();
  assert(/Start the build on GitHub/.test(t) && /Run workflow/.test(t) && /Check for updates/.test(t) && /0\.9\.2/.test(t), 'fallback text: ' + t.slice(0, 500));
  const href = await p.eval(`document.querySelector('[data-build-github]').href`);
  assert(/^https:\/\/github\.com\/TheTractorHacker\/rivet-core\/actions\/workflows\/endpoint-agent\.yml$/.test(href), 'github link: ' + href);
  assert(!(await p.exists('input[name=build_version]')), 'a Build form without a token');
  assert((await unlabeled('#agent-build')).length === 0, 'unlabeled: ' + (await unlabeled('#agent-build')).join(','));
  await shot('build-no-token');
});

await T('save a token through the form: stored, tested, and the page never shows it again', async () => {
  await p.evalWith(() => { document.getElementById('agent-build-token').open = true; });
  await p.waitSel('#ab_tok');
  assert(await p.eval(`document.getElementById('ab_tok').type === 'password'`), 'the token field is not a password field');
  await p.type('#ab_tok', TOKEN);
  await p.clickNav('button[name=agent_build_token_save]');
  await flash(/The token was saved \(encrypted\)\. The token works/, 'saved + tested');
  const html = await p.eval('document.documentElement.outerHTML');
  assert(!html.includes(TOKEN) && !html.includes(TOKEN.slice(0, 26)), 'the token (or a part of it) is in the page');
  assert(/\(?saved\)?/.test(await p.eval(`document.querySelector('[data-build-token-state]').textContent`)), 'state badge');
  const pre = await p.eval(`document.getElementById('ab_ver') ? document.getElementById('ab_ver').value : 'no form'`);
  assert(pre === '0.9.2', 'Build form with the next patch version, got: ' + pre);
  await shot('build-token-saved');
});

await T('Test token button answers in words', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#agent-build');
  await p.evalWith(() => { document.getElementById('agent-build-token').open = true; });
  await p.clickNav('button[name=agent_build_token_test]');
  await flash(/The token works: it can see the workflow/, 'test');
});

await T('Build: an existing version is refused in words and nothing starts', async () => {
  await setVal('#ab_ver', '0.9.1');
  await p.clickNav('button[name=agent_build_start]');
  await flash(/already exists/, 'refused');
  assert(!(await p.exists('[data-build-status]')), 'a build was started');
});

await T('Build 0.9.2 with publish automatically: queued, then the page follows GitHub (building, finished) without a reload, and publishes the release into the pilot ring', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#ab_ver');
  await p.click('#ab_auto');
  await p.clickNav('button[name=agent_build_start]');
  await flash(/The build of agent 0\.9\.2 was started/, 'started');
  await p.waitSel('[data-build-status]');
  assert(/Queued/.test(await p.eval(`document.querySelector('[data-build-status]').innerText`)), 'not queued');
  assert(await p.eval(`document.querySelector('[data-build-box]').dataset.buildActive === '1'`), 'not polling');
  await shot('build-queued');
  await p.waitFor(`/Queued/.test(document.querySelector('[data-build-status]').innerText) && /actions\\/runs\\/\\d+/.test(document.querySelector('[data-build-run]').href)`, { timeout: 25000, label: 'run link' });
  const id = runs().at(-1).id;
  setRun(id, 'in_progress', null);
  await p.waitFor(`/Building/.test(document.querySelector('[data-build-status]').innerText)`, { timeout: 25000, label: 'building' });
  await shot('build-building');
  setRun(id, 'completed', 'success');
  execFileSync('php', [process.env.EXTRA_PHP, MOCK, '0.9.2'], { stdio: 'ignore' });
  await p.waitFor(`/Finished/.test(document.querySelector('[data-build-status]')?.innerText || '') || !!document.querySelector('[data-feed-row="0.9.2"]')`, { timeout: 40000, label: 'finished' });
  await p.waitFor(`!!document.querySelector('[data-feed-row="0.9.2"]') && /pilot/.test(document.querySelector('[data-feed-row="0.9.2"]').innerText)`, { timeout: 60000, label: 'auto published row after the reload' });
  const r = await p.eval(`document.querySelector('[data-feed-row="0.9.2"]').innerText`);
  assert(/exe amd64/.test(r) && /pilot 10% amd64/.test(r), 'row: ' + r);
  await shot('build-finished-published');
}, { allowHttp: [/agent_build_poll/] });

await T('a failed build shows Failed and the link to the run; the token can be removed', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#ab_ver');
  await setVal('#ab_ver', '0.9.3');
  await p.clickNav('button[name=agent_build_start]');
  await flash(/The build of agent 0\.9\.3 was started/, 'started');
  await p.waitFor(`/actions\\/runs\\/\\d+/.test(document.querySelector('[data-build-run]').href)`, { timeout: 25000, label: 'run link' });
  setRun(runs().at(-1).id, 'completed', 'failure');
  await p.waitFor(`/Failed/.test(document.querySelector('[data-build-status]').innerText)`, { timeout: 25000, label: 'failed' });
  const href = await p.eval(`document.querySelector('[data-build-run]').href`);
  assert(/^https:\/\/github\.com\/TheTractorHacker\/rivet-core\/actions\/runs\/\d+$/.test(href), 'run link: ' + href);
  await shot('build-failed');
}, { allowHttp: [/agent_build_poll/] });

await T('mobile width: no horizontal scroll; dark mode renders; remove the token', async () => {
  await p.setViewport(390, 800, true);
  await go('/admin/settings_endpoint_agent.php#agent-updates');
  await p.waitSel('#agent-build');
  const o = await p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(o.sw <= o.vw + 1, `horizontal scroll ${o.sw} > ${o.vw}`);
  await shot('build-mobile');
  await p.setViewport(1366, 900, false);
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark'); true`);
  await shot('build-dark');
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','light'); true`);
  await p.evalWith(() => { document.getElementById('agent-build-token').open = true; });
  await p.clickNav('button[name=agent_build_token_remove]');
  await flash(/The token was removed/, 'removed');
  assert(await p.exists('[data-build-fallback]'), 'fallback after removal');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
