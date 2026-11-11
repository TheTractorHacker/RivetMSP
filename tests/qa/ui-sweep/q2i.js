const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/quote.php?quote_id=2');
await m.openModal(p,'modals/quote/quote_to_invoice.php?quote_id=2');await m.shotModal(p,'q2i');console.log(JSON.stringify(await m.audit(p)));
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show [name]')].map(e=>e.name+'='+e.type+'='+e.value)));
console.log('convert',await m.submit(p),await txt(p),await totals(p));
await b.close();})();
