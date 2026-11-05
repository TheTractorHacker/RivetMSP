const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/projects.php');await m.openModal(p,'modals/project/project_add.php','md');
await m.fillModal(p,{val:{name:'QA-UI Hostile "q\' <b>x</b> & Project'}});
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));
console.log(await m.submit(p));await p.waitForTimeout(500);await p.screenshot({path:m.IMG+'/dbg4.png'});
console.log(await p.evaluate(()=>(document.querySelector('.alert')||{}).innerText));await b.close()})();
