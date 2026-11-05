const L=require('./lib.js');
const T='/agent/ticket.php?client_id=6&ticket_id=48';
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
p.on('dialog',d=>d.accept());
const shot=async n=>{await p.waitForTimeout(800);await p.screenshot({path:L.SCR+'/img/tb_'+n+'.png'});const a=await L.audit(p,false);console.log(n,p.url().replace(L.BASE,''),JSON.stringify({h:a.hscroll,raw:a.rawerr}),[...new Set(log.splice(0))].join(' ; '))};
const open=async u=>{await p.evaluate(u=>window.openAjaxModal(u,'md',{}),u);await p.waitForSelector('.modal.show form, .modal.show .modal-content',{timeout:8000});await p.waitForTimeout(600)};
const submit=async()=>{await p.click('.modal.show button[type=submit]');await p.waitForURL(/ticket\.php/,{timeout:12000}).catch(()=>{});await p.waitForLoadState('networkidle');};
await p.goto(L.BASE+T,{waitUntil:'networkidle'});
// 1 public note tab + empty submit
await p.click('text=Public Note');await p.waitForTimeout(300);
await p.click('button:has-text("Submit")');await p.waitForTimeout(800);await shot('01_public_empty');
// 2 priority modal
await open('modals/ticket/ticket_priority.php?id=48');await L.setSel(p,'.modal.show','priority','High');await submit();await shot('02_priority');
// 3 status modal
await open('modals/ticket/ticket_status.php?id=48');
console.log(await p.evaluate(()=>[...document.querySelectorAll('.modal.show select[name=status] option')].map(o=>o.textContent).join('|')));
await L.setSel(p,'.modal.show','status','Open');await submit();await shot('03_status');
// 4 tags
await open('modals/ticket/ticket_tags.php?id=48');await shot('04a_tagsmodal');
await p.keyboard.press('Escape');await p.waitForTimeout(500);
// 5 watcher
await open('modals/ticket/ticket_add_watcher.php?ticket_id=48');await shot('05a_watchermodal');
await p.click('.modal.show button[type=submit]').catch(()=>{});await shot('05b_watcher_empty_submit');
await b.close()})();
