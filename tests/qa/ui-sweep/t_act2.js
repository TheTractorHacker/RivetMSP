const L=require('./lib.js');
const T='/agent/ticket.php?client_id=6&ticket_id=48';
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
await p.goto(L.BASE+T,{waitUntil:'networkidle'});
const toggles=['Client actions','New','More actions','Insert Canned Response','Set status & submit','Reply actions','Actions'];
for(const t of toggles){
  const el=p.locator(`[data-bs-toggle=dropdown][aria-label="${t}"], [data-bs-toggle=dropdown][title="${t}"], [data-bs-toggle=dropdown]:has-text("${t}")`).first();
  try{await el.click({timeout:3000});await p.waitForTimeout(500);
  const m=await p.evaluate(()=>{const d=document.querySelector('.dropdown-menu.show');if(!d)return null;const r=d.getBoundingClientRect();return {items:[...d.querySelectorAll('.dropdown-item')].map(i=>i.textContent.trim().replace(/\s+/g,' ')).slice(0,20),r:[Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)],cut:r.bottom>innerHeight||r.right>innerWidth||r.x<0}});
  console.log(t,JSON.stringify(m));
  await p.screenshot({path:L.SCR+'/img/dd_'+t.replace(/\W+/g,'_')+'.png'});
  await p.keyboard.press('Escape');await p.mouse.click(900,20);await p.waitForTimeout(300);}catch(e){console.log(t,'ERR',String(e).slice(0,80))}
}
console.log(log.join(';'));await b.close()})();
