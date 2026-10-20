// Browser smoke for Administration > Integrations > RMM > "Alert tickets" (RivetMSP 26.10.37).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/rmm_alert_category_smoke.mjs
//
// Same driver, harness and safety rules as the other smokes (loopback only, no npm dependencies, real login form). Needs a scratch install with the RMM module on
// and at least one Ticket category (it creates "Maintenance" itself through the one-click button when none exists).
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD; optionally SMOKE_OUT, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: use a throwaway loopback install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 1000);
const H = new Harness({ page: p, outDir, edition: 'alertcat', base: BASE });
const assert = (c, m) => { if (!c) throw new Error(m); };
const PAGE = '/admin/settings_integrations.php?tab=rmm';
const go = async () => { await p.goto('about:blank'); await p.goto(BASE + PAGE); await p.waitSel('#alert-tickets-card'); await sleep(300); };
const summary = () => p.eval(`document.getElementById('at-summary').innerText`);
const setSel = (sel, value) => p.evalWith((s, v) => { const e = document.querySelector(s); e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); return e.value === v; }, sel, value);
const rows = () => p.eval(`document.querySelectorAll('#at-rules tbody tr.at-rule').length`);

await H.check('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/./] });

await H.check('card shows with plain-language summary and no category chosen', async () => {
  await go();
  assert(/Every other ticket from an alert has no category/.test(await summary()), 'summary: ' + await summary());
  assert(await p.visible('#at_default'), 'default dropdown not visible');
}, { allowHttp: [/./] });

await H.check('one-click Maintenance category (when none exists) selects it', async () => {
  if (await p.exists('button[name=create_alert_maintenance_category]')) {
    await p.clickNav('button[name=create_alert_maintenance_category]');
    await p.waitSel('#alert-tickets-card');
  } else {   // re-run on an install that already has it: choose it and save
    await p.evalWith(() => { const s = document.getElementById('at_default'); s.value = [...s.options].find(o => o.text === 'Maintenance').value; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
    await p.waitSel('#alert-tickets-card');
  }
  assert(!(await p.exists('button[name=create_alert_maintenance_category]')), 'button still there');
  assert(/Every other ticket from an alert goes in "Maintenance"/.test(await summary()), 'summary: ' + await summary());
}, { allowHttp: [/./] });

await H.check('add two rules, summary updates live, remove one', async () => {
  await go();
  await p.click('#at-add'); await p.click('#at-add');
  assert(await rows() === 2, 'rows ' + await rows());
  assert(await setSel('#at-rules tr.at-rule:nth-child(1) .at-when', 'check_prefix|disk'), 'when 1');
  assert(await p.evalWith(() => { const s = document.querySelector('#at-rules tr.at-rule:nth-child(1) .at-cat'); s.selectedIndex = 1; s.dispatchEvent(new Event('change', { bubbles: true })); return true; }), 'cat 1');
  assert(await setSel('#at-rules tr.at-rule:nth-child(2) .at-when', 'severity|critical'), 'when 2');
  await p.evalWith(() => { const s = document.querySelector('#at-rules tr.at-rule:nth-child(2) .at-cat'); s.selectedIndex = 1; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await setSel('#at-rules tr.at-rule:nth-child(2) .at-prio', 'High');
  const s = await summary();
  assert(/When a "Disk space" check fails, the ticket goes in/.test(s) && /When an alert is critical severity, the ticket goes in ".+" with High priority/.test(s), 'summary: ' + s);
  await p.click('#at-rules tr.at-rule:nth-child(1) .at-remove');
  assert(await rows() === 1 && !/Disk space/.test(await summary()), 'remove');
  await p.click('#at-add');
  assert(await setSel('#at-rules tr.at-rule:nth-child(2) .at-when', 'check_prefix|patch'), 'when 3');
  await p.evalWith(() => { const s = document.querySelector('#at-rules tr.at-rule:nth-child(2) .at-cat'); s.selectedIndex = 1; s.dispatchEvent(new Event('change', { bubbles: true })); });
}, { allowHttp: [/./] });

await H.check('save persists the rules and the page draws them back', async () => {
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-tickets-card'); await sleep(300);
  assert(await rows() === 2, 'rows after save ' + await rows());
  const s = await summary();
  assert(/critical severity/.test(s) && /"Patching" check/.test(s) && /with High priority/.test(s), 'summary after save: ' + s);
  assert(/Alert ticket settings saved/.test(await p.eval('document.body.innerText')), 'no success message');
}, { allowHttp: [/./] });

await H.check('an incomplete rule is refused with a plain message and nothing is lost', async () => {
  await p.click('#at-add');
  assert(await setSel('#at-rules tr.at-rule:nth-child(3) .at-when', 'severity|info'), 'when only');
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-tickets-card'); await sleep(300);
  assert(await rows() === 2, 'saved rules must be unchanged, rows=' + await rows());
  assert(/not saved/.test(await p.eval('document.body.innerText')), 'no plain error message shown');
}, { allowHttp: [/./] });

await H.check('phone width: no sideways scroll', async () => {
  await p.setViewport(390, 800, true);
  await go();
  assert(await p.eval('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'horizontal overflow ' + await p.eval('document.documentElement.scrollWidth'));
  await p.setViewport(1366, 1000);
  await go(); await p.screenshot(outDir + '/alert-tickets.png');
}, { allowHttp: [/./] });

await H.check('cleanup: back to no category and no rules', async () => {
  await go();
  while (await rows() > 0) { await p.click('#at-rules tr.at-rule .at-remove'); }
  await setSel('#at_default', '0');
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-tickets-card');
  assert(await rows() === 0 && /has no category/.test(await summary()), 'not cleared');
}, { allowHttp: [/./] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
