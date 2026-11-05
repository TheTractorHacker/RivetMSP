const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:390,height:844},storageState:I+'state.json',hasTouch:true,isMobile:true});const page=await ctx.newPage();
for(const u of ['/agent/clients.php','/admin/maintenance.php']){
await page.goto(h.BASE+u);await page.screenshot({path:I+'phone_'+u.replace(/\W+/g,'_')+'_0.png'});
const t=await page.evaluate(()=>[...document.querySelectorAll('button')].filter(b=>b.getBoundingClientRect().width>0).map(b=>(b.id||b.className).toString().slice(0,60)+' @'+Math.round(b.getBoundingClientRect().top)+','+Math.round(b.getBoundingClientRect().left)));
console.log(u,t.slice(0,8));
// click app toggle
await page.locator('#itflowSidebarToggle').click();await page.waitForTimeout(700);await page.screenshot({path:I+'phone_'+u.replace(/\W+/g,'_')+'_1.png'});
console.log('after app toggle: sidebar visible?',await page.evaluate(()=>{const s=document.querySelector('.navbar-vertical');const r=s.getBoundingClientRect();return JSON.stringify({top:r.top,h:r.height,left:r.left,body:document.body.className,collapse:(document.querySelector('.navbar-vertical .collapse')||{}).className})}));
await page.locator('.navbar-vertical .navbar-toggler').first().click().catch(e=>console.log('no tabler toggler',String(e).slice(0,60)));await page.waitForTimeout(700);await page.screenshot({path:I+'phone_'+u.replace(/\W+/g,'_')+'_2.png'});
console.log('after tabler toggler',await page.evaluate(()=>(document.querySelector('.navbar-vertical .collapse')||{}).className));
}
await b.close();})();
