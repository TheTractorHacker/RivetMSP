// Capture script for group G3 "Field work": pages 06 (ticket billing and worksheets), 08 (dispatch, scheduling, calendar),
// 09 (projects) and 10 (problems and changes).
//
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8090 node docs/user-guide/tools/capture/field-work.cjs [06|08|09|10 ...]
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/field-work/
//
// Needs seeds 00, 01 and 40-44 (and the service-desk seeds, whose tickets appear around ours). Read-only: nothing is saved
// to the demo (modals are opened and photographed, never submitted).
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { login, shot, settle, callout, clearCallouts, BASE } = require('../lib.cjs');

const only = process.argv.slice(2);
const want = (n) => only.length === 0 || only.includes(n);
const G = 'field-work';

function sql(q) {
  return execSync(`mysql -u root rivetmsp_demo -N -B -e ${JSON.stringify(q)}`).toString().trim();
}
const tid = (subject) => sql(`SELECT ticket_id FROM tickets WHERE ticket_subject = '${subject.replace(/'/g, "\\'")}' LIMIT 1`);

async function newPage(browser, { width = 1440, height = 900, who = 'admin' } = {}) {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, colorScheme: 'light', timezoneId: 'America/Chicago' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  await login(page, who);
  return { context, page };
}
async function go(page, url) {
  await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'domcontentloaded' });
  await settle(page, 500);
}
// tag an element so a call-out can find it with a plain CSS selector
async function mark(page, loc, key) {
  const l = loc.first();
  if (!(await l.count())) { console.log('  (no element for', key, ')'); return; }
  await l.evaluate((el, k) => el.setAttribute('data-ug', k), key);
}
const S = (k) => `[data-ug="${k}"]`;
async function markCard(page, bodyId, key) {
  await page.evaluate(([id, k]) => { const b = document.querySelector('#' + id); if (b) b.closest('.card').setAttribute('data-ug', k); }, [bodyId, key]);
}
async function openCards(page, ids) {
  await page.evaluate((ids) => ids.forEach((id) => { const b = document.getElementById(id); if (b) b.classList.add('show'); }), ids);
  await page.waitForTimeout(300);
}
async function openModal(page, url, { wait = 700 } = {}) {
  await page.evaluate((u) => {
    const a = document.createElement('a');
    a.href = '#'; a.className = 'ajax-modal'; a.setAttribute('data-modal-url', u); a.id = 'ug-modal-opener';
    document.body.appendChild(a); a.click(); a.remove();
  }, url);
  await page.waitForSelector('.modal.show .modal-content', { timeout: 10000 });
  await page.waitForTimeout(wait);
}
async function closeModal(page) {
  try { await closeModal0(page); } catch (e) { await page.waitForTimeout(800); }
}
async function closeModal0(page) {
  await page.evaluate(() => document.querySelectorAll('.modal.show').forEach((m) => { try { bootstrap.Modal.getInstance(m).hide(); } catch (e) {} }));
  await page.waitForTimeout(500);
  await page.evaluate(() => { document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove()); document.body.classList.remove('modal-open'); document.body.style.overflow = ''; });
}
async function tall(page, h, fn) {
  const vp = page.viewportSize();
  await page.setViewportSize({ width: vp.width, height: h });
  await page.waitForTimeout(400);
  await fn();
  await page.setViewportSize(vp);
  await page.waitForTimeout(300);
}
const MODAL = '.modal.show .modal-content';
const fs = require('fs'); const path = require('path'); const { OUT } = require('../lib.cjs');
async function shotClip(page, rel, clip) {
  const file = path.join(OUT, rel + '.png');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, clip, fullPage: true });
  console.log('saved', rel);
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });

  // =====================================================================================================
  if (want('06')) {
    const { page } = await newPage(browser);
    const t = {
      monitors: tid('Mount and connect two new operatory monitors'), scan: tid('Set up scan-to-folder for the new copier'),
      teller: tid('Teller workstation will not boot after update'), quote: tid('Price a workstation for the third exam room'),
      printer: tid('Set up label printer at the shipping dock'), display: tid('Swap the boardroom projector for a wall display'),
      classroom: tid('Classroom printer shows offline'),
    };

    // 06-01 the billing strip on a closed, billable ticket
    await go(page, `/agent/ticket.php?ticket_id=${t.monitors}`);
    await mark(page, page.locator('.ticket-header-compact'), 'hdr');
    await mark(page, page.locator('a.btn:has-text("Quote")'), 'quote');
    await mark(page, page.locator('a.btn:has-text("Invoice")'), 'invoice');
    await mark(page, page.locator('a:has-text("Yes")').filter({ has: page.locator('.text-bold') }), 'billable');
    await mark(page, page.locator('a.ajax-modal[data-modal-url*="ticket_billable"]'), 'billable');
    await callout(page, [{ selector: S('quote'), n: 1 }, { selector: S('invoice'), n: 2 }, { selector: S('billable'), n: 3, side: 'br' }]);
    await shot(page, `${G}/06-01-ticket-billing-strip`, { selector: S('hdr') });

    // 06-02 Billable? modal
    await clearCallouts(page);
    await openModal(page, `modals/ticket/ticket_billable.php?id=${t.monitors}`);
    await shot(page, `${G}/06-02-billable-modal`, { selector: MODAL });
    await closeModal(page);

    // 06-03 Charges card expanded
    await go(page, `/agent/ticket.php?ticket_id=${t.monitors}`);
    await openCards(page, ['sidebarBody-charges', 'sidebarBody-timeEntryLog']);
    await markCard(page, 'sidebarBody-charges', 'charges');
    await markCard(page, 'sidebarBody-timeEntryLog', 'timelog');
    await page.setViewportSize({ width: 2000, height: 1000 }); await page.waitForTimeout(600);
    await callout(page, [{ selector: '[data-ug="charges"] .card-header', n: 1, side: 'tr' }, { selector: '[data-ug="charges"] tbody tr:first-child', n: 2, side: 'tr' }, { selector: '[data-ug="charges"] tfoot', n: 3, side: 'tr' }]);
    await shot(page, `${G}/06-03-charges-card`, { selector: S('charges') });
    await clearCallouts(page);
    await shot(page, `${G}/06-04-time-entry-log`, { selector: S('timelog') });
    await page.setViewportSize({ width: 1440, height: 900 }); await page.waitForTimeout(400);

    // 06-05 Add Charge modal (open ticket so the + is available)
    await go(page, `/agent/ticket.php?ticket_id=${t.printer}`);
    await openModal(page, `modals/ticket/ticket_charge_add.php?ticket_id=${t.printer}&client_id=${sql('SELECT ticket_client_id FROM tickets WHERE ticket_id=' + t.printer)}`);
    await mark(page, page.locator('#labor_type_btns'), 'lt');
    await mark(page, page.locator('#product_lookup').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'prod');
    await mark(page, page.locator('#charge_name'), 'cname');
    await mark(page, page.locator('#charge_quantity').locator('xpath=ancestor::div[contains(@class,"form-row")]'), 'qty');
    await callout(page, [{ selector: S('lt'), n: 1 }, { selector: S('prod'), n: 2 }, { selector: S('cname'), n: 3 }, { selector: S('qty'), n: 4 }]);
    await tall(page, 1100, async () => { await shot(page, `${G}/06-05-add-charge`, { selector: MODAL }); });
    await closeModal(page);

    // 06-06 reply form: labor type and Charge now
    await go(page, `/agent/ticket.php?ticket_id=${t.printer}`);
    await page.fill('#hours', '01'); await page.fill('#minutes', '15');
    await page.selectOption('#reply_labor_type_id', { label: 'On-Site Support' });
    await mark(page, page.locator('.time-entry-card'), 'te');
    await mark(page, page.locator('#reply_charge_now').locator('xpath=ancestor::div[contains(@class,"form-check")]'), 'now');
    await mark(page, page.locator('#ticket_add_reply'), 'submit');
    await callout(page, [{ selector: S('te'), n: 1 }, { selector: S('now'), n: 2 }, { selector: S('submit'), n: 3 }]);
    await shot(page, `${G}/06-06-time-and-charge-now`);

    // 06-07 Invoice from ticket modal: Northshore already has a Draft invoice, so the pop-up offers two tabs
    await go(page, `/agent/ticket.php?ticket_id=${t.monitors}`);
    await openModal(page, `modals/ticket/ticket_invoice_add.php?ticket_id=${t.monitors}`, { wait: 900 });
    await mark(page, page.locator('.nav-pills'), 'tabs');
    await mark(page, page.locator('select[name="invoice_id"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'exist');
    await callout(page, [{ selector: S('tabs'), n: 1 }, { selector: S('exist'), n: 2 }]);
    await tall(page, 1300, async () => { await shot(page, `${G}/06-07-invoice-existing-draft`, { selector: MODAL }); });
    await clearCallouts(page);
    await page.click('a.nav-link:has-text("Create New Invoice")');
    await page.waitForTimeout(600);
    const grp = (n) => page.locator(`[name="${n}"]`).locator('xpath=ancestor::div[contains(@class,"form-group")]');
    await mark(page, grp('date'), 'idate'); await mark(page, grp('category'), 'icat'); await mark(page, grp('scope'), 'iscope');
    await mark(page, grp('item_name'), 'item'); await mark(page, grp('qty'), 'iqty'); await mark(page, grp('price'), 'iprice');
    await callout(page, [{ selector: S('idate'), n: 1 }, { selector: S('icat'), n: 2 }, { selector: S('iscope'), n: 3 }, { selector: S('item'), n: 4 }, { selector: S('iqty'), n: 5 }, { selector: S('iprice'), n: 6 }]);
    await tall(page, 1500, async () => { await shot(page, `${G}/06-08-invoice-from-ticket`, { selector: MODAL }); });
    await closeModal(page);

    // 06-09 Quote from ticket modal
    await go(page, `/agent/ticket.php?ticket_id=${t.scan}`);
    await openModal(page, `modals/ticket/ticket_quote_add.php?ticket_id=${t.scan}`, { wait: 900 });
    await mark(page, page.locator('input[name="expire"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'exp');
    await mark(page, page.locator('input[name="scope"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'scope');
    await callout(page, [{ selector: S('scope'), n: 1 }, { selector: S('exp'), n: 2 }]);
    await tall(page, 1400, async () => { await shot(page, `${G}/06-09-quote-from-ticket`, { selector: MODAL }); });
    await closeModal(page);

    // 06-10 ticket that has an invoice / quote (links replace the buttons)
    await go(page, `/agent/ticket.php?ticket_id=${t.teller}`);
    await mark(page, page.locator('.ticket-header-compact'), 'hdr');
    await mark(page, page.locator('a[href*="invoice.php?invoice_id"]').first(), 'inv');
    await callout(page, [{ selector: S('inv'), n: 1 }]);
    await shot(page, `${G}/06-10-ticket-invoiced`, { selector: S('hdr') });
    await go(page, `/agent/ticket.php?ticket_id=${t.quote}`);
    await mark(page, page.locator('.ticket-header-compact'), 'hdr');
    await mark(page, page.locator('a[href*="quote.php?quote_id"]').first(), 'q');
    await callout(page, [{ selector: S('q'), n: 1 }]);
    await shot(page, `${G}/06-11-ticket-quoted`, { selector: S('hdr') });

    // 06-12 Ready to bill: the saved view "Billable, not yet invoiced", and the two reports
    await go(page, '/agent/tickets.php');
    const bv = page.locator('a:has-text("Billable, not yet invoiced")').first();
    await bv.click();
    await settle(page, 800);
    await mark(page, page.locator('a:has-text("Billable, not yet invoiced")').first(), 'view');
    await mark(page, page.locator('th:has-text("Billable")'), 'bcol');
    await callout(page, [{ selector: S('view'), n: 1 }, { selector: S('bcol'), n: 2 }]);
    await shot(page, `${G}/06-12-unbilled-ticket-list`);
    await go(page, '/agent/reports/tickets_unbilled.php');
    await mark(page, page.locator('table thead th:has-text("Unbilled Tickets")'), 'ub');
    await mark(page, page.locator('table tbody tr a').first(), 'cl');
    await callout(page, [{ selector: S('cl'), n: 1 }, { selector: S('ub'), n: 2 }]);
    await shot(page, `${G}/06-13-unbilled-report`);
    await go(page, '/agent/reports/ticket_charges.php');
    await shot(page, `${G}/06-14-ticket-charges-report`);

    // Worksheets ---------------------------------------------------------------------------------------
    await go(page, `/agent/ticket.php?ticket_id=${t.printer}`);
    await openCards(page, ['sidebarBody-worksheets']);
    await markCard(page, 'sidebarBody-worksheets', 'ws');
    await mark(page, page.locator('[data-ug="ws"] .card-tools a.ajax-modal'), 'wsadd');
    await mark(page, page.locator('[data-ug="ws"] .badge').first(), 'wspct');
    await mark(page, page.locator('[data-ug="ws"] button[name="complete_worksheet"]'), 'wsfin');
    await callout(page, [{ selector: S('wsadd'), n: 1 }, { selector: S('wspct'), n: 2 }, { selector: S('wsfin'), n: 3 }]);
    await shot(page, `${G}/06-15-worksheets-card`, { selector: S('ws') });
    await clearCallouts(page);

    await openModal(page, `modals/ticket/ticket_worksheet_add.php?ticket_id=${t.printer}`);
    await mark(page, page.locator('#isOuttake').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'req');
    await callout(page, [{ selector: S('req'), n: 1 }]);
    await shot(page, `${G}/06-16-add-worksheet`, { selector: MODAL });
    await closeModal(page);

    const wsOpen = sql(`SELECT worksheet_id FROM ticket_worksheets WHERE worksheet_ticket_id = ${t.printer} LIMIT 1`);
    await go(page, `/agent/worksheet.php?worksheet_id=${wsOpen}&ticket_id=${t.printer}`);
    await mark(page, page.locator('button[name="save_worksheet"]'), 'save');
    await mark(page, page.locator('button[name="complete_worksheet"]'), 'done');
    await callout(page, [{ selector: S('save'), n: 1 }, { selector: S('done'), n: 2 }]);
    await tall(page, 1250, async () => { await shot(page, `${G}/06-17-worksheet-page`); });

    // signed required worksheet
    await go(page, `/agent/ticket.php?ticket_id=${t.display}`);
    await openCards(page, ['sidebarBody-worksheets']);
    await markCard(page, 'sidebarBody-worksheets', 'ws');
    await mark(page, page.locator('[data-ug="ws"] .badge:has-text("Signed")'), 'signed');
    await callout(page, [{ selector: S('signed'), n: 1 }]);
    await shot(page, `${G}/06-18-worksheet-signed`, { selector: S('ws') });
    await clearCallouts(page);

    // outtake form: attachments card with the unsigned form, the form page, the in-person signing pop-up
    await go(page, `/agent/ticket.php?ticket_id=${t.printer}`);
    await mark(page, page.locator('.js-create-outtake:visible').first(), 'addout');
    await mark(page, page.locator('.js-sign-outtake').first(), 'signnow');
    await mark(page, page.locator('.badge:has-text("Awaiting Signature")'), 'await');
    await mark(page, page.locator('a[href*="outtake_form.php"]').first(), 'viewout');
    await page.evaluate(() => { const h = [...document.querySelectorAll('h5.card-title')].find((x) => x.textContent.includes('Attachments')); if (h) h.closest('.card').setAttribute('data-ug', 'attcard'); });
    await callout(page, [{ selector: S('addout'), n: 1 }, { selector: S('await'), n: 2 }, { selector: S('signnow'), n: 3 }, { selector: S('viewout'), n: 4 }]);
    await shot(page, `${G}/06-19-outtake-attachments`, { selector: S('attcard') });
    await clearCallouts(page);

    const out = sql(`SELECT outtake_id FROM ticket_outtake_forms WHERE outtake_ticket_id = ${t.printer} LIMIT 1`);
    await go(page, `/agent/outtake_form.php?outtake_id=${out}&ticket_id=${t.printer}`);
    await mark(page, page.locator('.alert-warning'), 'warn');
    await mark(page, page.locator('textarea[name="outtake_tech_notes"]'), 'notes');
    await mark(page, page.locator('.js-sign-outtake'), 'sign');
    await callout(page, [{ selector: S('warn'), n: 1 }, { selector: S('notes'), n: 2 }, { selector: S('sign'), n: 3 }]);
    await shot(page, `${G}/06-20-outtake-form`);

    await clearCallouts(page);
    await page.click('.js-sign-outtake');
    await page.waitForSelector('.modal.show, .swal2-popup, .rivet-dialog, canvas', { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(900);
    await shot(page, `${G}/06-21-sign-in-person`);
    await closeModal(page);

    // signed outtake form (PDF attached)
    await go(page, `/agent/ticket.php?ticket_id=${t.display}`);
    await mark(page, page.locator('a[href*="/uploads/tickets/"]').first(), 'pdf');
    await callout(page, [{ selector: S('pdf'), n: 1 }]);
    await page.evaluate(() => { const h = [...document.querySelectorAll('h5.card-title')].find((x) => x.textContent.includes('Attachments')); if (h) h.closest('.card').setAttribute('data-ug', 'attcard'); });
    await shot(page, `${G}/06-22-outtake-signed-pdf`, { selector: S('attcard') });

    // customer-facing signing page (token link)
    const tok = sql(`SELECT outtake_sign_token FROM ticket_outtake_forms WHERE outtake_ticket_id = ${t.printer} LIMIT 1`);
    const ctx2 = await browser.newContext({ viewport: { width: 1100, height: 1000 }, colorScheme: 'light', timezoneId: 'America/Chicago' });
    const gp = await ctx2.newPage();
    await go(gp, `/guest/outtake_sign.php?token=${tok}`);
    const box = await gp.locator('.section-bar:has-text("Customer Signature")').evaluate((el) => { const r = el.getBoundingClientRect(); const c = el.parentElement.getBoundingClientRect(); return { x: c.left, y: r.top + window.scrollY, w: c.width, h: c.bottom + window.scrollY - (r.top + window.scrollY) }; });
    await shotClip(gp, `${G}/06-23-customer-signing-page`, { x: box.x, y: box.y, width: box.w, height: box.h });
    await ctx2.close();

    // Administration: labor types, worksheet templates
    await go(page, '/admin/labor_type.php');
    await mark(page, page.locator('.card-tools .btn').first(), 'newlt');
    await callout(page, [{ selector: S('newlt'), n: 1 }]);
    await shot(page, `${G}/06-24-labor-types`);
    await go(page, '/admin/worksheet_template.php');
    await mark(page, page.locator('.card-tools .btn').first(), 'newt');
    await callout(page, [{ selector: S('newt'), n: 1 }]);
    await shot(page, `${G}/06-25-worksheet-templates`);
    const tmplId = sql(`SELECT worksheet_template_id FROM worksheet_templates WHERE worksheet_template_name = 'On-site visit sign-off'`);
    await go(page, `/admin/worksheet_template_details.php?id=${tmplId}`);
    await shot(page, `${G}/06-26-worksheet-template-fields`, { fullPage: true });
    await go(page, '/admin/settings_module.php');
    await mark(page, page.locator('label:has-text("Show Ticket Charges")').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'tc');
    await callout(page, [{ selector: S('tc'), n: 1 }]);
    await shot(page, `${G}/06-27-ticket-charges-switch`);
    await page.context().close();
  }


  // =====================================================================================================
  if (want('08')) {
    const { page } = await newPage(browser, { height: 1000 });
    const cardOf = (title, key) => page.evaluate(([t, k]) => { const h = [...document.querySelectorAll('.card-title')].find((x) => x.textContent.trim().startsWith(t)); if (h) h.closest('.card').setAttribute('data-ug', k); }, [title, key]);

    await go(page, '/agent/calendar.php');
    await page.waitForSelector('.fc-view');
    await cardOf('Calendars', 'cals'); await cardOf('Built-in', 'builtin'); await cardOf('Calendar Sync', 'sync');
    await mark(page, page.locator('.fc-toolbar-chunk').first(), 'nav');
    await mark(page, page.locator('.fc-dayGridMonth-button').locator('xpath=..'), 'views');
    await mark(page, page.locator('.fc-newEvent-button'), 'newev');
    await callout(page, [{ selector: S('cals'), n: 1 }, { selector: S('builtin'), n: 2 }, { selector: S('nav'), n: 3 }, { selector: S('views'), n: 4 }, { selector: S('newev'), n: 5 }]);
    await shot(page, `${G}/08-01-calendar-month`);

    // week view, scrolled to the working day
    await clearCallouts(page);
    await page.click('.fc-timeGridWeek-button'); await page.waitForTimeout(800);
    await page.evaluate(() => { const sc = document.querySelector('.fc-scroller-liquid-absolute'); if (sc) sc.scrollTop = 220; });
    await page.waitForTimeout(400);
    await shot(page, `${G}/08-02-calendar-week`);
    await page.click('.fc-listMonth-button'); await page.waitForTimeout(800);
    await shot(page, `${G}/08-03-calendar-list`);

    // calendar sync card (wide so URLs are readable)
    await go(page, '/agent/calendar.php');
    await cardOf('Calendar Sync', 'sync');
    await callout(page, [{ selector: '[data-ug="sync"] #cal_webcal_url', n: 1 }, { selector: '[data-ug="sync"] a.btn-block', n: 2 }, { selector: '[data-ug="sync"] #cal_https_url', n: 3 }]);
    await shot(page, `${G}/08-04-calendar-sync`, { selector: S('sync') });

    // new event, edit event, new calendar
    await clearCallouts(page);
    await page.click('.fc-newEvent-button'); await page.waitForSelector('.modal.show'); await page.waitForTimeout(700);
    await mark(page, page.locator('.modal.show .nav-pills'), 'tabs');
    await mark(page, page.locator('select[name="calendar"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'cal');
    await mark(page, page.locator('#event_add_start').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'when');
    await mark(page, page.locator('select[name="repeat"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'rep');
    await callout(page, [{ selector: S('tabs'), n: 1 }, { selector: S('cal'), n: 2 }, { selector: S('when'), n: 3 }, { selector: S('rep'), n: 4 }]);
    await tall(page, 1250, async () => { await shot(page, `${G}/08-05-new-event`, { selector: '.modal.show .modal-content' }); });
    await clearCallouts(page);
    await page.click('a.nav-link:has-text("Attendees")'); await page.waitForTimeout(500);
    await mark(page, page.locator('select[name="client_id"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'cl');
    await callout(page, [{ selector: S('cl'), n: 1 }]);
    await tall(page, 1250, async () => { await shot(page, `${G}/08-18-new-event-attendees`, { selector: '.modal.show .modal-content' }); });
    await closeModal(page); await clearCallouts(page);
    await openModal(page, `modals/calendar/calendar_add.php`);
    await shot(page, `${G}/08-06-new-calendar`, { selector: MODAL });
    await closeModal(page);

    // appointments on a ticket
    const wifi = tid('Branch Wi-Fi coverage walk-through');
    await go(page, `/agent/ticket.php?ticket_id=${wifi}`);
    await openCards(page, ['sidebarBody-appointments']);
    await markCard(page, 'sidebarBody-appointments', 'appts');
    await mark(page, page.locator('[data-ug="appts"] .card-tools a.ajax-modal'), 'add');
    await mark(page, page.locator('[data-ug="appts"] .badge:has-text("Onsite")').first(), 'type');
    await mark(page, page.locator('[data-ug="appts"] a[data-modal-url*="schedule_edit"]').first(), 'edit');
    await mark(page, page.locator('[data-ug="appts"] .fa-user-cog').first(), 'tech');
    await callout(page, [{ selector: S('add'), n: 1 }, { selector: S('tech'), n: 2 }, { selector: S('type'), n: 3 }, { selector: S('edit'), n: 4 }]);
    await shot(page, `${G}/08-07-ticket-appointments`, { selector: S('appts') });
    await clearCallouts(page);

    await openModal(page, `modals/ticket/ticket_schedule_add.php?ticket_id=${wifi}`);
    await page.fill('#sched_start', new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10) + 'T14:00');
    await page.waitForTimeout(500);
    await mark(page, page.locator('.modal.show .btn-group-sm').first(), 'apt');
    await mark(page, page.locator('#sched_start').locator('xpath=ancestor::div[contains(@class,"form-row")]'), 'when');
    await mark(page, page.locator('[name="schedule_tech_ids[]"]').first().locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'techs');
    await mark(page, page.locator('[name="schedule_notes"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'notes');
    await callout(page, [{ selector: S('apt'), n: 1 }, { selector: S('when'), n: 2 }, { selector: S('techs'), n: 3 }, { selector: S('notes'), n: 4 }]);
    await tall(page, 1100, async () => { await shot(page, `${G}/08-08-add-appointment`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);
    const sched = sql(`SELECT schedule_id FROM ticket_schedules WHERE schedule_ticket_id = ${wifi} ORDER BY schedule_id LIMIT 1`);
    await openModal(page, `modals/ticket/ticket_schedule_edit.php?schedule_id=${sched}`);
    await mark(page, page.locator('a:has-text("Delete"), a.text-danger').last(), 'del');
    await callout(page, [{ selector: S('del'), n: 1 }]);
    await tall(page, 1100, async () => { await shot(page, `${G}/08-09-edit-appointment`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);

    // personal colour + Outlook sync (user account), admin Outlook credentials
    await go(page, '/agent/user/user_integrations.php');
    await shot(page, `${G}/08-10-my-calendar-color`);
    await go(page, '/admin/settings_calendar_sync.php');
    await shot(page, `${G}/08-11-outlook-sync-admin`);

    // dispatch: skills, category required skill, automation rule
    await go(page, '/admin/users.php');
    await mark(page, page.locator('a[data-modal-url*="user_skills"]').first(), 'skills');
    await callout(page, [{ selector: S('skills'), n: 1 }]);
    await shot(page, `${G}/08-12-users-skills-button`);
    await clearCallouts(page);
    const devon = sql(`SELECT user_id FROM users WHERE user_name='Devon Park'`);
    await openModal(page, `modals/user/user_skills.php?id=${devon}`);
    await shot(page, `${G}/08-13-technician-skills`, { selector: MODAL });
    await closeModal(page);
    await go(page, '/admin/tag.php?type=7');
    await shot(page, `${G}/08-14-skill-tags`);
    await go(page, '/admin/category.php?category=Ticket');
    const onsiteCat = sql(`SELECT category_id FROM categories WHERE category_type='Ticket' AND category_name='On-Site' LIMIT 1`);
    await openModal(page, `modals/category/category_edit.php?id=${onsiteCat}`, { wait: 900 });
    await mark(page, page.locator('select[name="required_skill_tag_id"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'skill');
    await callout(page, [{ selector: S('skill'), n: 1 }]);
    await tall(page, 1300, async () => { await shot(page, `${G}/08-15-category-required-skill`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);
    await go(page, '/admin/ticket_automation.php');
    await shot(page, `${G}/08-16-automation-rules`);
    await openModal(page, `modals/ticket_automation/add_rule.php`, { wait: 900 });
    await tall(page, 1300, async () => { await shot(page, `${G}/08-17-new-automation-rule`, { selector: MODAL }); });
    await closeModal(page);
    await page.context().close();
  }

  // =====================================================================================================
  if (want('09')) {
    const { page } = await newPage(browser, { height: 1000 });
    const pid = (n) => sql(`SELECT project_id FROM projects WHERE project_name='${n}' LIMIT 1`);
    await go(page, '/agent/projects.php');
    await mark(page, page.locator('button:has-text("New Project")'), 'new');
    await mark(page, page.locator('a.btn:has-text("Open")').first(), 'open');
    await mark(page, page.locator('a.btn:has-text("Closed")').first(), 'closed');
    await mark(page, page.locator('a.btn:has-text("Archived")').first(), 'arch');
    await mark(page, page.locator('th:has-text("Tickets / Tasks")'), 'prog');
    await mark(page, page.locator('table tbody tr').first().locator('button.dropdown-toggle, .dropdown button').first(), 'act');
    await callout(page, [{ selector: S('new'), n: 1 }, { selector: S('open'), n: 2 }, { selector: S('prog'), n: 3 }, { selector: S('act'), n: 4 }]);
    await shot(page, `${G}/09-01-projects-list`);
    await clearCallouts(page);
    await go(page, '/agent/projects.php?status=1');
    await shot(page, `${G}/09-02-projects-closed`);
    await go(page, '/agent/projects.php?status=1&archived=1');
    await shot(page, `${G}/09-03-projects-archived`);

    const birch = sql("SELECT client_id FROM clients WHERE client_name='Birchwood Law Partners'");
    await go(page, `/agent/projects.php?client_id=${birch}`);
    await openModal(page, `modals/project/project_add.php?client_id=${birch}&`, { wait: 900 });
    await page.evaluate(() => { const el = document.querySelector('#project_template_id'); const o = [...el.options].find((x) => x.textContent.trim() === 'Laptop refresh'); if (el.tomselect) el.tomselect.setValue(o.value); });
    await page.fill('input[name="name"]', 'Laptop refresh, spring round');
    await page.waitForTimeout(500);
    const g = (n) => page.locator(`[name="${n}"]`).locator('xpath=ancestor::div[contains(@class,"form-group")]');
    await mark(page, g('name'), 'name'); await mark(page, g('project_template_id'), 'tmpl');
    await mark(page, g('contract_template_id'), 'contract'); await mark(page, g('due_date'), 'due'); await mark(page, g('estimated_hours'), 'est'); await mark(page, g('project_manager'), 'mgr');
    await callout(page, [{ selector: S('name'), n: 1 }, { selector: S('tmpl'), n: 2 }, { selector: S('contract'), n: 3 }, { selector: S('due'), n: 4 }, { selector: S('est'), n: 5 }, { selector: S('mgr'), n: 6 }]);
    await tall(page, 1500, async () => { await shot(page, `${G}/09-04-new-project`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);

    const p1 = pid('Office Wi-Fi upgrade');
    await go(page, `/agent/project_details.php?project_id=${p1}`);
    await mark(page, page.locator('.nav-pills'), 'tabs');
    await mark(page, page.locator('.card-header .dropdown button:has-text("New")'), 'new');
    await mark(page, page.locator('.card-header .dropdown button:has-text("Link")'), 'link');
    await mark(page, page.locator('h5:has-text("Budget & Effort")').locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'budget');
    await mark(page, page.locator('h5:has-text("Milestones")').locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'ms');
    await mark(page, page.locator('h5:has-text("Other Tasks")').locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'other');
    await mark(page, page.locator('.card-group').first(), 'summary');
    await callout(page, [{ selector: S('tabs'), n: 1 }, { selector: S('new'), n: 2 }, { selector: S('link'), n: 3 }, { selector: S('summary'), n: 4 }, { selector: S('budget'), n: 5 }, { selector: S('ms'), n: 6 }, { selector: S('other'), n: 7 }]);
    await tall(page, 1500, async () => { await shot(page, `${G}/09-05-project-page`); });
    await clearCallouts(page);
    // dropdowns
    await page.click('.card-header .dropdown button:has-text("New")'); await page.waitForTimeout(400);
    const hb = await page.locator('.card-header').first().boundingBox();
    const mb = await page.locator('.card-header .dropdown-menu.show').first().boundingBox();
    await shotClip(page, `${G}/09-06-project-new-menu`, { x: hb.x, y: hb.y, width: hb.width, height: (mb.y + mb.height) - hb.y + 12 });
    await page.keyboard.press('Escape');

    await openModal(page, `modals/project/milestone_add.php?project_id=${p1}`);
    await shot(page, `${G}/09-07-new-milestone`, { selector: MODAL });
    await closeModal(page);
    await openModal(page, `modals/project/task_add.php?project_id=${p1}`, { wait: 900 });
    const g2 = (n) => page.locator(`[name="${n}"]`).locator('xpath=ancestor::div[contains(@class,"form-group")]');
    await mark(page, g2('milestone_id'), 'tms'); await mark(page, g2('assigned_to'), 'twho'); await mark(page, g2('start'), 'tstart'); await mark(page, g2('completion_estimate'), 'test'); await mark(page, g2('progress'), 'tprog');
    await callout(page, [{ selector: S('tms'), n: 1 }, { selector: S('twho'), n: 2 }, { selector: S('tstart'), n: 3 }, { selector: S('test'), n: 4 }, { selector: S('tprog'), n: 5 }]);
    await tall(page, 1100, async () => { await shot(page, `${G}/09-08-new-task`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);
    await openModal(page, `modals/project/project_link_ticket.php?project_id=${p1}`, { wait: 900 });
    await tall(page, 1000, async () => { await shot(page, `${G}/09-09-link-open-ticket`, { selector: MODAL }); });
    await closeModal(page);

    // template-created project
    const p3 = pid('Laptop refresh');
    await go(page, `/agent/project_details.php?project_id=${p3}`);
    await mark(page, page.locator('h5:has-text("Project Tickets")').locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'tickets');
    await callout(page, [{ selector: S('tickets'), n: 1 }]);
    await tall(page, 1100, async () => { await shot(page, `${G}/09-10-template-project`); });
    await clearCallouts(page);

    // gantt and kanban
    await go(page, `/agent/project_gantt.php?project_id=${p1}`);
    await page.waitForTimeout(800);
    await mark(page, page.locator('.gantt-toolbar'), 'mode');
    await callout(page, [{ selector: S('mode'), n: 1 }]);
    await shot(page, `${G}/09-11-gantt`);
    await clearCallouts(page);
    await page.setViewportSize({ width: 1800, height: 900 });
    await go(page, `/agent/project_kanban.php?project_id=${p1}`);
    await mark(page, page.locator('.kanban-column').nth(0), 'c1'); await mark(page, page.locator('.kanban-column').nth(2), 'c3'); await mark(page, page.locator('.task').first(), 'card');
    await callout(page, [{ selector: S('c1'), n: 1 }, { selector: S('card'), n: 2 }, { selector: S('c3'), n: 3 }]);
    await shot(page, `${G}/09-12-kanban`);
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 1000 });

    // closed project: Archive in the menu
    const p4 = pid('Main Branch network refresh');
    await go(page, `/agent/project_details.php?project_id=${p4}`);
    await page.click('.card-header .dropdown.dropleft button, .card-header .dropleft button'); await page.waitForTimeout(400);
    await shot(page, `${G}/09-13-closed-project-menu`);
    await page.keyboard.press('Escape');

    // admin: project templates
    await go(page, '/admin/project_template.php');
    await shot(page, `${G}/09-14-project-templates`);
    const pt = sql(`SELECT project_template_id FROM project_templates WHERE project_template_name='Laptop refresh'`);
    await go(page, `/admin/project_template_details.php?project_template_id=${pt}`);
    await shot(page, `${G}/09-15-project-template-details`, { fullPage: true });
    await go(page, '/admin/settings_project.php');
    await shot(page, `${G}/09-16-project-settings`);
    // per-client projects
    await go(page, `/agent/projects.php?client_id=${sql("SELECT client_id FROM clients WHERE client_name='Northshore Dental Group'")}`);
    await shot(page, `${G}/09-17-client-projects`);
    await page.context().close();
  }

  // =====================================================================================================
  if (want('10')) {
    const { page } = await newPage(browser, { height: 1000 });
    const prob = (t) => sql(`SELECT problem_id FROM problems WHERE title LIKE '${t}%' LIMIT 1`);
    const chg = (t) => sql(`SELECT change_id FROM changes WHERE title LIKE '${t}%' LIMIT 1`);

    await go(page, '/agent/problems.php');
    await mark(page, page.locator('button:has-text("New Problem")'), 'new');
    await mark(page, page.locator('form .ts-wrapper').first(), 'status');
    await mark(page, page.locator('th:has-text("Linked Tickets")'), 'lt');
    await mark(page, page.locator('th:has-text("Linked Change")'), 'lc');
    await callout(page, [{ selector: S('new'), n: 1 }, { selector: S('status'), n: 2 }, { selector: S('lt'), n: 3 }, { selector: S('lc'), n: 4 }]);
    await shot(page, `${G}/10-01-problems-list`);
    await clearCallouts(page);
    await openModal(page, 'modals/problem/problem_add.php');
    await shot(page, `${G}/10-02-new-problem`, { selector: MODAL });
    await closeModal(page);

    await go(page, `/agent/problem_details.php?id=${prob('Wi-Fi and VPN')}`);
    await mark(page, page.locator('h3.card-title .badge').first(), 'badge');
    await mark(page, page.locator('button[name="set_problem_status"]').first().locator('xpath=..'), 'btns');
    await mark(page, page.locator('input[name="ticket_number"]'), 'link');
    await mark(page, page.locator('h5:has-text("Linked Change")').locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'chg');
    await mark(page, page.locator('button[name="unlink_problem_ticket"]').first(), 'unlink');
    await callout(page, [{ selector: S('badge'), n: 1 }, { selector: S('btns'), n: 2 }, { selector: S('unlink'), n: 3 }, { selector: S('link'), n: 4 }, { selector: S('chg'), n: 5 }]);
    await shot(page, `${G}/10-03-problem-details`);
    await clearCallouts(page);
    await go(page, `/agent/problem_details.php?id=${prob('Scan-to-email')}`);
    await mark(page, page.locator('select[name="change_id"]').locator('xpath=ancestor::form'), 'pick');
    await mark(page, page.locator('a:has-text("Create New Change")'), 'create');
    await callout(page, [{ selector: S('pick'), n: 1 }, { selector: S('create'), n: 2 }]);
    await shot(page, `${G}/10-04-problem-no-change`);
    await clearCallouts(page);

    // the ticket page's Problem card
    const lobby = tid('Lobby Wi-Fi drops every afternoon');
    await go(page, `/agent/ticket.php?ticket_id=${lobby}`);
    await openCards(page, ['sidebarBody-problem']);
    await markCard(page, 'sidebarBody-problem', 'pc');
    await shot(page, `${G}/10-05-ticket-problem-card`, { selector: S('pc') });
    const plain = tid('Scanner to email stopped working');
    await go(page, `/agent/ticket.php?ticket_id=${plain}`);
    await openCards(page, ['sidebarBody-problem']);
    await markCard(page, 'sidebarBody-problem', 'pc');
    await shot(page, `${G}/10-06-ticket-link-problem`, { selector: S('pc') });

    await go(page, '/agent/changes.php');
    await mark(page, page.locator('button:has-text("New Change")'), 'new');
    await mark(page, page.locator('th:has-text("Risk")'), 'risk');
    await mark(page, page.locator('th:has-text("Scheduled")'), 'sched');
    await callout(page, [{ selector: S('new'), n: 1 }, { selector: S('risk'), n: 2 }, { selector: S('sched'), n: 3 }]);
    await shot(page, `${G}/10-07-changes-list`);
    await clearCallouts(page);
    await openModal(page, 'modals/change/change_add.php', { wait: 900 });
    const g = (n) => page.locator(`[name="${n}"]`).locator('xpath=ancestor::div[contains(@class,"form-group")]');
    await mark(page, g('client_id'), 'mclient'); await mark(page, page.locator('select[name="risk"]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'mrisk'); await mark(page, page.locator('input[name="requires_client_approval"]').locator('xpath=ancestor::div[contains(@class,"form-check")]'), 'mappr'); await mark(page, g('scheduled_at'), 'mwhen');
    await callout(page, [{ selector: S('mclient'), n: 1 }, { selector: S('mappr'), n: 2 }, { selector: S('mrisk'), n: 3 }, { selector: S('mwhen'), n: 4 }]);
    await tall(page, 1500, async () => { await shot(page, `${G}/10-08-new-change`, { selector: MODAL }); });
    await closeModal(page); await clearCallouts(page);

    await go(page, `/agent/change_details.php?id=${chg('Replace Plant 1')}`);
    await mark(page, page.locator('h3.card-title .badge').nth(0), 'risk'); await mark(page, page.locator('h3.card-title .badge').nth(1), 'status'); await mark(page, page.locator('h3.card-title .badge').nth(2), 'client');
    await mark(page, page.locator('button[name="set_change_status"]').first().locator('xpath=ancestor::div[1]'), 'btns');
    await mark(page, page.locator('button[name="send_change_approval_request"]'), 'send');
    await mark(page, page.locator('button[name="reschedule_change"]').locator('xpath=ancestor::form'), 'resched');
    await callout(page, [{ selector: S('risk'), n: 1 }, { selector: S('status'), n: 2 }, { selector: S('client'), n: 3 }, { selector: S('btns'), n: 4 }, { selector: S('resched'), n: 5 }, { selector: S('send'), n: 6 }]);
    await shot(page, `${G}/10-09-change-awaiting-approval`);
    await clearCallouts(page);
    await go(page, `/agent/change_details.php?id=${chg('Upgrade office firewall')}`);
    await shot(page, `${G}/10-10-change-scheduled-linked-problem`);
    await go(page, `/agent/change_details.php?id=${chg('Switch the office')}`);
    await shot(page, `${G}/10-11-change-client-approved`);

    // what the client sees
    const row = sql(`SELECT CONCAT(change_id,'|',url_key) FROM changes WHERE title LIKE 'Replace Plant 1%'`).split('|');
    const ctx2 = await browser.newContext({ viewport: { width: 1100, height: 760 }, colorScheme: 'light', timezoneId: 'America/Chicago' });
    const gp = await ctx2.newPage();
    await go(gp, `/guest/guest_view_change.php?change_id=${row[0]}&url_key=${row[1]}`);
    await shot(gp, `${G}/10-12-client-approval-page`);
    await ctx2.close();
    await page.context().close();
  }

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
