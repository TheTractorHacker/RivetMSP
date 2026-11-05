const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/invoices.php?client_id=11');
 await m.openModal(p,'modals/invoice/invoice_add.php?client_id=11','md');
 await m.fillModal(p,{val:{scope:'QA-UI Monthly managed services',discount:'10'}});
 const r=await m.submit(p);console.log('after',r,await p.evaluate(()=>document.body.innerText.match(/(Could not|Invoice|error|required)[^\n]*/i)));console.log(p.errs);await b.close();})();
