const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();const C='client_id=1';
for(const f of ['QA-UI-note.txt','QA-UI-bad.exe']){
await m.go(p,'agent/files.php?'+C);await m.openModal(p,'modals/file/file_upload.php?'+C,'md');
await p.setInputFiles('#fileInput','/home/sysadmin/sweep-docs-img/'+f);
console.log(f,await m.submit(p));await p.waitForTimeout(400);
console.log(' toast:',await p.evaluate(()=>(document.querySelector('.alert,.toast')||{innerText:'(none)'}).innerText.replace(/\n/g,' ').slice(0,120)),' errs',p.errs.join(';').slice(0,100));}
await m.go(p,'agent/files.php?'+C);await p.screenshot({path:m.IMG+'/files_after.png',clip:{x:256,y:60,width:1660,height:520}});
console.log(await p.evaluate(()=>[...document.body.innerText.matchAll(/QA-UI[^\n]*/g)].map(x=>x[0]).join(' || ')));
await b.close()})();
