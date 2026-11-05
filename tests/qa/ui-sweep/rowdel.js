// usage: node rowdel.js <listpath> <rowtext>   deletes row with that text via its Delete action (confirm modal)
const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,process.argv[2]);
const row=await p.evaluateHandle(t=>[...document.querySelectorAll('tbody tr')].find(r=>r.innerText.includes(t)),process.argv[3]);
const el=row.asElement();if(!el){console.log('row not found');await b.close();return}
await (await el.$('button.dropdown-toggle, a.dropdown-toggle, .btn:has(i.fa-ellipsis-h), .btn:has(i.fa-ellipsis-v)')).click();await p.waitForTimeout(400);
const act=process.env.ACT||'Delete';const del=await p.$('.dropdown-menu.show a:has-text("'+act+'")')||await el.$('a:has-text("'+act+'")');await del.click();await p.waitForTimeout(900);
const ok=await p.$('.modal.show button:has-text("Yes"), .modal.show a:has-text("Yes")');if(!ok){console.log('no confirm');await p.screenshot({path:m.IMG+'/rowdel_dbg.png'});}else{await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),ok.click()]);await p.waitForTimeout(1000);}
console.log(process.argv[2],'delete ->',p.url().replace(m.h.BASE,''),await txt(p),p.errs);await b.close();})();
