const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
// taxes
await m.go(p,'admin/tax.php');
for(const [n,pc] of [['QA-UI Sales Tax',8.5],['QA-UI VAT',20]]){
 await m.openModal(p,'modals/tax/tax_add.php');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'tax_add');
 await m.fillModal(p,{val:{name:n,percent:String(pc)}});console.log(n,await m.submit(p),await p.evaluate(()=>(document.querySelector('.alert')||{}).innerText));await m.go(p,'admin/tax.php');}
console.log(p.errs);await b.close();})();
