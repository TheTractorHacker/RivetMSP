const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);p.on('dialog',d=>d.accept());
const note=async n=>{await p.waitForTimeout(700);await p.screenshot({path:L.SCR+'/img/td_'+n+'.png'});console.log(n,p.url().replace(L.BASE,''),'|',(await p.evaluate(()=>(document.querySelector('.alert, .toast, .it-toast')||{}).innerText||'')).slice(0,100).replace(/\n/g,' '),'|',[...new Set(log.splice(0))].join(';'))};
const modal=async(page,sel)=>{await p.goto(L.BASE+page,{waitUntil:'networkidle'});await p.click(sel);await p.waitForSelector('.modal.show form');await p.waitForTimeout(700)};
const sub=async()=>{await p.click('.modal.show button[type=submit]');await p.waitForLoadState('networkidle');await p.waitForTimeout(800)};
// segment: empty then valid
await p.goto(L.BASE+'/agent/segments.php',{waitUntil:'networkidle'});
await p.click('#segmentForm button:has-text("Save Segment")');await p.waitForTimeout(500);await note('seg_empty');
await p.fill('#segmentForm input[name=name]','QA-UI Segment');await p.click('#segmentForm button:has-text("Save Segment")');await p.waitForLoadState('networkidle');await note('seg_saved');
// campaign
await p.goto(L.BASE+'/agent/campaigns.php',{waitUntil:'networkidle'});
await p.click('button:has-text("Save Draft")');await p.waitForTimeout(500);await note('camp_empty');
await p.fill('input[name=name]','QA-UI Campaign');await L.setSel(p,'body','segment_id','QA-UI Segment');await p.fill('input[name=subject]','QA-UI subject');await p.fill('textarea[name=body]','<p>hello</p>');
await p.click('button:has-text("Save Draft")');await p.waitForLoadState('networkidle');await note('camp_saved');
// problem
await modal('/agent/problems.php','.ajax-modal:has-text("New Problem")');await sub();await note('prob_empty');
await p.fill('.modal.show input[name=title]','QA-UI Problem');await sub();await note('prob_saved');
// change
await modal('/agent/changes.php','.ajax-modal:has-text("New Change")');await sub();await note('chg_empty');
await p.fill('.modal.show input[name=title]','QA-UI Change');await sub();await note('chg_saved');
// recurring ticket
await modal('/agent/recurring_tickets.php','.ajax-modal:has-text("New Recurring")');await sub();await note('rt_empty');
await p.fill('.modal.show input[name=subject]','QA-UI Recurring');await L.setSel(p,'.modal.show','client_id','QA-UI Client A');await p.waitForTimeout(800);await sub();await note('rt_saved');
// opportunity
await modal('/agent/opportunities.php','.ajax-modal:has-text("New Opportunity")');await sub();await note('opp_empty');
await p.fill('.modal.show input[name=name]','QA-UI Opportunity');await p.fill('.modal.show input[name=amount]','1234.50');await sub();await note('opp_saved');
// lead
await modal('/agent/leads_board.php','.ajax-modal:has-text("Lead")');await p.fill('.modal.show input[name=name]','QA-UI Lead A');await sub();await note('lead_saved');
await b.close()})();
