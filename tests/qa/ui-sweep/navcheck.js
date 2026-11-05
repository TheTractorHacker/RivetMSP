const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
const errs=[];page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('pageerror',e=>errs.push('PE '+e));page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,''))});
await page.goto(h.BASE+'/agent/clients.php');
// expand all groups and collect links
const links=await page.evaluate(()=>[...document.querySelectorAll('.navbar-vertical a[href], aside a[href], #sidebar a[href]')].map(a=>({h:a.getAttribute('href'),t:a.textContent.trim().replace(/\s+/g,' ').slice(0,40)})).filter(l=>l.h&&!l.h.startsWith('#')&&!l.h.startsWith('http')));
console.log(links.length);
for(const l of links){errs.length=0;let r;try{r=await page.goto(new URL(l.h,page.url().replace(/\?.*/,'')).href,{waitUntil:'load'});}catch(e){console.log('ERR',l.h,String(e).slice(0,60));continue}
await page.waitForTimeout(300);
const st=await page.evaluate(()=>{const a=[...document.querySelectorAll('.navbar-vertical .active, aside .active, #sidebar .active')].filter(e=>e.matches('a,li,.nav-link,.dropdown-item')).map(e=>(e.textContent.trim().replace(/\s+/g,' ').slice(0,25)));return {active:[...new Set(a)].slice(0,4),h1:(document.querySelector('h1,h3.card-title,.card-title')||{}).textContent,hs:document.documentElement.scrollWidth>document.documentElement.clientWidth+1,raw:/Fatal error|Warning:|Notice:|Deprecated:/.test(document.body.innerText)}});
console.log(r.status(),l.t,'|',l.h,'|',JSON.stringify(st).slice(0,160),errs.slice(0,3).join(' ; '));}
await b.close();})();
