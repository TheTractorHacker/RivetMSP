const m=require('./modal.js');
(async()=>{const N=+process.argv[4]||6;const {b,p}=await m.ctxPage();await m.go(p,process.argv[2]);
const r=await p.evaluate(([sel,N])=>[...document.querySelectorAll(sel)].slice(0,N).map(e=>{const c=getComputedStyle(e);return e.tagName+'|'+(e.textContent||'').trim().slice(0,22)+'|pad '+c.padding+'|fs '+c.fontSize+'|fw '+c.fontWeight+'|tt '+c.textTransform+'|ls '+c.letterSpacing+'|h '+Math.round(e.getBoundingClientRect().height)+'|x '+Math.round(e.getBoundingClientRect().x)}),[process.argv[3],N]);
console.log(r.join('\n'));await b.close()})();
