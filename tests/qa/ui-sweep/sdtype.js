const m=require('./modal.js');
(async()=>{const {b,p}=await m.ctxPage();
await m.go(p,'admin/structured_doc_types.php');await m.openModal(p,'modals/structured_doc_type/structured_doc_type_add.php','md');
await m.fillModal(p,{val:{type_name:'QA-UI Firewall',type_icon:'fa-shield-alt'}});console.log(await m.submit(p));
const tid=process.argv[2]||'';
const t=await p.evaluate(()=>{const e=document.querySelector('[data-modal-url*="structured_doc_field_add"]');return e?e.getAttribute('data-modal-url'):''});console.log(t);
for(const [lab,kind] of [['WAN IP','text'],['Notes','textarea'],['Public','checkbox']]){
 await m.openModal(p,t,'md');const opts=await p.evaluate(()=>[...document.querySelectorAll('.modal.show select[name=field_kind] option')].map(o=>o.value));
 const k=opts.includes(kind)?kind:opts[1];await m.fillModal(p,{val:{field_label:'QA-UI '+lab,field_kind:k,field_options:''}});console.log(lab,k,await m.submit(p));}
await b.close()})();
