const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,process.argv[2],{width:1920,height:1080});const p=await ctx.newPage();
await p.goto(L.BASE+process.argv[3],{waitUntil:'networkidle'});
if(process.argv[4]==='dark')await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode')});
await p.evaluate(L.AXE||require('fs').readFileSync('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js','utf8'));
const r=await p.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa']});return x.violations.map(v=>({id:v.id,n:v.nodes.length,nodes:v.nodes.slice(0,4).map(n=>(n.html||'').slice(0,150)+' || '+(n.any[0]&&n.any[0].message||'').slice(0,110))}))})()`);
console.log(JSON.stringify(r,null,1));await b.close()})();
