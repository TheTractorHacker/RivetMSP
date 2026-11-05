process.env.QA_BASE='http://127.0.0.1:8213';
const h=require('./h.js');
(async()=>{const b=await h.launch();const ctx=await b.newContext({viewport:{width:1920,height:1080}});const p=await ctx.newPage();
await h.login(p,'admin');console.log(p.url());await ctx.storageState({path:'/home/sysadmin/sweep-billing-img/state.json'});await b.close();})();
