const h=require('./h.js');const I='/home/sysadmin/ui-sweep-adminother-img/';
(async()=>{const b=await h.launch();
for(const [w,hh] of [[1920,1080],[390,844]]){
const ctx=await b.newContext({viewport:{width:w,height:hh}});const page=await ctx.newPage();const errs=[];
page.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,100))});page.on('response',r=>{if(r.status()>=400)errs.push(r.status()+r.url())});
await page.goto(h.BASE+'/login.php');await page.screenshot({path:I+`login_${w}.png`});
console.log(w,'login audit',JSON.stringify(await h.audit(page,true)));
// empty submit
await page.click('button[type=submit]');await page.waitForTimeout(600);await page.screenshot({path:I+`login_empty_${w}.png`});
console.log(w,'after empty url',page.url());
// forgot
await page.goto(h.BASE+'/client/login_reset.php');await page.screenshot({path:I+`reset_${w}.png`});console.log(w,'reset audit',JSON.stringify(await h.audit(page,true)));
const em=page.locator('input[type=email],input[name=email]').first();if(await em.count()){await em.fill('notanemail');await page.click('button[type=submit]').catch(()=>{});await page.waitForTimeout(600);await page.screenshot({path:I+`reset_bad_${w}.png`});}
console.log(w,'errs',errs);await ctx.close();}
await b.close();})();
