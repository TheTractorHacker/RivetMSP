const L = require('./lib');
(async () => {
  const st = process.argv[2]; const w=+process.argv[3], h=+process.argv[4];
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: w, height: h }, storageState: L.IMG + '/' + st });
  const p = await ctx.newPage(); await p.goto(L.BASE + '/client/index.php', { waitUntil: 'networkidle' });
  if (w < 992) { await p.click('.navbar-toggler'); await p.waitForTimeout(500); }
  await p.screenshot({ path: `${L.IMG}/nav_${st.replace('.json','')}_${w}_menu.png` });
  for (const t of ['Finance', 'Technical']) { const el = p.locator('.nav-link.dropdown-toggle', { hasText: t }); if (await el.count()) { await el.first().click(); await p.waitForTimeout(400); await p.screenshot({ path: `${L.IMG}/nav_${st.replace('.json','')}_${w}_${t}.png` }); } }
  const u = p.locator('.nav-link.dropdown-toggle').last(); await u.click(); await p.waitForTimeout(400); await p.screenshot({ path: `${L.IMG}/nav_${st.replace('.json','')}_${w}_user.png` });
  await b.close();
})();
