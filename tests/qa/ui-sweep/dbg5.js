const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();await m.go(p,'agent/network_drives.php?client_id=1&archived=1');
console.log(p.url(),await p.evaluate(()=>[document.querySelectorAll('a.confirm-link').length,[...document.querySelectorAll('a.confirm-link')].map(e=>e.getAttribute('href').slice(0,40)),document.body.innerText.match(/QA-UI[^\n]*/g)]));await b.close()})();
