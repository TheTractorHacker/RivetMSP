const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/document_template.php');console.log((await page.locator('tbody tr').allInnerTexts()).map(t=>t.replace(/\s+/g,' ')));
await page.screenshot({path:I+'dt.png'});
await b.close();})();
