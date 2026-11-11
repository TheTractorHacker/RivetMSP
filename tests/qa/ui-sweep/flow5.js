const L = require('./lib');
(async () => {
  const w = +process.argv[2]||1920, h = +process.argv[3]||1080;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: w, height: h }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f5_${w}_${n}.png`, fullPage:true });
  const txt = async () => (await p.innerText('body')).replace(/\n+/g,' | ');
  await p.goto(L.BASE + '/client/contact_add.php', { waitUntil: 'networkidle' });
  await p.click('button[name=add_contact]'); await p.waitForTimeout(400); await shot('add_empty');
  await p.fill('input[name=contact_name]', 'QA-UI New Contact'); await p.fill('input[name=contact_email]', 'not-an-email');
  await p.click('button[name=add_contact]'); await p.waitForTimeout(400); await shot('add_bademail');
  console.log('bad email url', p.url());
  await p.fill('input[name=contact_email]', 'qa-ui-new@qa.example');
  const sel = await p.$$eval('select[name]', els => els.map(e => e.name + ':' + [...e.options].map(o => o.value + '=' + o.text).join(',')));
  console.log(sel);
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button[name=add_contact]')]);
  await shot('add_done'); console.log(p.url(), (await txt()).slice(280, 600));
  // dup
  await p.goto(L.BASE + '/client/contact_add.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=contact_name]', 'QA-UI Dup'); await p.fill('input[name=contact_email]', 'qa-ui-new@qa.example');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button[name=add_contact]')]);
  await shot('add_dup'); console.log('dup:', p.url(), (await txt()).slice(280, 520));
  console.log('errs', JSON.stringify(errs)); await b.close();
})();
