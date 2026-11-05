// edit then delete QA-UI records through the real row actions. usage: node crud.js <page> [editNameField] [no-delete]
const m=require('./modal.js');
(async()=>{const pg=process.argv[2],nf=process.argv[3]||'name',nodel=process.argv[4]==='no-delete';
 const {b,p}=await m.ctxPage();await m.go(p,pg);
 const tag=await p.evaluate(()=>{ // find the edit trigger inside a container whose text includes QA-UI
   const els=[...document.querySelectorAll('[data-modal-url*="_edit.php"]')];
   for(const e of els){let c=e.closest('tr, .card, .kb-card, li');if(c&&/QA-UI/.test(c.innerText)){e.setAttribute('data-qa-edit','1');return e.getAttribute('data-modal-url')}}return null});
 console.log(pg,'edit trigger:',tag);
 if(tag){await m.openModal(p,tag,'md');const a=await m.audit(p);
  await p.evaluate(([nf])=>{const e=document.querySelector('.modal.show [name='+nf+']');if(e){e.value=e.value+' EDITED';e.dispatchEvent(new Event('input',{bubbles:true}))}},[nf]);
  await m.shotModal(p,'crud_edit_'+pg.replace(/[^a-z0-9]+/gi,'_'));
  const r=await m.submit(p);const al=await p.evaluate(()=>{const e=document.querySelector('.alert');return e?e.innerText.trim().slice(0,100):''});
  const has=await p.evaluate(()=>/EDITED/.test(document.body.innerText));console.log(' edit ->',r,'| persisted on list:',has,'|',al.replace(/\n/g,' '),p.errs.length?'ERR '+p.errs.join(';'):'');}
 if(!nodel){
  const del=await p.evaluate(()=>{const els=[...document.querySelectorAll('a.confirm-link[href*="delete_"]')];for(const e of els){let c=e.closest('tr, .card, .kb-card, li');if(c&&/QA-UI/.test(c.innerText)){e.setAttribute('data-qa-del','1');return e.getAttribute('href')}}return null});
  console.log(' delete link:',del);
  if(del){await p.evaluate(()=>document.querySelector('[data-qa-del]').click());await p.waitForTimeout(500);
   await m.shotModal(p,'crud_confirm');const vis=await p.evaluate(()=>!!document.querySelector('#confirmationModal.show'));
   await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('#confirmSubmitBtn')]);await p.waitForTimeout(700);
   const al=await p.evaluate(()=>{const e=document.querySelector('.alert');return e?e.innerText.trim().slice(0,100):''});
   const raw=await p.evaluate(()=>/(Fatal error|Warning:|Notice:|Uncaught)/.exec(document.body.innerText));
   console.log(' confirm shown:',vis,'-> ',p.url().replace(m.h.BASE,''),'|',al.replace(/\n/g,' '),raw?'RAW '+raw[0]:'');}}
 await b.close();})();
