const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/clients.php');
 await p.waitForTimeout(500);await p.screenshot({path:m.IMG+'/alert.png',clip:{x:256,y:56,width:1664,height:200}});await b.close();})();
