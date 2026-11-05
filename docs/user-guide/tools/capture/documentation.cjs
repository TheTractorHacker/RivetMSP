// Documentation group (pages 11-14): assets, documentation records, knowledge base, credentials.
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8090 node docs/user-guide/tools/capture/documentation.cjs
//   GUIDE_CLEAN=1 ... writes the call-out-free copies into images-clean/.
//   GUIDE_ONLY=assets,kb  runs only the named sections.
// Read-only: every modal is opened and closed again, nothing is saved. Needs seeds 20-23 (Northshore Dental Group is the star client).
const { launch, login, shot, goto, callout, clearCallouts, BASE } = require('../lib.cjs');
const { co, fg, tall, normal, openModal, closeModal, modalTab, openDropdown, scrollTo } = require('./documentation-helpers.cjs');
const { execSync } = require('child_process');

const only = (process.env.GUIDE_ONLY || '').split(',').filter(Boolean);
const want = (name) => only.length === 0 || only.includes(name);

// Look ids up by name so the script survives a rebuild (ids can differ).
function sql(q) {
  return execSync(`mysql -u root rivetmsp_demo -N -e ${JSON.stringify(q)}`).toString().trim();
}
const id = {
  nsd: sql("select client_id from clients where client_name='Northshore Dental Group'"),
  blp: sql("select client_id from clients where client_name='Birchwood Law Partners'"),
  gpm: sql("select client_id from clients where client_name='Granite Peak Manufacturing'"),
};
const asset = (name) => sql(`select asset_id from assets where asset_name='${name}'`);

async function assets(page) {
  const C = id.nsd;
  const sel2 = (name) => `select[name="${name}"] + .ts-wrapper`;
  // 01 list with call-outs
  await goto(page, `/agent/assets.php?client_id=${C}`);
  await co(page, [
    { selector: '.btn-toolbar .btn-group > a:first-child', n: 1 },
    { selector: 'input[name=q]', n: 2 },
    { selector: sel2('location'), n: 3 },
    { selector: sel2('tags[]'), n: 4 },
    { selector: sel2('show_column[]'), n: 5 },
    { selector: 'button[data-modal-url*="asset_add"]', n: 6, side: 'tr' },
    { selector: 'a[href*="archived="]', n: 7, side: 'tr' },
  ]);
  await shot(page, 'documentation/11-01-assets-list');

  // 02 row detail: scrolled to the table with the row menu open and a type tab chosen
  await goto(page, `/agent/assets.php?client_id=${C}&type=network`);
  await page.locator('tbody tr').first().locator('button.btn-secondary[data-bs-toggle=dropdown]').click();
  await page.waitForTimeout(400);
  await co(page, [
    { selector: '.dropdown-menu.show', n: 1, side: 'tr' },
    { selector: 'tbody tr:first-child td:nth-child(3)', n: 2 },
  ]);
  await shot(page, 'documentation/11-02-assets-row-menu');

  // 03 all clients list
  await goto(page, '/agent/assets.php');
  await co(page, [
    { selector: sel2('client'), n: 1 },
    { selector: 'thead th:has-text("Client")', n: 2 },
  ]);
  await shot(page, 'documentation/11-03-assets-all-clients');

  // 04 extra columns
  await goto(page, `/agent/assets.php?client_id=${C}&type=server&show_column[]=Mac_Address&show_column[]=Purchase_Date&show_column[]=Warranty_Expire`);
  await co(page, [{ selector: 'thead th:nth-child(6)', n: 1 }, { selector: 'thead th:nth-child(7)', n: 2 }, { selector: 'thead th:nth-child(8)', n: 3 }]);
  await shot(page, 'documentation/11-04-assets-columns');

  // 05-09 New Asset tabs
  await goto(page, `/agent/assets.php?client_id=${C}`);
  await tall(page, 1150);
  await openModal(page, 'asset_add.php');
  await co(page, [
    { selector: '.modal.show .nav-pills', n: 1 },
    { selector: fg('Type'), n: 2 },
    { selector: fg('Name'), n: 3 },
    { selector: fg('Asset Tag'), n: 4 },
    { selector: fg('Serial Number'), n: 5 },
  ]);
  await shot(page, 'documentation/11-05-new-asset-details');
  await modalTab(page, 'Assignment');
  await co(page, [{ selector: fg('Location'), n: 1 }, { selector: fg('Physical Location'), n: 2 }, { selector: fg('Assign To'), n: 3 }, { selector: fg('Status'), n: 4 }]);
  await shot(page, 'documentation/11-06-new-asset-assignment');
  await modalTab(page, 'Network');
  await co(page, [{ selector: fg('Network'), n: 1 }, { selector: fg('IPv4 Address'), n: 2 }, { selector: fg('URI'), n: 3 }, { selector: fg('AnyDesk ID'), n: 4 }]);
  await shot(page, 'documentation/11-07-new-asset-network');
  await modalTab(page, 'Purchase');
  await co(page, [{ selector: fg('Vendor'), n: 1 }, { selector: fg('Purchase Reference'), n: 2 }, { selector: fg('Warranty Expire'), n: 3 }]);
  await shot(page, 'documentation/11-08-new-asset-purchase');
  await modalTab(page, 'Login');
  await co(page, [{ selector: fg('Username'), n: 1 }, { selector: fg('Password'), n: 2 }]);
  await shot(page, 'documentation/11-09-new-asset-login');
  await closeModal(page);

  // 10 bulk actions
  await goto(page, `/agent/assets.php?client_id=${C}`);
  await page.locator('tbody input.bulk-select').nth(0).check();
  await page.locator('tbody input.bulk-select').nth(1).check();
  await page.waitForTimeout(300);
  await openDropdown(page, '#bulkActionButton button');
  await co(page, [{ selector: '#bulkActionButton button', n: 1 }, { selector: '#bulkActionButton .dropdown-menu', n: 2 }]);
  await shot(page, 'documentation/11-10-assets-bulk');

  // 11 archived view
  await goto(page, `/agent/assets.php?client_id=${id.blp}&archived=1`);
  await shot(page, 'documentation/11-11-assets-archived');
}

async function assetPage(page) {
  const C = id.nsd;
  const A = asset('NSD-SRV-01');
  await page.setViewportSize({ width: 1680, height: 1000 });
  await goto(page, `/agent/asset_details.php?client_id=${C}&asset_id=${A}`);
  await co(page, [
    { selector: '#asset-details-content .col-md-3 .card:first-child .btn.float-end', n: 1, side: 'tr' },
    { selector: 'a[data-modal-tab=pills-notes]', n: 2 },
    { selector: '.breadcrumb + div .btn-primary, .breadcrumb ~ div .btn-primary', n: 3 },
    { selector: '.breadcrumb ~ div .btn-outline-primary', n: 4 },
    { selector: 'h3:has-text("Interfaces")', n: 5 },
    { selector: '#asset-details-content .col-md-3 .card-dark:has-text("Assignment")', n: 6 },
  ]);
  await shot(page, 'documentation/11-12-asset-page');
  await shot(page, 'documentation/11-13-asset-page-full', { fullPage: true });
  // New / Link menus
  await page.locator('.breadcrumb ~ div .btn-primary').first().click();
  await page.waitForTimeout(400);
  await co(page, [{ selector: '.dropdown-menu.show', n: 1 }]);
  await shot(page, 'documentation/11-14-asset-new-menu');
  await page.keyboard.press('Escape');
  await page.locator('body').click({ position: { x: 700, y: 500 } });
  await page.locator('.breadcrumb ~ div .btn-outline-primary').first().click();
  await page.waitForTimeout(400);
  await co(page, [{ selector: '.dropdown-menu.show', n: 1 }]);
  await shot(page, 'documentation/11-15-asset-link-menu');
  await page.locator('body').click({ position: { x: 700, y: 500 } });
  // interface add
  await tall(page, 1100);
  await openModal(page, 'asset_interface_add.php');
  await shot(page, 'documentation/11-16-interface-add');
  await closeModal(page);
  // edit: history tab
  await tall(page, 1100);
  await openModal(page, 'asset_edit.php');
  await modalTab(page, 'History');
  await shot(page, 'documentation/11-17-asset-edit-history');
  await closeModal(page);
  await page.setViewportSize({ width: 1440, height: 900 });
}


async function plain(page, url, name, items = [], opts = {}) {
  await goto(page, url);
  if (items.length) await co(page, items);
  await shot(page, `documentation/${name}`, opts);
}
async function modalShot(page, url, frag, name, items = [], { tab = null, h = 1100 } = {}) {
  await goto(page, url);
  await tall(page, h);
  await openModal(page, frag);
  if (tab) await modalTab(page, tab);
  if (items.length) await co(page, items);
  await shot(page, `documentation/${name}`);
  await closeModal(page);
}

async function records(page) {
  const C = id.nsd, G = id.gpm;
  await plain(page, `/agent/software.php?client_id=${C}`, '12-01-licenses-list', [
    { selector: 'button[data-modal-url*="software_add.php"]', n: 1, side: 'tr' },
    { selector: 'thead th:has-text("Seats")', n: 2 }, { selector: 'thead th:has-text("Expire")', n: 3 }]);
  await modalShot(page, `/agent/software.php?client_id=${C}`, 'software_add.php', '12-02-license-add', [{ selector: fg('Type'), n: 1 }]);
  await plain(page, `/agent/domains.php?client_id=${C}`, '12-03-domains-list', [{ selector: 'thead th:has-text("Expire")', n: 1 }]);
  const d = sql("select domain_id from domains where domain_name='birchwoodlaw.example'");
  await plain(page, `/agent/domain_details.php?client_id=${id.blp}&id=${d}`, '12-04-domain-page', [], { fullPage: true });
  await plain(page, `/agent/certificates.php?client_id=${C}`, '12-05-certificates-list', [{ selector: 'thead th:has-text("Expire")', n: 1 }]);
  await plain(page, `/agent/networks.php?client_id=${C}`, '12-06-networks-list', [{ selector: 'button[data-modal-url*="network_add.php"]', n: 1, side: 'tr' }]);
  await plain(page, `/agent/racks.php?client_id=${G}`, '12-07-racks', []);
  await plain(page, `/agent/printers.php?client_id=${C}`, '12-08-printers');
  await plain(page, `/agent/network_drives.php?client_id=${C}`, '12-09-network-drives');
  await plain(page, `/agent/services.php?client_id=${C}`, '12-10-services');
  await plain(page, `/agent/vendors.php?client_id=${C}`, '12-11-vendors', [{ selector: 'button[data-modal-url*="vendor_add.php"]', n: 1, side: 'tr' }]);
  await plain(page, `/agent/files.php?client_id=${C}`, '12-12-files', [], { fullPage: true });
  const doc = sql("select document_id from documents where document_name like 'NSD-SRV-01 Backup%'");
  await plain(page, `/agent/document_details.php?client_id=${C}&document_id=${doc}`, '12-13-document-page', [], { fullPage: true });
  await plain(page, `/agent/structured_docs.php?client_id=${C}`, '12-14-structured-docs', [{ selector: 'button:has-text("New")', n: 1, side: 'tr' }]);
  const sd = sql("select doc_id from structured_documents where doc_name='Main Office firewall'");
  await goto(page, `/agent/structured_doc.php?id=${sd}&client_id=${C}`);
  await page.locator('.js-sdv-reveal').first().click().catch(() => {});
  await page.waitForTimeout(700);
  await co(page, [{ selector: 'dl.row', n: 1 }, { selector: 'a.btn-primary:has-text("Edit"), button.btn-primary:has-text("Edit")', n: 2 }]);
  await shot(page, 'documentation/12-15-structured-doc-page');
  await plain(page, '/admin/structured_doc_types.php', '12-16-structured-doc-types', [{ selector: 'table', n: 1 }]);
  const t = sql("select type_id from structured_doc_types where type_name='Firewall'");
  await plain(page, `/admin/structured_doc_types.php?type_id=${t}`, '12-17-structured-doc-fields', [{ selector: 'table', n: 1 }]);
  const a = asset('NSD-SRV-01');
  await goto(page, `/agent/asset_details.php?client_id=${C}&asset_id=${a}`);
  await tall(page, 1100);
  await openModal(page, 'link_add.php');
  await shot(page, 'documentation/12-18-link-item');
  await closeModal(page);
}

async function kb(page) {
  await plain(page, '/agent/kb_articles.php', '13-01-kb-list', [
    { selector: 'input[name=q]', n: 1 }, { selector: '.kb-chapter >> nth=0', n: 2 },
    { selector: 'button[data-modal-url*="kb_category_manage"]', n: 3 }, { selector: '.dropdown.d-inline-block .btn-primary', n: 4, side: 'tr' },
    { selector: '#filterNeedsReview', n: 5 }]);
  await plain(page, '/agent/kb_articles.php?filter_needs_review=1', '13-02-kb-needs-review');
  await plain(page, '/agent/kb_articles.php?client_id=' + id.nsd, '13-03-kb-client-scope');
  const art = sql("select kb_article_id from kb_articles where kb_article_title='Imaging Server: Do and Do Not'");
  await plain(page, `/agent/kb_article.php?id=${art}&client_id=${id.nsd}`, '13-04-kb-article', [
    { selector: '.kb-reader-card', n: 1 }, { selector: '.card-sidebar >> nth=0', n: 2 }, { selector: 'a:has-text("Version History")', n: 3 },
    { selector: 'button:has-text("Review Schedule")', n: 4 }, { selector: 'button:has-text("Upload Attachment")', n: 5 }], { fullPage: true });
  await modalShot(page, `/agent/kb_article.php?id=${art}`, 'kb_article_edit.php', '13-05-kb-edit', [{ selector: fg('Title'), n: 1 }], {});
  await modalShot(page, `/agent/kb_article.php?id=${art}`, 'kb_article_review_edit.php', '13-06-kb-review', [], { h: 900 });
  const v = sql("select kb_article_id from kb_articles where kb_article_title='Password Reset Procedure'");
  await plain(page, `/agent/kb_article_versions.php?kb_article_id=${v}`, '13-07-kb-versions');
  await modalShot(page, '/agent/kb_articles.php', 'kb_category_manage.php', '13-08-kb-categories', [], { h: 900 });
  await modalShot(page, '/agent/kb_articles.php', 'kb_article_import_docx.php', '13-09-kb-import-docx', [], { h: 900 });
  await modalShot(page, '/agent/kb_articles.php', 'kb_article_add.php', '13-10-kb-new', [{ selector: fg('Title'), n: 1 }], {});
  const q = sql("select kb_article_id from kb_articles where kb_article_title='Quarterly Backup Test Procedure'");
  await plain(page, `/agent/kb_article.php?id=${q}`, '13-11-kb-embed-article', [{ selector: '.card-sidebar:has-text("Embeds")', n: 1 }], { fullPage: true });
}

async function creds(page) {
  const C = id.nsd;
  await goto(page, `/agent/credentials.php?client_id=${C}`);
  await co(page, [{ selector: 'button[data-modal-url*="credential_add.php"]', n: 1, side: 'tr' }, { selector: 'input[name=q]', n: 2 },
    { selector: 'a:has-text("Network devices")', n: 3 }, { selector: 'thead th:has-text("Password")', n: 4 }, { selector: 'thead th:has-text("OTP")', n: 5 }]);
  await shot(page, 'documentation/14-01-credentials-list');
  await plain(page, `/agent/credentials.php?client_id=${C}&folder_id=${sql("select folder_id from folders where folder_name='Network devices' limit 1")}`, '14-02-credentials-folder');
  await plain(page, '/agent/credentials.php', '14-03-credentials-all-clients');
  const cid = sql("select credential_id from credentials where credential_name like 'Edge Firewall%'");
  await goto(page, `/agent/credentials.php?client_id=${C}&folder_id=${sql("select folder_id from folders where folder_name='Network devices' limit 1")}`);
  await tall(page, 1100);
  await openModal(page, `credential_view.php?id=${cid}`);
  await shot(page, 'documentation/14-04-credential-view');
  await closeModal(page);
  await modalShot(page, `/agent/credentials.php?client_id=${C}`, 'credential_add.php', '14-05-credential-add', [{ selector: fg('Password'), n: 1 }, { selector: fg('Rotation'), n: 2 }]);
  await plain(page, '/agent/reports/credential_rotation.php', '14-06-rotation-report');
  await plain(page, '/admin/settings_security.php', '14-07-settings-security', []);
  await plain(page, '/admin/credential_restore.php', '14-08-credential-restore');
  await modalShot(page, `/agent/credentials.php?client_id=${C}`, 'credential_import.php', '14-09-credential-import', [], { h: 900 });
  // reveal one value (a Reveal / Copy audit entry is written to the activity log)
  await goto(page, `/agent/credentials.php?client_id=${C}`);
  await page.locator('.js-cred-reveal').nth(2).click();
  await page.waitForTimeout(1200);
  await co(page, [{ selector: 'tbody tr:nth-child(2) td:nth-child(3)', n: 1 }]);
  await shot(page, 'documentation/14-10-credential-revealed');
  // share link modal
  await goto(page, `/agent/credentials.php?client_id=${C}`);
  await page.evaluate(() => { const el = document.querySelector('.share-link-trigger'); el.click(); });
  await page.waitForSelector('#shareModal.show', { timeout: 8000 });
  await page.waitForTimeout(700);
  await shot(page, 'documentation/14-11-share-link');
  await page.keyboard.press('Escape');
  // edit modal history tab
  await goto(page, `/agent/credentials.php?client_id=${C}&folder_id=${sql("select folder_id from folders where folder_name='Network devices' limit 1")}`);
  await tall(page, 1100);
  await openModal(page, `credential_edit.php?id=${cid}`);
  await modalTab(page, 'History');
  await shot(page, 'documentation/14-12-credential-history');
  await closeModal(page);
  // vault locked for a role without the credential module (Read-only viewer has none): show Billing clerk's missing menu entry
}

(async () => {
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');
    if (want('assets')) await assets(page);
    if (want('assetpage')) await assetPage(page);
    if (want('records')) await records(page);
    if (want('kb')) await kb(page);
    if (want('creds')) await creds(page);
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
