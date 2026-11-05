const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();
// recalc INV-1

for(const id of [3,4,5]){await m.go(p,'agent/invoice.php?invoice_id='+id);
 p.on('dialog',d=>d.accept());
 await p.click('button:has(i.fa-ellipsis-v), a:has(i.fa-ellipsis-v)').catch(async e=>console.log('click1 fail'));
 await p.waitForTimeout(400);
 await p.screenshot({path:m.IMG+'/inv_menu_'+id+'.png'});
 const del=await p.$('a.confirm-link:visible:has-text("Delete")');console.log('del link',!!del);
 if(del){await del.click();await p.waitForTimeout(1200);await p.screenshot({path:m.IMG+'/inv_confirm_'+id+'.png'});
  console.log(await p.evaluate(()=>[...document.querySelectorAll('.swal2-popup,.modal.show')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,200))));
  const ok=await p.$('.modal.show button:has-text("Yes"), .modal.show a:has-text("Yes")');if(ok){await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),ok.click()]);await p.waitForTimeout(1000);}
  console.log('after',p.url().replace(m.h.BASE,''),await txt(p));}}
console.log(p.errs);await b.close();})();
