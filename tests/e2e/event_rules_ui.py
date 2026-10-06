"""
End-to-end check of the redesigned Administration > Event rules page (list, editor, test and history drawers) over HTTP:
list markup (summaries, badges, filter hooks, stats), toggle / duplicate / delete, saving every action type (and from a recipe),
the condition round trip, smart-select data, the DRY-RUN test (matched / not matched, and proof it has no side effects: no ticket,
no webhook, no queued job, no audit run), the history drawer, permissions, CSRF and hostile input.
Browser behaviour (search/sort/filter, the editor widgets) is driven separately in a real browser; this file is the server contract.

Needs a THROWAWAY app (scripts/setup_cli.php from scripts/, $config_https_only = FALSE, `php -S`), TEST_DB_USER / TEST_DB_PASS:
  PHP_LOG=/path/php.log TEST_DB_USER=.. TEST_DB_PASS=.. python3 tests/e2e/event_rules_ui.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, subprocess, os, threading, time, html as htmlmod, http.cookiejar, urllib.request, urllib.parse, urllib.error
from http.server import BaseHTTPRequestHandler, HTTPServer
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5] if len(sys.argv) > 5 else '.'
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PHP_LOG = os.environ.get('PHP_LOG')
TECH_EMAIL, TECH_PASSWORD = 'tech@scratch.test', 'Scratch-Tech-1234'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    def req(path, data=None, referer=None):
        headers = {'Referer': BASE + referer} if referer else {}
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            r = op.open(urllib.request.Request(BASE + path, data=body, headers=headers)); return r.status, r.read().decode('utf-8', 'replace'), r.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
    return req
def sql(q): return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True).stdout.strip()
def csrf(h):
    m = re.search(r'name="csrf_token" value="([^"]+)"', h); return m.group(1) if m else None
res = []
def check(n, ok, d=''):
    res.append(bool(ok)); print(('PASS' if ok else 'FAIL') + '  ' + n + (('  [' + str(d)[:300] + ']') if d and not ok else ''))
def login(req, email, password):
    s, h, _ = req('/login.php'); t = csrf(h)
    s, _, hd = req('/login.php', {'email': email, 'password': password, 'login': '', 'csrf_token': t})
    return s in (302, 303)

# A receiver that must NEVER be called by a dry run.
RX = {'log': []}
class H(BaseHTTPRequestHandler):
    def do_POST(self):
        RX['log'].append(self.rfile.read(int(self.headers.get('Content-Length', 0))))
        self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', 9456), H); threading.Thread(target=srv.serve_forever, daemon=True).start()

admin = session()
check('admin signs in', login(admin, EMAIL, PASSWORD))
sql("delete from automation_rules where name like 'E2E UI%' or name like '%<script>%'; delete from audit_events where event_type='automation.rule_fired'; delete from tickets where ticket_subject like 'E2E UI%'")
page = lambda p='/admin/event_rules.php': admin(p)
def tools(action, data=None, token=None, sess=None, **kw):
    d = {'action': action, 'csrf_token': token if token is not None else TOKEN}
    d.update(data or {}); d.update(kw)
    s, body, h = (sess or admin)('/admin/event_rules_tools.php', d, referer='/admin/event_rules.php')
    try: j = json.loads(body)
    except Exception: j = {'raw': body[:200]}
    return s, j
s, pg, _ = page(); TOKEN = csrf(pg)
check('the page loads', s == 200 and 'Event rules' in pg and 'id="er-data"' in pg, s)

def form(name, event, typ, conds=None, **cfg):
    d = {'rule_name': name, 'trigger_event': event, 'action_type': typ, 'is_enabled': '1', 'cond_field[]': [c[0] for c in (conds or [])], 'cond_value[]': [c[1] for c in (conds or [])]}
    d.update(cfg); return d
def rid(name): return sql("select rule_id from automation_rules where name='%s'" % name.replace("'", "''"))

# ---- create one rule of each action type through the editor's save endpoint
s, j = tools('save', form('E2E UI notify critical', 'ticket.created', 'notify_user', [('ticket_priority', 'Critical')], cfg_message='Critical {ticket_number}: {ticket_subject}'))
check('save: notify_user rule with a condition', s == 200 and j.get('ok') and rid('E2E UI notify critical'), j)
N_ID = rid('E2E UI notify critical')
s, j = tools('save', form('E2E UI ticket on failed login', 'auth.login_failed', 'create_ticket', [], cfg_subject='E2E UI failure {summary}', cfg_details='d', cfg_priority='High', cfg_client_id='0'))
check('save: create_ticket rule', s == 200 and j.get('ok'), j)
T_ID = rid('E2E UI ticket on failed login')
s, j = tools('save', form('E2E UI webhook', 'ticket.created', 'send_webhook', [('ticket_priority', 'High')], cfg_url='http://127.0.0.1:9456/hook', cfg_secret='s3cret'))
check('save: send_webhook rule (loopback allowed only because the test server sets RIVETMSP_WEBHOOK_ALLOW_PRIVATE)', s == 200 and j.get('ok'), j)
W_ID = rid('E2E UI webhook')
check('stored config matches what was posted', json.loads(sql("select action_config_json from automation_rules where rule_id=%s" % T_ID))['priority'] == 'High' and json.loads(sql("select condition_json from automation_rules where rule_id=%s" % N_ID)) == {'ticket_priority': 'Critical'})
check('saving is on the audit trail', int(sql("select count(*) from audit_events where event_type='automation.rule_created'")) >= 3)

# ---- list markup
s, pg, _ = page()
check('list: a card per rule with its plain-English summary', 'When &quot;Ticket created&quot; happens and ticket priority is &quot;Critical&quot;, notify technicians' in pg and 'create a High-priority ticket' in pg, re.findall(r'er-rule-summary">([^<]*)', pg)[:3])
check('list: a webhook summary shows the host, never the secret', 'send the event to a webhook at http://127.0.0.1:9456' in pg and 's3cret' not in pg)
check('list: trigger group badge, action badge and data hooks for filter/sort', 'class="er-badge"' in pg and 'er-badge-action' in pg and 'data-group="tickets"' in pg and 'data-group="security"' in pg and 'data-action="send_webhook"' in pg and 'data-er-search' in pg and 'data-er-sort' in pg)
check('list: summary strip counts rules and enabled', re.search(r'data-er-stat="total">3<', pg) and re.search(r'data-er-stat="enabled">3<', pg))
check('list: shows "Has not fired yet" before any run', pg.count('Has not fired yet') == 3)
check('list: every row action exists (edit, test, history, duplicate, delete) and a toggle with role=switch', all(x in pg for x in ['data-er-test', 'data-er-history', 'data-er-duplicate', 'data-er-delete', 'role="switch"', 'href="event_rules.php?edit=']))
check('list: recipe gallery with 8-10 recipes, linking to the editor', 8 <= pg.count('class="er-recipe"') <= 10 and 'event_rules.php?recipe=critical-ticket' in pg)
check('list: the page script is loaded on this page only', '/js/event_rules.js' in pg and '/js/event_rules.js' not in page('/admin/settings_webhooks.php')[1])
data = json.loads(htmlmod.unescape(re.search(r'<script type="application/json" id="er-data">(.*?)</script>', pg, re.S).group(1)))
check('page data: payload fields per event (ticket events carry ticket_priority), smart-select lists, actions', 'ticket_priority' in json.dumps(data['fields']['sets'][data['fields']['events']['ticket.created']]) and data['lists']['priority'] == ['Low', 'Medium', 'High', 'Critical'] and 'Open' in data['lists']['status'] and 'Scratch Admin' in data['lists']['agents'] and set(data['actions']) == {'create_ticket', 'send_webhook', 'notify_user'}, list(data['lists'])[:6])
check('page data: the webhook URL rule text is the shared network wording', data['urlRule'].startswith('allowed: public addresses'))
check('the editor form is on the page (picker for the trigger, step cards, summary panel, drawer)', all(x in pg for x in ['data-field="trigger_event"', 'Rule summary', 'data-er-chips', 'id="er-drawer"', 'aria-modal="true"']))

# ---- editor: new, recipe, edit round trip
s, pg, _ = page('/admin/event_rules.php?new=1')
check('editor: ?new=1 shows the editor and hides the list', s == 200 and re.search(r'<form id="er-editor"(?![^>]*hidden)[^>]*>', pg) and 'id="er-list" hidden' in pg)
s, pg, _ = page('/admin/event_rules.php?recipe=critical-ticket')
check('recipe: prefilled form (name, event, condition seed, message) and nothing saved', 'value="Notify on Critical tickets"' in pg and 'name="trigger_event" value="ticket.created"' in pg and 'data-field="ticket_priority" data-value="Critical"' in pg and 'Critical ticket {ticket_number}' in pg and rid('Notify on Critical tickets') == '')
check('recipe: says it is a recipe and unsaved', 'This is a recipe' in pg)
rc = {'rule_name': 'E2E UI from recipe', 'trigger_event': 'ticket.created', 'action_type': 'notify_user', 'cond_field[]': ['ticket_priority'], 'cond_value[]': ['Critical'], 'cfg_message': 'Critical ticket {ticket_number}: {ticket_subject} ({client_name})', 'is_enabled': '1'}
s, j = tools('save', rc)
check('recipe: saving the prefilled form creates the rule', j.get('ok') and rid('E2E UI from recipe'), j)
for slug in ['critical-ticket', 'ticket-webhook', 'resolved-webhook', 'credential-revealed', 'signin-blocked', 'failed-signin-ticket', 'onboarding-ticket', 'offboarding-ticket', 'workflow-failed', 'change-created']:
    s, pg, _ = page('/admin/event_rules.php?recipe=' + slug)
    check('recipe %s opens in the editor' % slug, s == 200 and 'This is a recipe' in pg)
s, pg, _ = page('/admin/event_rules.php?recipe=nope')
check('an unknown recipe falls back to the list', s == 200 and 'id="er-list" hidden' not in pg)
s, j = tools('save', form('E2E UI two conds', 'ticket.assigned', 'notify_user', [('ticket_priority', 'High'), ('client_name', 'Acme <b>&</b>')], cfg_message='m'))
R_ID = rid('E2E UI two conds')
s, pg, _ = page('/admin/event_rules.php?edit=' + R_ID)
check('round trip: edit shows exactly the saved conditions, action and settings', pg.count('class="er-cond-seed"') == 2 and 'data-field="ticket_priority" data-value="High"' in pg and 'data-field="client_name" data-value="Acme &lt;b&gt;&amp;&lt;/b&gt;"' in pg and 'name="rule_id" value="%s"' % R_ID in pg and 'value="E2E UI two conds"' in pg and 'name="trigger_event" value="ticket.assigned"' in pg and 'id="cfg_message" name="cfg_message" rows="3" maxlength="1000" data-er-ph>m</textarea>' in pg)
s, j = tools('save', form('E2E UI two conds renamed', 'ticket.assigned', 'notify_user', [('ticket_status', 'Open')], cfg_message='m2'), rule_id=R_ID)
check('editing replaces conditions and name', j.get('ok') and json.loads(sql("select condition_json from automation_rules where rule_id=%s" % R_ID)) == {'ticket_status': 'Open'} and rid('E2E UI two conds renamed') == R_ID, j)
s, pg, _ = page('/admin/event_rules.php?edit=99999')
check('editing a missing rule shows a notice and the list', s == 200 and 'That rule no longer exists' in pg)

# ---- toggle / duplicate / delete
s, j = tools('toggle', rule_id=W_ID)
check('toggle turns a rule off and persists', j.get('ok') and j['enabled'] is False and sql("select is_enabled from automation_rules where rule_id=%s" % W_ID) == '0', j)
s, j = tools('toggle', rule_id=W_ID)
check('toggle turns it back on', j.get('enabled') is True and sql("select is_enabled from automation_rules where rule_id=%s" % W_ID) == '1')
check('toggles are audited', int(sql("select count(*) from audit_events where event_type='automation.rule_toggled'")) >= 2)
s, j = tools('duplicate', rule_id=W_ID)
d = sql("select concat(name,'|',is_enabled,'|',action_type,'|',trigger_event,'|',action_config_json) from automation_rules where rule_id=%s" % j.get('id', 0))
check('duplicate: a disabled copy named "... (copy)" with the same configuration', j.get('ok') and d.startswith('E2E UI webhook (copy)|0|send_webhook|ticket.created|') and 's3cret' in d, d)
s, j2 = tools('delete', rule_id=j['id'])
check('delete removes the rule', j2.get('ok') and sql("select count(*) from automation_rules where rule_id=%s" % j['id']) == '0')
check('delete is audited', int(sql("select count(*) from audit_events where event_type='automation.rule_deleted'")) >= 1)
s, j2 = tools('delete', rule_id='999999')
check('deleting a missing rule is a clean 404', s == 404 and not j2.get('ok'))

# ---- dry run: no side effects
def counts():
    return (sql("select count(*) from tickets"), sql("select count(*) from automation_rules"), sql("select count(*) from audit_events where event_type='automation.rule_fired'"),
            sql("select count(*) from integration_jobs"), sql("select count(*) from webhook_deliveries"), sql("select count(*) from notifications"), len(RX['log']))
before = counts()
s, j = tools('test', rule_id=N_ID, source='sample')
check('test: sample ticket.created (priority High) does NOT match a Critical condition and says which one', j.get('ok') and j['matched'] is False and j['conditions'][0] == {'field': 'ticket_priority', 'expected': 'Critical', 'actual': 'High', 'ok': False} and j['dry_run'] is True, j)
s, j = tools('test', rule_id=W_ID, source='sample')
check('test: webhook rule matches the sample (High) and describes the call, host only', j['matched'] is True and j['would'].startswith('Would send the event as a signed JSON POST to http://127.0.0.1:9456') and 's3cret' not in json.dumps(j) and j.get('url_allowed') is True, j)
s, j = tools('test', rule_id=T_ID, source='sample')
check('test: ticket rule renders the subject with placeholders filled from the sample', j['matched'] is True and 'Would create a High-priority ticket "E2E UI failure Sample event' in j['would'], j.get('would'))
s, j = tools('test', form('E2E UI unsaved', 'ticket.created', 'notify_user', [('ticket_priority', 'High')], cfg_message='Hi {client_name}'), source='sample')
check('test: an UNSAVED editor form can be tested too (and stays unsaved)', j.get('matched') is True and j['fields']['Message'] == 'Hi Acme Corp' and rid('E2E UI unsaved') == '', j)
sql("insert into tickets set ticket_prefix='TCK-', ticket_number=9001, ticket_subject='E2E UI recent ticket', ticket_details='d', ticket_priority='Critical', ticket_status=1, ticket_client_id=0, ticket_created_by=1, ticket_url_key='abc9001', ticket_created_at=NOW()")
tid = sql("select ticket_id from tickets where ticket_subject='E2E UI recent ticket'")
before = counts()
s, rec = tools('recent', event='ticket.created')
check('recent: lists recent tickets for a ticket event', rec.get('ok') and any(e['id'] == 't' + tid for e in rec['events']) and rec['ticket_shaped'], rec)
s, j = tools('test', rule_id=N_ID, source='t' + tid)
check('test: a real recent ticket (Critical) matches the Critical rule and the message is rendered from it', j.get('matched') is True and 'Critical TCK-9001: E2E UI recent ticket' in j['would'], j)
sql("insert into audit_events (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json) values ('auth.login_failed', null, 'user', '0', 'failed', 'Failed login attempt using zed@example.test', '{\"ip\":\"10.0.0.5\"}')")
aid = sql("select max(audit_id) from audit_events where event_type='auth.login_failed'")
s, rec = tools('recent', event='auth.login_failed')
check('recent: audit events come from the audit trail', any(e['id'] == 'a' + aid for e in rec['events']) and not rec['ticket_shaped'], rec)
s, j = tools('test', form('E2E UI aud', 'auth.login_failed', 'notify_user', [('action', 'failed'), ('metadata.ip', '10.0.0.5')], cfg_message='{summary}'), source='a' + aid)
check('test: an audit event matches on action and on a nested metadata field', j.get('matched') is True and j['fields']['Message'] == 'Failed login attempt using zed@example.test', j)
s, j = tools('test', form('E2E UI aud', 'auth.login_failed', 'notify_user', [('metadata.ip', '10.9.9.9')], cfg_message='x'), source='a' + aid)
check('test: a nested condition that differs is reported as not matched', j.get('matched') is False and j['conditions'][0]['actual'] == '10.0.0.5')
s, j = tools('test', form('E2E UI aud', 'auth.login_failed', 'notify_user', [('nonexistent', 'x')], cfg_message='x'), source='a' + aid)
check('test: a field the event does not carry never matches', j['matched'] is False and j['conditions'][0]['actual'] is None)
s, j = tools('test', rule_id=N_ID, source='a999999')
check('test: an event source that does not fit the event is refused', s == 404 and not j.get('ok'))
after = counts()
before2 = list(before); before2[2] = str(int(before2[2]))
check('DRY RUN has no side effects: no ticket, no webhook sent, no job queued, no delivery, no notification, no rule run recorded, no rule created',
      after == before and len(RX['log']) == 0, (before, after, len(RX['log'])))

# ---- history
sql("insert into audit_events (event_type, entity_type, entity_id, action, summary, metadata_json, created_at) values "
    "('automation.rule_fired', 'automation_rule', '%s', 'ok', \"Rule 'E2E UI notify critical' on ticket.created: notification created\", '{\"event\":\"ticket.created\",\"rule_id\":%s}', NOW() - INTERVAL 5 MINUTE),"
    "('automation.rule_fired', 'automation_rule', '%s', 'failed', \"Rule 'E2E UI notify critical' on ticket.created: boom\", '{\"event\":\"ticket.created\",\"rule_id\":%s}', NOW() - INTERVAL 2 DAY)" % (N_ID, N_ID, N_ID, N_ID))
s, j = tools('history', rule_id=N_ID)
check('history: newest first with time, result, event and message (summary prefix stripped)', j.get('ok') and j['total'] == 2 and j['runs'][0]['ok'] is True and j['runs'][0]['message'] == 'notification created' and j['runs'][0]['event'] == 'ticket.created' and j['runs'][1]['ok'] is False and j['runs'][1]['message'] == 'boom' and 4 * 60 <= j['runs'][0]['ago'] <= 7 * 60, j)
s, j = tools('history', rule_id=W_ID)
check('history: a rule that never ran has no runs', j['runs'] == [] and j['total'] == 0)
s, pg, _ = page()
check('list: last fired, outcome and run count come from the audit trail', re.search(r'Last fired 5 minutes ago', pg) and 'in total' in pg and '2 runs in total' in pg, re.findall(r'er-rule-meta">(.*?)</div>', pg, re.S)[:1])
check('list: fired-in-last-24h strip counts only recent runs (1 of 2) and flags no failure', re.search(r'<span class="er-stat-n">1</span><span class="er-stat-l">fired in the last 24 h</span>', pg), re.findall(r'er-stat-l">([^<]*)', pg))

# ---- validation (server authoritative)
bad = {
  'no name': form('', 'ticket.created', 'notify_user', cfg_message='x'), 'bad event': form('E2E UI x', 'Bad Event!', 'notify_user', cfg_message='x'),
  'unknown action': form('E2E UI x', 'ticket.created', 'launch_missiles'), 'empty ticket subject': form('E2E UI x', 'ticket.created', 'create_ticket', cfg_subject=''),
  'bad webhook scheme': form('E2E UI x', 'ticket.created', 'send_webhook', cfg_url='javascript:alert(1)'), 'empty message': form('E2E UI x', 'ticket.created', 'notify_user', cfg_message=''),
  'invalid regex-like field': form('E2E UI x', 'ticket.created', 'notify_user', [('(a+)+$', 'x')], cfg_message='m'), 'duplicate field': form('E2E UI x', 'ticket.created', 'notify_user', [('a', '1'), ('a', '2')], cfg_message='m'),
  'huge value': form('E2E UI x', 'ticket.created', 'notify_user', [('a', 'v' * 5000)], cfg_message='m'), 'huge name': form('N' * 100000, 'ticket.created', 'notify_user', cfg_message='m'),
  'metadata url in userinfo': form('E2E UI x', 'ticket.created', 'send_webhook', cfg_url='http://user:pw@127.0.0.1/'),
}
n0 = sql("select count(*) from automation_rules")
for k, d in bad.items():
    s, j = tools('save', d)
    check('save refuses: ' + k + ' (with a per-field message, status 422)', s == 422 and not j.get('ok') and j.get('errors'), (s, j))
check('none of the refused rules was stored', sql("select count(*) from automation_rules") == n0)
s, j = tools('save', form('E2E UI x', 'ticket.created', 'notify_user'))
check('save: errors name the field (cfg_message)', 'cfg_message' in j.get('errors', {}), j)
s, j = tools('describe', form('E2E UI x', 'ticket.created', 'notify_user', [('ticket_priority', 'High')], cfg_message='{ticket_number} {client_name}'))
check('describe: live sentence + sample-data preview', j.get('ok') and j['summary'].startswith('When "Ticket created" happens and ticket priority is "High", notify technicians') and j['preview'] == {'Message': 'TCK-1042 Acme Corp'}, j)
s, j = tools('describe', form('E2E UI x', 'ticket.created', 'send_webhook', cfg_url='http://a.test/p?token=zzz', cfg_secret='shh'))
check('describe never echoes a URL path/query or secret', 'zzz' not in json.dumps(j) and 'shh' not in json.dumps(j) and 'http://a.test' in j['summary'], j)

# ---- hostile input is escaped everywhere it is displayed
evil = "E2E UI <script>alert(1)</script> \"'&"
s, j = tools('save', form(evil, 'ticket.created', 'notify_user', [('ticket_priority', '"><img src=x onerror=alert(2)>')], cfg_message='<img src=x onerror=alert(3)> {ticket_number}'))
check('hostile name, condition value and message are accepted as plain text', j.get('ok'), j)
s, pg, _ = page()
check('list escapes name, summary and data attributes', '<script>alert(1)' not in pg and '<img src=x' not in pg and 'E2E UI &lt;script&gt;alert(1)&lt;/script&gt;' in pg)
E_ID = rid(evil)
s, pg, _ = page('/admin/event_rules.php?edit=' + E_ID)
check('editor escapes name, condition seeds and message', '<script>alert(1)' not in pg and '<img src=x' not in pg and 'data-value="&quot;&gt;&lt;img src=x onerror=alert(2)&gt;"' in pg and '&lt;img src=x onerror=alert(3)&gt; {ticket_number}</textarea>' in pg)
raw = admin('/admin/event_rules_tools.php', {'action': 'history', 'csrf_token': TOKEN, 'rule_id': E_ID}, referer='/admin/event_rules.php')[1]
check('JSON responses escape < and & so they are safe even if mis-rendered', '<' not in raw and '&' not in raw)

# ---- permissions and CSRF
s, j = tools('toggle', rule_id=W_ID, token='wrong')
check('CSRF: a wrong token is refused (403) and changes nothing', s == 403 and sql("select is_enabled from automation_rules where rule_id=%s" % W_ID) == '1')
s, j = tools('delete', rule_id=W_ID, token='')
check('CSRF: an empty token is refused', s == 403 and sql("select count(*) from automation_rules where rule_id=%s" % W_ID) == '1')
for a in ('save', 'duplicate', 'test', 'history', 'describe', 'recent'):
    s, j = tools(a, rule_id=W_ID, token='x')
    check('CSRF: ' + a + ' is refused with a bad token', s == 403, s)
s, body, _ = admin('/admin/event_rules_tools.php?action=history&rule_id=%s' % W_ID)
check('GET is refused (405): the tools are POST only', s == 405)
sql("delete from users where user_email='%s'" % TECH_EMAIL)
h = subprocess.run(['php', '-r', 'echo password_hash("%s", PASSWORD_DEFAULT);' % TECH_PASSWORD], capture_output=True, text=True).stdout.strip()
sql("insert into users (user_name, user_email, user_password, user_specific_encryption_ciphertext, user_role_id, user_status, user_type) select 'Scratch Tech', '%s', '%s', user_specific_encryption_ciphertext, 2, 1, 1 from users where user_id=1" % (TECH_EMAIL, h))
sql("insert ignore into user_settings set user_id=%s" % sql("select user_id from users where user_email='%s'" % TECH_EMAIL))
tech = session()
check('technician signs in', login(tech, TECH_EMAIL, TECH_PASSWORD))
tpage = tech('/admin/event_rules.php')
check('non-admin: the page is refused', 'Event rules' not in tpage[1] and 'er-data' not in tpage[1], tpage[0])
_, tp = tech('/agent/dashboard.php')[0], tech('/agent/dashboard.php')[1]
ttoken = csrf(tp) or ''
n1 = sql("select count(*) from automation_rules")
for a, extra in [('toggle', {'rule_id': W_ID}), ('delete', {'rule_id': W_ID}), ('duplicate', {'rule_id': W_ID}), ('save', form('E2E UI tech', 'ticket.created', 'notify_user', cfg_message='x')), ('test', {'rule_id': W_ID}), ('history', {'rule_id': W_ID}), ('recent', {'event': 'ticket.created'}), ('describe', {})]:
    s, j = tools(a, extra, token=ttoken, sess=tech)
    check('non-admin: %s is refused (403)' % a, s == 403 and not j.get('ok'), (s, j))
check('non-admin changed nothing', sql("select count(*) from automation_rules") == n1 and sql("select is_enabled from automation_rules where rule_id=%s" % W_ID) == '1')
anon = session()
s, body, _ = anon('/admin/event_rules_tools.php', {'action': 'history', 'rule_id': W_ID})
check('signed-out: the tools endpoint gives no data', 'runs' not in body, s)

# ---- classic form post (no JavaScript) still works
s, pg, _ = page()
s, _, _ = admin('/admin/post.php', form('E2E UI classic', 'auth.login_failed', 'notify_user', [('action', 'failed')], cfg_message='classic', save_event_rule='1', csrf_token=csrf(pg)), referer='/admin/event_rules.php')
check('the classic form post still saves (no-JavaScript path)', rid('E2E UI classic') != '' and s in (302, 303))
if PHP_LOG and os.path.exists(PHP_LOG):
    log = open(PHP_LOG, 'rb').read().decode('utf-8', 'replace')
    bad_lines = [l for l in log.splitlines() if re.search(r'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)', l) and 'event_rules' in l]
    check('no PHP warnings from the event rules code in the server log', not bad_lines, bad_lines[:2])
node = subprocess.run(['node', '--check', os.path.join(APP, 'js/event_rules.js')], capture_output=True, text=True)
check('js/event_rules.js parses', node.returncode == 0, node.stderr[:200])
srv.shutdown()
sql("delete from automation_rules where name like 'E2E UI%' or name like '%<script>%'; delete from audit_events where event_type='automation.rule_fired'; delete from tickets where ticket_subject like 'E2E UI%'; delete from users where user_email='" + TECH_EMAIL + "'")
print('SUMMARY %d/%d passed' % (sum(res), len(res)))
sys.exit(0 if all(res) else 1)
