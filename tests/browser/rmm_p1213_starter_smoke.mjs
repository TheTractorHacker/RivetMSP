// Browser smoke for the RMM starter pack (RivetCore 1.0.0-rc.13): the Starter pack page (browse and filter, select, preview, install, uninstall with its preview and in-page
// confirmation, the settings of a policy draft), the menu entry, and the Starter badge / risk chip / "update waiting" marker in the script library, desktop and phone, light and dark.
//
//   /tmp/claude-1000/rc13msp_browser.sh 8682 tests/browser/rmm_p1213_starter_smoke.mjs
//
// Same driver, harness and safety rules as the other smokes: loopback only, zero npm dependencies, signs in through the real login form, every check also fails on any uncaught error,
// console.error, failed first-party request or HTTP 4xx/5xx while it ran. The Windows scripts of the pack are only ever listed and installed here, never run.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
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
const H = new Harness({ page, outDir, edition: 'rmm-p1213-starter', base: BASE });
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
  const r = await p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;const b=e.getBoundingClientRect();return {x:b.x+scrollX,y:b.y+scrollY,w:b.width,h:b.height}})()`);
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
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await nav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
// every visible form control inside the new cards has an accessible name
const unlabeled = () => p.eval(`[...document.querySelectorAll('#rmm-starter-form input, #rmm-starter-form select, #rmm-starter-form textarea, #rmm-starter-filter input, #rmm-starter-filter select')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const msgIn = (card) => p.eval(`(()=>{const e=document.querySelector('#${card} [data-rmm-scale-msg]');return e?e.innerText.trim():''})()`);
const checked = (sel) => p.eval(`!!document.querySelector(${JSON.stringify(sel)}).checked`);
// a person clicks the label of a switch or box (the input itself is a few pixels)
const setChecked = async (sel, want) => {
  for (let i = 0; i < 3 && (await checked(sel)) !== want; i++) { await click(`label[for="${sel.slice(1)}"]`); await sleep(350); }
  assert((await checked(sel)) === want, 'could not set ' + sel);
};
const php = (args, extraEnv = {}) => execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p1213_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env, ...extraEnv }, encoding: 'utf8' });


const PAGE = '/agent/rmm_starter_pack.php';
const rows = () => p.eval(`document.querySelectorAll('tr[data-rmm-starter-row]').length`);
const pickLabel = (id) => click(`label[for="st-chk-${id}"]`);
const isPicked = (id) => checked(`#st-chk-${id}`);
const selText = () => p.eval(`document.querySelector('[data-rmm-starter-selected]').innerText`);
const flashText = () => p.eval(`document.body.innerText`);

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ the page
await T('menu and page: the Starter pack entry, 73 scripts and 3 drafts, nothing pre-selected, labelled controls (desktop, light)', async () => {
  await go('/agent/rmm_script_library.php');
  assert(await p.exists('a.dropdown-item[href="/agent/rmm_starter_pack.php"]'), 'the Endpoints menu has no Starter pack entry');
  assert(await p.exists('a.btn[href="/agent/rmm_starter_pack.php"]'), 'the script library has no Starter pack button');
  await go(PAGE);
  await trapDialogs();
  assert((await rows()) === 76, 'rows: ' + (await rows()));
  assert(/0\s+of 73/.test(await p.eval(`document.querySelector('[data-rmm-starter-kpi=scripts]').innerText`)), 'figures missing');
  assert(await p.eval(`document.querySelectorAll('input[data-rmm-starter-pick]:checked').length === 0`), 'something is pre-selected');
  assert(/0 items? selected of 76/.test(await selText()), 'count: ' + (await selText()));
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  assert(!(await p.exists('[data-rmm-starter-blocker]')), 'unexpected blocker');
  const t = await bodyText();
  assert(/Read only/.test(t) && /Changes the system/.test(t) && /Disruptive/.test(t) && /Draft: off, unassigned/.test(t), 'risk chips or draft marks missing');
  await shot('starter-full-desktop-light');
  await shotEl('#starter-actions', 'starter-actions-desktop-light');
});
await T('filters: platform and risk narrow the list; hostile text is escaped; state filter', async () => {
  await go(PAGE);
  await p.eval(`document.getElementById('st_pf').value='linux'`);
  await nav('#rmm-starter-filter button[type=submit]');
  assert((await rows()) === 32 + 3 - 1 || (await rows()) === 33 || (await rows()) === 34, 'rows after the platform filter: ' + (await rows()));
  assert(await p.eval(`document.querySelectorAll('tr[data-rmm-starter-row^="win-"]').length === 0`), 'a Windows script is still listed');
  assert(await p.exists('a[href="/agent/rmm_starter_pack.php"]'), 'no way to clear the filter');
  await go(PAGE + '?risk=disruptive');
  assert((await rows()) === 6, 'disruptive rows: ' + (await rows()));
  await go(PAGE + '?q=' + encodeURIComponent('<img src=x onerror=alert(1)>'));
  assert(!(await p.exists('img[src=x]')) && /No script matches/.test(await bodyText()), 'hostile search text not escaped, or the empty state is missing');
  await go(PAGE + '?state=current');
  assert(/No script matches/.test(await bodyText()), 'nothing is installed yet, so the Installed filter should be empty');
});

// ------------------------------------------------------------------------------------------------ select, preview, install
await T('an empty selection is refused with a sentence, nothing is installed', async () => {
  await go(PAGE);
  await nav('button[name=action][value=install]');
  assert(/Tick at least one script or policy draft first/.test(await flashText()), 'message missing');
  assert(/0\s+of 73/.test(await p.eval(`document.querySelector('[data-rmm-starter-kpi=scripts]').innerText`)), 'something was installed');
});
await T('select with the mouse and the keyboard, select all in a table, typing a draft setting ticks the draft', async () => {
  await go(PAGE + '?platform=linux');
  await p.focus('input[data-rmm-starter-all]');
  await p.press(' ');
  assert(/31 items? selected|3\d items? selected/.test(await selText()), 'select all: ' + (await selText()));
  await p.press(' ');
  assert(/^0 items? selected/.test(await selText()), 'the second space did not clear: ' + (await selText()));
  await pickLabel('lin-disk-usage');
  await pickLabel('lin-memory-swap');
  assert(await isPicked('lin-disk-usage') && /^2 items? selected/.test(await selText()), 'count: ' + (await selText()));
  // policy draft settings: open the details, type the time server; the draft is ticked for the person
  await p.eval(`document.querySelector('tr[data-rmm-starter-row=policy-linux-server] details > summary').click()`);
  await sleep(300);
  await p.type('#st-pp-policy-linux-server-ntp_server', 'time.example.test');
  assert(await isPicked('policy-linux-server') && /^3 items? selected/.test(await selText()), 'typing in the settings did not tick the draft: ' + (await selText()));
  await shotEl('tr[data-rmm-starter-row=policy-linux-server]', 'starter-policy-row-desktop-light');
});
await T('Preview writes nothing; the result card lists what would happen and offers exactly those items', async () => {
  await nav('button[name=action][value=plan]');
  await p.waitSel('#starter-result');
  const t = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert(/Preview: what an install would do/.test(t) && (t.match(/Will be added/g) || []).length === 3 && /Install these/.test(t), 'preview: ' + t.slice(0, 400));
  assert(/0\s+of 73/.test(await p.eval(`document.querySelector('[data-rmm-starter-kpi=scripts]').innerText`)), 'the preview installed something');
  assert(await p.eval(`document.querySelectorAll('#starter-result input[name="ids[]"]').length === 3`), 'the follow-up form should carry the three ids');
  await shotEl('#starter-result', 'starter-preview-desktop-light');
});
await T('Install these: three added, the figures follow, installing again changes nothing', async () => {
  await nav('#starter-result button[type=submit]');
  await p.waitSel('#starter-result');
  let t = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert(/Install result/.test(t) && (t.match(/Added/g) || []).length >= 3 && /switched off, not assigned/.test(t), 'install result: ' + t.slice(0, 400));
  assert(/2\s+of 73/.test(await p.eval(`document.querySelector('[data-rmm-starter-kpi=scripts]').innerText`)) && /1\s+of 3/.test(await p.eval(`document.querySelector('[data-rmm-starter-kpi=policies]').innerText`)), 'figures: ' + (await bodyText()).slice(0, 200));
  await shotEl('#starter-result', 'starter-installed-desktop-light');
  // again: pick the same two scripts and preview
  await go(PAGE);
  await pickLabel('lin-disk-usage'); await pickLabel('lin-memory-swap');
  await nav('button[name=action][value=plan]');
  t = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert((t.match(/Already up to date/g) || []).length === 2 && !/Install these/.test(t), 'a second preview should find nothing to do: ' + t.slice(0, 300));
});
await T('a bad draft setting is blocked with Core\'s sentence in the preview', async () => {
  await go(PAGE);
  await p.eval(`document.querySelector('tr[data-rmm-starter-row=policy-windows-server] details > summary').click()`);
  await p.type('#st-pp-policy-windows-server-uptime_warn_days', '2000000');
  await sleep(200);
  assert(await isPicked('policy-windows-server'), 'the draft was not ticked');
  // the browser's own max check would stop this at the keyboard; switch it off to exercise the server's answer (Core's sentence)
  assert(await p.eval(`document.getElementById('st-pp-policy-windows-server-uptime_warn_days').validity.rangeOverflow`), 'the browser did not flag the out-of-range number');
  await p.eval(`document.getElementById('rmm-starter-form').noValidate = true`);
  await nav('button[name=action][value=plan]');
  const t = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert(/Blocked/.test(t) && /uptime_warn_days must be a whole number from 0 to 1000000/.test(t), 'preview: ' + t.slice(0, 300));
});

// ------------------------------------------------------------------------------------------------ the script library
await T('script library: Starter badge and risk chip on the installed scripts only', async () => {
  await go('/agent/rmm_script_library.php');
  assert((await p.eval(`document.querySelectorAll('tr[data-rmm-starter-script]').length`)) === 2, 'starter rows: ' + (await p.eval(`document.querySelectorAll('tr[data-rmm-starter-script]').length`)));
  assert(await p.eval(`!!document.querySelector('tr[data-rmm-starter-script] .rmm-starter-badge') && /Read only/.test(document.querySelector('tr[data-rmm-starter-script]').innerText)`), 'badge or chip missing');
  assert(!(await p.exists('.rmm-starter-waiting')), 'no update should be waiting');
  await shot('library-starter-desktop-light');
});
await T('script library: an update waiting for review is marked, explained on the script, and the comparison opens', async () => {
  const href = await p.eval(`document.querySelector('tr[data-rmm-starter-script] a[href*="script_id="]').getAttribute('href')`);
  const id = Number((href.match(/script_id=(\d+)/) || [])[1]);
  assert(id > 0, 'no script id in ' + href);
  // an administrator edits the script (a new version of their own) ...
  await go(href + '#scr-edit');
  await p.waitSel('#scr-edit textarea');
  await p.eval(`(()=>{const t=document.querySelector('#scr-edit textarea[name=body]');t.value=t.value+'\\n# edited by the administrator\\n'})()`);
  await nav('#scr-edit button[type=submit]');
  // ... and the pack ships a newer text, which Core parks (the same call StarterInstaller makes)
  const out = JSON.parse(php(['stage', String(id)]).split('\n').find((l) => l.startsWith('{')));
  assert(out.version > 1, 'stage: ' + JSON.stringify(out));
  await go('/agent/rmm_script_library.php');
  assert((await p.eval(`document.querySelectorAll('.rmm-starter-waiting').length`)) === 1 && /Update waiting/.test(await bodyText()), 'the Update waiting marker is missing');
  await shot('library-update-waiting-desktop-light');
  await go(href);
  assert(await p.exists('[data-rmm-starter-update]') && /The Starter pack has a newer version of this script/.test(await bodyText()), 'the explanation is missing');
  await nav('[data-rmm-starter-update] a');
  assert(await p.exists('#scr-diff') && /pack text v2/.test(await p.eval(`document.getElementById('scr-diff').innerText`)), 'the comparison did not open');
  await shotEl('#scr-diff', 'library-compare-desktop-light');
  await go(PAGE + '?q=disk+usage');
  assert(/Update waiting for review/.test(await bodyText()), 'the Starter pack page should say it too');
});
await T('policies: the drafts carry the Starter badge and the marker text is not shown', async () => {
  await go('/agent/rmm_policies.php');
  assert((await p.eval(`document.querySelectorAll('.rmm-starter-badge').length`)) === 1 && !/\[starter\|/.test(await bodyText()), 'badge or marker: ' + (await p.eval(`document.querySelectorAll('.rmm-starter-badge').length`)));
  await shot('policies-starter-desktop-light');
});

// ------------------------------------------------------------------------------------------------ uninstall: a preview, then an in-page confirmation
await T('uninstall: preview first, then the in-page dialog, untouched items go, the edited script stays', async () => {
  await go(PAGE);
  await trapDialogs();
  await pickLabel('lin-memory-swap'); await pickLabel('lin-disk-usage'); await pickLabel('policy-linux-server');
  await nav('button[name=action][value=uninstall_preview]');
  const t = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert(/Preview: what an uninstall would do/.test(t) && /Would be retired/.test(t) && /Would be deleted/.test(t) && /was left alone/.test(t), 'preview: ' + t.slice(0, 500));
  assert(await p.eval(`!!document.querySelector('#starter-result button[data-rmm-confirm]')`), 'the confirming button should ask first');
  await shotEl('#starter-result', 'starter-uninstall-preview-desktop-light');
  await trapDialogs();
  await click('#starter-result button[data-rmm-confirm]');
  await p.waitSel('#rmmAutoConfirm.show');
  assert(/Uninstall these items/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').innerText`)), 'dialog text missing');
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was used');
  await nav('#rmmAutoConfirmGo');
  await p.waitSel('#starter-result');
  const r = await p.eval(`document.getElementById('starter-result').innerText.replace(/\\s+/g,' ')`);
  assert(/Uninstall result/.test(r) && /Retired/.test(r) && /Deleted/.test(r), 'result: ' + r.slice(0, 400));
  await shotEl('#starter-result', 'starter-uninstalled-desktop-light');
});

// ------------------------------------------------------------------------------------------------ dark mode, phone width, no native dialogs
const pages = [['starter', PAGE], ['library', '/agent/rmm_script_library.php']];
await T('dark mode: the page and the library are dark, readable (screenshots in shots/)', async () => {
  await setTheme(1);
  for (const [name, path] of pages) {
    await p.setViewport(1366, 900);
    await go(path); await sleep(700);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
  }
  await go(PAGE);
  await pickLabel('win-flush-dns'); await pickLabel('policy-windows-server');
  await nav('button[name=action][value=plan]');
  await shotEl('#starter-result', 'starter-preview-desktop-dark');
});
await T('phone width, dark: nothing overflows the page, tables scroll inside their container', async () => {
  await p.setViewport(390, 800, true);
  for (const [name, path] of pages) {
    await go(path); await sleep(700);
    await noOverflow(`${name} dark phone`);
    await shot(`${name}-phone-dark`);
  }
  await go(PAGE);
  await shotEl('#starter-actions', 'starter-actions-phone-dark');
  assert(await p.eval(`getComputedStyle(document.getElementById('starter-actions')).position !== 'sticky'`), 'the action bar should not be sticky on a phone');
});
await T('phone width, light: nothing overflows; controls still labelled; targets reachable', async () => {
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(390, 800, true);
  for (const [name, path] of pages) {
    await go(path); await sleep(700);
    await noOverflow(`${name} light phone`);
    await shot(`${name}-phone-light`);
  }
  await go(PAGE + '?platform=windows');
  assert((await unlabeled()).length === 0, 'unlabeled controls on the phone layout: ' + (await unlabeled()).join(','));
  await noOverflow('starter windows phone');
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt, no inline handlers on the page', async () => {
  await go(PAGE);
  await trapDialogs();
  await p.eval(`document.querySelectorAll('input[data-rmm-starter-all]').forEach(b=>b.click())`);
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
  assert(await p.eval(`document.querySelectorAll('#rmm-starter-form [onclick],#rmm-starter-form [onchange],#rmm-starter-form [onsubmit],#rmm-starter-form [oninput]').length === 0`), 'inline event handlers on the page');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
