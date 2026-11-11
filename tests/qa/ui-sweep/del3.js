const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/quote.php?quote_id=2');
await p.click('button:has(i.fa-ellipsis-v), a:has(i.fa-ellipsis-v)');await p.waitForTimeout(400);
await (await p.$('a.confirm-link:visible:has-text("Delete")')).click();await p.waitForTimeout(900);
const ok=await p.$('.modal.show button:has-text("Yes"), .modal.show a:has-text("Yes")');await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),ok.click()]);await p.waitForTimeout(1000);
console.log('after',p.url().replace(m.h.BASE,''),await txt(p));await b.close();})();
