const h=require('./h.js');const fs=require('fs');
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:'/home/sysadmin/ui-sweep-adminother-img/state.json'});const page=await ctx.newPage();
for(const u of process.argv.slice(2)){await page.goto(h.BASE+u,{waitUntil:'networkidle'});
await page.evaluate(fs.readFileSync(process.env.QA_AXE,'utf8'));
const r=await page.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2aa']});return x.violations.map(v=>({id:v.id,nodes:v.nodes.slice(0,6).map(n=>n.html.slice(0,110)+' || '+(n.any[0]&&n.any[0].message||'').slice(0,90))}))})()`);
const small=await page.evaluate(()=>{const o=[];for(const el of document.querySelectorAll('body *')){const cs=getComputedStyle(el);if(cs.display==='none')continue;if([...el.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())&&parseFloat(cs.fontSize)<11.5){o.push(el.tagName+'.'+String(el.className).slice(0,30)+':'+cs.fontSize+':'+el.textContent.trim().slice(0,25))}}return [...new Set(o)].slice(0,8)});
console.log('==',u);console.log(JSON.stringify(r,null,1));console.log('small',small);}
await b.close();})();
