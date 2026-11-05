const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,ctx,p}=await m.ctxPage();const U='agent/invoice.php?invoice_id=6';await m.go(p,U);

// item invalid: empty name, negative qty
await m.openModal(p,'modals/invoice/invoice_item_edit.php?id=2');await p.evaluate(()=>{const q=document.querySelector('.modal.show [name=qty]');q.removeAttribute('pattern');q.value='-2'});
console.log('neg qty',await m.submit(p),await txt(p),await totals(p));await m.go(p,U);await m.openModal(p,'modals/invoice/invoice_item_edit.php?id=2');await p.evaluate(()=>{document.querySelector('.modal.show [name=qty]').value='2';document.querySelector('.modal.show [name=price]').value='100.00'});console.log('restore',await m.submit(p),await totals(p));
await m.go(p,U);
// delete the Plain item (id 4)
const tok=await p.evaluate(()=>document.querySelector('input[name=csrf_token]').value);
await p.goto(m.h.BASE+'/agent/post.php?delete_invoice_item=4&csrf_token='+tok,{waitUntil:'load',referer:m.h.BASE+'/agent/'+U}).catch(e=>console.log('goto',String(e).slice(0,60)));await p.waitForTimeout(1500);console.log('del item',await txt(p),await totals(p));
// quote->invoice modal
await m.go(p,'agent/quote.php?quote_id=2');await m.openModal(p,'modals/quote/quote_to_invoice.php?id=2');console.log('q2i',JSON.stringify(await m.audit(p)));await m.shotModal(p,'q2i');await m.closeModal(p);
for(const [n,u] of [['quote_edit','modals/quote/quote_edit.php?id=2'],['quote_copy','modals/quote/quote_copy.php?id=2'],['quote_item','modals/quote/quote_item_edit.php?id=2'],['inv_copy2','modals/invoice/invoice_copy.php?id=6']]){await m.openModal(p,u);console.log(n,JSON.stringify(await m.audit(p)));await m.shotModal(p,n);await m.closeModal(p);}
console.log(p.errs);await b.close();})();
