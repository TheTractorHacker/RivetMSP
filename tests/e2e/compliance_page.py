"""
End-to-end check of Administration > Compliance through the real web stack: sign in, load the page, submit the form, then
verify the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_page.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
sql("update settings set config_compliance_profile='none', config_audit_retention_days=365, config_log_retention=90, config_core_audit_enabled=0; delete from audit_events")
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))

# sign in
s, html, h = req('/login.php')
data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
s, html, h = req('/login.php', data)
check('sign in as the admin', s in (302, 303), (s, h.get('Location')))
def state(): return sql("select config_compliance_profile, config_audit_retention_days, config_log_retention, config_core_audit_enabled from settings").split('\t')
def save(profile, audit_days, log_days, recording, token=None):
    tok = token or csrf(req('/admin/settings_compliance.php')[1])
    d = {'csrf_token': tok, 'compliance_profile': profile, 'audit_retention_days': str(audit_days), 'log_retention_days': str(log_days), 'save_compliance_settings': '1'}
    if recording: d['audit_recording'] = '1'
    return req('/admin/post.php', d, referer='/admin/settings_compliance.php')
s, page, h = req('/admin/settings_compliance.php')
check('compliance page loads (200)', s == 200, s)
check('page shows the heading, presets, disclaimer and the audit switch', all(x in page for x in ['Compliance', 'ISO/IEC 27001', 'HIPAA', 'does not make an organization compliant', 'Record the audit trail']))
check('RivetMSP wording, not RivetIT', 'RivetMSP keeps' in page and 'RivetIT' not in page)
check('page defaults: no preset, audit switch off, 365 days', state() == ['none', '365', '90', '0'] and 'id="audit_recording" name="audit_recording" value="1" >' in page.replace('\n', ' ').replace('  ', ' ') or 'checked' not in page.split('audit_recording')[1][:120], state())
check('page is in the Settings sidebar', '/admin/settings_compliance.php' in page)
# 1. a preset forces the audit switch on and raises short retentions
save('soc2', 100, 10, False)
check('SOC 2 raises 100 and 10 to 365 and forces audit recording ON even though the box was unticked', state() == ['soc2', '365', '365', '1'], state())
ev = sql("select event_type, actor_user_id from audit_events where event_type='compliance.settings_changed' order by audit_id desc limit 1")
uid = sql("select user_id from users where user_email='" + EMAIL + "'")
check('the change is audited with the actor (audit switch was off before, on now)', ev == 'compliance.settings_changed\t' + uid, ev)
md = json.loads(sql("select metadata_json from audit_events where event_type='compliance.settings_changed' order by audit_id desc limit 1") or '{}')
check('the audit event shows audit_recording going 0 -> 1', md.get('before', {}).get('audit_recording') == 0 and md.get('after', {}).get('audit_recording') == 1, md)
s, page2, h = req('/admin/settings_compliance.php')
check('the page now shows the raised notice and recording on', 'raised' in page2 and 'currently <strong>on</strong>' in page2)
# 2. an unticked box under a preset cannot turn it off
save('iso27001', 400, 400, False)
check('under a preset the audit switch cannot be left off', state()[3] == '1', state())
# 3. back to no preset: turn auditing OFF -> the change that turns it off is itself recorded
n_before = sql("select count(*) from audit_events where event_type='compliance.settings_changed'")
save('none', 200, 50, False)
check('with no preset the audit switch can be turned off', state() == ['none', '200', '50', '0'], state())
check('the change that TURNED AUDITING OFF is itself recorded', int(sql("select count(*) from audit_events where event_type='compliance.settings_changed'")) == int(n_before) + 1)
last = json.loads(sql("select metadata_json from audit_events where event_type='compliance.settings_changed' order by audit_id desc limit 1") or '{}')
check('and says audit went 1 -> 0', last.get('before', {}).get('audit_recording') == 1 and last.get('after', {}).get('audit_recording') == 0, last)
# 4. while off, a further change is not audited (nothing to record into)
n = sql("select count(*) from audit_events")
save('none', 210, 50, False)
check('while audit is off, further changes are saved but not audited', state()[1] == '210' and sql("select count(*) from audit_events") == n)
# 5. turning it on with no preset
save('none', 210, 50, True)
check('with no preset the audit switch can be turned on', state()[3] == '1', state())
# 6. refusals and limits (same rules as RivetIT)
before = state()
save("x'; DROP TABLE settings;--", 1, 1, True)
check('an invalid preset is refused and nothing changes', state() == before and sql("select count(*) from settings") == '1', state())
req('/admin/post.php', {'csrf_token': 'wrong', 'compliance_profile': 'none', 'audit_retention_days': '5', 'log_retention_days': '5', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
check('a wrong CSRF token changes nothing', state() == before, state())
save('none', 999999999, -5, True)
check('huge values are capped and a negative becomes 0 (keep forever)', state()[1:3] == ['36500', '0'], state())
# 7. Security page honours a preset floor
save('soc2', 400, 400, True)
s, sec, h = req('/admin/settings_security.php')
fields = dict(re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', sec)); fields = {k: v for k, v in fields.items() if k != 'csrf_token'}
fields.update({'csrf_token': csrf(sec), 'config_log_retention': '7', 'edit_security_settings': '1'})
req('/admin/post.php', fields, referer='/admin/settings_security.php')
check('the Security page raises a too-short log retention to the preset floor (365)', state()[2] == '365', state())
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
