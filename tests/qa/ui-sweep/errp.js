const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();
let ctx=await b.newContext({viewport:{width:1920,height:1080}});let page=await ctx.newPage();
await h.login(page,'tech');console.log('tech landed',page.url());
await ctx.storageState({path:I+'state_tech.json'});
for(const u of ['/admin/users.php','/admin/backup.php','/admin/','/agent/client_overview.php?client_id=99999','/agent/ticket.php?client_id=1&ticket_id=999999','/agent/invoice.php?invoice_id=999999','/agent/user/user_details.php']){
 const r=await page.goto(h.BASE+u,{waitUntil:'load'});const t=await page.evaluate(()=>document.body.innerText.replace(/\s+/g,' ').slice(0,160));console.log(r.status(),u,'->',page.url().replace(h.BASE,''),'|',t);
 await page.screenshot({path:I+'err_'+u.replace(/\W+/g,'_')+'.png'});}
await b.close();})();
