const L = require('./lib');
(async () => {
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('console', m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))}); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f1_${n}.png`, fullPage: true });
  await p.goto(L.BASE + '/client/ticket_add.php', { waitUntil: 'networkidle' });
  // empty submit
  await p.click('button:has-text("Raise ticket")'); await p.waitForTimeout(500); await shot('add_empty');
  console.log('url after empty', p.url());
  await p.fill('input[name=subject]', 'QA-UI portal ticket ' + Date.now());
  await p.click('button:has-text("Raise ticket")'); await p.waitForTimeout(1500); await shot('add_nodetails');
  console.log('url after subject only', p.url()); console.log((await p.innerText('body')).slice(300,700).replace(/\n+/g,' | '));
  // fill details via tinymce
  await p.evaluate(() => { if (window.tinymce && tinymce.activeEditor) tinymce.activeEditor.setContent('<p>Details from QA-UI</p>'); });
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button:has-text("Raise ticket")')]);
  await shot('add_done'); console.log('url after valid', p.url()); console.log((await p.innerText('body')).slice(0, 400).replace(/\n+/g,' | '));
  console.log('errs', JSON.stringify(errs));
  await b.close();
})();
