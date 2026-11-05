const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
for(const u of ['/agent/tickets.php','/agent/ticket.php?client_id=6&ticket_id=48','/agent/clients.php']){
await p.goto(L.BASE+u,{waitUntil:'networkidle'});
const trig=p.locator('[data-modal-url*="ticket_add_v2"]').first();
if(!(await trig.count())){console.log(u,'no trigger');continue}
await p.evaluate(x=>window.openAjaxModal(x,'lg',{}),await trig.getAttribute('data-modal-url'));
await p.waitForSelector('.modal.show form');await p.waitForTimeout(1000);
if(await p.locator('.modal.show select[name=client_id]').count()){await L.setSel(p,'.modal.show','client_id','Summit');}await p.waitForTimeout(1500);
if(await p.locator('.modal.show select[name=client_id]').count()){await L.setSel(p,'.modal.show','client_id','Fox River');}await p.waitForTimeout(1500);
console.log(u,JSON.stringify(log));log.length=0;}
await b.close()})();
