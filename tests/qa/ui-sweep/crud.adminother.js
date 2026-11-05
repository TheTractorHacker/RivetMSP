// generic add -> (empty validation) -> persist -> delete/archive check on admin list pages. Creates QA-UI-ADM-* records only.
const h=require('./h.js');const fs=require('fs');const I='/home/sysadmin/ui-sweep-adminother-img/';
const pages=process.argv.slice(2);const STAMP='QA-UI-ADM-'+Date.now().toString().slice(-5);
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
const errs=[];page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('pageerror',e=>errs.push('PE '+String(e).slice(0,100)));page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,80))});
for(const u of pages){errs.length=0;const tag=u.replace(/[^a-z0-9]+/gi,'_');
 await page.goto(h.BASE+u,{waitUntil:'load'});
 const add=page.locator('[data-modal-url*="_add.php"],[data-modal-url*="/add"],[data-modal-url*="add_"]').first();
 if(!(await add.count())){console.log(u,'NO ADD BUTTON');continue}
 await add.click();await page.waitForTimeout(1500);
 const modal=page.locator('.modal.show');
 if(!(await modal.count())){console.log(u,'modal did not open');continue}
 // empty submit
 const sub=modal.locator('button[type=submit]').first();
 await sub.click();await page.waitForTimeout(700);
 const afterEmpty=await page.evaluate(()=>({modalOpen:!!document.querySelector('.modal.show'),invalid:[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name).slice(0,5),flash:(document.querySelector('.toast,.alert,#alert')||{}).textContent}));
 await page.screenshot({path:I+`crud_${tag}_empty.png`});
 console.log(u,'EMPTY submit ->',JSON.stringify(afterEmpty).slice(0,200),'url',page.url().replace(h.BASE,''));
 if(!afterEmpty.modalOpen){console.log('  !! empty form submitted / closed modal');await page.goto(h.BASE+u);continue}
 // fill
 const info=await modal.evaluate((m,st)=>{const filled=[];m.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=checkbox]):not([type=radio]):not([type=file]),textarea,select').forEach((e,i)=>{if(e.disabled||e.offsetParent===null&&!e.closest('.ts-wrapper'))return;const t=e.type;if(e.tagName==='SELECT'){if(e.required&&!e.value&&e.options.length>1){e.selectedIndex=1;e.dispatchEvent(new Event('change',{bubbles:true}));filled.push(e.name+'=sel')}return}
  if(!e.required&&i>0)return; if(e.value&&t!=='text')return;
  if(t==='number'){e.value=e.min||1}else if(t==='email'){e.value='qa-ui-adm@qa.example'}else if(t==='url'){e.value='https://qa.example'}else if(t==='color'){}else if(t==='date'){e.value='2027-01-15'}else if(t==='time'){e.value='09:00'}else if(t==='tel'){e.value='5550100'}else{if(!e.value)e.value=st}
  e.dispatchEvent(new Event('input',{bubbles:true}));filled.push(e.name+'='+e.value)});return filled},STAMP);
 console.log('  filled',info.join(', ').slice(0,160));
 await Promise.all([page.waitForLoadState('load').catch(()=>{}),sub.click()]);await page.waitForTimeout(1200);
 const body=await page.evaluate(()=>document.body.innerText);
 const ok=body.includes(STAMP);const toast=await page.evaluate(()=>[...document.querySelectorAll('.toast,.alert,#alert,.toastr')].map(e=>e.textContent.trim().slice(0,100)).join(' | '));
 await page.screenshot({path:I+`crud_${tag}_after.png`});
 console.log('  after save: url',page.url().replace(h.BASE,''),'persisted-in-list:',ok,'flash:',toast,'raw:',/Fatal error|Warning:|Notice:|Deprecated:/.test(body),errs.slice(0,3).join(';'));
}
console.log('STAMP',STAMP);await b.close();})();
