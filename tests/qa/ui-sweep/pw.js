const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/agent/user/user_security.php');await page.fill('input[name=current_password]','wrongwrong1');await page.fill('input[name=new_password]','ValidLongPass#9999');
await page.click('button[name=edit_your_user_password]');await page.waitForLoadState('load');await page.waitForTimeout(800);
console.log(await page.evaluate(()=>[...document.querySelectorAll('.toast.show,.alert,#alert,.toast-message')].filter(e=>e.offsetParent).map(e=>e.textContent.trim().slice(0,120))));await page.screenshot({path:I+'pf_pw2.png',clip:{x:0,y:0,width:1920,height:300}});
await b.close();})();
