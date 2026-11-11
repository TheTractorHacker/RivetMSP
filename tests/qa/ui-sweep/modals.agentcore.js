// open every distinct ajax modal found on a page, screenshot + audit. usage: node modals.js user dark|light page [vw vh] [filter-regex]
const L = require('./lib.js');
(async () => {
  const [,, user, theme, page_, vw = '1920', vh = '1080', filt = ''] = process.argv; const b = await L.launch();
  const ctx = await L.ctxFor(b, user, { width: +vw, height: +vh }); const p = await ctx.newPage(); const log = []; L.watch(p, log);
  await p.goto(L.BASE + page_, { waitUntil: 'networkidle' });
  const urls = await p.evaluate(() => { const m = new Map(); document.querySelectorAll('[data-modal-url]').forEach(e => { const u = e.getAttribute('data-modal-url'); const k = u.split('?')[0]; if (!m.has(k)) m.set(k, [u, e.getAttribute('data-modal-size') || 'md']); }); return [...m.values()]; });
  const re = filt ? new RegExp(filt) : null; let n = 0;
  for (const [u, size] of urls) {
    if (re && !re.test(u)) continue; n++;
    log.length = 0;
    await p.evaluate(([u, s]) => window.openAjaxModal(u, s, {}), [u, size]);
    try { await p.waitForSelector('.modal.show .modal-content', { timeout: 6000 }); } catch (e) { console.log('NOMODAL', u); continue; }
    await p.waitForTimeout(700);
    if (theme === 'dark') await p.evaluate(() => { document.documentElement.setAttribute('data-bs-theme', 'dark'); document.body.classList.add('dark-mode'); });
    const info = await p.evaluate(() => {
      const m = document.querySelector('.modal.show'); const d = m.querySelector('.modal-dialog'); const c = m.querySelector('.modal-content'); const r = c.getBoundingClientRect();
      const o = { title: (m.querySelector('.modal-title') || {}).textContent, h: Math.round(r.height), vh: innerHeight, bodyScroll: (() => { const b = m.querySelector('.modal-body'); return b ? b.scrollHeight > b.clientHeight + 2 : null })(), hs: c.scrollWidth > c.clientWidth + 1, overflowY: getComputedStyle(m).overflowY };
      o.cutTop = r.top < -1; o.cutBottom = r.bottom > innerHeight + 1 && getComputedStyle(m.querySelector('.modal-body') || m).overflowY === 'visible' && getComputedStyle(m).overflowY === 'hidden';
      const unl = []; m.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]), select, textarea').forEach(e => { if (e.offsetParent === null) return; const id = e.id; const lab = (id && m.querySelector('label[for="' + id + '"]')) || e.closest('.form-group, .mb-3, .col, [class*=col-]')?.querySelector('label'); if (!lab && !e.getAttribute('aria-label') && !e.closest('.input-group')) unl.push(e.name || e.id || e.tagName); });
      o.unlabeled = unl.slice(0, 6);
      const req = m.querySelectorAll('[required]').length, stars = m.querySelectorAll('.text-danger, .required, .req').length; o.required = req; o.stars = stars;
      const btns = [...m.querySelectorAll('.modal-footer button, .modal-footer a')].map(x => x.textContent.trim()).filter(Boolean); o.btns = btns;
      const t = m.innerText; o.rawerr = (/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|mysqli_sql)/.exec(t) || [null])[0];
      return o; });
    const name = u.split('?')[0].replace(/[^a-z0-9]+/gi, '_').slice(-50);
    await p.screenshot({ path: `${L.SCR}/img/M${theme[0]}_${vw}_${name}.png` });
    console.log(u.slice(0, 70), JSON.stringify(info), log.length ? JSON.stringify([...new Set(log)].slice(0, 3)) : '');
    await p.keyboard.press('Escape'); await p.waitForTimeout(400);
    await p.evaluate(() => { document.querySelectorAll('.modal.show').forEach(m => bootstrap.Modal.getInstance(m)?.hide()); });
    await p.waitForTimeout(300);
  }
  console.log('opened', n, 'of', urls.length); await b.close();
})();
