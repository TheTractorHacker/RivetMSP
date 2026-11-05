// Screenshots for docs/user-guide/32-client-portal.md and 33-guest-links.md (group "portal").
// Needs seeds 00-core .. 59 plus 70-portal-contacts, 71-portal-tickets and 72-shared-items applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/portal.cjs
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/
//   ONLY=guest node ... run only the shots whose section name contains the text (portal, roles, admin, guest, sandbox)
//
// Read-only against the shared demo: the guest invoice, quote, ticket and shared-item pages are opened, but nothing is accepted,
// approved, rated, replied to or saved there. Opening a guest invoice or quote only moves a Sent one to Viewed, so this script
// uses ones that are already Viewed.
//
// Shots that CHANGE data (accept a quote, approve a task, rate a ticket, save portal branding) run against a throw-away copy of the
// demo, never the shared one. Give it with G12_SANDBOX_URL=http://127.0.0.1:8192 (an app copy on its own database whose
// $config_base_url matches that host:port) and G12_SANDBOX_DB=<its database name>. Without it those shots are skipped and the
// existing PNGs are left alone. Database lookups use the mysql command line (G12_DB, default rivetmsp_demo).

const { execFileSync } = require('child_process');
const { launch, login, goto, shot, callout, clearCallouts, settle, BASE, DEMO_USERS } = require('../lib.cjs');
const G = 'portal';
const SANDBOX = (process.env.G12_SANDBOX_URL || '').replace(/\/$/, '');
const DB = process.env.G12_DB || 'rivetmsp_demo';
const SDB = process.env.G12_SANDBOX_DB || '';
const ONLY = process.env.ONLY || '';
const want = (name) => !ONLY || name.includes(ONLY);

function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
function sql(query, db = DB) {
  return execFileSync('mysql', ['-u', 'root', '-N', db, '-e', query], { encoding: 'utf8' }).trim();
}
async function check(page, { text = [], rows = 0 } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  if (/Oops, something went wrong|No item to view|Incorrect URL/.test(body) && !text.includes('Oops')) fail(`unexpected error page on ${page.url()}`);
  if (rows && (await page.locator('table tbody tr').count()) < rows) fail(`too few rows on ${page.url()}`);
  for (const t of text) if (!body.toLowerCase().includes(t.toLowerCase())) fail(`"${t}" not found on ${page.url()}`);
}
// Mark the first element matching a locator so callout() can reach it with a plain selector.
async function mark(locator, id) {
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}
async function cardOf(page, headingText, id) {
  const h = page.locator('.card-title, h3, h4, h5', { hasText: headingText }).first();
  const handle = await h.elementHandle();
  if (!handle) fail(`heading ${headingText} not found`);
  await handle.evaluate((el, v) => (el.closest('.card') || el).setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}
async function loginPortal(page, base, email, password = 'DemoPass#2026') {
  await page.goto(`${base}/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=login]')]);
  await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
  if (!page.url().includes('/client/')) fail(`portal login failed for ${email} (at ${page.url()})`);
}
async function loginAdmin(page, base, password = 'DemoAdmin#2026') {
  await page.goto(`${base}/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=email]', 'morgan.reyes@lakeshoremsp.example');
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=login]')]);
  await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
  await page.reload({ waitUntil: 'domcontentloaded' });
  await settle(page);
}
async function newPage(browser, w = 1440, h = 900) {
  const context = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, colorScheme: 'light' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  return { context, page };
}
async function openMenu(page, label) {
  await page.locator('.client-portal-nav a.dropdown-toggle', { hasText: label }).click();
  await page.waitForTimeout(400);
}

(async () => {
  const { browser, page: adminPage } = await launch({ width: 1440, height: 900 });
  const tid = (subject, db = DB) => sql(`select ticket_id from tickets where ticket_subject = '${subject.replace(/'/g, "''")}' order by ticket_id desc limit 1`, db);

  // ============================================================== PORTAL (Hannah Brooks: billing + technical)
  if (want('portal')) {
    // --- 01 sign-in page, 02 password reset
    const anon = await newPage(browser);
    await anon.page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
    await settle(anon.page);
    await check(anon.page, { text: ['Sign In'] });
    await callout(anon.page, [
      { selector: 'input[name=email]', n: 1 },
      { selector: 'input[name=password]', n: 2 },
      { selector: 'label[for=remember_me]', n: 3 },
      { selector: 'button[name=login]', n: 4 },
    ]);
    await shot(anon.page, `${G}/01-portal-sign-in`);
    await clearCallouts(anon.page);
    await anon.page.goto(`${BASE}/client/login_reset.php`, { waitUntil: 'domcontentloaded' });
    await settle(anon.page);
    await callout(anon.page, [
      { selector: 'input[name=email]', n: 1 },
      { selector: 'button[name=password_reset_email_request]', n: 2 },
    ]);
    await shot(anon.page, `${G}/02-portal-password-reset`);
    await anon.context.close();

    const { context, page } = await newPage(browser);
    await loginPortal(page, BASE, DEMO_USERS.portal.email, DEMO_USERS.portal.password);

    // --- 03 Home
    await goto(page, '/client/index.php');
    await check(page, { text: ['Welcome back, Hannah Brooks', 'Account Balance Due', 'Needs Attention'] });
    await callout(page, [
      { selector: 'nav.client-portal-nav .navbar-nav.me-auto', n: 1, side: 'bl' },
      { selector: 'nav.client-portal-nav .navbar-nav.pull-right', n: 2, side: 'bl' },
      { selector: '.welcome-banner', n: 3 },
      { selector: 'a.btn[href="ticket_add.php"]', n: 4 },
      { selector: '.row.mb-3:has(.small-box)', n: 5, side: 'tr' },
      { selector: await cardOf(page, 'Recent Tickets', 'recent'), n: 6, side: 'tr' },
      { selector: await cardOf(page, 'Needs Attention', 'attn'), n: 7, side: 'tr' },
      { selector: await cardOf(page, 'Quick Links', 'quick'), n: 8, side: 'tr' },
    ]);
    await shot(page, `${G}/03-portal-home`, { fullPage: true });
    await clearCallouts(page);

    // --- 04 Tickets
    await goto(page, '/client/tickets.php');
    await check(page, { text: ['Open Tickets', 'My Tickets'] });
    await callout(page, [
      { selector: 'table.table', n: 1 },
      { selector: '.list-group', n: 2, side: 'tr' },
      { selector: 'a.btn[href="ticket_add.php"]', n: 3, side: 'tr' },
      { selector: 'a[href="ticket_view_all.php"]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/04-portal-tickets`);
    await clearCallouts(page);

    // --- 05 New ticket
    await page.setViewportSize({ width: 1440, height: 1000 });
    await goto(page, '/client/ticket_add.php');
    await page.waitForSelector('.tox-tinymce', { timeout: 15000 }).catch(() => {});
    await page.fill('input[name=subject]', 'Second monitor for the hygiene room');
    await page.waitForTimeout(600);
    await callout(page, [
      { selector: 'input[name=subject]', n: 1 },
      { selector: 'select[name=priority] + .ts-wrapper, select[name=priority]', n: 2 },
      { selector: 'select[name=category] + .ts-wrapper, select[name=category]', n: 3 },
      { selector: 'select[name=asset] + .ts-wrapper, select[name=asset]', n: 4 },
      { selector: '.tox-tinymce', n: 5 },
      { selector: 'button[name=add_ticket]', n: 6 },
    ]);
    await shot(page, `${G}/05-portal-new-ticket`);
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 900 });

    // --- 06 Request Something
    await goto(page, '/client/service_catalog.php');
    await check(page, { text: ['What do you need?', 'Something else', 'Needs approval'] });
    await callout(page, [
      { selector: await mark(page.locator('.card-title', { hasText: 'New hire setup' }).locator('xpath=ancestor::a[1]'), 'c1'), n: 1 },
      { selector: await mark(page.locator('.badge', { hasText: 'Needs approval' }), 'c2'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card-title', { hasText: 'Something else' }).locator('xpath=ancestor::a[1]'), 'c3'), n: 3 },
    ]);
    await shot(page, `${G}/06-portal-request-something`, { fullPage: true });
    await clearCallouts(page);

    // --- 07 ticket with an approval, 08 resolved ticket, 09 closed ticket that can be rated
    const approvalId = tid('Add a second monitor at the front desk');
    if (!approvalId) fail('seed 71 ticket missing');
    await goto(page, `/client/ticket.php?id=${approvalId}`);
    await page.waitForSelector('.tox-tinymce', { timeout: 15000 }).catch(() => {});
    await check(page, { text: ['Approvals', 'Approve task'] });
    await callout(page, [
      { selector: await cardOf(page, 'Ticket TCK-', 't1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('p', { hasText: 'State:' }), 't2'), n: 2, side: 'tr' },
      { selector: await cardOf(page, 'Approvals', 't3'), n: 3, side: 'tr' },
      { selector: '.tox-tinymce', n: 4, side: 'tr' },
      { selector: 'button[name=add_ticket_comment]', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/07-portal-ticket`, { fullPage: true });
    await clearCallouts(page);

    const resolvedId = tid('Reception label printer prints blank labels');
    await goto(page, `/client/ticket.php?id=${resolvedId}`);
    await check(page, { text: ['Your ticket has been resolved', 'Reopen ticket', 'Close ticket'] });
    await callout(page, [
      { selector: await mark(page.locator('h4', { hasText: 'Your ticket has been resolved' }), 'r1'), n: 1 },
      { selector: 'a[href*="reopen_ticket"]', n: 2 },
      { selector: 'a[href*="close_ticket"]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/08-portal-ticket-resolved`, { fullPage: true });
    await clearCallouts(page);

    const unrated = sql("select ticket_id from tickets where ticket_contact_id = (select contact_id from contacts where contact_email = 'hannah.brooks@northshoredental.example') and ticket_closed_at is not null and ticket_csat_rating is null order by ticket_id desc limit 1");
    await goto(page, `/client/ticket.php?id=${unrated}`);
    await check(page, { text: ['How did we do?'] });
    await callout(page, [
      { selector: '.csat-faces', n: 1 },
      { selector: 'textarea[name=csat_comment]', n: 2 },
      { selector: 'button[name=add_ticket_feedback]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/09-portal-ticket-rating`, { fullPage: true });
    await clearCallouts(page);

    // --- 10 Finance menu, 11 Invoices, 12 Unpaid invoices, 13 Recurring, 14 Saved payments
    await goto(page, '/client/invoices.php');
    await openMenu(page, 'Finance');
    await callout(page, [
      { selector: 'nav.client-portal-nav .dropdown-menu.show', n: 1 },
    ]);
    await shot(page, `${G}/10-portal-finance-menu`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await goto(page, '/client/invoices.php');
    await check(page, { text: ['Invoices', 'Viewed', 'Paid'] });
    await page.evaluate(() => window.scrollTo(0, 0));
    await callout(page, [
      { selector: 'table.table thead', n: 1 },
      { selector: await mark(page.locator('table tbody tr:first-child td:first-child a'), 'i1'), n: 2 },
      { selector: await mark(page.locator('table tbody tr:first-child td:nth-child(5)'), 'i2'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('table tbody tr:first-child td:nth-child(6)'), 'i3'), n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/11-portal-invoices`);
    await clearCallouts(page);

    await goto(page, '/client/unpaid_invoices.php');
    await check(page, { text: ['Unpaid Invoices'] });
    const payBtn = page.locator('button.dropdown-toggle', { hasText: 'Pay' }).first();
    if (await payBtn.count()) { await payBtn.click(); await page.waitForTimeout(400); }
    await callout(page, [
      { selector: await mark(page.locator('table tbody tr:first-child td:nth-child(5)'), 'u1'), n: 1, side: 'tr' },
      { selector: await mark(payBtn, 'u2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `${G}/12-portal-unpaid-invoices`);
    await clearCallouts(page);

    await goto(page, '/client/recurring_invoices.php');
    await check(page, { text: ['Recurring Invoices', 'Next Bill Date'] });
    await callout(page, [
      { selector: 'table.table thead', n: 1 },
      { selector: await mark(page.locator('table thead th', { hasText: 'Auto Pay' }), 'ra'), n: 2, side: 'tr' },
    ]);
    await shot(page, `${G}/13-portal-recurring-invoices`);
    await clearCallouts(page);

    await goto(page, '/client/saved_payment_methods.php');
    await check(page, { text: ['Saved Payment Methods'] });
    await callout(page, [
      { selector: 'button[name=create_stripe_customer]', n: 1 },
    ]);
    await shot(page, `${G}/14-portal-saved-payments`);
    await clearCallouts(page);

    // --- 15 Technical menu, 16 Contacts, 17 Add contact, 18 Assets, 19 Documents (+ New Document), 20 Contracts, 21 Domains, 22 Certificates
    await goto(page, '/client/contacts.php');
    await openMenu(page, 'Technical');
    await callout(page, [{ selector: 'nav.client-portal-nav .dropdown-menu.show', n: 1 }]);
    await shot(page, `${G}/15-portal-technical-menu`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');

    await goto(page, '/client/contacts.php');
    await check(page, { text: ['Contacts', 'Primary contact'] });
    await callout(page, [
      { selector: 'a[href="contact_add.php"]', n: 1, side: 'tr' },
      { selector: await mark(page.locator('table tbody tr:first-child td:first-child a'), 'k1'), n: 2 },
      { selector: await mark(page.locator('table thead th', { hasText: 'Roles' }), 'k2'), n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/16-portal-contacts`);
    await clearCallouts(page);

    await page.setViewportSize({ width: 1440, height: 800 });
    await goto(page, '/client/contact_add.php');
    await page.fill('input[name=contact_name]', 'Avery Quinn');
    await page.fill('input[name=contact_email]', 'avery.quinn@northshoredental.example');
    await callout(page, [
      { selector: 'input[name=contact_name]', n: 1 },
      { selector: 'input[name=contact_email]', n: 2 },
      { selector: '#contactBillingCheckbox', n: 3 },
      { selector: '#contactTechnicalCheckbox', n: 4, side: 'tr' },
      { selector: 'select[name=contact_auth_method] + .ts-wrapper, select[name=contact_auth_method]', n: 5 },
      { selector: 'button[name=add_contact]', n: 6 },
    ]);
    await shot(page, `${G}/17-portal-contact-add`);
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 900 });

    await goto(page, '/client/assets.php');
    await check(page, { text: ['Assets', 'Warranty'] });
    await callout(page, [
      { selector: 'table.table thead', n: 1 },
      { selector: await mark(page.locator('table tbody tr:first-child td:nth-child(5)'), 'a1'), n: 2 },
    ]);
    await shot(page, `${G}/18-portal-assets`, { fullPage: true });
    await clearCallouts(page);

    await goto(page, '/client/documents.php');
    await check(page, { text: ['Documents', 'Office Wi-Fi and Printer Guide'] });
    await callout(page, [
      { selector: 'button[data-bs-target="#uploadDocumentModal"]', n: 1, side: 'tr' },
      { selector: 'button[data-bs-target="#uploadFileDocumentModal"]', n: 2, side: 'tr' },
      { selector: await mark(page.locator('table tbody tr:first-child td:first-child a'), 'd1'), n: 3 },
    ]);
    await shot(page, `${G}/19-portal-documents`);
    await clearCallouts(page);
    await page.locator('button[data-bs-target="#uploadFileDocumentModal"]').click();
    await page.waitForSelector('#uploadFileDocumentModal.show');
    await page.waitForTimeout(700);
    await callout(page, [
      { selector: '#uploadFileDocumentModal input[name=document_name]', n: 1 },
      { selector: '#uploadFileDocumentModal #documentFileInput', n: 2 },
      { selector: '#uploadFileDocumentModal button[name=client_upload_document]', n: 3 },
    ]);
    await shot(page, `${G}/20-portal-upload-document`);
    await clearCallouts(page);

    await goto(page, '/client/contracts.php');
    await check(page, { text: ['Contracts & Documents', 'Fully Managed IT Agreement'] });
    await callout(page, [
      { selector: await mark(page.locator('#contractAccordion .card-header').first(), 'ct1'), n: 1 },
    ]);
    await shot(page, `${G}/21-portal-contracts`);
    await clearCallouts(page);

    // --- 22 Security, 23 Knowledge Base, 24 Profile
    await goto(page, '/client/compliance.php');
    await check(page, { text: ['Your compliance', 'Policies and reviews'] });
    await callout(page, [
      { selector: await mark(page.locator('.card-body', { hasText: 'self-assessment' }).first(), 'p1'), n: 1 },
      { selector: await mark(page.locator('.row.g-3.mb-4').first(), 'p2'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card-header', { hasText: 'Policies and reviews' }).locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'p3'), n: 3, side: 'tr' },
    ]);
    await page.setViewportSize({ width: 1440, height: 1250 });
    await shot(page, `${G}/22-portal-security`);
    await page.setViewportSize({ width: 1440, height: 900 });
    await clearCallouts(page);

    await goto(page, '/client/kb_articles.php');
    await check(page, { text: ['Knowledge Base'] });
    await callout(page, [
      { selector: 'input[name=q]', n: 1 },
      { selector: await mark(page.locator('.card-title').first().locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'kb1'), n: 2, side: 'tr' },
    ]);
    await shot(page, `${G}/23-portal-knowledge-base`, { fullPage: true });
    await clearCallouts(page);

    await goto(page, '/client/profile.php');
    await check(page, { text: ['Profile', 'Login via: local'] });
    await callout(page, [
      { selector: await mark(page.locator('p', { hasText: 'Client Primary Contact' }), 'pr1'), n: 1 },
      { selector: 'input[name=new_password]', n: 2 },
      { selector: 'button[name=edit_profile]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/24-portal-profile`);
    await clearCallouts(page);
    await context.close();
  }

  // ============================================================== ROLES: what other kinds of contacts see
  if (want('roles')) {
    const roles = [
      ['25-portal-role-billing', 'denise.hargrove@granitepeakmfg.example', ['Account Balance Due', 'Recurring Monthly']],
      ['26-portal-role-technical', 'marcus.webb@granitepeakmfg.example', ['Needs Attention', 'Assigned Asset']],
      ['27-portal-role-plain', 'rosa.martinelli@cedarpinearch.example', ['Quick Links']],
      ['28-portal-role-primary', 'margaret.birchwood@birchwoodlaw.example', ['Account Balance Due', 'Needs Attention']],
    ];
    for (const [name, email, texts] of roles) {
      const { context, page } = await newPage(browser);
      await loginPortal(page, BASE, email);
      await goto(page, '/client/index.php');
      await check(page, { text: texts });
      await callout(page, [
        { selector: 'nav.client-portal-nav .navbar-nav.me-auto', n: 1, side: 'bl' },
        { selector: '.row.mb-3:has(.small-box)', n: 2 },
        { selector: await cardOf(page, 'Quick Links', 'ql'), n: 3, side: 'tr' },
      ]);
      await shot(page, `${G}/${name}`, { fullPage: true });
      await clearCallouts(page);
      await context.close();
    }
    // Theo Lindqvist (Birchwood, billing + technical): a Sent quote is waiting
    const { context, page } = await newPage(browser);
    await loginPortal(page, BASE, 'theo.lindqvist@birchwoodlaw.example');
    await goto(page, '/client/quotes.php');
    await check(page, { text: ['Quotes', 'Sent'] });
    await callout(page, [
      { selector: await mark(page.locator('table tbody tr:first-child td:first-child a'), 'q1'), n: 1 },
      { selector: await mark(page.locator('table thead th', { hasText: 'Status' }), 'q2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `${G}/29-portal-quotes`);
    await clearCallouts(page);
    await context.close();
  }

  // ============================================================== ADMIN / TECHNICIAN SIDE
  if (want('admin')) {
    await loginAdmin(adminPage, BASE);
    const page = adminPage;
    // contact record: Access tab
    const contact = sql("select contact_id from contacts where contact_email = 'theo.lindqvist@birchwoodlaw.example'");
    const client = sql("select contact_client_id from contacts where contact_email = 'theo.lindqvist@birchwoodlaw.example'");
    await page.setViewportSize({ width: 1440, height: 1100 });
    await goto(page, `/agent/contacts.php?client_id=${client}`);
    await page.locator(`a.dropdown-item.ajax-modal[data-modal-url*="contact_edit.php?id=${contact}"], a.ajax-modal[data-modal-url*="contact_edit.php?id=${contact}"]`).first().evaluate((el) => el.click()).catch(async () => {
      await page.goto(`${BASE}/agent/contact_details.php?contact_id=${contact}`, { waitUntil: 'domcontentloaded' });
    });
    await page.waitForSelector('.modal.show .modal-content', { timeout: 10000 }).catch(() => {});
    if (!(await page.locator('.modal.show').count())) {
      // open the modal through the app's own loader
      await page.evaluate((id) => {
        const a = document.createElement('a'); a.href = '#'; a.className = 'ajax-modal'; a.dataset.modalUrl = `modals/contact/contact_edit.php?id=${id}`;
        document.body.appendChild(a); a.click();
      }, contact);
      await page.waitForSelector('.modal.show .modal-content');
    }
    await page.waitForTimeout(900);
    await page.locator('.modal.show a[href^="#pills-access"]').click();
    await page.waitForTimeout(600);
    await callout(page, [
      { selector: '.modal.show select[name=auth_method] + .ts-wrapper, .modal.show select[name=auth_method]', n: 1 },
      { selector: '.modal.show input[name=send_email]', n: 2 },
      { selector: '.modal.show input[name=contact_billing]', n: 3, side: 'tr' },
      { selector: '.modal.show input[name=contact_technical]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/30-agent-contact-access`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1440, height: 900 });

    await goto(page, '/admin/settings_module.php');
    await check(page, { text: ['Enable Client Portal'] });
    await callout(page, [{ selector: await mark(page.locator('label', { hasText: 'Enable Client Portal' }).locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'm1'), n: 1, side: 'tr' }]);
    await shot(page, `${G}/31-admin-modules-portal`);
    await clearCallouts(page);

    await goto(page, '/admin/settings_portal_branding.php');
    await check(page, { text: ['Client Portal Branding'] });
    await callout(page, [
      { selector: 'input[name=file]', n: 1 },
      { selector: 'input[name=config_portal_display_name]', n: 2 },
      { selector: '#portal_accent_hex', n: 3, side: 'tr' },
      { selector: 'button[name=edit_portal_branding_settings]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/32-admin-portal-branding`);
    await clearCallouts(page);

    await page.setViewportSize({ width: 1440, height: 1300 });
    await goto(page, '/admin/identity_provider.php');
    await check(page, { text: ['Identity Providers', 'OpenID Connect for Client Portal'] });
    await callout(page, [
      { selector: await mark(page.locator('h4', { hasText: 'Client Portal SSO via Microsoft Entra' }), 'idp1'), n: 1 },
      { selector: await mark(page.locator('h4', { hasText: 'OpenID Connect for Client Portal' }), 'idp2'), n: 2 },
      { selector: '#oidc_link_by_email', n: 3, side: 'tr' },
      { selector: '#oidc_require_verified_email', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/33-admin-identity-provider`);
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 900 });
  }

  // ============================================================== GUEST PAGES (no sign-in) on the shared demo, read-only
  if (want('guest')) {
    const guestUrl = (kind, id, key) => `${BASE}/guest/${kind}`;
    // already-Viewed invoice for Northshore: opening it only adds a history line
    const inv = sql("select concat(invoice_id,'|',invoice_url_key) from invoices where invoice_client_id = (select client_id from clients where client_name = 'Northshore Dental Group') and invoice_status = 'Viewed' order by invoice_id desc limit 1").split('|');
    const { context, page } = await newPage(browser, 1440, 1000);
    await page.goto(`${BASE}/guest/guest_view_invoice.php?invoice_id=${inv[0]}&url_key=${inv[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['INVOICE', 'Bill To:', 'Account Balance'] });
    await callout(page, [
      { selector: await mark(page.locator('h4', { hasText: 'Account Balance' }), 'g1'), n: 1 },
      { selector: '.js-print-page', n: 2, side: 'tr' },
      { selector: 'a[href*="export_invoice_pdf"]', n: 3, side: 'tr' },
      { selector: 'a[href*="guest_pay_invoice_stripe"]', n: 4, side: 'tr' },
      { selector: await mark(page.locator('h5.badge'), 'g5'), n: 5, side: 'tr' },
    ]);
    await shot(page, `portal/guest-01-invoice`, { fullPage: true });
    await clearCallouts(page);

    // quote: already-Viewed one (Granite Peak firewall)
    const quo = sql("select concat(quote_id,'|',quote_url_key) from quotes where quote_status = 'Viewed' order by quote_id desc limit 1").split('|');
    await page.goto(`${BASE}/guest/guest_view_quote.php?quote_id=${quo[0]}&url_key=${quo[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['QUOTE', 'Expires:', 'Accept', 'Decline'] });
    await callout(page, [
      { selector: await mark(page.locator('h5.badge'), 'gq1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('th', { hasText: 'Expires:' }), 'gq2'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('button.btn-success', { hasText: 'Accept' }), 'gq3'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('button.btn-danger', { hasText: 'Decline' }), 'gq4'), n: 4, side: 'tr' },
    ]);
    await shot(page, `portal/guest-03-quote`, { fullPage: true });
    await clearCallouts(page);

    // tickets: a resolved one and a closed one, both from the guest link
    const gt = (where) => sql(`select concat(ticket_id,'|',ticket_url_key) from tickets where ${where} order by ticket_id desc limit 1`).split('|');
    const resolved = gt("ticket_subject = 'Reception label printer prints blank labels'");
    await page.goto(`${BASE}/guest/guest_view_ticket.php?ticket_id=${resolved[0]}&url_key=${resolved[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['Your ticket has been resolved', 'Reopen ticket', 'Close ticket'] });
    await callout(page, [
      { selector: await mark(page.locator('.tkt-pill-badge').first(), 'gt1'), n: 1 },
      { selector: await mark(page.locator('button', { hasText: 'Reopen ticket' }), 'gr2'), n: 2 },
      { selector: await mark(page.locator('button', { hasText: 'Close ticket' }), 'gr3'), n: 3, side: 'tr' },
    ]);
    await shot(page, `portal/guest-07-ticket-resolved`, { fullPage: true });
    await clearCallouts(page);

    const open = gt("ticket_subject = 'Add a second monitor at the front desk'");
    await page.goto(`${BASE}/guest/guest_view_ticket.php?ticket_id=${open[0]}&url_key=${open[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['log in'] });
    await callout(page, [
      { selector: await mark(page.locator('h6', { hasText: 'log in' }), 'go1'), n: 1 },
    ]);
    await shot(page, `portal/guest-06-ticket-open`, { fullPage: true });
    await clearCallouts(page);

    const closed = gt("ticket_closed_at is not null and ticket_csat_rating is null and ticket_client_id = 1");
    await page.goto(`${BASE}/guest/guest_view_ticket.php?ticket_id=${closed[0]}&url_key=${closed[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['How did we do?'] });
    await callout(page, [
      { selector: '.csat-faces', n: 1 },
      { selector: 'textarea[name=csat_comment]', n: 2 },
    ]);
    await shot(page, `portal/guest-08-ticket-rating`, { fullPage: true });
    await clearCallouts(page);

    // task approval (pending, seed 71)
    const ap = sql("select concat(a.approval_id,'|',a.approval_url_key) from task_approvals a join tasks t on t.task_id = a.approval_task_id join tickets k on k.ticket_id = t.task_ticket_id where k.ticket_subject = 'Add a second monitor at the front desk' and a.approval_scope = 'client' order by a.approval_id desc limit 1").split('|');
    await page.goto(`${BASE}/guest/guest_approve_ticket_task.php?task_approval_id=${ap[0]}&url_key=${ap[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['Task Approval', 'Approve Task'] });
    await callout(page, [
      { selector: await mark(page.locator('p', { hasText: 'Task Name' }), 'ga1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('a', { hasText: 'Approve Task' }), 'ga2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `portal/guest-10-task-approval`);
    await clearCallouts(page);

    // change approval (Granite Peak core switch, pending)
    const ch = sql("select concat(change_id,'|',url_key) from changes where requires_client_approval = 1 and client_decision = 'pending' and url_key is not null order by change_id desc limit 1").split('|');
    await page.goto(`${BASE}/guest/guest_view_change.php?change_id=${ch[0]}&url_key=${ch[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['Approve', 'Reject'] });
    await callout(page, [
      { selector: await mark(page.locator('span.badge', { hasText: 'Risk' }), 'gc1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('h5.badge'), 'gc2'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('button.btn-success', { hasText: 'Approve' }), 'gc3'), n: 3 },
      { selector: await mark(page.locator('button.btn-danger', { hasText: 'Reject' }), 'gc4'), n: 4, side: 'tr' },
    ]);
    await shot(page, `portal/guest-12-change`);
    await clearCallouts(page);

    // shared items
    const share = (label) => sql(`select concat(item_id,'|',item_key) from shared_items where item_key = md5('${label}')`).split('|');
    const EK = 'DemoEk-Lakeshore';
    let s = share('g12-doc-wifi');
    await page.goto(`${BASE}/guest/guest_view_item.php?id=${s[0]}&key=${s[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['Secure link intended for', 'Office Wi-Fi and Printer Guide'] });
    await callout(page, [
      { selector: await mark(page.locator('.card-header.bg-dark'), 'gs1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.prettyContent'), 'gs2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `portal/guest-16-shared-document`, { fullPage: true });
    await clearCallouts(page);

    s = share('g12-cred-view');
    await page.goto(`${BASE}/guest/guest_view_item.php?id=${s[0]}&key=${s[1]}#ek=${EK}`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !document.body.innerText.includes('Decrypting'), null, { timeout: 8000 }).catch(() => {});
    await settle(page);
    await check(page, { text: ['Insurance Claims Portal', 'Demo-Only-Not-A-Real-Password'] });
    await callout(page, [
      { selector: '#credUsername', n: 1, side: 'tr' },
      { selector: '#credPassword', n: 2, side: 'tr' },
    ]);
    await shot(page, `portal/guest-17-shared-credential`, { fullPage: true });
    await clearCallouts(page);
    // the same link with the key cut off
    await page.goto(`${BASE}/guest/guest_view_item.php?id=${s[0]}&key=${s[1]}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await check(page, { text: ['decryption key is missing'] });
    await callout(page, [{ selector: '#sharedCredentialKeyMissing', n: 1, side: 'tr' }]);
    await shot(page, `portal/guest-18-shared-credential-no-key`, { fullPage: true });
    await clearCallouts(page);

    s = share('g12-file-net');
    await page.goto(`${BASE}/guest/guest_view_item.php?id=${s[0]}&key=${s[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['A file has been shared with you'] });
    await callout(page, [
      { selector: await mark(page.locator('a', { hasText: 'Download NSD-Network-Diagram' }), 'gf1'), n: 1, side: 'tr' },
    ]);
    await shot(page, `portal/guest-19-shared-file`, { fullPage: true });
    await clearCallouts(page);

    // a link that is not valid
    await page.goto(`${BASE}/guest/guest_view_item.php?id=${s[0]}&key=not-a-real-key`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await shot(page, `portal/guest-24-invalid-link`);

    // outtake form (unsigned) and a signed one
    const ot = sql("select outtake_sign_token from ticket_outtake_forms where outtake_signed_at is null order by outtake_id desc limit 1");
    await page.setViewportSize({ width: 1440, height: 1420 });
    await page.goto(`${BASE}/guest/outtake_sign.php?token=${ot}`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['Full Name', 'Signature'] });
    // no call-outs here: the page scales its own layout, which throws overlay positions off
    await shot(page, `portal/guest-21-outtake-sign`);
    await context.close();
  }

  // ============================================================== AGENT-SIDE WHERE THE LINKS COME FROM (read-only)
  if (want('agentlinks')) {
    if (!(await adminPage.url().includes('/agent/'))) await loginAdmin(adminPage, BASE);
    const page = adminPage;
    await page.setViewportSize({ width: 1440, height: 900 });
    const inv = sql("select invoice_id from invoices where invoice_client_id = (select client_id from clients where client_name = 'Northshore Dental Group') and invoice_status = 'Viewed' order by invoice_id desc limit 1");
    await goto(page, `/agent/invoice.php?invoice_id=${inv}`);
    await page.locator('a.dropdown-item.clipboardjs', { hasText: 'Copy Guest URL' }).locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," dropdown ")][1]').locator('[data-bs-toggle=dropdown]').first().click();
    await page.waitForTimeout(500);
    await callout(page, [{ selector: await mark(page.locator('a.dropdown-item.clipboardjs', { hasText: 'Copy Guest URL' }), 'al1'), n: 1, side: 'tr' }]);
    await shot(page, `portal/guest-02-agent-invoice-copy-url`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');

    const quo = sql("select quote_id from quotes where quote_status = 'Viewed' order by quote_id desc limit 1");
    await goto(page, `/agent/quote.php?quote_id=${quo}`);
    await page.locator('a.dropdown-item.clipboardjs', { hasText: 'Copy Guest URL' }).locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," dropdown ")][1]').locator('[data-bs-toggle=dropdown]').first().click();
    await page.waitForTimeout(500);
    await callout(page, [{ selector: await mark(page.locator('a.dropdown-item.clipboardjs', { hasText: 'Copy Guest URL' }), 'al2'), n: 1, side: 'tr' }]);
    await shot(page, `portal/guest-04-agent-quote-copy-url`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');

    // task approver modal on the seeded ticket
    const tk = tid('Add a second monitor at the front desk');
    await page.setViewportSize({ width: 1440, height: 1100 });
    await goto(page, `/agent/ticket.php?ticket_id=${tk}`);
    const taskRow = page.locator('span', { hasText: 'Approve the monitor purchase' }).first();
    await taskRow.scrollIntoViewIfNeeded();
    const rowBox = taskRow.locator('xpath=ancestor::div[.//i[contains(@class,"fa-ellipsis-v")]][1]');
    await rowBox.locator('i.fa-ellipsis-v').first().click();
    await page.waitForTimeout(400);
    await callout(page, [{ selector: await mark(page.locator('.dropdown-menu.show a', { hasText: 'Add Approvers' }), 'ap1'), n: 1, side: 'tr' }]);
    await shot(page, `portal/guest-09-agent-task-menu`);
    await clearCallouts(page);
    await page.locator('.dropdown-menu.show a', { hasText: 'Add Approvers' }).click();
    await page.waitForSelector('.modal.show .modal-content');
    await page.waitForTimeout(800);
    await page.selectOption('.modal.show select[name=approval_scope]', 'client');
    await page.waitForTimeout(300);
    await callout(page, [
      { selector: '.modal.show select[name=approval_scope]', n: 1 },
      { selector: '.modal.show select[name=approval_type]', n: 2 },
    ]);
    await shot(page, `portal/guest-11-agent-task-approvers-modal`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    // change that needs the client's sign-off
    const chg = sql("select change_id from changes where requires_client_approval = 1 and client_decision = 'pending' and url_key is not null order by change_id desc limit 1");
    await page.setViewportSize({ width: 1440, height: 900 });
    await goto(page, `/agent/change_details.php?id=${chg}`);
    await check(page, { text: ['sign-off'] });
    await callout(page, [
      { selector: await mark(page.locator('.small.text-muted', { hasText: 'sign-off' }).locator('xpath=ancestor::div[contains(@class,"card")][1]'), 'chg1'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('button[name=send_change_approval_request]'), 'chg2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `portal/guest-13-agent-change-approval`);
    await clearCallouts(page);

    // the Share Link pop-up on a document, and the Shared Items card on the client's overview
    const nsdId = sql("select client_id from clients where client_name = 'Northshore Dental Group'");
    await goto(page, `/agent/files.php?client_id=${nsdId}`);
    const shareItem = page.locator('a[data-bs-target="#shareModal"][data-share-modal*="File"]').first();
    await shareItem.locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," dropdown ")][1]').locator('[data-bs-toggle=dropdown]').first().click();
    await page.waitForTimeout(300);
    await shareItem.click();
    await page.waitForSelector('#shareModal.show');
    await page.waitForTimeout(900);
    await callout(page, [
      { selector: '#shareModal .ts-wrapper', n: 1 },
      { selector: '#shareModal .btn-group.js-btn-group-toggle', n: 2 },
      { selector: '#share_views', n: 3, side: 'tr' },
      { selector: '#share_note', n: 4 },
      { selector: '#div_share_link_generate', n: 5, side: 'tr' },
    ]);
    await shot(page, `portal/guest-14-agent-share-link`);
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    await page.setViewportSize({ width: 1440, height: 900 });
    const nsd = sql("select client_id from clients where client_name = 'Northshore Dental Group'");
    await goto(page, `/agent/client_overview.php?client_id=${nsd}`);
    const si = await cardOf(page, 'Shared Items', 'si1');
    await page.locator(si).scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await callout(page, [
      { selector: si, n: 1, side: 'tr' },
      { selector: await mark(page.locator(`${si} a[href*="deactivate_shared_item"]`), 'si2'), n: 2, side: 'tr' },
    ]);
    await shot(page, `portal/guest-15-agent-shared-items`, { selector: si });
    await clearCallouts(page);
  }

  // ============================================================== SANDBOX: shots that change data (throw-away copy only)
  if (want('sandbox')) {
    if (!SANDBOX || !SDB) {
      console.log('sandbox shots skipped: set G12_SANDBOX_URL and G12_SANDBOX_DB (see the header of this file)');
    } else {
      const ssql = (q) => sql(q, SDB);
      const { context, page } = await newPage(browser, 1440, 1000);
      // accept a quote from the guest link, then the upload pop-up
      const quo = ssql("select concat(quote_id,'|',quote_url_key) from quotes where quote_status in ('Sent','Viewed') and quote_client_id = (select client_id from clients where client_name = 'Birchwood Law Partners') order by quote_id desc limit 1").split('|');
      await page.goto(`${SANDBOX}/guest/guest_view_quote.php?quote_id=${quo[0]}&url_key=${quo[1]}`, { waitUntil: 'domcontentloaded' });
      page.once('dialog', (d) => d.accept());
      await page.locator('button.btn-success', { hasText: 'Accept' }).click();
      // the app asks for confirmation with its own dialog
      await page.waitForTimeout(800);
      const ok = page.locator('.rivet-dialog button.btn-primary, .modal.show button.btn-primary, .swal2-confirm, button:has-text("OK"), button:has-text("Yes")').first();
      if (await ok.count()) await ok.click().catch(() => {});
      await page.waitForLoadState('domcontentloaded');
      await settle(page);
      await check(page, { text: ['Upload File'] });
      await callout(page, [
        { selector: await mark(page.locator('h5.badge'), 'sq1'), n: 1, side: 'tr' },
        { selector: await mark(page.locator('button', { hasText: 'Upload File' }), 'sq2'), n: 2, side: 'tr' },
      ]);
      await shot(page, `portal/guest-05-quote-accepted`, { fullPage: true });
      await clearCallouts(page);
      await context.close();
    }
  }

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
