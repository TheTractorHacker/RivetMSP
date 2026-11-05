// Screenshots for docs/user-guide/17-payments-autopay-stripe.md, 18-expenses-revenues-accounts.md, 19-payroll.md and
// 20-accounting-pax8-sync.md (group "money", images/money/NN-slug.png).
// Needs seeds 00-core, 01-modules-and-permissions, 55-money-accounts-expenses, 56-money-invoices-payments,
// 57-money-stripe-autopay (php), 58-money-payroll (php) and 59-money-accounting-pax8 applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/money.cjs [payments|finance|payroll|accounting]
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/
//
// Read-only with respect to app data: pop-ups are opened (and sometimes filled with illustrative values) but never saved.
// Stripe, QuickBooks Online, Xero and Pax8 cannot be reached from the sandbox: every screen is shown with seeded example
// rows and is NOT a live connection.

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');
const G = 'money';
const W = 1680;

function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
async function check(page, { rows = 0, text = [] } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access/.test(body)) fail(`unexpected page on ${page.url()}`);
  if (rows && (await page.locator('table tbody tr').count()) < rows) fail(`too few rows on ${page.url()}`);
  for (const t of text) if (!body.toLowerCase().includes(t.toLowerCase())) fail(`"${t}" not found on ${page.url()}`);
}
// Tag an element so the call-out helper can find it with a plain CSS selector.
async function mark(locator, id) {
  if (!(await locator.count())) fail(`cannot mark ${id}: element not found on ${locator.page().url()}`);
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}
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
async function pageHrefId(page, url, rowSel, param) {
  await goto(page, url);
  const href = await page.locator(rowSel).first().getAttribute('href');
  if (!href) fail(`no link for ${rowSel} at ${url}`);
  return new URL(href, 'http://x/agent/').searchParams.get(param);
}
async function clientId(page, name) {
  return pageHrefId(page, `/agent/clients.php?q=${encodeURIComponent(name)}`, 'table tbody tr a[href^="client_overview.php"]', 'client_id');
}
// Invoice id by client name and a status word shown in the client's invoice list
async function invoiceOf(page, clientName, scopeText, status) {
  const cid = await clientId(page, clientName);
  await goto(page, `/agent/invoices.php?client_id=${cid}&q=${encodeURIComponent(scopeText)}`);
  const rows = page.locator('table tbody tr');
  const n = await rows.count();
  for (let i = 0; i < n; i++) {
    const t = await rows.nth(i).innerText();
    if (t.includes(scopeText) && (!status || t.includes(status))) {
      const href = await rows.nth(i).locator('a[href^="invoice.php"]').first().getAttribute('href');
      return { cid, id: new URL(href, 'http://x/agent/').searchParams.get('invoice_id') };
    }
  }
  fail(`invoice ${clientName} / ${scopeText} / ${status} not found`);
}
async function openMenuOfRow(page, rowLocator) {
  await rowLocator.locator('button.dropdown-toggle, button[data-bs-toggle="dropdown"]').last().click();
  await page.waitForTimeout(400);
}
// The "..." menu of the first row whose text contains `text`; returns the selector of the whole menu
async function rowMenu(page, text, marker) {
  const row = page.locator('table tbody tr', { hasText: text }).first();
  if (!(await row.count())) fail(`row "${text}" not found on ${page.url()}`);
  await openMenuOfRow(page, row);
  return mark(page.locator('.dropdown-menu.show', { has: page.locator('.dropdown-item') }).last(), marker);
}

async function payments(browser) {
  const ctx = await browser.newContext({ viewport: { width: W, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light' });
  const page = await ctx.newPage();
  page.setDefaultTimeout(20000);
  await login(page, 'admin');

  // 01 Payments list
  await goto(page, '/agent/payments.php');
  await check(page, { rows: 6, text: ['Payment Date', 'Reference'] });
  await callout(page, [
    { selector: await mark(page.locator('input[name=q]'), 'q'), n: 1 },
    { selector: await mark(page.locator('select[name=account]').locator('xpath=..'), 'acc'), n: 2 },
    { selector: await mark(page.locator('select[name=method]').locator('xpath=..'), 'meth'), n: 3 },
    { selector: await mark(page.locator('.card-tools button', { hasText: 'Export' }), 'exp'), n: 4, side: 'tr' },
    { selector: await mark(page.locator('table thead th', { hasText: 'Reference' }), 'ref'), n: 5 },
    { selector: await mark(page.locator('table tbody tr').first().locator('button.dropdown-toggle, button[data-bs-toggle=dropdown]'), 'menu'), n: 6, side: 'tr' },
  ]);
  await shot(page, `${G}/17-01-payments-list`);

  // 02 Row menu on a bank payment (Edit / Delete)
  await clearCallouts(page);
  const menu = await rowMenu(page, 'ACH 7712-IRL', 'rowmenu');
  await callout(page, [{ selector: menu, n: 1 }]);
  await shot(page, `${G}/17-02-payment-row-menu`);
  await page.keyboard.press('Escape');

  // 03 Filtered by method Stripe
  await goto(page, '/agent/payments.php?method=Stripe');
  await check(page, { rows: 3 });
  await callout(page, [
    { selector: await mark(page.locator('select[name=method]').locator('xpath=..'), 'meth'), n: 1 },
    { selector: await mark(page.locator('table tbody tr').first().locator('td').nth(7), 'sref'), n: 2 },
  ]);
  await shot(page, `${G}/17-03-payments-stripe-filter`);

  // 04 Invoice with Add Payment button (open invoice of an AutoPay client: Lakeview, Sent)
  const inv = await invoiceOf(page, 'Lakeview Family Credit Union', 'October 2026', 'Sent');
  await goto(page, `/agent/invoice.php?invoice_id=${inv.id}`);
  await check(page, { text: ['Add Payment'] });
  const split = page.locator('.btn-group', { has: page.locator('button', { hasText: 'Add Payment' }) }).first();
  await split.locator('.dropdown-toggle-split').click();
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: await mark(page.locator('button', { hasText: 'Add Payment' }).first(), 'addpay'), n: 1 },
    { selector: await mark(page.locator('.dropdown-item', { hasText: 'Pay with Saved Card' }), 'saved'), n: 2 },
  ]);
  await shot(page, `${G}/17-04-invoice-add-payment`);
  await page.keyboard.press('Escape');
  await page.mouse.click(10, 400);

  // 05 Add Payment pop-up (partly paid Cedar & Pine invoice)
  const inv2 = await invoiceOf(page, 'Cedar & Pine Architects', 'October 2026', 'Partial');
  await goto(page, `/agent/invoice.php?invoice_id=${inv2.id}`);
  await openModal(page, page.locator('button', { hasText: 'Add Payment' }).first());
  await callout(page, [
    { selector: await mark(page.locator('.modal.show input[name=amount]'), 'amt'), n: 1 },
    { selector: await mark(page.locator('.modal.show select[name=account]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'acct'), n: 2 },
    { selector: await mark(page.locator('.modal.show select[name=payment_method]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'pm'), n: 3 },
    { selector: await mark(page.locator('.modal.show input[name=reference]'), 'refin'), n: 4 },
    { selector: await mark(page.locator('.modal.show button[name=add_payment]'), 'pay'), n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/17-05-add-payment-modal`);
  await closeModal(page);

  // 06 Invoice payments table / history of a paid Stripe invoice
  const inv3 = await invoiceOf(page, 'Birchwood Law Partners', 'October 2026', 'Paid');
  await goto(page, `/agent/invoice.php?invoice_id=${inv3.id}`);
  await check(page, { text: ['Stripe'] });
  await shot(page, `${G}/17-06-paid-invoice-stripe`, { fullPage: true });

  // 07 Batch payment (client invoices list, bulk action) for Maple Street Veterinary Clinic
  const cid = await clientId(page, 'Maple Street Veterinary Clinic');
  await goto(page, `/agent/invoices.php?client_id=${cid}`);
  await page.locator('table tbody tr .bulk-select').first().check();
  await page.waitForTimeout(300);
  await page.locator('#bulkActionButton button.dropdown-toggle').click();
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: await mark(page.locator('table tbody tr .bulk-select').first(), 'cb'), n: 1 },
    { selector: await mark(page.locator('#bulkActionButton button.dropdown-toggle'), 'bulk'), n: 2 },
    { selector: await mark(page.locator('.dropdown-item', { hasText: 'Batch Payment' }), 'batch'), n: 3 },
  ]);
  await shot(page, `${G}/17-07-batch-payment-menu`);
  await clearCallouts(page);
  await openModal(page, page.locator('.dropdown-item', { hasText: 'Batch Payment' }));
  await callout(page, [
    { selector: await mark(page.locator('.modal.show .alert-info'), 'notice'), n: 1 },
    { selector: await mark(page.locator('.modal.show input[name=amount]'), 'amt'), n: 2 },
  ]);
  await shot(page, `${G}/17-08-batch-payment-modal`);
  await closeModal(page);

  // 09 Recurring invoice with the AutoPay selector (Lakeview)
  const lv = await clientId(page, 'Lakeview Family Credit Union');
  await goto(page, `/agent/recurring_invoices.php?client_id=${lv}`);
  const rihrefs = await page.locator('table tbody tr a[href^="recurring_invoice.php"]').evaluateAll((as) => [...new Set(as.map((a) => a.getAttribute('href')))]);
  for (const h of rihrefs) {
    await goto(page, `/agent/${h}`);
    if (await page.locator('select[name=saved_payment_id]').count()) break;
  }
  await check(page, { text: ['Email Notify'] });
  await page.locator('form:has(select[name=saved_payment_id]) .ts-control').first().click();
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: await mark(page.locator('a.btn', { hasText: 'Email Notify' }).first(), 'notify'), n: 1 },
    { selector: await mark(page.locator('form:has(select[name=saved_payment_id])'), 'autopay'), n: 2, side: 'tr' },
    { selector: await mark(page.locator('.ts-dropdown').last(), 'opts'), n: 3, side: 'br' },
  ]);
  await shot(page, `${G}/17-09-recurring-invoice-autopay`);
  await page.keyboard.press('Escape');

  // 10 Client AutoPay overview (page is reached by URL only)
  await goto(page, `/agent/client_autopay.php?client_id=${lv}`);
  await check(page, { text: ['AutoPay', 'visa'.length ? 'mastercard' : ''] });
  await callout(page, [{ selector: await mark(page.locator('.card-body').first(), 'autopaybody'), n: 1 }]);
  await page.setViewportSize({ width: W, height: 480 });
  await shot(page, `${G}/17-10-client-autopay`);
  await page.setViewportSize({ width: W, height: 900 });

  // 11 Payment providers (admin)
  await goto(page, '/admin/payment_provider.php');
  await check(page, { text: ['Payment Providers', 'Customer Mapping', 'Stripe'] });
  await callout(page, [
    { selector: await mark(page.locator('.card-tools .btn', { hasText: 'Add Provider' }), 'add'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('table').first().locator('tbody tr').first(), 'prov'), n: 2 },
    { selector: await mark(page.locator('table').first().locator('tbody tr').first().locator('td').nth(6), 'saved'), n: 3 },
    { selector: await mark(page.locator('h3.card-title', { hasText: 'Customer Mapping' }).locator('xpath=ancestor::div[contains(@class,"card")]'), 'map'), n: 4 },
  ]);
  await shot(page, `${G}/17-11-payment-providers`, { fullPage: true });

  // 12 Edit provider pop-up: Details tab and Expense tab
  await clearCallouts(page);
  await openModal(page, page.locator('table').first().locator('tbody tr').first().locator('a.ajax-modal').first());
  await callout(page, [
    { selector: await mark(page.locator('.modal.show input[name=public_key]'), 'pk'), n: 1 },
    { selector: await mark(page.locator('.modal.show input[name=private_key]'), 'sk'), n: 2 },
    { selector: await mark(page.locator('#stripe_webhook_url_field').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'wh'), n: 3 },
    { selector: await mark(page.locator('.modal.show input[name=webhook_secret]'), 'whsec'), n: 4 },
    { selector: await mark(page.locator('.modal.show input[name=threshold]'), 'thr'), n: 5 },
  ]);
  await shot(page, `${G}/17-12-provider-details`, { fullPage: true });
  await clearCallouts(page);
  await page.locator('.modal.show .nav-link', { hasText: 'Expense' }).click();
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: await mark(page.locator('.modal.show select[name=expense_vendor]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'ven'), n: 1 },
    { selector: await mark(page.locator('.modal.show select[name=expense_category]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'cat'), n: 2 },
    { selector: await mark(page.locator('.modal.show input[name=percentage_fee]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'pct'), n: 3 },
    { selector: await mark(page.locator('.modal.show input[name=flat_fee]').locator('xpath=ancestor::div[contains(@class,"form-group")]'), 'flat'), n: 4 },
  ]);
  await shot(page, `${G}/17-13-provider-expense`, { fullPage: true });
  await closeModal(page);

  // 14 Saved payment methods (admin)
  await goto(page, '/admin/saved_payment_method.php');
  await check(page, { rows: 3, text: ['Saved Payment Methods'] });
  await shot(page, `${G}/17-14-saved-payment-methods`);

  // 15 Payment methods (admin)
  await goto(page, '/admin/payment_method.php');
  await check(page, { rows: 3, text: ['Payment Methods'] });
  await shot(page, `${G}/17-15-payment-methods`);
  await ctx.close();

  // Client portal: Saved Payments (Northshore Dental has not authorised automatic payments, so the consent step shows)
  const pctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light' });
  const portal = await pctx.newPage();
  portal.setDefaultTimeout(20000);
  await login(portal, 'portal');
  await portal.goto(`${BASE}/client/saved_payment_methods.php`, { waitUntil: 'domcontentloaded' });
  await settle(portal, 800);
  await check(portal, { text: ['Saved Payment Methods', 'I grant consent'] });
  await portal.locator('a.dropdown-toggle', { hasText: 'Finance' }).first().click().catch(() => {});
  await portal.waitForTimeout(400);
  await callout(portal, [
    { selector: await mark(portal.locator('a.dropdown-toggle', { hasText: 'Finance' }).first(), 'fin'), n: 1 },
    { selector: await mark(portal.locator('button[name=create_stripe_customer]'), 'consent'), n: 2 },
  ]);
  await shot(portal, `${G}/17-16-portal-saved-payments`);

  // Guest invoice page with Pay Now (the public link of an unpaid invoice)
  const { execSync } = require('child_process');
  const row = execSync(`mysql -u root rivetmsp_demo -N -e "SELECT invoice_id, invoice_url_key FROM invoices WHERE invoice_url_key = MD5('ug-invoice-A6') LIMIT 1"`).toString().trim().split(/\s+/);
  if (row.length === 2) {
    await portal.goto(`${BASE}/guest/guest_view_invoice.php?invoice_id=${row[0]}&url_key=${row[1]}`, { waitUntil: 'domcontentloaded' });
    await settle(portal, 800);
    await check(portal, { text: ['Pay Now'] });
    await callout(portal, [{ selector: await mark(portal.locator('a.btn-success', { hasText: 'Pay Now' }), 'paynow'), n: 1 }]);
    await shot(portal, `${G}/17-17-guest-invoice-pay-now`);
  }
  await pctx.close();
}


function fg(page, sel) { return page.locator(sel).first().locator('xpath=ancestor::div[contains(@class,"form-group")][1]'); }
async function admin(browser, who = 'admin', width = W) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light' });
  const page = await ctx.newPage();
  page.setDefaultTimeout(20000);
  await login(page, who);
  return { ctx, page };
}
async function listShot(page, url, name, texts, marks) {
  await goto(page, url);
  await check(page, { rows: 1, text: texts });
  const items = [];
  for (const [i, [sel, side]] of marks.entries()) items.push({ selector: await mark(sel(page), `m${i}`), n: i + 1, side });
  await callout(page, items);
  await shot(page, `${G}/${name}`);
}

async function finance(browser) {
  const { ctx, page } = await admin(browser);

  // Expenses
  await listShot(page, '/agent/expenses.php', '18-01-expenses-list', ['Category / Description'], [
    [(p) => p.locator('input[name=q]'), 'tl'],
    [(p) => p.locator('button[data-bs-target="#advancedFilter"]'), 'tl'],
    [(p) => p.locator('.card-tools .btn-group'), 'tr'],
    [(p) => p.locator('table tbody tr').first().locator('.bulk-select'), 'tl'],
    [(p) => p.locator('table thead th', { hasText: 'Vendor' }), 'tl'],
    [(p) => p.locator('table tbody tr').first().locator('button[data-bs-toggle=dropdown]'), 'tr'],
  ]);

  // Filters expanded
  await clearCallouts(page);
  await page.locator('button[data-bs-target="#advancedFilter"]').click();
  await page.waitForTimeout(600);
  await callout(page, [
    { selector: await mark(page.locator('#advancedFilter .form-group', { hasText: 'Date range' }), 'f1'), n: 1 },
    { selector: await mark(page.locator('#advancedFilter .form-group', { hasText: 'Vendor' }), 'f2'), n: 2 },
    { selector: await mark(page.locator('#advancedFilter .form-group', { hasText: 'Category' }), 'f3'), n: 3 },
    { selector: await mark(page.locator('#advancedFilter .form-group', { hasText: 'Account' }), 'f4'), n: 4 },
  ]);
  await shot(page, `${G}/18-02-expense-filters`);

  // Row menu
  await goto(page, '/agent/expenses.php?q=Dell+Latitude');
  const menu = await rowMenu(page, 'Dell Latitude', 'em');
  await callout(page, [{ selector: menu, n: 1 }]);
  await shot(page, `${G}/18-03-expense-row-menu`);
  await page.keyboard.press('Escape');

  // Bulk action
  await goto(page, '/agent/expenses.php');
  await page.locator('table tbody tr .bulk-select').nth(0).check();
  await page.locator('table tbody tr .bulk-select').nth(1).check();
  await page.waitForTimeout(300);
  await page.locator('#bulkActionButton button.dropdown-toggle').click();
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: await mark(page.locator('table tbody tr .bulk-select').nth(0), 'c1'), n: 1 },
    { selector: await mark(page.locator('#bulkActionButton button.dropdown-toggle'), 'bulk'), n: 2 },
    { selector: await mark(page.locator('#bulkActionButton .dropdown-menu'), 'bm'), n: 3, side: 'br' },
  ]);
  await shot(page, `${G}/18-04-expense-bulk-actions`);

  // New expense modal
  await goto(page, '/agent/expenses.php');
  await openModal(page, page.locator('[data-modal-url*="expense_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=date]'), 'a1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'a2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show select[name=account]'), 'a3'), n: 3 },
    { selector: await mark(fg(page, '.modal.show select[name=vendor]'), 'a4'), n: 4 },
    { selector: await mark(fg(page, '.modal.show select[name=category]'), 'a5'), n: 5 },
    { selector: await mark(fg(page, '.modal.show select[name=client_id]'), 'a6'), n: 6 },
    { selector: await mark(fg(page, '.modal.show input[name=file]'), 'a7'), n: 7 },
  ]);
  await shot(page, `${G}/18-05-expense-add-modal`, { fullPage: true });
  await closeModal(page);

  // Refund modal
  await goto(page, '/agent/expenses.php?q=Wireless+access+points');
  const row = page.locator('table tbody tr', { hasText: 'Wireless access points' }).first();
  await row.locator('button[data-bs-toggle=dropdown]').click();
  await page.waitForTimeout(300);
  await openModal(page, page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'Refund' }));
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'r1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show textarea[name=description]'), 'r2'), n: 2 },
  ]);
  await shot(page, `${G}/18-06-expense-refund-modal`, { fullPage: true });
  await closeModal(page);

  // Recurring expenses
  await listShot(page, '/agent/recurring_expenses.php', '18-07-recurring-expenses', ['Frequency'], [
    [(p) => p.locator('.card-tools .btn', { hasText: 'Recurring' }).first(), 'tr'],
    [(p) => p.locator('table thead th', { hasText: 'Next Date' }), 'tl'],
    [(p) => p.locator('table thead th', { hasText: 'Frequency' }), 'tl'],
    [(p) => p.locator('table thead th', { hasText: 'Last Billed' }), 'tl'],
  ]);
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="recurring_expense_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show select[name=frequency]'), 'b1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show select[name=month]'), 'b2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show input[name=day]'), 'b3'), n: 3 },
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'b4'), n: 4 },
    { selector: await mark(fg(page, '.modal.show select[name=vendor]'), 'b5'), n: 5 },
  ]);
  await shot(page, `${G}/18-08-recurring-expense-modal`, { fullPage: true });
  await closeModal(page);

  // Revenues
  await listShot(page, '/agent/revenues.php', '18-09-revenues', ['Reference'], [
    [(p) => p.locator('.card-tools .btn', { hasText: 'New Revenue' }), 'tr'],
    [(p) => p.locator('table thead th', { hasText: 'Category' }), 'tl'],
    [(p) => p.locator('table thead th', { hasText: 'Account' }), 'tl'],
  ]);
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="revenue_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'c1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show select[name=account]'), 'c2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show select[name=category]'), 'c3'), n: 3 },
    { selector: await mark(fg(page, '.modal.show select[name=payment_method]'), 'c4'), n: 4 },
  ]);
  await shot(page, `${G}/18-10-revenue-add-modal`, { fullPage: true });
  await closeModal(page);

  // Accounts
  await goto(page, '/agent/accounts.php');
  await check(page, { rows: 4, text: ['Balance'] });
  const am = await rowMenu(page, 'Cash', 'cashmenu');
  await callout(page, [
    { selector: await mark(page.locator('.card-tools .btn', { hasText: 'New Account' }), 'na'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('table thead th', { hasText: 'Balance' }), 'bal'), n: 2 },
    { selector: am, n: 3 },
  ]);
  await shot(page, `${G}/18-11-accounts`);
  await page.keyboard.press('Escape');
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="account_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=name]'), 'd1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show input[name=opening_balance]'), 'd2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show select[name=currency_code]'), 'd3'), n: 3 },
  ]);
  await shot(page, `${G}/18-12-account-add-modal`);
  await closeModal(page);

  // Transfers
  await listShot(page, '/agent/transfers.php', '18-13-transfers', ['From Account'], [
    [(p) => p.locator('.card-tools .btn', { hasText: 'New Transfer' }), 'tr'],
    [(p) => p.locator('table thead th', { hasText: 'From Account' }), 'tl'],
    [(p) => p.locator('table thead th', { hasText: 'To Account' }), 'tl'],
  ]);
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="transfer_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'e1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show select[name=account_from]'), 'e2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show select[name=account_to]'), 'e3'), n: 3 },
  ]);
  await shot(page, `${G}/18-14-transfer-add-modal`, { fullPage: true });
  await closeModal(page);

  // Trips
  await listShot(page, '/agent/trips.php', '18-15-trips', ['Driver'], [
    [(p) => p.locator('.card-tools .btn', { hasText: 'New Trip' }), 'tr'],
    [(p) => p.locator('table thead th', { hasText: 'Miles' }), 'tl'],
    [(p) => p.locator('table tbody tr').first().locator('td').nth(5), 'tl'],
  ]);
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="trip_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=miles]'), 'g1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show input[name=source]'), 'g2'), n: 2 },
    { selector: await mark(fg(page, '.modal.show textarea[name=purpose]'), 'g3'), n: 3 },
    { selector: await mark(fg(page, '.modal.show select[name=user]'), 'g4'), n: 4 },
  ]);
  await shot(page, `${G}/18-16-trip-add-modal`, { fullPage: true });
  await closeModal(page);

  // Vendors (company-wide)
  await listShot(page, '/agent/vendors.php', '18-17-vendors', ['Vendor'], [
    [(p) => p.locator('[data-modal-url*="vendor_add.php"]').first(), 'tr'],
    [(p) => p.locator('table thead th', { hasText: 'Description' }), 'tl'],
  ]);

  // Settings > Defaults (finance defaults)
  await goto(page, '/admin/settings_default.php');
  await check(page, { text: ['Transfer From Account', 'Payment Account', 'Expense Account'] });
  await callout(page, [
    { selector: await mark(fg(page, 'select[name=transfer_from_account]'), 's1'), n: 1 },
    { selector: await mark(fg(page, 'select[name=transfer_to_account]'), 's2'), n: 2 },
    { selector: await mark(fg(page, 'select[name=payment_account]'), 's3'), n: 3 },
    { selector: await mark(fg(page, 'select[name=expense_account]'), 's4'), n: 4 },
  ]);
  await shot(page, `${G}/18-18-settings-defaults`, { fullPage: true });
  await ctx.close();
}


async function periodUrl(page, text) {
  await goto(page, '/admin/payroll_periods.php');
  const row = page.locator('table tbody tr', { hasText: text }).first();
  if (!(await row.count())) fail(`pay period ${text} not found`);
  return row.locator('a[href^="payroll_period.php"]').getAttribute('href');
}

async function payroll(browser) {
  const { ctx, page } = await admin(browser, 'admin', 1800);

  // Turn it on: Settings > Modules
  await goto(page, '/admin/settings_module.php');
  await check(page, { text: ['Show Payroll'] });
  await callout(page, [{ selector: await mark(page.locator('input[name=config_module_enable_payroll]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'pm'), n: 1 }]);
  await shot(page, `${G}/19-01-modules-show-payroll`, { fullPage: true });

  // Settings
  await goto(page, '/admin/payroll_settings.php');
  await check(page, { text: ['gross pay only', 'Overtime threshold'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-info').first(), 'note'), n: 1 },
    { selector: await mark(fg(page, 'input[name=overtime_threshold_hours]'), 'p1'), n: 2 },
    { selector: await mark(fg(page, 'input[name=overtime_multiplier]'), 'p2'), n: 3 },
    { selector: await mark(fg(page, 'select[name=default_pay_frequency]'), 'p3'), n: 4 },
  ]);
  await shot(page, `${G}/19-02-payroll-settings`, { fullPage: true });

  // Employees
  await goto(page, '/admin/payroll_employees.php');
  await check(page, { rows: 4, text: ['Payroll Employees', 'Hourly'] });
  await callout(page, [
    { selector: await mark(page.locator('.dropdown-menu.show, #nav-group-payroll').first(), 'sb'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('table thead th', { hasText: 'Status' }), 'st'), n: 2 },
    { selector: await mark(page.locator('table tbody tr').first().locator('a.btn'), 'edit'), n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/19-03-payroll-employees`);

  // One employee: pay rate and standing deductions
  const devon = await page.locator('table tbody tr', { hasText: 'Devon Park' }).locator('a.btn').getAttribute('href');
  await goto(page, `/admin/${devon}`);
  await check(page, { text: ['Pay Rate', 'Standing Deductions'] });
  await callout(page, [
    { selector: await mark(fg(page, 'select[name=pay_type]'), 'e1'), n: 1 },
    { selector: await mark(fg(page, 'input[name=pay_amount]'), 'e2'), n: 2 },
    { selector: await mark(page.locator('a.btn', { hasText: 'Remove from Payroll' }), 'e3'), n: 3, side: 'tr' },
    { selector: await mark(page.locator('.card', { hasText: 'Standing Deductions' }).last(), 'e4'), n: 4 },
  ]);
  await shot(page, `${G}/19-04-employee-pay-rate`, { fullPage: true });
  await clearCallouts(page);
  await openModal(page, page.locator('[data-modal-url*="payroll_employee_deduction_add"]').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show select[name=category_id]'), 'f1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show input[name=amount]'), 'f2'), n: 2 },
  ]);
  await shot(page, `${G}/19-05-add-standing-deduction`);
  await closeModal(page);

  // Deduction categories
  await goto(page, '/admin/payroll_deductions.php');
  await check(page, { rows: 2, text: ['Deduction Categories'] });
  await callout(page, [
    { selector: await mark(page.locator('.card-tools .btn'), 'nd'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('table tbody tr').first(), 'row'), n: 2 },
  ]);
  await shot(page, `${G}/19-06-deduction-categories`);

  // Periods
  await goto(page, '/admin/payroll_periods.php');
  await check(page, { rows: 3, text: ['Pay Periods', 'Locked'] });
  await callout(page, [
    { selector: await mark(page.locator('.card-tools .btn'), 'np'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('table thead th', { hasText: 'Status' }), 'ps'), n: 2 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Payroll Run' }), 'pr'), n: 3 },
    { selector: await mark(page.locator('table tbody tr').first().locator('a.btn'), 'po'), n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/19-07-pay-periods`);
  await clearCallouts(page);
  await openModal(page, page.locator('.card-tools .btn').first());
  await callout(page, [
    { selector: await mark(fg(page, '.modal.show input[name=start_date]'), 'g1'), n: 1 },
    { selector: await mark(fg(page, '.modal.show input[name=end_date]'), 'g2'), n: 2 },
  ]);
  await shot(page, `${G}/19-08-new-pay-period`);
  await closeModal(page);

  // Hours for the second period (draft run, job hours)
  const p2 = await periodUrl(page, 'Oct 12, 2026');
  await goto(page, `/admin/${p2}`);
  await check(page, { text: ['Pay Period', 'Job-Specific Hours', 'View Payroll Run'] });
  await callout(page, [
    { selector: await mark(page.locator('table').first().locator('thead th').nth(1), 'w1'), n: 1 },
    { selector: await mark(page.locator('table').first().locator('tbody tr').first().locator('td').nth(1), 'w2'), n: 2 },
    { selector: await mark(page.locator('table tbody tr', { hasText: 'Morgan Reyes' }).first().locator('td').nth(1), 'w3'), n: 3 },
    { selector: await mark(page.locator('button[name=save_payroll_hours]'), 'w4'), n: 4 },
    { selector: await mark(page.locator('a.btn-success', { hasText: 'View Payroll Run' }), 'w5'), n: 5 },
  ]);
  await shot(page, `${G}/19-09-period-hours`, { fullPage: true });

  // Job hours pop-up
  await clearCallouts(page);
  if (await page.locator('[data-modal-url*="payroll_job_hours_add"]').count()) {
    await openModal(page, page.locator('[data-modal-url*="payroll_job_hours_add"]').first());
    await callout(page, [
      { selector: await mark(fg(page, '.modal.show select[name=ticket_id]'), 'j1'), n: 1 },
      { selector: await mark(page.locator('.modal.show input[name=hours_h]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'j2'), n: 2 },
      { selector: await mark(fg(page, '.modal.show input[name=rate]'), 'j3'), n: 3 },
    ]);
    await shot(page, `${G}/19-10-job-hours-modal`, { fullPage: true });
    await closeModal(page);
  }

  // The open period without a run yet
  const p3 = await periodUrl(page, 'Oct 26, 2026');
  await goto(page, `/admin/${p3}`);
  await check(page, { text: ['Run Payroll'] });
  await callout(page, [
    { selector: await mark(page.locator('button[name=run_payroll]'), 'rp'), n: 1, side: 'tr' },
    { selector: await mark(page.locator('button[name=save_payroll_hours]'), 'sh'), n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/19-11-period-run-payroll`, { fullPage: true });

  // Runs list, draft run, finalized run
  await goto(page, '/admin/payroll_runs.php');
  await check(page, { rows: 2, text: ['Payroll Runs'] });
  await callout(page, [
    { selector: await mark(page.locator('table thead th', { hasText: 'Gross' }), 'rg'), n: 1 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Net' }), 'rn'), n: 2 },
    { selector: await mark(page.locator('table tbody tr .badge', { hasText: 'Draft' }).first(), 'rd'), n: 3 },
  ]);
  await shot(page, `${G}/19-12-payroll-runs`);
  const draft = await page.locator('table tbody tr', { hasText: 'Draft' }).locator('a.btn').first().getAttribute('href');
  const fin = await page.locator('table tbody tr', { hasText: 'Finalized' }).locator('a.btn').first().getAttribute('href');

  await goto(page, `/admin/${draft}`);
  await check(page, { rows: 3, text: ['Recalculate', 'Finalize', 'Totals'] });
  await callout(page, [
    { selector: await mark(page.locator('table thead th', { hasText: 'OT Hours' }), 'd1'), n: 1 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Job Pay' }), 'd2'), n: 2 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Gross' }), 'd3'), n: 3 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Net' }), 'd4'), n: 4 },
    { selector: await mark(page.locator('table tbody tr').first().locator('a.btn'), 'd5'), n: 5, side: 'tr' },
    { selector: await mark(page.locator('button[name=recalculate_payroll_run]'), 'd6'), n: 6 },
    { selector: await mark(page.locator('button[name=finalize_payroll_run]'), 'd7'), n: 7 },
  ]);
  await shot(page, `${G}/19-13-payroll-run-draft`, { fullPage: true });

  await clearCallouts(page);
  await openModal(page, page.locator('table tbody tr', { hasText: 'Sam Ortiz' }).locator('a.ajax-modal').first());
  await shot(page, `${G}/19-14-run-edit-hours`);
  await closeModal(page);

  await goto(page, `/admin/${fin}`);
  await check(page, { rows: 3, text: ['Finalized'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-success'), 'f1'), n: 1 },
    { selector: await mark(page.locator('table tfoot tr'), 'f2'), n: 2 },
  ]);
  await shot(page, `${G}/19-15-payroll-run-finalized`, { fullPage: true });
  await ctx.close();
}


async function accounting(browser) {
  const { ctx, page } = await admin(browser, 'admin', 1500);

  // Settings directory: where the tiles are
  await goto(page, '/admin/settings.php');
  await check(page, { text: ['Accounting', 'Payroll'] });
  await callout(page, [
    { selector: await mark(page.locator('a[href="/admin/settings_accounting.php"]').first(), 't1'), n: 1 },
    { selector: await mark(page.locator('a[href="/admin/payroll_settings.php"]').first(), 't2'), n: 2 },
    { selector: await mark(page.locator('a[href="/admin/settings_integrations.php"]').first(), 't3'), n: 3 },
  ]);
  await shot(page, `${G}/20-01-settings-directory`, { fullPage: true });

  // QuickBooks Online: Connection tab
  await goto(page, '/admin/settings_accounting.php');
  await check(page, { text: ['Connection Status', 'App Credentials'] });
  await callout(page, [
    { selector: await mark(page.locator('#accountingTabs'), 'tabs'), n: 1 },
    { selector: await mark(page.locator('.badge', { hasText: 'Not Connected' }).first(), 'badge'), n: 2, side: 'tr' },
    { selector: await mark(page.locator('a, button', { hasText: 'Connect to' }).first(), 'conn'), n: 3 },
    { selector: await mark(fg(page, 'select[name=accounting_provider]'), 'prov'), n: 4 },
  ]);
  await shot(page, `${G}/20-02-accounting-connection`);
  await clearCallouts(page);
  await page.evaluate(() => window.scrollTo(0, 600));
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: await mark(fg(page, 'input[name=accounting_client_id]'), 'c1'), n: 1 },
    { selector: await mark(fg(page, 'input[name=accounting_client_secret]'), 'c2'), n: 2 },
    { selector: await mark(fg(page, 'select[name=accounting_environment]'), 'c3'), n: 3 },
    { selector: await mark(page.locator('#accAutoPush').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'c4'), n: 4 },
    { selector: await mark(page.locator('#accEnabled').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'c5'), n: 5 },
  ]);
  await shot(page, `${G}/20-03-accounting-credentials`);

  // Activity log
  await clearCallouts(page);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(400);
  await shot(page, `${G}/20-04-accounting-activity`);

  // Client mapping
  await goto(page, '/admin/settings_accounting.php?tab=client_mapping');
  await check(page, { text: ['Client', 'QuickBooks Customer', 'clients mapped'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-warning').first(), 'm1'), n: 1 },
    { selector: await mark(page.locator('.badge', { hasText: 'clients mapped' }), 'm2'), n: 2, side: 'tr' },
    { selector: await mark(page.locator('#tab-client_mapping table tbody tr', { hasText: 'Mapped' }).first(), 'm3'), n: 3 },
    { selector: await mark(page.locator('#tab-client_mapping table tbody tr', { hasText: 'Not mapped' }).first(), 'm4'), n: 4 },
  ]);
  await shot(page, `${G}/20-05-accounting-client-mapping`, { fullPage: true });

  // Item mapping
  await goto(page, '/admin/settings_accounting.php?tab=item_mapping');
  await check(page, { text: ['Product / Service'] });
  await callout(page, [
    { selector: await mark(page.locator('h3.card-title', { hasText: 'Product / Service' }).locator('xpath=ancestor::div[contains(@class,"card-header")]'), 'i1'), n: 1 },
  ]);
  await shot(page, `${G}/20-06-accounting-item-mapping`, { fullPage: true });

  // Sync status
  await goto(page, '/admin/settings_accounting.php?tab=sync_status');
  await check(page, { text: ['Recent Invoices', 'Pending', 'Failed'] });
  await callout(page, [
    { selector: await mark(page.locator('#tab-sync_status .badge', { hasText: 'Pending' }).first().locator('xpath=..'), 'q1'), n: 1 },
    { selector: await mark(page.locator('#assRunBtn'), 'q2'), n: 2, side: 'tr' },
    { selector: await mark(page.locator('#tab-sync_status table').first(), 'q3'), n: 3 },
  ]);
  await shot(page, `${G}/20-07-accounting-sync-status`);

  // Pax8: Integrations tab link, Connection, Client Mapping, Reconciliation, Billing Rules
  await goto(page, '/admin/settings_integrations.php');
  await check(page, { text: ['Pax8'] });
  await callout(page, [{ selector: await mark(page.locator('a.nav-link', { hasText: 'Pax8' }), 'px'), n: 1 }]);
  await shot(page, `${G}/20-08-integrations-pax8-tab`);

  await goto(page, '/admin/settings_pax8.php');
  await check(page, { text: ['Pax8 API Client ID', 'Automated Billing'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-info').first(), 'x1'), n: 1 },
    { selector: await mark(page.locator('input[name=pax8_client_id]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'x2'), n: 2 },
    { selector: await mark(page.locator('#pax8Enabled').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'x3'), n: 3 },
    { selector: await mark(page.locator('#pax8BillingSyncEnabled').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'x4'), n: 4 },
    { selector: await mark(page.locator('.nav-tabs'), 'x5'), n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/20-09-pax8-connection`, { fullPage: true });

  await goto(page, '/admin/settings_pax8.php?tab=client_mapping');
  await check(page, { text: ['Pax8 Company', 'Mapped Client'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-secondary').first(), 'y1'), n: 1 },
    { selector: await mark(page.locator('table tbody tr').first().locator('select'), 'y2'), n: 2 },
    { selector: await mark(page.locator('table tbody tr').first().locator('.form-switch'), 'y3'), n: 3 },
    { selector: await mark(page.locator('table tbody tr').first().locator('td').nth(2), 'y4'), n: 4 },
  ]);
  await shot(page, `${G}/20-10-pax8-client-mapping`, { fullPage: true });

  await goto(page, '/admin/settings_pax8.php?tab=reconciliation');
  await check(page, { text: ['Pax8 Cost', 'Margin'] });
  await callout(page, [
    { selector: await mark(page.locator('table thead th', { hasText: 'Pax8 Cost' }), 'z1'), n: 1 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Billed' }), 'z2'), n: 2 },
    { selector: await mark(page.locator('table thead th', { hasText: 'Margin %' }), 'z3'), n: 3 },
  ]);
  await shot(page, `${G}/20-11-pax8-reconciliation`, { fullPage: true });

  await goto(page, '/admin/settings_pax8.php?tab=billing_rules');
  await check(page, { text: ['Pax8 Product', 'Markup'] });
  await callout(page, [
    { selector: await mark(page.locator('.alert-secondary').first(), 'w1'), n: 1 },
    { selector: await mark(page.locator('select[name=pricing_mode]').first(), 'w2'), n: 2 },
    { selector: await mark(page.locator('input[name=sell_price]').first(), 'w3'), n: 3 },
    { selector: await mark(page.locator('input[name=markup_percent]').first(), 'w4'), n: 4 },
    { selector: await mark(page.locator('input[name=rule_enabled]').first().locator('xpath=ancestor::div[contains(@class,"form-switch")][1]'), 'w5'), n: 5 },
  ]);
  await shot(page, `${G}/20-12-pax8-billing-rules`, { fullPage: true });
  await ctx.close();
}

// ---- PARTS_PLACEHOLDER ----

(async () => {
  const part = process.argv[2];
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  try {
    const run = { payments, finance: typeof finance === 'function' ? finance : null, payroll: typeof payroll === 'function' ? payroll : null, accounting: typeof accounting === 'function' ? accounting : null };
    for (const k of Object.keys(run)) if (run[k] && (!part || part === k)) await run[k](browser);
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e.message); process.exit(1); });
