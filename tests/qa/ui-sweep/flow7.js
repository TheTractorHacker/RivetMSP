const L = require('./lib');
(async () => {
  const w=+process.argv[2]||1920,h=+process.argv[3]||1080;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: w, height: h } });
  const p = await ctx.newPage();
  const shot = n => p.screenshot({ path: `${L.IMG}/f7_${w}_${n}.png`, fullPage:true });
  await p.goto(L.BASE + '/login.php', { waitUntil: 'networkidle' });
  await p.click('button[name=login]'); await p.waitForTimeout(500); await shot('login_empty');
  await p.fill('input[name=email]:visible', 'nobody-qa-ui@qa.example'); await p.fill('input[name=password]:visible', 'wrongpass-qa-ui');
  await Promise.all([p.waitForLoadState('networkidle'), p.click('button[name=login]')]); await shot('login_bad');
  console.log((await p.innerText('body')).replace(/\n+/g,' | ').slice(0,300));
  await p.goto(L.BASE + '/client/login_reset.php', { waitUntil: 'networkidle' });
  await p.fill('input[type=email]', 'not-valid'); await p.click('button[name=password_reset_email_request]'); await p.waitForTimeout(400); await shot('reset_invalid');
  await b.close();
})();
