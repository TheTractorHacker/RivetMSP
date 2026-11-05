// sort / search / filter / pagination clicks on a list page. usage: node lists.js <path>
const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,process.argv[2]);
const rows=()=>p.evaluate(()=>document.querySelectorAll('tbody tr').length);
console.log('rows',await rows());
const ths=await p.evaluate(()=>[...document.querySelectorAll('thead th a')].map(a=>a.innerText.trim()+' => '+a.getAttribute('href')));console.log('sortable',ths.length,ths.slice(0,3));
for(let i=0;i<Math.min(ths.length,3);i++){const hrefs=ths[i].split(' => ')[1];await p.goto(m.h.BASE+'/agent/'+process.argv[2].split('/')[1].split('?')[0]+hrefs,{waitUntil:'networkidle'}).catch(()=>{});console.log(' sort',ths[i].split(' => ')[0],p.url().replace(m.h.BASE,''),'rows',await rows(),await p.evaluate(()=>/Fatal|Warning:|Notice:/.test(document.body.innerText)));}
// search
await m.go(p,process.argv[2]);const q=await p.$('input[name=q]');
if(q){await q.fill('zzzz-no-match');await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}).catch(()=>{}),q.press('Enter')]);console.log('search nomatch rows',await rows(),await p.evaluate(()=>(document.querySelector('.card-body')||{}).innerText.replace(/\s+/g,' ').slice(0,100)));await p.screenshot({path:m.IMG+'/list_empty_'+process.argv[2].replace(/\W+/g,'_')+'.png'});}
else console.log('no search box');
// filter panel
await m.go(p,process.argv[2]);
const f=await p.$('[data-bs-toggle=collapse][data-bs-target*=filter], button[data-bs-target*=Filter], a[data-bs-target*=ilter]');
if(f){await f.click();await p.waitForTimeout(600);console.log('filter panel visible',await p.evaluate(()=>[...document.querySelectorAll('.collapse.show')].length));await p.screenshot({path:m.IMG+'/list_filter_'+process.argv[2].replace(/\W+/g,'_')+'.png'});}
else console.log('no filter toggle');
console.log(p.errs);await b.close();})();
