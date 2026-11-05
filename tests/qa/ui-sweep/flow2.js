const L = require('./lib');
(async () => {
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('console', m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))}); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f2_${n}.png`, fullPage: true });
  const body = async () => (await p.innerText('body')).replace(/\n+/g,' | ');
  await p.goto(L.BASE + '/client/ticket.php?id=48', { waitUntil: 'networkidle' });
  // empty reply
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button[name=add_ticket_comment]')]);
  await shot('reply_empty'); console.log('empty reply ->', (await body()).match(/You must[^|]*/)?.[0]);
  await p.evaluate(() => tinymce.activeEditor.setContent('<p>QA-UI reply with attachment</p>'));
  await p.setInputFiles('#fileInput', L.IMG + '/qa-ui-attach.txt');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button[name=add_ticket_comment]')]);
  await shot('reply_ok'); console.log('after reply', p.url(), (await body()).slice(380, 900));
  console.log('errs', JSON.stringify(errs));
  await b.close();
})();
