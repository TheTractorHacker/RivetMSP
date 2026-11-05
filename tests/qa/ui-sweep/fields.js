const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
const list=JSON.parse(process.argv[3]);await m.go(p,process.argv[2]);
for(const u of list){await m.openModal(p,u);
 console.log(u,JSON.stringify(await p.evaluate(()=>[...document.querySelectorAll('.modal.show input,.modal.show select,.modal.show textarea')].filter(e=>e.type!=='hidden'&&e.name).map(e=>e.name+(e.required?'*':'')+':'+e.type+(e.tagName==='SELECT'?'['+[...e.options].slice(0,4).map(o=>o.value+'='+o.text.trim().slice(0,12)).join('/')+']':''))),null,0));await m.closeModal(p);}
await b.close();})();
