const L=require('./lib.js');const fs=require('fs');
const T='/agent/ticket.php?client_id=6&ticket_id=48';
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);p.on('dialog',d=>d.accept());
const st=async n=>{await p.waitForTimeout(900);await p.screenshot({path:L.SCR+'/img/tk_'+n+'.png'});const a=await L.audit(p,false);console.log(n,'|',p.url().replace(L.BASE,'').slice(0,50),'|',(await p.evaluate(()=>(document.querySelector('.alert, .toast, .it-toast')||{}).innerText||'')).replace(/\n/g,' ').slice(0,90),'|',a.rawerr||'', [...new Set(log.splice(0))].join(';'))};
const go=async()=>{await p.goto(L.BASE+T,{waitUntil:'networkidle'})};
const mod=async u=>{await p.evaluate(u=>window.openAjaxModal(u,'md',{}),u);await p.waitForSelector('.modal.show .modal-content');await p.waitForTimeout(700)};
const msub=async()=>{await p.click('.modal.show button[type=submit]');await p.waitForLoadState('networkidle');};
await go();
// canned reply
await p.click('button:has-text("Insert Canned Response")');await p.click('.dropdown-menu.show .dropdown-item >> nth=0');await p.waitForTimeout(800);await st('canned');
console.log('editor has text:',await p.evaluate(()=>tinymce.get('ticket-reply-editor').getContent().length));
// time entry
await p.fill('input[placeholder="00"] >> nth=1','15').catch(e=>console.log('time fill',String(e).slice(0,60)));
await st('time_filled');
// post public note with email notifications off
await go();
// turn email notification toggle off
const tog=p.locator('text=Email notifications').first();await tog.click().catch(()=>{});await p.waitForTimeout(800);
await go();console.log('email toggle state', await p.evaluate(()=>[...document.querySelectorAll('input[type=checkbox]')].filter(c=>c.closest('label,.form-check,.form-switch')&&/Email notif/.test(c.closest('label,.form-check,.form-switch').innerText)).map(c=>c.checked)));
// task add
await p.fill('input[placeholder^="Add a task"]','QA-UI task one');await p.click('button:has-text("Add")');await p.waitForLoadState('networkidle');await p.waitForTimeout(800);await st('task_added');
// schedule add
await mod('modals/ticket/ticket_schedule_add.php?ticket_id=48');
await st('sched_modal');
await p.click('.modal.show button[type=submit]');await p.waitForTimeout(600);await st('sched_empty');
// attachment modal (no file) submit
await p.keyboard.press('Escape');await p.waitForTimeout(500);
await mod('modals/ticket/ticket_attachment_add.php?ticket_id=48');
fs.writeFileSync(L.SCR+'/qa-ui-attach.txt','QA-UI attachment');
await p.setInputFiles('.modal.show input[type=file]',L.SCR+'/qa-ui-attach.txt');await p.waitForTimeout(500);await st('attach_chosen');
await msub();await st('attach_done');
await b.close()})();
