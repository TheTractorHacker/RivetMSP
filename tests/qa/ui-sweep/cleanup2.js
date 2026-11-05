const { H, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  p.on('dialog', d => d.accept());
  await p.goto(H.BASE + '/admin/api_keys.php', { waitUntil: 'networkidle' });
  const row = p.locator('tr:has-text("QA-UI-Key")');
  if (await row.count()) { await row.locator('button[data-bs-toggle=dropdown]').click(); await row.locator('a:has-text("Revoke")').click(); await p.waitForTimeout(900);
    const ok = p.locator('.modal.show .btn-primary, .modal.show .btn-danger'); if (await ok.count()) { await Promise.all([p.waitForLoadState('networkidle'), ok.first().click()]); } }
  for (let i = 0; i < 4; i++) {
    await p.goto(H.BASE + '/admin/roles.php', { waitUntil: 'networkidle' });
    const r = p.locator('tr:has-text("QA-UI-Role")'); if (!(await r.count())) break;
    await r.first().locator('button[data-bs-toggle=dropdown]').click(); await p.waitForTimeout(300);
    await r.first().locator('a:has-text("Archive")').click(); await p.waitForTimeout(900);
    await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show a:has-text("Yes"), .modal.show button:has-text("Yes")').first().click()]);
    await p.waitForTimeout(600);
  }
  await b.close();
})();
