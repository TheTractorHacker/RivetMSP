const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/ticket_status.php');
await page.locator('a:has-text("QA-UI-ADM")').first().click();await page.waitForTimeout(1500);
await page.screenshot({path:I+'ts_edit.png'});
await page.selectOption('.modal.show select[name=status]','0').catch(async e=>{console.log('sel',String(e).slice(0,80));console.log(await page.locator('.modal.show select').evaluateAll(s=>s.map(x=>x.name)))});
await page.locator('.modal.show button[type=submit]').click();await page.waitForLoadState('load');await page.waitForTimeout(800);
console.log(await page.evaluate(()=>[...document.querySelectorAll('tr')].filter(t=>/QA-UI-ADM/.test(t.textContent)).map(t=>t.innerText.replace(/\s+/g,' ')).join('|')));
await b.close();})();
