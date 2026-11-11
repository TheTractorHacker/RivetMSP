const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  await p.goto(H.BASE + '/admin/api_keys.php', { waitUntil: 'networkidle' });
  await p.screenshot({ path: IMG + '/keys-list.png' });
  const row = p.locator('tr:has-text("QA-UI-Key")');
  await row.locator('button[data-bs-toggle=dropdown]').click(); await p.waitForTimeout(300);
  await p.screenshot({ path: IMG + '/keys-menu.png' });
  console.log(await row.locator('.dropdown-menu a').allInnerTexts());
  for (let i = 0; i < 4; i++) {
    await p.goto(H.BASE + '/admin/roles.php', { waitUntil: 'networkidle' });
    const r = p.locator('tr:has-text("QA-UI-Role")'); if (!(await r.count())) break;
    await r.first().locator('button[data-bs-toggle=dropdown]').click(); await p.waitForTimeout(300);
    await r.first().locator('a:has-text("Archive")').click(); await p.waitForTimeout(900);
    await p.screenshot({ path: IMG + '/role-archive-confirm.png' });
    const ok = p.locator('.modal.show a:has-text("Yes"), .modal.show .btn-danger, .modal.show a.btn-primary');
    if (await ok.count()) { await Promise.all([p.waitForLoadState('networkidle'), ok.first().click()]); }
    await p.waitForTimeout(600);
  }
  await b.close();
})();
