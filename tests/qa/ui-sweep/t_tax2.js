const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'admin/tax.php');
// server validation
await p.evaluate(async()=>{});
await m.openModal(p,'modals/tax/tax_add.php');await m.fillModal(p,{val:{name:'QA-UI big',percent:'1000'}});
await p.evaluate(()=>{const e=document.querySelector('.modal.show [name=percent]');e.removeAttribute('max');});
console.log('submit',await m.submit(p),await p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.slice(0,100))));
await m.go(p,'admin/tax.php');
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ')+' | '+[...r.querySelectorAll('a')].map(a=>a.textContent.trim()+':'+(a.getAttribute('href')||a.dataset.modalUrl||'')).join(' ; ')).join('\n')));
await b.close();})();
