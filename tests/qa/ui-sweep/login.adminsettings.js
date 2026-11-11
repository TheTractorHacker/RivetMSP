const { H, STATE, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  await p.goto(H.BASE + '/login.php');
  await p.locator('input[name=email]:visible').fill(H.USERS.admin[0]);
  await p.locator('input[name=password]:visible').fill(H.USERS.admin[1]);
  await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}), p.locator('button[type=submit]:visible').first().click()]);
  console.log(p.url()); await c.storageState({ path: STATE }); await b.close();
})();
