// opens modals on a page by clicking a selector and screenshotting the open modal
const { H, IMG, ctx } = require('./common');
(async () => {
  const [page, ...sels] = process.argv.slice(2);
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('console', m => { if (m.type()==='error') errs.push(m.text().slice(0,120)); }); p.on('pageerror', e => errs.push(String(e).slice(0,120)));
  let i = 0;
  for (const s of sels) {
    await p.goto(H.BASE + '/' + page, { waitUntil: 'networkidle' });
    const [pre, sel] = s.includes('>>') ? s.split('>>') : [null, s];
    try {
      if (pre) { await p.locator(pre).first().click(); await p.waitForTimeout(300); }
      await p.locator(sel).first().click(); await p.waitForTimeout(1200);
    } catch (e) { console.log('click fail', s, e.message.slice(0,80)); continue; }
    const name = page.replace(/[\/.?=&]/g,'_') + '-m' + (i++);
    await p.screenshot({ path: `${IMG}/${name}.png` });
    const info = await p.evaluate(() => { const m = document.querySelector('.modal.show .modal-dialog'); if (!m) return 'no modal'; const r = m.getBoundingClientRect(); return JSON.stringify({ top: r.top|0, bottom: r.bottom|0, vh: innerHeight, title: (m.querySelector('.modal-title')||{}).textContent }); });
    console.log(name, s, info);
  }
  console.log('errs', errs); await b.close();
})();
