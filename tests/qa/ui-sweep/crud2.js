const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,120)); }); p.on('response', r => { if (r.status()>=400) errs.push(r.status()+' '+r.url().slice(0,90)); });
  p.on('dialog', d => d.accept());
  const alerts = async () => (await p.locator('.alert:visible').allInnerTexts()).join('|').replace(/\s+/g,' ').slice(0,160);
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New User")').first().click(); await p.waitForTimeout(1200);
  await p.fill('.modal.show input[name=name]', 'QA-UI User'); await p.fill('.modal.show input[name=email]', 'qa-ui-user@qa.example');
  await p.evaluate(() => { const s = document.querySelector('.modal.show select[name=role]'); const o=[...s.options].find(o=>/Technician/.test(o.text)); $(s).val(o.value).trigger('change'); });
  await p.fill('.modal.show input[name=password]', 'QaUiPassw0rd#2026');
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  console.log('user create ->', p.url(), await alerts(), (await p.locator('body').innerText()).includes('QA-UI User'));
  await p.screenshot({ path: IMG + '/crud-user-created.png' });
  // edit
  const row = p.locator('tr:has-text("QA-UI User")');
  await row.locator('a:has-text("Edit")').click(); await p.waitForTimeout(1200);
  await p.fill('.modal.show input[name=name]', 'QA-UI User Edited');
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  console.log('user edit ->', await alerts(), (await p.locator('body').innerText()).includes('QA-UI User Edited'));
  // archive
  const row2 = p.locator('tr:has-text("QA-UI User Edited")');
  await row2.locator('button[data-bs-toggle=dropdown]').click(); await p.waitForTimeout(300);
  await p.screenshot({ path: IMG + '/crud-user-menu.png' });
  await row2.locator('a:has-text("Archive")').click(); await p.waitForTimeout(1200);
  await p.screenshot({ path: IMG + '/crud-user-archive-modal.png' });
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  console.log('user archive ->', await alerts(), (await p.locator('body').innerText()).includes('QA-UI User Edited'));
  console.log(errs); await b.close();
})();
