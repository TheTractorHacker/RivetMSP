const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,120)); }); p.on('response', r => { if (r.status()>=400) errs.push(r.status()+' '+r.url().slice(0,90)); });
  const txt = async () => (await p.locator('body').innerText());
  // ROLE
  await p.goto(H.BASE + '/admin/roles.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New Role")').first().click(); await p.waitForTimeout(1200);
  await p.fill('.modal.show input[name=role_name]', 'QA-UI-Role'); await p.fill('.modal.show input[name=role_description]', 'QA-UI role desc');
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(500);
  console.log('after role create', p.url(), (await txt()).includes('QA-UI-Role'), (await p.locator('.alert').allInnerTexts()).join('|').slice(0,100));
  await p.screenshot({ path: IMG + '/crud-role-created.png' });
  // API KEY
  await p.goto(H.BASE + '/admin/api_keys.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New API Key"),a:has-text("New API Key")').first().click(); await p.waitForTimeout(1200);
  await p.fill('.modal.show input[name=name]', 'QA-UI-Key');
  const d = new Date(Date.now()+86400000*30).toISOString().slice(0,10); await p.fill('.modal.show input[type=date]', d);
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  await p.screenshot({ path: IMG + '/crud-key-ack.png' });
  await p.locator('.modal.show input[name=ack]').check();
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  console.log('after key create', p.url(), (await txt()).includes('QA-UI-Key'));
  await p.screenshot({ path: IMG + '/crud-key-created.png' });
  // USER
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  await p.locator('button:has-text("New User")').first().click(); await p.waitForTimeout(1200);
  await p.fill('.modal.show input[name=name]', 'QA-UI User'); await p.fill('.modal.show input[name=email]', 'qa-ui-user@qa.example');
  await p.locator('.modal.show select[name=role]').selectOption({ label: 'Technician' }).catch(async e => console.log('role sel', e.message.slice(0,60)));
  await p.fill('.modal.show input[name=password]', 'short');
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('.modal.show button[type=submit]').click()]); await p.waitForTimeout(800);
  await p.screenshot({ path: IMG + '/crud-user-short-pw.png' });
  console.log('short pw ->', p.url(), (await p.locator('.alert').allInnerTexts()).join('|').slice(0,160));
  console.log(errs); await b.close();
})();
