const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:390,height:844},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/mailbox.php');
console.log(await page.evaluate(()=>{const e=document.querySelector('.card-tools');const c=getComputedStyle(e);const p=getComputedStyle(e.parentElement);const r=e.getBoundingClientRect();return JSON.stringify({d:c.display,fw:c.flexWrap,w:r.width,left:r.left,ml:c.marginLeft,pd:p.display,pfw:p.flexWrap,pw:e.parentElement.getBoundingClientRect().width,btn:[...e.children].map(x=>x.getBoundingClientRect().width),minw:c.minWidth,float:c.float})}));
await b.close();})();
