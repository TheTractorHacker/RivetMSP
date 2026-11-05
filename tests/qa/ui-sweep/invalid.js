// submit every add modal EMPTY and with hostile values; report validation behaviour
const m=require('./modal.js');
const C='client_id=1';
const JOBS=[
 ['agent/credentials.php?'+C,'modals/credential/credential_add.php?'+C,'name'],
 ['agent/domains.php?'+C,'modals/domain/domain_add.php?'+C,'name'],
 ['agent/certificates.php?'+C,'modals/certificate/certificate_add.php?'+C,'name'],
 ['agent/software.php?'+C,'modals/software/software_add.php?'+C,'name'],
 ['agent/assets.php?'+C,'modals/asset/asset_add.php?'+C,'name'],
 ['agent/networks.php?'+C,'modals/network/network_add.php?'+C,'name'],
 ['agent/network_drives.php?'+C,'modals/network_drive/network_drive_add.php?'+C,'name'],
 ['agent/printers.php?'+C,'modals/printer/printer_add.php?'+C,'name'],
 ['agent/racks.php?'+C,'modals/rack/rack_add.php?'+C,'name'],
 ['agent/services.php?'+C,'modals/service/service_add.php?'+C,'name'],
 ['agent/vendors.php?'+C,'modals/vendor/vendor_add.php?'+C,'name'],
 ['agent/projects.php','modals/project/project_add.php','name'],
 ['agent/files.php?'+C,'modals/folder/folder_add.php?'+C,'name'],
 ['agent/files.php?'+C,'modals/document/document_add.php?'+C,'name'],
 ['agent/kb_articles.php','modals/kb_article/kb_article_add.php','title'],
];
(async()=>{const {b,p}=await m.ctxPage();
 for(const [pg,mu,nf] of JOBS){p.errs=[];await m.go(p,pg);await m.openModal(p,mu,'md');
  const before=p.url();
  const r=await p.evaluate(()=>{const mo=document.querySelector('.modal.show');const f=mo.querySelector('form');const inv=[...f.querySelectorAll(':invalid')].filter(e=>e.name).map(e=>e.name);return {inv,req:[...f.querySelectorAll('[required]')].length}});
  const btn=await p.$('.modal.show button[type=submit]');await btn.click();await p.waitForTimeout(500);
  const stillOpen=await p.evaluate(()=>!!document.querySelector('.modal.show'));
  const shown=await p.evaluate(()=>{const e=document.querySelector('.modal.show :invalid');return e?(e.validationMessage||'')+' @'+e.name:''});
  const vis=await p.evaluate(()=>{const e=document.querySelector('.modal.show :invalid');if(!e)return 'n/a';const r=e.getBoundingClientRect();return r.width>0&&r.height>0?'visible':'HIDDEN(select2/tabs?)'});
  // hostile value
  await m.closeModal(p);await m.go(p,pg);await m.openModal(p,mu,'md');
  await m.fillModal(p,{val:{[nf]:'<img src=x onerror=alert(1)> "\' & QA-UI-XSS'}});
  const rr=await m.submit(p);const al=await p.evaluate(()=>{const e=document.querySelector('.alert, .toast');return e?e.innerText.trim().slice(0,100):''});
  const raw=await p.evaluate(()=>/(Fatal error|Warning:|Notice:|Stack trace|Uncaught|mysqli_sql)/.exec(document.body.innerText));
  const dlg=await p.evaluate(()=>!!document.querySelector('img[src=x]'));
  console.log(mu.split('?')[0].split('/').pop(),'| empty: invalid',JSON.stringify(r.inv).slice(0,70),'stillOpen',stillOpen,'msg',JSON.stringify(shown).slice(0,50),vis,'| hostile ->',rr,al.replace(/\n/g,' '),raw?'RAW '+raw[0]:'', dlg?'XSS-IMG-RENDERED':'',p.errs.length?'ERR '+p.errs.join(';').slice(0,120):'');}
 await b.close();})();
