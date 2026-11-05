const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,120)); }); p.on('response', r => { if (r.status()>=400) errs.push(r.status()+' '+r.url().slice(0,90)); });
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New User")').first().click(); await p.waitForTimeout(1200);
  // empty submit
  await p.locator('.modal.show button[type=submit]').first().click(); await p.waitForTimeout(800);
  await p.screenshot({ path: IMG + '/users-empty-submit.png' });
  console.log('url after empty submit', p.url(), 'modal open', await p.locator('.modal.show').count());
  // Access tab
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New User")').first().click(); await p.waitForTimeout(1200);
  await p.locator('.modal.show a:has-text("Access"), .modal.show button:has-text("Access")').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/users-new-access.png' });
  // edit tabs
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  await p.locator('a.btn:has-text("Edit")').nth(1).click(); await p.waitForTimeout(1200);
  await p.locator('.modal.show a:has-text("Security"), .modal.show button:has-text("Security")').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/users-edit-security.png' });
  await p.locator('.modal.show a:has-text("Access"), .modal.show button:has-text("Access")').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/users-edit-access.png' });
  console.log(errs); await b.close();
})();
