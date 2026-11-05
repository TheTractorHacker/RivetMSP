// usage: node pages.js <listfile|comma paths> [tag] ; env VP=1920x1080 DARK=1 AXE=1
process.env.QA_BASE='http://127.0.0.1:8218';
const h=require('./h.js');const fs=require('fs');
const IMG='/home/sysadmin/sweep-docs-img';
const arg=process.argv[2];const tag=process.argv[3]||'p';
const paths=fs.existsSync(arg)?fs.readFileSync(arg,'utf8').split('\n').filter(Boolean):arg.split(',');
const [w,hh]=(process.env.VP||'1920x1080').split('x').map(Number);const dark=!!process.env.DARK;
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:w,height:hh},storageState:IMG+'/state.json'});const p=await ctx.newPage();
let errs=[];p.on('console',m=>{if(m.type()==='error')errs.push('C:'+m.text().slice(0,140))});p.on('pageerror',e=>errs.push('JS:'+String(e).slice(0,140)));
p.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,100))});
for(const u of paths){errs=[];
 try{await p.goto(h.BASE+'/'+u,{waitUntil:'networkidle',timeout:30000}).catch(()=>{});
 if(!process.env.DARK)await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','light');document.body.classList.remove('dark-mode')});if(dark)await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode')});
 await p.waitForTimeout(300);
 const a=await h.audit(p,!!process.env.AXE);
 const name=tag+'_'+u.replace(/[^a-z0-9]+/gi,'_').slice(0,60)+(dark?'_d':'_L')+'_'+w;
 const mx=process.env.MAXH?+process.env.MAXH:1000;
 const bh=await p.evaluate(()=>{let m=0;document.querySelectorAll('.page-body .card, .page-body .small-box, .page-body table, .page-body form, .page-body .alert').forEach(c=>{const r=c.getBoundingClientRect();if(r.height>0)m=Math.max(m,r.bottom+scrollY)});return Math.ceil(m||600)});
 const x0=w>=1000?256:0;
 if(process.env.FULL||bh<=600)await p.screenshot({path:IMG+'/'+name+'.png',fullPage:true});else await p.screenshot({path:IMG+'/'+name+'.png',fullPage:true,clip:{x:x0,y:56,width:w-x0,height:Math.max(200,Math.min(bh-56+16,mx))}});
 const ax=a.axe&&Array.isArray(a.axe)?a.axe.map(x=>x.id+':'+x.n).join(','):'';
 console.log(u,'|',p.url().replace(h.BASE,''),'|',JSON.stringify({hs:a.hscroll,h1:a.h1,sm:a.small,cl:a.clipped,nn:a.noname,raw:a.rawerr}),ax?'AXE '+ax:'',errs.length?'ERR '+errs.slice(0,5).join(' ; '):'');
 }catch(e){console.log(u,'FAIL',String(e).slice(0,100))}}
await b.close();})();
