// archive -> archived view -> delete every QA-UI record on a page. usage: node crud2.js <page> [archivedSuffix]
const m=require('./modal.js');
async function act(p,sel){const href=await p.evaluate((sel)=>{for(const e of document.querySelectorAll(sel)){let c=e.closest('tr, .card, .kb-card, li');if(c&&/QA-UI/.test(c.innerText)){e.setAttribute('data-qa-act','1');return e.getAttribute('href')}}return null},sel);
 if(!href)return null;await p.evaluate(()=>document.querySelector('[data-qa-act]').click());await p.waitForTimeout(400);
 await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}),p.click('#confirmSubmitBtn')]);await p.waitForTimeout(600);return href}
(async()=>{const pg=process.argv[2],suf=process.argv[3]||'&archived=1';const {b,p}=await m.ctxPage();
 let n=0;for(;n<12;n++){await m.go(p,pg);const h=await act(p,'a.confirm-link[href*="archive_"]');if(!h)break;console.log(' archived via',h.split('&csrf')[0]);}
 for(let i=0;i<12;i++){await m.go(p,pg+suf);const h=await act(p,'a.confirm-link[href*="delete_"]');if(!h)break;const raw=await p.evaluate(()=>/(Fatal error|Warning:|Notice:|Uncaught)/.exec(document.body.innerText));console.log(' deleted via',h.split('&csrf')[0],raw?'RAW '+raw[0]:'');}
 await m.go(p,pg+suf);const left=await p.evaluate(()=>/QA-UI/.test(document.body.innerText));console.log(pg,'QA-UI left in archived view:',left, p.errs.join(';').slice(0,150));
 await b.close();})();
