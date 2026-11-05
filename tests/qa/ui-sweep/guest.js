const L = require('./lib');
(async () => {
  const [,, tag, w, h, ...urls] = process.argv;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: +w, height: +h } });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('console', m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))}); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  for (const u of urls) { errs.length=0;
    await p.goto(L.BASE + u, { waitUntil: 'networkidle' }).catch(()=>{});
    await p.screenshot({ path: `${L.IMG}/${tag}_${u.replace(/[^a-z0-9]+/gi,'_').slice(0,50)}.png`, fullPage: true });
    const a = await L.audit(p, tag.startsWith('gx')); console.log(u, p.url().replace(L.BASE,''), JSON.stringify(a).slice(0,330), errs.length?'ERRS '+JSON.stringify(errs):''); }
  await b.close();
})();
