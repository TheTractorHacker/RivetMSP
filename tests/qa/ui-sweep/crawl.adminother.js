// usage: node crawl.js <listfile> <tag> [theme dark|light] [WxH]
const h=require('./h.js');const fs=require('fs');
const [,,list,tag,theme,size]=process.argv;const [W,H]=(size||'1920x1080').split('x').map(Number);
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:W,height:H},storageState:'/home/sysadmin/ui-sweep-adminother-img/state.json'});const page=await ctx.newPage();
if(theme==='dark')await ctx.addCookies([]);
const res=[];let cur='';const errs=[];
page.on('console',m=>{if(m.type()==='error')errs.push('console:'+m.text().slice(0,120))});
page.on('pageerror',e=>errs.push('pageerror:'+String(e).slice(0,120)));
page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,90))});
for(const u of fs.readFileSync(list,'utf8').split('\n').filter(Boolean)){errs.length=0;
 try{await page.goto(h.BASE+u,{waitUntil:'networkidle',timeout:30000});}catch(e){errs.push('goto:'+String(e).slice(0,80))}
 const a=await h.audit(page,true).catch(e=>({err:String(e)}));
 const name=u.replace(/[^a-z0-9]+/gi,'_').replace(/^_|_$/g,'');
 try{await page.screenshot({path:`/home/sysadmin/ui-sweep-adminother-img/${tag}_${name}.png`,fullPage:false});}catch(e){errs.push('shot:'+String(e).slice(0,50))}
 res.push({u,url:page.url().replace(h.BASE,''),title:await page.title(),errs:[...errs],a});}
fs.writeFileSync(`/home/sysadmin/ui-sweep-adminother-img/${tag}.json`,JSON.stringify(res,null,1));await b.close();})();
