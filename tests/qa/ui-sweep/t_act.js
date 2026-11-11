const L=require('./lib.js');
const T='/agent/ticket.php?client_id=6&ticket_id=48';
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);
const shot=async n=>{await p.waitForTimeout(700);await p.screenshot({path:L.SCR+'/img/ta_'+n+'.png'});const a=await L.audit(p,false);console.log(n,p.url().replace(L.BASE,''),JSON.stringify({h:a.hscroll,raw:a.rawerr,c:a.clipped}),log.splice(0).join(' ; '))};
await p.goto(L.BASE+T,{waitUntil:'networkidle'});
// turn off email notifications toggle (my QA ticket) so no mail gets queued
await shot('00_load');
// internal note
await p.evaluate(()=>{const e=tinymce.get('ticket-reply-editor');e.setContent('<p>QA-UI internal note</p>')});
await p.screenshot({path:L.SCR+'/img/ta_01_prenote.png'});
await Promise.all([p.waitForNavigation().catch(()=>{}),p.click('button:has-text("Submit")')]);
await p.waitForLoadState('networkidle');await shot('01_internal_posted');
await b.close()})();
