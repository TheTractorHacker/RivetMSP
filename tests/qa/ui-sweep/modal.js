// helper lib: open modal by url on a page, audit, fill, submit
process.env.QA_BASE='http://127.0.0.1:8218';
const h=require('./h.js');const IMG='/home/sysadmin/sweep-docs-img';
async function ctxPage(vp,dark){const b=await h.launch();const ctx=await b.newContext({viewport:vp||{width:1920,height:1080},storageState:IMG+'/state.json'});const p=await ctx.newPage();
 p.errs=[];p.on('console',m=>{if(m.type()==='error')p.errs.push('C:'+m.text().slice(0,140))});p.on('pageerror',e=>p.errs.push('JS:'+String(e).slice(0,140)));
 p.on('response',r=>{if(r.status()>=400)p.errs.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,100))});
 p.dark=dark;return {b,ctx,p};}
async function go(p,u){await p.goto(h.BASE+'/'+u,{waitUntil:'networkidle',timeout:30000}).catch(()=>{});if(p.dark)await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode')});}
async function openModal(p,url,size){
 await p.evaluate(([u,s])=>window.openAjaxModal(u,s||'md'),[url,size]);
 await p.waitForSelector('.modal.show .modal-dialog',{timeout:8000}).catch(()=>{});await p.waitForTimeout(600);}
const MAUDIT=`()=>{const m=document.querySelector('.modal.show');if(!m)return {none:true};const d=m.querySelector('.modal-dialog'),r=d.getBoundingClientRect(),c=m.querySelector('.modal-content').getBoundingClientRect();
 const o={w:Math.round(r.width),h:Math.round(c.height),vh:innerHeight,hs:m.scrollWidth>m.clientWidth+1};
 o.tall=c.height>innerHeight-10; o.title=(m.querySelector('.modal-title')||{}).textContent;
 const nolab=[],noname=[],req=[],small=[];
 m.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea').forEach(e=>{if(!e.offsetParent)return;const id=e.id;const has=e.getAttribute('aria-label')||e.getAttribute('title')||(id&&m.querySelector('label[for="'+id+'"]'))||e.closest('label')||e.closest('.form-group,.mb-3,.col,[class*=col-]')&&(e.closest('.form-group,.mb-3,.col,[class*=col-]').querySelector('label'));if(!has)nolab.push(e.name||e.id||e.tagName);});
 m.querySelectorAll('button,a.btn').forEach(e=>{if(!e.offsetParent)return;if(!e.textContent.trim()&&!e.getAttribute('aria-label')&&!e.getAttribute('title'))noname.push(e.className.slice(0,40))});
 m.querySelectorAll('[required]').forEach(e=>{if(e.offsetParent)req.push(e.name)});
 const t=m.innerText;const raw=/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception|Parse error)/.exec(t);
 o.nolab=nolab.slice(0,6);o.noname=noname.slice(0,4);o.nreq=req.length;o.raw=raw&&raw[0];
 const f=m.querySelector('form');o.form=!!f;o.btns=[...m.querySelectorAll('.modal-footer button,.modal-footer a')].map(b=>b.textContent.trim()).join('|');return o}`;
async function audit(p){return p.evaluate('('+MAUDIT+')()')}
async function shotModal(p,name){const el=await p.$('.modal.show .modal-content');if(el)await el.screenshot({path:IMG+'/'+name+'.png'}).catch(()=>{});}
async function closeModal(p){await p.keyboard.press('Escape');await p.waitForTimeout(250);await p.evaluate(()=>{document.querySelectorAll('.modal.show').forEach(m=>{try{bootstrap.Modal.getInstance(m).hide()}catch(e){}});});await p.waitForTimeout(300);
 await p.evaluate(()=>{document.querySelectorAll('.modal-backdrop').forEach(e=>e.remove());document.body.classList.remove('modal-open');document.body.style.overflow=''}); }
// fill visible fields in the open modal with plausible values
async function fillModal(p,opts){opts=opts||{};const val=opts.val||{};
 await p.evaluate(([val,pref])=>{const m=document.querySelector('.modal.show');const fire=(e)=>{e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}))};
  m.querySelectorAll('input,select,textarea').forEach(e=>{const n=e.name;if(!n||e.disabled||e.type==='hidden'||e.type==='file'||e.type==='submit')return;
   if(val[n]!==undefined){if(e.tagName==='SELECT'){e.value=val[n];if($(e).data('select2'))$(e).trigger('change');}else e.value=val[n];fire(e);return}
   if(e.type==='checkbox'||e.type==='radio')return;
   if(e.tagName==='SELECT'){if(!e.value){const o=[...e.options].find(o=>o.value&&!o.disabled);if(o){e.value=o.value;fire(e)}}return}
   if(e.value)return;
   if(e.type==='number')e.value='5';else if(e.type==='date')e.value='2026-11-10';else if(e.type==='email')e.value='qa-ui@qa.example';else if(e.type==='url')e.value='https://qa-ui.example.com';else if(e.type==='tel')e.value='555-0100';else if(e.type==='password')e.value='QaUi#Pass2026';else if(e.type==='time')e.value='10:00';else if(e.type==='color')return;else e.value=pref+' '+n.slice(0,20);fire(e)});},[val,opts.pref||'QA-UI']);}
async function submit(p){const b=await p.$('.modal.show .modal-footer button[type=submit], .modal.show button[type=submit]');if(!b)return 'nosubmit';
 await Promise.all([p.waitForLoadState('networkidle',{timeout:15000}).catch(()=>{}),b.click()]);await p.waitForTimeout(800);return p.url().replace(h.BASE,'');}
module.exports={h,ctxPage,go,openModal,audit,shotModal,closeModal,fillModal,submit,IMG};
