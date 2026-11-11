// generic CRUD flow. usage: node crud.js <entity>
const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
const E={
 revenue:{list:'agent/revenues.php',add:'modals/revenue/revenue_add.php',vals:{date:'2026-11-03',amount:'123.45',account:'2',category:'4',payment_method:'Check',description:'QA-UI revenue',reference:'QA-UI-REF'},match:'QA-UI-REF',edit:{amount:'200.00'},editMatch:'$200.00'},
 expense:{list:'agent/expenses.php',add:'modals/expense/expense_add.php',vals:{date:'2026-11-03',amount:'45.67',account:'2',vendor:'6',category:'3',description:'QA-UI expense',reference:'QA-UI-REF',client_id:'0'},match:'QA-UI expense',edit:{amount:'50.00'},editMatch:'$50.00'},
 recexp:{list:'agent/recurring_expenses.php',add:'modals/recurring_expense/recurring_expense_add.php',vals:{frequency:'1',month:'1',day:'15',amount:'19.99',account:'2',vendor:'6',category:'3',description:'QA-UI rec expense',reference:'QA-UI-REF',client_id:'0'},match:'QA-UI rec expense',edit:{amount:'29.99'},editMatch:'$29.99'},
 transfer:{list:'agent/transfers.php',add:'modals/transfer/transfer_add.php',vals:{date:'2026-11-03',amount:'10.00',account_from:'2',account_to:'1',notes:'QA-UI transfer'},match:'$10.00',edit:{amount:'12.00'},editMatch:'$12.00'},
 trip:{list:'agent/trips.php',add:'modals/trip/trip_add.php',vals:{date:'2026-11-03',miles:'12.5',source:'QA-UI src',destination:'QA-UI dest',purpose:'QA-UI trip purpose',user:'2',client_id:'9'},match:'QA-UI trip purpose',edit:{miles:'20'},editMatch:'20'},
};
(async()=>{const e=E[process.argv[2]];const {b,p}=await m.ctxPage();
await m.go(p,e.list);await p.screenshot({path:m.IMG+'/crud_'+process.argv[2]+'_list.png',fullPage:true});
const rows=()=>p.evaluate(()=>[...document.querySelectorAll('tbody tr')].map(r=>r.innerText.replace(/\s+/g,' ').slice(0,110)));
console.log('before rows',(await rows()).length);
console.log('toolbar',await p.evaluate(()=>[...document.querySelectorAll('.card-header .btn,.card-header a')].map(x=>x.innerText.trim()||x.getAttribute('aria-label')).filter(Boolean)));
await m.openModal(p,e.add);await m.shotModal(p,'crud_'+process.argv[2]+'_add');console.log(JSON.stringify(await m.audit(p)));
let r=await m.submit(p);console.log('empty submit ->',r,'modal open:',!!(await p.$('.modal.show')),'invalid:',await p.evaluate(()=>[...document.querySelectorAll('.modal.show :invalid')].map(x=>x.name)));
await m.closeModal(p);
if(!process.env.SKIP){await m.openModal(p,e.add);await m.fillModal(p,{val:e.vals});
if(process.argv[2]==='trip')await p.evaluate(()=>{const s=document.querySelector('.modal.show [name=destination]');if(s&&!s.value){const o=document.createElement('option');o.value='QA-UI dest';o.text='QA-UI dest';s.add(o);s.value='QA-UI dest';}});
console.log('create',await m.submit(p),await txt(p));}await m.go(p,e.list);
const rs=await rows();console.log('rows after',rs.length,rs.filter(x=>x.includes(e.match)));
// row actions
const acts=await p.evaluate((mt)=>{const r=[...document.querySelectorAll('tbody tr')].find(r=>r.innerText.includes(mt));return r?[...r.querySelectorAll('a')].map(a=>a.innerText.trim().replace(/\s+/g,' ')+' => '+(a.dataset.modalUrl||a.getAttribute('href'))):[]},e.match);console.log(acts);
const ed=acts.find(a=>/^Edit/i.test(a)&&/modal/.test(a));
if(ed){const u=ed.split(' => ')[1];await m.openModal(p,u);await m.shotModal(p,'crud_'+process.argv[2]+'_edit');console.log('edit audit',JSON.stringify(await m.audit(p)));await m.fillModal(p,{val:e.edit});
 await p.evaluate(v=>{for(const k in v){const el=document.querySelector('.modal.show [name='+k+']');if(el){el.value=v[k]}}},e.edit);
 console.log('save edit',await m.submit(p),await txt(p));await m.go(p,e.list);console.log('after edit',(await rows()).filter(x=>x.includes(e.editMatch)).slice(0,2));}
console.log(p.errs);await b.close();})();
