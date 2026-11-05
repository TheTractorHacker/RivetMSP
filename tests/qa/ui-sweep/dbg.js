const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/invoices.php?client_id=11');
 await m.openModal(p,'modals/invoice/invoice_add.php?client_id=11','md');
 await m.fillModal(p,{val:{scope:'QA-UI Monthly managed services',invoice_discount:'10'}});
 console.log(await p.evaluate(()=>[...new FormData(document.querySelector('.modal.show form'))].map(x=>x.join('=')).join('&').replace(/csrf[^&]*/,'')));
 p.on('response',r=>{if(r.url().includes('post.php'))console.log('POST',r.status(),r.headers().location)});
 const r=await m.submit(p);console.log(r);
 await p.screenshot({path:m.IMG+'/dbg.png',clip:{x:256,y:56,width:1664,height:300}});await b.close();})();
