// Screenshots for docs/user-guide/02-dashboard.md and 23-reports.md (group "insight").
// Needs seeds 00, 01, 30-31, 50-56 and 90-92 applied.
//
//   cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/insight.cjs
//   GUIDE_CLEAN=1 ...   writes call-out-free copies under images-clean/
//
// The administrator's dashboard is only read. The customise shots use the technician Sam Ortiz (tech2): the script changes
// Sam's layout and puts it back with Reset to default at the end.

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');
const G = 'insight';

function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
async function check(page, text = []) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|undefined)/.test(body)) fail(`error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access/.test(body)) fail(`unexpected page on ${page.url()}`);
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}
const W = 1680;

(async () => {
  const { browser, page } = await launch({ width: W, height: 1000 });

  // ============================================================ ADMIN: dashboard (read only) =====
  await login(page, 'admin');
  await goto(page, '/agent/my_dashboard.php');
  await check(page, ['My dashboard', 'Waiting on you', 'Open tickets by status']);
  await page.waitForTimeout(800);
  await callout(page, [
    { selector: '.td-board', n: 1 },
    { selector: '#dash-year', n: 2 },
    { selector: 'a[href="my_dashboard.php?customise=1"]', n: 3 },
    { selector: '[data-widget="tickets_by_status"] .card-header', n: 4 },
    { selector: '[data-widget="tickets_by_status"] .card-body', n: 5, side: 'br' },
  ]);
  await shot(page, `${G}/01-dashboard`);

  await clearCallouts(page);
  await callout(page, [
    { selector: '.td-col-bill', n: 1 }, { selector: '.td-col-late', n: 2 }, { selector: '.td-col-quote', n: 3 },
    { selector: '.td-total', n: 4, side: 'bl' }, { selector: '.td-bar', n: 5, side: 'bl' },
  ]);
  await shot(page, `${G}/02-waiting-on-you`, { selector: '.td-board' });

  await clearCallouts(page);
  await page.evaluate(() => document.querySelector('[data-widget="created_vs_closed"]').scrollIntoView());
  await page.waitForTimeout(600);
  await callout(page, [
    { selector: '[data-widget="sla_at_risk"] table', n: 1 },
    { selector: '[data-widget="my_tickets"] table', n: 2 },
    { selector: '[data-widget="created_vs_closed"] canvas', n: 3 },
  ]);
  await shot(page, `${G}/03-ticket-widgets`, { fullPage: true });

  await goto(page, '/agent/my_dashboard.php');
  await page.evaluate(() => document.querySelector('[data-widget="cash_flow"]').scrollIntoView());
  await page.waitForTimeout(600);
  await callout(page, [
    { selector: '[data-widget="cash_flow"] .card-title', n: 1 },
    { selector: '[data-widget="cash_flow"] canvas', n: 2 },
    { selector: '[data-widget="expiring"] table', n: 3 },
  ]);
  await shot(page, `${G}/04-cash-flow-and-expiring`);

  // admin: the add-widget panel (nothing is added)
  await goto(page, '/agent/my_dashboard.php?customise=1');
  await check(page, ['Add a widget', 'Reset to default']);
  await page.click('button[data-bs-target="#dash-add-widget"]');
  await page.waitForTimeout(700);
  await callout(page, [
    { selector: 'button[data-bs-target="#dash-add-widget"]', n: 1, side: 'tr' },
    { selector: '#dash-add-widget .mb-3', n: 2 },
    { selector: 'button[data-bs-target="#dash-reset-modal"]', n: 3 },
    { selector: 'a.btn-primary[href="my_dashboard.php"]', n: 4 },
  ]);
  await shot(page, `${G}/05-add-a-widget`);

  await clearCallouts(page);
  await page.click('button[data-bs-target="#dash-add-widget"]');
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: '[data-widget="tickets_by_status"] .dash-drag', n: 1 },
    { selector: '[data-widget="tickets_by_status"] .card-header .d-flex.gap-1', n: 2, side: 'br' },
    { selector: '[data-widget="tickets_by_status"] button[title="Hide"]', n: 3, side: 'br' },
    { selector: '[data-widget="tickets_by_status"] button[title="Remove"]', n: 4, side: 'br' },
  ]);
  await shot(page, `${G}/06-customise-toolbar`, { selector: '[data-widget="tickets_by_status"]' });

  await clearCallouts(page);
  await page.click('button[data-bs-target="#dash-reset-modal"]');
  await page.waitForSelector('#dash-reset-modal.show');
  await page.waitForTimeout(700);
  await shot(page, `${G}/07-reset-dialog`, { selector: '#dash-reset-modal .modal-content' });
  await page.keyboard.press('Escape');

  // ============================================================ TECHNICIAN: role-limited, customise ===
  await browser.close();
  const b2 = await launch({ width: W, height: 1000 });
  const p2 = b2.page;
  await login(p2, 'tech2');
  await goto(p2, '/agent/my_dashboard.php?customise=1');
  await check(p2, ['My dashboard']);
  // start from the default
  await p2.click('button[data-bs-target="#dash-reset-modal"]');
  await p2.waitForSelector('#dash-reset-modal.show');
  await p2.click('#dash-reset-modal button[type=submit]');
  await p2.waitForLoadState('domcontentloaded'); await settle(p2);
  await goto(p2, '/agent/my_dashboard.php');
  await check(p2, ['Your day']);
  await callout(p2, [{ selector: '.td-board', n: 1 }, { selector: '.td-col-bill', n: 2 }, { selector: '.td-col-late', n: 3 }, { selector: '.td-col-quote', n: 4 }]);
  await shot(p2, `${G}/08-technician-dashboard`);

  // add a few widgets, one at a time through the same buttons
  async function addWidget(id) {
    await goto(p2, '/agent/my_dashboard.php?customise=1');
    await p2.click('button[data-bs-target="#dash-add-widget"]');
    await p2.waitForTimeout(400);
    await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.locator(`#dash-add-widget form:has(input[value="${id}"]) button`).click()]);
    await settle(p2);
  }
  for (const id of ['csat', 'needs_attention', 'tickets_by_technician_open', 'tickets_flow_year', 'tickets_resolved_year']) await addWidget(id);
  await goto(p2, '/agent/my_dashboard.php?customise=1');
  await p2.click('button[data-bs-target="#dash-add-widget"]');
  await p2.waitForTimeout(600);
  await check(p2, ['Add a widget']);
  await callout(p2, [{ selector: '#dash-add-widget', n: 1 }]);
  await shot(p2, `${G}/09-technician-add-list`, { selector: '.card:has(#dash-add-widget)' });

  // chart toggles: pie for "Open tickets by status", size small for another
  await clearCallouts(p2);
  await goto(p2, '/agent/my_dashboard.php?customise=1');
  await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.locator('[data-widget="tickets_by_status"] button[title="Chart: Pie"]').click()]);
  await settle(p2, 900);
  await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.locator('[data-widget="tickets_by_priority"] button[title="Chart: Bar"]').click()]);
  await settle(p2, 900);
  await check(p2, ['Open tickets by status']);
  await callout(p2, [
    { selector: '[data-widget="tickets_by_status"] .card-header .d-flex.gap-1', n: 1, side: 'br' },
    { selector: '[data-widget="tickets_by_status"] canvas', n: 2, side: 'br' },
    { selector: '[data-widget="tickets_by_priority"] canvas', n: 3, side: 'br' },
  ]);
  await shot(p2, `${G}/10-chart-types`);

  // hide one widget: placeholder text
  await clearCallouts(p2);
  await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.locator('[data-widget="my_tickets"] button[title="Hide"]').click()]);
  await settle(p2, 700);
  await callout(p2, [{ selector: '[data-widget="my_tickets"] .card-body', n: 1 }, { selector: '[data-widget="my_tickets"] button[title="Show"]', n: 2, side: 'br' }]);
  await shot(p2, `${G}/11-hidden-widget`, { selector: '[data-widget="my_tickets"]' });
  await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.locator('[data-widget="my_tickets"] button[title="Show"]').click()]);
  await settle(p2);

  // finished dashboard (year widgets show the year picker)
  await goto(p2, '/agent/my_dashboard.php');
  await p2.waitForTimeout(800);
  await callout(p2, [{ selector: '#dash-year', n: 1 }, { selector: '[data-widget="csat"]', n: 2 }, { selector: '[data-widget="needs_attention"]', n: 3 }, { selector: '[data-widget="tickets_flow_year"]', n: 4 }]);
  await shot(p2, `${G}/12-technician-widgets`, { fullPage: true });

  // put Sam's dashboard back to the default
  await goto(p2, '/agent/my_dashboard.php?customise=1');
  await p2.click('button[data-bs-target="#dash-reset-modal"]');
  await p2.waitForSelector('#dash-reset-modal.show');
  await Promise.all([p2.waitForLoadState('domcontentloaded'), p2.click('#dash-reset-modal button[type=submit]')]);
  await settle(p2);
  await b2.browser.close();

  // ============================================================ ADMIN: reports ====================
  const b3 = await launch({ width: W, height: 1000 });
  const pg = b3.page;
  await login(pg, 'admin');
  const R = '/agent/reports';
  async function report(url, name, text, items, opts = {}) {
    await goto(pg, url);
    await check(pg, text);
    await pg.waitForTimeout(opts.wait || 1300);
    if (items && items.length) await callout(pg, items);
    await shot(pg, `${G}/${name}`, opts.full === false ? {} : { fullPage: true });
    await clearCallouts(pg);
  }
  const CSV = { selector: 'a.btn-success', n: 'x' };

  await report(`${R}/`, '20-reports-hub', ['Income This Month', 'Outstanding AR', 'Open Tickets'], [
    { selector: '.small-box.text-bg-success', n: 1 }, { selector: '.small-box.text-bg-warning', n: 2 }, { selector: '.small-box.text-bg-danger', n: 3 },
    { selector: '.small-box.text-bg-primary', n: 4 }, { selector: '#sidebar-menu', n: 5 }], { full: false });
  await report(`${R}/income_summary.php`, '21-income', ['Income Summary'], [
    { selector: 'select[name=year]', n: 1 }, { selector: 'a.btn-success', n: 2 }, { selector: '.js-print-page', n: 3 }, { selector: '#cashFlow', n: 4 }, { selector: 'table', n: 5 }]);
  await report(`${R}/income_by_client.php`, '22-income-by-client', ['Income By Client'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/recurring_by_client.php`, '23-recurring-by-client', ['Recurring Income By Client'], [{ selector: 'table', n: 1 }]);
  await report(`${R}/mrr.php`, '24-mrr', ['Current MRR', 'Top Clients by MRR'], [
    { selector: '.info-box:nth-of-type(1)', n: 1 }, { selector: '#mrrTrendChart', n: 2 }, { selector: '#mrrForecastChart', n: 3 }, { selector: 'a.btn-success', n: 4 }]);
  await report(`${R}/clients_with_balance.php`, '25-ar-aging', ['AR Aging', 'Balance'], [
    { selector: '.info-box.bg-success', n: 1 }, { selector: '.info-box.bg-danger', n: 2 }, { selector: 'table', n: 3 }, { selector: 'a.btn-success', n: 4 }]);
  await report(`${R}/expense_summary.php`, '26-expense', ['Expense Summary'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/expense_by_vendor.php`, '27-expense-by-vendor', ['Expense By Vendor'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/tax_summary.php`, '28-tax-summary', ['Collected Tax Summary'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'select[name=view]', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/profit_loss.php`, '29-profit-loss', ['Profit & Loss', 'Net Profit', 'Client Profitability'], [
    { selector: 'select[name=year]', n: 1 }, { selector: 'table.table', n: 2 }]);
  await report(`${R}/budget.php`, '30-budget', ['Annual Budget'], [{ selector: 'select[name=year]', n: 1 }, { selector: '#cashFlow', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/tickets_unbilled.php?year=2026`, '31-unbilled', ['Unbilled Tickets By Client'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/client_ticket_time_detail.php?canned_date=custom&from=2026-03-02&to=2026-03-06&billable_only=1`, '32-time-detail', ['Client Time Detail Audit Report', 'Grand Total'], [
    { selector: '.drp-btn', n: 1 }, { selector: 'select[name=billing_increment]', n: 2 }, { selector: '#billable_only', n: 3 }, { selector: 'table', n: 4 }], { full: false });
  await report(`${R}/included_issues.php?month=6&year=2026`, '33-included-hours', ['Included Support Hours'], [
    { selector: 'select[name=month]', n: 1 }, { selector: 'select[name=year]', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/service_desk.php?canned_date=last12months`, '34-service-desk', ['Service Desk', 'Technician Workload'], [
    { selector: '.drp-btn', n: 1 }, { selector: '#reportMaintenance', n: 2 }, { selector: '.info-box.bg-danger', n: 3 }, { selector: 'a.btn-success', n: 4 }]);
  await report(`${R}/ticket_summary.php`, '35-ticket-summary', ['Ticket Summary'], [{ selector: '#ticket-summary-year', n: 1 }, { selector: 'a.btn-success', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/ticket_charges.php?year=2026`, '36-ticket-charges', ['Ticket Charges'], [{ selector: 'select[name=year]', n: 1 }, { selector: '#uninvoiced', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/ticket_by_client.php?year=2026&month=3`, '37-tickets-by-client', ['Tickets By Client'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'select[name=month]', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/time_by_tech.php`, '38-time-by-tech', ['Time Logged By Technician'], [{ selector: 'select[name=year]', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/technician_performance.php?canned_date=last12months`, '39-tech-performance', ['Technician Performance'], [
    { selector: '.info-box.bg-success', n: 1 }, { selector: '.info-box:nth-of-type(1)', n: 2 }, { selector: 'table', n: 3 }, { selector: 'a.btn-success', n: 4 }]);
  await report(`${R}/csat.php?canned_date=last12months`, '40-csat', ['Customer Satisfaction', 'By Technician'], [
    { selector: '.drp-btn', n: 1 }, { selector: '.info-box.bg-danger', n: 2 }, { selector: 'table', n: 3 }]);
  await report(`${R}/rmm_health.php?canned_date=last12months`, '41-rmm-health', ['RMM Health', 'Noisiest'], [{ selector: '.drp-btn', n: 1 }, { selector: 'table', n: 2 }]);
  await report(`${R}/credential_rotation.php`, '42-credential-rotation', ['not changed/rotated'], [{ selector: 'input[name=days]', n: 1 }, { selector: 'table', n: 2 }], { full: false });

  // the date-range picker, open
  await goto(pg, `${R}/service_desk.php?canned_date=last12months`);
  await pg.click('.drp-btn');
  await pg.waitForTimeout(800);
  await shot(pg, `${G}/43-date-range-picker`);
  await pg.keyboard.press('Escape');

  // scheduled reports
  await report(`${R}/schedules.php`, '44-scheduled-reports', ['Scheduled & Emailed Reports', 'Add'], [
    { selector: 'select[name=schedule_report]', n: 1 }, { selector: 'select[name=schedule_frequency]', n: 2 }, { selector: 'input[name=schedule_recipients]', n: 3 },
    { selector: 'form.p-3 button.btn-primary', n: 4 }, { selector: 'table', n: 5 }], { full: false });
  await b3.browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
