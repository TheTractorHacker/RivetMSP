// Getting started (page 01): sign-in page, the app shell, role-dependent sidebars, the two secondary rails, the account pages,
// the bell, Search everywhere and the shared list controls. Read-only against the shared demo (it never saves anything: the
// MFA pop-up is opened and cancelled).
//
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8090 node docs/user-guide/tools/capture/getting-started.cjs
//   (GUIDE_CLEAN=1 for the call-out-free copies in images-clean/)

const { launch, login, shot, goto, settle, callout, clearCallouts, BASE } = require('../lib.cjs');
const g = 'getting-started';

async function clean(page, label) {
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught \w+|undefined)/);
  if (bad) throw new Error(`${label}: page shows "${bad[1]}"`);
}

(async () => {
  const { browser, context, page } = await launch();

  // 01 Sign-in page (signed out)
  await goto(page, '/login.php');
  await clean(page, 'login');
  await callout(page, [
    { selector: '#passkeySignInBtn', n: 1, side: 'tr' },
    { selector: 'input[name=email]', n: 2, side: 'tr' },
    { selector: 'input[name=password]', n: 3, side: 'tr' },
    { selector: '#remember_me', n: 4, side: 'tr' },
    { selector: 'button[name=login]', n: 5, side: 'tr' },
  ]);
  await shot(page, `${g}/01-sign-in`);
  await clearCallouts(page);

  // ---- administrator ----
  await login(page, 'admin');

  // 02 App shell with the user menu open
  await goto(page, '/agent/clients.php');
  await clean(page, 'clients');
  await page.click('a[aria-label="Account menu"]');
  await page.waitForTimeout(400);
  await callout(page, [
    { selector: '#nav-group-organization', n: 1, side: 'tr' },
    { selector: '#itflowSidebarToggle', n: 2, side: 'br' },
    { selector: '#globalSearchInput', n: 3, side: 'br' },
    { selector: 'a[data-modal-url="/modals/notifications.php"]', n: 4, side: 'bl' },
    { selector: '.user-menu .dropdown-menu', n: 5, side: 'tl' },
  ]);
  await shot(page, `${g}/02-app-shell`);
  await clearCallouts(page);

  // 03 Client workspace rail (one client)
  await goto(page, '/agent/client_overview.php?client_id=1');
  await clean(page, 'client overview');
  await callout(page, [
    { selector: '.client-nav-back', n: 1, side: 'br' },
    { selector: '.client-nav-header', n: 2, side: 'br' },
    { selector: 'li.nav-section-title', n: 3, side: 'tr' },
  ]);
  await shot(page, `${g}/03-client-workspace`);
  await clearCallouts(page);

  // 04 Company-wide rail (Client Overview in the main sidebar)
  await goto(page, '/agent/contacts.php');
  await clean(page, 'company-wide');
  await callout(page, [
    { selector: '.client-nav-back', n: 1, side: 'br' },
    { selector: '.client-nav-header', n: 2, side: 'br' },
  ]);
  await shot(page, `${g}/04-company-wide`);
  await clearCallouts(page);

  // 05 The Overview page of one client (cards)
  await goto(page, '/agent/client_overview.php?client_id=1');
  await page.evaluate(() => {
    const tag = (txt, id) => { const h = [...document.querySelectorAll('h5.card-title')].find((e) => e.textContent.includes(txt)); if (h) (h.closest('.card') || h).id = id; };
    tag('Key Contacts', 'ug-key-contacts'); tag('Open Tickets', 'ug-open-tickets'); tag('Expiring', 'ug-expiring');
  });
  await callout(page, [
    { selector: 'a.client-nav-header + *, .navbar-nav .nav-item.active', n: 1, side: 'tr' },
    { selector: '#ug-open-tickets', n: 2, side: 'tr' },
    { selector: '#ug-key-contacts', n: 3, side: 'tr' },
    { selector: '#ug-expiring', n: 4, side: 'tr' },
  ]);
  await shot(page, `${g}/05-client-overview`, { fullPage: true });
  await clearCallouts(page);

  // 06 Account > Details
  await goto(page, '/agent/user/user_details.php');
  await clean(page, 'details');
  await callout(page, [
    { selector: 'input[name=name]', n: 1, side: 'tr' },
    { selector: 'label.btn.btn-outline-secondary', n: 2, side: 'br' },
    { selector: 'input[disabled]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${g}/06-account-details`);
  await clearCallouts(page);

  // 07 Account > Security
  await goto(page, '/agent/user/user_security.php');
  await clean(page, 'security');
  await callout(page, [
    { selector: 'button[name=edit_your_user_password]', n: 1, side: 'tr' },
    { selector: 'button[data-bs-target="#enableMFAModal"]', n: 2, side: 'tr' },
    { selector: 'button[name=sign_out_everywhere]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${g}/07-account-security`);
  await clearCallouts(page);

  // 08 MFA enrolment pop-up (opened, never completed)
  const mfa = page.locator('button[data-bs-target="#enableMFAModal"]').first();
  if (await mfa.count()) {
    await mfa.click();
    await page.waitForSelector('#enableMFAModal.show input[name=verify_code]', { timeout: 8000 });
    await page.waitForTimeout(600);
    await shot(page, `${g}/08-mfa-setup`, { selector: '#enableMFAModal .modal-content' });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
  }

  // 09 Account > Preferences
  await goto(page, '/agent/user/user_preferences.php');
  await clean(page, 'preferences');
  await callout(page, [
    { selector: '.js-btn-group-toggle', n: 1, side: 'tr' },
    { selector: 'select[name=calendar_first_day]', n: 2, side: 'tr' },
    { selector: 'select[name=records_per_page]', n: 3, side: 'tr' },
    { selector: 'button[name=edit_your_user_preferences]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${g}/09-account-preferences`);
  await clearCallouts(page);

  // 10 Bell
  await goto(page, '/agent/dashboard.php');
  await page.click('a[data-modal-url="/modals/notifications.php"]');
  await page.waitForSelector('.modal.show .modal-content', { timeout: 8000 });
  await page.waitForTimeout(700);
  await shot(page, `${g}/10-notifications`, { selector: '.modal.show .modal-content' });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);

  // 11 Search everywhere (live drop-down)
  await goto(page, '/agent/clients.php');
  await page.fill('#globalSearchInput', 'northshore');
  await page.waitForSelector('#globalSearchResults:not(.d-none) .app-header-search-result', { timeout: 8000 });
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: '#globalSearchInput', n: 1, side: 'tl' },
    { selector: '.app-header-search-group', n: 2, side: 'tr' },
  ]);
  await shot(page, `${g}/11-search-dropdown`, { keepHover: true });
  await clearCallouts(page);

  // 12 Search results page
  await goto(page, '/agent/global_search.php?query=northshore');
  await clean(page, 'search results');
  await shot(page, `${g}/12-search-results`);

  // 13 List controls: search, filter, bulk select and the footer
  await goto(page, '/agent/clients.php');
  await page.click('button[data-bs-target="#advancedFilter"]');
  await page.waitForTimeout(600);
  await page.locator('tbody input[type=checkbox], .form-check-input.bulk-select').nth(0).check().catch(() => {});
  await page.waitForTimeout(300);
  await callout(page, [
    { selector: 'input[name=q]', n: 1, side: 'tr' },
    { selector: 'button[data-bs-target="#advancedFilter"]', n: 2, side: 'tr' },
    { selector: '#advancedFilter', n: 3, side: 'tr' },
    { selector: '#selectAllCheckbox', n: 4, side: 'tr' },
  ]);
  await shot(page, `${g}/13-list-controls`);
  await clearCallouts(page);

  // 14 List footer (records per page, page numbers)
  await goto(page, '/agent/clients.php');
  const footer = page.locator('.card-footer').last();
  if (await footer.count()) {
    await footer.scrollIntoViewIfNeeded();
    await shot(page, `${g}/14-list-footer`, { selector: '.card-footer' });
  }

  // 15 Roles (administrator view; full coverage is in the users and roles guide)
  await goto(page, '/admin/roles.php');
  await clean(page, 'roles');
  await shot(page, `${g}/15-roles`);

  // 16 The sidebar for three roles, side by side
  const bufs = [];
  const adminBuf = await (async () => { await goto(page, '/agent/dashboard.php'); return page.locator('aside.navbar-vertical').screenshot(); })();
  bufs.push(['Administrator', adminBuf]);
  for (const [who, label] of [['tech1', 'Technician'], ['billing', 'Billing clerk']]) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const p = await ctx.newPage();
    p.setDefaultTimeout(20000);
    await login(p, who);
    await goto(p, '/agent/dashboard.php');
    await clean(p, who);
    bufs.push([label, await p.locator('aside.navbar-vertical').screenshot()]);
    await ctx.close();
  }
  const cmp = await context.newPage();
  const cells = bufs.map(([l, b]) => `<figure style="margin:0;text-align:center"><div style="font:600 15px system-ui,sans-serif;margin-bottom:8px;color:#1f2d3d">${l}</div><img style="display:block;border:1px solid #c9d1d9" src="data:image/png;base64,${b.toString('base64')}"></figure>`).join('');
  await cmp.setContent(`<body style="margin:0;background:#fff"><div id="wrap" style="display:flex;gap:24px;padding:16px;align-items:flex-start;width:max-content">${cells}</div></body>`);
  await cmp.setViewportSize({ width: 1000, height: 800 });
  await cmp.waitForTimeout(300);
  await shot(cmp, `${g}/16-sidebars-by-role`, { selector: '#wrap' });
  await cmp.close();

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
