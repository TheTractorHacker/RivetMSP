const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'admin/tax.php');await p.screenshot({path:m.IMG+'/tax_list.png'});
for(const v of [{name:'QA-UI bad',percent:'-5'},{name:'QA-UI big',percent:'1000'},{name:'QA-UI txt',percent:'abc'},{name:'QA-UI Sales Tax',percent:'8.5'}]){
 await m.openModal(p,'modals/tax/tax_add.php');await m.fillModal(p,{val:v});
 console.log(JSON.stringify(v),await m.submit(p),(await p.evaluate(()=>document.body.innerText.match(/(QA-UI[^\n]*|error[^\n]*|Tax[^\n]*(added|created)[^\n]*)/i)||[''])[0]));
 console.log(await p.evaluate(()=>[...document.querySelectorAll('.toast,.alert,.swal2-popup')].map(e=>e.innerText.slice(0,100))));
 await m.go(p,'admin/tax.php');}
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ')).join('\n')));
console.log(p.errs);await b.close();})();
