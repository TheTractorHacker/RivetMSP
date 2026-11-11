const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
await p.goto(L.BASE+'/agent/leads_board.php',{waitUntil:'networkidle'});
await p.click('.ajax-modal:has-text("Lead")');await p.waitForSelector('.modal.show form');await p.waitForTimeout(700);
await p.fill('.modal.show input[name=name]','QA-UI Lead A');await p.evaluate(()=>{const e=document.querySelector('.modal.show input[name=contact]');e.value='QA-UI Lead Person'});
await p.click('.modal.show button[type=submit]');await p.waitForLoadState('networkidle');await p.waitForTimeout(900);
await p.screenshot({path:L.SCR+'/img/tl_board.png'});console.log(p.url(),log.join(';'));
await b.close()})();
