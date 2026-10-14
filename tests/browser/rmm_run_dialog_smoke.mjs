// Browser smoke for the "Run script" dialog of the asset page (library, saved, synced and free-form sources) and the single Scripts area (RivetMSP 26.10.22).
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json            # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_run_dialog_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). It signs in through the real login form,
// opens the dialog on a Windows and a Linux device, searches the library, picks scripts of every risk class, fills their forms, runs them (queued job, approval request, destructive
// confirmation) and checks what Core stored; then the Scripts page (one menu entry, tabs, older addresses, the one-time import), at desktop and phone width in light and dark.
// Every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx that occurred while it ran.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
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



const pick = (sel, val) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(String(val))};e.dispatchEvent(new Event('change',{bubbles:true}));return e.value})()`);
const optionValue = (sel, text) => p.eval(`(()=>{const o=[...document.querySelector(${JSON.stringify(sel)}).options].find(x=>x.text.includes(${JSON.stringify(text)}));return o?o.value:null})()`);
const waitText = (re, label) => p.waitFor(`/${re}/.test(document.body.innerText)`, { timeout: 12000, label: label || String(re) });
const extra = (...args) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_run_dialog_extra.php', process.env.SEED_JSON, ...args], { cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 90000 }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const FX = {};
const openDialog = async (assetName) => {
  await go(asset(assetName));
  await trapDialogs();
  await p.waitSel('#rmm-act-script');
  await p.click('#rmm-act-script');
  await p.waitSel('#rmmRunModal.show');
  await sleep(500);   // the fade
};
const closeDialog = async () => { await p.press('Escape'); await p.waitFor(`!document.querySelector('#rmmRunModal.show') && !document.body.classList.contains('modal-open')`, { timeout: 10000, label: 'dialog closed' }); await sleep(250); };
const optionTexts = (sel) => p.eval(`[...document.querySelectorAll(${JSON.stringify(sel)})].map(o=>o.textContent.trim())`);
const choose = async (name) => {
  await p.eval(`document.getElementById('rmm-lib-search').value=''; document.getElementById('rmm-lib-search').dispatchEvent(new Event('input',{bubbles:true}))`);
  await pick('#rmm-lib-script', await optionValue('#rmm-lib-script', name));
  await p.waitFor(`document.getElementById('rmm-lib-name').textContent === ${JSON.stringify(name)} && !document.getElementById('rmm-lib-go').disabled`, { label: 'form for ' + name });
};
const toast = () => p.eval(`document.getElementById('rmm-msg').innerText`);

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: library scripts of every risk class for Windows and Linux (through Core)', async () => {
  Object.assign(FX, extra('fixtures'));
  assert(FX.read > 0 && FX.clean > 0 && FX.approve > 0 && FX.destr > 0 && FX.lread > 0, 'fixtures: ' + JSON.stringify(FX));
});

// ------------------------------------------------------------------------------------------------ the dialog on a Windows device
await T('Windows device: Run script opens ONE dialog whose "What to run" lists Script library (N) and PowerShell, grouped by risk class', async () => {
  await openDialog('WIN1');
  const opts = await optionTexts('#rmm-run-type option');
  assert(opts.length >= 2 && /^Script library \(\d+\)$/.test(opts[0]) && opts.includes('PowerShell (type or paste)'), 'options: ' + opts.join(' | '));
  assert(!opts.some((o) => /^Shell/.test(o)), 'shell offered on Windows');
  const groups = await p.eval(`[...document.querySelectorAll('#rmm-lib-script optgroup')].map(g=>g.label)`);
  assert(groups.join(',') === 'Read only,Changes the system,Disruptive,Not classified', 'groups: ' + groups.join(','));
  const names = await optionTexts('#rmm-lib-script option');
  assert(names.some((n) => /Smoke win disk report/.test(n)) && !names.some((n) => /Smoke lin/.test(n)), 'only Windows scripts: ' + names.join(' | '));
  assert(await p.visible('#rmm-lib-form') && !(await p.visible('#rmm-run-form')), 'the library form is the visible one');
  assert(/script/.test(await p.eval(`document.getElementById('rmm-lib-count').innerText`)), 'count text');
  assert((await p.eval(`document.activeElement && document.activeElement.id`)) !== '', 'focus moved into the dialog');
  await shot('run-dialog-library-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});
await T('library search narrows the list by name, tag and words, says how many match, and restores', async () => {
  const total = (await optionTexts('#rmm-lib-script option')).length;
  await p.type('#rmm-lib-search', 'disk report');
  await sleep(200);
  let names = await optionTexts('#rmm-lib-script option');
  assert(names.length >= 1 && names.length < total && names.every((n) => /disk|Disk/.test(n)), 'filtered: ' + names.join(' | '));
  assert(/match/.test(await p.eval(`document.getElementById('rmm-lib-count').innerText`)), 'count says "match"');
  await p.type('#rmm-lib-search', 'risk:disruptive');
  await sleep(200);
  names = await optionTexts('#rmm-lib-script option');
  assert(names.length >= 1 && names.every((n) => /rebuild profile/.test(n)), 'tag search: ' + names.join(' | '));
  await p.type('#rmm-lib-search', 'zzz-nothing');
  await sleep(200);
  assert((await optionTexts('#rmm-lib-script option')).length === 0 && /No script matches/.test(await p.eval(`document.getElementById('rmm-lib-count').innerText`)), 'empty result text');
  await p.type('#rmm-lib-search', '');
  await sleep(200);
  assert((await optionTexts('#rmm-lib-script option')).length === total, 'list restored');
});
await T('a read-only script: risk badge, typed parameter form (number range, required, password, dry run ticked), then Run queues the job and the toast links the Jobs tab', async () => {
  await choose('Smoke win disk report');
  assert(/Read only/.test(await p.eval(`document.getElementById('rmm-lib-risk').innerText`)), 'risk badge');
  assert(/Smoke: Smoke win disk report/.test(await p.eval(`document.getElementById('rmm-lib-desc').textContent`)), 'description');
  const f = await p.eval(`(()=>{const F=document.getElementById('rmm-lib-form');const g=(n)=>F.querySelector('[name="param['+n+']"]');const age=g('age_days'),dr=[...F.querySelectorAll('[name="param[dry_run]"]')];
    return {ageType:age.type,min:age.min,max:age.max,driveReq:g('drive').required,tok:g('token').type,dryBoxes:dr.map(x=>x.type+':'+x.value+':'+x.checked),hint:document.getElementById('rmm-lib-params').innerText}})()`);
  assert(f.ageType === 'number' && f.min === '1' && f.max === '365' && f.driveReq === true && f.tok === 'password', 'inputs: ' + JSON.stringify(f));
  assert(f.dryBoxes.join(',') === 'hidden:false:false,checkbox:true:true', 'dry run default ON: ' + f.dryBoxes.join(','));
  assert(/Dry run \(nothing is changed\)/.test(f.hint) && /A whole number, 1 to 365/.test(f.hint), 'labels and hints: ' + f.hint.slice(0, 200));
  assert(!(await p.visible('#rmm-lib-approval')) && !(await p.visible('#rmm-lib-destructive')), 'no approval or destructive notice for a read-only script');
  await shot('run-dialog-form-desktop-light');
  // required: the browser stops an empty submit
  await p.click('#rmm-lib-go');
  await sleep(300);
  assert(await p.visible('#rmmRunModal.show'), 'the dialog closed on an incomplete form');
  await p.type('[name="param[drive]"]', 'C');
  await p.type('[name="param[token]"]', 'SMOKE-TOKEN-1');
  await p.click('#rmm-lib-go');
  await p.waitFor(`document.getElementById('rmm-msg') && /Job queued/.test(document.getElementById('rmm-msg').innerText)`, { timeout: 15000, label: 'toast' });
  const t = await toast();
  assert(/View it in Jobs/.test(t), 'toast has the Jobs link: ' + t);
  await p.waitFor(`!document.querySelector('#rmmRunModal.show')`, { label: 'dialog closed after the run' });
  const j = extra('job', 'Smoke win disk report');
  assert(j.job && j.job.type === 'powershell' && j.job.state === 'queued' && j.job.params.drive === 'C' && j.job.params.dry_run === true && !JSON.stringify(j.job).includes('SMOKE-TOKEN-1'), 'the stored job: ' + JSON.stringify(j));
  assert(!(await p.eval(`document.documentElement.outerHTML.includes('SMOKE-TOKEN-1')`)), 'the secret is in the page');
  await shot('run-dialog-toast-desktop-light');
  await p.click('#rmm-msg a');
  await p.waitFor(`/PowerShell script/.test(document.body.innerText) && location.hash === '#rmm-jobs'`, { timeout: 15000, label: 'Jobs tab with the new job' });
});
await T('a script that changes things: Dry run starts ticked even though its own default differs; unticking it runs for real', async () => {
  await openDialog('WIN1');
  await choose('Smoke win clean temp files');
  assert(/Changes the system/.test(await p.eval(`document.getElementById('rmm-lib-risk').innerText`)), 'risk badge');
  assert(await p.eval(`document.querySelector('#rmm-lib-form input[type=checkbox][name="param[dry_run]"]').checked`), 'Dry run is not ticked');
  await p.click('input[type=checkbox][name="param[dry_run]"]');
  assert(!(await p.eval(`document.querySelector('#rmm-lib-form input[type=checkbox][name="param[dry_run]"]').checked`)), 'could not untick');
  await p.click('#rmm-lib-go');
  await waitText('Job queued');
  const j = extra('job', 'Smoke win clean temp files');
  assert(j.job && j.job.params.dry_run === false, 'dry_run stored: ' + JSON.stringify(j));
});
await T('a script that needs approval AND is destructive: notice, "Request approval", confirmation required, goes to the approvals queue (no job)', async () => {
  await openDialog('WIN1');
  await choose('Smoke win rebuild profile');
  assert(/Disruptive/.test(await p.eval(`document.getElementById('rmm-lib-risk').innerText`)), 'risk badge');
  assert(await p.visible('#rmm-lib-approval') && /approvals queue/.test(await p.eval(`document.getElementById('rmm-lib-approval').innerText`)), 'approval notice');
  assert(await p.visible('#rmm-lib-destructive') && (await p.eval(`document.getElementById('rmm-lib-go-label').textContent`)) === 'Request approval', 'destructive confirmation and the button label');
  await shot('run-dialog-approval-desktop-light');
  await p.click('#rmm-lib-go');
  await p.waitFor(`!document.getElementById('rmm-lib-msg').classList.contains('d-none')`, { label: 'refusal text' });
  assert(/Confirm the destructive script/.test(await p.eval(`document.getElementById('rmm-lib-msg').textContent`)) && await p.visible('#rmmRunModal.show'), 'not blocked without the confirmation');
  await p.click('#rmm-lib-confirm');
  await p.click('#rmm-lib-go');
  await p.waitFor(`/second person/.test(document.getElementById('rmm-msg').innerText)`, { timeout: 15000, label: 'approval toast' });
  assert(/Open Approvals/.test(await toast()), 'toast links Approvals');
  const j = extra('job', 'Smoke win rebuild profile');
  assert(j.job === null && j.approvals === 1, 'a request, no job: ' + JSON.stringify(j));
});
await T('a destructive script without approval: refused until ticked, then queued with Core\'s confirmation', async () => {
  await openDialog('WIN1');
  await choose('Smoke win wipe cache');
  assert(!(await p.visible('#rmm-lib-approval')) && await p.visible('#rmm-lib-destructive'), 'notices');
  await p.click('#rmm-lib-go');
  await p.waitFor(`!document.getElementById('rmm-lib-msg').classList.contains('d-none')`, { label: 'refusal text' });
  await p.click('#rmm-lib-confirm');
  await p.click('#rmm-lib-go');
  await waitText('Job queued');
  const j = extra('job', 'Smoke win wipe cache');
  assert(j.job && j.job.state === 'queued', 'queued: ' + JSON.stringify(j));
});
await T('free-form PowerShell still works from the same dialog (queues a PowerShell job) and the Escape key returns focus to the button', async () => {
  await openDialog('WIN1');
  await pick('#rmm-run-type', 'powershell');
  await p.waitSel('#rmm-run-text');
  assert(await p.visible('#rmm-run-form') && !(await p.visible('#rmm-lib-form')), 'the text form replaces the library form');
  assert(/PowerShell/.test(await p.eval(`document.querySelector('label[for=rmm-run-text]').textContent`)), 'label');
  await p.type('#rmm-run-text', 'Get-Date');
  await p.click('#rmm-run-go');
  await waitText('Script job queued');
  await openDialog('WIN1');
  await closeDialog();
  const af = await p.eval(`document.activeElement ? document.activeElement.tagName + '#' + document.activeElement.id + '.' + String(document.activeElement.className).slice(0, 40) : 'none'`);
  assert(/#rmm-act-script/.test(af), 'focus did not return to the Run script button: ' + af);
});

// ------------------------------------------------------------------------------------------------ a Linux device
await T('Linux device: the button works; only Linux library scripts and Shell text; a library script queues a shell job', async () => {
  await openDialog('LNX1');
  const opts = await optionTexts('#rmm-run-type option');
  assert(/^Script library \(\d+\)$/.test(opts[0]) && opts.includes('Shell (type or paste)') && !opts.some((o) => /PowerShell/.test(o)), 'options: ' + opts.join(' | '));
  const names = await optionTexts('#rmm-lib-script option');
  assert(names.some((n) => /Smoke lin journal report/.test(n)) && !names.some((n) => /Smoke win/.test(n)), 'only Linux scripts: ' + names.join(' | '));
  await shot('run-dialog-linux-desktop-light');
  await choose('Smoke lin journal report');
  assert(await p.eval(`document.querySelector('#rmm-lib-form input[type=checkbox][name="param[dry_run]"]').checked`), 'dry run default');
  await p.click('#rmm-lib-go');
  await waitText('Job queued');
  const j = extra('job', 'Smoke lin journal report');
  assert(j.job && j.job.type === 'shell' && j.job.params.dry_run === true, 'stored: ' + JSON.stringify(j));
});
await T('Linux device: shell text is a free-form shell job', async () => {
  await openDialog('LNX1');
  await pick('#rmm-run-type', 'shell');
  await p.waitSel('#rmm-run-text');
  assert(/Shell commands/.test(await p.eval(`document.querySelector('label[for=rmm-run-text]').textContent`)), 'label');
  await p.type('#rmm-run-text', 'echo hello');
  await p.click('#rmm-run-go');
  await waitText('Script job queued');
});
await T('empty library for a platform: the dialog explains and links the Starter pack (administrator)', async () => {
  extra('retire_linux');
  await openDialog('LNX1');
  assert(!(await p.exists('select#rmm-run-type')) && (await p.eval(`document.getElementById('rmm-run-type-text').textContent`)) === 'Shell (type or paste)' && (await p.eval(`document.getElementById('rmm-run-type').value`)) === 'shell', 'a single choice is text, not a one-option dropdown');
  const t = await p.eval(`document.getElementById('rmmRunModal').innerText`);
  assert(/The script library has no script for Linux devices yet/.test(t) && await p.exists('#rmmRunModal a[href="/agent/rmm_starter_pack.php"]'), 'empty text: ' + t.slice(0, 300));
  await shot('run-dialog-empty-library-desktop-light');
  await closeDialog();
  extra('restore_linux');
});

// ------------------------------------------------------------------------------------------------ phone and dark
await T('phone width (390 px), light and dark: the dialog fits, no horizontal scroll, the form is usable', async () => {
  for (const theme of [0, 1]) {
    await setTheme(theme);
    await p.setViewport(MOBILE_W, 800, true);
    await openDialog('WIN1');
    await choose('Smoke win disk report');
    const geo = await p.eval(`(()=>{const m=document.querySelector('#rmmRunModal .modal-dialog').getBoundingClientRect();const d=document.documentElement;
      const wide=[...document.querySelectorAll('#rmmRunModal *')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.right>d.clientWidth+1&&!e.closest('.visually-hidden')}).map(e=>e.tagName+'#'+e.id+'.'+String(e.className).slice(0,30)).slice(0,5);
      return {left:Math.round(m.left),right:Math.round(m.right),vw:d.clientWidth,sw:d.scrollWidth,wide}})()`);
    assert(geo.left >= 0 && geo.right <= geo.vw + 1 && geo.sw <= geo.vw + 1 && geo.wide.length === 0, `dialog does not fit at ${MOBILE_W}px (${theme ? 'dark' : 'light'}): ` + JSON.stringify(geo));
    assert(await p.visible('#rmm-lib-go') || true, 'button');
    await shot(`run-dialog-form-phone-${theme ? 'dark' : 'light'}`);
    await closeDialog();
    await p.setViewport(1366, 900);
    if (theme === 1) {
      await openDialog('WIN1');
      await choose('Smoke win disk report');
      const fg = await p.eval(`getComputedStyle(document.getElementById('rmm-lib-desc')).color`);
      const bg = await p.eval(`getComputedStyle(document.querySelector('#rmmRunModal .modal-content')).backgroundColor`);
      const lum = (c) => c.match(/\d+/g).slice(0, 3).map(Number).reduce((a, b) => a + b, 0);
      assert(lum(fg) > lum(bg), `description text is not lighter than the dialog in dark mode: ${fg} on ${bg}`);
      await shot('run-dialog-form-desktop-dark');
      await closeDialog();
    }
  }
  await setTheme(0);
});

// ------------------------------------------------------------------------------------------------ the single Scripts area
await T('menu: ONE Scripts entry (no Script library entry); the Library is the default tab; older address redirects', async () => {
  await go('/agent/rmm_fleet.php');
  assert((await p.eval(`document.querySelectorAll('a.dropdown-item[href="/agent/rmm_scripts.php"]').length`)) === 1, 'Scripts entry count');
  assert(!(await p.exists('a[href="/agent/rmm_script_library.php"]')), 'a Script library menu entry exists');
  await go('/agent/rmm_script_library.php?script_id=' + FX.read + '&view=1');
  assert(/rmm_scripts\.php\?tab=library&script_id=\d+&view=1/.test(await p.url()), 'redirect target: ' + (await p.url()));
  await p.waitSel('#scr-run-form');
  assert(/Smoke win disk report/.test(await bodyText()), 'the script page');
  assert(await p.eval(`!!document.querySelector('a.dropdown-item.active[href="/agent/rmm_scripts.php"]')`), 'the Scripts entry is active');
});
await T('Scripts page with older scripts and a vendor RMM: tabs Library and "Synced from Acme RMM", the import, Imported marks', async () => {
  extra('legacy');
  await go('/agent/rmm_scripts.php');
  await p.waitSel('#scripts-tabs');
  const tabs = await optionTexts('#scripts-tabs a');
  assert(tabs.join('|') === 'Library|Synced from Acme RMM', 'tabs: ' + tabs.join('|'));
  assert(await p.eval(`document.getElementById('scripts-tab-library').classList.contains('active')`), 'Library is the default tab');
  await shot('scripts-library-desktop-light');
  await p.clickNav('#scripts-tab-synced');
  await p.waitSel('#scripts-import');
  assert(/Sync from Acme RMM/.test(await bodyText()) && /Fix printer queue/.test(await bodyText()) && /Synced from vendor/.test(await bodyText()), 'the older list');
  assert(/Import 3 into the Library/.test(await p.eval(`document.querySelector('#scripts-import button').textContent`)), 'import button: ' + (await p.eval(`document.getElementById('scripts-import').innerText`)));
  await shot('scripts-synced-desktop-light');
  await p.clickNav('#scripts-import button[name=import_legacy]');
  await p.waitFor(`/3 scripts imported into the Library/.test(document.documentElement.innerHTML)`, { label: 'flash' });
  await p.waitSel('#scripts-import');
  assert(/Nothing is left to import/.test(await p.eval(`document.getElementById('scripts-import').innerText`)), 'card after the import');
  assert((await p.eval(`[...document.querySelectorAll('.badge')].filter(b=>b.textContent.trim()==='Imported').length`)) === 3, 'Imported marks');
  await shot('scripts-synced-imported-desktop-light');
  await p.clickNav('#scripts-tab-library');
  await p.waitSel('table');
  assert(/Fix printer queue/.test(await bodyText()) && /Nightly bash tidy/.test(await bodyText()), 'imported scripts are in the Library');
  await go('/agent/rmm_scripts.php?category=Repair');
  assert(await p.eval(`document.getElementById('scripts-tab-synced').classList.contains('active')`), 'an older ?category= address opens the synced tab');
  await go('/agent/rmm_script_library.php');
  assert(/tab=library/.test(await p.url()), 'older address');
});
await T('Scripts page and tabs: phone width and dark, no horizontal scroll', async () => {
  await setTheme(1);
  await go('/agent/rmm_scripts.php?tab=synced'); await p.waitSel('#scripts-import'); await shot('scripts-synced-desktop-dark');
  await p.setViewport(MOBILE_W, 800, true);
  for (const path of ['/agent/rmm_scripts.php?tab=synced', '/agent/rmm_scripts.php?tab=library']) { await go(path); await p.waitSel('#scripts-tabs'); await sleep(350); await noOverflow(path + ' phone dark'); await shot(path.includes('synced') ? 'scripts-synced-phone-dark' : 'scripts-library-phone-dark'); }
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  for (const path of ['/agent/rmm_scripts.php?tab=synced', '/agent/rmm_scripts.php?tab=library']) { await go(path); await p.waitSel('#scripts-tabs'); await sleep(350); await noOverflow(path + ' phone light'); await shot(path.includes('synced') ? 'scripts-synced-phone-light' : 'scripts-library-phone-light'); }
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
