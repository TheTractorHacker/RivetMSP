const L = require('./lib');
(async () => {
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  await p.goto(L.BASE + '/client/ticket_view_all.php', { waitUntil: 'domcontentloaded' });
  for (const v of ['%','Closed','Open']) { await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}), p.selectOption('select[name=status]', v)]); const rows = await p.$$eval('tbody tr', r=>r.length); console.log(v, p.url().replace(L.BASE,''), 'rows', rows, 'selected', await p.$eval('select[name=status]', s=>s.value)); }
  // tickets.php filters
  for (const q of ['Open','Closed','%']) { await p.goto(L.BASE+'/client/tickets.php?status='+encodeURIComponent(q), {waitUntil:'domcontentloaded'}); console.log('tickets', q, await p.$$eval('tbody tr', r=>r.length), await p.$eval('.card-title', e=>e.textContent.trim())); }
  // CSAT submit ticket 7
  await p.goto(L.BASE+'/client/ticket.php?id=7', {waitUntil:'domcontentloaded'});
  await p.click('label[for=csat_face_4]'); await p.waitForTimeout(300); await p.screenshot({path:L.IMG+'/f8_csat_pick.png', fullPage:true});
  await p.fill('textarea[name=csat_comment]','QA-UI great');
  await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}), p.click('button[name=add_ticket_feedback]')]);
  await p.screenshot({path:L.IMG+'/f8_csat_done.png', fullPage:true});
  console.log((await p.innerText('body')).replace(/\n+/g,' | ').slice(250,560)); console.log('errs', JSON.stringify(errs)); await b.close();
})();
