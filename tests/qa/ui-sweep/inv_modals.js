const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,p}=await m.ctxPage();
const U='agent/invoice.php?invoice_id=6';
await m.go(p,U);
for(const [n,u] of [['inv_edit','modals/invoice/invoice_edit.php?id=6'],['inv_copy','modals/invoice/invoice_copy.php?id=6'],['inv_rec','modals/invoice/invoice_recurring_add.php?invoice_id=6'],['inv_item','modals/invoice/invoice_item_edit.php?id=2'],['inv_pay','modals/payment/payment_add.php?id=6'],['inv_note','modals/invoice/invoice_note.php?id=6']]){
 await m.openModal(p,u);console.log(n,JSON.stringify(await m.audit(p)));await m.shotModal(p,n);await m.closeModal(p);}
// discount validation via edit modal
for(const d of ['500','-3','abc','25.505']){
 await m.openModal(p,'modals/invoice/invoice_edit.php?id=6');await p.evaluate(d=>{const e=document.querySelector('.modal.show [name=invoice_discount]');e.removeAttribute('pattern');e.value=d},d);
 console.log('discount',d,await m.submit(p),await txt(p),await totals(p));await m.go(p,U);}
console.log(p.errs);await b.close();})();
