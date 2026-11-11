const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
for(const u of process.argv.slice(2)){await page.goto(h.BASE+u);const r=page.locator('tr:has-text("QA-UI-ADM")');console.log(u,await r.count());if(await r.count())console.log((await r.first().innerHTML()).replace(/\s+/g,' ').slice(0,900));}
await b.close();})();
