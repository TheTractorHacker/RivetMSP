// Screenshots for the Integrations, API and mobile guide pages (group G11):
//   29-integrations.md                  -> images/integrations/
//   30-mail-intake-and-sending.md       -> images/integrations/
//   30b-webhooks-event-rules-and-jobs.md-> images/integrations/
//   30c-ai-and-remote-mcp.md            -> images/integrations/
//   31-api-and-mobile.md                -> images/integrations/
//
// Run:  cd /home/user/rivetmsp && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/integrations.cjs
//       (GUIDE_CLEAN=1 writes the call-out-free copies to images-clean/integrations/; ONLY=api limits to one section:
//        integrations | mail | webhooks | ai | api)
//
// Data comes from seeds 95-99 (and 69 for the vendor RMM / UniFi rows). Everything is found by NAME, never by numeric id.
// WRITES: only the API section writes anything. It creates three throwaway API keys through the real "New API Key" dialog
// (names start with "Guide example") and, at the end, revokes and deletes every key whose name starts with that prefix, so a second run
// starts clean. Nothing else is saved: dialogs are closed with Escape, the webhook wizard is never submitted.
// The curl examples in 31-api-and-mobile.md were run by hand against a private copy of the app (see the page, "How these examples were checked").

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');
const fs = require('fs');
const path = require('path');

const G = 'integrations';
const ONLY = (process.env.ONLY || '').toLowerCase();
const want = (s) => !ONLY || ONLY === s;
let n = 0;
const next = (slug) => `${G}/${String(++n).padStart(2, '0')}-${slug}`;
const failures = [];

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
async function tall(page, h, fn) {
  const vp = page.viewportSize();
  await page.setViewportSize({ width: vp.width, height: h });
  await page.waitForTimeout(400);
  try { await fn(); } finally { await page.setViewportSize(vp); await page.waitForTimeout(300); }
}
/** Run one screenshot step; a failure is reported at the end and does not stop the others. */
async function step(label, fn) {
  try { await fn(); } catch (e) { failures.push(`${label}: ${e.message}`); console.error('FAILED', label, e.message); }
}
const c = (page, items) => callout(page, items);

(async () => {
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');

    // ===================== 29 Integrations =====================
    if (want('integrations')) {
      n = 0; // numbers are fixed per section so ONLY=<section> reruns overwrite the same files
      await step('settings directory', async () => {
        await open(page, '/admin/settings.php', ['Connections & data']);
        await tall(page, 1700, async () => {
          await page.locator('h4:has-text("Connections & data"), h3:has-text("Connections & data"), h2:has-text("Connections & data")').first().scrollIntoViewIfNeeded();
          const tile = (t) => page.locator('a', { hasText: t }).filter({ hasText: /./ }).first();
          await c(page, [
            { selector: await mark(tile('Integrations'), 't-int'), n: 1, side: 'tr' },
            { selector: await mark(tile('Calendar sync'), 't-cal'), n: 2, side: 'tr' },
            { selector: await mark(tile('Webhooks'), 't-wh'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('a', { hasText: 'Configure AI features' }).first(), 't-ai'), n: 4, side: 'tr' },
            { selector: await mark(tile('Remote MCP'), 't-mcp'), n: 5, side: 'tr' },
          ]);
          await shot(page, next('settings-connections'), { fullPage: true });
          await clearCallouts(page);
        });
      });

      await step('hub rmm tab', async () => {
        await open(page, '/admin/settings_integrations.php?tab=rmm', ['RMM Integration Settings']);
        await tall(page, 800, async () => {
          await c(page, [
            { selector: await mark(page.locator('#integrationsTabs a:has-text("RMM")').first(), 'tab-rmm'), n: 1 },
            { selector: await mark(page.locator('#integrationsTabs a:has-text("Backups")').first(), 'tab-bk'), n: 2 },
            { selector: await mark(page.locator('#integrationsTabs a:has-text("Firewalls")').first(), 'tab-fw'), n: 3 },
            { selector: await mark(page.locator('#integrationsTabs a:has-text("UniFi")').first(), 'tab-unifi'), n: 4 },
            { selector: await mark(page.locator('#integrationsTabs a:has-text("Accounting")').first(), 'tab-acc'), n: 5 },
            { selector: await mark(page.locator('#integrationsTabs a:has-text("Pax8")').first(), 'tab-pax'), n: 6, side: 'tr' },
            { selector: await mark(page.locator('#rmm_module_enabled'), 'sw-rmm'), n: 7, side: 'tr' },
            { selector: await mark(page.locator('.card:has(h3:has-text("Automatic Ticket Creation"))').first(), 'card-auto'), n: 8, side: 'tr' },
          ]);
          await shot(page, next('hub-rmm-tab'));
          await clearCallouts(page);
        });
        await tall(page, 1100, async () => {
          const list = page.locator('.card:has(h3:has-text("RMM Integrations"))').first();
          await list.scrollIntoViewIfNeeded();
          await c(page, [
            { selector: await mark(page.locator('button:has-text("Add Integration")').first(), 'b-addi'), n: 1 },
            { selector: await mark(list, 'card-list'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('.card:has(h3:has-text("Client mapping"))').first(), 'card-map'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('.card:has(h3:has-text("Stale assets"))').first(), 'card-stale'), n: 4, side: 'tr' },
          ]);
          await shot(page, next('hub-rmm-integrations'));
          await clearCallouts(page);
        });
      });

      await step('rmm add integration dialog', async () => {
        await open(page, '/admin/settings_integrations.php?tab=rmm');
        await page.locator('button:has-text("Add Integration")').first().click();
        await waitModal(page);
        await c(page, [
          { selector: await mark(page.locator(`${MODAL} label:has-text("Tactical")`).first(), 'm-tactical'), n: 1 },
          { selector: await mark(page.locator(`${MODAL} label:has-text("Level")`).first(), 'm-level'), n: 2 },
        ]);
        await shot(page, next('rmm-add-dialog'), { selector: MODAL });
        await clearCallouts(page);
        await closeModal(page);
      });

      await step('unifi tab', async () => {
        await open(page, '/admin/settings_integrations.php?tab=unifi', ['UniFi Integration Settings']);
        await tall(page, 1250, async () => {
          await c(page, [
            { selector: await mark(page.locator('#unifi_module_enabled, input[name=config_module_enable_unifi]').first(), 'sw-unifi'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('h3:has-text("UniFi Controllers")').locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," card ")][1]'), 'card-ctrl'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('h3:has-text("Site")').locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," card ")][1]'), 'card-site'), n: 3, side: 'tr' },
          ]);
          await shot(page, next('hub-unifi-tab'));
          await clearCallouts(page);
        });
      });

      await step('intune per client', async () => {
        await open(page, '/agent/clients.php?q=Cedar', ['Cedar & Pine Architects']);
        const href = await page.locator('a:has-text("Cedar & Pine Architects")').first().getAttribute('href');
        const cid = (href.match(/client_id=(\d+)/) || [])[1];
        await tall(page, 1200, async () => {
          await open(page, `/agent/intune_settings.php?client_id=${cid}`, ['Tenant Credentials', 'LT-CPA-04']);
          await c(page, [
            { selector: await mark(page.locator('.nav-link:has-text("Microsoft 365")').first(), 'nav-m365'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('input[name=tenant_id]'), 'f-tenant'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('input[name=azure_client_id]'), 'f-app'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('input[name=client_secret]'), 'f-secret'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('#msIntuneSyncEnabled').locator('xpath=..'), 'sw-intune'), n: 5, side: 'tr' },
            { selector: await mark(page.locator('button:has-text("Sync Now")').first(), 'btn-sync'), n: 6 },
          ]);
          await shot(page, next('intune-client'), { fullPage: true });
          await clearCallouts(page);
        });
      });

      await step('calendar sync admin', async () => {
        await open(page, '/admin/settings_calendar_sync.php', ['Azure App Credentials', 'Credentials saved']);
        await tall(page, 1050, async () => {
          await c(page, [
            { selector: await mark(page.locator('input[name=outlook_cal_tenant_id]'), 'f-t'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('input[name=outlook_cal_client_id]'), 'f-a'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('input[name=outlook_cal_client_secret]'), 'f-s'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('#redirect_uri_field'), 'f-r'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('button[name=save_outlook_cal_settings]'), 'b-save'), n: 5 },
          ]);
          await shot(page, next('calendar-sync-admin'));
          await clearCallouts(page);
        });
      });

      await step('calendar sync user', async () => {
        await open(page, '/agent/user/user_integrations.php', ['Outlook Calendar Sync']);
        await c(page, [
          { selector: await mark(page.locator('.card:has-text("My Calendar Color")').first(), 'card-color'), n: 1, side: 'tr' },
          { selector: await mark(page.locator('a:has-text("Connect Outlook Calendar"), button:has-text("Connect Outlook Calendar")').first(), 'btn-connect'), n: 2 },
        ]);
        await shot(page, next('calendar-sync-user'));
        await clearCallouts(page);
      });
    }

    // ===================== 30 Mail =====================
    if (want('mail')) {
      n = 10; // numbers are fixed per section so ONLY=<section> reruns overwrite the same files
      await step('mail settings', async () => {
        await open(page, '/admin/settings_mail.php', ['SMTP Mail Settings']);
        await c(page, [
          { selector: await mark(page.locator('select').first(), 'sel-smtp'), n: 1, side: 'tr' },
          { selector: await mark(page.locator('h3:has-text("IMAP"), h4:has-text("IMAP"), .card-title:has-text("IMAP")').first(), 'h-imap'), n: 2, side: 'tr' },
        ]);
        await shot(page, next('mail-settings'), { fullPage: true });
        await clearCallouts(page);
      });

      await step('mailboxes', async () => {
        await open(page, '/admin/mailbox.php', ['Mailboxes', 'Support inbox']);
        await tall(page, 1500, async () => {
          await c(page, [
            { selector: await mark(page.locator('button:has-text("Add Mailbox")').first(), 'b-add'), n: 1 },
            { selector: await mark(page.locator('button:has-text("Check Shared Mailbox Access")').first(), 'b-check'), n: 2 },
            { selector: await mark(page.locator('th:has-text("Health")').first(), 'th-health'), n: 3 },
            { selector: await mark(page.locator('.card:has-text("Quarantined mail")').last(), 'card-q'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('.card:has-text("Mail intake settings")').last(), 'card-s'), n: 5, side: 'tr' },
          ]);
          await shot(page, next('mailboxes'), { fullPage: true });
          await clearCallouts(page);
        });
      });

      await step('mailbox add dialog', async () => {
        await open(page, '/admin/mailbox.php');
        await tall(page, 1250, async () => {
          await page.locator('button:has-text("Add Mailbox")').first().click();
          await waitModal(page);
          await shot(page, next('mailbox-add'), { selector: MODAL });
          await closeModal(page);
        });
      });

      await step('email log', async () => {
        await open(page, '/admin/email_log.php', ['Email Log', 'Suppressed']);
        await c(page, [
          { selector: await mark(page.locator('select').first(), 'sel-outcome'), n: 1, side: 'tr' },
          { selector: await mark(page.locator('table').first(), 'tbl-log'), n: 2, side: 'tr' },
        ]);
        await shot(page, next('email-log'), { fullPage: true });
        await clearCallouts(page);
      });

      await step('mail queue', async () => {
        await open(page, '/admin/mail_queue.php', ['Email Queue']);
        await shot(page, next('mail-queue'), { fullPage: true });
      });

      await step('email templates', async () => {
        await open(page, '/admin/settings_mail_templates.php', ['Ticket created', 'Shared header and footer']);
        await tall(page, 1400, async () => {
          await c(page, [
            { selector: await mark(page.locator('.mail-template-card').first(), 'mt-first'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('.mail-template-card .badge').first(), 'mt-badge'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('h3:has-text("Shared header and footer")').first().locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," card ")][1]'), 'mt-shared'), n: 3, side: 'tr' },
          ]);
          await shot(page, next('email-templates'));
          await clearCallouts(page);
        });
      });

      await step('email template open', async () => {
        await open(page, '/admin/settings_mail_templates.php');
        await page.locator('.mt-card-toggle').first().click();
        await page.waitForTimeout(1200);
        await tall(page, 1400, async () => {
          await c(page, [
            { selector: await mark(page.locator('.mail-template-card').first().locator('input[name=enabled]').locator('xpath=..'), 'mt-send'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('.mail-template-card').first().locator('.mt-subject-input'), 'mt-subj'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('.mail-template-card').first().locator('.mt-subject-placeholder-btn').first(), 'mt-ph'), n: 3 },
          ]);
          await shot(page, next('email-template-open'), { selector: '.mail-template-card >> nth=0' });
          await clearCallouts(page);
        });
      });
    }

    if (want('mail')) {
      n = 17;
      await step('shared mailbox check dialog', async () => {
        await open(page, '/admin/mailbox.php');
        await tall(page, 1100, async () => {
          await page.locator('button:has-text("Check Shared Mailbox Access")').first().click();
          await waitModal(page);
          await shot(page, next('mailbox-check-access'), { selector: MODAL });
          await closeModal(page);
        });
      });
    }

    // ===================== 30b Webhooks, event rules, job queue =====================
    if (want('webhooks')) {
      n = 20; // numbers are fixed per section so ONLY=<section> reruns overwrite the same files
      await step('webhooks list', async () => {
        await open(page, '/admin/settings_webhooks.php', ['Dispatch board (n8n)']);
        await tall(page, 1450, async () => {
          await c(page, [
            { selector: await mark(page.locator('a:has-text("Add Webhook")').first(), 'b-add'), n: 1 },
            { selector: await mark(page.locator('a:has-text("Guides")').first(), 'b-guides'), n: 2 },
            { selector: await mark(page.locator('th:has-text("Last delivery")').first(), 'th-last'), n: 3 },
            { selector: await mark(page.locator('.whl-switch').first(), 'sw-on'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('#internal-networks, .card:has-text("Internal network access")').first(), 'card-net'), n: 5, side: 'tr' },
            { selector: await mark(page.locator('.card:has-text("Every attempt, including retries")').last(), 'card-del'), n: 6, side: 'tr' },
          ]);
          await shot(page, next('webhooks-list'), { fullPage: true });
          await clearCallouts(page);
        });
      });

      await step('webhook wizard platform', async () => {
        await tall(page, 1250, async () => {
          await open(page, '/admin/webhook_new.php', ['Where should the events go?']);
          await c(page, [
            { selector: await mark(page.locator('[data-wz-psearch]'), 'w-search'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('[data-wz-catchip]').first(), 'w-chips'), n: 2 },
            { selector: await mark(page.locator('[data-wz-popular]'), 'w-popular'), n: 3, side: 'tr' },
          ]);
          await shot(page, next('webhook-wizard-platform'));
          await clearCallouts(page);
        });
      });

      await step('webhook wizard connect', async () => {
        await open(page, '/admin/webhook_new.php?dest=n8n&step=connect', ['Connect n8n']);
        await page.fill('input[name=webhook_name]', 'Dispatch board (n8n)');
        await page.fill('input[name=webhook_url]', 'https://automation.lakeshoremsp.example/webhook/rivet-tickets');
        await page.locator('[data-wz-advanced] summary').click();
        await page.waitForTimeout(500);
        await tall(page, 1250, async () => {
          await c(page, [
            { selector: await mark(page.locator('input[name=webhook_name]'), 'w-name'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('input[name=webhook_url]'), 'w-url'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('input[name=webhook_allow_http]').locator('xpath=..'), 'w-http'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('[data-wz-advanced]'), 'w-adv'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('[data-wz-help]').first(), 'w-help'), n: 5 },
          ]);
          await shot(page, next('webhook-wizard-connect'));
          await clearCallouts(page);
        });
      });

      await step('webhook wizard events', async () => {
        // The Connect step resolves the host name; made-up ".example" hosts cannot be resolved in the sandbox, so the later steps are
        // opened through their own addresses (every step is linkable: ?dest=<platform>&step=<step>).
        await open(page, '/admin/webhook_new.php?dest=n8n&step=events', ['Which events?']);
        await tall(page, 1500, async () => {
          const preset = page.locator('[data-wz-preset]').first();
          if (await preset.count()) await preset.click();
          await page.waitForTimeout(500);
          await c(page, [
            { selector: await mark(page.locator('[data-wz-presets]'), 'e-presets'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('[data-wz-events]'), 'e-list'), n: 2, side: 'tr' },
          ]);
          await shot(page, next('webhook-wizard-events'));
          await clearCallouts(page);
        });
      });

      await step('webhook edit deliveries', async () => {
        await open(page, '/admin/settings_webhooks.php');
        const href = await page.locator('a.whl-name:has-text("Dispatch board (n8n)")').first().getAttribute('href');
        await tall(page, 1300, async () => {
          await open(page, `/admin/${href}&tab=deliveries`.replace('/admin//admin/', '/admin/'), ['Dispatch board']);
          await shot(page, next('webhook-edit-deliveries'), { fullPage: true });
        });
      });

      await step('webhook delivery payload', async () => {
        await open(page, '/admin/settings_webhooks.php');
        await page.locator('button[aria-label^="View payload of delivery"]').first().click();
        await waitModal(page);
        await shot(page, next('webhook-delivery-payload'), { selector: MODAL });
        await closeModal(page);
      });

      await step('webhook guides', async () => {
        await open(page, '/admin/webhook_guides.php', ['Guides']);
        await shot(page, next('webhook-guides'));
      });

      await step('event rules list', async () => {
        await open(page, '/admin/event_rules.php', ['Notify on Critical tickets']);
        await tall(page, 1050, async () => {
          await c(page, [
            { selector: await mark(page.locator('a:has-text("Recipes"), button:has-text("Recipes")').first(), 'b-rec'), n: 1 },
            { selector: await mark(page.locator('a:has-text("New rule"), button:has-text("New rule")').first(), 'b-new'), n: 2 },
            { selector: await mark(page.locator('input[type=checkbox][role=switch], .form-switch input').first(), 'sw-rule'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('a:has-text("Test"), button:has-text("Test")').first(), 'b-test'), n: 4 },
            { selector: await mark(page.locator('a:has-text("History"), button:has-text("History")').first(), 'b-hist'), n: 5 },
          ]);
          await shot(page, next('event-rules-list'));
          await clearCallouts(page);
        });
      });

      await step('event rule editor', async () => {
        await open(page, '/admin/event_rules.php?recipe=critical-ticket', ['New rule from a recipe']);
        await tall(page, 1500, async () => {
          await c(page, [
            { selector: await mark(page.locator('.er-step').nth(0), 'er-when'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('.er-step').nth(1), 'er-if'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('.er-step').nth(2), 'er-then'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('.er-step').nth(3), 'er-name'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('button:has-text("Save and test")').first(), 'er-test'), n: 5 },
          ]);
          await shot(page, next('event-rule-editor'));
          await clearCallouts(page);
        });
      });

      await step('event rule recipes', async () => {
        await open(page, '/admin/event_rules.php');
        await page.locator('a:has-text("Recipes"), button:has-text("Recipes")').first().click();
        await page.waitForTimeout(700);
        await tall(page, 1250, async () => { await shot(page, next('event-rule-recipes')); });
      });

      await step('job queue', async () => {
        await open(page, '/admin/job_queue.php', ['Job queue', 'webhook.deliver']);
        await tall(page, 900, async () => {
          await c(page, [
            { selector: await mark(page.locator('a[href="?status=pending"]').first(), 'jq-wait'), n: 1 },
            { selector: await mark(page.locator('a[href="?status=dead_letter"]').first(), 'jq-dead'), n: 2 },
            { selector: await mark(page.locator('button:has-text("Process jobs now")').first(), 'jq-run'), n: 3 },
            { selector: await mark(page.locator('table').first(), 'jq-tbl'), n: 4, side: 'tr' },
          ]);
          await shot(page, next('job-queue'));
          await clearCallouts(page);
        });
      });
    }

    // ===================== 30c AI and Remote MCP =====================
    if (want('ai')) {
      n = 40; // numbers are fixed per section so ONLY=<section> reruns overwrite the same files
      await step('ai settings', async () => {
        await open(page, '/admin/settings_ai.php', ['AI Features', 'Max Input Characters']);
        await shot(page, next('ai-settings'));
      });
      await step('ai providers', async () => {
        await open(page, '/admin/ai_provider.php', ['Lakeshore AI gateway']);
        await c(page, [
          { selector: await mark(page.locator('button:has-text("Add Provider")').first(), 'b-add'), n: 1 },
          { selector: await mark(page.locator('th:has-text("Key")').first(), 'th-key'), n: 2 },
          { selector: await mark(page.locator('th:has-text("Models")').first(), 'th-models'), n: 3 },
        ]);
        await shot(page, next('ai-providers'));
        await clearCallouts(page);
      });
      await step('ai provider dialog', async () => {
        await open(page, '/admin/ai_provider.php');
        await page.locator('button:has-text("Add Provider")').first().click();
        await waitModal(page);
        await shot(page, next('ai-provider-add'), { selector: MODAL });
        await closeModal(page);
      });
      await step('ai models', async () => {
        await open(page, '/admin/ai_model.php', ['Reply Draft']);
        await c(page, [
          { selector: await mark(page.locator('th:has-text("Use Case")').first(), 'th-use'), n: 1 },
          { selector: await mark(page.locator('button:has-text("Add Model")').first(), 'b-addm'), n: 2 },
        ]);
        await shot(page, next('ai-models'));
        await clearCallouts(page);
      });
      await step('mcp top', async () => {
        await open(page, '/admin/settings_mcp.php', ['Remote MCP', 'Built-in sign-in']);
        await tall(page, 1500, async () => {
          await c(page, [
            { selector: await mark(page.locator('#mcp_enabled').locator('xpath=..'), 'm-on'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('#mcp_url').locator('xpath=..'), 'm-url'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('#mcp_oauth_builtin').locator('xpath=..'), 'm-builtin'), n: 3, side: 'tr' },
            { selector: await mark(page.locator('#mcp_oauth_registration').locator('xpath=..'), 'm-reg'), n: 4, side: 'tr' },
            { selector: await mark(page.locator('#mcp_write_enabled').locator('xpath=..'), 'm-write'), n: 5, side: 'tr' },
          ]);
          await shot(page, next('mcp-settings'));
          await clearCallouts(page);
        });
      });
      await step('mcp lower', async () => {
        await open(page, '/admin/settings_mcp.php');
        await tall(page, 1500, async () => {
          const act = page.locator('.card:has-text("Active connections")').last();
          await act.scrollIntoViewIfNeeded();
          await c(page, [
            { selector: await mark(page.locator('.card:has-text("Active connections")').last(), 'm-act'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('.card:has-text("Registered apps")').last(), 'm-apps'), n: 2, side: 'tr' },
            { selector: await mark(page.locator('.card:has-text("Recent activity")').last(), 'm-recent'), n: 3, side: 'tr' },
          ]);
          await shot(page, next('mcp-connections'), { fullPage: true });
          await clearCallouts(page);
        });
      });
      await step('mcp proposals', async () => {
        await open(page, '/agent/mcp_actions.php', ['AI actions waiting for approval', 'ticket reply']);
        await c(page, [
          { selector: await mark(page.locator('a:has-text("Review"), button:has-text("Review")').first(), 'b-review'), n: 1 },
        ]);
        await shot(page, next('ai-actions'));
        await clearCallouts(page);
        await page.locator('a:has-text("Review"), button:has-text("Review")').first().click();
        await page.waitForTimeout(900);
        await assertClean(page, 'proposal review');
        await tall(page, 1000, async () => { await shot(page, next('ai-action-review')); });
      });
    }

    // ===================== 31 API and mobile =====================
    if (want('api')) {
      n = 60; // numbers are fixed per section so ONLY=<section> reruns overwrite the same files
      const PREFIX = 'Guide example';
      const stamp = new Date(Date.now() + 90 * 864e5).toISOString().slice(0, 10);
      async function deleteGuideKeys() {
        // revoke (expires it now), then delete: the Delete item only shows for an expired key
        for (let i = 0; i < 12; i++) {
          await goto(page, '/admin/api_keys.php');
          const row = page.locator('tr', { hasText: PREFIX }).first();
          if (!(await row.count())) break;
          await row.locator('button[data-bs-toggle="dropdown"]').click();
          await page.waitForTimeout(300);
          const rev = row.locator('a:has-text("Revoke")');
          const del = row.locator('a:has-text("Delete")');
          await ((await del.count()) ? del : rev).first().click();
          await page.locator('.modal.show button:has-text("Yes")').click();
          await page.waitForLoadState('domcontentloaded');
          await page.waitForTimeout(1500);   // the Delete item only appears once the expiry second has passed
        }
      }
      async function createKey(name, perm, clientName) {
        await goto(page, '/admin/api_keys.php');
        await page.locator('button:has-text("New API Key")').first().click();
        await waitModal(page);
        await page.fill(`${MODAL} input[name=name]`, name);
        await page.fill(`${MODAL} input[name=expire]`, stamp);
        if (clientName) {
          const val = await page.locator(`${MODAL} select[name=client] option`, { hasText: clientName }).first().getAttribute('value');
          await page.selectOption(`${MODAL} select[name=client]`, val);
        }
        await page.selectOption(`${MODAL} select[name=permission]`, perm);
      }
      await step('api keys', async () => {
        await deleteGuideKeys();
        await open(page, '/admin/api_keys.php', ['Rate limit', 'API Keys']);
        await tall(page, 1000, async () => {
          await c(page, [
            { selector: await mark(page.locator('.card:has-text("Rate limit")').first(), 'k-rate'), n: 1, side: 'tr' },
            { selector: await mark(page.locator('button:has-text("New API Key")').first(), 'k-new'), n: 2 },
          ]);
          await shot(page, next('api-keys-empty'));
          await clearCallouts(page);
        });

        // New key dialog: Details tab
        await createKey(`${PREFIX} (nightly report export)`, 'read', null);
        await c(page, [
          { selector: await mark(page.locator(`${MODAL} input[name=name]`), 'd-name'), n: 1, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} input[name=expire]`), 'd-exp'), n: 2, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} select[name=client]`).locator('xpath=..'), 'd-client'), n: 3, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} select[name=permission]`), 'd-perm'), n: 4, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} a:has-text("Keys")`), 'd-tab'), n: 5 },
        ]);
        await shot(page, next('api-key-dialog-details'), { selector: MODAL });
        await clearCallouts(page);

        // Keys tab: the key and the (random) decryption password are generated by the dialog and shown once.
        await page.locator(`${MODAL} a:has-text("Keys")`).click();
        await page.waitForTimeout(500);
        // Do not leave a real secret in a picture: blank the two generated values before the screenshot.
        await page.evaluate(() => {
          document.querySelectorAll('.modal.show #pills-api-keys input[type=text]').forEach((i) => { i.value = 'shown once when you create the key'; });
        });
        await c(page, [
          { selector: await mark(page.locator(`${MODAL} #pills-api-keys input`).first(), 'k-key'), n: 1, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} #pills-api-keys input`).nth(1), 'k-pw'), n: 2, side: 'tr' },
          { selector: await mark(page.locator(`${MODAL} input[name=ack]`), 'k-ack'), n: 3, side: 'tr' },
        ]);
        await shot(page, next('api-key-dialog-keys'), { selector: MODAL });
        await clearCallouts(page);
        await closeModal(page);

        // Actually create the three throwaway keys (the dialog's own hidden fields carry the real values).
        const specs = [
          [`${PREFIX} (nightly report export)`, 'read', null],
          [`${PREFIX} (ticket integration)`, 'write', null],
          [`${PREFIX} (Northshore only)`, 'read', 'Northshore Dental Group'],
        ];
        for (const [nm, perm, cl] of specs) {
          await createKey(nm, perm, cl);
          await page.locator(`${MODAL} a:has-text("Keys")`).click();
          await page.check(`${MODAL} input[name=ack]`);
          await Promise.all([page.waitForLoadState('domcontentloaded'), page.click(`${MODAL} button[name=add_api_key]`)]);
          await settle(page);
        }
        await open(page, '/admin/api_keys.php', [PREFIX]);
        await tall(page, 1000, async () => {
          await c(page, [
            { selector: await mark(page.locator('th:has-text("Client")').first(), 'l-client'), n: 1 },
            { selector: await mark(page.locator('th:has-text("Secret")').first(), 'l-secret'), n: 2 },
            { selector: await mark(page.locator('th:has-text("Permission")').first(), 'l-perm'), n: 3 },
            { selector: await mark(page.locator('th:has-text("Expires")').first(), 'l-exp'), n: 4 },
            { selector: await mark(page.locator('tr', { hasText: PREFIX }).first().locator('button[data-bs-toggle="dropdown"]'), 'l-act'), n: 5, side: 'tr' },
          ]);
          await shot(page, next('api-keys-list'));
          await clearCallouts(page);
        });
        // row menu (Revoke)
        await page.locator('tr', { hasText: PREFIX }).first().locator('button[data-bs-toggle="dropdown"]').click();
        await page.waitForTimeout(400);
        await shot(page, next('api-key-row-menu'));
        await page.keyboard.press('Escape');
      });

      await step('api docs admin', async () => {
        await open(page, '/admin/api_docs.php', ['API Documentation']);
        await shot(page, next('api-docs-admin'));
      });
      await step('api docs public', async () => {
        await open(page, '/api/v1/docs', undefined);
        await shot(page, next('api-docs-public'));
      });
      await step('mobile tokens', async () => {
        await open(page, '/agent/user/user_security.php', ['Mobile App Tokens', 'Pixel 8']);
        const card = page.locator('.card:has-text("Mobile App Tokens")').last();
        await card.scrollIntoViewIfNeeded();
        await c(page, [
          { selector: await mark(page.locator('.card:has-text("Mobile App Tokens")').last(), 'mt-card'), n: 1, side: 'tr' },
          { selector: await mark(page.locator('.js-revoke-api-token').first(), 'mt-rev'), n: 2 },
        ]);
        await shot(page, next('mobile-tokens'), { selector: '.card:has-text("Mobile App Tokens") >> nth=-1' });
        await clearCallouts(page);
      });

      // clean up: leave no throwaway key behind
      await step('cleanup keys', async () => { await deleteGuideKeys(); });
    }
  } finally {
    await browser.close();
  }
  if (failures.length) {
    console.error('\nSTEPS THAT FAILED:\n - ' + failures.join('\n - '));
    process.exitCode = 1;
  }
})();
