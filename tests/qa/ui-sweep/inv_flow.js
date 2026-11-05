const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,120)));
const totals=(p)=>p.evaluate(()=>{const t=[...document.querySelectorAll('table')].find(t=>/Subtotal/.test(t.innerText));return t?t.innerText.replace(/\s+/g,' '):'none'});
const kind=process.argv[2]||'invoice';
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,`agent/${kind}s.php?client_id=11`);
await m.openModal(p,`modals/${kind}/${kind}_add.php?client_id=11`);
await m.fillModal(p,{val:{scope:'QA-UI math test '+kind,invoice_discount:'10.00',category:undefined}});
// pick first category
await p.evaluate(()=>{const s=document.querySelector('.modal.show select[name=category]');const o=[...s.options].find(o=>o.value);s.value=o.value;$(s).trigger('change')});
console.log('create',await m.submit(p),await txt(p));
const url=p.url();console.log(url);
if(kind==='quote'){const id=url.match(/quote_id=(\d+)/)[1];await m.openModal(p,'modals/quote/quote_edit.php?id='+id);await p.evaluate(()=>{document.querySelector('.modal.show [name=quote_discount]').value='10.00'});console.log('qdisc',await m.submit(p),await txt(p));}
console.log('discount-only totals:',await totals(p));
await p.waitForLoadState('networkidle').catch(()=>{});console.log(await p.evaluate(()=>document.querySelectorAll('input[name=name]').length+' '+location.href));
const items=[['QA-UI Widget','2','100.00','1'],['QA-UI Gadget','1','49.99','2'],['QA-UI Plain','3','25.00','0']];
for(const [n,q,pr,tx] of items){
 await p.fill('input#name',n);await p.fill('input#qty',q);await p.fill('input#price',pr);
 await p.evaluate(tx=>{const s=document.querySelector('select[name=tax_id]');s.value=tx;$(s).trigger('change')},tx);
 await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('button[name=add_'+kind+'_item]')]);await p.waitForTimeout(500);
 console.log('added',n,await txt(p));}
console.log('totals:',await totals(p));
await p.screenshot({path:m.IMG+'/'+kind+'_math.png',fullPage:true});
console.log(p.errs);await b.close();})();
