const L = require('./lib');
(async () => {
  const w=+process.argv[2]||1920,h=+process.argv[3]||1080;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: w, height: h } });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f6_${w}_${n}.png`, fullPage:true });
  await p.goto(L.BASE + '/guest/guest_view_quote.php?quote_id=1&url_key=qaseedquo9001', { waitUntil: 'networkidle' });
  await p.click('button:has-text("Accept")'); await p.waitForTimeout(700); await shot('accept_confirm');
  await p.click('#confirmSubmitBtn'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500); await shot('accepted');
  console.log(p.url(), (await p.innerText('body')).replace(/\n+/g,' | ').slice(0,300));
  const up = await p.$('button:has-text("Upload File")'); if (up) { await up.click(); await p.waitForTimeout(600); await shot('upload_modal'); }
  console.log('errs', JSON.stringify(errs)); await b.close();
})();
