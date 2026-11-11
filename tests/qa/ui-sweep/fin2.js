const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/document_template.php');
await page.locator('tbody tr').first().locator('button[data-bs-toggle=dropdown]').click();await page.waitForTimeout(300);
await page.locator('.dropdown-menu.show a:has-text("Delete"),.dropdown-menu.show a:has-text("Archive")').click();await page.waitForTimeout(700);
await page.screenshot({path:I+"dt_del.png"});await page.locator(".modal.show button[type=submit], .modal.show a.btn-danger, .modal.show .btn-danger").first().click();await page.waitForLoadState('load');await page.waitForTimeout(500);
console.log('rows',await page.locator('tbody tr').count());await b.close();})();
