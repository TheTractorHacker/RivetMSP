const { H, IMG, ctx } = require('./common');
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('response', r => { if (r.status()>=400) errs.push(r.status()+' '+r.url().slice(0,80)); });
  await p.goto(H.BASE + '/admin/', { waitUntil: 'networkidle' }); console.log('admin/ ->', p.url());
  const links = await p.evaluate(() => [...document.querySelectorAll('aside a[href], .navbar-vertical a[href]')].map(a => [a.getAttribute('href'), a.textContent.trim().replace(/\s+/g,' ')]).filter(x => x[0] && !x[0].startsWith('#')));
  console.log(links.length, 'sidebar links');
  for (const [h, t] of links) {
    errs.length = 0;
    const r = await p.goto(new URL(h, H.BASE).href, { waitUntil: 'networkidle' }).catch(e => null);
    const act = await p.evaluate(() => [...document.querySelectorAll('aside .active, .navbar-vertical .active')].map(e => e.textContent.trim().replace(/\s+/g,' ')).join(' / '));
    console.log(t.padEnd(22), h.padEnd(34), r && r.status(), 'active:', act, errs.slice(0,2));
  }
  // billing group toggle
  await p.goto(H.BASE + '/admin/users.php', { waitUntil: 'networkidle' });
  const tog = p.locator('a[href="#nav-group-billing"], [data-bs-toggle=collapse]:has-text("Billing")').first();
  await tog.click(); await p.waitForTimeout(500); const open1 = await p.locator('#nav-group-billing').evaluate(e => e.classList.contains('show')); await p.screenshot({ path: IMG + '/nav-billing-open.png' });
  await tog.click(); await p.waitForTimeout(500); const open2 = await p.locator('#nav-group-billing').evaluate(e => e.classList.contains('show'));
  console.log('billing toggle', open1, open2);
  // sidebar collapse
  await p.locator('a:has(.fa-bars), button:has(.fa-bars)').first().click().catch(()=>{}); await p.waitForTimeout(500); await p.screenshot({ path: IMG + '/nav-collapsed.png' });
  await b.close();
})();
