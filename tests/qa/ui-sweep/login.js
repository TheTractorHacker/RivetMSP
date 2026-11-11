const L = require('./lib');
(async () => {
  const [,, email, pass, out, dark] = process.argv;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 } }); const page = await ctx.newPage();
  await page.goto(L.BASE + '/login.php');
  await page.screenshot({ path: L.IMG + '/login.png' });
  await page.fill('input[name=email]:visible', email); await page.fill('input[name=password]:visible', pass);
  await Promise.all([page.waitForLoadState('networkidle').catch(()=>{}), page.locator('button[name=login]').click()]);
  console.log(page.url()); console.log((await page.innerText('body')).slice(0, 300));
  await page.screenshot({ path: L.IMG + '/afterlogin.png' });
  await ctx.storageState({ path: L.IMG + '/' + out });
  await b.close();
})();
