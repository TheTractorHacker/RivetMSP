// Screenshots for 05-service-desk-tickets.md and 07-recurring-tickets-catalog-csat-requests.md (group service-desk).
//   NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/service-desk.cjs        (GUIDE_CLEAN=1 for call-out-free copies)
// Tickets are looked up by SUBJECT, never by id, so the script works after a rebuild with different ticket numbers.
const { execFileSync } = require('child_process');
const { launch, login, shot, goto, settle, callout, clearCallouts, BASE } = require('../lib.cjs');

function sql(q) { return execFileSync('mysql', ['-u', 'root', '-N', 'rivetmsp_demo', '-e', q]).toString().trim(); }
const tid = (subject) => sql(`SELECT ticket_id FROM tickets WHERE ticket_subject = '${subject.replace(/'/g, "\\'")}' LIMIT 1`);
const rid = (subject) => sql(`SELECT recurring_ticket_id FROM recurring_tickets WHERE recurring_ticket_subject = '${subject}' LIMIT 1`);
const mrid = (subject) => sql(`SELECT mail_request_id FROM mail_requests WHERE mail_request_subject = '${subject}' LIMIT 1`);
const G = 'service-desk';
const MODAL = '.modal.show .modal-content';

async function openModal(page, url, size = 'lg') {
  await page.evaluate(([u, s]) => window.openAjaxModal(u, s), [url, size]);
  await page.waitForSelector(MODAL, { state: 'visible' });
  await settle(page, 900);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  await page.evaluate(() => { document.querySelectorAll('.modal.show').forEach(m => { try { bootstrap.Modal.getInstance(m)?.hide(); } catch (e) {} }); });
  await page.waitForTimeout(500);
}
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);   // ONLY=merge,ticket-tour re-runs just those steps
async function step(name, fn) { if (ONLY.length && !ONLY.includes(name)) return; try { await fn(); } catch (e) { console.log('FAILED', name, String(e).split('\n')[0]); } }

(async () => {
  const wide = await launch({ width: 1760, height: 1000 });
  const { browser, page } = wide;
  await login(page, 'admin');

  // ---------------- page 05: tickets ----------------
  await step('list-tour', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await callout(page, [
      { selector: '.ticket-stat-row', n: 1, side: 'tl' },
      { selector: '.tvf-views-col .card', n: 2, side: 'tl' },
      { selector: '.ticket-filter-bar', n: 3, side: 'tl' },
      { selector: '.card-tools .btn-group', n: 4, side: 'tr' },
      { selector: 'table thead tr', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/02-ticket-list-tour`);
  });
  await step('sidebar', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await shot(page, `${G}/01-sidebar-service-desk`, { selector: 'aside, .main-sidebar, #sidebar' });
  });
  await step('more-filters', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await page.click('button[data-bs-target="#advancedFilter"]');
    await page.waitForTimeout(600);
    await shot(page, `${G}/03-ticket-list-more-filters`, { selector: '.ticket-filter-bar' });
  });
  await step('filter-dropdown', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await page.click('[data-bs-target="#tvfStatus"], #tvfStatus, button:has-text("Status")');
    await page.waitForTimeout(600);
    await shot(page, `${G}/04-ticket-list-status-filter`);
    await page.keyboard.press('Escape');
  });
  await step('status-pill', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await page.locator('tbody .badge.dropdown-toggle:text-is("Open")').first().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/05-ticket-list-status-menu`);
  });
  await step('bulk', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    const boxes = page.locator('input.bulk-select');
    await boxes.nth(0).check(); await boxes.nth(1).check();
    await page.click('#bulkActionButton button');
    await page.waitForTimeout(500);
    await callout(page, [{ selector: '#bulkActionButton', n: 1, side: 'tl' }]);
    await shot(page, `${G}/06-ticket-list-bulk-actions`);
  });
  await step('kanban', async () => {
    await goto(page, '/agent/tickets.php?status=Open&view=kanban');
    await shot(page, `${G}/07-ticket-kanban`);
  });
  await step('save-view', async () => {
    await goto(page, '/agent/tickets.php?status=Open&priority%5B%5D=High');
    await openModal(page, 'modals/ticket/ticket_saved_view_add.php?status=Open&priority%5B%5D=High', '');
    await shot(page, `${G}/08-save-view-modal`);   // the form scrolls inside the window; show its top part
    await closeModal(page);
  });
  await step('new-ticket', async () => {
    await goto(page, '/agent/tickets.php?status=Open');
    await page.click('button[data-modal-url*="ticket_add_v2"]');
    await page.waitForSelector(MODAL, { state: 'visible' });
    await settle(page, 1200);
    await shot(page, `${G}/09-new-ticket-details`, { selector: MODAL });
    await page.locator(`${MODAL} a[href="#pills-contacts"], ${MODAL} .nav-link:has-text("Contact")`).first().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/10-new-ticket-contact`, { selector: MODAL });
    await page.locator(`${MODAL} .nav-link:has-text("Assignment")`).first().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/11-new-ticket-assignment`, { selector: MODAL });
    await closeModal(page);
  });

  // ticket page
  const open1 = tid('Branch teller PC will not join the domain');
  await step('ticket-tour', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await page.evaluate(() => {
      const tag = (sel, text, name, up) => { for (const el of document.querySelectorAll(sel)) { if ((el.textContent || '').trim().startsWith(text)) { const t = up ? el.closest(up) : el; if (t) { t.setAttribute('data-ug', name); return; } } } };
      tag('.card-header, .card-title, h3, h5, span, div', 'Time Entry', 'time', '.card');
      tag('.card-header, .card-title, h3, h5, span, div', 'Tasks', 'tasks', '.card');
    });
    await callout(page, [
      { selector: 'h4.fw-bold', n: 1, side: 'tl' },
      { selector: 'a[title="Change Status"]', n: 2, side: 'bl' },
      { selector: '#ticketFlagSwitches', n: 3, side: 'tr' },
      { selector: '.sla-meter', n: 4, side: 'tr' },
      { selector: '#ticketReplyForm .nav, #ticketReplyForm .btn-group', n: 5, side: 'tr' },
      { selector: '[data-ug=time]', n: 6, side: 'tl' },
      { selector: '#dropdownMenuButton', n: 7, side: 'bl' },
    ]);
    await shot(page, `${G}/12-ticket-page-tour`);
  });
  await step('ticket-full', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await shot(page, `${G}/13-ticket-full-page`, { fullPage: true });
  });
  await step('reply-composer', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await page.locator('#ticketReplyForm').scrollIntoViewIfNeeded();
    await shot(page, `${G}/14-reply-composer`, { selector: '#ticketReplyForm' });
  });
  await step('submit-status', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await page.locator('#ticketReplyForm').scrollIntoViewIfNeeded();
    await page.locator('#ticketReplyForm .dropdown-toggle-split').last().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/15-reply-status-menu`);
  });
  await step('kebab', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await page.click('#dropdownMenuButton');
    await page.waitForTimeout(500);
    await shot(page, `${G}/16-ticket-action-menu`);
  });
  await step('merge', async () => {
    await goto(page, `/agent/ticket.php?ticket_id=${open1}`);
    await openModal(page, `modals/ticket/ticket_merge.php?ticket_id=${open1}`, '');
    await shot(page, `${G}/17-ticket-merge-modal`, { selector: MODAL });
    await closeModal(page);
  });
  await step('closed', async () => {
    const c = tid('Reset password for front desk account');
    await goto(page, `/agent/ticket.php?ticket_id=${c}`);
    await shot(page, `${G}/18-closed-ticket`);
  });
  await step('side-cards', async () => {
    const c = tid('New hire setup: Alex Rivera, AP clerk');
    await goto(page, `/agent/ticket.php?ticket_id=${c}`);
    await shot(page, `${G}/19-ticket-tasks-cards`, { fullPage: true });
  });
  await step('parent', async () => {
    const c = tid('Quarterly firewall rule review');
    await goto(page, `/agent/ticket.php?ticket_id=${c}`);
    await shot(page, `${G}/20-ticket-parent-child`, { fullPage: true });
  });

  // ---------------- page 07 ----------------
  await step('recurring', async () => {
    await goto(page, '/agent/recurring_tickets.php');
    await callout(page, [{ selector: '.card-tools .btn', n: 1, side: 'tl' }, { selector: 'table thead tr', n: 2, side: 'tl' }]);
    await shot(page, `${G}/30-recurring-list`);
  });
  await step('recurring-add', async () => {
    await goto(page, '/agent/recurring_tickets.php');
    await page.click('button[data-modal-url*="recurring_ticket_add"]');
    await page.waitForSelector(MODAL, { state: 'visible' });
    await settle(page, 1200);
    await shot(page, `${G}/31-recurring-new-details`, { selector: MODAL });
    await page.locator(`${MODAL} .nav-link:has-text("Schedule")`).first().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/32-recurring-new-schedule`, { selector: MODAL });
    await closeModal(page);
  });
  await step('recurring-bulk', async () => {
    await goto(page, '/agent/recurring_tickets.php');
    const boxes = page.locator('input.bulk-select');
    await boxes.nth(0).check(); await boxes.nth(1).check();
    await page.click('#bulkActionButton button');
    await page.waitForTimeout(500);
    await shot(page, `${G}/33-recurring-bulk-menu`);
  });
  await step('recurring-row-menu', async () => {
    await goto(page, '/agent/recurring_tickets.php');
    await page.locator('tbody button[data-bs-toggle="dropdown"]').first().click();
    await page.waitForTimeout(500);
    await shot(page, `${G}/34-recurring-row-menu`);
  });
  await step('catalog', async () => {
    await goto(page, '/agent/service_catalog.php');
    await callout(page, [{ selector: '#serviceCatalogGrid .col-md-4:nth-child(2)', n: 1, side: 'tl' }, { selector: '.card-tools a', n: 2, side: 'tl' }]);
    await shot(page, `${G}/35-request-something`, { fullPage: true });
  });
  await step('catalog-click', async () => {
    await goto(page, '/agent/service_catalog.php');
    await page.locator('.service-catalog-tile').nth(1).click();
    await page.waitForSelector(MODAL, { state: 'visible' });
    await settle(page, 1800);
    await shot(page, `${G}/36-request-something-prefilled`, { selector: MODAL });
    await closeModal(page);
  });
  await step('catalog-admin', async () => {
    await goto(page, '/admin/service_catalog.php');
    await shot(page, `${G}/37-catalog-admin`, { fullPage: true });
  });
  await step('catalog-edit', async () => {
    await goto(page, '/admin/service_catalog.php');
    const id = sql("SELECT catalog_item_id FROM service_catalog_items WHERE name='New laptop or desktop'");
    await openModal(page, `modals/service_catalog/service_catalog_item_edit.php?id=${id}`, 'lg');
    await shot(page, `${G}/38-catalog-item-edit`, { selector: MODAL });
    await closeModal(page);
  });
  await step('csat', async () => {
    await goto(page, '/agent/csat.php');
    await callout(page, [{ selector: 'form .row', n: 1, side: 'tl' }, { selector: 'tbody tr:first-child', n: 2, side: 'tl' }, { selector: '.js-csat-public-toggle', n: 3, side: 'tr' }]);
    await shot(page, `${G}/39-csat-ratings`);
  });
  await step('csat-followup', async () => {
    await goto(page, '/agent/csat.php?followup=1');
    await shot(page, `${G}/40-csat-needs-followup`);
  });
  await step('csat-report', async () => {
    await goto(page, '/agent/reports/csat.php');
    await shot(page, `${G}/41-csat-analytics`, { fullPage: true });
  });
  await step('requests', async () => {
    await goto(page, '/agent/mail_requests.php');
    await callout(page, [{ selector: 'tbody tr:first-child td:last-child', n: 1, side: 'tl' }]);
    await shot(page, `${G}/42-requests-list`);
  });
  await step('request-view', async () => {
    await goto(page, '/agent/mail_requests.php');
    await openModal(page, `modals/mail_request/mail_request_view.php?id=${mrid('Plant 1 camera is down')}`, 'lg');
    await shot(page, `${G}/43-request-view`, { selector: MODAL });
    await closeModal(page);
  });
  await step('request-convert', async () => {
    await goto(page, '/agent/mail_requests.php');
    await openModal(page, `modals/mail_request/mail_request_convert.php?id=${mrid('Plant 1 camera is down')}`, '');
    await shot(page, `${G}/44-request-convert`, { selector: MODAL });
    await closeModal(page);
  });
  await step('client-scoped', async () => {
    const cid = sql("SELECT client_id FROM clients WHERE client_name='Northshore Dental Group'");
    await goto(page, `/agent/tickets.php?client_id=${cid}&status=Open`);
    await shot(page, `${G}/21-client-ticket-list`);
  });
  await browser.close();

  // ---------------- portal ----------------
  const p = await launch({ width: 1440, height: 900 });
  await login(p.page, 'portal');
  await step('portal-catalog', async () => {
    await goto(p.page, '/client/service_catalog.php');
    await shot(p.page, `${G}/45-portal-request-something`);
  });
  await p.browser.close();
})();
