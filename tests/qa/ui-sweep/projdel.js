const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();
for(const id of [5,6,7]){await m.go(p,'agent/project_details.php?project_id='+id);
 const steps=[['a.confirm-link[href*="close_project"]','close'],['a.confirm-link[href*="archive_project"]','archive'],['a.confirm-link[href*="delete_project"]','delete']];
 for(const [sel,nm] of steps){ if(nm!=='close') await m.go(p,'agent/project_details.php?project_id='+id);
  const ok=await p.$(sel);if(!ok){console.log(id,nm,'link absent');continue}
  await ok.evaluate(e=>e.click());await p.waitForSelector('#confirmationModal.show');await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('#confirmSubmitBtn')]);await p.waitForTimeout(600);
  console.log(id,nm,'->',p.url().replace(m.h.BASE,''));}}
await b.close()})();
