const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 for (const id of ['ea_sw_policies','ea_sw_scripts','ea_sw_alerting','ea_sw_patching','ea_sw_software','ea_sw_hypervisor','ea_inv_sw']) {
  await p.goto(h.BASE + '/admin/settings_endpoint_agent.php'); await p.waitForTimeout(600);
  const el = p.locator('#'+id); await p.evaluate(i=>{const e=document.getElementById(i); const t=e.closest('.tab-pane'); },id);
  const was = await el.isChecked().catch(()=>null); if (was) { console.log(id,'already'); continue; }
  await el.evaluate(e=>{ e.checked=true; e.dispatchEvent(new Event('change',{bubbles:true})); e.form.querySelector('button[type=submit]').click(); }).catch(e=>console.log(id,'ERR',String(e).slice(0,80)));
  await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
  console.log(id, 'saved', await p.locator('#'+id).isChecked()); }
 await b.close(); })();
