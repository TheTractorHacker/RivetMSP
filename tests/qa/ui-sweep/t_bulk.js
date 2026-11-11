const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);p.on('dialog',d=>d.accept());
const st=async n=>{await p.waitForTimeout(800);await p.screenshot({path:L.SCR+'/img/tbk_'+n+'.png'});console.log(n,p.url().replace(L.BASE,'').slice(0,60),[...new Set(log.splice(0))].join(';'))};
await p.goto(L.BASE+'/agent/tickets.php',{waitUntil:'networkidle'});
await p.locator('tbody input[type=checkbox]').nth(0).check({force:true});await p.locator('tbody input[type=checkbox]').nth(1).check({force:true});await st('bulk_selected');
console.log(await p.evaluate(()=>[...document.querySelectorAll('#bulkActionButton, .bulk-actions, [id*=bulk]')].map(e=>e.id+':'+(e.offsetParent?'vis':'hid')+':'+e.textContent.trim().slice(0,30)).slice(0,8)));
// saved view add
await p.goto(L.BASE+'/agent/tickets.php',{waitUntil:'networkidle'});
await p.click('.btn-add-view');await p.waitForSelector('.modal.show form');await p.waitForTimeout(600);
await p.click('.modal.show button[type=submit]');await p.waitForTimeout(500);await st('view_empty');
await p.fill('.modal.show input[name=name]','QA-UI View');await p.click('.modal.show button[type=submit]');await p.waitForLoadState('networkidle');await st('view_saved');
console.log('view listed',await p.evaluate(()=>document.body.innerText.includes('QA-UI View')));
// filters
await p.goto(L.BASE+'/agent/tickets.php?q=zzzzqqq',{waitUntil:'networkidle'});await st('search_noresult');
await p.goto(L.BASE+'/agent/tickets.php?view=kanban',{waitUntil:'networkidle'});await st('kanban');
await b.close()})();
