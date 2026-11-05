// Screenshots for the CRM guide pages:
//   21-crm-leads-and-pipeline.md   -> images/crm/01..
//   22-campaigns-and-segments.md   -> images/crm/..
//
// Run:  cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/crm.cjs
//       (GUIDE_CLEAN=1 writes the call-out-free copies to images-clean/crm/)
//
// READ-ONLY: it opens pages, dialogs and menus and never submits a form that saves or sends. Drag and drop on the
// boards is not performed (it would change the demo data). Data comes from seeds 60-63; everything is found by
// NAME (client, contact, campaign), never by numeric id, so the script keeps working on a rebuilt database.

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');

const G = 'crm';
let n = 0;
const next = (slug) => `${G}/${String(++n).padStart(2, '0')}-${slug}`;

async function assertClean(page, label) {
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Parse error|Undefined (variable|array key|index))/);
  if (bad) throw new Error(`${label}: page shows a PHP problem (${bad[0]})`);
}
async function open(page, url, expect) {
  await goto(page, url);
  await assertClean(page, url);
  if (expect) {
    const text = await page.locator('body').innerText();
    for (const s of [].concat(expect)) if (!text.toLowerCase().includes(s.toLowerCase())) throw new Error(`${url}: expected "${s}"`);
  }
}
/** Tag an element so callout() (plain CSS selectors) can find it. */
async function mark(locator, name) {
  if ((await locator.count()) === 0) throw new Error(`mark(${name}): element not found`);
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), name);
  return `[data-ug="${name}"]`;
}
const MODAL = '.modal.show .modal-content';
async function waitModal(page, ms = 900) {
  await page.waitForSelector(MODAL, { timeout: 15000 });
  await page.waitForTimeout(ms);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  await page.mouse.move(2, 2);
}
async function wide(page, w, fn) {
  const vp = page.viewportSize();
  await page.setViewportSize({ width: w, height: vp.height });
  await page.waitForTimeout(400);
  try { await fn(); } finally { await page.setViewportSize(vp); await page.waitForTimeout(300); }
}
async function tall(page, fn) {
  const vp = page.viewportSize();
  await page.setViewportSize({ width: vp.width, height: 1400 });
  await page.waitForTimeout(400);
  try { await fn(); } finally { await page.setViewportSize(vp); await page.waitForTimeout(300); }
}
async function openRowMenu(page, name) {
  const row = page.locator('tr', { hasText: name }).first();
  await row.locator('button[data-bs-toggle="dropdown"]').last().click();
  await page.waitForTimeout(400);
  return row;
}

(async () => {
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');

    // ================= Page 21: leads and pipeline =================

    // 01 Leads list
    await open(page, '/agent/clients.php?leads=1', ['Harborview Chiropractic', 'Lodestar Freight Brokers']);
    await callout(page, [
      { selector: await mark(page.locator('a.btn:has-text("Leads")').first(), 'tog-leads'), n: 1 },
      { selector: await mark(page.locator('a.btn:has-text("Board")').first(), 'tog-board'), n: 2 },
      { selector: await mark(page.locator('button:has-text("New Lead")').first(), 'new-lead'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('th:has-text("Lead")').first(), 'th-lead'), n: 4 },
    ]);
    await shot(page, next('leads-list'));
    await clearCallouts(page);

    // 02 Row menu with Convert to Client
    await openRowMenu(page, 'Lodestar Freight Brokers');
    await callout(page, [
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("Edit")').first(), 'm-edit'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("Convert to Client")').first(), 'm-convert'), n: 2, side: 'tr' },
    ]);
    await shot(page, next('lead-row-menu'));
    await clearCallouts(page);
    await page.keyboard.press('Escape');

    // 03 New lead dialog, Details tab (tall window so the whole dialog fits)
    await page.setViewportSize({ width: 1440, height: 1250 });
    await page.waitForTimeout(300);
    await page.locator('button:has-text("New Lead")').first().click();
    await waitModal(page);
    await page.fill(`${MODAL} input[name=name]`, 'Cobblestone Dental Studio');
    await page.fill(`${MODAL} input[name=lead_source]`, 'Website form');
    await page.fill(`${MODAL} input[name=lead_score]`, '40');
    await callout(page, [
      { selector: await mark(page.locator(`${MODAL} input[name=name]`), 'f-name'), n: 1, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=lead]`).locator('xpath=ancestor::div[contains(@class,"input-group-text")]'), 'f-islead'), n: 2, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=lead_source]`), 'f-source'), n: 3, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=lead_status]`).locator('xpath=..'), 'f-status'), n: 4, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=lead_owner]`).locator('xpath=..'), 'f-owner'), n: 5, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=lead_score]`), 'f-score'), n: 6, side: 'tr' },
    ]);
    // the modal is tall: scroll the dialog so the Lead Details box is in view
    await page.locator(`${MODAL} input[name=lead_score]`).scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await shot(page, next('lead-new-dialog'), { selector: MODAL });
    await clearCallouts(page);
    await closeModal(page);

    // 04 Edit lead dialog with the Lead Status list open
    await open(page, '/agent/clients.php?leads=1&q=Tamarack');
    await tall(page, async () => {
    await openRowMenu(page, 'Tamarack Engineering');
    await page.locator('.dropdown-menu.show a:has-text("Edit")').first().click();
    await waitModal(page);
    await page.locator(`${MODAL} select[name=lead_status]`).locator('xpath=..').locator('.ts-control').first().click();
    await page.waitForTimeout(600);
    await page.locator(`${MODAL} select[name=lead_status]`).scrollIntoViewIfNeeded();
    await callout(page, [
      { selector: await mark(page.locator(`${MODAL} select[name=lead_status]`).locator('xpath=..').locator('.ts-dropdown').first(), 'ls-list'), n: 1, side: 'tr' },
    ]);
    await shot(page, next('lead-status-list'), { selector: MODAL });
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await closeModal(page);
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(300);

    // 05 The Leads Board
    await open(page, '/agent/leads_board.php', ['Lead pipeline', 'Convert to client']);
    await wide(page, 1760, async () => {
      await callout(page, [
        { selector: await mark(page.locator('a:has-text("List view")').first(), 'b-list'), n: 1 },
        { selector: await mark(page.locator('button:has-text("Lead")').first(), 'b-new'), n: 2, side: 'tr' },
        { selector: await mark(page.locator('.lb-col').nth(0).locator('.lb-col-head'), 'b-col-new'), n: 3 },
        { selector: await mark(page.locator('.lb-card').first(), 'b-card'), n: 4 },
        { selector: await mark(page.locator('.lb-col').nth(5).locator('.lb-col-head'), 'b-col-lost'), n: 5 },
        { selector: await mark(page.locator('#leadsConvert'), 'b-convert'), n: 6 },
      ]);
      await shot(page, next('leads-board'));
      await clearCallouts(page);
    });

    // 06 Opportunities list
    await open(page, '/agent/opportunities.php', ['Plant network refresh']);
    await callout(page, [
      { selector: await mark(page.locator('a.btn:has-text("Pipeline")').first(), 'o-pipe'), n: 1 },
      { selector: await mark(page.locator('button:has-text("New Opportunity")').first(), 'o-new'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('select[name=stage]').locator('xpath=..'), 'o-stage'), n: 3 },
      { selector: await mark(page.locator('.btn-group:has(a:has-text("Won"))').first(), 'o-status'), n: 4 },
      { selector: await mark(page.locator('th:has-text("Weighted")').first(), 'o-weighted'), n: 5 },
    ]);
    await shot(page, next('opportunities-list'));
    await clearCallouts(page);

    // 07 New opportunity dialog
    await page.locator('button:has-text("New Opportunity")').first().click();
    await waitModal(page);
    await page.fill(`${MODAL} input[name=name]`, 'Office Wi-Fi upgrade');
    await page.fill(`${MODAL} input[name=amount]`, '6400');
    await callout(page, [
      { selector: await mark(page.locator(`${MODAL} input[name=name]`), 'op-name'), n: 1, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=client_id]`).locator('xpath=..'), 'op-client'), n: 2, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=contact_id]`).locator('xpath=..'), 'op-contact'), n: 3, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=stage]`).locator('xpath=..'), 'op-stage'), n: 4, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=probability]`).locator('xpath=..'), 'op-prob'), n: 5, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=amount]`).locator('xpath=..'), 'op-amount'), n: 6, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} input[name=close_date]`).locator('xpath=..'), 'op-close'), n: 7, side: 'tr' },
      { selector: await mark(page.locator(`${MODAL} select[name=owner]`).locator('xpath=..'), 'op-owner'), n: 8, side: 'tr' },
    ]);
    await shot(page, next('opportunity-new-dialog'));
    await clearCallouts(page);
    await closeModal(page);

    // 08 Pipeline
    await open(page, '/agent/pipeline.php', ['Pipeline Value', 'Weighted Forecast', 'Won This Month']);
    await wide(page, 1760, async () => {
      await callout(page, [
        { selector: await mark(page.locator('.card:has-text("Pipeline Value") .card-body').first(), 'p-value'), n: 1 },
        { selector: await mark(page.locator('.card:has-text("Weighted Forecast") .card-body').first(), 'p-weighted'), n: 2 },
        { selector: await mark(page.locator('.card:has-text("Won This Month") .card-body').first(), 'p-won'), n: 3 },
        { selector: await mark(page.locator('#pipeline-board .kanban-column').nth(2).locator('h6'), 'p-col'), n: 4 },
        { selector: await mark(page.locator('#pipeline-board .task').first(), 'p-card'), n: 5 },
        { selector: await mark(page.locator('a:has-text("List View")').first(), 'p-list'), n: 6 },
      ]);
      await shot(page, next('pipeline'));
      await clearCallouts(page);
    });

    // 09 Edit opportunity dialog (double-click a card)
    await page.locator('#pipeline-board .task', { hasText: 'Plant network refresh' }).first().dblclick();
    await waitModal(page);
    await shot(page, next('opportunity-edit-dialog'), { selector: MODAL });
    await closeModal(page);

    // 10 Client overview: Open Opportunities card
    await open(page, '/agent/clients.php?q=Granite');
    const gp = await page.locator('a[href*="client_overview.php?client_id="]', { hasText: 'Granite Peak Manufacturing' }).first().getAttribute('href');
    await open(page, `/agent/${gp}`, ['Open Opportunities']);
    const card = page.locator('.card:has(h5:has-text("Open Opportunities"))').first();
    await card.evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 140));
    await page.waitForTimeout(300);
    await callout(page, [
      { selector: await mark(card.locator('a:has-text("View all")'), 'co-all'), n: 1 },
      { selector: await mark(card.locator('button:has-text("Add Opportunity")'), 'co-add'), n: 2, side: 'tr' },
      { selector: await mark(card.locator('tbody tr').first(), 'co-row'), n: 3 },
    ]);
    await shot(page, next('client-overview-opportunities'));
    await clearCallouts(page);

    // 11 Contact page: Activity Timeline
    await open(page, '/agent/clients.php?leads=1&q=Lodestar');
    const lf = await page.locator('a[href*="client_overview.php?client_id="]', { hasText: 'Lodestar Freight Brokers' }).first().getAttribute('href');
    const lodestarId = lf.match(/client_id=(\d+)/)[1];
    await open(page, `/agent/contacts.php?client_id=${lodestarId}`, ['Vivian Tran']);
    const vt = await page.locator('a[href*="contact_details.php"]', { hasText: 'Vivian Tran' }).first().getAttribute('href');
    await open(page, `/agent/${vt}`, ['Activity Timeline', 'Contract terms']);
    const tl = page.locator('.card:has(h3:has-text("Activity Timeline"))').first();
    await tl.evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 140));
    await page.waitForTimeout(300);
    await callout(page, [
      { selector: await mark(tl.locator('button:has-text("Log Activity")').first(), 'a-log'), n: 1, side: 'tr' },
      { selector: await mark(tl.locator('.media').first(), 'a-entry'), n: 2 },
      { selector: await mark(tl.locator('.crm-complete-activity').first(), 'a-complete'), n: 3 },
    ]);
    await shot(page, next('activity-timeline'));
    await clearCallouts(page);

    // 12 Log Activity dialog
    await tl.locator('button:has-text("Log Activity")').first().click();
    await page.waitForSelector('#logActivityModal.show', { timeout: 10000 });
    await page.waitForTimeout(700);
    await page.selectOption('#logActivityModal select[name=activity_type]', 'call');
    await page.fill('#logActivityModal input[name=subject]', 'Walk through the signing steps');
    await callout(page, [
      { selector: await mark(page.locator('#logActivityModal select[name=activity_type]'), 'l-type'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('#logActivityModal input[name=subject]'), 'l-subject'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('#logActivityModal textarea[name=body]'), 'l-body'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('#logActivityModal input[name=due_at]'), 'l-due'), n: 4, side: 'tr' },
      { selector: await mark(page.locator('#logActivityModal input[name=reminder_at]'), 'l-rem'), n: 5, side: 'tr' },
    ]);
    await shot(page, next('log-activity-dialog'), { selector: '#logActivityModal .modal-content' });
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    // 13 Notifications page with the CRM follow-up
    await open(page, '/agent/notifications.php', ['CRM Follow-up']);
    await callout(page, [
      { selector: await mark(page.locator('tr:has-text("CRM Follow-up"), .list-group-item:has-text("CRM Follow-up"), .card:has-text("CRM Follow-up")').last(), 'n-row'), n: 1 },
    ]);
    await shot(page, next('crm-notification'));
    await clearCallouts(page);

    // ================= Page 22: segments and campaigns =================

    // 14 Segments page
    await open(page, '/agent/segments.php', ['Saved Segments', 'Security newsletter']);
    await callout(page, [
      { selector: await mark(page.locator('input[name=name]').first(), 's-name'), n: 1 },
      { selector: await mark(page.locator('#seg_tag_id').locator('xpath=..'), 's-tag'), n: 2 },
      { selector: await mark(page.locator('#seg_client_id').locator('xpath=..'), 's-client'), n: 3 },
      { selector: await mark(page.locator('#seg_lead_status'), 's-lead'), n: 4 },
      { selector: await mark(page.locator('#seg_has_open_opportunity').locator('xpath=..'), 's-open'), n: 5 },
      { selector: await mark(page.locator('.alert:has-text("Matches")'), 's-matches'), n: 6 },
      { selector: await mark(page.locator('.card:has-text("Saved Segments") table').first(), 's-saved'), n: 7 },
    ]);
    await shot(page, next('segments'));
    await clearCallouts(page);

    // 15 Building a segment: pick Leads only, live count updates
    await page.selectOption('#seg_lead_status', 'lead');
    await page.waitForTimeout(1200);
    await page.fill('input[name=name]', 'Prospects');
    await callout(page, [
      { selector: await mark(page.locator('#seg_lead_status'), 's2-lead'), n: 1 },
      { selector: await mark(page.locator('.alert:has-text("Matches")'), 's2-matches'), n: 2 },
      { selector: await mark(page.locator('#seg_preview_list'), 's2-list'), n: 3 },
    ]);
    await shot(page, next('segment-live-preview'));
    await clearCallouts(page);

    // 16 Segment row menu
    await page.reload({ waitUntil: 'domcontentloaded' });
    await settle(page);
    await openRowMenu(page, 'Customers with an open deal');
    await callout(page, [
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("New Campaign")').first(), 'sm-new'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("Delete")').first(), 'sm-del'), n: 2, side: 'tr' },
    ]);
    await shot(page, next('segment-row-menu'));
    await clearCallouts(page);
    await page.keyboard.press('Escape');

    // 17 Campaigns: new campaign from a segment
    await open(page, '/agent/campaigns.php', ['Campaigns', 'Fall security checkup']);
    await page.evaluate(() => {
      const sel = document.querySelector('#campaign_segment_id');
      const opt = [...sel.options].find((o) => o.text.trim() === 'Customers with an open deal');
      if (sel.tomselect) sel.tomselect.setValue(opt.value); else { sel.value = opt.value; sel.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    await page.waitForTimeout(1200);
    await page.fill('input[name=name]', 'Spring Wi-Fi tune-up');
    await page.fill('input[name=subject]', 'Book your free Wi-Fi tune-up');
    await callout(page, [
      { selector: await mark(page.locator('input[name=name]').first(), 'c-name'), n: 1 },
      { selector: await mark(page.locator('#campaign_segment_id').locator('xpath=..'), 'c-seg'), n: 2 },
      { selector: await mark(page.locator('input[name=subject]'), 'c-subj'), n: 3 },
      { selector: await mark(page.locator('textarea[name=body]'), 'c-body'), n: 4 },
      { selector: await mark(page.locator('button:has-text("Save Draft")'), 'c-save'), n: 5 },
      { selector: await mark(page.locator('.card:has-text("Campaigns") table').last(), 'c-list'), n: 6 },
    ]);
    await shot(page, next('campaigns-new'));
    await clearCallouts(page);

    // 18 Draft campaign: Send Campaign and its confirmation (not confirmed)
    await open(page, '/agent/campaigns.php');
    await page.locator('a:has-text("Welcome to Lakeshore Managed IT")').first().click();
    await settle(page);
    await page.waitForSelector('button:has-text("Send Campaign")');
    await page.waitForTimeout(1200);
    await callout(page, [
      { selector: await mark(page.locator('#campaign_segment_id').locator('xpath=..'), 'd-seg'), n: 1 },
      { selector: await mark(page.locator('#campaign_recipient_preview'), 'd-recips'), n: 2 },
      { selector: await mark(page.locator('button:has-text("Update Draft")'), 'd-update'), n: 3 },
      { selector: await mark(page.locator('button:has-text("Send Campaign")'), 'd-send'), n: 4 },
    ]);
    await shot(page, next('campaign-draft'));
    await clearCallouts(page);

    await page.locator('button:has-text("Send Campaign")').click();
    await page.waitForTimeout(900);
    await shot(page, next('campaign-send-confirm'));
    // cancel, never send
    const cancel = page.locator('.modal.show button:has-text("Cancel"), .rivet-dialog button:has-text("Cancel"), dialog button:has-text("Cancel")').first();
    if (await cancel.count()) await cancel.click(); else await page.keyboard.press('Escape');
    await page.waitForTimeout(500);

    // 19 Sent campaign (read-only)
    await open(page, '/agent/campaigns.php', ['Fall security checkup']);
    await page.locator('a:has-text("Fall security checkup")').first().click();
    await settle(page);
    await page.waitForSelector('text=It is now read-only');
    await page.waitForTimeout(1000);
    await callout(page, [
      { selector: await mark(page.locator('.alert-success').first(), 'sent-alert'), n: 1 },
      { selector: await mark(page.locator('.card:has-text("Campaigns") .badge:has-text("Sent")').first(), 'sent-status'), n: 2 },
      { selector: await mark(page.locator('.card:has-text("Campaigns") tr:has-text("Fall security checkup") td').nth(3), 'sent-count'), n: 3 },
    ]);
    await shot(page, next('campaign-sent'));
    await clearCallouts(page);

    // 20 Mail queue shows the messages the campaign queued
    await open(page, '/admin/mail_queue.php');
    await assertClean(page, 'mail_queue');
    await shot(page, next('mail-queue'));

    // 21 Notification preferences: the CRM follow-ups switch
    await open(page, '/agent/user/user_preferences.php', ['CRM follow-ups']);
    const crmRow = page.locator('#inapp-notifications label:has-text("CRM follow-ups")').first();
    await crmRow.scrollIntoViewIfNeeded();
    await callout(page, [
      { selector: await mark(crmRow.locator('xpath=ancestor::div[contains(@class,"d-flex")][1]'), 'pref-crm'), n: 1 },
    ]);
    await shot(page, next('notification-preferences'), { selector: '#inapp-notifications' });
    await clearCallouts(page);
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
