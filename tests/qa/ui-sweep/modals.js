// open every distinct ajax-modal on each page; log status, overflow, console errors; screenshot
const h=require('./h.js');const fs=require('fs');const I='/home/sysadmin/ui-sweep-adminother-img/';
const tag=process.argv[3]||'mod';const [W,H]=(process.argv[4]||'1920x1080').split('x').map(Number);
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:W,height:H},storageState:I+'state.json'});const page=await ctx.newPage();
const errs=[];page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('pageerror',e=>errs.push('PE '+String(e).slice(0,100)));page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,80))});
page.on('dialog',d=>d.dismiss());
let n=0;
for(const u of fs.readFileSync(process.argv[2],'utf8').split('\n').filter(Boolean)){
 await page.goto(h.BASE+u,{waitUntil:'load'});await page.waitForTimeout(500);
 const urls=await page.evaluate(()=>[...new Set([...document.querySelectorAll('[data-modal-url]')].map(e=>e.getAttribute('data-modal-url')))]);
 for(const mu of urls){ errs.length=0; n++;
  const el=page.locator(`[data-modal-url="${mu}"]`).first();
  try{ if(!(await el.isVisible())){ // try open a dropdown parent
        const dd=el.locator('xpath=ancestor::*[contains(@class,"dropdown")][1]//*[@data-bs-toggle="dropdown"]').first(); if(await dd.count()){await dd.click({timeout:2000});} }
      await el.click({timeout:3000});}catch(e){console.log('NOCLICK',u,mu,String(e).slice(0,50));continue}
  await page.waitForTimeout(1400);
  const st=await page.evaluate(()=>{const m=document.querySelector('.modal.show');if(!m)return null;const d=m.querySelector('.modal-dialog');const r=d.getBoundingClientRect();const c=m.querySelector('.modal-content').getBoundingClientRect();
   return {title:(m.querySelector('.modal-title')||{}).textContent,h:Math.round(c.height),vh:innerHeight,top:Math.round(c.top),bottom:Math.round(c.bottom),hs:m.scrollWidth>m.clientWidth+1,raw:/Fatal error|Warning:|Notice:|Deprecated:|Uncaught/.test(m.innerText),labelless:[...m.querySelectorAll('input:not([type=hidden]):not([type=submit]),select,textarea')].filter(i=>!i.getAttribute('aria-label')&&!i.id&&!i.closest('label')&&!i.closest('.form-group,.mb-3,.input-group')).length,btnnoname:[...m.querySelectorAll('button,a.btn')].filter(b=>!b.textContent.trim()&&!b.getAttribute('aria-label')&&!b.title).length,focusIn:!!document.activeElement.closest('.modal'),foc:document.activeElement.tagName}});
  const name=(tag+'_'+u+'_'+mu).replace(/[^a-z0-9]+/gi,'_').slice(0,110);
  if(st)await page.screenshot({path:I+name+'.png'});
  console.log(u,'|',mu.replace(/\?.*/,''),'|',JSON.stringify(st),errs.slice(0,3).join(' ; '));
  await page.keyboard.press('Escape');await page.waitForTimeout(500);
  const still=await page.evaluate(()=>!!document.querySelector('.modal.show'));if(still){console.log('  !! Esc did not close');await page.evaluate(()=>{document.querySelectorAll('.modal.show .btn-close,.modal.show [data-dismiss],.modal.show [data-bs-dismiss]').forEach(b=>b.click())});await page.waitForTimeout(500);}
  if(await page.evaluate(()=>!!document.querySelector('.modal-backdrop'))){console.log('  !! stale backdrop');await page.reload({waitUntil:'load'});}
 }}
console.log('total',n);await b.close();})();
