// usage: node shot.js <theme-tag> <WxH> page1 page2 ...   (screenshots to shots/<tag>_<WxH>_<name>.png, prints audit)
const h = require('./h.js'); const fs=require('fs');
(async () => { const [tag, wh, ...pages] = process.argv.slice(2); const [W,H]=wh.split('x').map(Number);
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: W, height: H }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 for (const f of pages) { const p = await c.newPage(); const errs=[]; const bad=[];
  p.on('console', m=>{ if(m.type()==='error') errs.push(m.text().slice(0,140)); }); p.on('pageerror', e=>errs.push('EXC '+String(e).slice(0,140)));
  p.on('response', r=>{ if(r.status()>=400) bad.push(r.status()+' '+r.url().replace(h.BASE,'').slice(0,90)); });
  const r = await p.goto(h.BASE + '/' + f).catch(e=>null); await p.waitForLoadState('networkidle').catch(()=>{}); await p.waitForTimeout(600);
  const name = f.replace(/[^a-z0-9]+/gi,'_');
  const buf = await p.screenshot({fullPage:true}); const full = buf.readUInt32BE(20); const sl = Math.ceil(Math.min(full,H*3)/ (H*0.95));
  for (let i=0;i<sl;i++){ const y=Math.floor(i*H*0.95); await p.screenshot({path:`/home/sysadmin/ui-sweep-rmm/shots/${tag}_${wh}_${name}_${i}.png`, fullPage:true, clip:{x:0,y,width:W,height:Math.min(H,full-y)}}); }
  const a = await h.audit(p, process.env.AXE==='1');
  console.log(JSON.stringify({f, slices:sl, full, st:r&&r.status(), hs:a.hscroll, small:a.small, clip:a.clipped, noname:a.noname, raw:a.rawerr, axe:a.axe, errs, bad}));
  await p.close(); }
 await b.close(); })();
