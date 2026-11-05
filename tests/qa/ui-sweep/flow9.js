const L = require('./lib');
(async () => {
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/pw.json' });
  const p = await ctx.newPage();
  await p.goto(L.BASE + '/client/profile.php', { waitUntil: 'domcontentloaded' });
  await p.fill('#new_password', 'short'); await p.click('button[name=edit_profile]'); await p.waitForTimeout(400);
  await p.screenshot({ path: L.IMG + '/f9_short.png' });
  console.log('validity', await p.$eval('#new_password', e=>e.validationMessage));
  await p.fill('#new_password', 'QaPortal#2026y');
  await Promise.all([p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(()=>{}), p.click('button[name=edit_profile]')]);
  await p.screenshot({ path: L.IMG + '/f9_saved.png' });
  console.log(p.url(), (await p.innerText('body')).replace(/\n+/g,' | ').slice(150,330));
  await b.close();
})();
