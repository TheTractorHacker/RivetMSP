const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  await p.goto(H.BASE + '/admin/roles.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New Role")').first().click(); await p.waitForTimeout(1200);
  await p.locator('.modal.show a:has-text("Permissions"), .modal.show button:has-text("Permissions")').first().click(); await p.waitForTimeout(600);
  await p.screenshot({ path: IMG + '/roles-perm.png', fullPage: false });
  console.log(await p.evaluate(() => { const m=document.querySelector('.modal.show .modal-dialog'); const r=m.getBoundingClientRect(); return [r.top|0,r.bottom|0,innerHeight, getComputedStyle(document.querySelector('.modal.show .modal-body')).overflowY]; }));
  // empty submit
  await p.locator('.modal.show button[type=submit]').first().click(); await p.waitForTimeout(600);
  await p.screenshot({ path: IMG + '/roles-empty.png' });
  await p.goto(H.BASE + '/admin/roles.php', { waitUntil: 'networkidle' });
  await p.locator('a.ajax-modal:has-text("Technician")').first().click(); await p.waitForTimeout(1200);
  await p.screenshot({ path: IMG + '/roles-edit.png' });
  await b.close();
})();
