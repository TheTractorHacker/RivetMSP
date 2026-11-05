// seed QA-UI records through the real add modals (client 1): node seed.js
const m=require('./modal.js');
const C='client_id=1';
const JOBS=[
 ['agent/credentials.php?'+C,'modals/credential/credential_add.php?'+C,'md',{name:'QA-UI Credential',username:'qa-ui-user',password:'QaUi#Pass2026',uri:'https://qa-ui.example.com'}],
 ['agent/domains.php?'+C,'modals/domain/domain_add.php?'+C,'md',{name:'qa-ui-example.com'}],
 ['agent/certificates.php?'+C,'modals/certificate/certificate_add.php?'+C,'md',{name:'QA-UI Cert',domain:'qa-ui-example.com'}],
 ['agent/software.php?'+C,'modals/software/software_add.php?'+C,'md',{name:'QA-UI License',type:'Other',seats:'5'}],
 ['agent/assets.php?'+C,'modals/asset/asset_add.php?'+C,'md',{name:'QA-UI-ASSET-01',type:'Laptop',ip:'',mac:'',ipv6:'',nat_ip:'',uri:'',uri_2:'',uri_client:'',anydesk_id:'',asset_tag:'QA-UI-T1',make:'QA-UI Make',model:'QA-UI Model',serial:'QAUI123',os:'Windows 11',description:'QA-UI desc',physical_location:'Desk 4'}],
 ['agent/networks.php?'+C,'modals/network/network_add.php?'+C,'md',{name:'QA-UI Net',vlan:'20',network:'10.20.0.0/24',gateway:'10.20.0.1'}],
 ['agent/network_drives.php?'+C,'modals/network_drive/network_drive_add.php?'+C,'md',{name:'QA-UI Share'}],
 ['agent/printers.php?'+C,'modals/printer/printer_add.php?'+C,'md',{name:'QA-UI Printer'}],
 ['agent/racks.php?'+C,'modals/rack/rack_add.php?'+C,'md',{name:'QA-UI Rack',make:'QA-UI Make 12U',units:'12'}],
 ['agent/services.php?'+C,'modals/service/service_add.php?'+C,'md',{name:'QA-UI Service'}],
 ['agent/vendors.php?'+C,'modals/vendor/vendor_add.php?'+C,'md',{name:'QA-UI Vendor'}],
 ['agent/projects.php','modals/project/project_add.php','md',{name:'QA-UI Project'}],
 ['agent/files.php?'+C,'modals/folder/folder_add.php?'+C,'md',{name:'QA-UI Folder'}],
 ['agent/files.php?'+C,'modals/document/document_add.php?'+C,'lg',{name:'QA-UI Document'}],
 ['agent/kb_articles.php','modals/kb_article/kb_article_add.php','lg',{title:'QA-UI KB Article'}],
];
(async()=>{const only=process.argv[2];const {b,p}=await m.ctxPage();
 for(const [pg,mu,sz,val] of JOBS){ if(only&&!mu.includes(only))continue; p.errs=[];
  await m.go(p,pg);await m.openModal(p,mu,sz);
  const a=await m.audit(p);await m.fillModal(p,{val});await m.shotModal(p,'seed_'+mu.split('/').pop().replace('.php','')+'_filled');
  const inv=await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name).join(','));console.log('invalid before submit:',inv);const r=await m.submit(p);
  const al=await p.evaluate(()=>{const e=document.querySelector('.alert');return e?e.innerText.trim().slice(0,160):''});
  console.log(mu.split('?')[0],'->',r,'|',al.replace(/\n/g,' '),p.errs.length?'ERR '+p.errs.join(';'):'');}
 await b.close();})();
