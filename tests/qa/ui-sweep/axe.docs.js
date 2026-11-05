const m=require('./modal.js');const fs=require('fs');
const AXE=fs.readFileSync('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js','utf8');
(async()=>{const {b,p}=await m.ctxPage(process.env.VP?{width:+process.env.VP.split('x')[0],height:+process.env.VP.split('x')[1]}:null);
for(const pg of process.argv[2].split(',')){await m.go(p,pg);if(process.env.DARK)await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode')});await p.waitForTimeout(1000);
await p.evaluate(AXE);const r=await p.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag22aa','best-practice']});return x.violations.filter(v=>!${JSON.stringify((process.env.SKIP||'heading-order').split(','))}.includes(v.id)).map(v=>({id:v.id,nodes:v.nodes.slice(0,${+process.env.N||3}).map(n=>({t:n.target.join(' ').slice(0,90),h:n.html.slice(0,110),s:(n.any[0]&&n.any[0].message||'').slice(0,160)}))}))})()`);
console.log('##',pg);for(const v of r){console.log(v.id);for(const n of v.nodes)console.log('   ',n.t,'|',n.h,'|',n.s)}}
await b.close()})();
