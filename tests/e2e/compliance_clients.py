"""
End-to-end check of Administration > Per-customer compliance (client Compliance tab, sharing with the client's portal users) through the real web stack: sign in, load the page, record reviews,
save a snapshot, export CSV/HTML, verify escaping, the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_clients.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Sess:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None):
        headers = {}
        if referer: headers['Referer'] = BASE + referer
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            resp = self.opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    if out.returncode: print('SQL ERROR:', out.stderr.strip()[-300:])
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
import subprocess as sp
PW = 'Portal-Pass-12345'
hashed = sp.run(['php', '-r', 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);'], capture_output=True, text=True, env=dict(os.environ, P=PW)).stdout
sql("delete from compliance_subjects; delete from compliance_snapshots; delete from compliance_attestations; delete from compliance_shared_report; delete from audit_events where event_type like 'compliance.%'; update settings set config_client_portal_enable=1, config_core_audit_enabled=1")
sql("delete from users where user_email like '%@clientco.test'; delete from contacts where contact_email like '%@clientco.test'")
sql("set session sql_mode=''; delete from clients where client_name in ('Alpha <i>Co</i>', 'Beta Co'); insert into clients (client_name, client_currency_code) values ('Alpha <i>Co</i>', 'USD'), ('Beta Co', 'USD')")
A = sql("select client_id from clients where client_name='Alpha <i>Co</i>'"); B = sql("select client_id from clients where client_name='Beta Co'")
for tag, cid in (('a', A), ('b', B)):
    sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Portal %s', '%s@clientco.test', '%s', 2, 1, 0)" % (tag, tag, hashed))
    uid = sql("select user_id from users where user_email='%s@clientco.test'" % tag)
    sql("insert into contacts (contact_name, contact_email, contact_user_id, contact_client_id, contact_primary) values ('Portal %s', '%s@clientco.test', %s, %s, 1)" % (tag, tag, uid, cid))

admin = Sess(); pa = Sess(); pb = Sess()
def login(s, email, pw):
    st, html, h = s.req('/login.php'); data = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: data['csrf_token'] = t
    return s.req('/login.php', data)
check('admin signs in', login(admin, EMAIL, PASSWORD)[0] in (302, 303))
check('client A portal user signs in', login(pa, 'a@clientco.test', PW)[0] in (302, 303))
check('client B portal user signs in', login(pb, 'b@clientco.test', PW)[0] in (302, 303))

def post(data, cid=A, referer=None):
    page = admin.req('/agent/client_compliance.php?client_id=%s' % cid)[1]
    d = {'csrf_token': csrf(page), 'client_id': cid}; d.update(data)
    return admin.req('/agent/post.php', d, referer=referer or '/agent/client_compliance.php?client_id=%s' % cid)

st, page, h = admin.req('/agent/client_compliance.php?client_id=' + A)
check('client Compliance tab loads', st == 200 and 'Standards that apply' in page, st)
check('no standards yet: checklist is hidden', 'Choose at least one standard' in page and 'Record review' not in page)
check('client name is escaped in the tab', 'Alpha &lt;i&gt;Co&lt;/i&gt;' in page and 'Alpha <i>Co</i>' not in page)
check('tab link is in the client sidebar', '/agent/client_compliance.php?client_id=' + A in page)
check('overview is in the main navigation', 'compliance_clients.php' in admin.req('/agent/clients.php')[1])

post({'frameworks[]': ['hipaa', 'pci', 'bogus'], 'set_client_compliance_frameworks': '1'})
check('standards are stored (unknown ones dropped)', sql("select frameworks from compliance_subjects where subject_id=" + A) == 'pci,hipaa', sql("select frameworks from compliance_subjects where subject_id=" + A))
page = admin.req('/agent/client_compliance.php?client_id=' + A)[1]
check('checklist shows HIPAA and PCI items, not ISO-only items', 'Business associate agreements' in page and 'Cardholder-data scope' in page and 'statement of applicability' not in page)
check('client B is unaffected', sql("select count(*) from compliance_subjects where subject_id=" + B) == '0')

post({'item_id': 'baa_in_place', 'reviewer_name': 'Hidden <b>Tech</b>', 'reviewed_on': '2025-01-15', 'next_due_on': '', 'note': 'PRIVATE-EVIDENCE', 'record_client_compliance_review': '1'})
check('a review is stored against client A only', sql("select count(*) from compliance_attestations where subject_id=" + A) == '1' and sql("select count(*) from compliance_attestations where subject_id=" + B) == '0' and sql("select count(*) from compliance_attestations where subject_id=0") == '0')
page = admin.req('/agent/client_compliance.php?client_id=' + A)[1]
check('review shown, escaped', 'Hidden &lt;b&gt;Tech&lt;/b&gt;' in page and '<b>Tech</b>' not in page)
n0 = sql("select count(*) from compliance_attestations")
for bad in [{'item_id': 'security_policy_review'}, {'item_id': 'nope'}, {'item_id': 'baa_in_place', 'reviewed_on': '2099-01-01'}, {'item_id': 'baa_in_place', 'reviewer_name': ''}]:
    d = {'reviewer_name': 'x', 'reviewed_on': '2025-01-15', 'next_due_on': '', 'note': '', 'record_client_compliance_review': '1'}; d.update(bad); post(d)
check('installation-only item, unknown item, future date and blank reviewer are refused', sql("select count(*) from compliance_attestations") == n0)
admin.req('/agent/post.php', {'csrf_token': 'wrong', 'client_id': A, 'item_id': 'baa_in_place', 'reviewer_name': 'x', 'reviewed_on': '2025-01-15', 'record_client_compliance_review': '1'}, referer='/agent/client_compliance.php?client_id=' + A)
check('a wrong CSRF token stores nothing', sql("select count(*) from compliance_attestations") == n0)
post({'item_id': 'baa_in_place', 'reviewer_name': 'x', 'reviewed_on': '2025-01-15', 'record_client_compliance_review': '1'}, cid='999999')
check('an unknown client id stores nothing', sql("select count(*) from compliance_attestations") == n0)

post({'take_client_compliance_snapshot': '1'})
sid = sql("select max(snapshot_id) from compliance_snapshots where subject_id=" + A)
check('snapshot stored against client A', sid != '' and sql("select count(*) from compliance_snapshots where subject_id=0") == '0')
post({'frameworks[]': ['soc2'], 'set_client_compliance_frameworks': '1'}, cid=B); post({'take_client_compliance_snapshot': '1'}, cid=B)
sidb = sql("select max(snapshot_id) from compliance_snapshots where subject_id=" + B)
post({'snapshot_id': sidb, 'note': 'x', 'share_client_compliance': '1'}, cid=A)
check("one client's snapshot cannot be shared with another client", sql("select count(*) from compliance_subjects where subject_id=%s and shared_snapshot_id is not null" % A) == '0')

st, b, h = pa.req('/client/compliance.php')
check('client A sees nothing while nothing is shared', st in (302, 303) and 'Your compliance' not in b)
post({'snapshot_id': sid, 'note': 'Hello <b>team</b>', 'share_client_compliance': '1'})
check('sharing records the snapshot', sql("select shared_snapshot_id from compliance_subjects where subject_id=" + A) == sid)
check('sharing is audited', sql("select count(*) from audit_events where event_type='compliance.client_report_shared'") == '1')
st, b, h = pa.req('/client/compliance.php')
check("client A's portal user sees its own report", st == 200 and 'Your compliance' in b and 'Business associate agreements' in b, st)
check('the note is escaped', 'Hello &lt;b&gt;team&lt;/b&gt;' in b and 'Hello <b>team' not in b)
check('framework tiles show only the selected standards', 'HIPAA' in b and 'PCI DSS' in b and 'ISO/IEC 27001' not in b)
for leak in ['Hidden', 'PRIVATE-EVIDENCE', 'Alpha']:
    check('portal page does not leak: ' + leak, leak not in b)
check("client A's menu has the Security link", '/client/compliance.php' in pa.req('/client/index.php')[1])
st, b, h = pb.req('/client/compliance.php')
check("client B's portal user does NOT see client A's report", st in (302, 303) and 'Business associate' not in b and 'Your compliance' not in b, st)
check("client B's menu has no Security link", '/client/compliance.php' not in pb.req('/client/index.php')[1])

# overview + exports
page = admin.req('/agent/compliance_clients.php')[1]
check('overview lists clients with standards chosen and shows shared', 'Alpha &lt;i&gt;Co&lt;/i&gt;' in page and 'Beta Co' in page and 'Shared' in page)
st, csvb, h = admin.req('/agent/client_compliance_report.php?client_id=%s&format=csv' % A)
check('client CSV export: 200, attachment, nosniff, has items', st == 200 and 'attachment' in h.get('Content-Disposition', '') and h.get('X-Content-Type-Options') == 'nosniff' and 'Business associate' in csvb, st)
st, htmlb, h = admin.req('/agent/client_compliance_report.php?client_id=%s&format=html&snapshot=%s' % (A, sid))
check('client HTML export: escaped name, CSP, disclaimer', st == 200 and 'Alpha &lt;i&gt;Co&lt;/i&gt;' in htmlb and "default-src 'none'" in h.get('Content-Security-Policy', '') and 'not a certification' in htmlb, st)
check("another client's snapshot id cannot be exported under this client", admin.req('/agent/client_compliance_report.php?client_id=%s&format=csv&snapshot=%s' % (A, sidb))[0] == 404)
check('unknown client export is a 404', admin.req('/agent/client_compliance_report.php?client_id=999999&format=csv')[0] == 404)
check('exports are audited', int(sql("select count(*) from audit_events where event_type='compliance.client_report_exported'")) >= 2)
check('anonymous visitors cannot export', Sess().req('/agent/client_compliance_report.php?client_id=%s&format=csv' % A)[0] in (302, 303, 401, 403))
check('a portal user cannot reach the agent tab or export', pa.req('/agent/client_compliance.php?client_id=' + A)[0] in (302, 303, 401, 403) and pa.req('/agent/client_compliance_report.php?client_id=%s&format=csv' % A)[0] in (302, 303, 401, 403))

pa = Sess(); login(pa, 'a@clientco.test', PW)
post({'unshare_client_compliance': '1'})
st, b, h = pa.req('/client/compliance.php')
check('unsharing hides it from the client again', st in (302, 303) and 'Your compliance' not in b and sql("select count(*) from compliance_snapshots where subject_id=" + A) == '1')
check('unsharing is audited', sql("select count(*) from audit_events where event_type='compliance.client_report_unshared'") == '1')
check('the installation compliance page is unaffected by customer data', sql("select count(*) from compliance_attestations where subject_id=0") == '0')
post({'frameworks[]': ['nist171'], 'set_client_compliance_frameworks': '1'}, cid=B)
page = admin.req('/agent/client_compliance.php?client_id=' + B)[1]
check('NIST 800-171 / CMMC can be chosen and lists its own items with requirement numbers', 'NIST SP 800-171 Rev 2' in page and 'CUI / FCI scope' in page and '3.5.3' in page and 'Business associate' not in page)
check('the admin compliance page shows the NIST tile', 'NIST SP 800-171' in admin.req('/admin/compliance_status.php')[1])
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
