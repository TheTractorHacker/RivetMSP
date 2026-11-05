const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
const U='agent/recurring_invoice.php?recurring_invoice_id=1';
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,U);
for(const [n,u] of [['ri_edit','modals/recurring_invoice/recurring_invoice_edit.php?id=1'],['ri_item','modals/recurring_invoice/recurring_invoice_item_edit.php?id=1']]){await m.openModal(p,u);console.log(n,JSON.stringify(await m.audit(p)));await m.shotModal(p,n);await m.closeModal(p);}
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show [name],[id=recurringInvoiceNoteModal] [name]')].map(e=>e.name)));
for(const d of ['500','-3','10']){await m.openModal(p,'modals/recurring_invoice/recurring_invoice_edit.php?id=1');await p.evaluate(d=>{const e=document.querySelector('.modal.show [name=recurring_invoice_discount]');e.removeAttribute('pattern');e.value=d},d);
 console.log('disc',d,await m.submit(p),await txt(p),await totals(p));await m.go(p,U);}
await p.screenshot({path:m.IMG+'/ri3.png',fullPage:true});
console.log(p.errs);await b.close();})();
