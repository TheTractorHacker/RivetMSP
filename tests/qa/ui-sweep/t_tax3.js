const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();
for(const id of [3,4,5]){await m.go(p,'admin/tax.php');const href=await p.evaluate(id=>{const a=document.querySelector('a[href*="archive_tax='+id+'&"]');return a&&a.getAttribute('href')},id);
 if(href)await p.goto(m.h.BASE+'/admin/'+href,{waitUntil:'networkidle',referer:m.h.BASE+'/admin/tax.php'});}
await m.go(p,'admin/tax.php');
console.log(await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ')).join('\n')));
await b.close();})();
