const L = require('./lib');
(async () => {
  const [,, st, subj] = process.argv;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState: L.IMG + '/' + st });
  const p = await ctx.newPage();
  await p.goto(L.BASE + '/client/ticket_add.php', { waitUntil: 'networkidle' });
  await p.fill('input[name=subject]', subj);
  await p.evaluate(() => tinymce.activeEditor.setContent('<p>Details from QA-UI <b>bold</b></p>'));
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('button[name=add_ticket]')]);
  console.log(p.url()); await p.screenshot({ path: L.IMG + '/mk_ticket.png', fullPage: true }); await b.close();
})();
