const h=require('./h.js');const fs=require('fs');
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:'/home/sysadmin/ui-sweep-adminother-img/state.json'});const page=await ctx.newPage();
const agg={};
for(const u of fs.readFileSync(process.argv[2],'utf8').split('\n').filter(Boolean)){try{await page.goto(h.BASE+u,{waitUntil:'load',timeout:20000});}catch(e){continue}
const small=await page.evaluate(()=>{const o=[];for(const el of document.querySelectorAll('body *')){const cs=getComputedStyle(el);if(cs.display==='none'||cs.visibility==='hidden')continue;const rc=el.getBoundingClientRect();if(!rc.width)continue;if([...el.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())&&parseFloat(cs.fontSize)<11.9){o.push(el.tagName+'.'+String(el.className).replace(/\s+/g,'.').slice(0,40)+' '+parseFloat(cs.fontSize).toFixed(1)+' "'+el.textContent.trim().slice(0,20)+'"')}}return o});
for(const s of small){const k=s.replace(/ ".*/,'');(agg[k]=agg[k]||[]).push(u+' '+(s.match(/".*/)||[''])[0])}}
for(const [k,v] of Object.entries(agg).sort((a,b)=>b[1].length-a[1].length))console.log(v.length,k,'|',v[0]);
await b.close();})();
