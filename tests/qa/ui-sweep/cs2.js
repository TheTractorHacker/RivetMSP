const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
await page.goto(h.BASE+'/admin/compliance_status.php');
console.log(await page.evaluate(()=>{const e=[...document.querySelectorAll('.badge')].find(x=>/Needs/.test(x.textContent));const c=getComputedStyle(e);const r=e.getBoundingClientRect();const td=e.closest('td').getBoundingClientRect();return JSON.stringify({ws:c.whiteSpace,w:r.width,h:r.height,td:td.width,pad:c.padding,fs:c.fontSize,disp:c.display,minw:c.minWidth,maxw:c.maxWidth,br:c.borderRadius, tdw:getComputedStyle(e.closest('td')).width,tbl:getComputedStyle(e.closest('table')).tableLayout})}));
await b.close();})();
