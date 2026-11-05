// Walks the browser installer (/setup/) on a BRAND-NEW throw-away instance and screenshots each step, then the first
// screens an administrator sees after installing. Pictures go to images/setup-wizard/ (images-clean/setup-wizard/ with GUIDE_CLEAN=1).
//
// NOT read-only: it installs the app. It is NOT part of run-all.cjs and must never be pointed at the shared demo.
// Run it against a copy of the app that has no config.php, with an empty pre-created database:
//
//   1. Copy the app (no config.php, nothing under uploads/) -> $W/app; comment out the platform_check require in
//      vendor/composer/autoload_real.php if PHP < 8.5; create database rivetmsp_wizard + user rivetmsp_wizard.
//   2. Serve it (own port, not the demo's 8090):
//        cd $W/app && RIVETMSP_SKIP_KEYFILE=1 php -d upload_max_filesize=500M -d post_max_size=500M -S 127.0.0.1:8191 -t .
//      RIVETMSP_SKIP_KEYFILE=1 stops the installer from creating /etc/rivetmsp/keys.json on the host.
//   3. Run (the script maps a made-up host name to the port inside the browser, so the installer's "valid FQDN" check passes
//      without DNS or a hosts-file change):
//        WIZ_PORT=8191 WIZ_CONFIG=$W/app/config.php NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/setup-wizard.cjs
//      Add GUIDE_CLEAN=1 for the call-out-free set (needs a second fresh copy: the installer only installs once).
//
// KNOWN APP DEFECT (release 26.10.49): the Company step of the browser wizard dies with a 500 (setCanonicalVaultKey() is called with
// $site_encryption_master_key, which is only set during the User step's request), so no roles, permission modules, categories or
// ticket statuses are created. To get the post-install pictures, the SCRATCH copy's setup/index.php is patched (never the repository)
// with:  sed -i 's/^    setCanonicalVaultKey(\$mysqli, \$site_encryption_master_key);/    setCanonicalVaultKey($mysqli, $site_encryption_master_key ?? randomString());/' $W/app/setup/index.php
//
// WIZ_CONFIG (optional): path of the copy's config.php. After the HTTPS-only picture the script sets $config_https_only = FALSE
// there (what the guide describes for a private test server) so it can sign in and take the post-install pictures.

const fs = require('fs');
const { chromium } = require('playwright');
const { shot, callout, clearCallouts, settle } = require('../lib.cjs');

const HOST = process.env.WIZ_HOST || 'msp.lakeshoremsp.example';
const PORT = process.env.WIZ_PORT || '8191';
const BASE = `http://${HOST}`;
const DB = process.env.WIZ_DB || 'rivetmsp_wizard';
const DB_USER = process.env.WIZ_DB_USER || 'rivetmsp_wizard';
const DB_PASS = process.env.WIZ_DB_PASS || 'WizDbPass_2026';
const CONFIG = process.env.WIZ_CONFIG || '';
const ADMIN_EMAIL = 'morgan.reyes@lakeshoremsp.example';
const ADMIN_PASSWORD = 'WizardPass#2026';
const g = 'setup-wizard';

async function go(page, url) {
  await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'domcontentloaded' });
  await settle(page);
}

// Country / language / currency / time zone are select2 widgets over a hidden <select>.
async function setSelect(page, name, value) {
  await page.evaluate(({ name, value }) => {
    const el = document.querySelector(`select[name="${name}"]`);
    if (!el) throw new Error(`no select named ${name}`);
    el.value = value;
    if (window.jQuery) window.jQuery(el).val(value).trigger('change');
    else el.dispatchEvent(new Event('change', { bubbles: true }));
  }, { name, value });
}

async function assertClean(page, label) {
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught \w+)/);
  if (bad) throw new Error(`${label}: page shows a PHP problem ("${bad[1]}")`);
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1:${PORT}`] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light' });
  const page = await context.newPage();
  page.setDefaultTimeout(120000); // the Database step loads ~380 tables and can take well over 20 s

  // 01 Welcome
  await go(page, '/setup/');
  await assertClean(page, 'welcome');
  await callout(page, [
    { selector: 'aside .nav-sidebar', n: 1, side: 'tr' },
    { selector: 'a[href="?checks"].btn', n: 2, side: 'tr' },
  ]);
  await shot(page, `${g}/01-welcome`);
  await clearCallouts(page);

  // 02 Checks
  await go(page, '/setup/?checks');
  await assertClean(page, 'checks');
  // show a neutral web-root path instead of this sandbox's scratch directory
  await page.evaluate(() => { document.querySelectorAll('td').forEach((td) => { if (/\/g1-wizard\/app$/.test(td.textContent.trim())) td.textContent = '/var/www/rivetmsp'; }); });
  await callout(page, [{ selector: 'a[href="?database"].btn', n: 1, side: 'tr' }]);
  await shot(page, `${g}/02-checks`, { fullPage: true });
  await clearCallouts(page);

  // 03 Database
  await go(page, '/setup/?database');
  await page.fill('input[name=database]', DB);
  await page.fill('input[name=host]', 'localhost');
  await page.fill('input[name=username]', DB_USER);
  await page.fill('input[name=password]', DB_PASS);
  await callout(page, [
    { selector: 'input[name=database]', n: 1, side: 'tr' },
    { selector: 'input[name=host]', n: 2, side: 'tr' },
    { selector: 'input[name=username]', n: 3, side: 'tr' },
    { selector: 'input[name=password]', n: 4, side: 'tr' },
    { selector: 'button[name=add_database]', n: 5, side: 'tr' },
  ]);
  await shot(page, `${g}/03-database`);
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_database]')]);
  await settle(page, 2500);

  // 09 / 10 Welcome and Restore once the database is connected
  await go(page, '/setup/');
  await assertClean(page, 'welcome after database');
  await callout(page, [
    { selector: 'a[href="?user"].btn', n: 1, side: 'tr' },
    { selector: 'a[href="?restore"].btn', n: 2, side: 'tr' },
  ]);
  await shot(page, `${g}/09-welcome-after-database`);
  await clearCallouts(page);
  await go(page, '/setup/?restore');
  await assertClean(page, 'restore');
  await callout(page, [
    { selector: 'input[name=backup_zip]', n: 1, side: 'tr' },
    { selector: 'input[name=backup_passphrase]', n: 2, side: 'tr' },
    { selector: 'button[name=restore]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${g}/10-restore`, { fullPage: true });
  await clearCallouts(page);

  // 04 First user
  await go(page, '/setup/?user');
  await page.fill('input[name=name]', 'Morgan Reyes');
  await page.fill('input[name=email]', ADMIN_EMAIL);
  await page.fill('input[name=password]', ADMIN_PASSWORD);
  await callout(page, [
    { selector: 'input[name=name]', n: 1, side: 'tr' },
    { selector: 'input[name=email]', n: 2, side: 'tr' },
    { selector: 'input[name=password]', n: 3, side: 'tr' },
    { selector: 'button[name=add_user]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${g}/04-first-user`);
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_user]')]);
  await settle(page, 1000);

  // 05 Company
  await go(page, '/setup/?company');
  await page.fill('input[name=name]', 'Lakeshore Managed IT');
  await page.fill('input[name=address]', '1200 Ridge Road');
  await page.fill('input[name=city]', 'Madison');
  await page.fill('input[name=state]', 'WI');
  await page.fill('input[name=zip]', '53703');
  await setSelect(page, 'country', 'United States');
  await page.fill('input[name=phone]', '6085550100');
  await page.fill('input[name=email]', 'hello@lakeshoremsp.example');
  await page.fill('input[name=website]', 'lakeshoremsp.example');
  await callout(page, [
    { selector: 'input[name=name]', n: 1, side: 'tr' },
    { selector: 'select[name=country]', n: 2, side: 'tr' },
    { selector: 'button[name=add_company_settings]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${g}/05-company`, { fullPage: true });
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_company_settings]')]);
  await settle(page, 2500);

  // 06 Region and language
  await go(page, '/setup/?localization');
  await setSelect(page, 'locale', 'en_US');
  await setSelect(page, 'currency_code', 'USD');
  await setSelect(page, 'timezone', 'America/Chicago');
  await callout(page, [
    { selector: 'select[name=locale]', n: 1, side: 'tr' },
    { selector: 'select[name=currency_code]', n: 2, side: 'tr' },
    { selector: 'select[name=timezone]', n: 3, side: 'tr' },
    { selector: 'button[name=add_localization_settings]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${g}/06-localization`);
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_localization_settings]')]);

  // 07 Privacy and finish
  await go(page, '/setup/?telemetry');
  await assertClean(page, 'privacy');
  await callout(page, [{ selector: 'button[name=add_telemetry]', n: 1, side: 'tr' }]);
  await shot(page, `${g}/07-telemetry`, { fullPage: true });
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=add_telemetry]')]);
  await page.waitForTimeout(3500); // opcache may serve the old config.php for a moment

  // 08 First visit over plain http
  await go(page, '/login.php');
  console.log('finished at:', page.url());
  await page.setViewportSize({ width: 1100, height: 120 });   // the page is two lines of plain text: crop the empty area
  await shot(page, `${g}/08-first-sign-in`);
  await page.setViewportSize({ width: 1440, height: 900 });

  if (!CONFIG) { console.log('WIZ_CONFIG not set: stopping after the HTTPS-only message'); await browser.close(); return; }

  // allow plain http on this throw-away server, sign in
  let cfg = fs.readFileSync(CONFIG, 'utf8');
  if (!/\$config_https_only\s*=\s*FALSE/i.test(cfg)) {
    cfg = cfg.replace(/\$config_https_only\s*=\s*TRUE\s*;/i, '$config_https_only = FALSE;');
    fs.writeFileSync(CONFIG, cfg);
  }
  await page.waitForTimeout(3500);
  await go(page, '/login.php');
  await page.fill('input[name=email]', ADMIN_EMAIL);
  await page.fill('input[name=password]', ADMIN_PASSWORD);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=login]')]);
  await settle(page, 1500);
  console.log('signed in, now at:', page.url());
  await page.reload({ waitUntil: 'domcontentloaded' }); // drop the one-shot "Unencrypted connection" toast
  await settle(page, 800);

  // 11 First screen after install
  await shot(page, `${g}/11-first-screen`);

  // 12 Maintenance > Update
  await go(page, '/admin/update.php');
  await assertClean(page, 'update');
  await shot(page, `${g}/12-update`, { fullPage: true });

  // 13 Settings > Notifications (cron switch)
  await go(page, '/admin/settings_notification.php');
  await assertClean(page, 'notifications');
  if (await page.locator('#enableCronSwitch').count()) await callout(page, [{ selector: '#enableCronSwitch', n: 1, side: 'tr' }]);
  await shot(page, `${g}/13-enable-cron`, { fullPage: true });
  await clearCallouts(page);

  // 14 Backups
  await go(page, '/admin/backup.php');
  await assertClean(page, 'backups');
  await shot(page, `${g}/14-backups`, { fullPage: true });

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
