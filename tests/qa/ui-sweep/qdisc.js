const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,p}=await m.ctxPage();const U='agent/quote.php?quote_id=2';await m.go(p,U);
for(const d of ['500','-3','12.345','10']){await m.openModal(p,'modals/quote/quote_edit.php?id=2');await p.evaluate(d=>{const e=document.querySelector('.modal.show [name=quote_discount]');e.removeAttribute('pattern');e.value=d},d);
 console.log('disc',d,await m.submit(p),await txt(p),await totals(p));await m.go(p,U);}
for(const d of ['500','-3','10']){await m.go(p,'agent/recurring_invoice.php?recurring_invoice_id=1');await m.openModal(p,'modals/recurring_invoice/recurring_invoice_edit.php?id=1');await p.evaluate(d=>{const e=document.querySelector('.modal.show [name=recurring_invoice_discount]');e.removeAttribute('pattern');e.value=d},d);
 console.log('rdisc',d,await m.submit(p),await txt(p),await totals(p));}
await b.close();})();
