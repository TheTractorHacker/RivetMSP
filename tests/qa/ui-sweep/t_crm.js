const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,'admin',{width:1920,height:1080});const p=await ctx.newPage();const log=[];L.watch(p,log);p.on('dialog',d=>d.accept());
const note=async n=>{await p.waitForTimeout(700);await p.screenshot({path:L.SCR+'/img/tc_'+n+'.png'});console.log(n,p.url().replace(L.BASE,''),'|',(await p.evaluate(()=>(document.querySelector('.alert, .toast, .it-toast')||{}).innerText||'')).slice(0,100).replace(/\n/g,' '),'|',[...new Set(log.splice(0))].join(';'))};
const modal=async(page,sel)=>{await p.goto(L.BASE+page,{waitUntil:'networkidle'});await p.click(sel);await p.waitForSelector('.modal.show form');await p.waitForTimeout(700)};
const sub=async(txt)=>{await p.click('.modal.show button[type=submit]');await p.waitForLoadState('networkidle');await p.waitForTimeout(800)};
// client add: empty
await modal('/agent/clients.php','a.ajax-modal:has-text("New Client"), button.ajax-modal:has-text("New Client")');
await sub();await note('client_empty');
console.log('invalid:',await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name)));
await p.fill('.modal.show input[name=name]','QA-UI Client A');
await sub();await note('client_nocontact');
await p.fill('.modal.show input[name=contact]','QA-UI Primary');await sub();await note('client_created');
// contact
await modal('/agent/contacts.php','.ajax-modal:has-text("New Contact")');
await sub();await note('contact_empty');
await L.setSel(p,'.modal.show','client_id','QA-UI Client A');await p.fill('.modal.show input[name=name]','QA-UI Contact A');await p.fill('.modal.show input[name=email]','not-an-email');await sub();await note('contact_bademail');
await p.fill('.modal.show input[name=email]','qaui.a@qa.example');await sub();await note('contact_created');
// location
await modal('/agent/locations.php','.ajax-modal:has-text("New Location")');
await sub();await note('loc_empty');
await L.setSel(p,'.modal.show','client_id','QA-UI Client A');await p.fill('.modal.show input[name=name]','QA-UI Location A');await sub();await note('loc_created');
await b.close()})();
