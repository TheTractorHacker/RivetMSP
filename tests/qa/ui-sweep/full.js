const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const [W,H]=(process.argv[3]||'1920x1080').split('x').map(Number);const ctx=await b.newContext({viewport:{width:W,height:H},storageState:I+'state.json'});const page=await ctx.newPage();
for(const u of process.argv[2].split(',')){await page.goto(h.BASE+u,{waitUntil:'networkidle'});await page.waitForTimeout(800);await page.screenshot({path:I+'full_'+W+'_'+u.replace(/\W+/g,'_')+'.png',fullPage:true});}
await b.close();})();
