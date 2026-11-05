const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
const U='agent/invoice.php?invoice_id=6';
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,U);
await p.goto(m.h.BASE+'/agent/post.php?mark_invoice_sent=6&csrf_token='+await p.evaluate(()=>document.querySelector('input[name=csrf_token]').value),{referer:m.h.BASE+'/agent/'+U});await p.waitForTimeout(1500);console.log('marked',await txt(p));await m.go(p,U);
// reset discount to 10.00 first
await m.openModal(p,'modals/invoice/invoice_edit.php?id=6');await p.evaluate(()=>{document.querySelector('.modal.show [name=invoice_discount]').value='10.00'});console.log('disc',await m.submit(p),await txt(p),await totals(p));
for(const [amt,label] of [['0','zero'],['-5','neg'],['999999','over'],['100.00','partial']]){
 await m.go(p,U);await m.openModal(p,'modals/payment/payment_add.php?id=6');
 await m.fillModal(p,{val:{amount:amt,account:'2',payment_method:undefined}});
 await p.evaluate(()=>{const s=document.querySelector('.modal.show [name=payment_method]');const o=[...s.options].find(o=>o.value);s.value=o.value;$(s).trigger('change');const e=document.querySelector('.modal.show [name=amount]');e.removeAttribute('pattern');e.removeAttribute('min');e.removeAttribute('max');});
 const r=await m.submit(p);console.log(label,amt,r,await txt(p));await m.go(p,U);console.log(await totals(p));}
await p.screenshot({path:m.IMG+'/inv_paid.png',fullPage:true});
console.log(p.errs);await b.close();})();
