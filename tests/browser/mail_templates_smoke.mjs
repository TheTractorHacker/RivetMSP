// Browser smoke for Admin > Settings > Email templates (admin/settings_mail_templates.php, DB 2.6.107).
//
//   BASE_URL=http://127.0.0.1:<port> SESSION_ID=<a forged scratch ADMIN session id> node tests/browser/mail_templates_smoke.mjs
//   (or ADMIN_EMAIL + ADMIN_PASSWORD to sign in through the real login form)
//
// Same driver, harness and safety rules as the other smokes (loopback only, no npm dependencies). window.alert/confirm/prompt are
// trapped to throw and every CDP javascriptDialogOpening is recorded: any native dialog fails the check. Writes to the scratch install:
// saves and then resets the "Ticket created" template, and queues one [TEST] email to the admin's own address.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || (!process.env.SESSION_ID && (!EMAIL || !PASSWORD))) { console.error('Set BASE_URL and SESSION_ID (or ADMIN_EMAIL + ADMIN_PASSWORD); optionally SMOKE_OUT, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: use a throwaway loopback install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 1000);
const H = new Harness({ page: p, outDir, edition: 'mailtemplates', base: BASE });
const assert = (c, m) => { if (!c) throw new Error(m); };
const nativeOpened = [];
p.handlers.push((ev, pr) => { if (ev === 'Page.javascriptDialogOpening') nativeOpened.push(`${pr.type}: ${pr.message}`); });
await p.send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
  window.__native = [];
  for (const n of ['alert', 'confirm', 'prompt']) window[n] = function () { window.__native.push(n); throw new Error('NATIVE DIALOG window.' + n + '() called'); };
})()` });
const T = (name, fn) => H.check(name, async () => {
  nativeOpened.length = 0;
  await fn();
  const js = await p.eval('(window.__native || []).slice()').catch(() => []);
  assert(nativeOpened.length === 0 && js.length === 0, 'native dialog opened: ' + JSON.stringify([...nativeOpened, ...js]));
});
const PAGE = '/admin/settings_mail_templates.php';
const CARD = '#mt-card-ticket_created';
const go = async () => { await p.goto('about:blank'); await p.goto(BASE + PAGE); await p.waitSel(CARD); await p.waitFor('window.tinymce && window.MailTemplateBlocks && window.RivetDialog'); await sleep(300); };
const open = async (sel) => {
  await p.click(sel + ' .mt-card-toggle');
  await p.waitFor((s) => document.querySelector(s + ' .mt-block-list').dataset.mtInitialized === '1', { args: [sel] });
  await p.waitFor((s) => (document.querySelector(s + ' .js-mt-preview-frame').srcdoc || '').length > 500, { args: [sel], timeout: 10000, label: 'preview rendered' });
};
const state = (sel) => p.evalWith((s) => {
  const c = document.querySelector(s);
  const hidden = c.querySelector('.mt-body-hidden');
  return {
    types: [...c.querySelectorAll('.mt-block')].map((b) => b.dataset.type),
    editors: tinymce.get().length, body: hidden.value, original: hidden.defaultValue,
    subject: c.querySelector('.js-mt-preview-subject').textContent, srcdoc: c.querySelector('.js-mt-preview-frame').srcdoc,
    sandbox: c.querySelector('.js-mt-preview-frame').getAttribute('sandbox'), warnings: c.querySelector('.mt-warnings').textContent,
  };
}, sel);
const dlg = () => p.eval(`(()=>{const d=document.querySelector('.rivet-dialog');if(!d)return null;return {text:d.innerText,btns:[...d.querySelectorAll('.modal-footer button')].map(b=>b.textContent.trim())}})()`);

if (process.env.SESSION_ID) {
  await p.send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION_ID, url: BASE + '/' });
} else {
  await T('login: real form signs in', async () => {
    await p.goto(BASE + '/login.php');
    await p.type('input[name=email]', EMAIL);
    await p.type('input[name=password]', PASSWORD);
    await p.clickNav('button[name=login], button[type=submit]');
    assert(!/login\.php/.test(await p.url()), 'still on the login page');
  });
}

await T('page loads: seven collapsed cards, no template editor or preview built yet (lazy), footer scripts loaded', async () => {
  await go();
  const s = await p.eval(`({cards: document.querySelectorAll('.mail-template-card').length, blocks: document.querySelectorAll('.mt-block').length,
    editors: tinymce.get().map(e => e.id), shown: document.querySelectorAll('.mail-template-card .collapse.show').length,
    bs: typeof bootstrap, frames: [...document.querySelectorAll('.js-mt-preview-frame')].filter(f => f.srcdoc).length})`);
  assert(s.cards === 7, 'cards ' + s.cards);
  assert(s.blocks === 0 && s.shown === 0 && s.frames === 0, 'something was built before a card was opened: ' + JSON.stringify(s));
  assert(s.editors.every((id) => /^mt-(header|footer)-html$/.test(id)), 'only the shared header/footer editors may exist on load: ' + s.editors);
  assert(s.bs === 'object', 'bootstrap.bundle (includes/footer.php) not loaded');
});

await T('opening "Ticket created": its blocks, TinyMCE per text block, sandboxed live preview; nothing rewritten just by opening', async () => {
  await open(CARD);
  await sleep(800);   // let every block's TinyMCE finish initializing
  const s = await state(CARD);
  assert(s.types.join(',') === 'reply_marker,text,quote,text,text', 'blocks ' + s.types);
  assert(s.editors >= 2 + 4, 'TinyMCE instances ' + s.editors);
  assert(s.subject === 'Ticket Created - [TCK-1042] - Printer on 3rd floor is offline', 'preview subject ' + s.subject);
  assert(s.srcdoc.startsWith("<i style='color: #808080'>##- Please type your reply above this line -##</i><table") && s.srcdoc.includes('background-color:#f4f5f7'), 'preview is not the shell-wrapped real render');
  assert(!s.srcdoc.includes('RIVETMSP_BLOCKS') && !s.srcdoc.includes('{mt_accent}'), 'builder comment / accent token leaked into the preview');
  assert(s.sandbox === '', 'preview iframe must be sandboxed');
  assert(s.body === s.original, 'opening the card changed the stored body (TinyMCE re-serialization leaked in)');
  assert(s.warnings.trim() === '', 'warnings on the default: ' + s.warnings);
  if (process.env.SMOKE_SHOTS === '1') { await p.screenshot(resolve(outDir, 'mailtemplates-ticket-created-open.png')); }
  const other = await p.eval(`document.querySelectorAll('#mt-card-ticket_reply .mt-block').length`);
  assert(other === 0, 'another card was built too');
});

await T('adding a Spacer: hidden body = MailTemplateBlocks.serialize(blocks), preview re-rendered (debounced)', async () => {
  const before = (await state(CARD)).srcdoc;
  await p.click(CARD + ' .mt-add-block[data-type="spacer"]');
  await p.waitFor((s, b) => document.querySelector(s + ' .js-mt-preview-frame').srcdoc !== b, { args: [CARD, before], timeout: 5000, label: 'preview updated' });
  const s = await state(CARD);
  assert(s.types[s.types.length - 1] === 'spacer', 'spacer not appended');
  assert(s.body.includes('{"type":"spacer","size":16}') && s.body.includes('<div style="height:16px;line-height:1px;font-size:1px;">&nbsp;</div>'), 'serialized body lacks the spacer');
  assert(s.srcdoc.includes('height:16px'), 'preview lacks the spacer');
});

await T('drag-and-drop: moving the last block to the top reorders the blocks (SortableJS)', async () => {
  // SortableJS uses native HTML5 drag and drop: intercept the drag Chrome starts and replay it as real drag events.
  const from = await p.centre(CARD + ' .mt-block:last-child .mt-drag-handle');
  await p.send('Input.setInterceptDrags', { enabled: true });
  const intercepted = p.waitEvent('Input.dragIntercepted', () => true, 5000);
  await p.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: from.x, y: from.y });
  await p.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: from.x, y: from.y, button: 'left', clickCount: 1 });
  for (let i = 1; i <= 6; i++) { await p.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: from.x, y: from.y - 4 * i, button: 'left', buttons: 1 }); await sleep(30); }
  const { data } = await intercepted;
  const target = await p.centre(CARD + ' .mt-block:first-child .mt-drag-handle');
  for (const type of ['dragEnter', 'dragOver']) { await p.send('Input.dispatchDragEvent', { type, x: target.x, y: target.y - 4, data }); await sleep(120); }
  await p.send('Input.dispatchDragEvent', { type: 'drop', x: target.x, y: target.y - 4, data });
  await p.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: target.x, y: target.y - 4, button: 'left', clickCount: 1 });
  await p.send('Input.setInterceptDrags', { enabled: false });
  await sleep(600);
  const s = await state(CARD);
  assert(s.types[0] === 'spacer', 'order after drag ' + s.types);
  assert(s.body.indexOf('"type":"spacer"') < s.body.indexOf('"type":"reply_marker"'), 'hidden body not re-serialized in the new order');
  assert(/reply marker is not the first block/.test(s.warnings), 'no "marker not first" warning: ' + s.warnings);
});

await T('removing the reply marker: inline warning, then Save asks via RivetDialog (Cancel keeps you on the page)', async () => {
  await p.click(CARD + ' .mt-block[data-type="reply_marker"] .mt-block-delete');
  await sleep(200);
  const s = await state(CARD);
  assert(!s.types.includes('reply_marker'), 'marker block still there');
  assert(/reply marker is missing/.test(s.warnings), 'no missing-marker warning: ' + s.warnings);
  const url = await p.url();
  await p.click(CARD + ' button[name="save_mail_template"]');
  await p.waitSel('.rivet-dialog');
  const d = await dlg();
  assert(/reply marker is missing/.test(d.text) && d.btns.includes('Save anyway'), 'dialog ' + JSON.stringify(d));
  await p.click('.rivet-dialog .modal-footer .btn-secondary');
  await p.waitFor(`!document.querySelector('.rivet-dialog')`);
  await sleep(300);
  assert(await p.url() === url, 'navigated although cancelled');
});

await T('"Save anyway" saves (warning toast), the card shows Customized, the saved body has no marker', async () => {
  await p.click(CARD + ' button[name="save_mail_template"]');
  await p.waitSel('.rivet-dialog');
  await p.clickNav('.rivet-dialog .modal-footer button.btn-danger');
  await p.waitSel(CARD);
  const txt = await p.eval('document.body.innerText');
  assert(/Email template saved, but the reply marker is missing/.test(txt), 'no server-side warning toast');
  assert(await p.evalWith((s) => /Customized/.test(document.querySelector(s + ' .card-header').innerText), CARD), 'no Customized badge');
  const body = await p.evalWith((s) => document.querySelector(s + ' .mt-body-hidden').value, CARD);
  assert(body.startsWith('<!--RIVETMSP_BLOCKS:') && !/Please type your reply/.test(body.replace(/^<!--[\s\S]*?-->/, '')), 'saved body unexpected');
});

await T('Reset to default asks via RivetDialog, then restores the default (no Customized badge, marker back)', async () => {
  await p.waitFor('window.RivetDialog');
  await open(CARD);
  await p.click(CARD + ' button[name="reset_mail_template"]');
  await p.waitSel('.rivet-dialog');
  assert(/Reset this email to its default text/.test((await dlg()).text), 'reset dialog text');
  await p.clickNav('.rivet-dialog .modal-footer button.btn-danger');
  await p.waitSel(CARD);
  assert(/reset to the default text/i.test(await p.eval('document.body.innerText')), 'no reset toast');
  assert(!(await p.evalWith((s) => /Customized/.test(document.querySelector(s + ' .card-header').innerText), CARD)), 'still Customized after reset');
  const body = await p.evalWith((s) => document.querySelector(s + ' .mt-body-hidden').value, CARD);
  assert(body === await p.eval(`MailTemplateBlocks.serialize(MailTemplateBlocks.decompile(${JSON.stringify(body)}))`) && /reply_marker/.test(body), 'reset body is not the default');
});

await T('Send test email to me: RivetDialog confirm, then "queued" alert', async () => {
  await open(CARD);
  await p.click(CARD + ' .js-mt-test');
  await p.waitSel('.rivet-dialog');
  await p.click('.rivet-dialog .modal-footer .btn-primary');
  await p.waitFor(`document.querySelector('.rivet-dialog') && /Test email queued/.test(document.querySelector('.rivet-dialog').innerText)`, { timeout: 10000, label: 'queued alert' });
  await p.click('.rivet-dialog .modal-footer .btn-primary');
});

await T('phone width: no horizontal scroll on the page', async () => {
  await p.setViewport(390, 900, true);
  await go();
  await open(CARD);
  const w = await p.eval('document.documentElement.scrollWidth - document.documentElement.clientWidth');
  await p.setViewport(1366, 1000);
  assert(w <= 1, 'horizontal overflow ' + w + 'px');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
