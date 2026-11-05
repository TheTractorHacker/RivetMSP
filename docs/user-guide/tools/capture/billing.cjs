// Screenshots for docs/user-guide/15-products-quotes-invoices.md and 16-contracts-and-recurring-invoices.md
// (group "billing"). Needs seeds 00-core, 01-modules-and-permissions and 50 to 54 (billing catalog, contracts,
// recurring invoices, quotes, invoices) applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/billing.cjs
//   GUIDE_CLEAN=1 ...    writes call-out-free copies under images-clean/
//   ONLY=quotes,guest ...  runs just those sections (products, quotes, invoices, guest, settings, contracts, recurring)
//
// Read-only with respect to app data: pop-ups are opened and filled with illustrative values but never saved.
// The one exception is the guest links: opening a guest quote or invoice marks a Sent document as Viewed, as it would for a
// real client, so those are opened on documents that are already Viewed (see the sections below).

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');
const G = 'billing';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const W = 1840;
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);
const want = (s) => !ONLY.length || ONLY.includes(s);

function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
async function check(page, { rows = 0, text = [] } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access|There is no (Quote|Invoice)/.test(body)) fail(`unexpected page on ${page.url()}`);
  if (rows && (await page.locator('table tbody tr').count()) < rows) fail(`too few rows on ${page.url()}`);
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}
async function mark(locator, id) {
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}
// Mark the whole input group (icon + field, including the select box that replaces a <select>) that holds a field.
async function markGroup(page, fieldSel, id) {
  await page.locator(fieldSel).first().evaluate((el, v) => { (el.closest('.input-group') || el.parentElement).setAttribute('data-ug', v); }, id);
  return `[data-ug="${id}"]`;
}
// Choose an option in a searchable select box (the app replaces <select> with a Tom Select control).
async function pickTom(page, selectSel, label) {
  await page.click(`${selectSel} + .ts-wrapper .ts-control`);
  await page.locator('.ts-dropdown .option', { hasText: label }).first().click();
  await page.waitForTimeout(300);
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
// First link on a list page whose text matches: used to find a document by its scope, because ids differ between builds.
async function findHref(page, listUrl, q, hrefStart) {
  await goto(page, `${listUrl}${listUrl.includes('?') ? '&' : '?'}q=${encodeURIComponent(q)}`);
  const link = page.locator(`table tbody tr a[href^="${hrefStart}"]`).first();
  if (!(await link.count())) fail(`"${q}" not found on ${listUrl}`);
  return new URL(await link.getAttribute('href'), `${BASE}/agent/`).toString();
}
// Same, but only a row that shows the given status badge (a client can have several invoices with the same scope).
async function openDocWithStatus(page, listUrl, q, status, hrefStart) {
  await goto(page, `${listUrl}${listUrl.includes('?') ? '&' : '?'}q=${encodeURIComponent(q)}`);
  const row = page.locator('table tbody tr', { hasText: status }).first();
  if (!(await row.count())) fail(`no ${status} row for "${q}"`);
  const url = new URL(await row.locator(`a[href^="${hrefStart}"]`).first().getAttribute('href'), `${BASE}/agent/`).toString();
  await goto(page, url);
  return url;
}
async function openDoc(page, listUrl, q, hrefStart) {
  const url = await findHref(page, listUrl, q, hrefStart);
  await goto(page, url);
  return url;
}
// Close every dropdown so a later call-out is not drawn over it.
async function closeMenus(page) {
  await page.mouse.click(5, 400);
  await page.waitForTimeout(200);
}
async function clientId(page, name) {
  await goto(page, `/agent/clients.php?q=${encodeURIComponent(name)}`);
  const href = await page.locator('table tbody tr a[href^="client_overview.php"]').first().getAttribute('href');
  if (!href) fail(`client ${name} not found`);
  return new URL(href, 'http://x/agent/').searchParams.get('client_id');
}

(async () => {
  const { browser, page } = await launch({ width: W, height: 1000 });
  await login(page, 'admin');

  // ------------------------------------------------------------------------------------------- products
  if (want('products')) {
    await goto(page, '/agent/products.php');
    await check(page, { rows: 5, text: ['Managed Workstation'] });
    const tabs = await mark(page.locator('a[href="?type=service"]'), 'p-service');
    await mark(page.locator('a[href="?type=product"]'), 'p-product');
    await mark(page.locator('a:has-text("Archived")').first(), 'p-archived');
    await mark(page.locator('select[name=category]'), 'p-cat');
    await mark(page.locator('button.ajax-modal:has-text("Service")').first(), 'p-new');
    await mark(page.locator('table thead th:has-text("Price")'), 'p-price');
    await mark(page.locator('table thead th:has-text("Tax Rate")'), 'p-tax');
    await callout(page, [
      { selector: '[data-ug="p-new"]', n: 1, side: 'tl' },
      { selector: '[data-ug="p-cat"]', n: 2, side: 'tl' },
      { selector: '[data-ug="p-service"]', n: 3, side: 'tl' },
      { selector: '[data-ug="p-archived"]', n: 4, side: 'tr' },
      { selector: '[data-ug="p-tax"]', n: 5, side: 'tl' },
      { selector: '[data-ug="p-price"]', n: 6, side: 'tr' },
    ]);
    await shot(page, `${G}/01-products-services`);
    await clearCallouts(page);

    // Products (stocked) with the row menu open
    await goto(page, '/agent/products.php?type=product');
    await check(page, { rows: 5, text: ['Business Laptop 14 in'] });
    const qty = await mark(page.locator('table thead th:has-text("QTY")'), 'p-qty');
    await mark(page.locator('table thead th:has-text("Location")'), 'p-loc');
    await page.locator('table tbody tr').first().locator('button.dropdown-toggle, button[data-bs-toggle=dropdown]').click();
    await page.waitForTimeout(300);
    await mark(page.locator('.dropdown-menu.show a:has-text("Add Stock")'), 'p-stock');
    await mark(page.locator('.dropdown-menu.show'), 'p-menu');
    await callout(page, [
      { selector: '[data-ug="p-qty"]', n: 1, side: 'tl' },
      { selector: '[data-ug="p-loc"]', n: 2, side: 'tl' },
      { selector: '[data-ug="p-stock"]', n: 3, side: 'tl' },
    ]);
    await shot(page, `${G}/02-products-stock`);
    await clearCallouts(page);
    await closeMenus(page);

    // New product pop-up
    await openModal(page, page.locator('button.ajax-modal:has-text("Product")').first());
    await page.fill('.modal.show input[name=name]', 'Docking Station (USB-C)');
    await page.fill('.modal.show input[name=price]', '189.00');
    await page.fill('.modal.show input[name=location]', 'Shelf A3');
    await page.fill('.modal.show input[name=code]', 'HW-DOCK');
    await page.fill('.modal.show textarea[name=description]', 'USB-C dock with dual display output and power delivery.');
    await markGroup(page, '.modal.show select[name=category]', 'm-cat');
    await mark(page.locator('.modal.show input[name=price]'), 'm-price');
    await markGroup(page, '.modal.show select[name=tax]', 'm-tax');
    await mark(page.locator('.modal.show input[name=location]'), 'm-loc');
    await mark(page.locator('.modal.show input[name=code]'), 'm-code');
    await callout(page, [
      { selector: '[data-ug="m-cat"]', n: 1, side: 'tl' },
      { selector: '[data-ug="m-price"]', n: 2, side: 'tl' },
      { selector: '[data-ug="m-tax"]', n: 3, side: 'tr' },
      { selector: '[data-ug="m-loc"]', n: 4, side: 'tl' },
      { selector: '[data-ug="m-code"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/03-product-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // Add stock pop-up
    await page.locator('table tbody tr').first().locator('button[data-bs-toggle=dropdown]').click();
    await openModal(page, page.locator('.dropdown-menu.show a:has-text("Add Stock")'));
    await page.fill('.modal.show input[name=qty]', '5');
    await page.fill('.modal.show textarea[name=note]', 'Delivery from the distributor, PO 2291');
    await mark(page.locator('.modal.show input[name=qty]'), 's-qty');
    await markGroup(page, '.modal.show select[name=expense]', 's-exp');
    await callout(page, [
      { selector: '[data-ug="s-qty"]', n: 1, side: 'tl' },
      { selector: '[data-ug="s-exp"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/04-product-add-stock`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
  }


  // ------------------------------------------------------------------------------------------- quotes
  if (want('quotes')) {
    // List with the date filter open
    await goto(page, '/agent/quotes.php');
    await check(page, { rows: 6, text: ['QUO-1', 'Accepted', 'Declined'] });
    await page.locator('button[data-bs-target="#advancedFilter"]').click();
    await page.waitForTimeout(500);
    await mark(page.locator('button.ajax-modal:has-text("New Quote")'), 'q-new');
    await mark(page.locator('button[data-bs-target="#advancedFilter"]'), 'q-filter');
    await mark(page.locator('table thead th:has-text("Expire")'), 'q-expire');
    await mark(page.locator('table thead th:has-text("Status")'), 'q-status');
    await mark(page.locator('table tbody tr:has-text("QUO-4") td:nth-child(1) a'), 'q-number');
    await mark(page.locator('table tbody tr:has-text("QUO-4") .badge'), 'q-badge');
    await mark(page.locator('table tbody tr:has-text("QUO-4") button[data-bs-toggle=dropdown]'), 'q-action');
    await callout(page, [
      { selector: '[data-ug="q-new"]', n: 1, side: 'tl' },
      { selector: '[data-ug="q-filter"]', n: 2, side: 'tl' },
      { selector: '[data-ug="q-number"]', n: 3, side: 'tl' },
      { selector: '[data-ug="q-expire"]', n: 4, side: 'tl' },
      { selector: '[data-ug="q-badge"]', n: 5, side: 'tr' },
      { selector: '[data-ug="q-action"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/05-quotes-list`);
    await clearCallouts(page);

    // New quote pop-up
    await openModal(page, page.locator('button.ajax-modal:has-text("New Quote")'));
    await page.fill('.modal.show input[name=scope]', 'Server room cooling and power check');
    await markGroup(page, '.modal.show input[name=scope]', 'n-scope');
    await markGroup(page, '.modal.show select[name=client_id]', 'n-client');
    await markGroup(page, '.modal.show select[name=category]', 'n-cat');
    await markGroup(page, '.modal.show input[name=date]', 'n-date');
    await markGroup(page, '.modal.show input[name=expire]', 'n-exp');
    await callout(page, [
      { selector: '[data-ug="n-scope"]', n: 1, side: 'tl' },
      { selector: '[data-ug="n-client"]', n: 2, side: 'tl' },
      { selector: '[data-ug="n-cat"]', n: 3, side: 'tl' },
      { selector: '[data-ug="n-date"]', n: 4, side: 'tl' },
      { selector: '[data-ug="n-exp"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/06-quote-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // A Draft quote: Send menu open
    await openDoc(page, '/agent/quotes.php', 'Replace reception workstations', 'quote.php');
    await check(page, { text: ['Draft', 'Business Laptop 14 in'] });
    await page.locator('button:has-text("Send")').first().click();
    await page.waitForTimeout(300);
    await mark(page.locator('button.dropdown-toggle:has-text("Send")'), 'd-send');
    await mark(page.locator('.dropdown-menu.show a:has-text("Mark Sent")'), 'd-marksent');
    await mark(page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)'), 'd-more');
    await mark(page.locator('h5.badge'), 'd-badge');
    await mark(page.locator('input[name=name]#name'), 'd-item');
    await mark(page.locator('button[name=add_quote_item]'), 'd-add');
    await mark(page.locator('.card-header:has-text("Notes:")'), 'd-notes');
    await mark(page.locator('td:has-text("Total:")').first().locator('xpath=..'), 'd-total');
    await mark(page.locator('th:has-text("Expires:")').locator('xpath=..'), 'd-expires');
    await callout(page, [
      { selector: '[data-ug="d-send"]', n: 1, side: 'tl' },
      { selector: '[data-ug="d-more"]', n: 2, side: 'tr' },
      { selector: '[data-ug="d-badge"]', n: 3, side: 'tl' },
      { selector: '[data-ug="d-expires"]', n: 4, side: 'tl' },
      { selector: '[data-ug="d-item"]', n: 5, side: 'tl' },
      { selector: '[data-ug="d-add"]', n: 6, side: 'tr' },
      { selector: '[data-ug="d-notes"]', n: 7, side: 'tl' },
      { selector: '[data-ug="d-total"]', n: 8, side: 'tl' },
    ]);
    await shot(page, `${G}/07-quote-draft`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);

    // The product type-ahead in the Item box
    await page.locator('#name').click();
    await page.locator('#name').type('Wi', { delay: 120 });
    await page.waitForSelector('.ui-autocomplete .ui-menu-item', { timeout: 5000 });
    await page.waitForTimeout(400);
    await mark(page.locator('.ui-autocomplete'), 'a-menu');
    await mark(page.locator('#name'), 'a-name');
    await callout(page, [
      { selector: '[data-ug="a-name"]', n: 1, side: 'tl' },
      { selector: '[data-ug="a-menu"]', n: 2, side: 'tr' },
    ]);
    await page.locator('#name').scrollIntoViewIfNeeded();
    await shot(page, `${G}/08-quote-item-autocomplete`);
    await clearCallouts(page);

    // A Sent quote: Accept / Decline and the menu
    await openDoc(page, '/agent/quotes.php', 'Office Wi-Fi refresh', 'quote.php');
    await check(page, { text: ['Sent', 'discount'].slice(0, 1) });
    await page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)').click();
    await page.waitForTimeout(300);
    await mark(page.locator('a:has-text("Accept")').first(), 's-accept');
    await mark(page.locator('a:has-text("Decline")').first(), 's-decline');
    await mark(page.locator('.dropdown-menu.show a:has-text("Download PDF")'), 's-pdf');
    await mark(page.locator('.dropdown-menu.show a:has-text("Copy Guest URL")'), 's-guest');
    await mark(page.locator('.dropdown-menu.show a:has-text("Copy")').first(), 's-copy');
    await mark(page.locator('td:has-text("Discount:")').first().locator('xpath=..'), 's-discount');
    await callout(page, [
      { selector: '[data-ug="s-accept"]', n: 1, side: 'tl' },
      { selector: '[data-ug="s-decline"]', n: 2, side: 'tl' },
      { selector: '[data-ug="s-copy"]', n: 3, side: 'tl' },
      { selector: '[data-ug="s-pdf"]', n: 4, side: 'tl' },
      { selector: '[data-ug="s-guest"]', n: 5, side: 'tl' },
      { selector: '[data-ug="s-discount"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/09-quote-sent`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);

    // An Accepted quote: Invoice button and its menu, then the pop-up
    await openDoc(page, '/agent/quotes.php', 'Annual penetration test', 'quote.php');
    await check(page, { text: ['Accepted'] });
    await page.locator('.btn-group.fix-quote-dropdown .dropdown-toggle').click();
    await page.waitForTimeout(300);
    await mark(page.locator('.btn-group.fix-quote-dropdown > button.ajax-modal'), 'i-invoice');
    await mark(page.locator('.dropdown-menu.show a:has-text("Mark Invoiced")'), 'i-mark');
    await mark(page.locator('h5.badge'), 'i-badge');
    await mark(page.locator('.card:has(.fa-history) table'), 'i-history');
    await callout(page, [
      { selector: '[data-ug="i-invoice"]', n: 1, side: 'tl' },
      { selector: '[data-ug="i-mark"]', n: 2, side: 'tl' },
      { selector: '[data-ug="i-badge"]', n: 3, side: 'tl' },
      { selector: '[data-ug="i-history"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/10-quote-accepted`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);
    await openModal(page, page.locator('.btn-group.fix-quote-dropdown > button.ajax-modal'));
    await markGroup(page, '.modal.show input[name=date]', 'qi-date');
    await mark(page.locator('.modal.show button[name=add_quote_to_invoice]'), 'qi-go');
    await callout(page, [
      { selector: '[data-ug="qi-date"]', n: 1, side: 'tl' },
      { selector: '[data-ug="qi-go"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/11-quote-to-invoice`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
  }


  // ------------------------------------------------------------------------------------------- invoices
  if (want('invoices')) {
    // List with the three tiles
    await goto(page, '/agent/invoices.php');
    await check(page, { rows: 8, text: ['Draft', 'Unpaid', 'Overdue'] });
    await mark(page.locator('a.small-box.bg-secondary'), 'l-draft');
    await mark(page.locator('a.small-box.bg-info'), 'l-unpaid');
    await mark(page.locator('a.small-box.bg-danger'), 'l-over');
    await mark(page.locator('button.ajax-modal:has-text("New Invoice")'), 'l-new');
    await mark(page.locator('table thead th:has-text("Due")'), 'l-due');
    await mark(page.locator('table thead th:has-text("Recurring")'), 'l-rec');
    await mark(page.locator('table thead th:has-text("Status")'), 'l-status');
    await mark(page.locator('table thead td.checkbox-column'), 'l-check');
    await callout(page, [
      { selector: '[data-ug="l-draft"]', n: 1, side: 'tl' },
      { selector: '[data-ug="l-unpaid"]', n: 2, side: 'tl' },
      { selector: '[data-ug="l-over"]', n: 3, side: 'tl' },
      { selector: '[data-ug="l-new"]', n: 4, side: 'tl' },
      { selector: '[data-ug="l-check"]', n: 5, side: 'tl' },
      { selector: '[data-ug="l-due"]', n: 6, side: 'tl' },
      { selector: '[data-ug="l-status"]', n: 7, side: 'tl' },
      { selector: '[data-ug="l-rec"]', n: 8, side: 'tl' },
    ]);
    await shot(page, `${G}/12-invoices-list`);
    await clearCallouts(page);

    // Overdue tile filter with the Action menu open on an overdue row
    await goto(page, '/agent/invoices.php?status=Overdue');
    await check(page, { rows: 3 });
    await page.locator('table tbody tr').first().locator('button[data-bs-toggle=dropdown]').click();
    await page.waitForTimeout(300);
    await mark(page.locator('.dropdown-menu.show a:has-text("Add Payment")'), 'o-pay');
    await mark(page.locator('.dropdown-menu.show a:has-text("Copy")'), 'o-copy');
    await mark(page.locator('.dropdown-menu.show a:has-text("Delete")'), 'o-del');
    await mark(page.locator('table tbody tr td.text-danger').first(), 'o-due');
    await callout(page, [
      { selector: '[data-ug="o-due"]', n: 1, side: 'tl' },
      { selector: '[data-ug="o-pay"]', n: 2, side: 'tl' },
      { selector: '[data-ug="o-copy"]', n: 3, side: 'tl' },
      { selector: '[data-ug="o-del"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/13-invoices-overdue-menu`);
    await clearCallouts(page);
    await closeMenus(page);

    // New invoice pop-up (from the list)
    await goto(page, '/agent/invoices.php');
    await openModal(page, page.locator('button.ajax-modal:has-text("New Invoice")'));
    await page.fill('.modal.show input[name=scope]', 'Quarterly on-site visit');
    await markGroup(page, '.modal.show input[name=scope]', 'n-scope');
    await markGroup(page, '.modal.show select[name=client_id]', 'n-client');
    await markGroup(page, '.modal.show select[name=category]', 'n-cat');
    await markGroup(page, '.modal.show input[name=date]', 'n-date');
    await markGroup(page, '.modal.show input[name=invoice_discount]', 'n-disc');
    await callout(page, [
      { selector: '[data-ug="n-scope"]', n: 1, side: 'tl' },
      { selector: '[data-ug="n-client"]', n: 2, side: 'tl' },
      { selector: '[data-ug="n-cat"]', n: 3, side: 'tl' },
      { selector: '[data-ug="n-date"]', n: 4, side: 'tl' },
      { selector: '[data-ug="n-disc"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/14-invoice-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // A Draft invoice with the Send menu open
    await openDoc(page, '/agent/invoices.php', 'Conference room display install', 'invoice.php');
    await check(page, { text: ['Draft', 'Project Labor (per hour)'] });
    await page.locator('button.dropdown-toggle:has-text("Send")').click();
    await page.waitForTimeout(300);
    await mark(page.locator('button.dropdown-toggle:has-text("Send")'), 'd-send');
    await mark(page.locator('.dropdown-menu.show a:has-text("Mark Sent")'), 'd-marksent');
    await mark(page.locator('h5.badge'), 'd-badge');
    await mark(page.locator('th:has-text("Due:")').locator('xpath=..'), 'd-due');
    await mark(page.locator('#name'), 'd-item');
    await mark(page.locator('button[name=add_invoice_item]'), 'd-add');
    await mark(page.locator('td:has-text("Balance:")').locator('xpath=..'), 'd-balance');
    await callout(page, [
      { selector: '[data-ug="d-send"]', n: 1, side: 'tl' },
      { selector: '[data-ug="d-badge"]', n: 2, side: 'tl' },
      { selector: '[data-ug="d-due"]', n: 3, side: 'tl' },
      { selector: '[data-ug="d-item"]', n: 4, side: 'tl' },
      { selector: '[data-ug="d-add"]', n: 5, side: 'tr' },
      { selector: '[data-ug="d-balance"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/15-invoice-draft`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);

    // A Sent invoice: Add Payment and the actions menu
    await openDocWithStatus(page, '/agent/invoices.php', 'Managed services and plant network - monthly', 'Sent', 'invoice.php');
    // the newest Granite Peak invoice is the Sent one
    await check(page, { text: ['Granite Peak Manufacturing'] });
    await page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)').click();
    await page.waitForTimeout(300);
    await mark(page.locator('button.btn-success:has-text("Add Payment")'), 'm-pay');
    await mark(page.locator('.dropdown-menu.show a:has-text("Copy")').first(), 'm-copy');
    await mark(page.locator('.dropdown-menu.show a:has-text("Recurring")'), 'm-rec');
    await mark(page.locator('.dropdown-menu.show a:has-text("Download PDF")'), 'm-pdf');
    await mark(page.locator('.dropdown-menu.show a:has-text("Packing Slip")'), 'm-slip');
    await mark(page.locator('.dropdown-menu.show a:has-text("Copy Guest URL")'), 'm-guest');
    await mark(page.locator('.dropdown-menu.show a:has-text("Cancel")'), 'm-cancel');
    await callout(page, [
      { selector: '[data-ug="m-pay"]', n: 1, side: 'tl' },
      { selector: '[data-ug="m-copy"]', n: 2, side: 'tl' },
      { selector: '[data-ug="m-rec"]', n: 3, side: 'tl' },
      { selector: '[data-ug="m-pdf"]', n: 4, side: 'tl' },
      { selector: '[data-ug="m-slip"]', n: 5, side: 'tl' },
      { selector: '[data-ug="m-guest"]', n: 6, side: 'tl' },
      { selector: '[data-ug="m-cancel"]', n: 7, side: 'tl' },
    ]);
    await shot(page, `${G}/16-invoice-sent-menu`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);

    // Add payment pop-up
    await openModal(page, page.locator('button.btn-success:has-text("Add Payment")'));
    await page.fill('.modal.show input[name=amount]', '1000.00');
    await page.fill('.modal.show input[name=reference]', 'ACH-534990');
    await markGroup(page, '.modal.show input[name=date]', 'p-date');
    await markGroup(page, '.modal.show input[name=amount]', 'p-amount');
    await markGroup(page, '.modal.show select[name=account]', 'p-acct');
    await markGroup(page, '.modal.show select[name=payment_method]', 'p-method');
    await markGroup(page, '.modal.show input[name=reference]', 'p-ref');
    await callout(page, [
      { selector: '[data-ug="p-date"]', n: 1, side: 'tl' },
      { selector: '[data-ug="p-amount"]', n: 2, side: 'tl' },
      { selector: '[data-ug="p-acct"]', n: 3, side: 'tl' },
      { selector: '[data-ug="p-method"]', n: 4, side: 'tl' },
      { selector: '[data-ug="p-ref"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/17-invoice-add-payment`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // A Partial invoice: payments and history cards
    const partialHref = await (async () => {
      await goto(page, '/agent/invoices.php?q=Managed+IT+and+compliance+reporting');
      const row = page.locator('table tbody tr', { hasText: 'Partial' }).first();
      if (!(await row.count())) fail('no Partial compliance invoice');
      return new URL(await row.locator('a[href^="invoice.php"]').first().getAttribute('href'), `${BASE}/agent/`).toString();
    })();
    await goto(page, partialHref);
    await check(page, { text: ['Partial', 'Payments'] });
    await mark(page.locator('h5.badge'), 'r-badge');
    await mark(page.locator('td:has-text("Paid:")').first().locator('xpath=..'), 'r-paid');
    await mark(page.locator('td:has-text("Balance:")').locator('xpath=..'), 'r-bal');
    await mark(page.locator('.card:has(.fa-credit-card) table').last(), 'r-pay');
    await mark(page.locator('.card:has(.fa-history) table').first(), 'r-hist');
    await callout(page, [
      { selector: '[data-ug="r-badge"]', n: 1, side: 'tl' },
      { selector: '[data-ug="r-paid"]', n: 2, side: 'tl' },
      { selector: '[data-ug="r-bal"]', n: 3, side: 'tl' },
      { selector: '[data-ug="r-pay"]', n: 4, side: 'tl' },
      { selector: '[data-ug="r-hist"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/18-invoice-partial`, { fullPage: true });
    await clearCallouts(page);

    // Overdue invoice with a late fee line
    await openDoc(page, '/agent/invoices.php', 'Remote support - printer and file server issues', 'invoice.php');
    await check(page, { text: ['Late Fee', 'Overdue'] });
    await mark(page.locator('.badge:has-text("Overdue")'), 'f-over');
    await mark(page.locator('table#items tbody tr:has-text("Late Fee")'), 'f-fee');
    await mark(page.locator('th:has-text("Due:")').locator('xpath=..'), 'f-due');
    await mark(page.locator('.card-header:has-text("Notes:")').locator('xpath=..'), 'f-notes');
    await mark(page.locator('.card:has(.fa-history) table').first(), 'f-hist');
    await callout(page, [
      { selector: '[data-ug="f-over"]', n: 1, side: 'tl' },
      { selector: '[data-ug="f-due"]', n: 2, side: 'tl' },
      { selector: '[data-ug="f-fee"]', n: 3, side: 'tl' },
      { selector: '[data-ug="f-hist"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/19-invoice-late-fee`, { fullPage: true });
    await clearCallouts(page);

    // Non-Billable and Cancelled badges, side by side in the list
    await goto(page, '/agent/invoices.php?q=goodwill');
    await check(page, { rows: 1, text: ['Non-Billable'] });
  }


  // ------------------------------------------------------------------------------------------- guest links
  // The links a client receives. Opened on documents that are already Viewed, so no status changes.
  if (want('guest')) {
    async function guestUrl(listUrl, q, status, hrefStart) {
      await openDocWithStatus(page, listUrl, q, status, hrefStart);
      const text = await page.locator('a.clipboardjs[data-clipboard-text]').first().getAttribute('data-clipboard-text');
      const u = new URL(text.replace(/^https?:\/\//, 'http://'));
      return `${BASE}${u.pathname}${u.search}`;
    }
    const quoteGuest = await guestUrl('/agent/quotes.php', 'Plant firewall replacement', 'Viewed', 'quote.php');
    const invoiceGuest = await guestUrl('/agent/invoices.php', 'Managed workstations and Microsoft 365 - monthly', 'Viewed', 'invoice.php');

    await page.goto(quoteGuest, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['QUOTE', 'Firewall Appliance'] });
    await mark(page.locator('a:has-text("Print")').first(), 'g-print');
    await mark(page.locator('a:has-text("Download PDF"), a[href*="export_quote_pdf"]').first(), 'g-pdf');
    await mark(page.locator('button:has-text("Accept")').first(), 'g-accept');
    await mark(page.locator('button:has-text("Decline")').first(), 'g-decline');
    await mark(page.locator('.badge').first(), 'g-badge');
    await callout(page, [
      { selector: '[data-ug="g-print"]', n: 1, side: 'tl' },
      { selector: '[data-ug="g-pdf"]', n: 2, side: 'tl' },
      { selector: '[data-ug="g-badge"]', n: 3, side: 'tl' },
      { selector: '[data-ug="g-accept"]', n: 4, side: 'tl' },
      { selector: '[data-ug="g-decline"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/20-guest-quote`, { fullPage: true });
    await clearCallouts(page);

    await page.goto(invoiceGuest, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await check(page, { text: ['INVOICE', 'Balance'] });
    await mark(page.locator('a:has-text("Print")').first(), 'g-print');
    await mark(page.locator('a[href*="export_invoice_pdf"]').first(), 'g-pdf');
    await mark(page.locator('.badge').first(), 'g-badge');
    const pay = page.locator('a:has-text("Pay")').first();
    const callouts = [
      { selector: '[data-ug="g-print"]', n: 1, side: 'tl' },
      { selector: '[data-ug="g-pdf"]', n: 2, side: 'tl' },
      { selector: '[data-ug="g-badge"]', n: 3, side: 'tl' },
    ];
    if (await pay.count()) { await mark(pay, 'g-pay'); callouts.push({ selector: '[data-ug="g-pay"]', n: 4, side: 'tl' }); }
    await callout(page, callouts);
    await shot(page, `${G}/21-guest-invoice`, { fullPage: true });
    await clearCallouts(page);
  }

  // ------------------------------------------------------------------------------------------- settings
  if (want('settings')) {
    await goto(page, '/admin/settings_invoice.php');
    await check(page, { text: ['Invoice Settings', 'Late Fee'] });
    await mark(page.locator('input[name=config_invoice_prefix]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-prefix');
    await mark(page.locator('input[name=config_invoice_next_number]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-next');
    await mark(page.locator('textarea[name=config_invoice_footer]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-footer');
    await mark(page.locator('input[name=config_invoice_late_fee_enable]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-late');
    await mark(page.locator('input[name=config_invoice_late_fee_percent]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-pct');
    await mark(page.locator('input[name=config_recurring_invoice_prefix]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 's-rec');
    await callout(page, [
      { selector: '[data-ug="s-prefix"]', n: 1, side: 'tl' },
      { selector: '[data-ug="s-next"]', n: 2, side: 'tl' },
      { selector: '[data-ug="s-footer"]', n: 3, side: 'tl' },
      { selector: '[data-ug="s-late"]', n: 4, side: 'tl' },
      { selector: '[data-ug="s-pct"]', n: 5, side: 'tl' },
      { selector: '[data-ug="s-rec"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/22-settings-invoices`, { fullPage: true });
    await clearCallouts(page);

    await goto(page, '/admin/settings_quote.php');
    await check(page, { text: ['Quote Settings'] });
    await mark(page.locator('input[name=config_quote_prefix]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'q-prefix');
    await mark(page.locator('input[name=config_quote_next_number]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'q-next');
    await mark(page.locator('textarea[name=config_quote_footer]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'q-footer');
    await mark(page.locator('input[name=config_quote_notification_email]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'q-mail');
    await callout(page, [
      { selector: '[data-ug="q-prefix"]', n: 1, side: 'tl' },
      { selector: '[data-ug="q-next"]', n: 2, side: 'tl' },
      { selector: '[data-ug="q-footer"]', n: 3, side: 'tl' },
      { selector: '[data-ug="q-mail"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/23-settings-quotes`);
    await clearCallouts(page);

    await goto(page, '/admin/settings_notification.php');
    await check(page, { text: ['Invoice Notifications', 'Client Overdue Reminders'] });
    const sec = page.locator('.notif-section', { hasText: 'Invoice Notifications' }).first();
    await sec.scrollIntoViewIfNeeded();
    await mark(sec.locator('.notif-row', { hasText: 'Client Overdue Reminders' }), 'n-client');
    await mark(sec.locator('.notif-row', { hasText: 'Internal Overdue Alerts' }), 'n-internal');
    await mark(sec.locator('.notif-row', { hasText: 'Invoice Paid Notification' }), 'n-paid');
    await mark(sec.locator('.notif-row', { hasText: 'Recurring Invoice Emails' }), 'n-recur');
    await callout(page, [
      { selector: '[data-ug="n-client"]', n: 1, side: 'tl' },
      { selector: '[data-ug="n-internal"]', n: 2, side: 'tl' },
      { selector: '[data-ug="n-paid"]', n: 3, side: 'tl' },
      { selector: '[data-ug="n-recur"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/24-settings-invoice-notifications`);
    await clearCallouts(page);
  }


  // ------------------------------------------------------------------------------------------- contracts
  if (want('contracts')) {
    // Every client's contracts (the all-clients view of the same page)
    await goto(page, '/agent/contracts.php');
    await check(page, { rows: 8, text: ['Due Soon', 'Expired', 'SLA', 'Allowance'] });
    await mark(page.locator('button.ajax-modal:has-text("New Contract")'), 'c-new');
    await mark(page.locator('table thead th:has-text("Status")'), 'c-status');
    await mark(page.locator('table thead th:has-text("Value")'), 'c-value');
    await mark(page.locator('table thead th:has-text("Frequency")'), 'c-freq');
    await mark(page.locator('table tbody .badge:has-text("SLA")'), 'c-sla');
    await mark(page.locator('table tbody .badge:has-text("Allowance")'), 'c-allow');
    await mark(page.locator('table tbody .badge:has-text("Due Soon")'), 'c-due');
    await mark(page.locator('table tbody .badge:has-text("Expired")'), 'c-exp');
    await mark(page.locator('table thead th:has-text("Action")'), 'c-action');
    await callout(page, [
      { selector: '[data-ug="c-new"]', n: 1, side: 'tl' },
      { selector: '[data-ug="c-status"]', n: 2, side: 'tl' },
      { selector: '[data-ug="c-value"]', n: 3, side: 'tl' },
      { selector: '[data-ug="c-freq"]', n: 4, side: 'tl' },
      { selector: '[data-ug="c-sla"]', n: 5, side: 'tl' },
      { selector: '[data-ug="c-allow"]', n: 6, side: 'tl' },
      { selector: '[data-ug="c-due"]', n: 7, side: 'tr' },
      { selector: '[data-ug="c-exp"]', n: 8, side: 'tr' },
      { selector: '[data-ug="c-action"]', n: 9, side: 'tl' },
    ]);
    await shot(page, `${G}/30-contracts-list`);
    await clearCallouts(page);

    // One client's contracts, opened from the client sidebar
    const lakeview = await clientId(page, 'Lakeview Family Credit Union');
    await goto(page, `/agent/contracts.php?client_id=${lakeview}`);
    await check(page, { rows: 2, text: ['Compliance and Security SLA', 'Pending'] });
    await mark(page.locator('.nav-link:has(.fa-file-contract)').first(), 'k-side');
    await mark(page.locator('table tbody tr:has-text("Pending") td:nth-child(3)'), 'k-status');
    await mark(page.locator('table tbody tr:has-text("Compliance and Security SLA") a[title="Documents"]'), 'k-docs');
    await mark(page.locator('table tbody tr:has-text("Compliance and Security SLA") a.btn-secondary'), 'k-edit');
    await mark(page.locator('table tbody tr:has-text("Compliance and Security SLA") a.btn-danger'), 'k-del');
    await callout(page, [
      { selector: '[data-ug="k-side"]', n: 1, side: 'tr' },
      { selector: '[data-ug="k-status"]', n: 2, side: 'tl' },
      { selector: '[data-ug="k-docs"]', n: 3, side: 'tl' },
      { selector: '[data-ug="k-edit"]', n: 4, side: 'tl' },
      { selector: '[data-ug="k-del"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/31-contracts-client`);
    await clearCallouts(page);

    // New contract pop-up (top, then the included hours at the bottom)
    await setSize(page, W, 1500);
    await openModal(page, page.locator('button.ajax-modal:has-text("New Contract")'));
    await page.fill('.modal.show input[name=contract_name]', 'Server Room Monitoring Agreement');
    await pickTom(page, '.modal.show select[name=contract_type]', 'Managed Services');
    await page.fill('.modal.show input[name=contract_value]', '350.00');
    await pickTom(page, '.modal.show select[name=contract_renewal_frequency]', 'Monthly');
    await page.fill('.modal.show input[name=contract_start_date]', '2026-12-01');
    await page.fill('.modal.show input[name=contract_end_date]', '2027-11-30');
    await page.fill('.modal.show input[name=contract_renewal_date]', '2027-11-30');
    await page.fill('.modal.show input[name=sla_high_response]', '1');
    await page.fill('.modal.show input[name=sla_high_resolution]', '4');
    await page.fill('.modal.show input[name=sla_medium_response]', '4');
    await page.fill('.modal.show input[name=sla_medium_resolution]', '24');
    await page.fill('.modal.show input[name=sla_low_response]', '8');
    await page.fill('.modal.show input[name=sla_low_resolution]', '48');
    await page.fill('.modal.show input[name=support_hours_included_remote]', '2');
    await page.fill('.modal.show input[name=support_hours_included_onsite]', '1');
    await markGroup(page, '.modal.show input[name=contract_name]', 'c1-name');
    await markGroup(page, '.modal.show select[name=contract_type]', 'c1-type');
    await markGroup(page, '.modal.show select[name=contract_status]', 'c1-status');
    await markGroup(page, '.modal.show input[name=contract_value]', 'c1-value');
    await markGroup(page, '.modal.show select[name=contract_renewal_frequency]', 'c1-freq');
    await mark(page.locator('.modal.show input[name=contract_start_date]'), 'c1-start');
    await mark(page.locator('.modal.show input[name=contract_renewal_date]'), 'c1-renew');
    await mark(page.locator('.modal.show table.table-bordered'), 'c1-sla');
    await mark(page.locator('.modal.show input[name=support_hours_included_remote]').locator('xpath=ancestor::div[contains(@class,"row")][1]'), 'c1-hours');
    await callout(page, [
      { selector: '[data-ug="c1-name"]', n: 1, side: 'tl' },
      { selector: '[data-ug="c1-type"]', n: 2, side: 'tl' },
      { selector: '[data-ug="c1-status"]', n: 3, side: 'tr' },
      { selector: '[data-ug="c1-value"]', n: 4, side: 'tl' },
      { selector: '[data-ug="c1-freq"]', n: 5, side: 'tr' },
      { selector: '[data-ug="c1-start"]', n: 6, side: 'tl' },
      { selector: '[data-ug="c1-renew"]', n: 7, side: 'tl' },
      { selector: '[data-ug="c1-sla"]', n: 8, side: 'tl' },
      { selector: '[data-ug="c1-hours"]', n: 9, side: 'tl' },
    ]);
    await shot(page, `${G}/32-contract-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // Documents pop-up of a contract
    await setSize(page, W, 1000);
    await page.locator('table tbody tr:has-text("Compliance and Security SLA") a[title="Documents"]').click();
    await page.waitForSelector('.modal.show .modal-content');
    await page.waitForTimeout(900);
    await mark(page.locator('.modal.show input[type=file]'), 'd-file');
    await mark(page.locator('.modal.show button[name=upload_contract_document]'), 'd-up');
    await callout(page, [
      { selector: '[data-ug="d-file"]', n: 1, side: 'tl' },
      { selector: '[data-ug="d-up"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/33-contract-documents`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // Contract / SLA choice on a new ticket (the longer New Ticket form, opened from a contact's page)
    await goto(page, '/agent/contacts.php?q=Elena+Voss');
    const contactHref = await page.locator('table tbody tr a[href^="contact_details.php"]').first().getAttribute('href');
    await goto(page, `/agent/${contactHref}`);
    await page.locator('a[data-modal-url*="modals/ticket/ticket_add.php"]').first().evaluate((el) => el.click());
    await page.waitForSelector('.modal.show select[name=contract_id]', { timeout: 15000 });
    await page.waitForTimeout(900);
    await pickTom(page, '.modal.show select[name=contract_id]', 'Compliance and Security SLA');
    await page.waitForTimeout(500);
    await markGroup(page, '.modal.show select[name=contract_id]', 't-contract');
    await mark(page.locator('.modal.show #slaHint'), 't-hint');
    await callout(page, [
      { selector: '[data-ug="t-contract"]', n: 1, side: 'tl' },
      { selector: '[data-ug="t-hint"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/34-ticket-contract-sla`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // Included support hours against the allowance (Reports)
    await goto(page, '/agent/reports/included_issues.php');
    await check(page, { text: ['Included Support Hours'] });
    await mark(page.locator('.card-title:has-text("Included Support Hours")').locator('xpath=ancestor::div[contains(@class,"card-header")]'), 'u-head');
    await mark(page.locator('table').first(), 'u-table');
    await callout(page, [
      { selector: '[data-ug="u-head"]', n: 1, side: 'tl' },
      { selector: '[data-ug="u-table"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/39-included-hours-report`);
    await clearCallouts(page);

    // Contract templates (Administration > Templates > Contract templates)
    await goto(page, '/admin/contract_template.php');
    await check(page, { rows: 3, text: ['Fully Managed - Standard'] });
    await mark(page.locator('button.ajax-modal:has-text("New Template")'), 't-new');
    await mark(page.locator('table thead th:has-text("Update Frequency")'), 't-freq');
    await mark(page.locator('table thead th:has-text("Type")').first(), 't-type');
    await mark(page.locator('table thead th:has-text("Net Terms")'), 't-net');
    await page.locator('table tbody tr', { hasText: 'Fully Managed - Standard' }).locator('button[data-bs-toggle=dropdown]').click();
    await page.waitForTimeout(300);
    await mark(page.locator('.dropdown-menu.show a:has-text("Apply to Clients")'), 't-apply');
    await callout(page, [
      { selector: '[data-ug="t-new"]', n: 1, side: 'tl' },
      { selector: '[data-ug="t-type"]', n: 2, side: 'tl' },
      { selector: '[data-ug="t-freq"]', n: 3, side: 'tl' },
      { selector: '[data-ug="t-net"]', n: 4, side: 'tl' },
      { selector: '[data-ug="t-apply"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/35-contract-templates`);
    await clearCallouts(page);
    await closeMenus(page);

    // Apply to clients
    await page.locator('table tbody tr', { hasText: 'Fully Managed - Standard' }).locator('button[data-bs-toggle=dropdown]').click();
    await openModal(page, page.locator('.dropdown-menu.show a:has-text("Apply to Clients")'));
    await page.locator('.modal.show .form-check', { hasText: 'Cedar & Pine Architects' }).locator('input').check();
    await page.locator('.modal.show .form-check', { hasText: 'Kestrel Property Management' }).locator('input').check();
    await mark(page.locator('.modal.show select[name=contract_status]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'ta-status');
    await mark(page.locator('.modal.show input[name=contract_start_date]').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'ta-start');
    await mark(page.locator('.modal.show #select_all_clients').locator('xpath=ancestor::div[contains(@class,"form-group")][1]'), 'ta-clients');
    await mark(page.locator('.modal.show button[name=apply_contract_template]'), 'ta-go');
    await callout(page, [
      { selector: '[data-ug="ta-status"]', n: 1, side: 'tl' },
      { selector: '[data-ug="ta-start"]', n: 2, side: 'tl' },
      { selector: '[data-ug="ta-clients"]', n: 3, side: 'tl' },
      { selector: '[data-ug="ta-go"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/36-contract-template-apply`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // New template pop-up, General Info and SLA tabs
    await setSize(page, W, 1300);
    await openModal(page, page.locator('button.ajax-modal:has-text("New Template")'));
    await page.fill('.modal.show input[name=name]', 'Fully Managed - Small Office');
    await page.fill('.modal.show input[name=description]', 'All-inclusive plan for offices of up to 10 users.');
    await pickTom(page, '.modal.show select[name=type]', 'Fully Managed');
    await pickTom(page, '.modal.show select[name=renewal_frequency]', 'Annually');
    await mark(page.locator('.modal.show #general-tab'), 'tn-general');
    await mark(page.locator('.modal.show #sla-tab'), 'tn-sla');
    await mark(page.locator('.modal.show #rates-tab'), 'tn-rates');
    await mark(page.locator('.modal.show #details-tab'), 'tn-details');
    await markGroup(page, '.modal.show select[name=type]', 'tn-type');
    await markGroup(page, '.modal.show select[name=renewal_frequency]', 'tn-freq');
    await callout(page, [
      { selector: '[data-ug="tn-general"]', n: 1, side: 'tl' },
      { selector: '[data-ug="tn-sla"]', n: 2, side: 'tl' },
      { selector: '[data-ug="tn-rates"]', n: 3, side: 'tl' },
      { selector: '[data-ug="tn-details"]', n: 4, side: 'tl' },
      { selector: '[data-ug="tn-type"]', n: 5, side: 'tl' },
      { selector: '[data-ug="tn-freq"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/37-contract-template-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await page.locator('.modal.show #rates-tab').click();
    await page.waitForTimeout(500);
    await markGroup(page, '.modal.show input[name=rate_standard]', 'tr-std');
    await markGroup(page, '.modal.show input[name=rate_after_hours]', 'tr-ah');
    await markGroup(page, '.modal.show input[name=support_hours]', 'tr-hours');
    await markGroup(page, '.modal.show input[name=net_terms]', 'tr-net');
    await callout(page, [
      { selector: '[data-ug="tr-std"]', n: 1, side: 'tl' },
      { selector: '[data-ug="tr-ah"]', n: 2, side: 'tl' },
      { selector: '[data-ug="tr-hours"]', n: 3, side: 'tl' },
      { selector: '[data-ug="tr-net"]', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/38-contract-template-rates`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await setSize(page, W, 1000);
  }


  // ------------------------------------------------------------------------------------------- recurring invoices
  if (want('recurring')) {
    await goto(page, '/agent/recurring_invoices.php');
    await check(page, { rows: 8, text: ['REC-1', 'Monthly', 'Yearly', 'No Cards on File'] });
    await mark(page.locator('button.ajax-modal:has-text("New Recurring Invoice")'), 'r-new');
    await mark(page.locator('a:has-text("Inactive")').first(), 'r-inactive');
    await mark(page.locator('table thead th:has-text("Next Date")'), 'r-next');
    await mark(page.locator('table thead th:has-text("Frequency")'), 'r-freq');
    await mark(page.locator('table thead th:has-text("Last Sent")'), 'r-last');
    await mark(page.locator('table thead th:has-text("Auto Pay")'), 'r-auto');
    await mark(page.locator('table tbody .badge:has-text("Active")').first(), 'r-badge');
    await mark(page.locator('table thead th:has-text("Action")'), 'r-action');
    await callout(page, [
      { selector: '[data-ug="r-new"]', n: 1, side: 'tl' },
      { selector: '[data-ug="r-inactive"]', n: 2, side: 'tl' },
      { selector: '[data-ug="r-next"]', n: 3, side: 'tl' },
      { selector: '[data-ug="r-freq"]', n: 4, side: 'tl' },
      { selector: '[data-ug="r-last"]', n: 5, side: 'tl' },
      { selector: '[data-ug="r-auto"]', n: 6, side: 'tl' },
      { selector: '[data-ug="r-badge"]', n: 7, side: 'tr' },
      { selector: '[data-ug="r-action"]', n: 8, side: 'tl' },
    ]);
    await shot(page, `${G}/40-recurring-list`);
    await clearCallouts(page);

    // Inactive: the one paused by its expired contract
    await goto(page, '/agent/recurring_invoices.php?status=inactive');
    await check(page, { rows: 1, text: ['Break/fix support retainer'] });
    await mark(page.locator('a:has-text("Active")').first(), 'i-active');
    await mark(page.locator('table tbody .badge').first(), 'i-badge');
    await page.locator('table tbody tr').first().locator('button[data-bs-toggle=dropdown]').click();
    await page.waitForTimeout(300);
    await mark(page.locator('.dropdown-menu.show a:has-text("Delete")'), 'i-del');
    await callout(page, [
      { selector: '[data-ug="i-active"]', n: 1, side: 'tl' },
      { selector: '[data-ug="i-badge"]', n: 2, side: 'tl' },
      { selector: '[data-ug="i-del"]', n: 3, side: 'tl' },
    ]);
    await shot(page, `${G}/41-recurring-inactive`);
    await clearCallouts(page);
    await closeMenus(page);

    // The paused one: its history says why
    await openDoc(page, '/agent/recurring_invoices.php?status=inactive', 'Break/fix support retainer', 'recurring_invoice.php');
    await check(page, { text: ['paused automatically'] });
    await mark(page.locator('h5.badge'), 'p-badge');
    await mark(page.locator('.card:has(.fa-history) table, .card table:has(th:has-text("Event"))').last(), 'p-hist');
    await callout(page, [
      { selector: '[data-ug="p-badge"]', n: 1, side: 'tl' },
      { selector: '[data-ug="p-hist"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/42-recurring-paused-history`, { fullPage: true });
    await clearCallouts(page);

    // A recurring invoice with a saved card on file: Email Notify, auto pay and the menu
    await openDoc(page, '/agent/recurring_invoices.php', 'Managed IT and compliance reporting', 'recurring_invoice.php');
    await check(page, { text: ['RECURRING INVOICE', 'Frequency:'] });
    await page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)').click();
    await page.waitForTimeout(300);
    await mark(page.locator('a:has-text("Email Notify")'), 'd-notify');
    const auto = page.locator('select[name=saved_payment_id]');
    const hasAuto = (await auto.count()) > 0;
    if (hasAuto) await markGroup(page, 'select[name=saved_payment_id]', 'd-auto');
    await mark(page.locator('.dropdown-menu.show a:has-text("Force Send")'), 'd-force');
    await mark(page.locator('.dropdown-menu.show a:has-text("Edit")'), 'd-edit');
    await mark(page.locator('th:has-text("Next Date:")').locator('xpath=..'), 'd-next');
    await mark(page.locator('th:has-text("Last Sent:")').locator('xpath=..'), 'd-last');
    await mark(page.locator('#name'), 'd-item');
    const dc = [
      { selector: '[data-ug="d-notify"]', n: 1, side: 'tl' },
      { selector: '[data-ug="d-edit"]', n: 3, side: 'tl' },
      { selector: '[data-ug="d-force"]', n: 4, side: 'tl' },
      { selector: '[data-ug="d-next"]', n: 5, side: 'tl' },
      { selector: '[data-ug="d-last"]', n: 6, side: 'tl' },
      { selector: '[data-ug="d-item"]', n: 7, side: 'tl' },
    ];
    if (hasAuto) dc.push({ selector: '[data-ug="d-auto"]', n: 2, side: 'tl' });
    await callout(page, dc);
    await shot(page, `${G}/43-recurring-detail`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);

    // Edit pop-up with the contract link
    await page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)').click();
    await openModal(page, page.locator('.dropdown-menu.show a:has-text("Edit")'));
    await setSize(page, W, 1300);
    await markGroup(page, '.modal.show input[name=scope]', 'e-scope');
    await markGroup(page, '.modal.show select[name=frequency]', 'e-freq');
    await markGroup(page, '.modal.show input[name=next_date]', 'e-next');
    await markGroup(page, '.modal.show select[name=category]', 'e-cat');
    await markGroup(page, '.modal.show input[name=recurring_invoice_discount]', 'e-disc');
    await markGroup(page, '.modal.show select[name=contract_id]', 'e-contract');
    await markGroup(page, '.modal.show select[name=status]', 'e-status');
    await callout(page, [
      { selector: '[data-ug="e-scope"]', n: 1, side: 'tl' },
      { selector: '[data-ug="e-freq"]', n: 2, side: 'tl' },
      { selector: '[data-ug="e-next"]', n: 3, side: 'tl' },
      { selector: '[data-ug="e-cat"]', n: 4, side: 'tl' },
      { selector: '[data-ug="e-disc"]', n: 5, side: 'tl' },
      { selector: '[data-ug="e-contract"]', n: 6, side: 'tl' },
      { selector: '[data-ug="e-status"]', n: 7, side: 'tl' },
    ]);
    await shot(page, `${G}/44-recurring-edit`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await setSize(page, W, 1000);

    // New recurring invoice for one client (offers the contract list)
    const lakeview = await clientId(page, 'Lakeview Family Credit Union');
    await goto(page, `/agent/recurring_invoices.php?client_id=${lakeview}`);
    await openModal(page, page.locator('button.ajax-modal:has-text("New Recurring Invoice")'));
    await page.fill('.modal.show input[name=scope]', 'Quarterly examiner report - monthly share');
    await pickTom(page, '.modal.show select[name=frequency]', 'Monthly');
    await pickTom(page, '.modal.show select[name=contract_id]', 'Compliance and Security SLA');
    await pickTom(page, '.modal.show select[name=category]', 'Managed Services');
    await markGroup(page, '.modal.show input[name=scope]', 'w-scope');
    await markGroup(page, '.modal.show select[name=contract_id]', 'w-contract');
    await markGroup(page, '.modal.show input[name=start_date]', 'w-start');
    await markGroup(page, '.modal.show select[name=frequency]', 'w-freq');
    await markGroup(page, '.modal.show select[name=category]', 'w-cat');
    await callout(page, [
      { selector: '[data-ug="w-scope"]', n: 1, side: 'tl' },
      { selector: '[data-ug="w-contract"]', n: 2, side: 'tl' },
      { selector: '[data-ug="w-start"]', n: 3, side: 'tl' },
      { selector: '[data-ug="w-freq"]', n: 4, side: 'tl' },
      { selector: '[data-ug="w-cat"]', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/45-recurring-new`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);

    // Turn an invoice into a recurring one
    await openDocWithStatus(page, '/agent/invoices.php', 'After-hours server restore', 'Paid', 'invoice.php');
    await page.locator('button.btn-secondary[aria-label="Actions"]:not(.btn-sm)').click();
    await openModal(page, page.locator('.dropdown-menu.show a:has-text("Recurring")'));
    await markGroup(page, '.modal.show select[name=frequency]', 'x-freq');
    await mark(page.locator('.modal.show button[name=add_invoice_recurring]'), 'x-go');
    await callout(page, [
      { selector: '[data-ug="x-freq"]', n: 1, side: 'tl' },
      { selector: '[data-ug="x-go"]', n: 2, side: 'tl' },
    ]);
    await shot(page, `${G}/46-invoice-to-recurring`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
  }


  // ------------------------------------------------------------------------------------------- invoice list for one client, bulk actions, PDF
  if (want('bulk')) {
    const lakeview = await clientId(page, 'Lakeview Family Credit Union');
    await goto(page, `/agent/invoices.php?client_id=${lakeview}&status=Unpaid`);
    await check(page, { rows: 2, text: ['Partial'] });
    const rows = page.locator('table tbody tr').filter({ hasText: /Partial|Viewed|Sent/ });
    await rows.nth(0).locator('input.bulk-select').check();
    await rows.nth(1).locator('input.bulk-select').check();
    await page.waitForSelector('#bulkActionButton:not([hidden])');
    await page.locator('#bulkActionButton button.dropdown-toggle').click();
    await page.waitForTimeout(300);
    await mark(page.locator('#bulkActionButton button.dropdown-toggle'), 'b-bulk');
    await mark(page.locator('.dropdown-menu.show a:has-text("Batch Payment")'), 'b-batch');
    await mark(page.locator('.dropdown-menu.show a:has-text("Set Category")'), 'b-cat');
    await mark(page.locator('table tbody tr').filter({ hasText: /Partial|Viewed|Sent/ }).nth(0).locator('input.bulk-select'), 'b-check');
    await mark(page.locator('table tbody td:has(a[href^="recurring_invoice.php"])').first(), 'b-rec');
    await mark(page.locator('a.small-box.bg-info'), 'b-unpaid');
    await callout(page, [
      { selector: '[data-ug="b-unpaid"]', n: 1, side: 'tl' },
      { selector: '[data-ug="b-check"]', n: 2, side: 'tl' },
      { selector: '[data-ug="b-bulk"]', n: 3, side: 'tl' },
      { selector: '[data-ug="b-batch"]', n: 4, side: 'tl' },
      { selector: '[data-ug="b-cat"]', n: 5, side: 'tl' },
      { selector: '[data-ug="b-rec"]', n: 6, side: 'tl' },
    ]);
    await shot(page, `${G}/25-invoices-client-bulk`, { fullPage: true });
    await clearCallouts(page);
    await closeMenus(page);
  }

  if (want('pdf')) {
    // The PDF a client gets: fetched through the signed-in session and rendered with pdftoppm (poppler)
    await openDocWithStatus(page, '/agent/invoices.php', 'Managed IT services - monthly', 'Sent', 'invoice.php');
    const link = await page.locator('a[href*="export_invoice_pdf"]').first().getAttribute('href');
    const b64 = await page.evaluate(async (l) => {
      const r = await fetch('/agent/' + l);
      const buf = new Uint8Array(await r.arrayBuffer());
      let s = ''; for (let i = 0; i < buf.length; i++) s += String.fromCharCode(buf[i]);
      return btoa(s);
    }, link);
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ug-pdf-'));
    const pdf = path.join(tmp, 'invoice.pdf');
    fs.writeFileSync(pdf, Buffer.from(b64, 'base64'));
    execFileSync('pdftoppm', ['-png', '-r', '80', '-f', '1', '-l', '1', '-singlefile', pdf, path.join(tmp, 'page')]);
    const out = path.join(require('../lib.cjs').OUT, G, '26-invoice-pdf.png');
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.copyFileSync(path.join(tmp, 'page.png'), out);
    console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), out));
    fs.rmSync(tmp, { recursive: true, force: true });
  }

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
