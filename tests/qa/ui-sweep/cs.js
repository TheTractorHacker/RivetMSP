const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/compliance_status.php');
console.log(await page.evaluate(()=>[...document.querySelectorAll('.badge')].slice(0,40).map(e=>{const c=getComputedStyle(e);return e.className+' | '+c.color+' on '+c.backgroundColor}).filter((v,i,a)=>a.indexOf(v)===i).join('\n')));
console.log(await page.evaluate(()=>{const c=getComputedStyle(document.querySelector('.btn-outline-primary')||document.body);return c.color+' '+c.borderColor}));
await b.close();})();
