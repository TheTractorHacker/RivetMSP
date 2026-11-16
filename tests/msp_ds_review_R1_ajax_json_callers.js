// Review R1 (MSP-DS-28 regression): agent/ajax.php answers application/json, so jQuery hands callbacks an already-parsed value.
// No caller of ajax.php may JSON.parse() it unguarded. Run: node tests/msp_ds_review_R1_ajax_json_callers.js
const fs = require('fs'), path = require('path'), vm = require('vm');
const root = path.join(__dirname, '..');
let pass = 0, fail = 0;
const ok = (c, l) => { if (c) { pass++; console.log('  ok   ' + l); } else { fail++; console.log('  FAIL ' + l); } };

// 1. the endpoint keeps its XSS protection
const ajax = fs.readFileSync(path.join(root, 'agent/ajax.php'), 'utf8');
ok(/header\('Content-Type: application\/json; charset=utf-8'\);\s*\n\s*header\('X-Content-Type-Options: nosniff'\)/.test(ajax), 'ajax.php sends JSON content-type + nosniff by default');

// 2. static scan: every file that calls ajax.php
function walk(d, out) {
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    if (['plugins', 'vendor', 'node_modules', '.git', 'tests', 'uploads'].includes(e.name)) continue;
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, out); else if (/\.(js|php)$/.test(e.name)) out.push(p);
  }
  return out;
}
const files = [];
for (const d of ['agent', 'js', 'admin', 'client']) walk(path.join(root, d), files);
let callers = 0;
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  if (!/ajax\.php/.test(src)) continue;
  callers++;
  const bad = [];
  src.split('\n').forEach((line, i) => {
    // JSON.parse of a callback argument (data/response/res/resp/result) without a typeof guard
    if (/JSON\.parse\(\s*(data|response|res|resp|result)\s*\)/.test(line) && !/typeof\s+(data|response|res|resp|result)/.test(line)) bad.push(i + 1);
  });
  ok(bad.length === 0, path.relative(root, f) + (bad.length ? ' unguarded JSON.parse at line ' + bad.join(',') : ''));
}
ok(callers > 20, 'scanned ' + callers + ' ajax.php callers');

// 3. run real callers against a jQuery stub that behaves like jQuery 3.7 on a JSON content-type (parsed value delivered)
function run(file, reply, elId) {
  const el = { value: '', innerText: '' };
  const ctx = vm.createContext({
    jQuery: { get: (u, d, cb) => cb(reply) }, document: { getElementById: () => el, addEventListener() {} }, console,
  });
  vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), ctx);
  return { el, ctx };
}
{
  const r = run('agent/js/generate_password.js', 'TheSmartTigerRunsQuickly');
  r.ctx.generatePassword(); ok(r.el.value === 'TheSmartTigerRunsQuickly', 'generatePassword fills the field from a parsed JSON string');
  for (const f of ['agent/js/credential_show_otp_via_id.js', 'js/credential_show_otp_via_id.js']) {
    const o = run(f, '012345'); o.ctx.showOTPViaCredentialID(7);
    ok(o.el.innerText === '012345', f + ' shows a TOTP starting with 0');
    const e = run(f, { error: 'vault' }); e.ctx.showOTPViaCredentialID(7);
    ok(e.el.innerText === '', f + ' shows nothing for an {error} reply');
  }
}
console.log(pass + ' passed, ' + fail + ' failed'); process.exit(fail ? 1 : 0);
