// usage: [DARK=1] [VP=WxH] node axe1.js <path>  -> prints axe violation nodes
const m=require('./m2.js');const fs=require('fs');const [w,hh]=(process.env.VP||'1366x768').split('x').map(Number);
(async()=>{const {b,p}=await m.ctxPage({width:w,height:hh},!!process.env.DARK);await m.go(p,process.argv[2]);
await p.evaluate(fs.readFileSync(process.env.QA_AXE||'/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js','utf8'));
const r=await p.evaluate(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag22aa','best-practice']});return x.violations.map(v=>({id:v.id,imp:v.impact,nodes:v.nodes.slice(0,4).map(n=>n.target.join(' ')+' :: '+(n.any[0]&&n.any[0].message||'').slice(0,110))}))});
console.log(JSON.stringify(r,null,1));await b.close();})();
