const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'agent/products.php');await p.screenshot({path:m.IMG+'/prod_list.png'});
await m.openModal(p,'modals/product/product_add.php?type=product');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'prod_add');
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show select')].map(s=>s.name+':'+[...s.options].map(o=>o.value+'='+o.text).join('/'))));
await b.close();})();
