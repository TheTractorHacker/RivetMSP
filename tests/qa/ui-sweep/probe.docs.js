const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,process.argv[2]);
const r=await p.evaluate((sel)=>{const rows=[...document.querySelectorAll(sel)].slice(0,3);return rows.map(tr=>[...tr.children].slice(0,3).map(td=>{const c=getComputedStyle(td);return td.className+'|'+c.backgroundColor+'|'+c.boxShadow.slice(0,40)}))},process.argv[3]||'table tbody tr');
console.log(JSON.stringify(r,null,1));await b.close()})();
