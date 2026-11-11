const h=require('./h.js');const fs=require('fs');
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:'/home/sysadmin/ui-sweep-adminother-img/state.json'});const page=await ctx.newPage();
const out={};
for(const u of process.argv.slice(2)){await page.goto(h.BASE+u);const l=await page.evaluate(()=>[...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href')+' | '+a.textContent.trim().replace(/\s+/g,' ').slice(0,40)));out[u]=[...new Set(l)];}
console.log(JSON.stringify(out,null,0));await b.close();})();
