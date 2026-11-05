// Screenshots for docs/user-guide/03-clients-contacts-locations.md and 04-client-workflows-and-compliance-per-client.md
// (group "clients"). Needs seeds 00-core, 10-clients-tags-notes, 11-client-workflows and 12-client-compliance applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/clients.cjs
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/
//
// Read-only with respect to app data: pop-ups are opened and filled with illustrative values but never saved.

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');
const G = 'clients';
const W = 1680;

function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
async function check(page, { rows = 0, text = [] } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access/.test(body)) fail(`unexpected page on ${page.url()}`);
  if (rows && (await page.locator('table tbody tr').count()) < rows) fail(`too few rows on ${page.url()}`);
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}
async function mark(locator, id) {
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}
async function setSize(page, w, h) { await page.setViewportSize({ width: w, height: h }); }
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
async function pickTom(page, selectSel, label) {
  await page.click(`${selectSel} + .ts-wrapper .ts-control`);
  await page.locator('.ts-dropdown .option', { hasText: label }).first().click();
  await page.waitForTimeout(300);
}
// Hide cards that belong to other guides (CRM) so they do not distract.
async function hideCrm(page) {
  await page.evaluate(() => {
    document.querySelectorAll('h5.card-title, h3.card-title').forEach((h) => {
      if (/Open Opportunities|Activity Timeline/.test(h.textContent)) {
        const card = h.closest('.card'); const row = card && card.closest('.row');
        (row && row.children.length === 1 ? row : card).style.display = 'none';
      }
    });
  });
}
async function clientId(page, name) {
  await goto(page, `/agent/clients.php?q=${encodeURIComponent(name)}`);
  const href = await page.locator('table tbody tr a[href^="client_overview.php"]').first().getAttribute('href');
  if (!href) fail(`client ${name} not found`);
  return new URL(href, 'http://x/agent/').searchParams.get('client_id');
}
async function contactUrl(page, name) {
  await goto(page, `/agent/contacts.php?q=${encodeURIComponent(name)}`);
  const href = await page.locator('table tbody tr a[href^="contact_details.php"]').first().getAttribute('href');
  if (!href) fail(`contact ${name} not found`);
  return '/agent/' + href;
}
async function runUrl(page, q) {
  await goto(page, `/agent/workflow_runs.php?q=${encodeURIComponent(q)}`);
  const href = await page.locator('table tbody tr a[href^="workflow_run.php"]').first().getAttribute('href');
  if (!href) fail(`run ${q} not found`);
  return '/agent/' + href;
}

(async () => {
  const { browser, page } = await launch({ width: W, height: 1000 });
  await login(page, 'admin');

  // ================================================================ PAGE 03 ==========
  // 01 Clients list
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await check(page, { rows: 9, text: ['Northshore Dental Group', 'Showing 1 to 9 of 9'] });
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: 'button[data-bs-target="#advancedFilter"]', n: 2, side: 'tr' },
    { selector: '.btn-toolbar .btn-group.me-2', n: 3 },
    { selector: '.btn-toolbar .btn-group:not(.me-2)', n: 4, side: 'tr' },
    { selector: '.card-tools .btn-group', n: 5, side: 'tr' },
    { selector: 'table tbody tr:first-child a .fa-info-circle', n: 6, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(6)', n: 7 },
    { selector: 'table tbody tr:first-child button.btn-secondary', n: 8, side: 'tl' },
  ]);
  await shot(page, `${G}/01-clients-list`, { fullPage: true });
  await clearCallouts(page);

  // 02 Filters: tag filter applied (panel opens itself)
  const tagId = await page.locator('select[name="tags[]"] option', { hasText: 'Regulated' }).first().getAttribute('value');
  if (!tagId) fail('client tag "Regulated" missing (seed 10)');
  await goto(page, `/agent/clients.php?sort=client_name&order=ASC&tags%5B%5D=${tagId}`);
  await page.waitForTimeout(700);
  await check(page, { rows: 3 });
  await callout(page, [
    { selector: '#dateFilter', n: 1 },
    { selector: 'select[name="tags[]"] + .ts-wrapper', n: 2 },
    { selector: 'select[name=industry] + .ts-wrapper', n: 3 },
    { selector: 'select[name=referral] + .ts-wrapper', n: 4 },
  ]);
  await shot(page, `${G}/02-clients-filters`);
  await clearCallouts(page);

  // 03 Records pop-up
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await page.locator('table tbody tr').first().locator('a .fa-info-circle').hover();
  await page.waitForTimeout(1200);
  await shot(page, `${G}/03-clients-records-popup`, { keepHover: true });

  // 04-07 New Client pop-up, four tabs
  await setSize(page, W, 1300);
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Client' }).first());
  await page.fill('.modal.show input[name=name]', 'Summit Ridge Dental');
  await page.fill('.modal.show input[name=abbreviation]', 'SRD');
  await page.fill('.modal.show input[name=type]', 'Healthcare');
  await page.fill('.modal.show input[name=website]', 'summitridgedental.example');
  await page.click('.modal.show .modal-title');
  const locTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Location' }), 'nc-loc');
  const conTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Contact' }), 'nc-con');
  const bilTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Billing' }), 'nc-bil');
  await callout(page, [
    { selector: '.modal.show input[name=name]', n: 1, side: 'tr' },
    { selector: '.modal.show input[name=abbreviation]', n: 2, side: 'tr' },
    { selector: '.modal.show .card.bg-light', n: 3, side: 'tr' },
    { selector: conTab, n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/04-new-client-details`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);

  await page.click(locTab); await page.waitForTimeout(500);
  await page.fill('.modal.show input[name=address]', '12 Lakeside Court');
  await page.fill('.modal.show input[name=city]', 'Madison');
  await page.fill('.modal.show input[name=state]', 'WI');
  await page.fill('.modal.show input[name=zip]', '53705');
  await page.fill('.modal.show input[name=location_phone]', '6085550190');
  await shot(page, `${G}/05-new-client-location`, { selector: '.modal.show .modal-content' });

  await page.click(conTab); await page.waitForTimeout(500);
  await page.fill('.modal.show input[name=contact]', 'Dr. Elise Park');
  await page.fill('.modal.show input[name=title]', 'Owner');
  await page.fill('.modal.show input[name=contact_phone]', '6085550191');
  await page.fill('.modal.show input[name=contact_email]', 'elise.park@summitridgedental.example');
  await callout(page, [
    { selector: '.modal.show input[name=contact]', n: 1, side: 'tr' },
    { selector: '.modal.show button[name=add_client]', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/06-new-client-contact`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);

  await page.click(bilTab); await page.waitForTimeout(500);
  await page.fill('.modal.show input[name=tax_id_number]', '39-5550199');
  await callout(page, [
    { selector: '.modal.show input[name=rate]', n: 1, side: 'tr' },
    { selector: '.modal.show select[name=net_terms] + .ts-wrapper', n: 2, side: 'tr' },
    { selector: '.modal.show input[name=tax_id_number]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/07-new-client-billing`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);
  await setSize(page, W, 1000);

  // 08 Bulk Action menu
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await page.locator('table tbody tr').nth(0).locator('input.bulk-select').check();
  await page.locator('table tbody tr').nth(1).locator('input.bulk-select').check();
  await page.waitForTimeout(300);
  await page.click('#bulkActionButton button.dropdown-toggle');
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: '#bulkActionButton button.dropdown-toggle', n: 1 },
    { selector: '#bulkActionButton .dropdown-menu', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/08-clients-bulk-menu`);
  await clearCallouts(page);

  // 09 Archived clients + row menu with Restore
  await goto(page, '/agent/clients.php?archived=1');
  await check(page, { rows: 1, text: ['Old Mill Hardware Co.'] });
  await page.locator('table tbody tr').first().locator('button.btn-secondary').click();
  await page.waitForTimeout(400);
  const restore = await mark(page.locator('.dropdown-menu.show a', { hasText: 'Restore' }), 'cl-restore');
  await callout(page, [
    { selector: '.btn-toolbar .btn-group:not(.me-2) a.btn', n: 1 },
    { selector: restore, n: 2, side: 'tl' },
  ]);
  await shot(page, `${G}/09-clients-archived`);
  await clearCallouts(page);
  await page.keyboard.press('Escape');

  // 10 Import pop-up
  await setSize(page, W, 900);
  await goto(page, '/agent/clients.php');
  await page.locator('.card-tools button.dropdown-toggle').click();
  await page.waitForTimeout(300);
  await openModal(page, page.locator('.card-tools .dropdown-menu a', { hasText: 'Import' }));
  await callout(page, [
    { selector: '.modal.show input[type=file]', n: 1, side: 'tr' },
    { selector: '.modal.show a[href*="download_clients_csv_template"]', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/10-client-import`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // 11 Client overview
  const nsId = await clientId(page, 'Northshore Dental Group');
  await goto(page, `/agent/client_overview.php?client_id=${nsId}`);
  await check(page, { text: ['Key Contacts', 'Quick Notes'] });
  await hideCrm(page);
  await callout(page, [
    { selector: 'a.client-nav-back, .client-nav-back', n: 1, side: 'br' },
    { selector: 'button[aria-label="Client actions"]', n: 2, side: 'tl' },
    { selector: '.card-group', n: 3, side: 'tr' },
    { selector: '.list-group:has(.js-dept-stat)', n: 4, side: 'tr' },
    { selector: '#clientNotes', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/11-client-overview`, { fullPage: true });
  await clearCallouts(page);

  // 12 Client actions menu
  await page.click('button[aria-label="Client actions"]');
  await page.waitForTimeout(400);
  await shot(page, `${G}/12-client-actions-menu`);
  await page.keyboard.press('Escape');

  // 13 Contacts list
  await goto(page, `/agent/contacts.php?client_id=${nsId}`);
  await check(page, { rows: 3, text: ['Hannah Brooks'] });
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: 'select[name="tags[]"] + .ts-wrapper', n: 2 },
    { selector: 'select[name=location] + .ts-wrapper', n: 3 },
    { selector: '.card-tools .btn-group', n: 4, side: 'tr' },
    { selector: 'table tbody tr:nth-child(2) td:nth-child(2) .badge', n: 5, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(2) small.text-success', n: 6, side: 'tr' },
    { selector: 'table tbody tr:first-child button.btn-secondary', n: 7, side: 'tl' },
  ]);
  await shot(page, `${G}/13-contacts-list`);
  await clearCallouts(page);

  // 14 / 15 New Contact pop-up: Details and Access
  await setSize(page, W, 1300);
  await goto(page, `/agent/contacts.php?client_id=${nsId}`);
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Contact' }).first());
  await page.fill('.modal.show input[name=name]', 'Jordan Lee');
  await page.fill('.modal.show input[name=title]', 'Front Desk Coordinator');
  await page.fill('.modal.show input[name=department]', 'Administration');
  await page.fill('.modal.show input[name=phone]', '6085550143');
  await page.fill('.modal.show input[name=email]', 'jordan.lee@northshoredental.example');
  await page.click('.modal.show .modal-title');
  const accTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Access' }), 'ct-acc');
  await callout(page, [
    { selector: '.modal.show input[name=name]', n: 1, side: 'tr' },
    { selector: '.modal.show input[name=contact_primary]', n: 2, side: 'tr' },
    { selector: '.modal.show select[name=location] + .ts-wrapper', n: 3, side: 'tr' },
    { selector: accTab, n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/14-new-contact-details`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await page.click(accTab); await page.waitForTimeout(500);
  await pickTom(page, '.modal.show select.authMethod', 'Using Set Password');
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: '.modal.show select.authMethod + .ts-wrapper', n: 1, side: 'tr' },
    { selector: '.modal.show .passwordGroup', n: 2, side: 'tr' },
    { selector: '#contactBillingCheckbox', n: 3, side: 'tr' },
    { selector: '#contactTechnicalCheckbox', n: 4, side: 'tr' },
    { selector: '#contactImportantCheckbox', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/15-new-contact-access`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);
  await setSize(page, W, 1000);

  // 16 Contact details page
  const hannah = await contactUrl(page, 'Hannah Brooks');
  await goto(page, hannah);
  await check(page, { text: ['Hannah Brooks', 'Notes'] });
  await hideCrm(page);
  await callout(page, [
    { selector: 'button[data-modal-url*="contact_edit.php"]', n: 1, side: 'tl' },
    { selector: '#contactNotes', n: 2, side: 'tr' },
    { selector: '.btn-group.mb-3 .btn-primary', n: 3, side: 'tr' },
    { selector: '.btn-group.mb-3 .btn-outline-primary', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/16-contact-details`);
  await clearCallouts(page);

  // 17 Contacts bulk menu
  await goto(page, `/agent/contacts.php?client_id=${nsId}`);
  await page.locator('table tbody tr').nth(0).locator('input.bulk-select').check();
  await page.locator('table tbody tr').nth(1).locator('input.bulk-select').check();
  await page.waitForTimeout(300);
  await page.click('#bulkActionButton button.dropdown-toggle');
  await page.waitForTimeout(400);
  await callout(page, [{ selector: '#bulkActionButton .dropdown-menu', n: 1, side: 'tr' }]);
  await shot(page, `${G}/17-contacts-bulk-menu`);
  await clearCallouts(page);

  // 18 Row menu on a contact (archive and anonymise)
  await goto(page, `/agent/contacts.php?client_id=${nsId}`);
  await page.locator('table tbody tr').nth(2).locator('button.btn-secondary').click();
  await page.waitForTimeout(400);
  await shot(page, `${G}/18-contact-row-menu`);
  await page.keyboard.press('Escape');

  // 19 Archived contacts of Birchwood
  const bwId = await clientId(page, 'Birchwood Law Partners');
  await goto(page, `/agent/contacts.php?client_id=${bwId}&archived=1`);
  await check(page, { rows: 1, text: ['Pat Lindgren'] });
  await callout(page, [{ selector: '.btn-group.float-end a.btn', n: 1 }]);
  await shot(page, `${G}/19-contacts-archived`);
  await clearCallouts(page);

  // 20 Client Overview > Contacts (all clients)
  await goto(page, '/agent/contacts.php');
  await check(page, { rows: 5 });
  await callout(page, [
    { selector: 'select[name=client] + .ts-wrapper', n: 1, side: 'tr' },
    { selector: 'table thead th:nth-last-child(2)', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/20-contacts-all-clients`);
  await clearCallouts(page);

  // 21 Locations list
  const gpId = await clientId(page, 'Granite Peak Manufacturing');
  await goto(page, `/agent/locations.php?client_id=${gpId}`);
  await check(page, { rows: 2, text: ['Plant 1'] });
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: '.card-tools .btn-group', n: 2, side: 'tr' },
    { selector: 'table tbody tr:first-child small.text-success', n: 3, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(3) a', n: 4, side: 'tr' },
    { selector: 'table tbody tr:first-child button.btn-secondary', n: 5, side: 'tl' },
  ]);
  await shot(page, `${G}/21-locations-list`);
  await clearCallouts(page);

  // 22 New Location pop-up
  await setSize(page, W, 1100);
  await goto(page, `/agent/locations.php?client_id=${gpId}`);
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Location' }).first());
  await page.fill('.modal.show input[name=name]', 'Warehouse Annex');
  await page.fill('.modal.show input[name=description]', 'Raw material store');
  await page.click('.modal.show .modal-title');
  const addrTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Address' }), 'nl-addr');
  await callout(page, [
    { selector: '.modal.show input[name=name]', n: 1, side: 'tr' },
    { selector: '.modal.show input[name=location_primary]', n: 2, side: 'tr' },
    { selector: addrTab, n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/22-new-location`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);
  await setSize(page, W, 1000);

  // ================================================================ PAGE 04 ==========
  // 01 Runs list
  await goto(page, '/agent/workflow_runs.php');
  await check(page, { rows: 4, text: ['In Progress', 'Completed With Exceptions', 'Cancelled'] });
  await callout(page, [
    { selector: '.card-tools .btn-primary', n: 1, side: 'tr' },
    { selector: 'select[name=type] + .ts-wrapper', n: 2 },
    { selector: 'select[name=status] + .ts-wrapper', n: 3 },
    { selector: 'table tbody tr:first-child td:nth-child(5)', n: 4, side: 'tr' },
    { selector: 'table tbody tr:first-child a.btn', n: 5, side: 'tl' },
  ]);
  await shot(page, `${G}/30-workflow-runs`);
  await clearCallouts(page);

  // 02 Start workflow pop-up
  await setSize(page, W, 900);
  await goto(page, '/agent/workflow_runs.php');
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'Start Workflow' }));
  await page.locator('.modal.show select[name=workflow_template_id]').selectOption({ label: '[Onboarding] New client onboarding' }).catch(() => {});
  await callout(page, [
    { selector: '.modal.show select[name=workflow_template_id] + .ts-wrapper', n: 1, side: 'tr' },
    { selector: '.modal.show select[name=client_id] + .ts-wrapper', n: 2, side: 'tr' },
    { selector: '.modal.show select[name=contact_id] + .ts-wrapper', n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/31-start-workflow`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // 03 Run in progress
  await setSize(page, W, 1000);
  await goto(page, await runUrl(page, 'Kestrel'));
  await check(page, { text: ['Checklist', 'Cancel Workflow'] });
  const skipBtn = await mark(page.locator('button.btn-outline-warning').first(), 'wf-skip');
  await callout(page, [
    { selector: '.card-header .badge.text-bg-success', n: 1, side: 'tr' },
    { selector: '.card-body .fa-check-circle', n: 2, side: 'tr' },
    { selector: 'button[name=complete_workflow_run_task]', n: 3, side: 'tl' },
    { selector: skipBtn, n: 4, side: 'tr' },
    { selector: 'button[name=cancel_workflow_run]', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/32-workflow-run`, { fullPage: true });
  await clearCallouts(page);

  // 04 Skip pop-up (opened, not saved)
  await page.click(skipBtn);
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(600);
  await page.fill('.modal.show input[name=skip_reason]', 'Client declined the welcome call');
  await shot(page, `${G}/33-skip-task`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  // 05 Completed with exceptions
  await goto(page, await runUrl(page, 'Old Mill'));
  await check(page, { text: ['Skipped:'] });
  await callout(page, [
    { selector: '.card-header .badge.text-bg-warning', n: 1, side: 'tr' },
    { selector: '.fa-minus-circle', n: 2, side: 'tr' },
    { selector: 'button[name=reopen_workflow_run_task]', n: 3, side: 'tl' },
  ]);
  await shot(page, `${G}/34-workflow-run-exceptions`, { fullPage: true });
  await clearCallouts(page);

  // 06 Templates (Administration)
  await goto(page, '/admin/workflow_templates.php');
  await check(page, { rows: 4, text: ['New client onboarding', 'Inactive'] });
  await callout(page, [
    { selector: '.card-tools .btn-primary', n: 1, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(3)', n: 2, side: 'tr' },
    { selector: 'table tbody tr:last-child td:nth-child(4)', n: 3, side: 'tr' },
    { selector: 'table tbody tr:first-child button.btn-secondary', n: 4, side: 'tl' },
  ]);
  await shot(page, `${G}/35-workflow-templates`);
  await clearCallouts(page);

  // 07 Template details
  await page.locator('table tbody tr td:first-child a', { hasText: 'New client onboarding' }).first().click();
  await settle(page);
  await check(page, { text: ['Add Task', 'Tasks'] });
  await callout(page, [
    { selector: 'button[name=move_workflow_template_task_down]', n: 1, side: 'tr' },
    { selector: 'input[name=title]', n: 2, side: 'tr' },
    { selector: 'input[name=required]', n: 3, side: 'tr' },
    { selector: 'button[name=add_workflow_template_task]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/36-workflow-template-details`, { fullPage: true });
  await clearCallouts(page);

  // 08 New template pop-up
  await goto(page, '/admin/workflow_templates.php');
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Template' }));
  await page.fill('.modal.show input[name=name]', 'Branch office opening');
  await callout(page, [
    { selector: '.modal.show input[name=name]', n: 1, side: 'tr' },
    { selector: '.modal.show select[name=type] + .ts-wrapper', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/37-new-workflow-template`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // ---- Compliance -----------------------------------------------------------------------
  // 09 Overview of all clients
  await goto(page, '/agent/compliance_clients.php');
  await check(page, { rows: 3, text: ['Client compliance', 'Shared'] });
  await callout(page, [
    { selector: 'table tbody tr:first-child td:nth-child(2)', n: 1, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(3)', n: 2, side: 'tr' },
    { selector: 'table thead th:nth-child(5)', n: 3, side: 'tr' },
    { selector: 'table tbody tr:first-child td:nth-child(8)', n: 4, side: 'tr' },
    { selector: 'details summary', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/40-compliance-overview`);
  await clearCallouts(page);

  // 10 Client compliance page: top
  await goto(page, `/agent/client_compliance.php?client_id=${nsId}`);
  await check(page, { text: ['Standards that apply', 'Checklist', 'Snapshots'] });
  await callout(page, [
    { selector: 'a[href*="client_compliance_report.php"][href*="html"]', n: 1, side: 'tl' },
    { selector: 'button[name=take_client_compliance_snapshot]', n: 2, side: 'tr' },
    { selector: 'button[name=set_client_compliance_frameworks]', n: 3, side: 'tr' },
    { selector: '.row.g-3 .col-6:first-child .card', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/41-client-compliance`);
  await clearCallouts(page);

  // 11 Security event-log presets card
  const presets = page.locator('.card', { has: page.locator('h4', { hasText: 'Security event log presets' }) }).first();
  if (await presets.count()) {
    await presets.screenshot({ path: require('path').join(require('../lib.cjs').OUT, G, '42-log-presets-card.png') });
    console.log('saved clients/42-log-presets-card');
  }

  // 12 Checklist with a review form open
  await page.locator('button:has-text("Record review")').nth(5).click();
  await page.waitForTimeout(500);
  await page.evaluate(() => { const c = [...document.querySelectorAll('.card-header h4')].find((h) => /Checklist/.test(h.textContent)); c && c.scrollIntoView(); });
  await page.waitForTimeout(300);
  await mark(page.locator('.card', { has: page.locator('h4', { hasText: 'Checklist' }) }), 'cl-card');
  const rev = await mark(page.locator('tr.collapse.show form').first(), 'rev-form');
  await callout(page, [
    { selector: 'table.table-sm thead th:nth-child(2)', n: 1, side: 'tr' },
    { selector: 'table.table-sm tbody tr:first-child td:nth-child(3)', n: 2, side: 'tr' },
    { selector: rev, n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/43-compliance-checklist`, { selector: '[data-ug="cl-card"]' });
  await clearCallouts(page);

  // 13 Share and snapshots
  await page.locator('button:has-text("Share")').last().click().catch(() => {});
  await page.waitForTimeout(500);
  const shareCard = page.locator('.card', { has: page.locator('h4', { hasText: 'Share with the client' }) }).first();
  await shareCard.scrollIntoViewIfNeeded();
  const snapCard = page.locator('.card', { has: page.locator('h4', { hasText: 'Snapshots' }) }).first();
  await mark(snapCard.locator('tbody tr').first().locator('a', { hasText: 'Report' }), 'sn-report');
  await mark(snapCard.locator('tbody tr').first().locator('button'), 'sn-share1');
  await mark(snapCard.locator('tbody tr').nth(2).locator('button'), 'sn-share2');
  await callout(page, [
    { selector: 'button[name=unshare_client_compliance]', n: 1, side: 'tr' },
    { selector: '[data-ug="sn-report"]', n: 2, side: 'tr' },
    { selector: '[data-ug="sn-share1"]', n: 3, side: 'tr' },
    { selector: '[data-ug="sn-share2"]', n: 4, side: 'tr' },
  ]);
  const bothCards = await page.evaluate(() => {
    const find = (t) => [...document.querySelectorAll('.card')].find((c) => c.querySelector('h4') && c.querySelector('h4').textContent.includes(t));
    const a = find('Share with the client').getBoundingClientRect(), b = find('Snapshots').getBoundingClientRect();
    return { x: Math.max(0, Math.min(a.left, b.left) - 10), y: a.top + window.scrollY - 10, w: Math.max(a.width, b.width) + 20, h: b.bottom - a.top + 20 };
  });
  await page.mouse.move(2, 2);
  const out = require('path').join(require('../lib.cjs').OUT, G, '44-compliance-share-snapshots.png');
  require('fs').mkdirSync(require('path').dirname(out), { recursive: true });
  const vp = page.viewportSize();
  await setSize(page, vp.width, Math.min(4200, Math.ceil(bothCards.y + bothCards.h + 120)));
  await page.waitForTimeout(500);
  await page.screenshot({ path: out, clip: { x: bothCards.x, y: bothCards.y, width: bothCards.w, height: bothCards.h } });
  console.log('saved clients/44-compliance-share-snapshots');
  await setSize(page, vp.width, vp.height);
  await clearCallouts(page);

  // 14 Printable report (server-rendered HTML)
  const rep = await page.context().newPage();
  await rep.setViewportSize({ width: 1200, height: 900 });
  await rep.goto(`${require('../lib.cjs').BASE}/agent/client_compliance_report.php?client_id=${nsId}&format=html`, { waitUntil: 'domcontentloaded' });
  await rep.waitForTimeout(600);
  await shot(rep, `${G}/45-compliance-report`);
  await rep.close();

  // 15 What the client sees: portal Security page
  const p2 = await launch({ width: 1440, height: 1000 });
  await login(p2.page, 'portal');
  await goto(p2.page, '/client/compliance.php');
  await check(p2.page, { text: ['Your compliance'] });
  await shot(p2.page, `${G}/46-portal-security`, { fullPage: true });
  await p2.browser.close();

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
