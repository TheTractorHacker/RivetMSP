// usage: node ev.js <path> "<js expression>"  (evaluates in page, prints result)
const m=require('./m2.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,process.argv[2]);
console.log(JSON.stringify(await p.evaluate(process.argv[3]),null,1));if(p.errs.length)console.log(p.errs);await b.close();})();
