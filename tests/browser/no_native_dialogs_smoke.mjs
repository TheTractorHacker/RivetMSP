// Browser smoke for RivetDialog (RivetMSP 26.10.35): the app must never open a native browser dialog.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/no_native_dialogs_smoke.mjs
//
// Same driver, harness and safety rules as the other smokes (loopback only, no npm dependencies, real login form). On every page this
// installs traps so window.alert / confirm / prompt THROW and record the call, and it also records every CDP Page.javascriptDialogOpening
// (which includes the beforeunload prompt). Any native dialog fails the check that caused it. It then exercises the helper (text-only
// message, Esc, backdrop, focus, queue, ARIA, stacking on an open modal, phone width, dark theme) and the real delegates (data-confirm-submit).
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: use a throwaway install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'dialogs', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const assert = (c, m) => { if (!c) throw new Error(m); };

// native dialogs: CDP level (what the browser really opened) + JS level (throwing traps)
const nativeOpened = [];
p.handlers.push((ev, pr) => { if (ev === 'Page.javascriptDialogOpening') nativeOpened.push(`${pr.type}: ${pr.message}`); });
await p.send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
  window.__native = [];
  for (const n of ['alert', 'confirm', 'prompt']) window[n] = function () { window.__native.push(n + ': ' + [].join.call(arguments, ' ')); throw new Error('NATIVE DIALOG window.' + n + '() called'); };
})()` });
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, async () => {
  nativeOpened.length = 0;
  await fn();
  const js = await p.eval('(window.__native || []).slice()').catch(() => []);
  assert(nativeOpened.length === 0 && js.length === 0, 'native dialog opened: ' + JSON.stringify([...nativeOpened, ...js]));
}, o));
const go = async (path) => { await p.goto('about:blank'); await p.goto(BASE + path); await sleep(400); };
const dlg = () => p.eval(`(()=>{const d=document.querySelector('.rivet-dialog');if(!d)return null;const b=d.querySelector('.modal-body');return {role:d.getAttribute('role'),modal:d.getAttribute('aria-modal'),
  lab:document.getElementById(d.getAttribute('aria-labelledby'))?.textContent,desc:document.getElementById(d.getAttribute('aria-describedby'))?.textContent,
  active:document.activeElement?.textContent||document.activeElement?.tagName,activeTag:document.activeElement?.tagName,activeIn:d.contains(document.activeElement),
  btns:[...d.querySelectorAll('.modal-footer button')].map(x=>x.textContent+'|'+x.className)}})()`);
const gone = () => p.waitFor(`!document.querySelector('.rivet-dialog') && !document.querySelector('.modal-backdrop[style*="2090"]')`);
// start a dialog without awaiting it; result lands in window.__r
const start = (call) => p.eval(`window.__r = undefined; (${call}).then(v => { window.__r = v === undefined ? '__undef' : v; }); 0`);
const result = async () => { await p.waitFor('window.__r !== undefined'); return p.eval('window.__r'); };

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('helper is present on the main layout, and on the login page', async () => {
  await go('/agent/clients.php');
  assert(await p.eval('typeof RivetDialog.alert === "function" && typeof RivetDialog.confirm === "function" && typeof RivetDialog.prompt === "function"'), 'no RivetDialog on the agent layout');
  await p.clearCookies();
  await go('/login.php');
  assert(await p.eval('typeof window.RivetDialog === "object"'), 'no RivetDialog on login.php');
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
}, { allowHttp: [/./] });

await T('confirm: text only (markup shown literally), safe default focus, ARIA, OK = true', async () => {
  await go('/agent/clients.php');
  await start(`RivetDialog.confirm('<img src=x onerror="window.__xss=1"> & <b>bold</b>\\nline two', { title: '<i>T</i>', variant: 'danger', okText: 'Delete' })`);
  await p.waitSel('.rivet-dialog');
  const d = await dlg();
  assert(d.role === 'alertdialog' && d.modal === 'true', 'role/aria-modal: ' + JSON.stringify(d));
  assert(d.lab === '<i>T</i>' && d.desc.startsWith('<img src=x'), 'label/description not literal text: ' + JSON.stringify(d));
  assert(!(await p.exists('.rivet-dialog img, .rivet-dialog b, .rivet-dialog i')), 'message markup was parsed as HTML');
  assert(!(await p.eval('window.__xss')), 'XSS payload executed');
  assert(d.active === 'Cancel' && d.activeIn, 'default focus should be Cancel, was ' + d.active);
  assert(d.btns[1].includes('btn-danger') && d.btns[1].startsWith('Delete'), 'danger variant: ' + JSON.stringify(d.btns));
  await p.click('.rivet-dialog .btn-danger');
  assert((await result()) === true, 'OK should resolve true');
  await gone();
});

await T('confirm: Esc and Cancel and backdrop and X all resolve false; focus returns to the opener', async () => {
  await go('/agent/clients.php');
  for (const how of ['esc', 'cancel', 'backdrop', 'x']) {
    await p.eval(`(()=>{let b=document.getElementById('rd-opener');if(!b){b=document.createElement('button');b.id='rd-opener';b.textContent='opener';document.body.prepend(b);}b.focus();})()`);
    await start(`RivetDialog.confirm('Sure?')`);
    await p.waitSel('.rivet-dialog');
    if (how === 'esc') await p.press('Escape');
    else if (how === 'cancel') await p.click('.rivet-dialog .btn-secondary');
    else if (how === 'x') await p.click('.rivet-dialog .btn-close');
    else await p.eval(`(()=>{const r=document.querySelector('.rivet-dialog');r.dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));r.dispatchEvent(new MouseEvent('click',{bubbles:true}));})()`);
    assert((await result()) === false, how + ' should resolve false');
    await gone();
    assert(await p.eval(`document.activeElement && document.activeElement.id==='rd-opener'`), how + ': focus did not return to the opener');
  }
});

await T('prompt: value on OK / Enter, null on Esc, password type, required blocks empty', async () => {
  await go('/agent/clients.php');
  await start(`RivetDialog.prompt('Name?', { defaultValue: 'abc' })`);
  await p.waitSel('.rivet-dialog input');
  assert((await dlg()).activeTag === 'INPUT', 'prompt should focus its input');
  await p.type('.rivet-dialog input', 'hello <b>');
  await p.press('Enter');
  assert((await result()) === 'hello <b>', 'Enter should resolve the typed value');
  await gone();
  await start(`RivetDialog.prompt('Name?')`);
  await p.waitSel('.rivet-dialog input');
  await p.press('Escape');
  assert((await result()) === null, 'Esc should resolve null');
  await gone();
  await start(`RivetDialog.prompt('Secret?', { type: 'password', required: true })`);
  await p.waitSel('.rivet-dialog input');
  assert(await p.eval(`document.querySelector('.rivet-dialog input').type === 'password'`), 'not a password field');
  await p.click('.rivet-dialog .btn-primary');
  assert(await p.exists('.rivet-dialog'), 'required prompt closed on empty OK');
  await p.type('.rivet-dialog input', 's3');
  await p.click('.rivet-dialog .btn-primary');
  assert((await result()) === 's3', 'password value');
  await gone();
});

await T('alert: resolves true on OK; queued dialogs show one at a time in order', async () => {
  await go('/agent/clients.php');
  await p.eval(`window.__order = []; RivetDialog.alert('one').then(()=>window.__order.push('a1')); RivetDialog.confirm('two').then(v=>window.__order.push('c2:'+v)); RivetDialog.prompt('three').then(v=>window.__order.push('p3:'+v)); 0`);
  await p.waitSel('.rivet-dialog');
  assert((await p.eval(`document.querySelectorAll('.rivet-dialog').length`)) === 1, 'more than one dialog at a time');
  assert((await dlg()).desc === 'one', 'first dialog should be "one"');
  await p.click('.rivet-dialog .btn-primary');
  await p.waitFor(`document.querySelector('.rivet-dialog .modal-body')?.textContent==='two'`);
  await p.click('.rivet-dialog .btn-primary');
  await p.waitFor(`document.querySelector('.rivet-dialog .modal-body')?.textContent.startsWith('three')`);
  await p.press('Escape');
  await p.waitFor(`window.__order.length===3`);
  assert((await p.eval('window.__order.join(",")')) === 'a1,c2:true,p3:null', 'order/results: ' + await p.eval('window.__order.join(",")'));
  await gone();
});

await T('focus trap: Tab and Shift+Tab stay inside the dialog; the page behind is inert', async () => {
  await go('/agent/clients.php');
  await start(`RivetDialog.confirm('Trap?')`);
  await p.waitSel('.rivet-dialog');
  for (let i = 0; i < 5; i++) { await p.press('Tab'); assert((await dlg()).activeIn, 'focus left the dialog on Tab ' + i); }
  for (let i = 0; i < 5; i++) { await p.press('Tab', { modifiers: 8 }); assert((await dlg()).activeIn, 'focus left the dialog on Shift+Tab ' + i); }
  assert(await p.eval(`[...document.body.children].filter(n=>!n.classList.contains('rivet-dialog')&&!n.classList.contains('modal-backdrop')&&n.tagName!=='SCRIPT').every(n=>n.inert)`), 'page content is not inert');
  await p.press('Escape'); await result(); await gone();
  assert(await p.eval(`[...document.body.children].every(n=>!n.inert)`), 'inert not removed after close');
});

await T('stacks on top of an open Bootstrap modal: it stays open, Esc closes only the dialog, the dialog is on top', async () => {
  await go('/agent/clients.php');
  await p.eval(`document.querySelector('button.ajax-modal[data-modal-url*="client_add"]').click()`);
  await p.waitFor(`document.querySelector('.modal.show:not(.rivet-dialog) .modal-content')`, { timeout: 15000 });
  await sleep(500);
  await start(`RivetDialog.confirm('Stacked?')`);
  await p.waitSel('.rivet-dialog');
  const top = await p.eval(`(()=>{const c=document.querySelector('.rivet-dialog .modal-content').getBoundingClientRect();const e=document.elementFromPoint(c.left+c.width/2,c.top+20);return !!e.closest('.rivet-dialog')})()`);
  assert(top, 'dialog is not on top of the open modal');
  assert((await dlg()).activeIn, 'focus not in dialog');
  await p.press('Escape');
  assert((await result()) === false, 'Esc should cancel the stacked confirm');
  await gone();
  assert(await p.eval(`!!document.querySelector('.modal.show:not(.rivet-dialog)')`), 'Esc closed the underlying modal too');
  await p.press('Escape'); await sleep(500);
});

await T('data-confirm-submit: Cancel stops the submit, OK submits exactly once (as the clicked button), a second click while open is ignored', async () => {
  await go('/agent/clients.php');
  await p.eval(`(()=>{window.__subs=[];const f=document.createElement('form');f.id='rd-form';f.method='post';f.action='#';f.setAttribute('data-confirm-submit','Delete it?');f.setAttribute('data-confirm-variant','danger');
    f.innerHTML='<input name="x" value="1"><button id="rd-go" name="go" value="yes">Go</button>';document.body.prepend(f);
    document.addEventListener('submit',e=>{if(e.target===f){e.preventDefault();window.__subs.push(e.submitter&&e.submitter.name);}});})()`);
  await p.eval("document.getElementById('rd-go').click()");
  await p.waitSel('.rivet-dialog');
  await p.eval(`document.getElementById('rd-form').requestSubmit(document.getElementById('rd-go'))`);   // second attempt while open
  assert((await p.eval(`document.querySelectorAll('.rivet-dialog').length`)) === 1, 'second dialog opened');
  await p.press('Escape'); await gone();
  assert((await p.eval('window.__subs.length')) === 0, 'cancel did not stop the submit');
  await p.eval("document.getElementById('rd-go').click()");
  await p.waitSel('.rivet-dialog');
  assert((await dlg()).btns[1].includes('btn-danger'), 'data-confirm-variant not applied');
  await p.click('.rivet-dialog .btn-danger');
  await gone(); await sleep(200);
  assert((await p.eval('window.__subs.join()')) === 'go', 'confirmed submit: ' + await p.eval('window.__subs.join()'));
});

await T('real page flow: a script error alert (agent/alerts.php bulk action with nothing selected) is a modal', async () => {
  await go('/agent/alerts.php');
  assert(await p.eval('typeof bulkAction === "function"'), 'bulkAction missing on alerts.php');
  await p.eval(`bulkAction('acknowledge'); 0`);
  await p.waitSel('.rivet-dialog');
  assert((await dlg()).desc === 'No alerts selected.', 'wrong message: ' + JSON.stringify(await dlg()));
  await p.click('.rivet-dialog .btn-primary'); await gone();
});

await T('phone width: the dialog fits in 390 px and the page does not scroll sideways', async () => {
  await p.setViewport(390, 800, true);
  await go('/agent/clients.php');
  await start(`RivetDialog.confirm('A long message '.repeat(40) + 'x'.repeat(120), { title: 'Phone' })`);
  await p.waitSel('.rivet-dialog');
  const r = await p.eval(`(()=>{const c=document.querySelector('.rivet-dialog .modal-content').getBoundingClientRect(),d=document.documentElement;return {l:c.left,r:c.right,vw:d.clientWidth,over:d.scrollWidth-d.clientWidth,btnVisible:[...document.querySelectorAll('.rivet-dialog .modal-footer button')].every(b=>{const q=b.getBoundingClientRect();return q.bottom<=innerHeight&&q.right<=innerWidth})}})()`);
  assert(r.l >= 0 && r.r <= r.vw && r.over <= 1 && r.btnVisible, 'phone layout: ' + JSON.stringify(r));
  await p.press('Escape'); await result(); await gone();
  await p.setViewport(1366, 900);
});

await T('dark theme: the dialog follows the theme (background differs from the light dialog)', async () => {
  await go('/agent/clients.php');
  const colour = async (theme) => {
    await p.eval(`document.documentElement.setAttribute('data-bs-theme','${theme}'); document.documentElement.setAttribute('data-theme','${theme}')`);
    await start(`RivetDialog.alert('Theme')`);
    await p.waitSel('.rivet-dialog');
    const c = await p.eval(`getComputedStyle(document.querySelector('.rivet-dialog .modal-content')).backgroundColor`);
    await p.click('.rivet-dialog .btn-primary'); await gone();
    return c;
  };
  const light = await colour('light'), dark = await colour('dark');
  assert(light !== dark, `same background in both themes (${light})`);
});

await T('no native dialog on a representative sweep of pages (traps + CDP)', async () => {
  for (const path of ['/agent/dashboard.php', '/agent/tickets.php', '/agent/assets.php', '/agent/user/user_security.php', '/agent/user/user_preferences.php', '/admin/settings_integrations.php', '/admin/settings_notification.php']) {
    await go(path);
  }
}, { allowHttp: [/./] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
