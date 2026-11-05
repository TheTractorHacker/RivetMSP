const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080},storageState:I+'state.json'});const page=await ctx.newPage();
const errs=[];page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+r.url().replace(h.BASE,''))});
const flash=()=>page.evaluate(()=>[...document.querySelectorAll('.toast,.alert,#alert,.toastr')].map(e=>e.textContent.trim().slice(0,120)).join('|'));
// password wrong current
await page.goto(h.BASE+'/agent/user/user_security.php');
await page.fill('input[name=current_password]','wrongwrong');await page.fill('input[name=new_password]','short');
await page.click('button[name=edit_your_user_password]');await page.waitForLoadState('load');await page.waitForTimeout(700);console.log('pw bad ->',page.url().replace(h.BASE,''),await flash());await page.screenshot({path:I+'pf_pw.png',clip:{x:0,y:0,width:1920,height:400}});
// empty submit
await page.click('button[name=edit_your_user_password]');await page.waitForTimeout(500);console.log('pw empty ->',await flash(),page.url().replace(h.BASE,''));
// MFA modal
await page.click('text=Enable MFA');await page.waitForTimeout(1500);console.log('mfa modal',await page.evaluate(()=>!!document.querySelector('.modal.show')));await page.screenshot({path:I+'pf_mfa.png'});await page.keyboard.press('Escape');await page.waitForTimeout(500);
// add passkey
await page.click('text=Add Passkey');await page.waitForTimeout(1500);console.log('passkey ->',await flash(),await page.evaluate(()=>!!document.querySelector('.modal.show')));await page.screenshot({path:I+'pf_pk.png'});
// details name empty
await page.goto(h.BASE+'/agent/user/user_details.php');await page.fill('input[name=name]','');await page.click('button:has-text("Save Changes")');await page.waitForTimeout(600);console.log('name empty invalid:',await page.evaluate(()=>[...document.querySelectorAll(':invalid')].map(e=>e.name)));
await page.fill('input[name=name]','Alex Morgan');await page.fill('input[name=title]','QA-UI Title').catch(()=>{});await page.click('button:has-text("Save Changes")');await page.waitForLoadState('load');await page.waitForTimeout(700);console.log('details save ->',await flash());
await page.goto(h.BASE+'/agent/user/user_details.php');console.log('title persisted:',await page.inputValue('input[name=title]').catch(e=>'n/a'));
await page.fill('input[name=title]','').catch(()=>{});await page.click('button:has-text("Save Changes")');await page.waitForLoadState('load');
// prefs
await page.goto(h.BASE+'/agent/user/user_preferences.php');await page.selectOption('select[name=records_per_page]','25').catch(e=>console.log('rpp',String(e).slice(0,60)));await page.click('button[name=edit_your_user_preferences]');await page.waitForLoadState('load');await page.waitForTimeout(500);console.log('prefs ->',await flash());
await page.goto(h.BASE+'/agent/user/user_preferences.php');console.log('rpp now',await page.inputValue('select[name=records_per_page]'));await page.selectOption('select[name=records_per_page]','10');await page.click('button[name=edit_your_user_preferences]');await page.waitForLoadState('load');
console.log(errs.slice(0,5));await b.close();})();
