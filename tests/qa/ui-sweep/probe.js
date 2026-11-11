const L = require('./lib');
(async () => {
  const [,, st, url, w, h, js] = process.argv;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: +w||1920, height: +h||1080 }, storageState: L.IMG + '/' + st });
  const p = await ctx.newPage(); await p.goto(L.BASE + url, { waitUntil: 'networkidle' });
  console.log(JSON.stringify(await p.evaluate(js), null, 1)); await b.close();
})();
