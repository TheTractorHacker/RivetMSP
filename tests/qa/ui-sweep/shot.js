// usage: VP=1920x1080 DARK=1 node shot.js <path> <name>
const m=require('./m2.js');const [w,hh]=(process.env.VP||'1920x1080').split('x').map(Number);
(async()=>{const {b,p}=await m.ctxPage({width:w,height:hh},!!process.env.DARK);await m.go(p,process.argv[2]);await p.waitForTimeout(400);
await p.screenshot({path:m.IMG+'/'+process.argv[3]+'.png',fullPage:true});
const a=await m.h.audit(p,!!process.env.AXE);console.log(JSON.stringify(a));if(p.errs.length)console.log(p.errs);await b.close();})();
