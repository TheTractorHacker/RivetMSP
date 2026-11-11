// usage: node crawl.js <theme-tag> <width> <height> [pages...]
const { H, IMG, ctx } = require('./common');
const fs = require('fs');
const PAGES = ['admin/settings.php','admin/users.php','admin/roles.php','admin/api_keys.php','admin/api_docs.php','admin/catalog_setup.php','admin/tax.php','admin/payment_method.php','admin/payment_provider.php','admin/ticketing_setup.php','admin/template_library.php','admin/maintenance.php',
'admin/settings_company.php','admin/settings_localization.php','admin/settings_theme.php','admin/settings_appearance.php','admin/settings_portal_branding.php','admin/settings_default.php','admin/settings_module.php','admin/settings_ticket.php','admin/settings_project.php','admin/settings_invoice.php','admin/settings_quote.php','admin/settings_accounting.php','admin/payroll_settings.php','admin/settings_kb.php','admin/settings_security.php','admin/settings_compliance.php','admin/compliance_status.php','admin/event_rules.php','admin/job_queue.php','admin/settings_mail.php','admin/settings_mail_templates.php','admin/settings_notification.php','admin/identity_provider.php','admin/settings_integrations.php','admin/settings_endpoint_agent.php','admin/rmm_mesh_wizard.php','admin/settings_calendar_sync.php','admin/settings_webhooks.php','admin/settings_ai.php','admin/settings_mcp.php','admin/settings_telemetry.php'];
(async () => {
  const [tag, w, h, ...rest] = process.argv.slice(2);
  const pages = rest.length ? rest : PAGES;
  const b = await H.launch(); const c = await ctx(b, +w, +h); const p = await c.newPage();
  let errs = [];
  p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text().slice(0, 150)); });
  p.on('pageerror', e => errs.push('pageerror: ' + String(e).slice(0, 150)));
  p.on('response', r => { if (r.status() >= 400) errs.push(r.status() + ' ' + r.url().slice(0, 100)); });
  for (const u of pages) {
    errs = [];
    try { await p.goto(H.BASE + '/' + u, { waitUntil: 'networkidle', timeout: 30000 }); } catch (e) { errs.push('nav ' + e.message.slice(0, 80)); }
    const name = u.replace(/[\/?=&.]/g, '_');
    await p.screenshot({ path: `${IMG}/${name}-${tag}.png`, fullPage: true });
    const r = await H.audit(p, true);
    console.log(u, '->', p.url().replace(H.BASE, ''), JSON.stringify({ hs: r.hscroll, h1: r.h1, small: r.small, clip: r.clipped, noname: r.noname, raw: r.rawerr, th: r.themeattr, axe: r.axe, errs }));
  }
  await b.close();
})();
