// visit list of pages, screenshot + audit. usage: node pages.js user theme(light|dark) vpw vph tag url...
const L = require('./lib.js');
(async () => {
  const [,, user, w, h, tag, ...urls] = process.argv; const b = await L.launch();
  const ctx = await L.ctxFor(b, user, { width: +w, height: +h }); const p = await ctx.newPage();
  for (const u of urls) {
    const log = []; const pl = () => {}; L.watch(p, log);
    try { await p.goto(L.BASE + u, { waitUntil: 'networkidle', timeout: 40000 }); } catch (e) { log.push('nav ' + String(e).slice(0, 80)); }
    if (tag.startsWith('D')) await p.evaluate(()=>{document.documentElement.setAttribute('data-bs-theme','dark');document.body.classList.add('dark-mode');});
    await p.waitForTimeout(400);
    const name = u.replace(/[^a-z0-9]+/gi, '_').slice(0, 60);
    await p.screenshot({ path: `${L.SCR}/img/${tag}_${name}.png`, fullPage: false });
    const a = await L.audit(p, tag.includes('axe'));
    console.log(u, '->', p.url().replace(L.BASE, ''), JSON.stringify(a), log.length ? JSON.stringify([...new Set(log)].slice(0, 6)) : '');
    p.removeAllListeners('console'); p.removeAllListeners('pageerror'); p.removeAllListeners('response');
  }
  await b.close();
})();
