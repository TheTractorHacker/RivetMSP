const m=require('./modal.js');
(async()=>{const {b,ctx,p}=await m.ctxPage();
for(const r of ['clients_with_balance','expense_summary','csat','service_desk','technician_performance','income_summary','mrr','ticket_summary']){
 for(const q of ['','?canned_date=last30days&year=2026','?canned_date=thisyear']){
 const res=await ctx.request.get(m.h.BASE+'/agent/reports/'+r+'.php'+(q?q+'&':'?')+'export=csv');
 const h=res.headers();const t=await res.text();
 console.log(r+q.slice(0,22).padEnd(24),res.status(),(h['content-type']||'').slice(0,30),(h['content-disposition']||'').slice(0,50),'bytes',t.length,'lines',t.split('\n').length,/Warning|Fatal|<html/i.test(t.slice(0,2000))?'RAW/HTML':'ok','|',t.split('\n')[0].slice(0,70));}}
await b.close()})();
