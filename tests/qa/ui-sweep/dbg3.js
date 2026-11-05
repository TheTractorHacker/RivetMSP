const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/assets.php?client_id=1');await m.openModal(p,'modals/asset/asset_add.php?client_id=1','md');
await m.fillModal(p,{val:{name:'QA-UI-ASSET-01',type:'Laptop'}});
console.log(await p.evaluate(()=>{const f=document.querySelector('.modal.show form');return [f.action,[...new FormData(f).entries()].filter(e=>e[0]!='csrf_token').map(e=>e.join('=')).join('&').slice(0,400)]}));
await m.submit(p);await p.screenshot({path:m.IMG+'/dbg_after.png'});console.log(await p.evaluate(()=>document.body.innerText.slice(0,300)));
await b.close()})();
