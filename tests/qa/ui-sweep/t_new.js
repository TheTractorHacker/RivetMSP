const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
await p.goto(L.BASE+'/agent/tickets.php',{waitUntil:'networkidle'});
await p.click('button.ajax-modal:has-text("New Ticket")');
await p.waitForSelector('.modal.show form');await p.waitForTimeout(800);
// empty submit
await p.click('.modal.show button:has-text("Create")');await p.waitForTimeout(600);
await p.screenshot({path:L.SCR+'/img/new_empty.png'});
console.log('url after empty',p.url(), await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));
await p.click('.modal.show a:has-text("Contact")');await p.waitForTimeout(400);
await p.screenshot({path:L.SCR+'/img/new_contact.png'});
await p.click('.modal.show a:has-text("Assignment")');await p.waitForTimeout(400);
await p.screenshot({path:L.SCR+'/img/new_assign.png'});
console.log(JSON.stringify(log));await b.close()})();
