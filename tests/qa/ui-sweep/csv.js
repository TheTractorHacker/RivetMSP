const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 for (const u of ['agent/rmm_software_report_csv.php?group_by=package','agent/rmm_software_report_csv.php?group_by=bogus','agent/rmm_job_output.php','agent/rmm_job_output.php?device_id=1&job_id=x']) { const r = await c.request.get(h.BASE+'/'+u); console.log(u, r.status(), r.headers()['content-type'], (await r.text()).slice(0,100).replace(/\r?\n/g,'\\n')); }
 await b.close(); })();
