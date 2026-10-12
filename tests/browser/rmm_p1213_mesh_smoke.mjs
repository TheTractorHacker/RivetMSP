// Browser smoke for the optional MeshCentral agent (RivetCore 1.0.0-rc.12, migration 0023): the install settings on Administration > Endpoint agent (device group id, certificate
// pin and the "read it from the server" button that only PROPOSES, the overwrite switch, the advanced corrections), the tick-box in the Add device dialog, and the device's Mesh
// agent state (installed / failed / mapped / conflict / kept manual) on the asset page, desktop and phone, light and dark.
//
//   RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 /tmp/claude-1000/rc13msp_browser.sh 8681 tests/browser/rmm_p1213_mesh_smoke.mjs
//
// RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 must be set for THIS run only: php -S inherits it, which lets the pin probe reach the loopback TLS server this script starts. There is NO real
// MeshCentral server behind any of it (the MeshCentral agent download is covered by the agent's own tests against a mock). Same driver, harness and safety rules as the other smokes:
// loopback only, zero npm dependencies, signs in through the real login form, every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke changes administration settings and converts a table; it is meant for a throwaway install.`);
  process.exit(2);
}
if (process.env.RIVETMSP_WEBHOOK_ALLOW_PRIVATE !== '1') {
  console.error('Run with RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 in front of the wrapper (the php -S server needs it to reach the loopback fake store).');
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });


const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-p1213-mesh', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); await sleep(500); };   // let fonts and layout settle before anything is clicked
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const ADMIN = '/admin/settings_endpoint_agent.php';


// Clicks that survive the late layout shifts of a page opened on a #fragment (the browser re-applies the scroll to the fragment while fonts and images arrive): the
// target's position must be the same twice, 200 ms apart, before the mouse goes down.
const click = async (sel) => {
  let a = await p.centre(sel);
  for (let i = 0; i < 8; i++) {
    await sleep(200);
    const b = await p.centre(sel);
    const same = Math.abs(a.x - b.x) < 1 && Math.abs(a.y - b.y) < 1;
    a = b;
    if (same) break;
  }
  await p.clickAt(a.x, a.y);
};
const nav = async (sel, timeout = 90000) => {
  const origin = await p.eval('performance.timeOrigin');
  await click(sel);
  const end = Date.now() + timeout;
  for (;;) {
    await sleep(200);
    try { if (await p.eval(`performance.timeOrigin !== ${origin} && document.readyState === 'complete'`)) break; } catch { /* the execution context is gone mid-navigation */ }
    if (Date.now() > end) throw new Error('page did not navigate after clicking ' + sel);
  }
  await sleep(600);
};

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
/** A screenshot of one element (a card), however far down the page it is. */
async function shotEl(sel, name) {
  const r = await p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;const b=e.getBoundingClientRect();return {x:b.x+scrollX,y:b.y+scrollY,w:b.width,h:b.height}})()`);
  assert(r, 'no element ' + sel);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: Math.max(0, r.x), y: Math.max(0, r.y), width: Math.ceil(r.w), height: Math.min(Math.ceil(r.h), 6000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const over=[];
  const clipped=(e)=>{for(let n=e.parentElement;n&&n!==document.body&&n!==d;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'||o==='clip')return true}return false};
  for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width<=0||r.right<=vw+1)continue;
    const cs=getComputedStyle(e);if(cs.position==='fixed'||cs.visibility==='hidden'||cs.display==='none')continue;
    if(e.closest('[hidden],.modal,.offcanvas,.dropdown-menu,.navbar-vertical,.main-sidebar')||clipped(e))continue;
    over.push(e.tagName+'.'+String(e.className).slice(0,50)+' right='+Math.round(r.right));if(over.length>5)break}
  return {sw:d.scrollWidth,vw,over}})()`);
const noOverflow = async (label) => {
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await nav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
// every visible form control inside the new cards has an accessible name
const unlabeled = () => p.eval(`[...document.querySelectorAll('#mesh input, #mesh select, #mesh textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const msgIn = (card) => p.eval(`(()=>{const e=document.querySelector('#${card} [data-rmm-scale-msg]');return e?e.innerText.trim():''})()`);
const checked = (sel) => p.eval(`!!document.querySelector(${JSON.stringify(sel)}).checked`);
// a person clicks the label of a switch or box (the input itself is a few pixels)
const setChecked = async (sel, want) => {
  for (let i = 0; i < 3 && (await checked(sel)) !== want; i++) { await click(`label[for="${sel.slice(1)}"]`); await sleep(350); }
  assert((await checked(sel)) === want, 'could not set ' + sel);
};
const php = (args, extraEnv = {}) => execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p1213_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env, ...extraEnv }, encoding: 'utf8' });


// ---- the local TLS server the pin probe reads (a stand-in for a Mesh server's TLS front end; php tests/browser/rmm_p1213_extra.php tls)
const tlsProc = spawn('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p1213_extra.php', 'tls'], { env: process.env, stdio: ['ignore', 'pipe', 'ignore'] });
const tls = await new Promise((res, rej) => { let buf = ''; tlsProc.stdout.on('data', (d) => { buf += d; if (buf.includes('\n')) { try { res(JSON.parse(buf.split('\n')[0])); } catch (e) { rej(e); } } }); setTimeout(() => rej(new Error('TLS helper did not start')), 15000); });
const stopTls = () => { try { tlsProc.kill(); } catch {} };
process.on('exit', stopTls);
const GROUP = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';
// RivetCore 1.0.0-rc.15: MeshCentral's own spelling of the same id (64 characters of base64, '@' for '+', '$' for '/'); the field reads this back, not the hex
const urlOf = (raw) => raw.toString('base64').replace(/\+/g, '@').replace(/\//g, '$');
const GROUP_URL = urlOf(Buffer.from(GROUP, 'hex'));
const fresh = () => { for (;;) { const raw = randomBytes(48), u = urlOf(raw); if (u.includes('@') && u.includes('$')) return { raw, url: u, hex: raw.toString('hex') }; } };   // made at run time, never committed
const NODE_A = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';
const NODE_B = 'node//ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210@$ZyXwVuTsRqPoNmLkJiHgFeDcBa98';
const NODE_C = 'node//QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKk1234@$QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKk';
let fx = null;
const phpJson = (args) => JSON.parse(php(args).split('\n').find((l) => l.startsWith('{')));   // the test library prints its own summary line after ours

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ the dialog before Mesh is set up
await T('Add device dialog, Mesh not set up: the box is there, off, disabled, and says why', async () => {
  assert(phpJson(['binary']).ok === true, 'could not publish the test binary');
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await trapDialogs();
  assert(await p.exists('#rmm-inst-mesh'), 'the box is missing');
  assert(!(await checked('#rmm-inst-mesh')), 'the box is on by default');
  assert(await p.eval(`document.getElementById('rmm-inst-mesh').disabled`), 'the box should be disabled until Mesh is configured');
  const help = await p.eval(`document.getElementById('rmm-inst-mesh-help').innerText`);
  assert(/device group id/.test(help), 'help text should be Core\'s refusal sentence, got: ' + help);
  assert(await p.exists('#rmm-inst-mesh-help a[href="/admin/settings_endpoint_agent.php#mesh"]'), 'link to the Mesh settings missing');
  assert(await p.eval(`document.getElementById('rmm-inst-mesh').getAttribute('aria-describedby') === 'rmm-inst-mesh-help'`), 'help is not tied to the box');
  await shot('dialog-mesh-unavailable-desktop-light');
});

// ------------------------------------------------------------------------------------------------ the Mesh card
await T('Mesh card: install fields, labelled controls, the group id masked and revealable, verify warning', async () => {
  await go(ADMIN + '#mesh');
  await trapDialogs();
  for (const sel of ['#mc_group', '#mc_pin', '#mc_over', '#mc_ids', '#mc_args', 'button[form=mesh-pin-probe]']) assert(await p.exists(sel), 'missing ' + sel);
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'password', 'the group id is not masked');
  await setChecked('#mc_group_show', true);
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'text', 'the show box did not reveal the group id');
  await setChecked('#mc_group_show', false);
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'password', 'the show box did not mask it again');
  assert(/Not ready/.test(await p.eval(`document.querySelector('#mesh').innerText`)), 'the card should say the install option is not ready yet');
  const help = await p.eval(`document.getElementById('mc_group_h').innerText.replace(/\\s+/g,' ')`);
  assert(/Paste the download address from MeshCentral \(device group, Add Agent, Windows 64-bit installer\) or just its meshid\. It is 64 characters, not hex\. Treat it like an invitation key/.test(help), 'group id help: ' + help);
  const pinHelp = await p.eval(`document.getElementById('mc_pin_h').innerText.replace(/\\s+/g,' ')`);
  assert(/Leave empty when the server certificate is issued by a public CA such as Let's Encrypt/.test(pinHelp) && /Use a pin only for a private CA or a self-signed certificate/.test(pinHelp), 'pin help: ' + pinHelp);
  assert(!(await p.eval(`document.getElementById('mc_group').hasAttribute('pattern')`)), 'the group id field must not carry a hex-only pattern');
  await click('#mesh details > summary');
  await sleep(300);
  const t = await p.eval(`document.querySelector('#mesh details').innerText.replace(/\\s+/g,' ')`);
  assert(/was checked against a real MeshCentral/.test(t) && /were not: check them against your Mesh version/.test(t) && /Verify these against your own Mesh server/.test(t), 'verify warning missing: ' + t.slice(0, 300));
  assert(/windows\/amd64/.test(t), 'the built-in agent id table should be shown');
  await shotEl('#mesh', 'card-mesh-desktop-light');
});
await T('a bad pin is refused with Core\'s sentence in the card, role=alert, and what was typed stays', async () => {
  await go(ADMIN + '#mesh');
  await p.type('#mc_group', GROUP);
  await p.type('#mc_pin', 'not-a-hash');
  await nav('button[name=save_mesh_settings]');
  await p.waitSel('#mesh [data-rmm-scale-msg]');
  const m = await msgIn('mesh');
  assert(/The certificate pin must be the SHA-384/.test(m), 'message: ' + m);
  assert((await p.eval(`document.querySelector('#mesh [data-rmm-scale-msg]').getAttribute('role')`)) === 'alert', 'refusal is not role=alert');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === 'not-a-hash' && (await p.eval(`document.getElementById('mc_group').value`)) === GROUP, 'typed values were not kept');
  await shotEl('#mesh', 'card-mesh-refused-desktop-light');
});
await T('invalid JSON in the advanced box is refused before Core is asked; a bad id is refused by Core', async () => {
  await go(ADMIN + '#mesh');
  await click('#mesh details > summary');
  await p.type('#mc_ids', '{not json');
  await nav('button[name=save_mesh_settings]');
  await p.waitSel('#mesh [data-rmm-scale-msg]');
  assert(/must be a JSON object/.test(await msgIn('mesh')), 'message: ' + (await msgIn('mesh')));
  assert((await p.eval(`document.getElementById('mc_ids').value`)) === '{not json', 'text not kept');
  assert(await p.eval(`document.querySelector('#mesh details').open`), 'the advanced section should be open when it holds something');
  await p.type('#mc_ids', '{"windows/amd64": 999}');
  await nav('button[name=save_mesh_settings]');
  await p.waitSel('#mesh [data-rmm-scale-msg]');
  assert(/agent ids between 1 and 255/.test(await msgIn('mesh')), 'message: ' + (await msgIn('mesh')));
});

// ------------------------------------------------------------------------------------------------ the pin probe
await T('"Read it from the server" proposes the hash, saves NOTHING, and a button puts it in the field', async () => {
  await go(ADMIN + '#mesh');
  await p.type('#mc_url', tls.url);
  await nav('button[name=save_mesh_settings]');
  await go(ADMIN + '#mesh');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === '', 'the pin field should be empty');
  assert(!(await p.eval(`document.querySelector('button[form=mesh-pin-probe]').disabled`)), 'the probe button should be enabled once an address is saved');
  await nav('button[form=mesh-pin-probe]');
  await p.waitSel('[data-rmm-mesh-pin-result=ok]');
  const t = await p.eval(`document.querySelector('[data-rmm-mesh-pin-result=ok]').innerText.replace(/\\s+/g,' ')`);
  assert(t.includes(tls.sha384) && t.includes(tls.sha256) && /Nothing has been saved/.test(t) && /mesh\.test\.invalid/.test(t), 'proposal text: ' + t.slice(0, 400));
  // RivetCore 1.0.0-rc.15: Core's note says why (a self-signed certificate is not publicly trusted); the pin may be used, and it is not the "publicly trusted" panel
  assert(/not trusted by the system certificate store/.test(t) && /private CA or a self-signed certificate/.test(t), 'probe note missing: ' + t.slice(0, 500));
  assert(!(await p.exists('[data-rmm-mesh-pin-result=trusted]')) && await p.exists('[data-rmm-mesh-use-pin]'), 'a self-signed server must offer the pin, not the trusted panel');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === '', 'the proposal must not fill the field by itself');
  await shotEl('#mesh', 'card-mesh-proposal-desktop-light');
  // reload: nothing was saved, the proposal is gone
  await go(ADMIN + '#mesh');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === '' && !(await p.exists('[data-rmm-mesh-pin-result]')), 'the probe saved something, or the proposal was shown twice');
  // again, then use it with the keyboard and save
  await nav('button[form=mesh-pin-probe]');
  await p.waitSel('[data-rmm-mesh-use-pin]');
  await p.focus('[data-rmm-mesh-use-pin]');
  await p.press('Enter');
  await sleep(300);
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === tls.sha384, 'the button did not put the hash in the field');
  assert((await p.eval(`document.activeElement && document.activeElement.id`)) === 'mc_pin', 'focus did not move to the pin field');
  await p.type('#mc_group', GROUP);
  await nav('button[name=save_mesh_settings]');
  await go(ADMIN + '#mesh');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === tls.sha384 && (await p.eval(`document.getElementById('mc_group').value`)) === GROUP_URL, 'pin was not saved by Save, or the group id was not read back in the URL form');
  assert(/Installers can include the MeshCentral agent/.test(await p.eval(`document.querySelector('#mesh').innerText`)), 'the card should say installers can include the agent now');
});
await T('overwrite switch saves from the page', async () => {
  await go(ADMIN + '#mesh');
  assert(!(await checked('#mc_over')), 'the overwrite switch should be off by default');
  await setChecked('#mc_over', true);
  await nav('button[name=save_mesh_settings]');
  await go(ADMIN + '#mesh');
  assert(await checked('#mc_over'), 'the overwrite switch was not saved');
  await setChecked('#mc_over', false);
  await nav('button[name=save_mesh_settings]');
  await go(ADMIN + '#mesh');
  assert(!(await checked('#mc_over')), 'the overwrite switch was not saved off');
});

// ------------------------------------------------------------------------------------------------ RivetCore 1.0.0-rc.15: the device group id in MeshCentral's URL form
const saveGroup = async (v) => { await go(ADMIN + '#mesh'); await p.type('#mc_group', v); await nav('button[name=save_mesh_settings]'); await go(ADMIN + '#mesh'); };
const readGroup = () => p.eval(`document.getElementById('mc_group').value`);
const meshHost = new URL(tls.url).host;
await T('group id: the bare meshid, the whole pasted address, hex and mesh// are accepted and read back in the URL form', async () => {
  const A = fresh(), B = fresh();
  await saveGroup(A.url);
  assert((await readGroup()) === A.url, 'bare meshid not read back: ' + (await readGroup()));
  assert(await p.eval(`document.getElementById('mc_group').type === 'password'`), 'the group id must stay masked');
  assert((await msgIn('mesh')) === '', 'a clean save has no warning');
  await saveGroup(`https://${meshHost}/meshagents?id=4&meshid=${B.url}&installflags=0`);
  assert((await readGroup()) === B.url, 'whole address: only the meshid should be kept, got ' + (await readGroup()));
  assert((await msgIn('mesh')) === '', 'an address for the saved Mesh host should give no warning, got: ' + (await msgIn('mesh')));
  await saveGroup(A.hex.toUpperCase());
  assert((await readGroup()) === A.url, 'hex not read back as the URL form');
  await saveGroup('mesh//' + B.url);
  assert((await readGroup()) === B.url, 'mesh// form not accepted');
  await saveGroup(GROUP);
  assert((await readGroup()) === GROUP_URL, 'hex group not read back as the URL form');
});
await T('group id: an address for a different host is saved with a visible warning', async () => {
  const C = fresh();
  await go(ADMIN + '#mesh');
  await p.type('#mc_group', `https://other.example.test/meshagents?id=4&meshid=${C.url}&installflags=0`);
  await nav('button[name=save_mesh_settings]');
  await p.waitSel('#mesh [data-rmm-scale-msg]');
  const m = await msgIn('mesh');
  assert(/Saved, with a warning/.test(m) && /other\.example\.test/.test(m) && new RegExp(meshHost.split(':')[0].replace(/\./g, '\\.')).test(m), 'warning text: ' + m);
  assert((await p.eval(`document.querySelector('#mesh [data-rmm-scale-msg]').getAttribute('role')`)) === 'alert', 'the warning should be role=alert');
  assert((await readGroup()) === C.url, 'the id should still be saved');
  await shotEl('#mesh', 'card-mesh-warning-desktop-light');
  await go(ADMIN + '#mesh');
  assert((await msgIn('mesh')) === '', 'the warning is shown once');
});
await T('group id: hostile or malformed values are refused, nothing changes, the text is put back escaped', async () => {
  const D = fresh();
  await saveGroup(D.url);
  const bad = ['"' + D.url, D.url + ' ' + D.url, D.url + '&installflags=0', D.url + '#x', D.url.slice(0, 63), D.url.slice(0, 63) + '!', 'mesh//' + D.url.slice(1), 'javascript:alert(1)', 'https://u:p@mesh.example.test/meshagents?id=4&meshid=' + D.url, `https://${meshHost}/meshagents?id=999&meshid=${D.url}`, '"><script>window.__x=1</script>'];
  for (const v of bad) {
    await go(ADMIN + '#mesh');
    await p.type('#mc_group', v);
    await nav('button[name=save_mesh_settings]');
    await p.waitSel('#mesh [data-rmm-scale-msg]');
    const m = await msgIn('mesh');
    assert(/The Mesh device group id must be the meshid from the MeshCentral download address/.test(m), `refusal for ${JSON.stringify(v).slice(0, 60)}: ${m}`);
    assert((await p.eval('window.__x')) === undefined, 'a hostile value ran script');
    await go(ADMIN + '#mesh');
    assert((await readGroup()) === D.url, `${JSON.stringify(v).slice(0, 60)} changed the stored id`);
  }
  await shotEl('#mesh', 'card-mesh-group-after-refusals-desktop-light');
});
await T('group id: the installer payload carries the URL-form meshid verbatim (decoded from a real download)', async () => {
  const E = fresh();
  await saveGroup(`https://${meshHost}/meshagents?id=4&meshid=${E.url}&installflags=0`);
  assert((await readGroup()) === E.url, 'setup: id not saved');
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  const b64 = await p.eval(`(async()=>{const f=document.querySelector('#rmmInstallerModal form');const q=new URLSearchParams(new FormData(f));q.set('mode','download');q.set('os','windows');q.set('arch','amd64');q.set('client_id','1');q.set('install_mesh_agent','1');
    const r=await fetch(f.getAttribute('action'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body:q.toString()});
    if(!r.ok)return 'ERR'+r.status;const u=new Uint8Array(await r.arrayBuffer());let s='';for(let i=0;i<u.length;i+=32768)s+=String.fromCharCode.apply(null,u.subarray(i,i+32768));return btoa(s)})()`);
  assert(!/^ERR/.test(b64), 'installer download failed: ' + b64);
  const file = join(tmpdir(), 'p1213_inst_' + randomBytes(4).toString('hex') + '.exe');
  writeFileSync(file, Buffer.from(b64, 'base64'));
  const d = phpJson(['payload', file]);
  assert(d.ok && d.install_mesh_agent === true && d.mesh && d.mesh.meshid === E.url, 'payload meshid: ' + JSON.stringify(d.mesh) + ' (expected the URL form)');
  assert(d.mesh.meshid.length === 64 && !/^[0-9a-f]{64}$/.test(d.mesh.meshid) && d.mesh.meshid.includes('@') && d.mesh.meshid.includes('$'), 'the payload meshid must be the 64-character URL form with @ and $ literal');
  await saveGroup(GROUP);   // restore the hex group for the rest of the file
});

// ------------------------------------------------------------------------------------------------ the dialog once Mesh is set up
await T('Add device dialog, Mesh set up: the box is enabled, off, ticks with the keyboard; the Linux command carries the Mesh flags', async () => {
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await trapDialogs();
  assert(!(await p.eval(`document.getElementById('rmm-inst-mesh').disabled`)) && !(await checked('#rmm-inst-mesh')), 'the box should be enabled and unticked');
  assert(/downloads the MeshCentral agent from your own Mesh server/.test(await p.eval(`document.getElementById('rmm-inst-mesh-help').innerText`)), 'help text missing');
  await click('#rmm-inst-tab-linux');
  await p.eval(`document.getElementById('rmm-inst-client').value = '1'; document.getElementById('rmm-inst-client').dispatchEvent(new Event('change', {bubbles: true}))`);
  await p.focus('#rmm-inst-mesh');
  await p.press(' ');
  assert(await checked('#rmm-inst-mesh'), 'space did not tick the box');
  await click('#rmm-inst-go');
  await p.waitSel('#rmm-inst-cmd-text');
  const cmd = await p.eval(`document.getElementById('rmm-inst-cmd-text').textContent`);
  assert(/--install-mesh-agent/.test(cmd) && /--mesh-url/.test(cmd) && cmd.includes(GROUP_URL) && !cmd.includes(GROUP) && cmd.includes(tls.sha384), 'command: ' + cmd.slice(0, 300));
  assert(/also installs the MeshCentral agent/.test(await p.eval(`document.getElementById('rmm-inst-msg').innerText`)), 'the message should say the command also installs the Mesh agent');
  await shot('dialog-mesh-linux-desktop-light');
  // the box is unticked again the next time the dialog opens
  await p.eval(`bootstrap.Modal.getInstance(document.getElementById('rmmInstallerModal')).hide()`);
  await sleep(500);
  await p.eval(`bootstrap.Modal.getOrCreateInstance(document.getElementById('rmmInstallerModal')).show()`);
  await sleep(600);
  assert(!(await checked('#rmm-inst-mesh')), 'the box should be off again when the dialog reopens');
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
});
await T('Linux command without the tick has no Mesh flag', async () => {
  await go('/agent/rmm_fleet.php?add=1');
  await p.waitSel('#rmmInstallerModal.show');
  await click('#rmm-inst-tab-linux');
  await p.eval(`document.getElementById('rmm-inst-client').value = '1'; document.getElementById('rmm-inst-client').dispatchEvent(new Event('change', {bubbles: true}))`);
  await click('#rmm-inst-go');
  await p.waitSel('#rmm-inst-cmd-text');
  const cmd = await p.eval(`document.getElementById('rmm-inst-cmd-text').textContent`);
  assert(!/mesh/i.test(cmd) && !cmd.includes(GROUP) && !cmd.includes(GROUP_URL), 'command should not mention Mesh: ' + cmd.slice(0, 200));
});

// ------------------------------------------------------------------------------------------------ devices that report a Mesh agent
await T('devices: installed and mapped, failed, conflict, kept manual', async () => {
  fx = phpJson(['devices']);
  php(['report', 'M1', JSON.stringify({ installed: true, version: '1.1.60', node_id: NODE_A })]);
  php(['report', 'M2', JSON.stringify({ installed: true, version: '1.1.60', node_id: NODE_A })]);
  php(['report', 'M3', JSON.stringify({ installed: false, error: 'download failed: the Mesh server certificate does not match the pinned hash' })]);
  const asset = (n) => `/agent/asset_details.php?asset_id=${fx.asset[n]}`;
  await go(asset('M1'));
  let t = await p.eval(`document.querySelector('#rmm-remote').innerText.replace(/\\s+/g,' ')`);
  assert(/MeshCentral agent installed/.test(t) && /version 1\.1\.60/.test(t) && /mapped to this device/.test(t), 'M1: ' + t.slice(0, 400));
  assert(await p.exists('#rmm-remote [data-rmm-mesh-map=mapped]') && !(await p.exists('#rmm-remote [data-rmm-mesh-hint]')), 'M1: should be mapped without a hint');
  assert((await p.eval(`document.getElementById('rmm-mesh-node').value`)) === NODE_A, 'M1: the mapping form should hold the automatically found node');
  await shotEl('#rmm-remote', 'device-mesh-mapped-desktop-light');
  await go(asset('M2'));
  assert(await p.exists('#rmm-remote [data-rmm-mesh-hint=conflict]'), 'M2: the conflict hint is missing');
  t = await p.eval(`document.querySelector('#rmm-remote [data-rmm-mesh-hint=conflict]').innerText.replace(/\\s+/g,' ')`);
  assert(/Not mapped/.test(t) && /cloned from an image/.test(t), 'M2 hint: ' + t);
  assert(await p.exists(`#rmm-remote a[href="/agent/rmm_agent_device.php?device_id=${fx.dev.M1}"]`), 'M2: the hint should link the other device of the same client');
  assert((await p.eval(`document.querySelector('#rmm-remote [data-rmm-mesh-hint=conflict]').getAttribute('role')`)) === 'alert', 'M2: the conflict hint should be role=alert');
  await shotEl('#rmm-remote', 'device-mesh-conflict-desktop-light');
  await go(asset('M3'));
  t = await p.eval(`document.querySelector('#rmm-remote').innerText.replace(/\\s+/g,' ')`);
  assert(/MeshCentral agent not installed/.test(t) && /does not match the pinned hash/.test(t) && /The RivetMSP agent itself is fine/.test(t), 'M3: ' + t.slice(0, 400));
  await shotEl('#rmm-remote', 'device-mesh-failed-desktop-light');
  // M4: mapped by hand through the existing form, then the agent reports a different node
  await go(asset('M4'));
  await p.type('#rmm-mesh-node', NODE_B);
  await click('#rmm-mesh-form button[type=submit]');
  await sleep(1500);
  php(['report', 'M4', JSON.stringify({ installed: true, version: '1.1.60', node_id: NODE_C })]);
  await go(asset('M4'));
  assert(await p.exists('#rmm-remote [data-rmm-mesh-hint=kept_manual]'), 'M4: the kept-manual hint is missing (the mapping form may not have saved)');
  t = await p.eval(`document.querySelector('#rmm-remote [data-rmm-mesh-hint=kept_manual]').innerText.replace(/\\s+/g,' ')`);
  assert(/Your mapping was kept/.test(t) && /Administration/.test(t), 'M4 hint: ' + t);
  assert(await p.exists('#rmm-remote [data-rmm-mesh-hint=kept_manual] a[href="/admin/settings_endpoint_agent.php#mesh"]'), 'M4: link to the Mesh settings missing');
  await shotEl('#rmm-remote', 'device-mesh-kept-manual-desktop-light');
  // M5 never reported
  await go(asset('M5'));
  assert(!(await p.exists('#rmm-remote [data-rmm-mesh-agent]')), 'M5: no Mesh agent block for a device that never reported one');
});
await T('the Remote tab shows the same state next to its buttons', async () => {
  // turn the remote tools switch on through the page's own card and look at the tab of the conflicting device
  await go(ADMIN + '#remote-tools');
  await setChecked('#rt_on', true);
  await nav('button[name=save_remote_settings]');
  await go(`/agent/asset_details.php?asset_id=${fx.asset.M2}#rmm-remote`);
  assert(await p.exists('#rem-mesh [data-rmm-mesh-hint=conflict]') || await p.exists('#rmm-remote [data-rmm-mesh-hint=conflict]'), 'the conflict hint is not on the Remote tab');
});

// ------------------------------------------------------------------------------------------------ dark mode and phone width
const devPages = () => [['mesh-card', ADMIN + '#mesh'], ['device-conflict', `/agent/asset_details.php?asset_id=${fx.asset.M2}#rmm-remote`], ['device-kept', `/agent/asset_details.php?asset_id=${fx.asset.M4}#rmm-remote`]];
await T('dark mode: the Mesh card and the device state are dark and readable (screenshots in shots/)', async () => {
  await setTheme(1);
  for (const [name, path] of devPages()) {
    await p.setViewport(1366, 900);
    await go(path); await sleep(700);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
  }
  await go(ADMIN + '#mesh');
  await shotEl('#mesh', 'card-mesh-desktop-dark');
});
await T('phone width, dark: nothing overflows the page', async () => {
  await p.setViewport(390, 800, true);
  for (const [name, path] of devPages()) {
    await go(path); await sleep(700);
    await noOverflow(`${name} dark phone`);
    await shot(`${name}-phone-dark`);
  }
  await go(ADMIN + '#mesh');
  await shotEl('#mesh', 'card-mesh-phone-dark');
});
await T('phone width, light: nothing overflows the page; controls still labelled', async () => {
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(390, 800, true);
  for (const [name, path] of devPages()) {
    await go(path); await sleep(700);
    await noOverflow(`${name} light phone`);
    await shot(`${name}-phone-light`);
  }
  await go(ADMIN + '#mesh');
  assert((await unlabeled()).length === 0, 'unlabeled controls on the phone layout: ' + (await unlabeled()).join(','));
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt and no inline handlers in the Mesh card', async () => {
  await go(ADMIN + '#mesh');
  await trapDialogs();
  await p.eval(`document.querySelectorAll('#mesh [data-rmm-mesh-show]').forEach(b=>b.click())`);
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
  assert(await p.eval(`document.querySelectorAll('#mesh [onclick],#mesh [onchange],#mesh [onsubmit],#mesh [oninput]').length === 0`), 'inline event handlers in the Mesh card');
});

const fails = H.summary();
await browser.close();
stopTls();
process.exit(fails ? 1 : 0);
