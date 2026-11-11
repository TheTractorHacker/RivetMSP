const { H, ctx } = require('./common');
(async () => { const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
 for (const u of process.argv.slice(2)) { await p.goto(H.BASE + '/' + u, { waitUntil: 'networkidle' });
  await p.evaluate(require('fs').readFileSync(process.env.QA_AXE,'utf8'));
  const r = await p.evaluate(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa']});return x.violations.map(v=>({id:v.id,nodes:v.nodes.slice(0,4).map(n=>n.target.join(' ')+' :: '+(n.any[0]&&n.any[0].message||'').slice(0,110))}))});
  console.log(u, JSON.stringify(r,null,1)); }
 await b.close(); })();
