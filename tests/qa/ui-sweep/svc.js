const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/services.php?client_id=11');await p.screenshot({path:m.IMG+'/svc_l.png',fullPage:true});
await m.openModal(p,'modals/service/service_add.php?client_id=11','lg');console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'svc_add');
let r=await m.submit(p);console.log('empty',r,!!(await p.$('.modal.show')));
await m.fillModal(p,{val:{name:'QA-UI Service',description:'QA-UI desc',importance:'High'}});console.log('create',await m.submit(p),await txt(p));
await m.go(p,'agent/services.php?client_id=11');
const rows=await p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ').slice(0,80)+' || '+[...r.querySelectorAll('a')].map(a=>a.innerText.trim()+'=>'+(a.dataset.modalUrl||a.getAttribute('href')||'').replace(/csrf.*/,'')).join(' ; ')));console.log(rows);
const em=rows[0]&&rows[0].match(/modals\/service\/service_edit\.php\?[^ ;]*/);
if(em){await m.openModal(p,em[0],'lg');console.log('edit',JSON.stringify(await m.audit(p)));await m.shotModal(p,'svc_edit');await p.evaluate(()=>{document.querySelector('.modal.show [name=description]').value='QA-UI desc 2'});console.log('save',await m.submit(p),await txt(p));}
console.log(p.errs);await b.close();})();
