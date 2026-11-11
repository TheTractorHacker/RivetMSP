const m=require('./m2.js');const fs=require('fs');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,ctx,p}=await m.ctxPage();await m.go(p,'agent/payments.php');
await m.openModal(p,'modals/payment/payment_edit.php?id=2');await m.shotModal(p,'pay_edit');console.log(JSON.stringify(await m.audit(p)));
await p.evaluate(()=>{const e=document.querySelector('.modal.show [name=amount]');e.value='120.00'});console.log('edit pay',await m.submit(p),await txt(p));
await m.go(p,'agent/invoice.php?invoice_id=6');console.log(await totals(p));
// CSV export
await m.go(p,'agent/payments.php');const csrf=await p.evaluate(()=>document.querySelector('input[name=csrf_token]')&&document.querySelector('input[name=csrf_token]').value);
await m.openModal(p,'modals/payment/payment_export.php');const form=await p.evaluate(()=>{const f=document.querySelector('.modal.show form');return [f.action,f.method,[...f.elements].map(e=>e.name+'='+e.value.slice(0,10)).join('&')]});console.log(form);
const [dl]=await Promise.all([p.waitForEvent('download',{timeout:15000}).catch(()=>null),p.click('.modal.show button[type=submit]')]);
if(dl){const f=m.IMG+'/pay.csv';await dl.saveAs(f);console.log('csv',dl.suggestedFilename(),fs.readFileSync(f,'utf8').slice(0,250));}else console.log('no download');
await b.close();})();
