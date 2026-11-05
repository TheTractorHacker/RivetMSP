const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const sub = async () => { await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}), p.locator('form[action="post.php"] button[type=submit]:visible').first().click()]); await p.waitForTimeout(500); };
  await p.goto(H.BASE + '/admin/settings_ticket.php', { waitUntil: 'networkidle' });
  const orig = await p.inputValue('input[name=config_ticket_prefix]'); console.log('orig prefix', orig);
  await p.fill('input[name=config_ticket_prefix]', 'QAUI-'); await sub();
  await p.goto(H.BASE + '/admin/settings_ticket.php', { waitUntil: 'networkidle' });
  console.log('persisted', await p.inputValue('input[name=config_ticket_prefix]'));
  await p.fill('input[name=config_ticket_prefix]', orig); await sub();
  await p.goto(H.BASE + '/admin/settings_ticket.php', { waitUntil: 'networkidle' });
  console.log('restored', await p.inputValue('input[name=config_ticket_prefix]'));
  // required empty: company name
  await p.goto(H.BASE + '/admin/settings_company.php', { waitUntil: 'networkidle' });
  const nm = await p.inputValue('input[name=name]'); await p.fill('input[name=name]', '');
  await p.locator('form[action="post.php"] button[type=submit]:visible').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/company-empty.png' }); console.log('url', p.url(), 'orig name', nm);
  // invalid email
  await p.fill('input[name=name]', nm); await p.fill('input[name=email]', 'not-an-email');
  await p.locator('form[action="post.php"] button[type=submit]:visible').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/company-bademail.png' });
  // negative number
  await p.goto(H.BASE + '/admin/settings_ticket.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=config_ticket_autoclose_hours]', '-5'); await p.locator('form[action="post.php"] button[type=submit]:visible').first().click(); await p.waitForTimeout(500);
  await p.screenshot({ path: IMG + '/ticket-neg.png' });
  await b.close();
})();
