const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 const S=(n)=>p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/f_'+n+'.png'});
 const form = () => p.locator('form:has(input[name=max_uses])');
 await p.goto(h.BASE+'/agent/panel_deploy.php?client_id=1');
 // bypass HTML5 validation to see server-side handling
 await form().evaluate(f=>f.noValidate=true);
 await form().locator('input[name=ttl_hours]').fill('0'); await form().locator('input[name=max_uses]').fill('0');
 await form().locator('button[type=submit]').click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(500);
 console.log('invalid ->', p.url().replace(h.BASE,''), (await p.locator('.alert, .toast, .toastr, #toast-container').allInnerTexts()).join('|').slice(0,200)); await S('panel_invalid');
 await form().locator('input[name=label]').fill('QA-UI-panel token'); await form().locator('input[name=ttl_hours]').fill('24'); await form().locator('input[name=max_uses]').fill('1');
 await form().locator('button[type=submit]').click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(700);
 console.log('valid ->', p.url().replace(h.BASE,''), (await p.locator('.alert').allInnerTexts()).join('|').slice(0,300)); await S('panel_valid');
 await b.close(); })();
