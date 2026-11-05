const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  await p.goto(H.BASE + '/admin/settings.php'); await p.waitForLoadState('networkidle');
  await p.screenshot({ path: IMG + '/dir-light.png', fullPage: true });
  const links = await p.evaluate(() => [...document.querySelectorAll('a[href]')].map(a => [a.closest('nav,aside,.sidebar,.navbar-vertical,main,.page-body') ? (a.closest('aside,.navbar-vertical')?'nav':'main') : 'other', a.getAttribute('href'), a.textContent.trim().replace(/\s+/g,' ').slice(0,40)]));
  for (const l of links) console.log(l.join(' | '));
  await b.close();
})();
