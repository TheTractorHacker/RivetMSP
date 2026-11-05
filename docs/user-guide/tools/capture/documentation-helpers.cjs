// Local helpers for the documentation capture script (kept out of lib.cjs on purpose).
const { BASE, settle, CLEAN } = require('../lib.cjs');

// Click an element that opens an ajax modal, even when it is inside a closed dropdown, then wait for the modal body.
async function openModal(page, urlFragment, { wait = 900 } = {}) {
  await page.evaluate(() => document.querySelectorAll('.ug-callout').forEach((n) => n.remove()));
  await page.evaluate((frag) => {
    const el = document.querySelector(`[data-modal-url*="${frag}"]`);
    if (!el) throw new Error('no modal trigger for ' + frag);
    el.click();
  }, urlFragment);
  await page.waitForSelector('.modal.show .modal-content', { timeout: 10000 });
  await page.waitForTimeout(wait);
}

// A form group inside the open modal, found by the text of its label.
const fg = (label) => `.modal.show .form-group:has(> label:has-text("${label}"))`;

async function tall(page, h = 1250) {
  const vp = page.viewportSize();
  if (vp.height !== h) { await page.setViewportSize({ width: vp.width, height: h }); await page.waitForTimeout(350); }
}
async function normal(page, h = 900) { await tall(page, h); }

async function closeModal(page) {
  await normal(page);
  await page.evaluate(() => {
    document.querySelectorAll('.ug-callout').forEach((n) => n.remove());
    const m = document.querySelector('.modal.show');
    if (m && window.bootstrap) { const i = window.bootstrap.Modal.getInstance(m); if (i) i.hide(); }
  });
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    document.querySelectorAll('.modal-backdrop').forEach((n) => n.remove());
    document.body.classList.remove('modal-open'); document.body.style.overflow = '';
  });
}

// Click a pill/tab inside the open modal by its visible text.
async function modalTab(page, text) {
  await page.locator('.modal.show .nav-link', { hasText: text }).first().click();
  await page.waitForTimeout(400);
}

// Open a dropdown menu next to a trigger so it can be photographed.
async function openDropdown(page, selector) {
  await page.locator(selector).first().click();
  await page.waitForTimeout(400);
}

// Scroll so that an element is near the top of the viewport (the fixed header is ~60px).
async function scrollTo(page, selector, offset = 90) {
  await page.evaluate(([sel, off]) => {
    const el = document.querySelector(sel);
    if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - off);
  }, [selector, offset]);
  await page.waitForTimeout(300);
}

// Call-outs like lib.cjs callout(), but the target may be any Playwright selector (text=, :has-text, etc.).
// items: [{ selector, n, side }]; a target that is not found is skipped (and reported) rather than failing the run.
async function co(page, items) {
  if (CLEAN) return;
  const rects = [];
  for (const it of items) {
    const loc = page.locator(it.selector).first();
    let box = null;
    try { await loc.waitFor({ state: 'visible', timeout: 2500 }); box = await loc.boundingBox(); } catch (e) { box = null; }
    if (!box) { console.warn('  call-out target not found:', it.n, it.selector); continue; }
    rects.push({ box, n: it.n, side: it.side || 'tl' });
  }
  await page.evaluate((rects) => {
    document.querySelectorAll('.ug-callout').forEach((n) => n.remove());
    const sx = window.scrollX, sy = window.scrollY;
    rects.forEach(({ box: r, n, side }) => {
      const left = r.x + sx, top = r.y + sy;
      const frame = document.createElement('div');
      frame.className = 'ug-callout';
      frame.style.cssText = `position:absolute;z-index:2147483646;pointer-events:none;border:3px solid #e03131;border-radius:6px;left:${left - 3}px;top:${top - 3}px;width:${r.width + 6}px;height:${r.height + 6}px;box-shadow:0 0 0 2px rgba(255,255,255,.7)`;
      const badge = document.createElement('div');
      badge.className = 'ug-callout';
      const bx = side.includes('r') ? left + r.width - 4 : left - 18;
      const by = side.includes('b') ? top + r.height - 4 : top - 18;
      badge.textContent = String(n);
      badge.style.cssText = `position:absolute;z-index:2147483647;pointer-events:none;left:${bx}px;top:${by}px;width:26px;height:26px;line-height:26px;text-align:center;border-radius:50%;background:#e03131;color:#fff;font:700 14px/26px system-ui,sans-serif;box-shadow:0 1px 4px rgba(0,0,0,.4)`;
      document.body.appendChild(frame);
      document.body.appendChild(badge);
    });
  }, rects);
}

module.exports = { co, fg, tall, normal, openModal, closeModal, modalTab, openDropdown, scrollTo, BASE, settle };
