const h = require('./h.js'); const fs=require('fs');
(async () => { const pages=process.argv.slice(2);
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 const AXE=fs.readFileSync('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js','utf8');
 for (const f of pages) { const p = await c.newPage(); await p.goto(h.BASE+'/'+f); await p.waitForLoadState('networkidle').catch(()=>{}); await p.evaluate(AXE);
  const r = await p.evaluate(async()=>{const x=await axe.run(document,{runOnly:['color-contrast']});return x.violations.flatMap(v=>v.nodes.map(n=>({t:n.target.join(' ').slice(0,70), d:(n.any[0]&&n.any[0].data)?(n.any[0].data.fgColor+' on '+n.any[0].data.bgColor+' '+n.any[0].data.contrastRatio):''})))});
  console.log(f, r.length); for (const n of r.slice(0,6)) console.log('   ',n.t,n.d); await p.close(); }
 await b.close(); })();
