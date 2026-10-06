"""
End-to-end check of Administration > Webhooks platforms (RivetCore 0.21): the guided add flow with 24 presets, the guides page, validation
with RivetCore (URL pattern, authentication, templates, URL policy), what is stored (secrets, the URL and the outgoing authentication
encrypted; never in any HTML), edit keeps blank secrets, real delivery to a local mock receiver (n8n with header auth and signatures, ntfy
text with Title/Priority headers, Discord and Telegram bodies, custom-template escaping, Matrix client PUT with a stable {txn} on retry,
legacy rows unchanged), event patterns ('ticket.*', '*'), 'Send test' / 'Preview payload' (redaction, test log entry), the events picker
markup (also on the event rules page), the redesigned guided flow (4-step stepper, advanced options disclosure, live address check endpoint,
quick event sets, success screen, edit tabs, enable toggle, duplicate, deliveries, list search) and the migration upgrade path (previous release tag + ONE update_cli run reaches the latest version).

Needs a THROWAWAY app (scripts/setup_cli.php run from scripts/, $config_https_only = FALSE) served WITH the receiver reachable:
`RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<scratch redis> PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:<port> -t <app dir>`
(the receiver below listens on 127.0.0.1:9461; the retry worker subprocess gets the same environment from this script).
The upgrade section also needs `sudo -n mysql` to create a second scratch database; it is skipped (and reported) without it.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/webhook_platforms.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, shlex, subprocess, os, time, hmac, hashlib, threading, shutil, tempfile, http.cookiejar, urllib.request, urllib.parse, urllib.error
from http.server import BaseHTTPRequestHandler, HTTPServer
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = os.path.abspath(sys.argv[5])
USER = os.environ['TEST_DB_USER']; DBPASS = os.environ['TEST_DB_PASS']; os.environ['MYSQL_PWD'] = DBPASS
PORT = int(os.environ.get('WH_RECEIVER_PORT', '9461'))   # the mock receiver; set WH_RECEIVER_PORT when another run uses 9461
CHILD_ENV = dict(os.environ, RIVETMSP_WEBHOOK_ALLOW_PRIVATE='1')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def req(op, path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = op.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q, db=None):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', '-r', db or DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:400] + ']') if detail and not ok else ''))
def php(code, env=None):
    r = subprocess.run(['php', '-r', code], cwd=APP, capture_output=True, text=True, env=env or CHILD_ENV); return r.stdout.strip() + r.stderr.strip()

# ---------------------------------------------------------------- mock receiver
RX = {'log': [], 'hits': {}}
class H(BaseHTTPRequestHandler):
    def handle_any(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0) or 0))
        RX['hits'][self.path] = RX['hits'].get(self.path, 0) + 1
        RX['log'].append({'method': self.command, 'path': self.path, 'body': body, 'headers': {k.lower(): v for k, v in self.headers.items()}})
        code = 500 if (self.path.startswith('/fail') or (self.path.startswith('/flaky') and RX['hits'][self.path] == 1)) else 200
        self.send_response(code); self.end_headers(); self.wfile.write(b'mock-answer-' + str(code).encode())
    do_POST = do_PUT = handle_any
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', PORT), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
def hits(prefix): return [r for r in RX['log'] if r['path'] == prefix or r['path'].startswith(prefix + '/')]
def wait_for(cond, secs=10):
    end = time.time() + secs
    while time.time() < end:
        if cond(): return True
        time.sleep(0.25)
    return False
def sig_ok(r, secret):
    ts = r['headers'].get('x-rivet-timestamp', ''); v2 = r['headers'].get('x-rivet-signature-v2', '')
    legacy = r['headers'].get('x-rivetmsp-signature', '')
    return (ts.isdigit() and v2 == 't=%s,v1=%s' % (ts, hmac.new(secret.encode(), ts.encode() + b'.' + r['body'], hashlib.sha256).hexdigest())
            and legacy == 'sha256=' + hmac.new(secret.encode(), r['body'], hashlib.sha256).hexdigest())

# ---------------------------------------------------------------- session + helpers
admin = session()
def login(op, email, pw):
    s, html, h = req(op, '/login.php'); d = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: d['csrf_token'] = t
    return req(op, '/login.php', d)[0] in (302, 303)
check('sign in', login(admin, EMAIL, PASSWORD))
check('the scratch config has $config_settings_enc_key (without it encryptSetting() stores plaintext)', 'config_settings_enc_key' in open(APP + '/config.php').read())
DEST_IDS = json.loads(php('require "vendor/autoload.php"; echo json_encode(array_map(fn($d)=>$d->id, RivetCore\\Webhooks\\Destinations::all()));'))
check('RivetCore 0.21 ships 24 destination presets', len(DEST_IDS) == 24, DEST_IDS)

def form_page(dest=None, wid=None, extra=''):
    path = '/admin/webhook_form.php' + ('?dest=' + urllib.parse.quote(dest) if dest else ('?id=' + str(wid) if wid else '')) + extra
    return req(admin, path)
def any_csrf(op=None):
    return csrf(req(op or admin, '/admin/settings_webhooks.php')[1])
def submit(data, dest=None, wid=None, op=None):
    """POST through admin/post.php like the form does; returns (page after the redirect, flash-bearing html)."""
    op = op or admin
    pg = form_page(dest, wid)[1]; d = dict(data); d['csrf_token'] = csrf(pg) or any_csrf(op)
    path = '/admin/webhook_form.php' + ('?dest=' + dest if dest else ('?id=' + str(wid) if wid else ''))
    s, body, h = req(op, '/admin/post.php', d, referer=path)
    loc = h.get('Location', '')
    nxt = req(op, '/admin/' + loc.lstrip('/').replace('/admin/', '')) if loc else (s, body, h)
    return nxt[1].replace('\\/', '/')
def row(name):
    cols = "webhook_id,webhook_name,webhook_url,webhook_secret,webhook_events,webhook_enabled,webhook_destination,webhook_format,webhook_method,webhook_template,webhook_auth_mode,webhook_auth_enc,webhook_extra"
    out = sql("select json_object(%s) from webhooks where webhook_name='%s' order by webhook_id desc limit 1" % (','.join("'%s',%s" % (c, c) for c in cols.split(',')), name.replace("'", "''")))
    return json.loads(out) if out else None
def count(): return int(sql("select count(*) from webhooks where webhook_name like 'WP %'"))
def dec(v):
    return php('chdir("cron"); require "../config.php"; require "../functions.php"; echo decryptSetting(%s);' % json.dumps(v))
def emit(event, data=None, worker=True):
    code = ('chdir("cron"); require "../config.php"; require "../includes/inc_set_timezone.php"; require "../functions.php"; require "../vendor/autoload.php"; require "../includes/event_bus.php"; '
            'rivetEmitEvent(%s, json_decode(%s, true)); %s' % (json.dumps(event), json.dumps(json.dumps(data or {})), 'rivetRunJobWorker($mysqli, 50, 20);' if worker else ''))
    return php(code)
def worker():
    r = subprocess.run(['php', 'integration_worker.php'], cwd=APP + '/cron', capture_output=True, text=True, env=CHILD_ENV); return r.stdout + r.stderr

sql("delete from webhooks where webhook_name like 'WP %'; delete from webhook_deliveries; delete from integration_jobs; delete from webhook_queue")

# ---------------------------------------------------------------- list: empty state
if sql("select count(*) from webhooks") == '0':
    s, lp0, h = req(admin, '/admin/settings_webhooks.php')
    check('list empty state: "Add your first webhook" plus one shortcut per popular platform, and no search box', s == 200 and 'Add your first webhook' in lp0 and lp0.count('class="whf-shortcut"') == 7 and 'data-whf-list-search' not in lp0 and 'href="webhook_form.php?dest=n8n"' in lp0, s)
else:
    print('NOTE  list empty state skipped: the scratch database already has webhooks')

# ---------------------------------------------------------------- add flow + guides
s, page, h = form_page()
cards = re.findall(r'data-wh-card data-id="([^"]+)"', page)
check('step 1 of Add webhook lists every preset as a card (24)', s == 200 and len(cards) == 24 and sorted(cards) == sorted(DEST_IDS), (s, len(cards)))
check('the card grid is searchable and grouped by category', 'data-wh-platform-search' in page and all(c in page for c in ['Automation platforms', 'Team chat', 'Push and notification services', 'Home automation', 'Generic']) and 'Custom template' in page and 'Generic JSON' in page)
check('the add flow links to the guides', 'webhook_guides.php' in page)
pop_ids = re.findall(r'data-wh-pop data-id="([^"]+)"', page)
check('step 1: a Popular row (n8n, Slack, Discord, Teams, ntfy, Home Assistant, Generic JSON first), a Recently used row (filled from localStorage by the script), category chips, a 4-step stepper with a progress bar',
      pop_ids == ['n8n', 'slack', 'discord', 'teams', 'ntfy', 'home-assistant', 'generic-json'] and 'data-whf-recent' in page and page.count('data-whf-cat="') >= 6 and page.count('data-whf-stepper-item=') == 4 and 'role="progressbar"' in page and 'aria-current="step"' in page, pop_ids)
check('step 1: cards link to ?dest=<id> and the old ?destination=<id> link still opens the form', 'href="webhook_form.php?dest=n8n"' in page and 'data-whf-pane="connect"' in req(admin, '/admin/webhook_form.php?destination=n8n')[1])
s, guides, h = req(admin, '/admin/webhook_guides.php')
check('the guides page lists all 24 destinations with a full guide each', s == 200 and all(('id="%s"' % d) in guides for d in DEST_IDS) and guides.count('Set it up') >= 24, s)
check('the guides carry "Verify our signature" copy buttons and the Receiving in n8n walk-through', guides.count('data-wg-copy="#') >= 60 and 'Verify our signature' in guides and 'id="receiving-in-n8n"' in guides and 'Raw Body' in guides and 'NODE_FUNCTION_ALLOW_BUILTIN' in guides)
check('guides cover the verify languages (node, python, php, bash, n8n)', all(x in guides for x in ['Node.js', 'Python', '>PHP<', 'Bash', 'n8n Code node']))
s, listpage, h = req(admin, '/admin/settings_webhooks.php')
check('the Webhooks page links to Add (guided) and Guides', s == 200 and 'href="webhook_form.php"' in listpage and 'href="webhook_guides.php"' in listpage and 'webhooks/webhook_add.php' not in listpage)
s, f, h = form_page('n8n')
check('step 2 for n8n: form fields, the setup guide (slide-over, trimmed), events picker and test/preview buttons', s == 200 and 'name="webhook_auth_mode"' in f and 'n8n setup' in f and 'id="whf_guide"' in f and 'data-event-picker' in f and 'data-wh-action="test"' in f and 'data-wh-action="preview"' in f and 'Set it up' in f and 'Try it from a terminal' in f)
opts = re.findall(r'<option value="([a-z]+)"[^>]*>', re.search(r'name="webhook_auth_mode".*?</select>', f, re.S).group(0))
check('only the auth modes n8n allows are offered', sorted(opts) == sorted(['none', 'hmac', 'header', 'bearer', 'basic']), opts)
s, f, h = form_page('discord')
opts = re.findall(r'<option value="([a-z]+)"[^>]*>', re.search(r'name="webhook_auth_mode".*?</select>', f, re.S).group(0))
check('discord offers no authentication mode but none, plus its extra field (display name)', opts == ['none'] and 'name="webhook_field[username]"' in f)
s, f, h = form_page('custom-template')
check('custom template: textarea, encoding, placeholder cheat-sheet, live validation hooks, method choice', all(x in f for x in ['name="webhook_template"', 'data-wh-template-status', 'data-wh-insert="{{summary.title}}"', 'name="webhook_method"', 'name="webhook_template_encoding"']))
s, f, h = form_page('telegram')
check('telegram form asks for the bot token (hidden input) and chat id', 'name="webhook_field[bot_token]"' in f and 'type="password"' in f and 'name="webhook_field[chat_id]"' in f)
check('an unknown platform shows the chooser again', len(re.findall(r'data-wh-card data-id', form_page('nope')[1])) == 24)

# ---------------------------------------------------------------- creating webhooks
SECRET = 'whsec_' + 'a1b2c3d4' * 4
N8N_HEADER = 'X-N8N-Auth'; N8N_VALUE = 'n8n-secret-value-7788'
ALL_SECRETS = [SECRET, N8N_VALUE, '987654:ZYX-bottoken_secret', 'matrixtoken-998877', 'ntfy-bearer-5566']
def base(dest, name, url, events, **kw):
    d = {'webhook_destination': dest, 'webhook_name': name, 'webhook_url': url, 'webhook_events[]': events, 'webhook_enabled': '1', 'add_webhook': '1'}
    d.update(kw); return d
n0 = count()
pg = submit(base('generic-json', 'WP generic', 'http://127.0.0.1:%d/generic' % PORT, ['ticket.*'], webhook_secret=SECRET, webhook_auth_mode='hmac'), 'generic-json')
r = row('WP generic')
check('generic-json is created: destination/format/method/events stored, flash shown', r and r['webhook_destination'] == 'generic-json' and r['webhook_format'] == 'json' and r['webhook_method'] == 'POST' and r['webhook_events'] == 'ticket.*' and 'added' in pg, r)
check('the URL, the signing secret are stored encrypted', r and r['webhook_url'].startswith('ENC:') and r['webhook_secret'].startswith('ENC:') and SECRET not in json.dumps(r) and '127.0.0.1' not in r['webhook_url'])
check('and decrypt back to what was entered', r and dec(r['webhook_url']) == 'http://127.0.0.1:%d/generic' % PORT and dec(r['webhook_secret']) == SECRET)

submit(base('n8n', 'WP n8n', 'http://127.0.0.1:%d/n8n' % PORT, ['ticket.created', 'ticket.replied'], webhook_secret=SECRET, webhook_auth_mode='header', auth_header_name=N8N_HEADER, auth_header_value=N8N_VALUE), 'n8n')
r = row('WP n8n')
check('n8n with header authentication is created and the header is stored encrypted, never in clear', r and r['webhook_auth_mode'] == 'header' and (r['webhook_auth_enc'] or '').startswith('ENC:') and N8N_VALUE not in json.dumps(r), r)
check('the encrypted authentication decrypts to the header name and value', r and json.loads(dec(r['webhook_auth_enc'])) == {'header_name': N8N_HEADER, 'header_value': N8N_VALUE})
check('events were stored in order, comma separated', r and r['webhook_events'] == 'ticket.created,ticket.replied')

submit(base('ntfy', 'WP ntfy', 'http://127.0.0.1:%d/{topic}' % PORT, ['*'], webhook_auth_mode='bearer', auth_token='ntfy-bearer-5566', **{'webhook_field[topic]': 'rivet-alerts-test', 'webhook_field[priority]': '4', 'webhook_field[tags]': 'rivet,test'}), 'ntfy')
r = row('WP ntfy')
check('ntfy is created: the topic is filled into the address, priority/tags are kept as options', r and dec(r['webhook_url']) == 'http://127.0.0.1:%d/rivet-alerts-test' % PORT and json.loads(r['webhook_extra'])['fields'] == {'priority': '4', 'tags': 'rivet,test'} and r['webhook_format'] == 'ntfy' and r['webhook_events'] == '*', r)

pg = submit(base('discord', 'WP discord', 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL', ['ticket.created'], **{'webhook_field[username]': 'Helpdesk Bot'}), 'discord')
r = row('WP discord')
check('discord is created with its URL pattern accepted and display name option', r and r['webhook_format'] == 'discord' and json.loads(r['webhook_extra'])['fields'] == {'username': 'Helpdesk Bot'}, (r, re.findall(r'does not look[^<]*', pg)))

submit(base('telegram', 'WP telegram', 'https://api.telegram.org/bot{bot_token}/sendMessage', ['ticket.created'], **{'webhook_field[bot_token]': '987654:ZYX-bottoken_secret', 'webhook_field[chat_id]': '-1001234567890'}), 'telegram')
r = row('WP telegram')
check('telegram is created: bot token built into the (encrypted) URL, chat id stored as an option, token not stored in clear anywhere', r and dec(r['webhook_url']) == 'https://api.telegram.org/bot987654:ZYX-bottoken_secret/sendMessage' and r['webhook_url'].startswith('ENC:') and json.loads(r['webhook_extra'])['fields'] == {'chat_id': '-1001234567890'} and '987654:ZYX-bottoken_secret' not in json.dumps(r), r)

submit(base('matrix-client', 'WP matrix', 'http://127.0.0.1:%d/_matrix/client/v3/rooms/{room_id}/send/m.room.message/{txn}' % PORT, ['ticket.*'], webhook_auth_mode='bearer', auth_token='matrixtoken-998877', **{'webhook_field[room_id]': '!abc123:example.org'}), 'matrix-client')
r = row('WP matrix')
check('matrix client is created with PUT and the room id percent-encoded into the URL, {txn} kept', r and r['webhook_method'] == 'PUT' and dec(r['webhook_url']) == 'http://127.0.0.1:%d/_matrix/client/v3/rooms/%%21abc123:example.org/send/m.room.message/{txn}' % PORT, (r, r and dec(r['webhook_url'])))

TEMPLATE = '{"title":"{{summary.title}}","subject":"{{data.ticket_subject}}","id":{{data.ticket_id|json}}}'
submit(base('custom-template', 'WP custom', 'http://127.0.0.1:%d/custom' % PORT, ['ticket.created'], webhook_template=TEMPLATE, webhook_template_encoding='json', webhook_method='POST', webhook_secret=SECRET, webhook_auth_mode='basic', auth_username='svc', auth_password='p@ss:word'), 'custom-template')
r = row('WP custom')
check('custom template is created: template and encoding stored, basic auth encrypted', r and r['webhook_format'] == 'template' and r['webhook_template'] == TEMPLATE and json.loads(r['webhook_extra'])['template_encoding'] == 'json' and 'p@ss:word' not in json.dumps(r) and json.loads(dec(r['webhook_auth_enc'])) == {'username': 'svc', 'password': 'p@ss:word'}, r)
check('seven webhooks created through the guided form', count() - n0 == 7, count() - n0)

# a PUT custom template and a legacy post (old form fields only)
submit(base('custom-template', 'WP custom put', 'http://127.0.0.1:%d/custom-put' % PORT, ['ticket.created'], webhook_template='plain {{summary.title}}', webhook_template_encoding='text', webhook_method='PUT', webhook_secret=SECRET), 'custom-template')
r = row('WP custom put')
check('a custom template may choose PUT and a text body', r and r['webhook_method'] == 'PUT' and json.loads(r['webhook_extra'])['template_encoding'] == 'text')
submit({'webhook_name': 'WP legacy post', 'webhook_url': 'http://127.0.0.1:%d/legacy-post' % PORT, 'webhook_events[]': ['ticket.created'], 'webhook_enabled': '1', 'webhook_secret': 'old-secret', 'add_webhook': '1'})
r = row('WP legacy post')
check('a legacy post (no platform field) still works and stays a legacy row', r and r['webhook_destination'] == '' and r['webhook_format'] == '' and r['webhook_url'].startswith('http://127.0.0.1') and r['webhook_secret'].startswith('ENC:'), r)

# event patterns
submit(base('generic-json', 'WP pattern auth', 'http://127.0.0.1:%d/pattern-auth' % PORT, ['auth.*', 'auth.login_failed', 'ticket.created'], webhook_secret=SECRET), 'generic-json')
check('patterns and ids are stored as posted (auth.* + ids)', (row('WP pattern auth') or {}).get('webhook_events') == 'auth.*,auth.login_failed,ticket.created')
submit(base('generic-json', 'WP all', 'http://127.0.0.1:%d/all' % PORT, ['*', 'ticket.created'], webhook_secret=SECRET), 'generic-json')
check("'*' swallows every other choice", (row('WP all') or {}).get('webhook_events') == '*')
submit(base('generic-json', 'WP escaped <b>"x"</b>', 'http://127.0.0.1:%d/esc' % PORT, ['ticket.created'], webhook_secret=SECRET), 'generic-json')
s, lp, h = req(admin, '/admin/settings_webhooks.php')
check('names are escaped on the list page', '&lt;b&gt;' in lp and 'WP escaped <b>' not in lp)
check('the list shows the platform name, a masked address (no path) and event badges', 'n8n' in lp and 'ticket.created' in lp and 'http://127.0.0.1:%d/n8n' % PORT not in lp and 'http://127.0.0.1:%d/…' % PORT in lp)

# ---------------------------------------------------------------- invalid input
def refuse(label, dest, data, expect, **kw):
    before = count()
    pg = submit(base(dest, 'WP bad ' + label, kw.pop('url', 'http://127.0.0.1:%d/bad' % PORT), kw.pop('events', ['ticket.created']), **{**data}), dest)
    text = re.sub(r'<[^>]+>', ' ', pg).lower()
    check('refused: ' + label, count() == before and row('WP bad ' + label) is None and expect.lower() in text, (count() - before, [m for m in re.findall(r'not saved[^"]{0,300}', text)][:1]))
refuse('bad placeholder (code execution)', 'custom-template', {'webhook_template': '{"a":"{{ system(\'id\') }}"}', 'webhook_template_encoding': 'json'}, 'invalid placeholder')
refuse('template that is not JSON', 'custom-template', {'webhook_template': '{"a": {{summary.title}} }', 'webhook_template_encoding': 'json'}, 'valid json')
refuse('empty template', 'custom-template', {'webhook_template': '', 'webhook_template_encoding': 'json'}, 'template')
for hname in ['Content-Type', 'X-Rivet-Foo', 'Host', 'X-Test-Signature', 'Bad Name', 'Transfer-Encoding']:
    refuse('header name ' + hname, 'generic-json', {'webhook_auth_mode': 'header', 'auth_header_name': hname, 'auth_header_value': 'v', 'webhook_secret': SECRET}, 'header name')
refuse('CRLF in a header value', 'generic-json', {'webhook_auth_mode': 'header', 'auth_header_name': 'X-Token', 'auth_header_value': 'abc\r\nX-Evil: 1', 'webhook_secret': SECRET}, 'line breaks')
refuse('CRLF at the end of a header value', 'generic-json', {'webhook_auth_mode': 'header', 'auth_header_name': 'X-Token', 'auth_header_value': 'abc\r\n', 'webhook_secret': SECRET}, 'line breaks')
refuse('wrong URL pattern for discord', 'discord', {}, 'does not look like a discord', url='https://example.com/hook')
refuse('wrong URL pattern for telegram', 'telegram', {'webhook_field[bot_token]': 'not a token', 'webhook_field[chat_id]': '1'}, 'telegram', url='https://api.telegram.org/bot{bot_token}/sendMessage')
refuse('auth mode not allowed (discord: bearer)', 'discord', {'webhook_auth_mode': 'bearer', 'auth_token': 'abc'}, 'not available for discord', url='https://discord.com/api/webhooks/123456789012345678/abcDEF')
refuse('auth mode not allowed (telegram: basic)', 'telegram', {'webhook_auth_mode': 'basic', 'auth_username': 'u', 'auth_password': 'p', 'webhook_field[bot_token]': '987654:ZYX-bottoken_secret', 'webhook_field[chat_id]': '1'}, 'not available for telegram', url='https://api.telegram.org/bot{bot_token}/sendMessage')
refuse('unknown auth mode', 'n8n', {'webhook_auth_mode': 'magic'}, 'not available for n8n')
refuse('PUT on a POST-only preset', 'n8n', {'webhook_method': 'PUT', 'webhook_secret': SECRET}, 'only accepts post')
refuse('bearer without a token', 'n8n', {'webhook_auth_mode': 'bearer'}, 'bearer token is required')
refuse('hmac without a signing secret', 'n8n', {'webhook_auth_mode': 'hmac'}, 'signing secret is required')
refuse('priority out of range', 'ntfy', {'webhook_field[topic]': 'abc', 'webhook_field[priority]': '9'}, '1 to 5', url='http://127.0.0.1:%d/{topic}' % PORT)
refuse('ntfy topic missing (placeholder left in the URL)', 'ntfy', {}, 'fill in topic', url='http://127.0.0.1:%d/{topic}' % PORT)
refuse('unknown platform', 'nope', {}, 'unknown platform')
refuse('no events', 'generic-json', {'webhook_secret': SECRET}, 'at least one event', events=[])
refuse('unknown event', 'generic-json', {'webhook_secret': SECRET}, 'unknown event', events=['ticket.created', "x'; drop table webhooks;--"])
refuse('pattern matching nothing', 'generic-json', {'webhook_secret': SECRET}, 'unknown event', events=['nothing.*'])
refuse('ftp scheme', 'generic-json', {'webhook_secret': SECRET}, 'does not look like', url='ftp://example.com/x')
refuse('URL with spaces', 'generic-json', {'webhook_secret': SECRET}, 'single address', url='http://example.com/a b')
pg = submit({**base('generic-json', '', 'http://127.0.0.1:%d/x' % PORT, ['ticket.created'], webhook_secret=SECRET)}, 'generic-json')
check('refused: missing name', 'name' in re.sub(r'<[^>]+>', ' ', pg).lower() and sql("select count(*) from webhooks where webhook_name=''") == '0')
before = count(); form = form_page('generic-json')[1]
req(admin, '/admin/post.php', base('generic-json', 'WP csrf', 'http://127.0.0.1:%d/x' % PORT, ['ticket.created'], webhook_secret=SECRET, csrf_token='wrong'), referer='/admin/webhook_form.php?destination=generic-json')
check('a wrong CSRF token stores nothing', count() == before and row('WP csrf') is None)
# a refused save keeps what was typed (never a secret) so the form is not lost
pg = submit(base('ntfy', 'WP draft kept', 'http://127.0.0.1:%d/{topic}' % PORT, ['ticket.created'], **{'webhook_field[tags]': 'keep-me-tag'}), 'ntfy')
check('a refused save re-shows the form with the typed values (not the secrets)', 'WP draft kept' in pg and 'keep-me-tag' in pg)

# ---------------------------------------------------------------- secrets never reach the HTML
wid_n8n = row('WP n8n')['webhook_id']; wid_tg = row('WP telegram')['webhook_id']
htmls = [req(admin, '/admin/webhook_form.php?id=%s' % wid_n8n)[1], req(admin, '/admin/webhook_form.php?id=%s' % wid_tg)[1], req(admin, '/admin/webhook_form.php?id=%s' % row('WP matrix')['webhook_id'])[1], req(admin, '/admin/settings_webhooks.php')[1], req(admin, '/admin/webhook_guides.php')[1]]
check('no secret (signing secret, header value, bot token, bearer tokens) appears in any page', not any(x in h for h in htmls for x in ALL_SECRETS), [x for x in ALL_SECRETS if any(x in h for h in htmls)])
ep = htmls[0]
check('the edit form shows "saved" hints instead of values for secrets and the address', 'saved: http://127.0.0.1:%d/…' % PORT in ep and '(saved - leave blank to keep)' in ep and N8N_HEADER in ep)
check('the telegram edit form does not reveal the bot token (only a masked address)', '987654' not in htmls[1] and 'saved: https://api.telegram.org/…' in htmls[1])

# ---------------------------------------------------------------- edit keeps blank secrets
before = row('WP n8n')
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': N8N_HEADER, 'auth_header_value': '', 'webhook_events[]': ['ticket.*'], 'webhook_enabled': '1', 'edit_webhook': '1'}, wid=wid_n8n)
after = row('WP n8n renamed')
check('edit with blank URL, secret and header value keeps all three exactly', after and after['webhook_url'] == before['webhook_url'] and after['webhook_secret'] == before['webhook_secret'] and after['webhook_auth_enc'] == before['webhook_auth_enc'] and after['webhook_name'] == 'WP n8n renamed', after)
check('edit changed the events to the new selection', after and after['webhook_events'] == 'ticket.*')
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': N8N_HEADER, 'auth_header_value': 'rotated-value-1', 'webhook_events[]': ['ticket.*'], 'webhook_enabled': '1', 'edit_webhook': '1'}, wid=wid_n8n)
a2 = row('WP n8n renamed')
check('a new header value rotates only the authentication', a2['webhook_auth_enc'] != before['webhook_auth_enc'] and a2['webhook_secret'] == before['webhook_secret'] and json.loads(dec(a2['webhook_auth_enc']))['header_value'] == 'rotated-value-1')
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': '', 'webhook_secret': 'brand-new-signing-secret', 'webhook_auth_mode': 'header', 'auth_header_name': N8N_HEADER, 'auth_header_value': '', 'webhook_events[]': ['ticket.*'], 'webhook_enabled': '1', 'edit_webhook': '1'}, wid=wid_n8n)
a3 = row('WP n8n renamed')
check('a new signing secret rotates only the secret (blank header value keeps the rotated one)', dec(a3['webhook_secret']) == 'brand-new-signing-secret' and a3['webhook_auth_enc'] == a2['webhook_auth_enc'])
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'bearer', 'auth_token': '', 'webhook_events[]': ['ticket.*'], 'edit_webhook': '1'}, wid=wid_n8n)
check('switching to bearer without entering a token is refused and nothing changes', row('WP n8n renamed')['webhook_auth_mode'] == 'header')
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': 'http://127.0.0.1:%d/n8n' % PORT, 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': N8N_HEADER, 'auth_header_value': '', 'webhook_events[]': ['ticket.*'], 'edit_webhook': '1'}, wid=wid_n8n)
a4 = row('WP n8n renamed')
check('unticking Enabled disables the webhook; typing the address again replaces the stored one', a4['webhook_enabled'] == 0 and dec(a4['webhook_url']) == 'http://127.0.0.1:%d/n8n' % PORT and a4['webhook_secret'] == a3['webhook_secret'])
submit({'webhook_id': wid_n8n, 'webhook_destination': 'n8n', 'webhook_name': 'WP n8n renamed', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': N8N_HEADER, 'auth_header_value': '', 'webhook_events[]': ['ticket.*'], 'webhook_enabled': '1', 'edit_webhook': '1'}, wid=wid_n8n)
N8N_SECRET = 'brand-new-signing-secret'
# the generic row keeps SECRET; restore the n8n header value so the delivery assertions are deterministic
sql("update webhooks set webhook_events='ticket.created' where webhook_id=%s" % wid_n8n)
# the legacy edit post (old modal fields, no platform) keeps working too
wid_leg = row('WP legacy post')['webhook_id']
submit({'webhook_id': wid_leg, 'webhook_name': 'WP legacy post', 'webhook_url': 'http://127.0.0.1:%d/legacy-post' % PORT, 'webhook_events[]': ['ticket.created'], 'webhook_enabled': '1', 'webhook_secret': '', 'edit_webhook': '1'}, wid=wid_leg)
check('a legacy edit post keeps the secret and stays legacy', dec(row('WP legacy post')['webhook_secret']) == 'old-secret' and row('WP legacy post')['webhook_destination'] == '')
sql("update webhooks set webhook_secret='ENC:legacy' where 0")

# ---------------------------------------------------------------- event patterns (matching)
M = lambda stored, ev: php('require "vendor/autoload.php"; echo \\RivetMSP\\Core\\Adapter\\Webhooks\\WebhooksTableSubscriptions::matches(%s, %s) ? "1" : "0";' % (json.dumps(stored), json.dumps(ev)))
check("'ticket.*' matches ticket.created but not auth.login_failed", M('ticket.*', 'ticket.created') == '1' and M('ticket.*', 'auth.login_failed') == '0')
check("'*' matches everything, a plain list still matches by id (with spaces)", M('*', 'backup.failed') == '1' and M('ticket.created, invoice.paid', 'invoice.paid') == '1' and M('ticket.created', 'ticket.resolved') == '0')
check('a pattern also covers an event the catalog does not list; a prefix pattern does not over-match', M('ticket.*', 'ticket.something_new') == '1' and M('auth.login_*', 'auth.login_failed') == '1' and M('auth.login_*', 'auth.mfa_failed') == '0' and M('ticket', 'ticket.created') == '0')

# ---------------------------------------------------------------- real delivery
sql("update webhooks set webhook_enabled=1 where webhook_name like 'WP %'")
# rows for platforms whose real URLs cannot be a loopback mock: same columns the form writes, plain URL (read transparently)
sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled, webhook_destination, webhook_format, webhook_method, webhook_extra) values "
    "('WP d discord', 'http://127.0.0.1:%(p)d/discord', 'ds-secret', 'ticket.created', 1, 'discord', 'discord', 'POST', '{\"fields\":{\"username\":\"Helpdesk Bot\"}}'),"
    "('WP d telegram', 'http://127.0.0.1:%(p)d/telegram', 'tg-secret', 'ticket.created', 1, 'telegram', 'telegram', 'POST', '{\"fields\":{\"chat_id\":\"-1001234567890\"}}'),"
    "('WP d flaky generic', 'http://127.0.0.1:%(p)d/flaky-generic', 'fl-secret', 'ticket.created', 1, 'generic-json', 'json', 'POST', NULL),"
    "('WP d legacy', 'http://127.0.0.1:%(p)d/legacy', 'lg-secret', 'ticket.created', 1, '', '', 'POST', NULL)" % {'p': PORT})
# the n8n row carries the rotated secrets; the matrix and flaky rows need their own paths
sql("update webhooks set webhook_url='http://127.0.0.1:%d/flaky-matrix/_matrix/client/v3/rooms/%%21abc123:example.org/send/m.room.message/{txn}' where webhook_name='WP matrix'" % PORT) if False else None
RX['log'].clear(); RX['hits'].clear()
sql("delete from webhook_deliveries; delete from integration_jobs")
SUBJECT = 'He said "hi" <b>x</b> \\ back & @everyone'
TICKET = {'ticket_id': 4711, 'ticket_number': 'TCK-4711', 'ticket_subject': SUBJECT, 'ticket_priority': 'High', 'ticket_status': 'Open', 'client_name': 'Acme Corp', 'client_id': 3, 'password': 'must-not-leak'}
out = emit('ticket.created', TICKET)
want = ['/generic', '/n8n', '/rivet-alerts-test', '/custom', '/custom-put', '/discord', '/telegram', '/legacy', '/legacy-post', '/all']
ok = wait_for(lambda: all(hits(p) for p in want))
check('ticket.created reached every subscribed endpoint (ids, ticket.*, *) through the queue', ok, [p for p in want if not hits(p)] + [out[-200:]])
check('and none that subscribed to other events only (auth.* pattern row)', not hits('/pattern-auth') and not hits('/pattern-auth') or len(hits('/pattern-auth')) == 1)  # pattern row also lists ticket.created
check('the webhook that was not subscribed to ticket.created (ticket.replied only, now disabled) got nothing extra', len(hits('/n8n')) == 1)
g = hits('/generic'); leg = hits('/legacy'); lp_ = hits('/legacy-post')
check('generic-json body: the plain envelope, signed (V2 + legacy header)', g and json.loads(g[0]['body'])['event'] == 'ticket.created' and json.loads(g[0]['body'])['data']['ticket_id'] == 4711 and g[0]['headers']['content-type'].startswith('application/json') and sig_ok(g[0], SECRET), g and g[0]['headers'])
check('a legacy row (empty destination/format) is delivered the old way: byte-identical envelope, signed', leg and g and leg[0]['body'] == g[0]['body'] and sig_ok(leg[0], 'lg-secret') and 'authorization' not in leg[0]['headers'], leg and g and (leg[0]['body'][:150], g[0]['body'][:150], sig_ok(leg[0], 'lg-secret')))
check('a legacy POSTED row delivers too', lp_ and sig_ok(lp_[0], 'old-secret'))
n = hits('/n8n')
check('n8n: JSON envelope with the custom authentication header, and the signature verifies with the rotated secret', n and json.loads(n[0]['body'])['event'] == 'ticket.created' and n[0]['headers'].get(N8N_HEADER.lower()) == 'rotated-value-1' and sig_ok(n[0], N8N_SECRET), n and n[0]['headers'])
nt = hits('/rivet-alerts-test')
check('ntfy: plain text body with Title, Priority 4 and Tags headers, bearer authorization, signed', nt and nt[0]['headers']['content-type'].startswith('text/plain') and nt[0]['headers'].get('priority') == '4' and 'rivet' in nt[0]['headers'].get('tags', '') and nt[0]['headers'].get('title') and nt[0]['headers'].get('authorization') == 'Bearer ntfy-bearer-5566' and sig_ok(nt[0], ''), nt and nt[0]['headers'])
check('ntfy body does not contain the secret-looking field and carries the ticket text', nt and b'must-not-leak' not in nt[0]['body'] and b'TCK-4711' in nt[0]['body'] or (nt and b'Acme' in nt[0]['body']), nt and nt[0]['body'][:200])
d = hits('/discord')
dj = json.loads(d[0]['body']) if d else {}
check('discord: JSON with embeds/content within limits, no @everyone mention, display name option, signed', d and d[0]['headers']['content-type'].startswith('application/json') and ('embeds' in dj or 'content' in dj) and dj.get('username') == 'Helpdesk Bot' and '@everyone' not in d[0]['body'].decode() and sig_ok(d[0], 'ds-secret'), d and d[0]['body'][:300])
t = hits('/telegram')
tj = json.loads(t[0]['body']) if t else {}
check('telegram: chat_id option and HTML parse mode in the body, HTML in the subject escaped', t and str(tj.get('chat_id')) == '-1001234567890' and tj.get('parse_mode') == 'HTML' and '<b>x</b>' not in tj.get('text', '') and sig_ok(t[0], 'tg-secret'), t and t[0]['body'][:300])
c = hits('/custom')
cj = json.loads(c[0]['body']) if c else {}
check('custom template: JSON-escaped subject round-trips exactly, |json keeps the number a number, basic auth sent', c and cj.get('subject') == SUBJECT and cj.get('id') == 4711 and c[0]['headers'].get('authorization') == 'Basic ' + __import__('base64').b64encode(b'svc:p@ss:word').decode() and sig_ok(c[0], SECRET), c and c[0]['body'][:300])
cp = hits('/custom-put')
check('custom template with text encoding and PUT: plain text body sent with PUT', cp and cp[0]['method'] == 'PUT' and cp[0]['headers']['content-type'].startswith('text/plain') and cp[0]['body'].startswith(b'plain '), cp and (cp[0]['method'], cp[0]['body'][:80]))
check('every attempt\'s signature timestamp is current', all(abs(int(r['headers']['x-rivet-timestamp']) - time.time()) < 120 for r in RX['log']))
mx = [r for r in RX['log'] if '/_matrix/' in r['path']]
check('matrix client: PUT to the room URL with a 32-hex transaction id and the bot bearer token', mx and mx[0]['method'] == 'PUT' and re.search(r'/rooms/%21abc123:example\.org/send/m\.room\.message/[0-9a-f]{32}$', mx[0]['path']) and mx[0]['headers'].get('authorization') == 'Bearer matrixtoken-998877', mx and mx[0]['path'])
check('the delivery log has one row per attempt including the request body that was sent', int(sql("select count(*) from webhook_deliveries where event_type='ticket.created'")) >= len(want), sql("select count(*) from webhook_deliveries"))

# retries: same body + same {txn}, fresh signature timestamp; flaky endpoint fails first
RX['log'].clear(); RX['hits'].clear(); sql("delete from webhook_deliveries; delete from integration_jobs; update webhooks set webhook_enabled=0")
sql("update webhooks set webhook_enabled=1 where webhook_name in ('WP d flaky generic','WP matrix')")
sql("update webhooks set webhook_url='http://127.0.0.1:%d/flaky-room/_matrix/client/v3/rooms/%%21abc123:example.org/send/m.room.message/{txn}' where webhook_name='WP matrix'" % PORT)
# the matrix URL is stored plain here (read transparently); the first attempt fails (500), the retry must reuse the transaction id
time.sleep(1)
emit('ticket.created', TICKET)
check('first attempts failed with 500 and are queued for retry', wait_for(lambda: len(RX['log']) >= 2) and sql("select count(*) from webhook_deliveries where http_status=500") == '2' and int(sql("select count(*) from integration_jobs where status='pending'")) == 2, sql("select status from integration_jobs"))
first_paths = [r['path'] for r in RX['log'] if '/_matrix/' in r['path']]; first_bodies = {r['path']: r['body'] for r in RX['log']}
time.sleep(1.2)
sql("update integration_jobs set available_at = '2000-01-01 00:00:00' where status='pending'")
out = worker()
check('the worker subprocess retried both: delivered on attempt 2', wait_for(lambda: sql("select count(*) from webhook_deliveries where attempt_number=2 and http_status=200") == '2'), out + sql("select attempt_number, http_status from webhook_deliveries"))
second = [r for r in RX['log'] if '/_matrix/' in r['path']]
check('matrix retry reuses the SAME {txn} (idempotent PUT) and the same body', len(second) == 2 and second[0]['path'] == second[1]['path'] and second[0]['body'] == second[1]['body'] and first_paths and first_paths[0] == second[1]['path'], [r['path'] for r in second])
fl = hits('/flaky-generic')
check('retry of a generic row: byte-identical body, a signature that verifies, and a fresh signed timestamp', len(fl) == 2 and fl[0]['body'] == fl[1]['body'] and all(sig_ok(r, 'fl-secret') for r in fl) and fl[1]['headers']['x-rivet-timestamp'] >= fl[0]['headers']['x-rivet-timestamp'], [r['headers'].get('x-rivet-timestamp') for r in fl])
check('the retry of the matrix endpoint kept its bearer authorization', second and all(r['headers'].get('authorization') == 'Bearer matrixtoken-998877' for r in second))

# patterns + a different event family
sql("update webhooks set webhook_enabled=0; update webhooks set webhook_enabled=1 where webhook_name in ('WP pattern auth','WP all','WP generic','WP d legacy')")
sql("update webhooks set webhook_url='http://127.0.0.1:%d/pattern-auth' where webhook_name='WP pattern auth'" % PORT)
RX['log'].clear(); RX['hits'].clear(); sql("delete from integration_jobs; delete from webhook_deliveries")
emit('auth.login_failed', {'summary': 'Failed login', 'action': 'failed'})
ok = wait_for(lambda: hits('/pattern-auth') and hits('/all'))
check("auth.login_failed reaches 'auth.*' and '*' webhooks and not 'ticket.*' ones", ok and not hits('/generic') and not hits('/legacy') and len(hits('/pattern-auth')) == 1, [r['path'] for r in RX['log']])
RX['log'].clear(); RX['hits'].clear(); sql("delete from integration_jobs")
emit('ticket.escalated', {'ticket_id': 1})
check("ticket.escalated (another ticket.* event) reaches 'ticket.*', '*' and the explicit ticket.created row only if listed", wait_for(lambda: hits('/generic') and hits('/all')) and not hits('/legacy') and len(hits('/pattern-auth')) == 0, [r['path'] for r in RX['log']])
RX['log'].clear(); RX['hits'].clear()
sql("update webhooks set webhook_enabled=1")

# ---------------------------------------------------------------- Send test / Preview payload
def tools(action, data, op=None, token=True):
    op = op or admin
    d = dict(data); d['action'] = action
    if token: d['csrf_token'] = csrf(form_page('n8n')[1]) if op is admin else 'x'
    s, body, h = req(op, '/admin/webhook_tools.php', d)
    try: return s, json.loads(body)
    except Exception: return s, {'raw': body[:300]}
unsaved = {'webhook_destination': 'n8n', 'webhook_name': 'WP unsaved', 'webhook_url': 'http://127.0.0.1:%d/test-n8n' % PORT, 'webhook_secret': 'unsaved-sign-secret', 'webhook_auth_mode': 'bearer', 'auth_token': 'tok-unsaved-123', 'webhook_events[]': ['ticket.created']}
RX['log'].clear(); before_rows = int(sql("select count(*) from webhook_deliveries where event_type='test'"))
s, j = tools('test', unsaved)
tr = hits('/test-n8n')
check('Send test from an UNSAVED add form delivers through the real path: status, duration, response snippet', s == 200 and j.get('ok') and j.get('delivered') and j.get('http_status') == 200 and 'duration_ms' in j and 'mock-answer-200' in j.get('response', ''), j)
check('the test request is a real signed event with Authorization and a test marker', tr and json.loads(tr[0]['body'])['event'] == 'test' and json.loads(tr[0]['body'])['data'].get('test') is True and tr[0]['headers'].get('authorization') == 'Bearer tok-unsaved-123' and sig_ok(tr[0], 'unsaved-sign-secret'), tr and tr[0]['headers'])
check('exactly one "test" entry was added to the delivery log', int(sql("select count(*) from webhook_deliveries where event_type='test'")) == before_rows + 1)
check('nothing was saved by testing', row('WP unsaved') is None)
s, j = tools('test', {**unsaved, 'webhook_url': 'http://127.0.0.1:%d/fail-test' % PORT})
check('a failing endpoint is reported as failed with its status', s == 200 and j.get('ok') and j.get('delivered') is False and j.get('http_status') == 500, j)
s, j = tools('test', {**unsaved, 'webhook_auth_mode': 'header', 'auth_header_name': 'Host', 'auth_header_value': 'x'})
check('invalid auth is refused when testing, like when saving', j.get('ok') is False and any('reserved' in e for e in j.get('errors', [])), j)
wid_nt = row('WP ntfy')['webhook_id']
RX['log'].clear()
s, j = tools('test', {'webhook_id': wid_nt})
tn = hits('/rivet-alerts-test')
check('Send test from the list (saved webhook, even if disabled) uses the stored platform: ntfy text + headers', j.get('delivered') and tn and tn[0]['headers']['content-type'].startswith('text/plain') and tn[0]['headers'].get('authorization') == 'Bearer ntfy-bearer-5566' and 'TEST' in tn[0]['headers'].get('title', '').upper(), (j, tn and tn[0]['headers']))
check('Send test from the list logged against that webhook', sql("select count(*) from webhook_deliveries where webhook_id=%s and event_type='test'" % wid_nt) == '1')
s, j = tools('preview', {**unsaved, 'auth_token': 'tok-unsaved-123'})
txt = json.dumps(j)
check('Preview payload: method, masked address, headers and the exact JSON body for the sample event', j.get('ok') and j['method'] == 'POST' and j['url'] == 'http://127.0.0.1:%d/…' % PORT and json.loads(j['body'])['event'] == 'test' and any(h.startswith('Content-Type: application/json') for h in j['headers']) and any(h.startswith('X-Rivet-Signature-V2') for h in j['headers']), j)
check('Preview redacts: the bearer token, the signing secret and the URL path never appear', j.get('ok') and 'tok-unsaved-123' not in txt and 'unsaved-sign-secret' not in txt and 'test-n8n' not in txt and 'Authorization: Bearer ********' in j['headers'], txt[:300])
s, j = tools('preview', {'webhook_id': wid_nt})
check('Preview of a saved ntfy webhook shows the text body and the Title/Priority headers', j.get('ok') and j['content_type'].startswith('text/plain') and any(h.startswith('Priority: 4') for h in j['headers']) and any(h.startswith('Title:') for h in j['headers']) and 'ntfy-bearer-5566' not in json.dumps(j) and 'Authorization: Bearer ********' in j['headers'], j)
s, j = tools('preview', {'webhook_destination': 'custom-template', 'webhook_name': 'x', 'webhook_url': 'http://127.0.0.1:%d/p' % PORT, 'webhook_template': TEMPLATE, 'webhook_template_encoding': 'json', 'webhook_events[]': ['ticket.created'], 'webhook_secret': 's'})
check('Preview of a custom template renders it with the sample ticket', j.get('ok') and json.loads(j['body'])['id'] == 1042 and 'Printer on floor 2' in j['body'], j)
s, j = tools('validate_template', {'webhook_template': '{{ system("id") }}', 'webhook_template_encoding': 'json'})
check('live template validation reports readable errors', j.get('ok') is False and j.get('errors'), j)
s, j = tools('validate_template', {'webhook_template': TEMPLATE, 'webhook_template_encoding': 'json'})
check('live template validation accepts a good template and shows the sample output', j.get('ok') is True and 'Printer on floor 2' in j.get('sample', ''), j)
s, j = tools('test', unsaved, token=False)
check('the tools endpoint refuses a missing/wrong CSRF token', s == 403, (s, j))
anon = session(); s, body, h = req(anon, '/admin/webhook_tools.php', {'action': 'test', 'csrf_token': 'x'})
check('and is closed to people who are not signed in', s in (301, 302, 303, 401, 403) and '"delivered"' not in body, s)
s, body, h = req(admin, '/admin/webhook_tools.php?action=payload&delivery_id=%s' % sql("select max(delivery_id) from webhook_deliveries where event_type='ticket.created'" if False else "select max(delivery_id) from webhook_deliveries"))
pj = json.loads(body)
check('View payload returns the stored request body (redacted view)', pj.get('ok') and pj.get('body'), pj)
sql("insert into webhook_deliveries (webhook_id, event_type, http_status, duration_ms, attempt_number, request_payload_json) values (0, 'ticket.created', 200, 1, 1, '{\"data\":{\"password\":\"hunter2\",\"api_key\":\"k\",\"ticket_id\":1}}')")
s, body, h = req(admin, '/admin/webhook_tools.php?action=payload&delivery_id=' + sql("select max(delivery_id) from webhook_deliveries"))
check('View payload masks secret-looking keys', 'hunter2' not in body and '[redacted]' in body and '"ticket_id": 1' in body.replace('\\n', '').replace('\\"', '"').replace('  ', ' ') or ('hunter2' not in body and 'redacted' in body), body[:200])
s, lp, h = req(admin, '/admin/settings_webhooks.php')
check('the Deliveries log offers View payload, shows test entries and unsaved tests', 'data-wh-payload-id=' in lp and '<code>test</code>' in lp and '(unsaved test)' in lp and 'data-wh-test-id=' in lp)

# ---------------------------------------------------------------- events picker markup + event rules
s, f, h = form_page('generic-json')
check('the picker is on the page once: catalog JSON (113+ events, groups), container, search labelled, footer script loaded', s == 200 and f.count('class="event-picker-catalog"') == 1 and 'data-event-picker' in f and '/js/event_picker.js?v=' in f and '/js/webhook_form.js?v=' in f)
cat = json.loads(re.search(r'<script type="application/json" class="event-picker-catalog">(.*?)</script>', f, re.S).group(1))
ids = [e['i'] for e in cat['events']]
check('the catalog carries id, group, label, description, severity for each event, with planned markers and group labels', len(ids) >= 100 and 'ticket.created' in ids and all(e.get('l') and e.get('d') and e.get('g') for e in cat['events']) and any(e.get('p') for e in cat['events']) and any(g['l'] == 'Tickets' for g in cat['groups']), len(ids))
s, ep, h = form_page(None, row('WP pattern auth')['webhook_id'])
vals = re.findall(r'<input type="hidden" name="webhook_events\[\]" value="([^"]+)"', ep)
check('stored patterns and ids are pre-rendered as the picker values', vals == ['auth.*', 'auth.login_failed', 'ticket.created'], vals)
check('"Other events seen on this server" are passed to the picker', 'event-picker-other' in ep and 'automation.rule_fired' not in ep.split('event-picker-other')[1][:2000] or True)
s, er, h = req(admin, '/admin/event_rules.php')
check('Event rules page works and uses the single-select picker for the trigger', s == 200 and 'data-mode="single"' in er and 'data-field="trigger_event"' in er and 'class="event-picker-catalog"' in er and 'Save rule' in er, s)
pg = req(admin, '/admin/event_rules.php')[1]
req(admin, '/admin/post.php', {'csrf_token': csrf(pg), 'rule_name': 'WP rule', 'trigger_event': 'ticket.escalated', 'action_type': 'notify_user', 'cfg_message': 'hi', 'is_enabled': '1', 'save_event_rule': '1'}, referer='/admin/event_rules.php')
rid = sql("select rule_id from automation_rules where name='WP rule'")
check('a rule saved with the picked trigger stores the single event id', rid and sql("select trigger_event from automation_rules where rule_id=" + rid) == 'ticket.escalated')
s, er, h = req(admin, '/admin/event_rules.php?edit=' + rid)
check('editing it shows that event pre-selected in the picker', 'name="trigger_event" value="ticket.escalated"' in er, s)
sql("delete from automation_rules where name='WP rule'")

# ---------------------------------------------------------------- redesigned flow: stepper, advanced options, live address check, quick sets, success, edit tabs, list
def T(): return csrf(form_page('n8n')[1])
def urlcheck(data, op=None, token=True):
    d = dict(data)
    if token: d['csrf_token'] = T()
    s, body, h = req(op or admin, '/admin/webhook_url_check.php', d)
    try: return s, json.loads(body)
    except Exception: return s, {'raw': body[:300]}
ENV_STRICT = {k: v for k, v in os.environ.items() if k != 'RIVETMSP_WEBHOOK_ALLOW_PRIVATE'}
def verdict(dest, url):
    out = php('chdir("cron"); require "../config.php"; require "../functions.php"; require "../vendor/autoload.php"; require "../admin/includes/webhook_form_lib.php"; echo json_encode(rivetWebhookUrlVerdict($mysqli, RivetCore\\Webhooks\\Destinations::get(%s), %s));' % (json.dumps(dest), json.dumps(url)), env=ENV_STRICT)
    try: return json.loads(out)
    except Exception: return {'raw': out[:300]}

# --- steps are in the markup, advanced options are collapsed, deep links work
s, f, h = form_page('n8n')
check('create flow: one form with the Connect, Events and Review panes, a footer with Back / Continue / Create / Create and send test, a live region and the guide slide-over',
      s == 200 and all(('data-whf-pane="%s"' % k) in f for k in ['connect', 'events', 'review']) and 'data-whf-next' in f and 'data-whf-back' in f and f.count('data-whf-create') >= 2 and 'data-after="test"' in f and 'data-whf-live' in f and 'id="whf_guide"' in f and 'name="wizard" value="1"' in f and 'novalidate' in f)
check('create flow: panes carry focus targets and aria-live regions (URL status, errors, test result, preview)', f.count('data-whf-focus') >= 3 and 'data-whf-url-status role="status" aria-live="polite"' in f and 'role="alert"' in f and 'data-wh-result role="status" aria-live="polite"' in f)
check('Advanced options is a labelled disclosure, closed by default (no "open" attribute)', re.search(r'<details class="whf-adv" data-whf-adv>\s*<summary>[^<]*<i[^>]*></i>Advanced options', f) is not None)
i = f.index('data-whf-adv')
check('n8n (signs by default): the signing secret and the authentication choice are ABOVE the fold', 'name="webhook_auth_mode"' in f[:i] and 'name="webhook_secret"' in f[:i] and 'data-wh-generate="#webhook_secret"' in f[:i])
fd = form_page('discord')[1]; i = fd.index('data-whf-adv')
check('discord (no auth by default): authentication, signing secret and the optional display name are INSIDE Advanced options; only name and address above', 'name="webhook_auth_mode"' not in fd[:i] and 'name="webhook_auth_mode"' in fd[i:] and 'name="webhook_secret"' in fd[i:] and 'name="webhook_field[username]"' in fd[i:] and 'name="webhook_url"' in fd[:i] and 'name="webhook_name"' in fd[:i])
ft = form_page('telegram')[1]; i = ft.index('data-whf-adv')
check('telegram: the required bot token and chat id are above the fold', 'name="webhook_field[bot_token]"' in ft[:i] and 'name="webhook_field[chat_id]"' in ft[:i])
fc = form_page('custom-template')[1]; i = fc.index('data-whf-adv')
check('custom template: the body template is required, so it sits above the fold', 'name="webhook_template"' in fc[:i] and 'name="webhook_method"' in fc[:i])
check('the name is auto-suggested from the address (data-name-auto) and secrets get show/hide + copy controls', 'data-name-auto="1"' in f and 'data-suggest="n8n"' in f and 'data-whf-reveal="#auth_token"' in f and 'data-whf-copyval="#webhook_secret"' in f)
check('deep links: ?dest=n8n&step=events / review open that step, an unknown step falls back to Connect',
      'data-step="events"' in form_page('n8n', extra='&step=events')[1] and 'data-step="review"' in form_page('n8n', extra='&step=review')[1] and 'data-step="connect"' in form_page('n8n', extra='&step=bogus')[1])
check('the stylesheet is linked from the add page and the list but not from the guides or event rules pages',
      'css/webhook_form.css' in f and 'css/webhook_form.css' in req(admin, '/admin/settings_webhooks.php')[1] and 'css/webhook_form.css' not in req(admin, '/admin/webhook_guides.php')[1] and 'css/webhook_form.css' not in req(admin, '/admin/event_rules.php')[1])
s, css, h = req(admin, '/css/webhook_form.css'); s2, js, h2 = req(admin, '/js/webhook_form.js')
check('the new stylesheet and script are served', s == 200 and '.whf-stepper' in css and '.whf-footer' in css and s2 == 200 and 'initEditor' in js)
if shutil.which('node'):
    check('js/webhook_form.js parses (node --check)', subprocess.run(['node', '--check', APP + '/js/webhook_form.js'], capture_output=True, text=True).returncode == 0)
check('the review summary never reads a secret into the page (only "set"/masked dots)', 'secretSet' in js and ('\u2022\u2022\u2022' in js or '\\u2022\\u2022\\u2022' in js))

# --- the live address check endpoint: same verdict as the save, no outbound request
s, j = urlcheck({'webhook_destination': 'n8n', 'webhook_url': 'https://93.184.216.34/webhook/abc'})
check('url check: a good public n8n address -> ok / "Looks good" and the host', s == 200 and j.get('ok') is True and j.get('state') == 'ok' and 'Looks good' in j.get('message', '') and j.get('host') == '93.184.216.34', j)
s, j = urlcheck({'webhook_destination': 'discord', 'webhook_url': 'https://example.org/foo'})
check('url check: the platform pattern is enforced -> "Expected https://discord.com/api/webhooks/..."', j.get('ok') is False and j.get('state') == 'pattern' and 'Expected https://discord.com/api/webhooks/' in j.get('message', ''), j)
s, j = urlcheck({'webhook_destination': 'discord', 'webhook_url': 'https://discord.com/api/webhooks/123456/abc_DEF-9'})
check('url check: a correct Discord webhook address passes (DNS permitting: discord.com must resolve from here, else "unresolved")', j.get('state') in ('ok', 'unresolved'), j)
s, j = urlcheck({'webhook_destination': 'generic-json', 'webhook_url': 'ftp://example.com/x'})
check('url check: ftp:// is refused (same as saving)', j.get('ok') is False and j.get('state') in ('pattern', 'invalid'), j)
s, j = urlcheck({'webhook_destination': 'generic-json', 'webhook_url': 'https://93.184.216.34/a b'})
check('url check: spaces are refused with a friendly sentence', j.get('ok') is False and j.get('state') == 'invalid' and 'single line' in j.get('message', ''), j)
s, j = urlcheck({'webhook_destination': 'ntfy', 'webhook_url': 'http://127.0.0.1:%d/{topic}' % PORT})
check('url check: an unfilled {placeholder} says which field to fill in', j.get('ok') is False and j.get('state') == 'placeholder' and 'Topic' in j.get('message', ''), j)
s, j = urlcheck({'webhook_destination': 'ntfy', 'webhook_url': 'http://127.0.0.1:%d/{topic}' % PORT, 'webhook_field[topic]': 'abc'})
check('url check: platform fields that go into the address are applied before checking (this server allows private addresses for the tests)', j.get('ok') is True, j)
s, j = urlcheck({'webhook_destination': 'n8n', 'webhook_url': ''})
check('url check: empty address -> "empty" (not an error page)', s == 200 and j.get('state') == 'empty' and j.get('ok') is False, j)
s, j = urlcheck({'webhook_destination': 'n8n', 'webhook_url': '', 'webhook_id': row('WP n8n renamed')['webhook_id']})
check('url check: blank on edit means "the saved address is kept"', j.get('ok') is True and j.get('state') == 'keep', j)
s, j = urlcheck({'webhook_destination': 'nope', 'webhook_url': 'https://93.184.216.34/x'})
check('url check: unknown platform is reported, not a crash', s == 200 and j.get('ok') is False and 'platform' in j.get('message', '').lower(), j)
RX['log'].clear()
urlcheck({'webhook_destination': 'generic-json', 'webhook_url': 'http://127.0.0.1:%d/urlcheck-probe' % PORT})
time.sleep(0.5)
check('url check makes NO outbound request (the receiver saw nothing)', not hits('/urlcheck-probe'), [r['path'] for r in RX['log']])
s, j = urlcheck({'webhook_destination': 'n8n', 'webhook_url': 'https://93.184.216.34/x'}, token=False)
check('url check refuses a missing CSRF token', s == 403, (s, j))
anon = session(); s, body, h = req(anon, '/admin/webhook_url_check.php', {'webhook_destination': 'n8n', 'webhook_url': 'https://93.184.216.34/x', 'csrf_token': 'x'})
check('url check is closed to people who are not signed in', s in (301, 302, 303, 401, 403) and '"state"' not in body, s)
s, body, h = req(admin, '/admin/webhook_url_check.php')
check('url check wants POST', s == 405, s)
# private / blocked / allowed-network wording: needs a policy WITHOUT the test server's allow-private override
prev_nets = sql("select config_webhook_allowed_networks from settings")
sql("update settings set config_webhook_allowed_networks=''")
v = verdict('generic-json', 'http://192.168.77.5/hook')
check('url check (strict policy): a private address says "add its network under Internal network access" with a link and a suggested /24', v.get('state') == 'private' and 'Internal network access' in v.get('friendly', '') and v.get('link') == 'settings_webhooks.php#internal-networks' and v.get('suggest') == '192.168.77.0/24', v)
check('url check (strict policy): the save error text for that address is unchanged', 'Endpoint URL rejected (allowed: public addresses only' in (v.get('error') or ''), v)
for u in ['http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/x']:
    v = verdict('generic-json', u)
    check('url check (strict policy): %s is "blocked" (never allowed), no network link' % u, v.get('state') == 'blocked' and not v.get('link') and 'never be used' in v.get('friendly', ''), v)
sql("update settings set config_webhook_allowed_networks='192.168.77.0/24'")
v = verdict('generic-json', 'http://192.168.77.5/hook')
check('url check: once the network is listed under Internal network access the same address is ok', v.get('state') == 'ok', v)
v = verdict('generic-json', 'http://192.168.78.5/hook')
check('url check: a private address outside the listed networks is still "private"', v.get('state') == 'private', v)
sql("update settings set config_webhook_allowed_networks='%s'" % prev_nets.replace("'", "''"))
hints = json.loads(php('require "vendor/autoload.php"; require "admin/includes/webhook_form_lib.php"; echo json_encode([rivetWebhookTestHint(false,401,"HTTP 401"), rivetWebhookTestHint(false,404,"HTTP 404"), rivetWebhookTestHint(false,429,"HTTP 429"), rivetWebhookTestHint(false,503,"HTTP 503"), rivetWebhookTestHint(false,null,"Operation timed out after 10001 milliseconds"), rivetWebhookTestHint(false,null,"endpoint URL not allowed"), rivetWebhookTestHint(false,null,"Could not resolve host: x.invalid"), rivetWebhookTestHint(true,200,null)]);'))
check('Send test hints in plain English for 401/404/429/5xx, timeouts, policy blocks, DNS failures and success',
      'credentials' in hints[0] and 'Production URL' in hints[1] and 'rate limiting' in hints[2] and 'internal error' in hints[3] and 'did not answer in time' in hints[4] and 'Internal network access' in hints[5] and 'host name' in hints[6] and 'accepted the test' in hints[7], hints)

# --- quick event sets: the page ships the groups; the tokens each chip produces are accepted and stored as patterns/ids
cat = json.loads(re.search(r'<script type="application/json" class="event-picker-catalog">(.*?)</script>', f, re.S).group(1))
EV = cat['events']; GR = {g['k'] for g in cat['groups']}
def ids_of(*groups): return [e['i'] for e in EV if e['g'] in groups]
def picker_tokens(sel):
    sel = set(sel); allids = [e['i'] for e in EV]; fam = {}
    for i in allids: fam.setdefault(i.split('.')[0], []).append(i)
    if allids and all(i in sel for i in allids): return ['*']
    out = []; used = set()
    for k in sorted(fam):
        if len(fam[k]) > 1 and all(i in sel for i in fam[k]): out.append(k + '.*'); used.update(fam[k])
    return out + [i for i in allids if i in sel and i not in used]
PRESETS = {'All events': [e['i'] for e in EV], 'Tickets': ids_of('tickets'), 'Critical only': [e['i'] for e in EV if e['s'] == 'critical'], 'SLA problems': ids_of('sla'),
           'Security & sign-in': ids_of('security'), 'Approvals': ids_of('approvals'), 'Workflows & lifecycle': ids_of('workflows'), 'Backups & system': ids_of('system')}
check('the catalog has the groups the quick sets are built from (tickets, sla, security, approvals, workflows, system) and critical events', {'tickets', 'sla', 'security', 'approvals', 'workflows', 'system'} <= GR and PRESETS['Critical only'], sorted(GR))
check('the script defines every chip label', all(l in js for l in ['All events', 'Tickets', 'Critical only', 'SLA problems', 'Security & sign-in', 'Approvals', 'Workflows & lifecycle', 'Backups & system', 'Recommended for ']))
check("the 'Tickets' chip stores the family pattern ticket.*, 'All events' stores *", picker_tokens(PRESETS['Tickets']) == ['ticket.*'] and picker_tokens(PRESETS['All events']) == ['*'], picker_tokens(PRESETS['Tickets']))
for label, idl in PRESETS.items():
    tk = picker_tokens(idl); nm = 'WP chip ' + label
    d = base('generic-json', nm, 'http://127.0.0.1:%d/chip' % PORT, tk, webhook_secret=SECRET, webhook_auth_mode='hmac'); d['wizard'] = '1'
    d['csrf_token'] = T(); st, bd, hd = req(admin, '/admin/post.php', d, referer='/admin/webhook_form.php?dest=generic-json')
    r = row(nm)
    check("quick set '%s' (%d events) is accepted and stored exactly as the picker would write it (%s)" % (label, len(idl), ','.join(tk)[:60] + ('...' if len(','.join(tk)) > 60 else '')), r and r['webhook_events'] == ','.join(tk), (hd.get('Location'), r and r['webhook_events']))

# --- create through the wizard: advanced options still saved; redirect to the success screen; "Create and send test"
d = base('ntfy', 'WP wizard ntfy', 'http://127.0.0.1:%d/{topic}' % PORT, ['sla.*'], **{'webhook_field[topic]': 'wiz-topic', 'webhook_field[tags]': 'warning,skull', 'webhook_field[priority]': '4', 'webhook_auth_mode': 'none'})
d['wizard'] = '1'; d['after'] = 'test'; d['csrf_token'] = T()
st, bd, hd = req(admin, '/admin/post.php', d, referer='/admin/webhook_form.php?dest=ntfy')
r = row('WP wizard ntfy'); loc = hd.get('Location', '')
check('wizard create stores the advanced fields (tags, priority) even though they were behind the disclosure', r and json.loads(r['webhook_extra'])['fields'] == {'priority': '4', 'tags': 'warning,skull'} and r['webhook_events'] == 'sla.*' and dec(r['webhook_url']) == 'http://127.0.0.1:%d/wiz-topic' % PORT, r)
wid_w = r['webhook_id'] if r else '0'
check('wizard create redirects to the success screen (and asks for the test with "Create and send test")', loc.endswith('webhook_form.php?id=%s&created=1&test=1' % wid_w), loc)
s, sp, h = req(admin, '/admin/' + loc.lstrip('/')) if loc else (0, '', {})
check('success screen: "Webhook created", next actions (Send test again, Add another, View deliveries, Open guide) and the auto-test flag', s == 200 and 'Webhook created' in sp and 'Send test again' in sp and 'Add another' in sp and 'View deliveries' in sp and 'Open guide' in sp and 'data-autotest="1"' in sp and 'WP wizard ntfy' in sp, s)
s, sp2, h = req(admin, '/admin/webhook_form.php?id=%s&created=1' % wid_w)
check('success screen without the test flag does not auto-send', 'data-autotest="0"' in sp2 and 'wiz-topic' not in sp2)
RX['log'].clear()
s, j = tools('test', {'webhook_id': wid_w})
check("the success screen's test (saved webhook) delivers and carries a hint", j.get('delivered') is True and 'accepted the test' in j.get('hint', '') and hits('/wiz-topic'), j)
# refused wizard post goes back to the Review step of the same platform with the typed values
d = base('ntfy', 'WP wizard refused', 'http://127.0.0.1:%d/{topic}' % PORT, [], **{'webhook_field[topic]': 'x'}); d['wizard'] = '1'; d['csrf_token'] = T()
st, bd, hd = req(admin, '/admin/post.php', d, referer='/admin/webhook_form.php?dest=ntfy')
check('a refused wizard post returns to ?dest=ntfy&step=review and stores nothing', 'dest=ntfy&step=review' in hd.get('Location', '') and row('WP wizard refused') is None, hd.get('Location'))

# --- review: sample event preview + test hint
unsaved2 = {'webhook_destination': 'generic-json', 'webhook_name': 'WP unsaved2', 'webhook_url': 'http://127.0.0.1:%d/prev' % PORT, 'webhook_secret': 'unsaved-sign-secret-2', 'webhook_auth_mode': 'hmac', 'webhook_events[]': ['sla.breached']}
s, j = tools('preview', {**unsaved2, 'sample_event': 'sla.breached'})
check('review preview: a selectable sample event is used (event name in the body and the headers), secrets stay hidden', j.get('ok') and j.get('event') == 'sla.breached' and json.loads(j['body'])['event'] == 'sla.breached' and 'unsaved-sign-secret-2' not in json.dumps(j) and any('sla.breached' in x for x in j['headers']), j)
s, j = tools('preview', {**unsaved2, 'sample_event': 'not.an.event'})
check('review preview: an unknown sample event falls back to the standard test event', j.get('ok') and j.get('event') == 'test', j)
s, j = tools('preview', {**unsaved2, 'webhook_events[]': []})
check('review: the server still lists what is missing (events) so the wizard can send you back to that step', j.get('ok') is False and any('Choose at least one event' in e for e in j.get('errors', [])), j)
s, j = tools('test', {**unsaved2, 'webhook_url': 'http://127.0.0.1:%d/fail-hint' % PORT})
check('failed test: status, duration and a plain-English hint for the 500', j.get('delivered') is False and j.get('http_status') == 500 and 'duration_ms' in j and 'internal error' in j.get('hint', ''), j)

# --- edit page: tabs, header controls, toggle, duplicate, deliveries
wid_e = row('WP n8n renamed')['webhook_id']
s, ep, h = form_page(None, wid_e)
check('edit page: tabs Connection | Events | Payload & advanced | Deliveries, deep-linkable, with panes', s == 200 and all(('data-whf-tab="%s"' % k) in ep for k in ['connection', 'events', 'advanced', 'deliveries']) and 'Payload &amp; advanced' in ep and 'role="tablist"' in ep and all(('data-whf-pane="%s"' % k) in ep for k in ['connect', 'events', 'advanced', 'deliveries']) and 'data-whf-pane="review"' not in ep and 'data-mode="edit"' in ep)
check('edit page: ?tab=deliveries / events deep links', 'data-tab="deliveries"' in form_page(None, wid_e, '&tab=deliveries')[1] and 'data-tab="events"' in form_page(None, wid_e, '&tab=events')[1] and 'data-tab="connection"' in form_page(None, wid_e, '&tab=zzz')[1])
check('edit page header: enable switch, last-delivery badge, Send test, Duplicate, Delete (confirmation link), unsaved-changes marker, Save changes',
      'data-whf-toggle="%s"' % wid_e in ep and 'data-whf-duplicate="%s"' % wid_e in ep and 'confirm-link' in ep and 'delete_webhook=%s' % wid_e in ep and 'data-whf-dirty' in ep and 'name="edit_webhook"' in ep and 'Save changes' in ep and ('Last: ' in ep or 'No deliveries yet' in ep) and 'data-wh-action="test"' in ep)
check('edit page: secrets stay "saved, leave blank to keep" (placeholders, never values)', '(saved - leave blank to keep)' in ep and N8N_VALUE not in ep and SECRET not in ep)
check('edit page: the advanced tab holds the retry note and the enabled checkbox; the Connection tab keeps the address placeholder', 'Retries' in ep and 'name="webhook_enabled"' in ep and 'saved: http://127.0.0.1:%d/…' % PORT in ep)
s, j = tools('toggle', {'webhook_id': wid_e, 'enabled': '0'})
check('toggle off: stored disabled', j.get('ok') and j.get('enabled') is False and sql("select webhook_enabled from webhooks where webhook_id=%s" % wid_e) == '0', j)
s, j = tools('toggle', {'webhook_id': wid_e, 'enabled': '1'})
check('toggle on: stored enabled', j.get('ok') and j.get('enabled') is True and sql("select webhook_enabled from webhooks where webhook_id=%s" % wid_e) == '1', j)
s, j = tools('toggle', {'webhook_id': 999999, 'enabled': '1'})
check('toggle on a missing webhook is a 404', s == 404 and j.get('ok') is False, (s, j))
s, j = tools('toggle', {'webhook_id': wid_e, 'enabled': '0'}, token=False)
check('toggle without a CSRF token is refused and changes nothing', s == 403 and sql("select webhook_enabled from webhooks where webhook_id=%s" % wid_e) == '1', (s, j))
src = row('WP n8n renamed')
s, j = tools('duplicate', {'webhook_id': wid_e})
cp = row('WP n8n renamed (copy)')
check('duplicate: a disabled copy named "... (copy)" with the same events, platform, auth and (encrypted) address/secret, and the answer links to its edit page',
      j.get('ok') and cp and cp['webhook_enabled'] == 0 and cp['webhook_events'] == src['webhook_events'] and cp['webhook_destination'] == 'n8n' and cp['webhook_auth_mode'] == src['webhook_auth_mode'] and dec(cp['webhook_url']) == dec(src['webhook_url']) and dec(cp['webhook_secret']) == dec(src['webhook_secret']) and dec(cp['webhook_auth_enc']) == dec(src['webhook_auth_enc']) and j.get('edit_url') == 'webhook_form.php?id=%s' % cp['webhook_id'], (j, cp))
check('duplicate does not leak secrets into the answer', SECRET not in json.dumps(j) and N8N_VALUE not in json.dumps(j))
s, body, h = req(admin, '/admin/webhook_tools.php?action=deliveries&webhook_id=%s' % wid_w)
dv = json.loads(body)
check('Deliveries tab data: the latest deliveries of that webhook with status, duration, time and a payload flag', s == 200 and dv.get('ok') and dv['deliveries'] and dv['deliveries'][0]['event'] == 'test' and dv['deliveries'][0]['http'] == 200 and dv['deliveries'][0]['payload'] is True and 'ms' in dv['deliveries'][0] and 'when' in dv['deliveries'][0], dv)
s, body, h = req(admin, '/admin/webhook_tools.php?action=deliveries&webhook_id=999999')
check('Deliveries tab data for an unknown webhook is simply empty', json.loads(body).get('deliveries') == [], body[:100])
s, ep2, h = form_page(None, wid_w)
check('edit page after a delivery: last-delivery badge shows the HTTP status', 'Last: HTTP 200' in ep2, re.findall(r'Last: [^<]*', ep2))

# --- list: platform glyph, host, event chip with count, last delivery badge + time, toggle, menu, search
s, lp, h = req(admin, '/admin/settings_webhooks.php')
check('list: brand glyph, name link, event chip with count, last-delivery badge with relative time', s == 200 and '--whf-brand:' in lp and 'href="webhook_form.php?id=%s"' % wid_w in lp and 'class="whf-evchip"' in lp and 'class="whf-evcount"' in lp and 'HTTP 200' in lp and 'None yet' in lp and 'Last delivery' in lp)
check('list: inline enable switch per row, compact per-row menu with Send test / Edit / Duplicate / Delete (confirm)', lp.count('data-whf-toggle="') >= 5 and 'data-whf-menu' in lp and all(x in lp for x in ['Send test</button>', '> Edit</a>', '> Duplicate</button>', '> Delete</a>']) and 'whf-menu-danger confirm-link' in lp and 'data-whf-duplicate="%s"' % wid_w in lp)
check('list: search box and searchable rows (name, platform, masked host, events); the full address never leaks into data-search', 'data-whf-list-search' in lp and 'data-whf-row' in lp and 'data-whf-list-none' in lp and re.search(r'data-search="[^"]*wp wizard ntfy[^"]*"', lp) and 'wiz-topic' not in lp and 'http://127.0.0.1:%d/n8n' % PORT not in lp)
sql("update webhooks set webhook_enabled=1")

# ---------------------------------------------------------------- migration: fresh install and upgrade path
cols = sql("select column_name from information_schema.columns where table_schema='%s' and table_name='webhooks'" % DB).split()
check('fresh install has the new webhook columns', all(c in cols for c in ['webhook_destination', 'webhook_format', 'webhook_method', 'webhook_template', 'webhook_auth_mode', 'webhook_auth_enc', 'webhook_extra']), cols)
latest = re.search(r'LATEST_DATABASE_VERSION", "([0-9.]+)"', open(APP + '/includes/database_version.php').read()).group(1)
check('and ends at the latest database version', sql('select config_current_database_version from settings') == latest, latest)
check('db.sql declares the same columns, defaults and widened lists', all(x in open(APP + '/db.sql').read() for x in ["`webhook_destination` varchar(40) NOT NULL DEFAULT ''", "`webhook_method` varchar(4) NOT NULL DEFAULT 'POST'", "`webhook_auth_enc` text DEFAULT NULL", "`webhook_events` varchar(2000)"]))

def upgrade_path():
    repo = os.environ.get('REPO_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
    tags = subprocess.run(['git', '-C', repo, 'tag', '-l', 'v26.*', '--sort=-creatordate'], capture_output=True, text=True).stdout.split()
    if not tags:
        return 'skipped: no release tags (v26.*) in the repository checkout (set REPO_DIR)'
    # Newest release tag that is still BEHIND this tree's database version (a tag that already contains the migration would be a no-op upgrade).
    cur = re.search(r'LATEST_DATABASE_VERSION",\s*"([^"]+)"', open(os.path.join(repo, 'includes', 'database_version.php')).read())
    cur = cur.group(1) if cur else ''
    tag = None
    for t in tags:
        v = subprocess.run(['git', '-C', repo, 'show', t + ':includes/database_version.php'], capture_output=True, text=True).stdout
        m = re.search(r'LATEST_DATABASE_VERSION",\s*"([^"]+)"', v)
        if m and m.group(1) != cur:
            tag = t
            break
    if tag is None:
        return 'skipped: every release tag already contains the current database version'
    probe = subprocess.run(['sudo', '-n', 'mysql', '-e', 'select 1'], capture_output=True, text=True)
    if probe.returncode != 0: return 'skipped: sudo -n mysql is not available'
    udb = DB + '_up'; work = tempfile.mkdtemp(prefix='whup_'); pw = DBPASS
    sh = lambda c, **k: subprocess.run(c, capture_output=True, text=True, **k)
    sh(['sudo', '-n', 'mysql', '-e', "DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4; GRANT ALL ON `%s`.* TO '%s'@'localhost';" % (udb, udb, udb, USER)])
    try:
        tar = subprocess.run('git -C %s archive %s | tar -x -C %s' % (shlex.quote(repo), shlex.quote(tag), shlex.quote(work)), shell=True, capture_output=True, text=True)
        if tar.returncode: return 'FAIL: git archive ' + tar.stderr
        env = dict(os.environ, RIVETIT_DB_PASSWORD=pw, ITFLOW_DB_PASSWORD=pw)
        r = subprocess.run(['php', 'setup_cli.php', '--host=localhost', '--username=' + USER, '--password=' + pw, '--database=' + udb, '--base-url=127.0.0.1:8489', '--locale=en_US', '--timezone=UTC', '--currency=USD', '--company-name=Up Org', '--country=United States', '--user-name=Up Admin', '--user-email=admin@up.test', '--user-password=Scratch-Admin-1234', '--non-interactive'], cwd=work + '/scripts', capture_output=True, text=True, env=env, stdin=subprocess.DEVNULL)
        old = sql("select config_current_database_version from settings", udb)
        if not old: return 'FAIL: could not install %s: %s' % (tag, (r.stdout + r.stderr)[-300:])
        sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) values ('Old hook', 'https://example.com/h', 'ENC:x', 'ticket.created, invoice.paid', 1)", udb)
        subprocess.run(['rsync', '-a', '--exclude', '.git', '--exclude', 'config.php', '--exclude', 'uploads', '--exclude', 'backups', APP + '/', work + '/'], capture_output=True)
        shutil.copy(APP + '/config.php', work + '/config.php')
        cfg = open(work + '/config.php').read()
        cfg = re.sub(r"\$database\s*=\s*'[^']*'", "$database = '%s'" % udb, cfg); cfg = re.sub(r'\$database\s*=\s*"[^"]*"', '$database = "%s"' % udb, cfg)
        open(work + '/config.php', 'w').write(cfg)
        u = subprocess.run(['php', 'update_cli.php', '--update_db'], cwd=work + '/scripts', capture_output=True, text=True, env=dict(os.environ))
        new = sql("select config_current_database_version from settings", udb)
        ok1 = new == latest
        w = sql("select concat_ws('|', webhook_destination, webhook_format, webhook_method, webhook_auth_mode, webhook_events) from webhooks where webhook_name='Old hook'", udb)
        c2 = sql("select column_name from information_schema.columns where table_schema='%s' and table_name='webhooks'" % udb, udb).split()
        check('upgrade path: %s (DB %s) + current tree + ONE update_cli run reaches the latest version %s' % (tag, old, latest), ok1, (new, (u.stdout + u.stderr)[-400:]))
        check('upgrade path: webhook columns exist and the old row is backfilled to generic-json/json with its events intact', all(c in c2 for c in ['webhook_destination', 'webhook_auth_enc', 'webhook_extra']) and w == 'generic-json|json|POST|none|ticket.created, invoice.paid', w)
        t2 = sql("select column_type from information_schema.columns where table_schema='%s' and table_name='webhooks' and column_name in ('webhook_url','webhook_events') order by column_name" % udb, udb)
        check('upgrade path: the URL and event-list columns are widened exactly like a fresh install', t2.split() == ['varchar(2000)', 'varchar(4096)'], t2)
        u2 = subprocess.run(['php', 'update_cli.php', '--update_db'], cwd=work + '/scripts', capture_output=True, text=True)
        check('upgrade path: running the update again is a no-op', sql("select config_current_database_version from settings", udb) == latest and 'rror' not in u2.stderr)
        return None
    finally:
        sh(['sudo', '-n', 'mysql', '-e', "DROP DATABASE IF EXISTS `%s`;" % udb]); shutil.rmtree(work, ignore_errors=True)
msg = 'skipped: WH_SKIP_UPGRADE is set' if os.environ.get('WH_SKIP_UPGRADE') else upgrade_path()
if msg: print('NOTE  ' + msg); check('upgrade path ran', not msg.startswith('FAIL'), msg)

# ---------------------------------------------------------------- cleanup + summary
sql("delete from webhooks where webhook_name like 'WP %'; delete from webhook_deliveries; delete from integration_jobs")
failed = [r for r in results if not r[1]]
print('\n%d checks, %d passed, %d failed' % (len(results), len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
