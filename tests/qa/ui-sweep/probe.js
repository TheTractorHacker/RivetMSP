const L=require('./lib.js');
(async()=>{const b=await L.launch();const ctx=await L.ctxFor(b,process.argv[2]||'admin',{width:+(process.argv[3]||1920),height:1080});const p=await ctx.newPage();
await p.goto(L.BASE+process.argv[4],{waitUntil:'networkidle'});
const r=await p.evaluate(process.argv[5]);console.log(JSON.stringify(r,null,1));await b.close()})();
