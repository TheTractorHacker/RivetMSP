const { H, IMG, ctx } = require('./common');
const PAGES = ['settings_company','settings_localization','settings_default','settings_ticket','settings_invoice','settings_quote','settings_project','payroll_settings','settings_kb','settings_compliance','settings_ai','settings_notification','settings_appearance','settings_portal_branding','settings_module'];
(async () => {
  const b = await H.launch(); const c = await ctx(b); const p = await c.newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0,100))); p.on('response', r => { if (r.status()>=400) errs.push(r.status()+' '+r.url().slice(0,80)); });
  for (const pg of PAGES) {
    await p.goto(H.BASE + '/admin/' + pg + '.php', { waitUntil: 'networkidle' });
    const btn = p.locator('form[action="post.php"] button[type=submit]:visible').first();
    if (!(await btn.count())) { console.log(pg, 'no submit'); continue; }
    errs.length = 0;
    await Promise.all([p.waitForLoadState('networkidle').catch(()=>{}), btn.click()]); await p.waitForTimeout(700);
    const body = await p.locator('body').innerText();
    const flash = (await p.locator('.alert:visible, .toast:visible, .noty_body:visible, [role=alert]:visible').allInnerTexts()).join('|').replace(/\s+/g,' ').slice(0,80);
    const raw = /(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception)/.exec(body);
    console.log(pg, '->', p.url().replace(H.BASE,''), 'flash:', flash, raw ? 'RAW '+raw[0] : '', errs.slice(0,2));
    await p.screenshot({ path: IMG + '/save-' + pg + '.png' });
  }
  await b.close();
})();
