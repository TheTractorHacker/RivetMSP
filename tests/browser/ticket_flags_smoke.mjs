// Browser smoke for the ticket page's "Count toward SLA" and "Email notifications" switches and RivetDialog.choice (RivetMSP 26.10.41).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... TICKET_ID=<an open ticket with SLA and email off> \
//   node tests/browser/ticket_flags_smoke.mjs        (or SESSION_ID=<a forged scratch session id> instead of the e-mail and password)
//
// Same driver, harness and safety rules as the other smokes (loopback only, no npm dependencies, real login form). window.alert/confirm/prompt are
// trapped to throw and every CDP javascriptDialogOpening is recorded: any native dialog fails the check. Needs a scratch install with a default SLA
// policy (so turning SLA on produces due dates) and TICKET_ID pointing at an open ticket whose two switches are off (an RMM alert ticket by default).
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD, TID = parseInt(process.env.TICKET_ID || '0', 10);
if (!BASE || !TID || (!process.env.SESSION_ID && (!EMAIL || !PASSWORD))) { console.error('Set BASE_URL, TICKET_ID and ADMIN_EMAIL + ADMIN_PASSWORD (or SESSION_ID); optionally SMOKE_OUT, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: use a throwaway loopback install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 1000);
// Pre-existing, not part of this feature: the reply-draft autosave on agent/ticket.php calls tinymce.get() on a 3 s timer, which throws until the
// deferred tinymce script has run (seen on slow scratch page loads). Counted and listed, not failed.
const KNOWN = [{ re: /tinymce is not defined/, note: 'pre-existing: agent/ticket.php reply-draft autosave calls tinymce.get() before the deferred tinymce script has loaded' }];
const H = new Harness({ page: p, outDir, edition: 'ticketflags', base: BASE, known: KNOWN });
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
}, { allowHttp: [/./] });
const PAGE = `/agent/ticket.php?ticket_id=${TID}`;
const go = async () => { await p.goto('about:blank'); await p.goto(BASE + PAGE); await p.waitSel('#ticketFlagSwitches'); await sleep(300); };
const checked = (id) => p.eval(`document.getElementById('${id}').checked`);
const dlg = () => p.eval(`(()=>{const d=document.querySelector('.rivet-dialog');if(!d)return null;return {title:d.querySelector('.modal-title').textContent,
  btns:[...d.querySelectorAll('.modal-footer button')].map(b=>b.textContent+'|'+(b.getAttribute('data-choice')||'')),active:document.activeElement?.textContent}})()`);
const gone = () => p.waitFor(`!document.querySelector('.rivet-dialog')`);

if (process.env.SESSION_ID) {
  // Scratch harness option: reuse a session the test harness forged (tests/endpoint_agent_lib.php ea_forge_session) instead of the login form.
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

await T('ticket page: both switches off, No email marker, reply banner with the one-reply override', async () => {
  await go();
  assert(!(await checked('ticketCountsSla')) && !(await checked('ticketSendEmail')), 'switches should start off');
  const txt = await p.eval('document.body.innerText');
  assert(/No email/.test(txt), 'no "No email" marker');
  assert(await p.exists('#ticketEmailOffBanner'), 'no reply banner');
});

await T('SLA on: RivetDialog.choice with Cancel focused; Esc cancels and the switch stays off', async () => {
  await p.click('#ticketCountsSla');
  await p.waitSel('.rivet-dialog');
  const d = await dlg();
  assert(d.title === 'Count toward SLA', 'title ' + d.title);
  assert(d.btns.length === 3 && d.btns[0].startsWith('Cancel') && d.btns[1] === 'Start from now|now' && d.btns[2] === 'Start from ticket creation|created', 'buttons ' + JSON.stringify(d.btns));
  assert(d.active === 'Cancel', 'initial focus ' + d.active);
  await p.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await gone();
  assert(!(await checked('ticketCountsSla')), 'switch flipped although cancelled');
  assert(/ticket\.php/.test(await p.url()), 'navigated away on cancel');
});

await T('SLA on, "Start from now": saved, switch on, "SLA counted from" shown', async () => {
  await p.click('#ticketCountsSla');
  await p.waitSel('.rivet-dialog');
  await p.clickNav('.rivet-dialog [data-choice="now"]');
  await p.waitSel('#ticketFlagSwitches'); await sleep(300);
  assert(await checked('ticketCountsSla'), 'switch not on after save');
  const txt = await p.eval('document.body.innerText');
  assert(/SLA counted from \d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(txt), 'no "SLA counted from"');
  assert(/counts toward SLA, starting now/.test(txt), 'no success message');
});

await T('SLA off: RivetDialog.confirm, then saved off', async () => {
  await p.click('#ticketCountsSla');
  await p.waitSel('.rivet-dialog');
  const d = await dlg();
  assert(d.btns.some(b => b.startsWith('Stop counting')), 'confirm buttons ' + JSON.stringify(d.btns));
  await p.clickNav('.rivet-dialog .modal-footer button.btn-warning');
  await p.waitSel('#ticketFlagSwitches'); await sleep(300);
  assert(!(await checked('ticketCountsSla')), 'switch still on');
});

await T('Email on (no question) then off (RivetDialog.confirm)', async () => {
  await p.clickNav('#ticketSendEmail');
  await p.waitSel('#ticketFlagSwitches'); await sleep(300);
  assert(await checked('ticketSendEmail'), 'email not on');
  assert(!(await p.exists('#ticketEmailOffBanner')), 'banner still shown with email on');
  await p.click('#ticketSendEmail');
  await p.waitSel('.rivet-dialog');
  await p.clickNav('.rivet-dialog .modal-footer button.btn-warning');
  await p.waitSel('#ticketFlagSwitches'); await sleep(300);
  assert(!(await checked('ticketSendEmail')), 'email still on');
});

await T('RivetDialog.choice API: resolves the value, null on Cancel, text-only labels', async () => {
  await p.eval(`window.__r = undefined; RivetDialog.choice('<b>x</b>', [{ value: 'a', label: '<i>A</i>' }, { value: 'b', label: 'B', variant: 'danger' }]).then(v => { window.__r = v === null ? '__null' : v; }); 0`);
  await p.waitSel('.rivet-dialog');
  assert(!(await p.exists('.rivet-dialog b, .rivet-dialog i')), 'markup parsed as HTML');
  assert(await p.eval(`document.querySelector('.rivet-dialog [data-choice="b"]').className.includes('btn-danger')`), 'variant not applied');
  await p.click('.rivet-dialog [data-choice="a"]');
  await p.waitFor('window.__r !== undefined');
  assert(await p.eval('window.__r') === 'a', 'value ' + await p.eval('window.__r'));
  await p.eval(`window.__r = undefined; RivetDialog.choice('m', [{ value: 'a', label: 'A' }]).then(v => { window.__r = v === null ? '__null' : v; }); 0`);
  await p.waitSel('.rivet-dialog');
  await p.click('.rivet-dialog .modal-footer .btn-secondary');
  await p.waitFor('window.__r !== undefined');
  assert(await p.eval('window.__r') === '__null', 'cancel should give null');
  assert(await p.eval(`RivetDialog.choice('m', []).then(v => v === null)`), 'no choices should resolve null at once');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
