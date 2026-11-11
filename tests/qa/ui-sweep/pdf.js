const m=require('./m2.js');const fs=require('fs');
(async()=>{const {b,ctx,p}=await m.ctxPage();
await m.go(p,'agent/quote.php?quote_id=2');
const tok=await p.evaluate(()=>document.querySelector('input[name=csrf_token]').value);
for(const [n,u] of [['quote','post.php?export_quote_pdf=2'],['invoice','post.php?export_invoice_pdf=6'],['packing','post.php?export_invoice_packing_slip=6']]){
 const r=await ctx.request.get(m.h.BASE+'/agent/'+u+'&csrf_token='+tok,{headers:{referer:m.h.BASE+'/agent/quote.php?quote_id=2'}});
 const body=await r.body();console.log(n,r.status(),r.headers()['content-type'],body.length,body.slice(0,5).toString());
 fs.writeFileSync(m.IMG+'/'+n+'.pdf',body);}
await b.close();})();
