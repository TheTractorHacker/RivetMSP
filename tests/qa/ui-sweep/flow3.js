const L = require('./lib');
(async () => {
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('console', m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))}); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f3_${n}.png` });
  await p.goto(L.BASE + '/client/ticket.php?id=49', { waitUntil: 'networkidle' });
  await p.click('a:has-text("Resolve ticket")'); await p.waitForTimeout(700); await shot('resolve_confirm');
  console.log((await p.innerText('body')).match(/Are you sure[^\n]*/)?.[0]);
  const btn = p.locator('.modal.show button, .modal.show a').filter({ hasText: /Yes|Confirm|OK|Resolve/i }).first();
  console.log('confirm btn count', await btn.count());
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), btn.click()]);
  await shot('resolved'); console.log(p.url());
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('a:has-text("Reopen ticket")').catch(e=>console.log('no reopen'))]);
  await shot('reopened');
  console.log('errs', JSON.stringify(errs)); await b.close();
})();
