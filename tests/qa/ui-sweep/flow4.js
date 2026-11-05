const L = require('./lib');
(async () => {
  const w = +process.argv[2]||1920, h = +process.argv[3]||1080;
  const b = await L.launch(); const ctx = await b.newContext({ viewport: { width: w, height: h }, storageState: L.IMG + '/tech.json' });
  const p = await ctx.newPage(); const errs=[]; p.on('pageerror', e=>errs.push(String(e).slice(0,100))); p.on('response', r=>{if(r.status()>=400)errs.push(r.status()+' '+r.url().replace(L.BASE,''))});
  const shot = n => p.screenshot({ path: `${L.IMG}/f4_${w}_${n}.png` });
  await p.goto(L.BASE + '/client/documents.php', { waitUntil: 'networkidle' });
  await p.click('button:has-text("New Document")'); await p.waitForTimeout(600); await shot('newdoc');
  // empty submit
  await p.click('#uploadDocumentModal button[name=client_add_document]'); await p.waitForTimeout(400); await shot('newdoc_empty');
  await p.fill('#uploadDocumentModal input[name=document_name]', 'QA-UI portal doc');
  await p.fill('#uploadDocumentModal textarea', 'Some content <b>x</b>');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('#uploadDocumentModal button[name=client_add_document]')]);
  await shot('newdoc_done'); console.log((await p.innerText('body')).replace(/\n+/g,' | ').slice(300,700));
  await p.click('button:has-text("Upload File")'); await p.waitForTimeout(600); await shot('upload');
  await p.fill('#uploadFileDocumentModal input[name=document_name]', 'QA-UI uploaded doc');
  await p.setInputFiles('#documentFileInput', L.IMG + '/qa-ui-attach.txt');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(()=>{}), p.click('#uploadFileDocumentModal button[name=client_upload_document]')]);
  await shot('upload_done'); console.log((await p.innerText('body')).replace(/\n+/g,' | ').slice(300,800));
  console.log('errs', JSON.stringify(errs)); await b.close();
})();
