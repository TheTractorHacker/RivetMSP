const m=require('./m2.js');
const badge=(p)=>p.evaluate(()=>(document.querySelector('.badge.p-2, h5.badge')||{}).innerText);
(async()=>{const {b,p}=await m.ctxPage();const U='agent/invoice.php?invoice_id=6';await m.go(p,U);
await m.openModal(p,'modals/payment/payment_add.php?id=6');await m.fillModal(p,{val:{amount:'100.00',account:'1',payment_method:'Cash'}});console.log('pay',await m.submit(p));
await m.go(p,U);console.log('status after pay',await badge(p));
await m.go(p,'agent/payments.php');
await b.close();})();
