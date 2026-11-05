const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
const errs=[];page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('pageerror',e=>errs.push('PE '+e));page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,''))});
await page.goto(h.BASE+'/agent/clients.php');
// live search
await page.fill('#globalSearchInput','har');await page.waitForTimeout(1200);await page.screenshot({path:I+'c_search.png',clip:{x:0,y:0,width:1000,height:500}});
console.log('search results visible', await page.evaluate(()=>!document.getElementById('globalSearchResults').classList.contains('d-none')));
await page.fill('#globalSearchInput','zzzzqq');await page.waitForTimeout(1200);await page.screenshot({path:I+'c_search_none.png',clip:{x:0,y:0,width:1000,height:300}});
await page.keyboard.press('Escape');
await page.fill('#globalSearchInput','');
// bell
await page.click('a[aria-label^=Notifications]');await page.waitForTimeout(1500);await page.screenshot({path:I+'c_bell.png'});
console.log('modal open', await page.evaluate(()=>document.querySelector('.modal.show')?document.querySelector('.modal.show').className:'none'),'focus in modal',await page.evaluate(()=>!!document.activeElement.closest('.modal')));
await page.keyboard.press('Escape');await page.waitForTimeout(700);console.log('after esc',await page.evaluate(()=>!!document.querySelector('.modal.show')));
// backdrop click
await page.click('a[aria-label^=Notifications]');await page.waitForTimeout(1200);await page.mouse.click(1900,1000);await page.waitForTimeout(700);console.log('after backdrop',await page.evaluate(()=>!!document.querySelector('.modal.show')));
// user menu
await page.click('a[aria-label="Account menu"]');await page.waitForTimeout(400);await page.screenshot({path:I+'c_usermenu.png',clip:{x:1300,y:0,width:620,height:400}});
await page.keyboard.press('Escape');
console.log(errs);
await b.close();})();
