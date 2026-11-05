const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
const pages=process.argv.slice(2);
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
page.on('dialog',d=>d.accept());
for(const u of pages){for(let n=0;n<6;n++){await page.goto(h.BASE+u,{waitUntil:'load'});
 const row=page.locator('tr:has-text("QA-UI-ADM")').first();if(!(await row.count()))break;
 const tg=row.locator('[data-bs-toggle=dropdown]').first();if(await tg.count())await tg.click();
 const act=row.locator('a:has-text("Delete"),a:has-text("Archive"),button:has-text("Delete"),a[aria-label=Delete]').first();
 if(!(await act.count())){console.log(u,'no delete action found');break}
 await act.click({timeout:4000});await page.waitForTimeout(900);
 // confirm dialog (custom)
 const c=page.locator('.modal.show button:has-text("Delete"),.modal.show button:has-text("Archive"),.modal.show button:has-text("Confirm"),.modal.show button:has-text("OK"),.modal.show .btn-danger,.rivet-dialog button.btn-danger,[role=dialog] button:has-text("Yes")').first();
 if(await c.count()){await c.click();}
 await page.waitForLoadState('load').catch(()=>{});await page.waitForTimeout(800);console.log(u,'deleted one, now',page.url().replace(h.BASE,''));}
}
await b.close();})();
