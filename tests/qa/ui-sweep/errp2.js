const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state_tech.json'});const page=await ctx.newPage();
const r=await page.goto(h.BASE+'/admin/users.php');console.log(r.status());await page.screenshot({path:I+'e403.png'});
await b.close();})();
