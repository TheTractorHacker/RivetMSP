const { H, ctx } = require('./common');
(async () => { const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
 for (const u of process.argv.slice(2)) { await p.goto(H.BASE + '/' + u, { waitUntil: 'networkidle' });
  console.log(u, await p.evaluate(() => { const f = document.querySelector('footer'); const r = f && f.getBoundingClientRect(); const card = document.querySelector('.card:last-of-type, .card'); return JSON.stringify({ foot: r && [r.x, r.right, r.top], cls: f && f.className, par: f && f.parentElement.className, sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bsw: document.body.scrollWidth, mt: f && getComputedStyle(f).marginTop }); })); }
 await b.close(); })();
