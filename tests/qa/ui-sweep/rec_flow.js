const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'agent/recurring_invoices.php?client_id=11');await p.screenshot({path:m.IMG+'/ri_list.png'});
await m.openModal(p,'modals/recurring_invoice/recurring_invoice_add.php?client_id=11');await m.shotModal(p,'ri_add');console.log(JSON.stringify(await m.audit(p)));
let r=await m.submit(p);console.log('empty',r,!!(await p.$('.modal.show')));
await m.fillModal(p,{val:{scope:'QA-UI recurring',start_date:'2026-12-01',frequency:'month'}});
await p.evaluate(()=>{const s=document.querySelector('.modal.show select[name=category]');const o=[...s.options].find(o=>o.value);s.value=o.value;$(s).trigger('change')});
console.log('create',await m.submit(p),await txt(p));console.log(p.url());
const items=[['QA-UI Widget','2','100.00','1'],['QA-UI Gadget','1','49.99','2']];
for(const [n,q,pr,tx] of items){
 await p.fill('input#name',n);await p.fill('input#qty',q);await p.fill('input#price',pr);
 await p.evaluate(tx=>{const s=document.querySelector('select[name=tax_id]');s.value=tx;$(s).trigger('change')},tx);
 await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('button[name=add_recurring_invoice_item]')]);await p.waitForTimeout(500);console.log('added',n,await txt(p));}
console.log('totals',await totals(p));
await p.screenshot({path:m.IMG+'/ri_page.png',fullPage:true});
console.log(await p.evaluate(()=>[...document.querySelectorAll('.card-header a,.card-header button,.dropdown-menu a,.dropdown-menu button')].filter(e=>e.closest('.page-body')).map(e=>(e.innerText.trim()||e.getAttribute('aria-label'))+' => '+(e.dataset.modalUrl||e.getAttribute('href')||'')).join('\n')));
console.log(p.errs);await b.close();})();
