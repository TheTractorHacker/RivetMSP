const L = require('./lib');
// usage: node crawl.js state.json tag w h url1 url2 ...
(async () => {
  const [,, st, tag, w, h, ...urls] = process.argv;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: +w, height: +h }, storageState: L.IMG + '/' + st });
  const page = await ctx.newPage(); const errs = [];
  page.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 120)); });
  page.on('pageerror', e => errs.push('PAGEERR ' + String(e).slice(0, 120)));
  page.on('response', r => { if (r.status() >= 400) errs.push(r.status() + ' ' + r.url().replace(L.BASE, '')); });
  for (const u of urls) {
    errs.length = 0;
    await page.goto(L.BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
    const name = u.replace(/[^a-z0-9]+/gi, '_').slice(0, 60);
    await page.screenshot({ path: `${L.IMG}/${tag}_${name}.png`, fullPage: true });
    const a = await L.audit(page, tag.startsWith('dx'));
    console.log(u, page.url().replace(L.BASE, ''), JSON.stringify(a), errs.length ? 'ERRS ' + JSON.stringify(errs) : '');
  }
  await b.close();
})();
