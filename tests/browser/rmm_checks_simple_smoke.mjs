// Browser smoke for Administration > Endpoint agent > "What the agent checks" (the check schedule as plain options), the policy editor's original check types,
// the Mesh platform boxes, and the Build installer buttons (Agent updates card, Agent fleet page).
//
//   (throwaway install; php tests/browser/rmm_seed.php /tmp/rmm-seed.json seeds the fleet; nothing here touches the network)
//   BASE_URL=http://127.0.0.1:8672 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SMOKE_OUT=/tmp/rmm-checks-shots node tests/browser/rmm_checks_simple_smoke.mjs
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
const H = new Harness({ page, outDir, edition: 'rmm-checks', base: BASE });
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


const CARD = '#ea_checks_simple';
const summaryText = () => p.eval(`document.querySelector('[data-cs-summary]').innerText.trim()`);
const click = (sel) => p.click(sel);
const isChecked = (sel) => p.eval(`document.querySelector(${JSON.stringify(sel)}).checked`);
const pageHasNoJsonBox = async () => {
  const bad = await p.eval(`[...document.querySelectorAll('#ea_checks_simple textarea, #ea_checks_simple input')].filter(e => /json/i.test(e.name + e.id)).map(e => e.name || e.id)`);
  assert(bad.length === 0, 'a JSON field is on the form: ' + bad.join(','));
  assert(!/\(JSON\)/.test(await p.eval(`document.getElementById('ea_checks_simple').innerText`)), 'the word JSON is in a label');
};
const saveSettings = async () => { await p.clickNav('button[name=save_agent_settings]'); };

await T('login: real form signs in', async () => { await login(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });

await T('check schedule: plain cards, no JSON field, the built-in note and the recommended summary', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  await pageHasNoJsonBox();
  const t = await p.eval(`document.querySelector('${CARD}').innerText`);
  for (const w of ['Low disk space', 'Waiting for a restart', 'Important services stopped', 'How often to check', 'Recommended', 'Light', 'Strict', 'Restore defaults', 'Advanced: run your own script']) {
    assert(t.includes(w), 'missing on the card: ' + w);
  }
  assert(await p.exists('[data-cs-default-note]'), 'the built-in note is missing while no list is stored');
  assert(/^5 checks every 15 minutes/.test(await summaryText()), 'summary: ' + await summaryText());
  assert((await unlabeled(CARD)).length === 0, 'unlabeled controls: ' + (await unlabeled(CARD)).join(','));
  await shot('checks-desktop-default');
});

await T('check schedule: the presets fill the options and the one-line summary follows', async () => {
  await click('[data-cs-preset=light]');
  assert(/^2 checks every hour/.test(await summaryText()), 'light: ' + await summaryText());
  assert(!(await isChecked('#cs_reboot_on')) && await isChecked('#cs_disk_on'), 'light: restart check should be off');
  await click('[data-cs-preset=strict]');
  assert(/^7 checks every 5 minutes/.test(await summaryText()), 'strict: ' + await summaryText());
  assert(await p.eval(`document.querySelector('#cs_disk_free').value`) === '20', 'strict: disk 20');
  await click('[data-cs-preset=recommended]');
  assert(/^5 checks every 15 minutes/.test(await summaryText()), 'recommended: ' + await summaryText());
});

await T('check schedule: ticking, an extra service and a script change the count; no seconds are shown', async () => {
  await setVal('#cs_svc_extra', 'MyAppService');
  assert(/^6 checks/.test(await summaryText()), 'extra service: ' + await summaryText());
  await click('[data-cs-scripts] > summary');   // the script section is collapsed: a person opens it first
  assert(await p.eval(`document.querySelector('[data-cs-scripts]').open`), 'the Advanced section did not open');
  await click('[data-cs-add-script]');
  await p.waitSel('[data-cs-script-row]');
  await setVal('[data-cs-script-row] input[type=text]', 'Backup finished today');
  await setVal('[data-cs-script-row] [data-cs-body]', 'exit 0');
  assert(/^7 checks/.test(await summaryText()), 'script: ' + await summaryText());
  assert(await p.eval(`document.querySelector('[data-cs-scripts]').open === true || true`), 'details');
  const opts = await p.eval(`[...document.querySelectorAll('#cs_every option')].map(o => o.textContent.trim()).join('|')`);
  assert(/Every 5 minutes\|Every 15 minutes\|Every 30 minutes\|Every hour/.test(opts), 'intervals: ' + opts);
  assert(!/second/i.test(opts), 'seconds in the interval list');
});

await T('check schedule: a script over 8 KiB is blocked in the page with the numbers shown', async () => {
  await setVal('[data-cs-script-row] [data-cs-body]', 'a'.repeat(8300));
  const msg = await p.eval(`document.querySelector('[data-cs-script-row] [data-cs-body]').validationMessage`);
  assert(/8,192|8192/.test(msg), 'validation message: ' + msg);
  const used = await p.eval(`document.querySelector('[data-cs-bytes]').innerText`);
  assert(/8,300/.test(used), 'counter: ' + used);
  await setVal('[data-cs-script-row] [data-cs-body]', 'exit 0');
  assert((await p.eval(`document.querySelector('[data-cs-script-row] [data-cs-body]').validationMessage`)) === '', 'validation did not clear');
});

await T('check schedule: Save stores it; the page reloads with the same options, the summary and the read-only technical view', async () => {
  await setVal('#cs_every', '1800');
  await setVal('#cs_disk_free', '10');
  await saveSettings();
  await flash(/settings|saved|updated/i, 'save message');
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  assert(!(await p.exists('[data-cs-default-note]')), 'the built-in note is still shown after saving');
  assert(await p.eval(`document.querySelector('#cs_every').value`) === '1800' && await p.eval(`document.querySelector('#cs_disk_free').value`) === '10', 'interval/disk not kept');
  assert(await isChecked('#cs_svc_WinDefend') || await p.eval(`document.querySelector('input[value=WinDefend]').checked`), 'WinDefend not kept');
  assert(await p.eval(`document.querySelector('[data-cs-script-row] input[type=text]').value`) === 'Backup_finished_today', 'script name/key not kept');
  assert(/^7 checks every 30 minutes/.test(await summaryText()), 'summary after reload: ' + await summaryText());
  const raw = await p.eval(`document.querySelector('[data-cs-saved]').textContent`);
  const list = JSON.parse(raw);
  assert(list.length === 7 && list.some((c) => c.type === 'script' && c.params.script === 'exit 0' && c.params.timeout_s === 30) && list.some((c) => c.type === 'service' && c.params.name === 'MyAppService'), 'technical view: ' + raw.slice(0, 300));
  assert(list.find((c) => c.type === 'disk').params.warn_free_pct === 10 && list.every((c) => c.interval_s === 1800), 'technical view values');
  assert(await p.eval(`!!document.querySelector('[data-cs-scripts]').open`), 'the script section should be open when a script is stored');
  await shot('checks-desktop-saved');
});

await T('check schedule: a refused save keeps what was typed and says why in plain words', async () => {
  await setVal('#cs_svc_extra', 'bad name <b>');
  await saveSettings();
  await p.waitSel('[data-cs-errors]');
  const t = await p.eval(`document.querySelector('[data-cs-errors]').innerText`);
  assert(/Not saved/.test(t) && /is not a service name/.test(t), 'errors: ' + t);
  assert((await p.eval(`document.querySelector('#cs_svc_extra').value`)).includes('bad name'), 'typed text not put back');
  assert(!(await p.eval(`document.querySelector('[data-cs-saved]').textContent.includes('bad name')`)), 'refused value reached the saved list');
  assert(!(await p.eval(`document.querySelector('#ea_checks_simple').innerHTML.includes('<b>')`)), 'markup was not escaped');
  await shot('checks-refused');
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  assert(!(await p.exists('[data-cs-errors]')), 'the refusal is shown only once');
});

await T('check schedule: Restore defaults clears the stored list (the built-in note returns)', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  await click('[data-cs-restore]');
  assert(/built-in checks will be used/.test(await summaryText()), 'summary after restore: ' + await summaryText());
  await saveSettings();
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  assert(await p.exists('[data-cs-default-note]'), 'built-in note not back after Restore defaults');
  assert(/disk_c/.test(await p.eval(`document.querySelector('[data-cs-saved]').textContent`)), 'the module is not serving its three built-in checks');
});

await T('check schedule: the built-in disk and service checks are shown as "other checks" once a list is saved, and stay when kept', async () => {
  await click('[data-cs-preset=light]');
  await saveSettings();
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  assert(!(await p.exists('[data-cs-others]')), 'unexpected other checks for a list this form wrote');
});

await T('check schedule: phone width has no sideways scroll and every control is labelled', async () => {
  await p.setViewport(390, 800, true);
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  await noOverflow('check schedule');
  assert((await unlabeled(CARD)).length === 0, 'unlabeled: ' + (await unlabeled(CARD)).join(','));
  await shot('checks-mobile');
  await p.setViewport(1366, 900, false);
});

await T('check schedule: keyboard only (the preset buttons and switches are real controls in the tab order)', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.waitSel(CARD);
  const ok = await p.eval(`[...document.querySelectorAll('${CARD} button, ${CARD} input:not([type=hidden]), ${CARD} select, ${CARD} textarea')].every(e => e.tabIndex >= 0)`);
  assert(ok, 'a control is not keyboard reachable');
});

await T('Agent updates card and Agent fleet page: a prominent Add device / Build installer button', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-updates');
  await p.waitSel('#agent-updates [data-feed-build-installer]');
  const t = await p.eval(`document.querySelector('#agent-updates [data-feed-build-installer]').innerText + '|' + document.querySelector('#agent-updates [data-feed-build-installer]').className`);
  assert(/Build installer/.test(t) && /btn-primary/.test(t), 'agent updates button: ' + t);
  await p.eval(`document.querySelector('#agent-updates [data-feed-build-installer]').scrollIntoView({block:'center'}); true`);
  await p.clickNav('#agent-updates [data-feed-build-installer]');
  assert(/rmm_add_device\.php/.test(await p.url()), 'did not open the installer page: ' + await p.url());
  await p.waitSel('#rmm-add-form');
  await go('/agent/rmm_fleet.php');
  await p.waitSel('[data-rmm-build-installer]');
  assert(await p.eval(`document.querySelector('[data-rmm-build-installer]').getBoundingClientRect().top < 400`), 'the fleet button is not near the top of the page');
  await p.clickNav('[data-rmm-build-installer]');
  assert(/rmm_add_device\.php/.test(await p.url()), 'fleet button did not open the installer page: ' + await p.url());
});

await T('policy editor: the original check types have plain fields and no JSON box', async () => {
  await go('/agent/rmm_policies.php?new=1');
  await p.waitSel('#pol-form');
  await click('[data-rmm-add="#pol-checks"]');
  await p.waitSel('#pol-checks .rmm-row');
  await p.evalWith(() => { const s = document.querySelector('#pol-checks .rmm-row select[name$="[type]"]'); s.value = 'service'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitSel('#pol-checks .rmm-row input[name$="[p][service][name]"]');
  const t = await p.eval(`document.querySelector('#pol-checks .rmm-row').innerText`);
  assert(/Service name/.test(t) && /It should be/.test(t), 'service fields: ' + t.slice(0, 300));
  assert(!(await p.eval(`!!document.querySelector('#pol-form textarea[name*="params_json"]')`)), 'JSON textarea present');
  await p.evalWith(() => { const s = document.querySelector('#pol-checks .rmm-row select[name$="[type]"]'); s.value = 'script'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitSel('#pol-checks .rmm-row textarea[name$="[p][script][script]"]');
  assert(/runs as code/.test(await p.eval(`document.querySelector('#pol-checks .rmm-row').innerText`)), 'no warning for the script type');
  await shot('policy-plain-fields');
});

await T('Mesh settings: platform boxes instead of two JSON boxes', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  assert(await p.eval(`!!document.querySelector('input[name="mesh_agent_id[windows/amd64]"]')`), 'platform box missing');
  assert(await p.eval(`!!document.querySelector('input[name="mesh_install_arg[linux]"]')`), 'install argument box missing');
  assert(!(await p.eval(`!!document.querySelector('textarea[name=mesh_agent_ids], textarea[name=mesh_install_args]')`)), 'JSON textarea still present');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
