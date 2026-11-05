const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/accounts.php');
await m.openModal(p,'modals/account/account_edit.php?id=2');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'acct_edit');
await p.evaluate(()=>{document.querySelector('.modal.show [name=name]').value='QA-UI Checking 2'});console.log('edit',await m.submit(p),await txt(p));
await m.go(p,'agent/accounts.php');console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ')+' || '+[...r.querySelectorAll('a')].map(a=>a.innerText.trim()).join(','))));
// bad inputs
await m.openModal(p,'modals/account/account_add.php');await m.fillModal(p,{val:{name:'QA-UI Weird',opening_balance:'abc'}});console.log('bad opening',await m.submit(p),await txt(p),await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));
console.log(p.errs);await b.close();})();
