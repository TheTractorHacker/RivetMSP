// Re-review RR5: callers of ajax.php branches that return a bare JSON STRING (get_readable_pass, share_generate_link) must use the
// already-parsed value. A `typeof data === "string" ? JSON.parse(data) : data` guard still throws on a parsed string.
// Run: node tests/msp_ds_review_RR5_string_callers.js
const fs = require('fs'), path = require('path'), vm = require('vm');
const root = path.join(__dirname, '..');
let pass = 0, fail = 0;
const ok = (c, l) => { if (c) { pass++; console.log('  ok   ' + l); } else { fail++; console.log('  FAIL ' + l); } };

function walk(d, out) {
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    if (['plugins', 'vendor', 'node_modules', '.git', 'tests', 'uploads'].includes(e.name)) continue;
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, out); else if (/\.(js|php)$/.test(e.name)) out.push(p);
  }
  return out;
}
const files = [];
for (const d of ['agent', 'js', 'admin', 'client', 'guest']) if (fs.existsSync(path.join(root, d))) walk(path.join(root, d), files);

// the real `jQuery.get(... <branch> ... function(data){...});` call expression, cut out of the source
function callOf(src, branch) {
  const out = []; let i = -1;
  while ((i = src.indexOf(branch, i + 1)) !== -1) {
    const start = src.lastIndexOf('jQuery.get(', i); if (start < 0) continue;
    const end = src.indexOf('\n', src.indexOf('function', i)); let depth = 0, j = src.indexOf('(', start), k = j;
    for (; k < src.length; k++) { if (src[k] === '(') depth++; else if (src[k] === ')' && --depth === 0) break; }
    out.push(src.slice(start, k + 1));
  }
  return out;
}
let n = 0;
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  for (const branch of ['get_readable_pass', 'share_generate_link']) {
    for (const call of callOf(src, branch)) {
      n++;
      ok(!/JSON\.parse\(/.test(call), path.relative(root, f) + ' (' + branch + ') call does not JSON.parse a string reply');
      // run the real call with a jQuery stub that delivers the parsed string
      const el = { value: '', hidden: true }, ctx = vm.createContext({ console, navigator: { clipboard: { writeText() {} } },
        jQuery: { get: (u, d, cb) => cb(branch === 'get_readable_pass' ? 'TheSmartTigerRunsQuickly' : 'https://x.example/s?id=1') },
        document: { getElementById: () => el, querySelector: () => el, querySelectorAll: () => [] }, input: el, type: 'contact', id: 1, item_expires: 1,
        csrf_token: 'x', client_id: 1, item_type: 'a', item_ref_id: 1, item_note: '', item_views: 0, contact_email: '' });
      let thrown = null;
      try { vm.runInContext(call.replace(/\/\*[\s\S]*?\*\//g, ''), ctx); } catch (e) { thrown = e; }
      ok(!thrown, path.relative(root, f) + ' (' + branch + ') callback runs on a parsed string' + (thrown ? ' (' + thrown.message + ')' : ''));
    }
  }
}
ok(n >= 7, 'ran ' + n + ' string-branch calls');
console.log(pass + ' passed, ' + fail + ' failed'); process.exit(fail ? 1 : 0);
