const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/assets.php?client_id=1');
console.log(await p.evaluate(()=>{const a=document.querySelector('a[href*="asset_id=26"]');return a.outerHTML.replace(/\s+/g,' ')+'\n'+a.closest('tr').innerText.slice(0,200)}));await b.close()})();
