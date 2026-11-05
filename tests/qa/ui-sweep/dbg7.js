const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/assets.php?client_id=1');
console.log(await p.evaluate(()=>[...document.querySelectorAll('a[href*="asset_id=26"]')].map(a=>a.outerHTML.replace(/\s+/g,' ').slice(0,160)+' @'+a.parentElement.tagName+'.'+a.parentElement.className)));await b.close()})();
