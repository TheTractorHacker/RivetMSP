const h = require('./h.js');
(async () => { const [url, tag, wh='1920x1080'] = process.argv.slice(2); const [W,H]=wh.split('x').map(Number);
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: W, height: H }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,120))); p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,120))});
 await p.goto(h.BASE+'/'+url); await p.waitForLoadState('networkidle').catch(()=>{});
 const tabs = await p.locator('[role=tab], .nav-tabs a, .nav-tabs button').evaluateAll(e=>e.map((x,i)=>i+':'+x.innerText.trim().replace(/\s+/g,' ')));
 console.log(tabs.join(' | '));
 const n = tabs.length;
 for (let i=0;i<n;i++) { await p.locator('[role=tab], .nav-tabs a, .nav-tabs button').nth(i).click(); await p.waitForTimeout(500);
  const buf = await p.screenshot({fullPage:true}); const full = buf.readUInt32BE(20); const sl=Math.ceil(full/(H*0.95));
  for (let k=0;k<Math.min(sl,4);k++){ const y=Math.floor(k*H*0.95); await p.screenshot({path:`/home/sysadmin/ui-sweep-rmm/shots/${tag}_t${i}_${k}.png`, fullPage:true, clip:{x:0,y,width:W,height:Math.min(H,full-y)}}); }
  const a = await h.audit(p,false); console.log('tab',i,'full',full,JSON.stringify({hs:a.hscroll,small:a.small,clip:a.clipped,noname:a.noname,raw:a.rawerr})); }
 console.log(errs); await b.close(); })();
