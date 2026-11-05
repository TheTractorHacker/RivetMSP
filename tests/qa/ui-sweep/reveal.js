const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/credentials.php?client_id=1');
const reqs=[];p.on('response',r=>{if(/credential_reveal/.test(r.url()))reqs.push(r.status())});
const eyes=await p.$$('tbody tr:has-text("QA-UI Credential") .fa-eye');
await eyes[1].click();await p.waitForSelector('#credStepUpModal.show');await p.waitForTimeout(500);
await (await p.$('#credStepUpModal .modal-content')).screenshot({path:m.IMG+'/stepup.png'});
// wrong password first
await p.fill('#credStepUpModal input[type=password]','wrong-pass-QA');await p.click('#credStepUpModal button:has-text("Confirm"), #credStepUpModal button.btn-primary');await p.waitForTimeout(900);
console.log('wrong pw -> modal still open',await p.evaluate(()=>!!document.querySelector('#credStepUpModal.show')),'msg:',await p.evaluate(()=>(document.querySelector('#credStepUpModal .text-danger, #credStepUpModal .alert')||{innerText:''}).innerText.slice(0,80)));
await p.fill('#credStepUpModal input[type=password]','DemoAdmin#2026');await p.click('#credStepUpModal button:has-text("Confirm"), #credStepUpModal button.btn-primary');await p.waitForTimeout(1200);
const st=await p.evaluate(()=>{const r=[...document.querySelectorAll('tbody tr')].find(t=>/QA-UI Credential/.test(t.innerText));return {modal:!!document.querySelector('#credStepUpModal.show'),dots:(r.innerText.match(/•+/g)||[]).length,len:r.innerText.length}});
console.log('after correct pw',JSON.stringify(st),'reveal calls',reqs.join(','));
await b.close()})();
