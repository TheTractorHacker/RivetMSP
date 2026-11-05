const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'agent/products.php');
await m.openModal(p,'modals/product/product_add.php?type=product');await m.shotModal(p,'prod_add2');
// validation: empty submit
let r=await m.submit(p);console.log('empty submit ->',r,'modal still open:',!!(await p.$('.modal.show')));
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));
await m.closeModal(p);
const items=[['QA-UI Widget','100.00','1'],['QA-UI Gadget','49.99','2'],['QA-UI Plain','25.00','0'],['QA-UI Negative','-5','0']];
for(const [n,pr,tx] of items){await m.openModal(p,'modals/product/product_add.php?type=product');
 await m.fillModal(p,{val:{name:n,price:pr,tax:tx,category:'4'}});
 console.log(n,await m.submit(p),await p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.slice(0,100))));await m.go(p,'agent/products.php');}
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ')).join('\n')));
console.log(p.errs);await b.close();})();
