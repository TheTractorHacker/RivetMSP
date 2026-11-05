const m=require('./m2.js');
(async()=>{for(const dark of [false,true]){for(const vp of [[1366,768],[390,844]]){
 const {b,p}=await m.ctxPage({width:vp[0],height:vp[1]},dark);
 for(const [n,u] of [['gq','guest/guest_view_quote.php?quote_id=2&url_key=xP-qrABUZR9MI6kVY8aT-Cfp1MPQu89b']]){
  await p.goto(m.h.BASE+'/'+u,{waitUntil:'networkidle'}).catch(()=>{});
  if(dark)await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode')});
  await p.screenshot({path:`${m.IMG}/${n}_${vp[0]}_${dark?'d':'l'}.png`,fullPage:true});
  console.log(n,vp[0],dark,JSON.stringify(await m.h.audit(p,true)),p.errs);}
 await b.close();}}})();
