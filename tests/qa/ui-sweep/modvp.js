// usage: node modvp.js <page> <modalurl> [size]   audits modal at 1366x768 and 390x844 (can it scroll, footer reachable, hscroll)
const m=require('./m2.js');
(async()=>{for(const vp of [[1366,768],[390,844]]){const {b,p}=await m.ctxPage({width:vp[0],height:vp[1]});await m.go(p,process.argv[2]);
 await m.openModal(p,process.argv[3],process.argv[4]);
 const r=await p.evaluate(()=>{const mo=document.querySelector('.modal.show');const d=mo.querySelector('.modal-dialog');const c=mo.querySelector('.modal-content');const ft=mo.querySelector('.modal-footer');
  const fr=ft?ft.getBoundingClientRect():null;const scrollable=getComputedStyle(mo).overflowY;const mb=mo.querySelector('.modal-body');
  return {vh:innerHeight,vw:innerWidth,dlgW:Math.round(d.getBoundingClientRect().width),contentH:Math.round(c.getBoundingClientRect().height),footerBottom:fr&&Math.round(fr.bottom),footerVisible:fr&&fr.bottom<=innerHeight+1,modalOverflowY:scrollable,dialogScrollable:d.classList.contains('modal-dialog-scrollable'),bodyOverflow:mb&&getComputedStyle(mb).overflowY,hs:mo.scrollWidth>mo.clientWidth+1,docHs:document.documentElement.scrollWidth>document.documentElement.clientWidth+1}});
 console.log(vp.join('x'),JSON.stringify(r));
 await p.screenshot({path:`${m.IMG}/modvp_${process.argv[3].replace(/\W+/g,'_').slice(0,30)}_${vp[0]}.png`});await b.close();}})();
