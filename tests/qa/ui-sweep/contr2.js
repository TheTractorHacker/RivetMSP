const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/contracts.php?client_id=11');
await m.openModal(p,'modals/contract/contract_edit.php?contract_id=3','lg');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'contract_edit');
await p.evaluate(()=>{document.querySelector('.modal.show [name=contract_value]').value='1500'});console.log('edit',await m.submit(p),await txt(p));
await m.go(p,'agent/contracts.php?client_id=11');console.log(await p.evaluate(()=>document.querySelector('tbody').innerText.replace(/\s+/g,' ').slice(0,100)));
await m.openModal(p,'modals/contract/contract_documents.php?contract_id=3');console.log('docs',JSON.stringify(await m.audit(p)));await m.shotModal(p,'contract_docs');await m.closeModal(p);
await p.screenshot({path:m.IMG+'/contracts_l2.png',fullPage:true});
await b.close();})();
