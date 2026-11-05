const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();const pg='agent/project_details.php?project_id=5';
await m.go(p,pg);
await m.openModal(p,'modals/project/milestone_add.php?project_id=5','md');await m.fillModal(p,{val:{name:'QA-UI Milestone 1',due:'2026-12-01',order:'1',description:'QA-UI ms desc'}});console.log('milestone',await m.submit(p));
await m.go(p,pg);
await m.openModal(p,'modals/project/task_add.php?project_id=5','md');await m.fillModal(p,{val:{name:'QA-UI Task A',start:'2026-11-05',due:'2026-11-20',completion_estimate:'3',progress:'40'}});console.log('task',await m.submit(p));
// bad: due before start, progress>100
await m.go(p,pg);await m.openModal(p,'modals/project/task_add.php?project_id=5','md');await m.fillModal(p,{val:{name:'QA-UI Task BAD',start:'2026-12-20',due:'2026-11-01',progress:'250'}});
console.log('inv',await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));console.log('bad submit',await m.submit(p));
await p.screenshot({path:m.IMG+'/proj_after.png',fullPage:true});
console.log(await p.evaluate(()=>[...document.body.innerText.matchAll(/QA-UI[^\n]*/g)].map(x=>x[0]).join(' || ')));
await b.close()})();
