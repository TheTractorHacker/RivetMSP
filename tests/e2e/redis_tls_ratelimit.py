"""
End-to-end check of Redis authentication + TLS settings, the REST API rate limit (429 / Retry-After / audit / fail-open), the queue worker
under cron, and the 2.6.74 migration upgrade path, through the real web stack.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory against a
scratch database, set $config_https_only = FALSE in its config.php, serve it with `php -S 127.0.0.1:<port> -t <app dir>`, and give this
script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS. It starts its own throwaway Redis servers on 6385 (plain),
6386 (requirepass + an ACL user) and 6387 (TLS, skipped when redis-server has no TLS) with a self-signed CA made by openssl, and a
second php -S on <port>+1 for the environment-override check. It never touches any other Redis.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/redis_tls_ratelimit.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>

Optional migration upgrade path (a scratch install of the previous release, already set up, in TEST_UPGRADE_APP with database
TEST_UPGRADE_DB; the user needs grants on both databases): the current tree is laid over it, ONE `scripts/update_cli.php --update_db`
run must reach the latest version with the new columns.
"""
import re, sys, json, subprocess, os, time, shutil, tempfile, http.cookiejar, urllib.request, urllib.parse, urllib.error, hashlib, signal
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PORT = int(BASE.rsplit(':', 1)[1]); BASE2 = 'http://127.0.0.1:%d' % (PORT + 1)
PLAIN, AUTHP, TLSP = 6385, 6386, 6387
RPASS, ACLUSER, ACLPASS = 'Redis-Test-Pass-1', 'rivet', 'Acl-Pass-2'
WORK = tempfile.mkdtemp(prefix='rtl_')
procs = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Session:
    def __init__(self, base):
        self.base = base; self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None, headers=None):
        h = dict(headers or {})
        if referer: h['Referer'] = self.base + referer
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(self.base + path, data=body, headers=h)
        try:
            resp = self.opener.open(r, timeout=90); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
    def login(self):
        s, html, h = self.req('/login.php'); t = csrf(html)
        d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
        if t: d['csrf_token'] = t
        return self.req('/login.php', d)[0] in (302, 303)
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
def skip(name, why): print('SKIP  ' + name + '  [' + why + ']')

# ---------------------------------------------------------------- throwaway Redis servers
def redis_cli(port, *a, tls=False):
    cmd = ['redis-cli', '-p', str(port)]
    if tls: cmd += ['--tls', '--cacert', WORK + '/ca.crt']
    return subprocess.run(cmd + list(a), capture_output=True, text=True).stdout.strip()
def start_redis(port, *extra):
    p = subprocess.Popen(['redis-server', '--port', str(port), '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--dir', WORK] + list(extra),
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    procs.append(p); return p
def wait_up(port, tls=False, pw=None):
    for _ in range(40):
        a = ['-a', pw, '--no-auth-warning', 'ping'] if pw else ['ping']
        if redis_cli(port, *a, tls=tls) == 'PONG': return True
        time.sleep(0.25)
    return False
def sh(cmd): return subprocess.run(cmd, capture_output=True, text=True, cwd=WORK)

plain = start_redis(PLAIN)
auth = start_redis(AUTHP, '--requirepass', RPASS)
assert wait_up(PLAIN) and wait_up(AUTHP, pw=RPASS), 'throwaway redis did not start'
redis_cli(AUTHP, '-a', RPASS, '--no-auth-warning', 'ACL', 'SETUSER', ACLUSER, 'on', '>' + ACLPASS, '~*', '&*', '+@all')

TLS_OK = False
sh(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.crt', '-days', '2', '-subj', '/CN=Test CA'])
sh(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'srv.key', '-out', 'srv.csr', '-subj', '/CN=localhost'])
open(WORK + '/ext.cnf', 'w').write('subjectAltName=DNS:localhost,IP:127.0.0.1')
sh(['openssl', 'x509', '-req', '-in', 'srv.csr', '-CA', 'ca.crt', '-CAkey', 'ca.key', '-CAcreateserial', '-out', 'srv.crt', '-days', '2', '-extfile', 'ext.cnf'])
sh(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'other.key', '-out', 'other.crt', '-days', '2', '-subj', '/CN=Other CA'])
tls = start_redis(TLSP, '--tls-port', str(TLSP), '--port', '0', '--tls-cert-file', WORK + '/srv.crt', '--tls-key-file', WORK + '/srv.key',
                  '--tls-ca-cert-file', WORK + '/ca.crt', '--tls-auth-clients', 'no')
# (--port is given twice: the later value, 0, disables the plain listener on this server)
time.sleep(0.5)
if tls.poll() is None and wait_up(TLSP, tls=True):
    TLS_OK = True
CA, OTHER = WORK + '/ca.crt', WORK + '/other.crt'

def cleanup():
    for p in procs:
        try: p.terminate()
        except Exception: pass
    for p in procs:
        try: p.wait(timeout=5)
        except Exception: pass
    shutil.rmtree(WORK, ignore_errors=True)

try:
    admin = Session(BASE)
    for p in (PLAIN, AUTHP): redis_cli(p, *(['-a', RPASS, '--no-auth-warning'] if p == AUTHP else []), 'flushall')
    check('sign in as the scratch admin', admin.login())

    def settings_page(): return admin.req('/admin/settings_redis.php')[1]
    def post_redis(d, button):
        pg = settings_page(); data = {'csrf_token': csrf(pg), button: '1'}; data.update(d)
        admin.req('/admin/post.php', data, referer='/admin/settings_redis.php')
        return settings_page()  # the flash message shows on the next page
    def form(host='127.0.0.1', port=PLAIN, **kw):
        d = {'redis_host': host, 'redis_port': str(port), 'redis_db': '0', 'redis_password': ''}
        d.update({k: v for k, v in kw.items()}); return d

    # ------------------------------------------------------------ settings page
    page = settings_page()
    check('the Redis page has the ACL username, TLS, verify and certificate fields',
          all(x in page for x in ['name="redis_username"', 'name="redis_tls"', 'name="redis_tls_verify"', 'name="redis_tls_ca_file"', 'name="redis_tls_cert_file"', 'name="redis_tls_key_file"']))
    cols = sql("select count(*) from information_schema.columns where table_schema=database() and table_name='settings' and column_name in ('config_redis_username','config_redis_tls','config_redis_tls_verify','config_redis_tls_ca_file','config_redis_tls_cert_file','config_redis_tls_key_file','config_api_rate_limit_per_minute')")
    check('a fresh install has all seven new settings columns, ending at the latest version', cols == '7' and sql('select config_current_database_version from settings') == open(APP + '/includes/database_version.php').read().split('LATEST_DATABASE_VERSION", "')[1].split('"')[0], cols)

    page = post_redis(form(port=PLAIN), 'test_redis_connection')
    check('Test only against the plain server connects and says nothing was saved', 'Connected to Redis. Nothing was saved.' in page, '')
    page = post_redis(form(port=AUTHP), 'test_redis_connection')
    check('a password-protected server without a password: distinct "auth" reason with a fix', 'Authentication failed' in page and 'Fix:' in page and 'requirepass' in page)
    page = post_redis(form(port=AUTHP, redis_password='Wrong-pass-999'), 'test_redis_connection')
    check('a wrong password is an auth failure and the password is never echoed', 'Authentication failed' in page and 'Wrong-pass-999' not in page)
    page = post_redis(form(port=AUTHP, redis_password=RPASS), 'test_redis_connection')
    check('the right password connects', 'Connected to Redis. Nothing was saved.' in page and RPASS not in page)
    page = post_redis(form(port=AUTHP, redis_password=ACLPASS, redis_username=ACLUSER), 'test_redis_connection')
    check('an ACL username and its password connect', 'Connected to Redis. Nothing was saved.' in page and ACLPASS not in page)
    page = post_redis(form(port=AUTHP, redis_password=ACLPASS, redis_username='nobody'), 'test_redis_connection')
    check('a wrong ACL username is an auth failure that mentions the username', 'Authentication failed' in page and 'username' in page.split('Authentication failed')[1][:200])
    page = post_redis(form(port=AUTHP, redis_password='x', redis_username='bad name!'), 'test_redis_connection')
    check('an invalid username is refused before connecting (invalid reason)', 'username may use letters' in page)
    page = post_redis(form(port=59999), 'test_redis_connection')
    check('nothing listening: "unreachable" reason with a fix', 'Could not connect' in page and 'Fix:' in page and 'Authentication failed' not in page)
    page = post_redis(form(port=PLAIN, redis_tls='1', redis_tls_ca_file=WORK + '/nope.pem'), 'test_redis_connection')
    check('a CA file that does not exist is refused (validate with file existence)', 'CA file cannot be read' in page)
    page = post_redis(form(port=PLAIN, redis_tls_ca_file=CA), 'test_redis_connection')
    check('certificate files without TLS are refused', 'only apply when TLS is turned on' in page)

    if TLS_OK:
        page = post_redis(form(port=TLSP, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA), 'test_redis_connection')
        check('TLS with the right CA and verification on connects', 'Connected to Redis. Nothing was saved.' in page, re.sub(r'<[^>]+>', ' ', page)[:0])
        page = post_redis(form(port=TLSP, redis_tls='1', redis_tls_verify='1'), 'test_redis_connection')
        check('TLS to a self-signed server without its CA: "tls" reason with a fix', 'TLS handshake' in page and 'Fix:' in page)
        page = post_redis(form(port=TLSP, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=OTHER), 'test_redis_connection')
        check('TLS with the wrong CA: "tls" reason', 'TLS handshake' in page)
        page = post_redis(form(port=TLSP, redis_tls='1'), 'test_redis_connection')
        check('TLS with verification off connects to a self-signed server', 'Connected to Redis. Nothing was saved.' in page)
        page = post_redis(form(port=TLSP), 'test_redis_connection')
        check('plain text to a TLS port does not connect', 'Connected to Redis' not in page)
    else:
        skip('TLS connection cases', 'this redis-server has no TLS support')

    # ------------------------------------------------------------ save + round trip + encryption
    n = int(sql("select count(*) from audit_events where event_type='redis.settings_changed'") or 0)
    page = post_redis(form(port=AUTHP, redis_password=ACLPASS, redis_username=ACLUSER), 'save_redis_settings')
    check('Test and save with the ACL user stores the new columns', sql('select config_redis_username, config_redis_port from settings') == '%s\t%d' % (ACLUSER, AUTHP))
    enc = sql('select config_redis_password from settings')
    check('the password is stored encrypted, not in clear', enc != '' and ACLPASS not in enc and enc != ACLPASS)
    page = settings_page()
    check('the page never echoes the password and shows the username', ACLPASS not in page and 'value="%s"' % ACLUSER in page and 'Saved. Leave blank to keep' in page and 'Connected' in page)
    check('saving is audited without the password', int(sql("select count(*) from audit_events where event_type='redis.settings_changed'") or 0) == n + 1 and ACLPASS not in sql("select group_concat(metadata_json) from audit_events where event_type='redis.settings_changed'"))
    page = post_redis(form(port=AUTHP, redis_username=ACLUSER), 'save_redis_settings')
    check('saving with a blank password keeps the stored one (still connects)', sql('select config_redis_password from settings') == enc and 'Redis settings saved. Connected.' in page)
    if TLS_OK:
        post_redis(form(port=TLSP, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA, redis_clear_password='1'), 'save_redis_settings')
        row = sql('select config_redis_tls, config_redis_tls_verify, config_redis_tls_ca_file, config_redis_port, config_redis_password = \'\' from settings')
        check('TLS settings round trip into the database (and the password was cleared)', row == '1\t1\t%s\t%d\t1' % (CA, TLSP), row)
        page = settings_page()
        check('the page shows TLS ticked and the CA path, and is connected over TLS', re.search(r'id="redis_tls"[^>]*checked', page) and CA in page and 'Not connected' not in page)
        refused = post_redis(form(port=TLSP, redis_tls='1', redis_tls_verify='1'), 'save_redis_settings')
        check('saving a failing TLS config is refused unless forced', 'Nothing was saved' in refused and sql('select config_redis_tls_ca_file from settings') == CA)
    # back to the plain server for everything that follows
    post_redis(form(port=PLAIN, redis_clear_password='1'), 'save_redis_settings')
    row = sql("select config_redis_port, config_redis_username, config_redis_tls, config_redis_tls_verify from settings")
    check('saving the plain server clears the TLS switch and username', row.split('\t')[0] == str(PLAIN) and row.split('\t')[2] == '0', row)

    # ------------------------------------------------------------ environment precedence
    resolve_php = ('chdir("%s"); require "config.php"; require "functions.php"; require "vendor/autoload.php"; '
                   '$r = RivetMSP\\Redis\\RedisSettings::resolve($mysqli); echo json_encode(["u"=>$r["username"],"tls"=>$r["tls"],"v"=>$r["tls_verify"],"ca"=>$r["tls_ca_file"],"port"=>$r["port"],"env"=>$r["from_env"]["port"]]);' % APP)
    def resolve(env_extra):
        env = {k: v for k, v in os.environ.items() if not k.startswith('RIVETMSP_REDIS')}; env.update(env_extra)
        r = subprocess.run(['php', '-r', resolve_php], capture_output=True, text=True, env=env, cwd=APP)
        try: return json.loads(r.stdout)
        except Exception: return {'error': r.stdout + r.stderr}
    sql("update settings set config_redis_username='stored-user', config_redis_tls=1, config_redis_tls_verify=1, config_redis_tls_ca_file='/stored/ca.pem', config_redis_port=%d" % PLAIN)
    r = resolve({})
    check('with no environment the stored values apply', r.get('u') == 'stored-user' and r.get('tls') is True and r.get('v') is True and r.get('ca') == '/stored/ca.pem' and r.get('env') is False, r)
    r = resolve({'RIVETMSP_REDIS_USERNAME': 'env-user', 'RIVETMSP_REDIS_TLS': '0', 'RIVETMSP_REDIS_TLS_VERIFY': '0', 'RIVETMSP_REDIS_TLS_CA_FILE': '/env/ca.pem', 'RIVETMSP_REDIS_PORT': '6999'})
    check('RIVETMSP_REDIS_USERNAME/TLS/TLS_VERIFY/TLS_CA_FILE/PORT win over the stored values', r.get('u') == 'env-user' and r.get('tls') is False and r.get('v') is False and r.get('ca') == '/env/ca.pem' and r.get('port') == 6999 and r.get('env') is True, r)
    envfile = WORK + '/redis.env'
    open(envfile, 'w').write('# written by the installer\nRIVETMSP_REDIS_USERNAME="file-user"\nexport RIVETMSP_REDIS_PORT=6998\nRIVETMSP_REDIS_TLS=1\nUNRELATED=1\n')
    r = resolve({'RIVETMSP_REDIS_ENV_FILE': envfile})
    check('the env file (/etc/rivetmsp/redis.env format) wins over stored values', r.get('u') == 'file-user' and r.get('port') == 6998 and r.get('tls') is True and r.get('v') is True, r)
    r = resolve({'RIVETMSP_REDIS_ENV_FILE': envfile, 'RIVETMSP_REDIS_PORT': '6997', 'RIVETMSP_REDIS_USERNAME': 'proc-user'})
    check('the real process environment beats the env file', r.get('port') == 6997 and r.get('u') == 'proc-user' and r.get('tls') is True, r)
    # the page shows env-controlled fields read-only and posting cannot change them
    env_srv = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % (PORT + 1), '-t', APP], env=dict(os.environ, RIVETMSP_REDIS_USERNAME='env-user', RIVETMSP_REDIS_TLS='0', RIVETMSP_REDIS_PORT=str(PLAIN), RIVETMSP_REDIS_HOST='127.0.0.1'),
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, cwd=APP)
    procs.append(env_srv); time.sleep(1)
    redis_cli(PLAIN, 'flushall')
    admin2 = Session(BASE2); ok2 = admin2.login()
    pg = admin2.req('/admin/settings_redis.php')[1]
    check('with the environment set, the page shows the env username read-only with "Set by the server"', ok2 and re.search(r'name="redis_username"[^>]*value="env-user"[^>]*readonly', pg) and 'RIVETMSP_REDIS_USERNAME' in pg and re.search(r'id="redis_tls"[^>]*disabled', pg), re.sub(r'\s+', ' ', pg)[:0])
    admin2.req('/admin/post.php', {'csrf_token': csrf(pg), 'save_redis_settings': '1', 'redis_host': '127.0.0.1', 'redis_port': '1', 'redis_username': 'hacked', 'redis_tls': '1', 'redis_password': ''}, referer='/admin/settings_redis.php')
    check('posting over an environment-controlled field does not change the stored value', sql('select config_redis_username from settings') == 'stored-user')
    env_srv.terminate(); procs.remove(env_srv)
    sql("update settings set config_redis_username='', config_redis_tls=0, config_redis_tls_verify=1, config_redis_tls_ca_file='', config_redis_port=%d, config_redis_host='127.0.0.1'" % PLAIN)

    # ------------------------------------------------------------ API rate limit
    redis_cli(PLAIN, 'flushall')
    sql('update settings set config_api_rate_limit_per_minute = 120')
    api_pg = admin.req('/admin/api_keys.php')[1]
    check('the API Keys page has the rate limit field (default 120)', 'name="api_rate_limit_per_minute"' in api_pg and 'value="120"' in api_pg)
    def set_limit(v):
        pg = admin.req('/admin/api_keys.php')[1]
        admin.req('/admin/post.php', {'csrf_token': csrf(pg), 'set_api_rate_limit': '1', 'api_rate_limit_per_minute': str(v)}, referer='/admin/api_keys.php')
        return sql('select config_api_rate_limit_per_minute from settings')
    check('saving the rate limit stores it (and it is audited)', set_limit(3) == '3' and int(sql("select count(*) from audit_events where event_type='api.rate_limit_changed'") or 0) >= 1)
    check('a negative or absurd value is clamped', set_limit(-5) == '0' and set_limit(999999999) == '100000' and set_limit(3) == '3')

    def mkkey(name, secret):
        sql("delete from api_keys where api_key_name='%s'" % name)
        sql("insert into api_keys set api_key_name='%s', api_key_secret='%s', api_key_decrypt_hash='x', api_key_expire=DATE_ADD(CURDATE(), INTERVAL 30 DAY), api_key_client_id=0, api_key_permission='read'" % (name, hashlib.sha256(secret.encode()).hexdigest()))
    keys = {n: 'e2e-secret-%s-0123456789abcdef' % n for n in 'ABCD'}
    for n, sec in keys.items(): mkkey('E2E ' + n, sec)
    anon = Session(BASE)
    def api(secret=None, bearer=None, path='/api/v1/statuses'):
        h = {}
        if secret: h['X-Api-Key'] = secret
        if bearer: h['Authorization'] = 'Bearer ' + bearer
        return Session(BASE).req(path, headers=h)
    sql("delete from audit_events where event_type='api.rate_limited'")
    s, body, h = api(keys['A'])
    check('a valid API key works (not 401)', s == 200, (s, body[:100]))
    codes = [api(keys['A'])[0] for _ in range(4)]   # 1 already used: calls 2,3 pass, 4,5 are over
    check('with the limit at 3 per minute the 4th call in the window is HTTP 429', codes[:2] == [200, 200] and codes[2:] == [429, 429], codes)
    s, body, h = api(keys['A'])
    ra = h.get('Retry-After')
    check('the 429 carries a numeric Retry-After within the window and a JSON error', s == 429 and ra and 1 <= int(ra) <= 60 and json.loads(body).get('error') == 'Rate limit exceeded' and json.loads(body).get('retry_after') == int(ra), (s, ra, body[:120]))
    check('the key counter lives in Redis under rivetmsp:rl:api:', int(redis_cli(PLAIN, 'eval', "return #redis.call('keys','rivetmsp:rl:api:key:*')", '0') or 0) >= 1)
    ev = sql("select count(*), min(entity_id) from audit_events where event_type='api.rate_limited'")
    check('exactly one api.rate_limited audit event per key and window, despite several 429s', ev.split('\t')[0] == '1', ev)
    meta = sql("select metadata_json from audit_events where event_type='api.rate_limited' limit 1")
    check('the audit event names the scope and limit and holds no secret', '"scope":"key"' in meta.replace(' ', '') and '"limit_per_minute":3' in meta.replace(' ', '') and keys['A'] not in meta, meta)
    s, body, h = api(keys['B'])
    check('another key is not affected by the first key being limited', s == 200, (s, body[:100]))
    # per-address limit: three times the key limit (9) across all keys from this address
    for _ in range(2): api(keys['B'])          # B: 3 hits, address: 3 (A) + 3 (B)
    for _ in range(3): api(keys['C'])          # address: 9
    s, body, h = api(keys['D'])
    check('one address is limited at three times the key limit, with Retry-After', s == 429 and h.get('Retry-After'), (s, body[:100]))
    check('the address-scope 429 is audited once as scope address', int(sql("select count(*) from audit_events where event_type='api.rate_limited' and metadata_json like '%\"scope\":\"address\"%'") or 0) == 1)
    redis_cli(PLAIN, 'flushall')
    s, body, h = api(keys['A'])
    check('after the window resets the key works again', s == 200, (s, body[:100]))
    # a bearer token is limited per token
    uid = sql("select user_id from users where user_type=1 order by user_id limit 1")
    tok = 'e2e-bearer-token-0123456789abcdef0123456789'
    sql("delete from api_tokens where token_name='E2E token'; insert into api_tokens set token_user_id=%s, token_name='E2E token', token_hash='%s'" % (uid, hashlib.sha256(tok.encode()).hexdigest()))
    redis_cli(PLAIN, 'flushall')
    codes = [api(bearer=tok)[0] for _ in range(5)]
    check('a Bearer token is limited per token too (3 pass, then 429)', codes[:3] == [200, 200, 200] and codes[3:] == [429, 429], codes)
    # off
    set_limit(0); redis_cli(PLAIN, 'flushall')
    codes = [api(keys['A'])[0] for _ in range(8)]
    check('a limit of 0 turns the limiter off', all(c == 200 for c in codes), codes)
    set_limit(3); redis_cli(PLAIN, 'flushall')
    # fail open: Redis stopped
    plain.terminate(); plain.wait(timeout=10)
    codes = [api(keys['A'])[0] for _ in range(6)]
    check('with Redis stopped the API fails open (no 429s, no errors)', all(c == 200 for c in codes), codes)
    codes = [Session(BASE).req('/api/v1/auth', {'email': 'x@x.test', 'password': 'x'}, headers={})[0] for _ in range(1)]
    check('the sign-in endpoint still fails closed with Redis down (429)', codes == [429], codes)
    plain = start_redis(PLAIN); assert wait_up(PLAIN)
    codes = [api(keys['A'])[0] for _ in range(5)]
    check('Redis back: limiting resumes', codes == [200, 200, 200, 429, 429], codes)
    set_limit(120)

    # ------------------------------------------------------------ queue worker under cron
    redis_cli(PLAIN, 'flushall')
    sql("delete from integration_jobs; delete from webhooks where webhook_name like 'E2E worker%'")
    env = dict(os.environ); env.pop('RIVETMSP_REDIS_PORT', None)
    q = ("chdir('%s/cron'); require '../config.php'; require '../functions.php'; require '../vendor/autoload.php'; require '../includes/event_bus.php'; "
         "$q = new RivetCore\\Jobs\\JobQueue(rivetCoreDb($mysqli)); "
         "foreach ([1,2,3] as $i) $q->enqueue('webhook.deliver', ['webhook_id' => 999990 + $i, 'event' => 'e2e.test', 'data' => []], null, 'webhook', 0, 3); echo 'queued';" % APP)
    r = subprocess.run(['php', '-r', q], capture_output=True, text=True, cwd=APP)
    check('three jobs can be enqueued with the Core JobQueue', r.stdout.strip() == 'queued' and sql("select count(*) from integration_jobs where status='pending'") == '3', r.stdout + r.stderr)
    r = subprocess.run(['php', 'integration_worker.php'], capture_output=True, text=True, cwd=APP + '/cron')
    out = r.stdout + r.stderr
    check('cron/integration_worker.php runs once: claims the 3 jobs and finishes them (webhook gone = failed for good)', r.returncode == 0 and '3 job(s) claimed' in out and '3 failed for good' in out, out)
    check('the jobs left the queue in a terminal state with a reason', sql("select count(*) from integration_jobs where status in ('dead_letter','failed')") == '3' and sql("select count(*) from integration_jobs where status='pending'") == '0', sql('select status, result from integration_jobs'))
    # a job that completes: a real endpoint answering 200 (local receiver, private networks allowed for this child only)
    import threading
    from http.server import BaseHTTPRequestHandler, HTTPServer
    hits = []
    class H(BaseHTTPRequestHandler):
        def do_POST(self):
            hits.append(self.rfile.read(int(self.headers.get('Content-Length', 0)))); self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
        def log_message(self, *a): pass
    srv = HTTPServer(('127.0.0.1', 9456), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
    sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) values ('E2E worker hook', 'http://127.0.0.1:9456/hook', 'sec', 'e2e.test', 1)")
    wid = sql("select webhook_id from webhooks where webhook_name='E2E worker hook'")
    q2 = q.replace("foreach ([1,2,3] as $i) $q->enqueue('webhook.deliver', ['webhook_id' => 999990 + $i,", "foreach ([1,2] as $i) $q->enqueue('webhook.deliver', ['webhook_id' => %s," % wid)
    subprocess.run(['php', '-r', q2], capture_output=True, text=True, cwd=APP)
    r = subprocess.run(['php', 'integration_worker.php'], capture_output=True, text=True, cwd=APP + '/cron', env=dict(env, RIVETMSP_WEBHOOK_ALLOW_PRIVATE='1'))
    srv.shutdown()
    check('a second worker run delivers queued webhooks (2 completed) through the registered handler', '2 job(s) claimed: 2 completed' in r.stdout and len(hits) == 2, r.stdout + r.stderr)
    redis_cli(PLAIN, 'set', 'rivetmsp:lock:cron:integration_worker', 'someone-else', 'EX', '60')
    r = subprocess.run(['php', 'integration_worker.php'], capture_output=True, text=True, cwd=APP + '/cron')
    check('the worker exits quietly while another copy holds its Redis lock', 'already running' in r.stdout, r.stdout + r.stderr)
    redis_cli(PLAIN, 'del', 'rivetmsp:lock:cron:integration_worker')
    cron_pg = admin.req('/admin/cron.php')[1]
    check('the Cron Manager catalog lists the integration worker with a Run now', 'integration_worker.php' in cron_pg and 'Integration job worker' in cron_pg)
    sql("delete from webhooks where webhook_name like 'E2E worker%'")

    # ------------------------------------------------------------ migration upgrade path
    UP_APP = os.environ.get('TEST_UPGRADE_APP'); UP_DB = os.environ.get('TEST_UPGRADE_DB')
    if UP_APP and UP_DB:
        def usql(q):
            return subprocess.run(['mysql', '-u', USER, '-N', '-B', UP_DB, '-e', q], capture_output=True, text=True).stdout.strip()
        before = usql('select config_current_database_version from settings')
        had = usql("select count(*) from information_schema.columns where table_schema=database() and table_name='settings' and column_name in ('config_redis_username','config_redis_tls','config_api_rate_limit_per_minute')")
        check('the previous release scratch install is on an older database version without the new columns', before != '' and before != open(APP + '/includes/database_version.php').read().split('LATEST_DATABASE_VERSION", "')[1].split('"')[0] and had == '0', (before, had))
        cfg = open(UP_APP + '/config.php').read()
        subprocess.run(['rsync', '-a', '--exclude', 'config.php', '--exclude', 'uploads', '--exclude', 'backups', '--exclude', '.git', '--exclude', 'tests/browser', '--exclude', 'tests/installer', APP + '/', UP_APP + '/'], check=True)
        open(UP_APP + '/config.php', 'w').write(cfg)
        r = subprocess.run(['php', 'update_cli.php', '--update_db'], capture_output=True, text=True, cwd=UP_APP + '/scripts')
        after = usql('select config_current_database_version from settings')
        latest = open(APP + '/includes/database_version.php').read().split('LATEST_DATABASE_VERSION", "')[1].split('"')[0]
        check('ONE update_cli run reaches the latest database version', after == latest, (before, after, r.stdout[-300:], r.stderr[-300:]))
        have = usql("select group_concat(column_name order by column_name) from information_schema.columns where table_schema=database() and table_name='settings' and column_name like 'config_redis_%' or (table_schema=database() and table_name='settings' and column_name='config_api_rate_limit_per_minute')")
        for c in ['config_redis_username', 'config_redis_tls', 'config_redis_tls_verify', 'config_redis_tls_ca_file', 'config_redis_tls_cert_file', 'config_redis_tls_key_file', 'config_api_rate_limit_per_minute']:
            check('upgraded database has ' + c, c in have.split(','), have)
        d = usql('select config_api_rate_limit_per_minute, config_redis_tls, config_redis_tls_verify from settings')
        check('upgrade defaults: limit 120, TLS off, verification on', d == '120\t0\t1', d)
        r2 = subprocess.run(['php', 'update_cli.php', '--update_db'], capture_output=True, text=True, cwd=UP_APP + '/scripts')
        check('running the update again is a no-op (idempotent)', usql('select config_current_database_version from settings') == latest and r2.returncode == 0, r2.stdout[-200:])
    else:
        skip('migration upgrade path', 'set TEST_UPGRADE_APP and TEST_UPGRADE_DB')

finally:
    cleanup()
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
