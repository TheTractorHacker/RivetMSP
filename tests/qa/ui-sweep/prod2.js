const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/products.php?type=product');
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].slice(0,1).map(r=>[...r.querySelectorAll('a')].map(a=>a.innerText.trim()+'=>'+(a.dataset.modalUrl||a.getAttribute('href')||'').replace(/csrf.*/,'')).join(' ; '))));
console.log('toolbar',await p.evaluate(()=>[...document.querySelectorAll('.card-header .btn,.card-header .dropdown-item,.btn-group a')].map(x=>x.innerText.trim()).filter(Boolean)));
await m.openModal(p,'modals/product/product_edit.php?id=1');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'prod_edit');
await p.evaluate(()=>{document.querySelector('.modal.show [name=price]').value='110.00'});console.log('edit',await m.submit(p),await txt(p));
await m.go(p,'agent/products.php?type=product');
await m.openModal(p,'modals/product/product_stock_add.php?id=1');console.log('stock',JSON.stringify(await m.audit(p)));await m.shotModal(p,'prod_stock');await m.closeModal(p);
await m.openModal(p,'modals/product/product_export.php');console.log('export',JSON.stringify(await m.audit(p)));await m.closeModal(p);
// negative price validation server-side
await m.openModal(p,'modals/product/product_add.php?type=product');await m.fillModal(p,{val:{name:'QA-UI Neg',price:'-5',tax:'0',category:'4'}});
await p.evaluate(()=>{const e=document.querySelector('.modal.show [name=price]');e.removeAttribute('pattern');e.removeAttribute('min');e.type='text'});
console.log('neg price',await m.submit(p),await txt(p));
await m.go(p,'agent/products.php?type=product');console.log((await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ').slice(0,80)))));
console.log(p.errs);await b.close();})();
