// Operations (pages 36 and 37): Administration > Settings, Maintenance, Ticketing setup and Templates.
// Read-mostly against the shared demo. The only thing it writes is ONE backup file (Maintenance > Backups > Save to Server),
// and only when the server holds no backup yet. Pop-ups are opened and cancelled; nothing else is saved.
//
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8090 node docs/user-guide/tools/capture/operations.cjs
//   (GUIDE_CLEAN=1 for the call-out-free copies in images-clean/; ONLY=36 or ONLY=37 to run one page's shots)
//
// Needs seed 85-operations.php (extra statuses, holidays, canned responses, notification addresses, backup switches).

const { launch, login, shot, goto, settle, callout, clearCallouts, BASE } = require('../lib.cjs');
const g = 'operations';
const ONLY = process.env.ONLY || '';

async function clean(page, label) {
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught \w+|undefined)/);
  if (bad) throw new Error(`${label}: page shows "${bad[1]}"`);
}

// Give the first element of `selector` whose text contains `text` the id `id`, so a call-out can point at it.
async function mark(page, id, selector, text) {
  const ok = await page.evaluate(([id, selector, text]) => {
    const el = [...document.querySelectorAll(selector)].find((e) => !text || e.textContent.replace(/\s+/g, ' ').includes(text));
    if (!el) return false;
    el.id = id;
    return true;
  }, [id, selector, text]);
  if (!ok) throw new Error(`mark ${id}: nothing matches ${selector} / ${text}`);
}

async function openModal(page, url, wait = 900) {
  await page.locator(`[data-modal-url*="${url}"]`).first().click();
  await page.locator('.modal.show .modal-content').first().waitFor({ state: 'visible' });
  await page.waitForTimeout(wait);
}
async function closeModal(page) {
  await clearCallouts(page);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    document.querySelectorAll('.modal.show').forEach((m) => { const b = m.querySelector('[data-bs-dismiss="modal"]'); if (b) b.click(); });
  });
  await page.waitForTimeout(500);
}
async function co(page, items) {
  let i = 0;
  for (const it of items) {
    if (it.text) { const id = 'ug-auto-' + (i++); await mark(page, id, it.selector, it.text); it.selector = '#' + id; delete it.text; }
  }
  await callout(page, items);
}
const MODAL = '.modal.show .modal-dialog';

(async () => {
  const { browser, page } = await launch({ width: 1500, height: 900 });
  page.setDefaultTimeout(60000);
  await login(page, 'admin');

  const side = (label) => mark(page, 'ug-side', '.navbar-vertical a.nav-link', label);
  const tile = (id, text) => mark(page, id, '.admin-directory__item', text);

  // =====================================================================================================
  // Page 36: system settings and maintenance
  // =====================================================================================================
  if (!ONLY || ONLY === '36') {
    // 01 Open Administration from the account menu
    await goto(page, '/agent/clients.php');
    await page.click('a[aria-label="Account menu"]');
    await page.waitForTimeout(500);
    await mark(page, 'ug-admin-link', '.user-menu .dropdown-menu a', 'Administration');
    await co(page, [
      { selector: 'a[aria-label="Account menu"]', n: 1, side: 'bl' },
      { selector: '#ug-admin-link', n: 2, side: 'tr' },
    ]);
    await shot(page, `${g}/36-01-open-administration`);
    await clearCallouts(page);

    // 02 Settings directory
    await goto(page, '/admin/settings.php');
    await clean(page, 'settings');
    await side('Settings');
    await tile('ug-t-company', 'Company details');
    await tile('ug-t-modules', 'Modules');
    await co(page, [
      { selector: '#ug-side', n: 1, side: 'tr' },
      { selector: '.admin-directory__nav', n: 2, side: 'br' },
      { selector: '#ug-t-company', n: 3, side: 'tr' },
      { selector: '#ug-t-modules', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/36-02-settings-directory`);
    await clearCallouts(page);

    // 03 Modules
    await goto(page, '/admin/settings_module.php');
    await clean(page, 'modules');
    await mark(page, 'ug-m-charges', '.form-group', 'Show Ticket Charges');
    await mark(page, 'ug-m-portal', '.form-group', 'Enable Client Portal');
    await co(page, [
      { selector: '#customSwitch1', n: 1, side: 'tr' },
      { selector: '#ug-m-charges', n: 2, side: 'tr' },
      { selector: '#ug-m-portal', n: 3, side: 'tr' },
      { selector: 'button[name=edit_module_settings]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/36-03-modules`);
    await clearCallouts(page);

    // 04 Company details
    await goto(page, '/admin/settings_company.php');
    await clean(page, 'company');
    await co(page, [
      { selector: 'input[name=name]', n: 1, side: 'tr' },
      { selector: 'input[name=tax_id]', n: 2, side: 'tr' },
      { selector: 'button.btn-primary', text: 'Save', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/36-04-company-details`, { fullPage: true });
    await clearCallouts(page);

    // 05 Language and region
    await goto(page, '/admin/settings_localization.php');
    await clean(page, 'localization');
    await shot(page, `${g}/36-05-language-region`);

    // 06 Appearance
    await goto(page, '/admin/settings_appearance.php');
    await clean(page, 'appearance');
    await shot(page, `${g}/36-06-appearance`, { fullPage: true });

    // 07 Defaults
    await goto(page, '/admin/settings_default.php');
    await clean(page, 'defaults');
    await shot(page, `${g}/36-07-defaults`, { fullPage: true });

    // 08 Notifications
    await goto(page, '/admin/settings_notification.php');
    await clean(page, 'notifications');
    await shot(page, `${g}/36-08-notifications`, { fullPage: true });

    // 09 Maintenance directory
    await goto(page, '/admin/maintenance.php');
    await clean(page, 'maintenance');
    await side('Maintenance');
    await tile('ug-t-cron', 'Scheduled jobs');
    await tile('ug-t-backups', 'Backups');
    await tile('ug-t-update', 'Update');
    await co(page, [
      { selector: '#ug-side', n: 1, side: 'tr' },
      { selector: '#ug-t-cron', n: 2, side: 'tr' },
      { selector: '#ug-t-backups', n: 3, side: 'tr' },
      { selector: '#ug-t-update', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/36-09-maintenance-directory`);
    await clearCallouts(page);

    // 10 Scheduled jobs (Cron Manager)
    await goto(page, '/admin/cron.php');
    await clean(page, 'cron');
    await mark(page, 'ug-cron-warn', '.alert', 'No main');
    await mark(page, 'ug-cron-table', 'table', 'Job');
    await mark(page, 'ug-cron-unsched', 'h6', 'Available but not scheduled');
    await co(page, [
      { selector: 'button.btn-success', n: 1, side: 'bl' },
      { selector: '#ug-cron-warn', n: 2, side: 'tr' },
      { selector: '#ug-cron-table', n: 3, side: 'tr' },
      { selector: '#ug-cron-unsched', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/36-10-scheduled-jobs`);
    await clearCallouts(page);

    // 11 Mail queue
    await goto(page, '/admin/mail_queue.php');
    await clean(page, 'mail queue');
    await shot(page, `${g}/36-11-mail-queue`);

    // 12 Server status (retry while the shared demo box is busy so the picture shows a typical healthy server)
    for (let i = 0; i < 1; i++) {
      await goto(page, '/admin/server_status.php');
      const t = await page.locator('body').innerText();
      if (!/Needs attention/.test(t)) break;
      await page.waitForTimeout(15000);
    }
    await clean(page, 'server status');
    await mark(page, 'ug-ss-head', '.card', 'Server status');
    await mark(page, 'ug-ss-group', '.card', 'Application and database');
    await co(page, [
      { selector: '.badge.fs-6', n: 1, side: 'bl' },
      { selector: '#ug-ss-head .btn', n: 2, side: 'bl' },
      { selector: '#ug-ss-group', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/36-12-server-status`, { fullPage: true });
    await clearCallouts(page);

    // 13 Redis
    await goto(page, '/admin/settings_redis.php');
    await clean(page, 'redis');
    await shot(page, `${g}/36-13-redis`);

    // 14 Backups: make one real backup on first run, then shoot the page
    await goto(page, '/admin/backup.php');
    const hasBackup = !(await page.locator('body').innerText()).includes('No backups stored on server');
    if (!hasBackup) {
      await page.locator('a:has-text("Save to Server")').first().click();
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(3000);
      await goto(page, '/admin/backup.php');
    }
    await clean(page, 'backup');
    await mark(page, 'ug-bk-hero', '.card', 'System Backup');
    await mark(page, 'ug-bk-sched', '.card', 'Scheduled Backups');
    await mark(page, 'ug-bk-key', '.card', 'Encryption Key Backup');
    await co(page, [
      { selector: 'a.btn', text: 'Download Backup', n: 1, side: 'tr' },
      { selector: 'a.btn', text: 'Save to Server', n: 2, side: 'tr' },
      { selector: '#backup_auto', n: 3, side: 'tr' },
      { selector: 'input[name=config_backup_passphrase]', n: 4, side: 'tr' },
      { selector: '#backup_allow_unencrypted', n: 5, side: 'tr' },
      { selector: 'button[name=backup_master_key]', n: 6, side: 'tr' },
    ]);
    await shot(page, `${g}/36-14-backup`);
    await clearCallouts(page);

    // 15 Backups: lower half (remote storage, recovery status, sync watcher)
    await mark(page, 'ug-bk-s3', '.card', 'Remote Storage');
    await mark(page, 'ug-bk-rec', '.card', 'Recovery status');
    await page.evaluate(() => document.getElementById('ug-bk-s3').scrollIntoView());
    await co(page, [
      { selector: '#backup_s3_enabled', n: 1, side: 'tr' },
      { selector: 'button[name=backup_s3_test]', n: 2, side: 'tr' },
    ]);
    await shot(page, `${g}/36-15-backup-remote-storage`);
    await clearCallouts(page);

    await page.evaluate(() => document.getElementById('ug-bk-rec').scrollIntoView());
    await mark(page, 'ug-bk-watch', '.card', 'Integration sync watcher');
    await co(page, [
      { selector: '#ug-bk-rec .row', n: 1, side: 'tr' },
      { selector: '#ug-bk-watch', n: 2, side: 'tr' },
    ]);
    await shot(page, `${g}/36-16-backup-recovery-status`);
    await clearCallouts(page);

    await page.evaluate(() => document.getElementById('restore-drill').scrollIntoView());
    await co(page, [
      { selector: 'button[name=run_restore_drill]', n: 1, side: 'bl' },
      { selector: '#drill_enabled', n: 2, side: 'tr' },
      { selector: '#restore-drill .alert-warning', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/36-17-restore-drill`);
    await clearCallouts(page);

    await page.evaluate(() => { const h = [...document.querySelectorAll('.card-title')].find((e) => e.textContent.includes('Backup History')); if (h) h.scrollIntoView(); });
    await mark(page, 'ug-bk-hist', '.card', 'Backup History');
    await co(page, [
      { selector: '#ug-bk-hist .badge.text-bg-primary', n: 1, side: 'tr' },
      { selector: '#ug-bk-hist a[title=Download]', n: 2, side: 'tr' },
      { selector: '#ug-bk-hist a[title=Delete]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/36-18-backup-history`);
    await clearCallouts(page);

    // 19 Credential restore
    await goto(page, '/admin/credential_restore.php');
    await clean(page, 'credential restore');
    await shot(page, `${g}/36-19-credential-restore`);

    // 20 Update
    await goto(page, '/admin/update.php');
    await clean(page, 'update');
    await shot(page, `${g}/36-20-update`);
    await page.evaluate(() => { const h = [...document.querySelectorAll('.card-title, h2, h3, h4')].find((e) => e.textContent.trim() === 'Release channel'); if (h) h.scrollIntoView(); });
    await shot(page, `${g}/36-21-update-channel`);
  }

  // =====================================================================================================
  // Page 37: ticketing setup and templates
  // =====================================================================================================
  if (!ONLY || ONLY === '37' || ONLY === 'fix') {
    // 01 Ticketing directory
    await goto(page, '/admin/ticketing_setup.php');
    await clean(page, 'ticketing setup');
    await side('Ticketing');
    await tile('ug-t-status', 'Ticket statuses');
    await tile('ug-t-sla', 'SLA policies');
    await tile('ug-t-auto', 'Ticket automation');
    await co(page, [
      { selector: '#ug-side', n: 1, side: 'tr' },
      { selector: '#ug-t-status', n: 2, side: 'tr' },
      { selector: '#ug-t-auto', n: 3, side: 'tr' },
      { selector: '#ug-t-sla', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/37-01-ticketing-setup`);
    await clearCallouts(page);

    // 02 Ticket statuses
    await goto(page, '/admin/ticket_status.php');
    await clean(page, 'statuses');
    await mark(page, 'ug-st-pause', 'td', 'Paused');
    await mark(page, 'ug-st-inactive', 'td', 'Inactive');
    await co(page, [
      { selector: 'button', text: 'New Ticket Status', n: 1, side: 'bl' },
      { selector: 'th', text: 'SLA clock', n: 2, side: 'tr' },
      { selector: '#ug-st-inactive', n: 3, side: 'tr' },
      { selector: 'tbody tr:nth-child(6) .dropdown button', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/37-02-ticket-statuses`);
    await clearCallouts(page);

    // 03 New status pop-up
    await openModal(page, 'ticket_status_add.php');
    await page.fill('.modal.show input[name=name]', 'Waiting on Vendor');
    await co(page, [
      { selector: '.modal.show input[name=name]', n: 1, side: 'tr' },
      { selector: '.modal.show input[name=color]', n: 2, side: 'tr' },
      { selector: '.modal.show input[name=pauses_sla]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/37-03-new-status`, { selector: MODAL });
    await closeModal(page);

    // 04 SLA policies
    await goto(page, '/admin/sla_policies.php');
    await clean(page, 'sla policies');
    await co(page, [
      { selector: 'button', text: 'New Policy', n: 1, side: 'bl' },
      { selector: 'th', text: 'Targets', n: 2, side: 'tr' },
      { selector: 'th', text: 'Pause Statuses', n: 3, side: 'tr' },
      { selector: 'span.badge', text: 'Default', n: 4, side: 'tr' },
    ]);
    await shot(page, `${g}/37-04-sla-policies`);
    await clearCallouts(page);

    // 05 New policy pop-up
    await openModal(page, 'policy_add.php');
    await co(page, [
      { selector: '.modal.show input[name=name], .modal.show input[name=policy_name]', n: 1, side: 'tr' },
      { selector: '.modal.show .ts-wrapper, .modal.show .select2-container, .modal.show select[multiple]', n: 2, side: 'tr' },
      { selector: '.modal.show table', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/37-05-new-sla-policy`, { selector: MODAL });
    await closeModal(page);

    // 06 Business hours
    await goto(page, '/admin/sla_calendars.php');
    await clean(page, 'business hours');
    await shot(page, `${g}/37-06-business-hours`);

    // 07 Edit calendar (hours and holidays)
    await page.setViewportSize({ width: 1500, height: 1500 });
    await openModal(page, 'calendar_edit.php');
    await shot(page, `${g}/37-07-edit-business-hours`, { selector: MODAL });
    await closeModal(page);
    await page.setViewportSize({ width: 1500, height: 900 });

    // 08 Holidays
    await goto(page, '/admin/holidays.php');
    await clean(page, 'holidays');
    await co(page, [
      { selector: 'button', text: 'Load Holidays', n: 1, side: 'bl' },
      { selector: 'select[name=country], select', n: 2, side: 'tr' },
    ]);
    await shot(page, `${g}/37-08-holidays`);
    await clearCallouts(page);

    // 09 Automation rules
    await goto(page, '/admin/ticket_automation.php');
    await clean(page, 'automation');
    await co(page, [
      { selector: 'a', text: 'Run Log', n: 1, side: 'bl' },
      { selector: 'button', text: 'New Rule', n: 2, side: 'bl' },
      { selector: 'tbody tr:first-child td:nth-child(3)', n: 3, side: 'tr' },
      { selector: 'tbody tr:first-child td:nth-child(4)', n: 4, side: 'tr' },
      { selector: 'tbody tr:first-child td:nth-child(5)', n: 5, side: 'tr' },
    ]);
    await shot(page, `${g}/37-09-automation-rules`);
    await clearCallouts(page);

    // 10 New rule pop-up (filled in, not saved)
    await openModal(page, 'add_rule.php');
    await page.fill('.modal.show input[name=rule_name], .modal.show input[name=name]', 'Note on high priority tickets');
    await page.selectOption('#ruleTrigger', 'ticket_created');
    await page.waitForTimeout(400);
    await page.selectOption('.modal.show select[name="cond_field[]"]', 'priority');
    await page.fill('.modal.show .cond-row input[type=text]', 'high');
    await page.selectOption('.modal.show select[name="action_name[]"]', 'add_note');
    await page.waitForTimeout(300);
    await co(page, [
      { selector: '.modal.show #ruleTrigger', n: 1, side: 'tr' },
      { selector: '.modal.show .cond-row', n: 2, side: 'tr' },
      { selector: '.modal.show .action-row', n: 3, side: 'tr' },
    ]);
    await shot(page, `${g}/37-10-new-rule`, { selector: MODAL });
    await closeModal(page);

    // 11 Run log
    await goto(page, '/admin/ticket_automation_log.php');
    await clean(page, 'automation log');
    await shot(page, `${g}/37-11-automation-run-log`);

    // 12 Tags
    await goto(page, '/admin/catalog_setup.php');
    await clean(page, 'catalog');
    await shot(page, `${g}/37-12-tags-and-categories`);
    await goto(page, '/admin/tag.php?type=6');
    await clean(page, 'tags');
    await shot(page, `${g}/37-13-ticket-tags`);

    // 14 Categories
    await goto(page, '/admin/category.php?category=ticket');
    await clean(page, 'categories');
    await shot(page, `${g}/37-14-ticket-categories`, { fullPage: true });

    // 15 New category pop-up
    await page.locator('[data-modal-url*="category_add.php"]').first().click();
    await page.locator('.modal.show .modal-content').first().waitFor({ state: 'visible' });
    await page.waitForTimeout(900);
    await shot(page, `${g}/37-15-new-category`, { selector: MODAL });
    await closeModal(page);

    // 16 Templates directory
    await goto(page, '/admin/template_library.php');
    await clean(page, 'templates');
    await shot(page, `${g}/37-16-templates-directory`, { fullPage: true });

    // 17 Ticket templates
    await goto(page, '/admin/ticket_template.php');
    await clean(page, 'ticket templates');
    await shot(page, `${g}/37-17-ticket-templates`);

    // 18 One template's tasks
    await page.locator('a:has-text("New hire setup")').first().click();
    await page.waitForLoadState('domcontentloaded');
    await settle(page);
    await clean(page, 'ticket template tasks');
    await shot(page, `${g}/37-18-ticket-template-tasks`, { fullPage: true });

    // 19 Canned responses
    await goto(page, '/admin/canned_responses.php');
    await clean(page, 'canned responses');
    await shot(page, `${g}/37-19-canned-responses`);

    // 20 Settings > Ticketing
    await goto(page, '/admin/settings_ticket.php');
    await clean(page, 'ticket settings');
    await shot(page, `${g}/37-20-settings-ticketing`, { fullPage: true });
  }

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
