const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();const C='client_id=1';
await m.go(p,'agent/network_drives.php?'+C);await m.openModal(p,'modals/network_drive/network_drive_add.php?'+C,'md');
await m.fillModal(p,{val:{name:'QA-UI BS Drive',path:'\\\\srv01\\share\\QA-UI',letter:'Z',purpose:"Joe's \"files\""}});console.log(await m.submit(p));
const t=await p.evaluate(()=>{for(const e of document.querySelectorAll('[data-modal-url*="network_drive_details"]')){if(/BS Drive/.test(e.closest('tr').innerText))return e.getAttribute('data-modal-url')}});console.log(t);
await m.openModal(p,t,'md');console.log(await p.evaluate(()=>document.querySelector('.modal.show .modal-body').innerText.replace(/\n+/g,' | ').slice(0,300)));await m.shotModal(p,'bs_details');
await m.closeModal(p);console.log(await p.evaluate(()=>[...document.querySelectorAll('tr')].filter(t=>/BS Drive/.test(t.innerText)).map(t=>t.innerText.replace(/\s+/g,' ')).join('')));
await b.close()})();
