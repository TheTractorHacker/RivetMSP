const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();
const reps=['service_desk','technician_performance','csat','rmm_health','clients_with_balance','client_ticket_time_detail','mrr','ticket_summary','income_summary','profit_loss','tax_summary','budget','expense_summary','expense_by_vendor','income_by_client','tickets_unbilled','time_by_tech','ticket_by_client','ticket_charges','included_issues','credential_rotation'];
const qs=['canned_date=last7days','canned_date=lastmonth','canned_date=custom&from=2026-01-01&to=2026-03-31','canned_date=custom&from=2026-12-31&to=2026-01-01','canned_date=custom&from=garbage&to=nonsense','year=1999','year=abc&month=13','days=-5','days=abc'];
for(const r of reps){let bad=[];for(const q of qs){p.errs=[];const res=await p.goto(m.h.BASE+'/agent/reports/'+r+'.php?'+q,{waitUntil:'domcontentloaded'}).catch(()=>null);
 const st=res?res.status():'ERR';const raw=await p.evaluate(()=>{const mm=/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception)/.exec(document.body.innerText);return mm&&mm[0]});
 if(st!==200||raw||p.errs.length)bad.push(q+' -> '+st+' '+(raw||'')+' '+p.errs.join(';').slice(0,80));}
 console.log(r,bad.length?bad.join(' || '):'ok (9 variants)');}
await b.close()})();
