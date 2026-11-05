const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/payments.php');
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ').slice(0,90)+' || '+[...r.querySelectorAll('a')].map(a=>a.innerText.trim()+'=>'+(a.dataset.modalUrl||a.getAttribute('href')||'').replace(/csrf.*/,'')).join(' ; '))));
console.log('toolbar',await p.evaluate(()=>[...document.querySelectorAll('.card-header .btn,.card-header .dropdown-item')].map(x=>x.innerText.trim()+'=>'+(x.dataset.modalUrl||'')).filter(x=>x!=='=>')));
for(const [n,u] of [['pay_export','modals/payment/payment_export.php'],['pay_bulk','modals/payment/payment_bulk_add.php']]){await m.openModal(p,u);console.log(n,JSON.stringify(await m.audit(p)));await m.shotModal(p,n);await m.closeModal(p);}
await p.screenshot({path:m.IMG+'/pay_list.png',fullPage:true});
console.log(p.errs);await b.close();})();
