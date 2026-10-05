"""
End-to-end check of the Cron Manager (Core JobRunner, Redis lock state, audit events) and the KB Word/PDF import +
credential references, through the real web stack.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`RIVETMSP_REDIS_PORT=<redis port> php -S 127.0.0.1:<port> -t <app dir>` (and a throwaway redis on that port), and give this
script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... TEST_REDIS_PORT=6394 python3 tests/e2e/cron_kb.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, subprocess, os, io, time, zipfile, uuid, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
RPORT = os.environ['TEST_REDIS_PORT']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None, raw=None, ctype=None):
        headers = {}
        if referer: headers['Referer'] = BASE + referer
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else raw
        if ctype: headers['Content-Type'] = ctype
        r = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            resp = self.opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
    def login(self, email, password):
        s, html, h = self.req('/login.php'); t = csrf(html)
        d = {'email': email, 'password': password, 'login': ''}
        if t: d['csrf_token'] = t
        return self.req('/login.php', d)
    def upload(self, path, fields, fname, content, ctype, referer, field='docx_file'):
        b = uuid.uuid4().hex; parts = []
        for k, v in fields.items():
            parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, field, fname, ctype)).encode() + content + b'\r\n')
        parts.append(('--%s--\r\n' % b).encode())
        return self.req(path, raw=b''.join(parts), referer=referer, ctype='multipart/form-data; boundary=' + b)

def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
def rcli(*a):
    return subprocess.run(['redis-cli', '-p', RPORT] + list(a), capture_output=True, text=True).stdout.strip()
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
def wait_for(cond, secs=12):
    end = time.time() + secs
    while time.time() < end:
        if cond(): return True
        time.sleep(0.3)
    return False

# ---- test files, hand-made so no extra tooling is needed
def make_docx(extra=b''):
    ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'
    rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
    doc = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
           '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>VPN Runbook Heading</w:t></w:r></w:p>'
           '<w:p><w:r><w:t>Connect with the corporate client CANARY-DOCX-BODY.</w:t></w:r></w:p>'
           '<w:p><w:r><w:t>Admin password: [[credential:7]]</w:t></w:r></w:p>'
           '<w:sectPr/></w:body></w:document>')
    bio = io.BytesIO()
    with zipfile.ZipFile(bio, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', ct); z.writestr('_rels/.rels', rels); z.writestr('word/document.xml', doc)
    return bio.getvalue()
def make_pdf(text):
    objs = []
    stream = ('BT /F2 24 Tf 72 700 Td (PDF Import Heading) Tj ET\nBT /F1 12 Tf 72 660 Td (%s) Tj ET\nBT /F1 12 Tf 72 640 Td (Second paragraph of the scratch pdf.) Tj ET' % text).encode()
    objs.append(b'<< /Type /Catalog /Pages 2 0 R >>')
    objs.append(b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
    objs.append(b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>')
    objs.append(b'<< /Length %d >>\nstream\n' % len(stream) + stream + b'\nendstream')
    objs.append(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>')
    objs.append(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>')
    out = b'%PDF-1.4\n'; offs = []
    for i, o in enumerate(objs, 1):
        offs.append(len(out)); out += b'%d 0 obj\n' % i + o + b'\nendobj\n'
    x = len(out); out += b'xref\n0 %d\n0000000000 65535 f \n' % (len(objs) + 1)
    for o in offs: out += b'%010d 00000 n \n' % o
    out += b'trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objs) + 1, x)
    return out

admin = Session()
s, html, h = admin.login(EMAIL, PASSWORD)
check('admin signs in', s in (302, 303), s)

# a non-admin technician (role 2) for the denial checks
pwhash = subprocess.run(['php', '-r', "echo password_hash('Tech-User-1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("insert into users set user_name='Scratch Tech', user_email='tech@scratch.test', user_password='%s', user_specific_encryption_ciphertext='x', user_role_id=2" % pwhash.replace("'", "''"))
sql("update user_role_permissions set user_role_permission_level=1 where user_role_id=2 and module_id=(select module_id from modules where module_name='module_kb')")
tech = Session(); tech.login('tech@scratch.test', 'Tech-User-1234')

rcli('flushall')

# =============================== CRON MANAGER ===============================
s, html, h = admin.req('/admin/cron.php')
check('cron page loads', s == 200 and 'Cron Manager' in html, s)
cron_files = sorted(f for f in os.listdir(APP + '/cron') if f.endswith('.php'))
for f in ['integration_worker.php', 'domain_refresher.php', 'certificate_refresher.php', 'metrics_rollup.php', 'mail_queue.php', 'report_scheduler.php']:
    check('page lists real job %s' % f, f in html, f)
check('page lists the scripts/ job unifi_sync_cli.php', 'unifi_sync_cli.php' in html)
check('page does not list scripts that do not exist (backup_cron.php)', 'backup_cron.php' not in html)
check('send-mail jobs show a reason, not a Run now button', 'Sends real email.' in html)

def run_job(script, sess=admin, token=None):
    s, html, h = admin.req('/admin/cron.php'); t = token or csrf(html)
    return sess.req('/admin/post.php', {'csrf_token': t, 'run_cron_job': '1', 'cron_script': script}, referer='/admin/cron.php')

sql("delete from logs where log_type='Cron'")
s, body, h = run_job('integration_worker.php')
check('run-now of integration_worker.php is accepted (redirect)', s in (302, 303), s)
def job_done():
    s, html, h = admin.req('/admin/cron.php')
    return 'exit 0' in html or 'Latest output (exit' in html
check('run-now finished and the page shows its exit status', wait_for(job_done))
s, html, h = admin.req('/admin/cron.php')
check('last-run output is shown on the page', 'Latest output' in html)
check('a Cron log entry was written', int(sql("select count(*) from logs where log_type='Cron' and log_action='Run'")) >= 1)
check('an audit event cron.job_started was recorded', int(sql("select count(*) from audit_events where event_type='cron.job_started' and entity_id='integration_worker.php'") or 0) >= 1)

# a held lock: shown, and run refused
rcli('set', 'rivetmsp:lock:cron:certificate_refresher', 'held-by-test', 'EX', '300')
s, html, h = admin.req('/admin/cron.php')
check('a held lock is shown on the page', 'Lock held' in html and 'certificate_refresher' in html)
n_before = int(sql("select count(*) from audit_events where event_type='cron.job_refused'") or 0)
s, body, h = run_job('certificate_refresher.php')
s2, html2, h2 = admin.req('/admin/cron.php')
check('run is refused while the lock is held', 'cron lock is held' in html2 or 'already running' in html2, html2[:0])
state = '/tmp/rivetmsp-jobs-*'
check('the refused job was not started (no state log)', not any('u-certificate_refresher' in f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d)))
check('the refusal is audited', int(sql("select count(*) from audit_events where event_type='cron.job_refused'") or 0) > n_before)
rcli('del', 'rivetmsp:lock:cron:certificate_refresher')
s, body, h = run_job('certificate_refresher.php')
check('after the lock is released the same job runs', wait_for(lambda: any('u-certificate_refresher.log' in f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d))))

# safety: jobs that must not start from the UI, unknown script names, path tricks
for bad in ['mail_queue.php', 'report_scheduler.php', 'cron.php', '../config.php', 'nonexistent.php', '../../etc/passwd']:
    before = set(f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d))
    run_job(bad)
    after = set(f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d))
    check('refused to start %s from the UI' % bad, before == after, after - before)

# CSRF and permission
s, html, h = admin.req('/admin/cron.php'); good = csrf(html)
s, body, h = admin.req('/admin/post.php', {'csrf_token': 'bogus', 'run_cron_job': '1', 'cron_script': 'domain_refresher.php'}, referer='/admin/cron.php')
check('bad CSRF token is rejected (redirected away, job not started)', s == 302 and 'index.php' in (h.get('Location') or '') and not any('u-domain_refresher.log' in f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d)), (s, h.get('Location')))
s, body, h = tech.req('/admin/cron.php')
check('non-admin cannot open the cron page', 'Cron Manager' not in body, s)
tt = good
s, body, h = tech.req('/admin/post.php', {'csrf_token': tt, 'run_cron_job': '1', 'cron_script': 'domain_refresher.php'}, referer='/admin/cron.php')
check('non-admin cannot run a job through the post handler', not any('u-domain_refresher.log' in f for d in __import__('glob').glob('/tmp/rivetmsp-jobs-*') for f in os.listdir(d)), s)

# side nav entry
s, html, h = admin.req('/admin/maintenance.php')
check('the Maintenance area links to Scheduled jobs (cron.php)', 'cron.php' in html)

# =============================== KB IMPORT ===============================
KBREF = '/agent/kb_articles.php'
s, html, h = admin.req('/agent/kb_articles.php')
check('KB list page has the Word and PDF import buttons', 'kb_article_import_docx.php' in html and 'kb_article_import_pdf.php' in html, s)
t = csrf(html)
import json
def modal(path):
    s, body, h = admin.req(path)
    try: return s, json.loads(body).get('content', ''), h
    except Exception: return s, body, h
s, mhtml, h = modal('/agent/modals/kb_article/kb_article_import_docx.php')
check('Word import modal renders with accept=.docx and a size limit', s == 200 and 'accept=".docx"' in mhtml and 'MAX_FILE_SIZE' in mhtml, s)
s, mhtml, h = modal('/agent/modals/kb_article/kb_article_import_pdf.php')
check('PDF import modal renders', s == 200 and 'pdf_file' in mhtml, s)
t = csrf(mhtml) or t

fields = {'csrf_token': t, 'title': '', 'client_id': '0', 'category_id': '0', 'client_visible': '1', 'import_kb_article_docx': '1'}
def last_article():
    return sql("select kb_article_id from kb_articles order by kb_article_id desc limit 1")
n0 = int(sql("select count(*) from kb_articles"))
sql("delete from logs where log_description like '%imported KB article%'")
s, body, h = admin.upload('/agent/post.php', fields, 'vpn_onboarding_runbook.docx', make_docx(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', KBREF)
check('docx import redirects', s in (302, 303), (s, body[:200]))
check('docx import created exactly one article', int(sql("select count(*) from kb_articles")) == n0 + 1)
aid = last_article()
content = sql("select kb_article_content from kb_articles where kb_article_id=%s" % aid)
check('title derived from filename', sql("select kb_article_title from kb_articles where kb_article_id=%s" % aid) == 'Vpn Onboarding Runbook')
check('converted content has the heading and body', 'VPN Runbook Heading' in content and 'CANARY-DOCX-BODY' in content and '<h1' in content, content[:200])
check('search index text populated', 'CANARY-DOCX-BODY' in sql("select kb_article_content_raw from kb_articles where kb_article_id=%s" % aid))
check('import logged in the activity log', int(sql("select count(*) from logs where log_description like '%imported KB article from a Word%'")) >= 1)
check('source .docx not left in uploads', not any(f.endswith('.docx') for r, d, fs in os.walk(APP + '/uploads') for f in fs))

# credential reference rendering
s, html, h = admin.req('/agent/kb_article.php?id=%s' % aid)
check('article page renders the converted content', s == 200 and 'CANARY-DOCX-BODY' in html, s)
check('credential token becomes a Reveal button (no raw token, no secret)', 'Reveal linked credential' in html and 'credential_view.php?id=7' in html and '[[credential:7]]' not in html)
check('stored content keeps the raw token', '[[credential:7]]' in content)
# portal drops it: simulated by the same regex path is covered by code review; verify the client page file contains the strip
check('portal article page strips credential tokens (source check)', "credential:\\d+" in open(APP + '/client/kb_article.php').read())

# bad types / junk content / missing file
n1 = int(sql("select count(*) from kb_articles"))
s, body, h = admin.upload('/agent/post.php', fields, 'evil.php', b'<?php echo 1;', 'application/x-php', KBREF)
s2, h2, _ = admin.req('/agent/kb_articles.php')
check('wrong extension (.php as docx_file) rejected, no article', int(sql("select count(*) from kb_articles")) == n1 and 'Only .docx files can be imported' in h2, h2[:0])
s, body, h = admin.upload('/agent/post.php', fields, 'fake.docx', b'this is not a zip at all', 'application/octet-stream', KBREF)
s2, h2, _ = admin.req('/agent/kb_articles.php')
check('a .docx name with non-docx bytes is rejected, no article', int(sql("select count(*) from kb_articles")) == n1 and 'Import failed' in h2, h2[:0])
s, body, h = admin.upload('/agent/post.php', fields, 'x.pdf', make_pdf('x'), 'application/pdf', KBREF)
s2, h2, _ = admin.req('/agent/kb_articles.php')
check('a PDF sent to the Word importer is rejected', int(sql("select count(*) from kb_articles")) == n1)
# oversize: > converter budget (32 MB) - a 34 MB zip-named body; server rejects (ini limit or converter)
big = make_docx() + b'\0' * (34 * 1024 * 1024)
s, body, h = admin.upload('/agent/post.php', fields, 'big.docx', big, 'application/octet-stream', KBREF)
check('oversize docx rejected, no article', int(sql("select count(*) from kb_articles")) == n1, s)
# CSRF
bad = dict(fields, csrf_token='bogus')
s, body, h = admin.upload('/agent/post.php', bad, 'ok.docx', make_docx(), 'application/octet-stream', KBREF)
check('docx import with a bad CSRF token is rejected', int(sql("select count(*) from kb_articles")) == n1, s)
def tech_token():
    s, body, h = tech.req('/agent/modals/kb_article/kb_article_import_docx.php')
    try: body = json.loads(body).get('content', '')
    except Exception: pass
    return csrf(body.replace('\\"', '"')) or csrf(body)
ttok = tech_token()
check('technician has a valid session token of their own', bool(ttok))
# permission: tech with view-only module_kb
s, body, h = tech.upload('/agent/post.php', dict(fields, csrf_token=ttok), 'tech.docx', make_docx(), 'application/octet-stream', KBREF)
check('user with view-only module_kb cannot import', int(sql("select count(*) from kb_articles")) == n1, s)

# PDF
pf = dict(fields); pf.pop('import_kb_article_docx'); pf['import_kb_article_pdf'] = '1'
s, body, h = admin.upload('/agent/post.php', pf, 'network_guide.pdf', make_pdf('CANARY-PDF-BODY runs on the main switch.'), 'application/pdf', KBREF, field='pdf_file')
check('pdf import redirects', s in (302, 303), (s, body[:200]))
check('pdf import created one article', int(sql("select count(*) from kb_articles")) == n1 + 1)
aid2 = last_article()
c2 = sql("select kb_article_content from kb_articles where kb_article_id=%s" % aid2)
check('pdf content converted', 'CANARY-PDF-BODY' in c2 and 'PDF Import Heading' in c2, c2[:300])
check('pdf title from filename', sql("select kb_article_title from kb_articles where kb_article_id=%s" % aid2) == 'Network Guide')
check('pdf search text from poppler', 'CANARY-PDF-BODY' in sql("select kb_article_content_raw from kb_articles where kb_article_id=%s" % aid2))
n2 = int(sql("select count(*) from kb_articles"))
s, body, h = admin.upload('/agent/post.php', pf, 'fake.pdf', b'not a pdf', 'application/pdf', KBREF, field='pdf_file')
check('garbage .pdf rejected, no article', int(sql("select count(*) from kb_articles")) == n2, s)
s, body, h = admin.upload('/agent/post.php', pf, 'a.docx', make_docx(), 'application/octet-stream', KBREF, field='pdf_file')
check('a .docx sent to the PDF importer is rejected', int(sql("select count(*) from kb_articles")) == n2, s)
s, body, h = admin.upload('/agent/post.php', pf, 'big.pdf', make_pdf('x') + b'\0' * (34 * 1024 * 1024), 'application/pdf', KBREF, field='pdf_file')
check('oversize pdf rejected, no article', int(sql("select count(*) from kb_articles")) == n2, s)

# cleanup of test users
sql("delete from users where user_email='tech@scratch.test'")
rcli('flushall')
p = sum(1 for r in results if r[1]); print('\n%d/%d passed' % (p, len(results)))
sys.exit(0 if p == len(results) else 1)
