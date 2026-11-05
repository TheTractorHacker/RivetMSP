const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:390,height:844},storageState:I+'state.json'});const page=await ctx.newPage();
for(const u of process.argv.slice(2)){await page.goto(h.BASE+u,{waitUntil:'load'});await page.waitForTimeout(500);
console.log(u,await page.evaluate(()=>{const W=document.documentElement.clientWidth;const o=[];for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width&&r.right>W+1&&getComputedStyle(e).position!=='fixed'){let p=e.parentElement,clipped=false;while(p){const cs=getComputedStyle(p);if(/(auto|scroll|hidden)/.test(cs.overflowX)&&p!==document.body&&p!==document.documentElement){clipped=true;break}p=p.parentElement}if(!clipped)o.push(e.tagName+'.'+String(e.className).slice(0,40)+' r='+Math.round(r.right))}}return o.slice(0,6)}));}
await b.close();})();
