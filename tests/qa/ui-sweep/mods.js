// usage: node mods.js <page> [client_id]  -> opens every distinct modal trigger on the page (light + dark shots)
const m=require('./modal.js');const fs=require('fs');
(async()=>{const page=process.argv[2];const only=process.argv[3];
 const {b,p}=await m.ctxPage();await m.go(p,page);
 const trig=await p.evaluate(()=>{const seen={},out=[];document.querySelectorAll('[data-modal-url]').forEach(e=>{const u=e.getAttribute('data-modal-url');const k=u.split('?')[0];if(seen[k])return;seen[k]=1;out.push([u,e.getAttribute('data-modal-size')||'md',(e.textContent||'').trim().slice(0,30)])});return out});
 console.log(page,'triggers',trig.length);
 for(const [u,s,t] of trig){if(only&&!u.includes(only))continue;p.errs=[];
  const nm=page.replace(/[^a-z0-9]+/gi,'_')+'__'+u.split('?')[0].split('/').pop().replace('.php','');
  for(const dark of [false,true]){
   await p.evaluate((d)=>{document.documentElement.setAttribute('data-bs-theme',d?'dark':'light');document.body.classList.toggle('dark-mode',d)},dark);
   await m.openModal(p,u,s);const a=await m.audit(p);
   if(!dark)console.log(u.split('?')[0],'['+t+']',JSON.stringify(a),p.errs.length?'ERR '+p.errs.join(';'):'');
   await m.shotModal(p,'m_'+nm+(dark?'_d':'_l'));await m.closeModal(p);}
 }
 await b.close();})();
