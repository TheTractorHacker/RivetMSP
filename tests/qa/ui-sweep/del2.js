const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
async function delFrom(p,url){await m.go(p,url);p.removeAllListeners('dialog');
 await p.click('button:has(i.fa-ellipsis-v), a:has(i.fa-ellipsis-v)');await p.waitForTimeout(400);
 const del=await p.$('a.confirm-link:visible:has-text("Delete")');await del.click();await p.waitForTimeout(900);
 const ok=await p.$('.modal.show button:has-text("Yes"), .modal.show a:has-text("Yes")');await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),ok.click()]);await p.waitForTimeout(1000);
 console.log('after delete',url,'->',p.url().replace(m.h.BASE,''),await txt(p));}
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'agent/invoices.php?client_id=11');await m.openModal(p,'modals/invoice/invoice_add.php?client_id=11');await m.fillModal(p,{val:{scope:'QA-UI delete me',invoice_discount:'0'}});
await p.evaluate(()=>{const s=document.querySelector('.modal.show select[name=category]');const o=[...s.options].find(o=>o.value);s.value=o.value;$(s).trigger('change')});
console.log(await m.submit(p),await txt(p));const u=p.url().replace(m.h.BASE+'/','');console.log('created',u);
await delFrom(p,u);
await delFrom(p,'agent/recurring_invoice.php?recurring_invoice_id=1');
console.log(p.errs);await b.close();})();
