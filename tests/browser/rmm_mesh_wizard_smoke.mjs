// Browser smoke for the Create installer button (RMM dashboard, Agent fleet, Add device dialog) and the Remote access (MeshCentral) setup wizard (RivetMSP 26.10.30).
//
//   (throwaway install seeded by tests/browser/rmm_seed.php; web server: php -S with tests/rmm_golden/router.php)
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SMOKE_OUT=/tmp/mw-shots node tests/browser/rmm_mesh_wizard_smoke.mjs
//
// Same driver and rules as rmm_agent_feed_smoke.mjs: loopback only, no npm dependencies, real login form; every check also fails on a console.error, an uncaught error,
// a failed first-party request or an HTTP 4xx/5xx. No real MeshCentral exists here: "Save and test connection" is shown to say, in words, that the server did not answer.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: it saves MeshCentral settings.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-meshwiz', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (c, m) => { if (!c) throw new Error(m); };
const text = (sel) => p.eval(`(document.querySelector(${JSON.stringify(sel)})||{innerText:''}).innerText`);
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 9000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const noOverflow = async (label) => {
  const o = await p.eval(`({sw:document.documentElement.scrollWidth,vw:document.documentElement.clientWidth})`);
  assert(o.sw <= o.vw + 1, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw})`);
};
const state = (n) => p.eval(`(document.querySelector('[data-wiz-step="${n}"]')||{getAttribute:()=>'none'}).getAttribute('data-wiz-state')`);
const fill = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}))})()`);

await T('login: real form signs in', async () => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

for (const [name, path] of [['Agent fleet', '/agent/rmm_fleet.php'], ['RMM dashboard', '/agent/rmm_dashboard.php']]) {
  await T(`${name}: a visible primary Create installer button opens the installer page`, async () => {
    await go(path);
    await p.waitSel('[data-rmm-build-installer]');
    const v = await p.eval(`(()=>{const e=document.querySelector('[data-rmm-build-installer]');const r=e.getBoundingClientRect();return {w:r.width,h:r.height,cls:e.className,t:e.innerText.trim()}})()`);
    assert(v.w > 80 && v.h > 20 && /btn-primary/.test(v.cls) && /Create installer/.test(v.t), JSON.stringify(v));
    await shot(name.replace(/\W+/g, '-').toLowerCase() + '-header');
    await p.clickNav('[data-rmm-build-installer]');
    await p.waitSel('#rmm-add-form');
    assert(/rmm_add_device\.php/.test(await p.url()), 'url ' + (await p.url()));
  }, { allowHttp: [/rmm_add_device|rmm_fleet|rmm_dashboard/] });
}

await T('Add device dialog links to the Create installer page', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('[data-rmm-installer-open]');
  await p.click('[data-rmm-installer-open]');
  await p.waitSel('#rmmInstallerModal.show');
  assert(await p.eval(`!!document.querySelector('#rmmInstallerModal [data-rmm-installer-page-link] a[href="/agent/rmm_add_device.php"]')`), 'no link');
});

await T('wizard: reachable from the Endpoint agent header; five steps', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('[data-mesh-wizard-link]');
  await p.clickNav('[data-mesh-wizard-link]');
  await p.waitSel('[data-wiz-step="5"]');
  assert((await p.eval(`document.querySelectorAll('[data-wiz-step]').length`)) === 5, 'five steps');
  assert(/steps done/.test(await text('[data-wiz-progress]')), 'progress badge');
  await shot('wizard-first-look-desktop');
});

await T('wizard step 1: address and key, Save and test connection: key never shown again, the unreachable server is said in words', async () => {
  const key = 'ab12'.repeat(16);
  await go('/admin/rmm_mesh_wizard.php');
  await p.waitSel('#wz_url');
  await fill('#wz_url', 'https://127.0.0.1:1');
  await fill('#wz_key', key);
  await p.clickNav('button[name=wiz_test]');
  await p.waitSel('[data-wiz-step="1"]');
  assert(!(await p.eval('document.body.innerHTML')).includes(key), 'the login key is printed back');
  assert(/stored encrypted/.test(await text('#step1')), 'stored encrypted badge');
  assert((await state(1)) === 'todo' && /did not answer/.test(await text('#step1 [data-wiz-status]')), 'status: ' + (await text('#step1 [data-wiz-status]')));
  await fill('#wz_url', 'https://mesh.example.test');
  await p.clickNav('button[name=wiz_save1]');
  await p.waitSel('[data-wiz-step="1"]');
  assert((await state(1)) === 'ok', 'step 1 should turn green after a plain save: ' + (await text('#step1 [data-wiz-status]')));
});

await T('wizard step 2: the whole pasted download address is accepted, the id is never printed as text', async () => {
  const id = 'A'.repeat(30) + '@$' + 'b'.repeat(32);
  await go('/admin/rmm_mesh_wizard.php');
  await p.waitSel('#mc_group');
  await fill('#mc_group', `https://mesh.example.test/meshagents?id=4&meshid=${id}&installflags=0`);
  await p.clickNav('button[name=wiz_save2]');
  await p.waitSel('[data-wiz-step="2"]');
  assert((await state(2)) === 'ok', 'step 2: ' + (await text('#step2 [data-wiz-status]')));
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'password', 'the group field must be masked');
  await p.click('#mc_group_show');
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'text', 'Show it toggles the field');
});

await T('wizard step 2: nonsense is refused in words, the saved group stays, nothing unescaped', async () => {
  await go('/admin/rmm_mesh_wizard.php');
  await p.waitSel('#mc_group');
  await fill('#mc_group', '<script>alert(1)</script>');
  await p.clickNav('button[name=wiz_save2]');
  await p.waitSel('[data-wiz-step="2"]');
  assert(/group id/i.test(await text('[data-rmm-scale-msg]')), 'refusal text: ' + (await text('[data-rmm-scale-msg]')));
  assert((await state(2)) === 'ok', 'group lost');
  assert(!(await p.eval('document.body.innerHTML')).includes('<script>alert(1)</script>'), 'unescaped');
});

await T('wizard step 3: Read it from the server (nothing answers) shows an error and saves no pin; a pin can be saved', async () => {
  await go('/admin/rmm_mesh_wizard.php');
  await p.waitSel('button[form=wiz-probe]');
  await p.clickNav('button[form=wiz-probe]');
  await p.waitSel('[data-rmm-mesh-pin-result="error"]');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === '', 'pin must stay empty');
  assert((await state(3)) === 'todo', 'step 3 should be amber');
  await fill('#mc_pin', 'a1b2c3d4e5f60718'.repeat(6));
  await p.clickNav('button[name=wiz_save3]');
  await p.waitSel('[data-wiz-step="3"]');
  assert((await state(3)) === 'ok', 'pinned: ' + (await text('#step3 [data-wiz-status]')));
});

await T('wizard: troubleshooting items open, the page fits a phone and every control has a label', async () => {
  await p.setViewport(390, 800, true);
  await go('/admin/rmm_mesh_wizard.php#troubleshooting');
  await p.waitSel('[data-wiz-trouble]');
  await p.click('[data-wiz-trouble] summary');
  assert(/MeshCentral node mapped|home page/i.test(await text('#troubleshooting')), 'trouble text');
  await noOverflow('wizard phone');
  await shot('wizard-mobile');
  await p.setViewport(1366, 900);
  const un = await p.eval(`[...document.querySelectorAll('[data-wiz-step] input, [data-wiz-step] select')].filter(e=>e.type!=='hidden'&&!(e.labels&&e.labels.length)&&!e.getAttribute('aria-label')).map(e=>e.name||e.id)`);
  assert(un.length === 0, 'unlabeled: ' + un.join(','));
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
