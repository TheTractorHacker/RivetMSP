const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();const pg='agent/project_details.php?project_id=5';
await m.go(p,pg);await m.openModal(p,'modals/project/task_add.php?project_id=5','md');await m.fillModal(p,{val:{name:'QA-UI Task BACKWARDS',start:'2026-12-20',due:'2026-11-01',progress:'10'}});
console.log('submit',await m.submit(p));
await p.waitForTimeout(500);console.log(await p.evaluate(()=>(document.querySelector('.alert,.toast')||{innerText:'(none)'}).innerText.slice(0,100)));
await b.close()})();
