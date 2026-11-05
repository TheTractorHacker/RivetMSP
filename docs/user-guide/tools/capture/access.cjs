// Screenshots for docs/user-guide/34-users-roles-security.md and 35-compliance-admin.md (group "access").
// Needs seeds 00, 01, 12, 80 and 81 applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/access.cjs
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/
//
// Nothing is saved: pop-ups are opened and closed, never submitted.

const lib = require('../lib.cjs');
const { launch, login, goto, shot, clearCallouts, settle } = lib;
const G = 'access';
const W = 1440;

// Call-outs take Playwright selectors here: each is resolved (first match) and tagged, then lib.callout draws the badge.
let _n = 0;
async function callout(page, items) {
  const out = [];
  for (const it of items) {
    const loc = page.locator(it.selector).first();
    if (!(await loc.count())) fail('call-out target missing: ' + it.selector);
    const id = 'co' + (++_n);
    await loc.evaluate((el, v) => el.setAttribute('data-ug', v), id);
    out.push({ ...it, selector: `[data-ug="${id}"]` });
  }
  return lib.callout(page, out);
}
function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
async function check(page, text = []) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}
async function mark(locator, id) { await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id); return `[data-ug="${id}"]`; }
async function openModal(page, opener) {
  await opener.click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(900);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('.modal.show'), null, { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(500);
}
async function tab(page, label) { await page.locator('.modal.show .nav-link', { hasText: label }).click(); await page.waitForTimeout(500); }
async function rowOf(page, text) { return page.locator('table tbody tr', { hasText: text }).first(); }
// Crop a screenshot to one card (callouts are absolutely positioned, so they stay inside the card).
async function card(page, titleText) {
  const c = page.locator('.card', { has: page.locator('h3.card-title, h4.card-title', { hasText: titleText }) }).last();
  return mark(c, 'c-' + titleText.replace(/\W+/g, ''));
}

(async () => {
  const { browser, page } = await launch({ width: W, height: 1000 });
  await login(page, 'admin');

  // ===================================================================== PAGE 34
  // 01 name menu
  await goto(page, '/agent/clients.php');
  await page.locator('li.user-menu > a').click();
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: 'li.user-menu > a', n: 1 },
    { selector: '.user-menu .dropdown-menu a[href="/admin/"]', n: 2 },
    { selector: '.user-menu .dropdown-menu a[href="/agent/user/user_details.php"]', n: 3 },
  ]);
  await shot(page, `${G}/01-name-menu`, { keepHover: true });
  await clearCallouts(page);

  // 02 users list
  await goto(page, '/admin/users.php');
  await check(page, ['Riley Chen', 'Devon Park']);
  const rileyRow = await mark(await rowOf(page, 'Riley Chen'), 'riley');
  await callout(page, [
    { selector: 'button.ajax-modal:has-text("New User")', n: 1 },
    { selector: '.btn-group .dropdown-toggle-split', n: 2, side: 'tr' },
    { selector: 'input[name=q]', n: 3 },
    { selector: 'a:has-text("Archived")', n: 4, side: 'tr' },
    { selector: 'th:has-text("MFA")', n: 5 },
    { selector: `${rileyRow} a:has-text("Edit")`, n: 6 },
    { selector: `${rileyRow} a:has-text("Skills")`, n: 7 },
    { selector: `${rileyRow} .dropdown button`, n: 8, side: 'tr' },
  ]);
  await shot(page, `${G}/02-users-list`, { fullPage: true });
  await clearCallouts(page);

  // 03 New User - details
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New User' }).first());
  await page.fill('.modal.show input[name=name]', 'Taylor Brandt');
  await page.fill('.modal.show input[name=email]', 'taylor.brandt@lakeshoremsp.example');
  await page.fill('.modal.show input[name=password]', 'correct-horse-battery-7');
  await callout(page, [
    { selector: '.modal.show select[name=role] + .select2', n: 1 },
    { selector: '.modal.show input[name=password]', n: 2 },
    { selector: '.modal.show .js-generate-password', n: 3, side: 'tr' },
    { selector: '.modal.show #forceMFACheckBox', n: 4 },
    { selector: '.modal.show .nav-link:has-text("Access")', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/03-new-user`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // 04 Edit - Access tab (Riley)
  await setSize(page, W, 1300);
  await goto(page, '/admin/users.php');
  await openModal(page, (await rowOf(page, 'Riley Chen')).locator('a:has-text("Edit")'));
  await tab(page, 'Access');
  await callout(page, [
    { selector: '.modal.show .alert-info', n: 1 },
    { selector: '.modal.show .js-toggle-all-clients', n: 2 },
    { selector: '.modal.show .border.rounded', n: 3 },
  ]);
  await shot(page, `${G}/04-user-access-tab`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);

  // 05 Edit - Security tab (Avery, who has two-factor, a passkey and trusted devices)
  await closeModal(page);
  await goto(page, '/admin/users.php');
  await openModal(page, (await rowOf(page, 'Avery Stone')).locator('a:has-text("Edit")'));
  await tab(page, 'Security');
  await callout(page, [
    { selector: '.modal.show .btn-outline-danger:has-text("Disable")', n: 1 },
    { selector: '.modal.show .btn-outline-danger[title="Delete passkey"]', n: 2 },
    { selector: '.modal.show .btn-outline-warning:has-text("Revoke All")', n: 3 },
  ]);
  await shot(page, `${G}/05-user-security-tab`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  await setSize(page, W, 1000);
  // 06 row menu
  await goto(page, '/admin/users.php');
  await (await rowOf(page, 'Riley Chen')).locator('.dropdown button').click();
  await page.waitForTimeout(400);
  await shot(page, `${G}/06-user-row-menu`, { keepHover: true });
  await page.keyboard.press('Escape');

  // 07 Archive pop-up (Devon, not saved)
  await goto(page, '/admin/users.php');
  await (await rowOf(page, 'Devon Park')).locator('.dropdown button').click();
  await page.locator('.dropdown-menu.show a:has-text("Archive")').click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(900);
  await callout(page, [
    { selector: '.modal.show select[name=ticket_assign] + .select2', n: 1 },
    { selector: '.modal.show button[name=archive_user]', n: 2 },
  ]);
  await shot(page, `${G}/07-archive-user`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // 08 Archived view
  await goto(page, '/admin/users.php?archived=1');
  await check(page, ['Casey Lindgren']);
  await (await rowOf(page, 'Casey')).locator('.dropdown button').click();
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: 'a:has-text("Archived")', n: 1, side: 'tr' },
    { selector: '.dropdown-menu.show a:has-text("Restore")', n: 2 },
  ]);
  await shot(page, `${G}/08-users-archived`, { keepHover: true });
  await clearCallouts(page);
  await page.keyboard.press('Escape');

  // 09 IR pop-up
  await goto(page, '/admin/users.php');
  await page.locator('.dropdown-toggle-split').click();
  await page.locator('.dropdown-menu.show a:has-text("IR")').click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(900);
  await shot(page, `${G}/09-ir-reset`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  // 10 Roles list
  await goto(page, '/admin/roles.php');
  await check(page, ['Billing clerk', 'Read-only viewer']);
  await callout(page, [
    { selector: 'button.ajax-modal:has-text("New Role")', n: 1 },
    { selector: 'tbody tr:nth-child(1) td:nth-child(2)', n: 2 },
    { selector: 'tbody tr:nth-child(1) td:nth-child(3)', n: 3 },
    { selector: 'tbody tr:nth-child(4) .dropdown button', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/10-roles-list`);
  await clearCallouts(page);

  // 11/12 Role editor: Details and Permissions (Billing clerk)
  await setSize(page, W, 1500);
  await openModal(page, (await rowOf(page, 'Billing clerk')).locator('a.ajax-modal').first());
  await callout(page, [
    { selector: '.modal.show input[name=role_name]', n: 1 },
    { selector: '.modal.show #admin_no', n: 2 },
  ]);
  await shot(page, `${G}/11-role-details`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await tab(page, 'Permissions');
  await callout(page, [
    { selector: '.modal.show .btn-group.js-btn-group-toggle >> nth=0', n: 1 },
    { selector: '.modal.show #pills-role-permissions .form-text >> nth=0', n: 2 },
    { selector: '.modal.show #pills-role-permissions .form-group >> nth=6', n: 3 },
  ]);
  await shot(page, `${G}/12-role-permissions`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);
  await setSize(page, W, 1000);

  // 13-16 Security settings
  await goto(page, '/admin/settings_security.php');
  await check(page, ['Sign-in policy', 'Vault Encryption']);
  let sel = await card(page, 'Vault Encryption');
  await shot(page, `${G}/13-vault-and-keys`, { selector: sel });
  sel = await card(page, 'Encryption keys');
  await shot(page, `${G}/14-encryption-keys`, { selector: sel });
  sel = await card(page, 'Security');
  await callout(page, [
    { selector: 'textarea[name=config_login_message]', n: 1 },
    { selector: 'input[name=config_login_key_required]', n: 2 },
    { selector: 'input[name=config_login_remember_me_expire]', n: 3 },
    { selector: 'input[name=config_login_session_lifetime]', n: 4 },
    { selector: 'input[name=config_log_retention]', n: 5 },
  ]);
  await shot(page, `${G}/15-security-card`, { selector: sel });
  await clearCallouts(page);
  sel = await card(page, 'Sign-in policy');
  await callout(page, [
    { selector: 'select[name=mfa_policy]', n: 1 },
    { selector: 'input[name=mfa_grace_days]', n: 2 },
    { selector: '#rememberSkipsMfa', n: 3 },
    { selector: 'input[name=password_min_length]', n: 4 },
    { selector: '#hibpCheck', n: 5 },
    { selector: 'input[name=session_idle_minutes]', n: 6 },
    { selector: 'input[name=vault_stepup_minutes]', n: 7 },
    { selector: 'input[name=vault_reveal_limit]', n: 8 },
  ]);
  await shot(page, `${G}/16-signin-policy`, { selector: sel });
  await clearCallouts(page);

  // 17 Account > Security (own)
  await goto(page, '/agent/user/user_security.php');
  await check(page, ['Two-Factor Authentication', 'Passkeys']);
  await callout(page, [
    { selector: 'button[name=edit_your_user_password]', n: 1 },
    { selector: 'button[data-bs-target="#enableMFAModal"]', n: 2, side: 'tr' },
    { selector: '#active-sessions button[name=sign_out_everywhere]', n: 3, side: 'tr' },
    { selector: '#addPasskeyBtn', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/17-account-security`);
  await clearCallouts(page);

  // 18 Audit log
  await goto(page, '/admin/audit_log.php?canned_date=all&type=User');
  await check(page, ['Audit Logs']);
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: 'select[name=client] + .select2', n: 2 },
    { selector: 'select[name=user] + .select2', n: 3 },
    { selector: 'select[name=type] + .select2', n: 4 },
    { selector: 'select[name=action] + .select2', n: 5 },
    { selector: 'button[data-bs-target="#advancedFilter"]', n: 6, side: 'tr' },
  ]);
  await shot(page, `${G}/18-audit-log`);
  await clearCallouts(page);

  // 19 Audit trail
  await goto(page, '/admin/audit_trail.php?type=user');
  await check(page, ['Integrity and copies']);
  await callout(page, [
    { selector: 'a:has-text("Export CSV")', n: 1, side: 'tr' },
    { selector: 'button[name=verify_audit_chain]', n: 2 },
    { selector: '#audit_sink_path', n: 3 },
    { selector: 'select[name=type]', n: 4 },
    { selector: 'select[name=actor]', n: 5 },
  ]);
  await shot(page, `${G}/19-audit-trail`);
  await clearCallouts(page);

  // 20 Restricted view: Riley sees only two clients
  const ctx2 = await browser.newContext({ viewport: { width: W, height: 800 } });
  const p2 = await ctx2.newPage();
  p2.setDefaultTimeout(20000);
  await login(p2, { email: 'riley.chen@lakeshoremsp.example', password: 'DemoPass#2026' });
  await goto(p2, '/agent/clients.php');
  await check(p2, ['Northshore Dental Group']);
  await callout(p2, [{ selector: 'table tbody tr:first-child td:nth-child(2)', n: 1 }]);
  await shot(p2, `${G}/20-scoped-clients`);
  await ctx2.close();

  // ===================================================================== PAGE 35
  // 30 Settings directory
  await goto(page, '/admin/settings.php');
  const grp = page.locator('.card, section, div', { has: page.getByText('Compliance status', { exact: true }) }).last();
  await callout(page, [
    { selector: 'a[href="/admin/settings_security.php"]', n: 1 },
    { selector: 'a[href="/admin/settings_compliance.php"]', n: 2 },
    { selector: 'a[href="/admin/compliance_status.php"]', n: 3 },
  ]);
  await shot(page, `${G}/30-settings-directory`, { fullPage: true });
  await clearCallouts(page);

  // 31 Compliance settings
  await goto(page, '/admin/settings_compliance.php');
  await check(page, ['Retention', 'In effect now']);
  await callout(page, [
    { selector: '#compliance_profile', n: 1 },
    { selector: '#audit_recording', n: 2 },
    { selector: '#audit_retention_days', n: 3 },
    { selector: '#log_retention_days', n: 4 },
    { selector: 'button[name=save_compliance_settings]', n: 5 },
  ]);
  await shot(page, `${G}/31-compliance-settings`, { fullPage: true });
  await clearCallouts(page);

  // 32 Compliance status header and scores
  await goto(page, '/admin/compliance_status.php');
  await check(page, ['Automatic checks', 'Manual checklist']);
  await callout(page, [
    { selector: 'a:has-text("Printable report")', n: 1 },
    { selector: 'a:has-text("Download CSV")', n: 2 },
    { selector: 'button[name=take_compliance_snapshot]', n: 3, side: 'tr' },
    { selector: '.row.g-3.mb-3 > div:nth-child(1) .card', n: 4 },
    { selector: '.row.g-3.mb-3 > div:nth-child(3) .card', n: 5 },
  ]);
  await page.setViewportSize({ width: W, height: 560 });
  await shot(page, `${G}/32-compliance-scores`);
  await clearCallouts(page);
  await page.setViewportSize({ width: W, height: 1000 });

  // 33 Framework filter + automatic checks (crop to the card)
  await goto(page, '/admin/compliance_status.php?framework=pci');
  sel = await card(page, 'Automatic checks');
  await callout(page, [
    { selector: 'th:has-text("Check")', n: 1, side: 'tr' },
    { selector: 'th:has-text("Result")', n: 2 },
    { selector: 'th:has-text("Finding")', n: 3 },
    { selector: 'a:has-text("Fix this")', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/33-automatic-checks`, { selector: sel });
  await clearCallouts(page);

  // 34 Manual checklist, one review form open
  await goto(page, '/admin/compliance_status.php');
  await page.locator('button:has-text("Record review")').nth(2).click();
  await page.waitForTimeout(600);
  sel = await card(page, 'Manual checklist');
  await callout(page, [
    { selector: 'th:has-text("State")', n: 1 },
    { selector: '[data-ug="c-Manualchecklist"] tbody tr .badge', n: 2 },
    { selector: 'button:has-text("Record review") >> nth=2', n: 3 },
    { selector: 'input[name=reviewer_name] >> visible=true', n: 4 },
    { selector: 'input[name=note] >> visible=true', n: 5 },
  ]);
  await shot(page, `${G}/34-manual-checklist`, { selector: sel });
  await clearCallouts(page);

  // 35 Shared on the portal + Snapshots
  const shared = await card(page, 'Shared on the portal');
  const snaps = await card(page, 'Snapshots');
  await page.locator('button:has-text("Share")').first().click().catch(() => {});
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: `${shared} button[name=unpublish_compliance_report]`, n: 1 },
    { selector: `${snaps} a:has-text("Report") >> nth=0`, n: 2 },
    { selector: `${snaps} a:has-text("CSV") >> nth=0`, n: 3 },
    { selector: `${snaps} button:has-text("Share")`, n: 4 },
  ]);
  const both = await page.evaluate(() => {
    const a = document.querySelectorAll('.card');
    return a.length;
  });
  await page.locator('[data-ug="c-Snapshots"]').scrollIntoViewIfNeeded();
  await shot(page, `${G}/35-snapshots`, { selector: snaps });
  await clearCallouts(page);
  await shot(page, `${G}/36-shared-on-portal`, { selector: shared });

  // 37 Printable report
  await goto(page, '/admin/compliance_report.php?format=html');
  await shot(page, `${G}/37-auditor-report`);

  // 38 Portal Security page
  const ctx3 = await browser.newContext({ viewport: { width: W, height: 1000 } });
  const p3 = await ctx3.newPage();
  p3.setDefaultTimeout(20000);
  await login(p3, 'portal');
  await goto(p3, '/client/compliance.php');
  await check(p3, ['self-assessment']);
  await shot(p3, `${G}/38-portal-security`, { fullPage: true });
  await ctx3.close();

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
async function setSize(page, w, h) { await page.setViewportSize({ width: w, height: h }); }
