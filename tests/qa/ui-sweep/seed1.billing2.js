const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/clients.php');
 await m.openModal(p,'modals/client/client_add.php','lg');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'client_add');
 await m.fillModal(p,{val:{name:'QA-UI Test Client',abbreviation:'QAUI'}});
 const r=await m.submit(p);console.log('after',r,await p.evaluate(()=>(document.querySelector('.alert')||{}).innerText));console.log(p.errs);await b.close();})();
