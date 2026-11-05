const m=require('./m2.js');
const txt=(p)=>p.evaluate(()=>[...document.querySelectorAll('.toast,.alert')].map(e=>e.innerText.replace(/\s+/g,' ').slice(0,140)));
(async()=>{const {b,p}=await m.ctxPage();const U='agent/invoice.php?invoice_id=6';await m.go(p,U);
await p.click('a[data-bs-target="#invoiceNoteModal"]');await p.waitForTimeout(700);
console.log(JSON.stringify(await m.audit(p)));await m.shotModal(p,'inv_note_modal');
await p.fill('.modal.show textarea[name=note]','QA-UI note line 1\nline 2 <b>x</b>');
console.log('save note',await m.submit(p),await txt(p));
console.log('note shown',await p.evaluate(()=>[...document.querySelectorAll('.card-body')].map(e=>e.innerText).filter(t=>/QA-UI note/.test(t)).map(t=>t.replace(/\n/g,'|').slice(0,80))));
// print media
await p.emulateMedia({media:'print'});await p.screenshot({path:m.IMG+'/inv_print.png',fullPage:true});
await p.emulateMedia({media:'screen'});
console.log(p.errs);await b.close();})();
