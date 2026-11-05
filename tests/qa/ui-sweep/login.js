const h=require('./h.js');
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080}});const page=await ctx.newPage();
await h.login(page,'admin');console.log(page.url());
await ctx.storageState({path:'/home/sysadmin/ui-sweep-adminother-img/state.json'});
const links=await page.evaluate(()=>[...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href')));
console.log([...new Set(links)].length);require('fs').writeFileSync('/home/sysadmin/ui-sweep-adminother-img/navlinks.txt',[...new Set(links)].join('\n'));
await page.screenshot({path:'/home/sysadmin/ui-sweep-adminother-img/home.png'});await b.close();})();
