const h = require('./h.js');
(async () => { const mode = process.argv[2]; // dark|light
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE+'/agent/user/user_preferences.php'); await p.waitForLoadState('networkidle');
 await p.locator('label:has(input[name=dark_mode])').nth(mode==='dark'?1:0).click();
 await p.locator('form:has(input[name=dark_mode]) [type=submit]').first().click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500);
 console.log(mode, await p.evaluate(()=>document.documentElement.getAttribute('data-bs-theme'))); await b.close(); })();
