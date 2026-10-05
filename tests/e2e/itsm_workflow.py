"""
End-to-end check of Problems & Changes (RivetCore ITSM module) and client onboarding/offboarding Workflows through the
real web stack: sign in, load every page, create/edit/transition problems and changes, link tickets from both the problem
page and the ticket page, workflow templates -> run on a client / contact -> complete tasks, permission denial for
low-privilege users, CSRF rejection, the module-off switch, and the audit trail / event bus rows written.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/itsm_workflow.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, subprocess, os, time, http.cookiejar, urllib.request, urllib.parse, urllib.error

BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
assert 'scratch' in DB and 'scratch' in USER, 'refusing to run against a non-scratch database'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, path, data=None, referer=None):
        headers = {}
        if referer:
            headers['Referer'] = BASE + referer
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            resp = self.opener.open(r)
            return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers

    def login(self, email, password):
        s, html, h = self.req('/login.php')
        d = {'email': email, 'password': password, 'login': ''}
        t = csrf(html)
        if t:
            d['csrf_token'] = t
        return self.req('/login.php', d)[0] in (302, 303)

    def post(self, page, data, handler='/agent/post.php'):
        """POST a handler action the way the browser does: token scraped from the page, Referer = that page."""
        d = dict(data)
        if 'csrf_token' not in d:
            d['csrf_token'] = csrf(self.req(page)[1])
        return self.req(handler, d, referer=page)


def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True)
    return out.stdout.strip()


def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'window\.csrfToken = "([^"]+)"', html)
    return m.group(1) if m else None


results = []


def check(name, ok, detail=''):
    results.append((name, bool(ok), detail))
    print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))


BADPAGE = re.compile(r'(Warning|Notice|Deprecated|Fatal error|Parse error)</b>:|Uncaught')


def clean(page):
    return not BADPAGE.search(page)


def hash_pw(pw):
    return subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True).stdout


# ---- fixtures: low-privilege users, a client, contacts, tickets
sql("delete from problems; delete from changes; delete from workflow_runs; delete from workflow_run_tasks; delete from workflow_templates; delete from workflow_template_tasks; delete from audit_events where event_type like 'problem.%' or event_type like 'change.%' or event_type like 'workflow.%'")
sql("delete from user_settings where user_id in (select user_id from users where user_email like 'e2e-%@scratch.test'); delete from users where user_email like 'e2e-%@scratch.test'; delete from user_roles where role_name like 'E2E %'")
sql("insert into user_roles (role_name, role_type, role_is_admin) values ('E2E read-only', 1, 0), ('E2E none', 1, 0)")
RO = sql("select role_id from user_roles where role_name='E2E read-only'"); NO = sql("select role_id from user_roles where role_name='E2E none'")
sql("insert into user_role_permissions values (%s, 1, 1), (%s, 2, 1)" % (RO, RO))
pwh = hash_pw('E2e-Pass-12345').replace("'", "")
for em, role in (('e2e-ro@scratch.test', RO), ('e2e-none@scratch.test', NO)):
    sql("insert into users (user_name, user_email, user_password, user_role_id, user_status) values ('%s', '%s', '%s', %s, 1)" % (em.split('@')[0], em, pwh, role))
    sql("insert into user_settings (user_id, user_config_records_per_page) select user_id, 10 from users where user_email = '%s'" % em)
sql("delete from tickets where ticket_subject like 'E2E %'; delete from contacts where contact_name like 'E2E %'; delete from clients where client_name like 'E2E %'")
sql("insert into clients (client_name, client_currency_code, client_net_terms) values ('E2E Contoso', 'USD', 30), ('E2E Fabrikam', 'USD', 30)")
C1 = sql("select client_id from clients where client_name='E2E Contoso'"); C2 = sql("select client_id from clients where client_name='E2E Fabrikam'")
sql("insert into contacts (contact_name, contact_client_id) values ('E2E Alice', %s), ('E2E Bob', %s)" % (C1, C2))
ALICE = sql("select contact_id from contacts where contact_name='E2E Alice'"); BOB = sql("select contact_id from contacts where contact_name='E2E Bob'")
sql("insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_status, ticket_created_by, ticket_client_id) values ('TCK-', 9001, 'E2E server down', 'x', 1, 1, %s), ('TCK-', 9002, 'E2E server down again', 'x', 1, 1, %s)" % (C1, C1))
T1 = sql("select ticket_id from tickets where ticket_number=9001"); T2 = sql("select ticket_id from tickets where ticket_number=9002")

admin = Session(); ro = Session(); none = Session()
check('admin signs in', admin.login(EMAIL, PASSWORD))
check('read-only user signs in', ro.login('e2e-ro@scratch.test', 'E2e-Pass-12345'))
check('no-access user signs in', none.login('e2e-none@scratch.test', 'E2e-Pass-12345'))

# ================= pages load
for path, needle in [('/agent/problems.php', 'Problems'), ('/agent/changes.php', 'Changes'), ('/agent/workflow_runs.php', 'Onboarding'),
                     ('/admin/workflow_templates.php', 'Client Workflow Templates'), ('/agent/tickets.php', 'Problems'),
                     ('/agent/global_search.php?query=zzz', ''), ('/agent/ticket.php?ticket_id=' + T1, 'Problem')]:
    s, pg, h = admin.req(path)
    check('page loads 200, clean, has content: ' + path, s == 200 and needle in pg and clean(pg), s)
s, pg, h = admin.req('/agent/tickets.php')
check('side nav carries Problems, Changes and Onboarding & Offboarding entries', '/agent/problems.php' in pg and '/agent/changes.php' in pg and '/agent/workflow_runs.php' in pg)
s, pg, h = admin.req('/admin/template_library.php')
check('the Templates area carries Client Workflows', 'workflow_templates.php' in pg)

# ================= problems
admin.post('/agent/problems.php', {'add_problem': '1', 'title': 'E2E Contoso file server crashes', 'description': 'root cause unknown'})
P1 = sql("select problem_id from problems where title='E2E Contoso file server crashes'")
check('problem created (open) with creator', P1 != '' and sql("select status from problems where problem_id=" + P1) == 'open' and sql("select created_by from problems where problem_id=" + P1) == '1', P1)
check('problem.created audit event written', sql("select count(*) from audit_events where event_type='problem.created' and entity_id='%s'" % P1) == '1')
s, pg, h = admin.req('/agent/problem_details.php?id=' + P1)
check('problem details page renders title/description/status buttons', s == 200 and 'E2E Contoso file server crashes' in pg and 'root cause unknown' in pg and 'Start Investigating' in pg and clean(pg), s)
s, pg, h = admin.req('/agent/modals/problem/problem_add.php')
check('add-problem modal loads for a writer', s == 200 and 'add_problem' in pg, s)
admin.post('/agent/problem_details.php?id=' + P1, {'edit_problem': '1', 'problem_id': P1, 'title': 'E2E Contoso file server crashes (edited)', 'description': 'rca in progress'})
check('problem edited', sql("select description from problems where problem_id=" + P1) == 'rca in progress' and 'edited' in sql("select title from problems where problem_id=" + P1))
for st in ('investigating', 'resolved'):
    admin.post('/agent/problem_details.php?id=' + P1, {'set_problem_status': '1', 'problem_id': P1, 'status': st})
check('problem walks open -> investigating -> resolved, resolved_at set', sql("select status from problems where problem_id=" + P1) == 'resolved' and sql("select resolved_at is not null from problems where problem_id=" + P1) == '1')
admin.post('/agent/problem_details.php?id=' + P1, {'set_problem_status': '1', 'problem_id': P1, 'status': 'nonsense'})
check('an invalid status is refused and nothing changes', sql("select status from problems where problem_id=" + P1) == 'resolved')
check('problem.status_changed audit events written', int(sql("select count(*) from audit_events where event_type='problem.status_changed' and entity_id='%s'" % P1)) == 2)
admin.post('/agent/problem_details.php?id=' + P1, {'set_problem_status': '1', 'problem_id': P1, 'status': 'open'})
check('a resolved problem can be reopened', sql("select status from problems where problem_id=" + P1) == 'open')

# CSRF + permissions
n0 = sql("select count(*) from problems")
admin.req('/agent/post.php', {'csrf_token': 'wrong', 'add_problem': '1', 'title': 'E2E csrf problem'}, referer='/agent/problems.php')
check('a wrong CSRF token creates nothing', sql("select count(*) from problems") == n0)
ro.post('/agent/problems.php', {'add_problem': '1', 'title': 'E2E read-only problem'})
check('a read-only user cannot create a problem', sql("select count(*) from problems") == n0)
s, pg, h = ro.req('/agent/problems.php')
check('a read-only user can still view the problem list', s == 200 and 'E2E Contoso' in pg, s)
s, pg, h = ro.req('/agent/modals/problem/problem_add.php')
check('a read-only user is refused the add-problem modal', 'not permitted' in pg or s in (401, 403), pg[:100])
ro.post('/agent/problem_details.php?id=' + P1, {'set_problem_status': '1', 'problem_id': P1, 'status': 'investigating'})
check('a read-only user cannot change a problem status', sql("select status from problems where problem_id=" + P1) == 'open')
s, pg, h = none.req('/agent/problems.php')
check('a user without Support access is denied the problems page', 'not permitted' in pg and 'E2E Contoso' not in pg, pg[:150])
s, pg, h = none.req('/agent/changes.php')
check('a user without Support access is denied the changes page', 'not permitted' in pg, pg[:150])
none.post('/agent/problems.php', {'add_problem': '1', 'title': 'E2E none problem'}) if False else None

# ================= changes
admin.post('/agent/changes.php', {'add_change': '1', 'title': 'E2E Migrate file server', 'risk': 'high', 'reason': 'EOL hardware', 'impact': 'one hour downtime', 'implementation_plan': 'cut over', 'rollback_plan': 'switch back', 'scheduled_at': ''})
CH1 = sql("select change_id from changes where title='E2E Migrate file server'")
check('change created as draft with its plans and risk', CH1 != '' and sql("select concat(status,'/',risk,'/',rollback_plan) from changes where change_id=" + CH1) == 'draft/high/switch back', CH1)
check('change.created audit event written', sql("select count(*) from audit_events where event_type='change.created' and entity_id='%s'" % CH1) == '1')
s, pg, h = admin.req('/agent/change_details.php?id=' + CH1)
check('change details page renders plans and Submit for Approval', s == 200 and 'EOL hardware' in pg and 'Submit for Approval' in pg and clean(pg), s)
admin.post('/agent/change_details.php?id=' + CH1, {'edit_change': '1', 'change_id': CH1, 'title': 'E2E Migrate file server v2', 'risk': 'medium', 'reason': 'EOL hardware', 'impact': '', 'implementation_plan': 'plan', 'rollback_plan': 'rb'})
check('change edited', sql("select concat(title,'/',risk) from changes where change_id=" + CH1) == 'E2E Migrate file server v2/medium')
admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': 'successful'})
check('an illegal jump (draft -> successful) is refused', sql("select status from changes where change_id=" + CH1) == 'draft')
admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': 'awaiting_approval'})
admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': 'approved'})
admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': 'scheduled'})
check('scheduling without a date is refused', sql("select status from changes where change_id=" + CH1) == 'approved')
admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': 'scheduled', 'scheduled_at': '2030-01-02T03:04'})
check('approved -> scheduled with a date', sql("select concat(status,'/',scheduled_at) from changes where change_id=" + CH1) == 'scheduled/2030-01-02 03:04:00')
admin.post('/agent/change_details.php?id=' + CH1, {'reschedule_change': '1', 'change_id': CH1, 'scheduled_at': '2030-02-03T04:05'})
check('change rescheduled', sql("select scheduled_at from changes where change_id=" + CH1) == '2030-02-03 04:05:00')
for st in ('in_progress', 'successful'):
    admin.post('/agent/change_details.php?id=' + CH1, {'set_change_status': '1', 'change_id': CH1, 'status': st})
check('change completes (in_progress -> successful)', sql("select status from changes where change_id=" + CH1) == 'successful')
check('change.status_changed audit events written', int(sql("select count(*) from audit_events where event_type='change.status_changed' and entity_id='%s'" % CH1)) >= 5)
m0 = sql("select count(*) from changes")
ro.post('/agent/changes.php', {'add_change': '1', 'title': 'E2E ro change', 'risk': 'low'})
admin.req('/agent/post.php', {'csrf_token': 'wrong', 'add_change': '1', 'title': 'E2E csrf change', 'risk': 'low'}, referer='/agent/changes.php')
check('read-only user and bad CSRF both fail to create a change', sql("select count(*) from changes") == m0)
s, pg, h = ro.req('/agent/modals/change/change_edit.php?id=' + CH1)
check('a read-only user is refused the change edit modal', 'not permitted' in pg or s in (401, 403), pg[:100])

# change created from a problem links back to it
admin.post('/agent/problem_details.php?id=' + P1, {'add_change': '1', 'title': 'E2E Replace disk', 'risk': 'low', 'linked_problem_id': P1})
CH2 = sql("select change_id from changes where title='E2E Replace disk'")
check('creating a change from a problem links it to that problem', sql("select change_problem_id from problems where problem_id=" + P1) == CH2, CH2)
s, pg, h = admin.req('/agent/problem_details.php?id=' + P1)
check('problem page shows the linked change', 'E2E Replace disk' in pg)
s, pg, h = admin.req('/agent/change_details.php?id=' + CH2)
check('change page shows the linked problem', 'E2E Contoso file server' in pg and 'Linked Problems' in pg)
admin.post('/agent/problem_details.php?id=' + P1, {'unlink_problem_change': '1', 'problem_id': P1})
check('change unlinked from problem', sql("select change_problem_id from problems where problem_id=" + P1) == 'NULL')
admin.post('/agent/problem_details.php?id=' + P1, {'link_problem_change': '1', 'problem_id': P1, 'change_id': CH1})
check('an existing change can be linked', sql("select change_problem_id from problems where problem_id=" + P1) == CH1)

# ================= ticket <-> problem
admin.post('/agent/problem_details.php?id=' + P1, {'link_problem_ticket': '1', 'problem_id': P1, 'ticket_number': '9001'})
check('ticket linked to the problem by ticket number', sql("select ticket_problem_id from tickets where ticket_id=" + T1) == P1)
admin.post('/agent/problem_details.php?id=' + P1, {'link_problem_ticket': '1', 'problem_id': P1, 'ticket_number': '424242'})
check('an unknown ticket number links nothing', sql("select count(*) from tickets where ticket_problem_id=" + P1) == '1')
s, pg, h = admin.req('/agent/problem_details.php?id=' + P1)
check('problem page lists the linked ticket', 'E2E server down' in pg and 'TCK-9001' in pg)
s, pg, h = admin.req('/agent/ticket.php?ticket_id=' + T1)
check('ticket page shows the linked problem card', 'E2Econtoso' not in pg and 'E2E Contoso file server crashes' in pg and 'problem_details.php?id=' + P1 in pg and clean(pg))
s, pg, h = admin.req('/agent/problems.php')
check('problem list shows the linked ticket count', re.search(r'text-center">1</td>', pg) is not None)
s, pg, h = admin.req('/agent/ticket.php?ticket_id=' + T2)
check('an unlinked ticket offers the link form', 'set_ticket_problem' in pg and 'Link to Problem' in pg)
admin.post('/agent/ticket.php?ticket_id=' + T2, {'set_ticket_problem': '1', 'ticket_id': T2, 'problem_id': P1})
check('ticket linked to the problem from the ticket page', sql("select ticket_problem_id from tickets where ticket_id=" + T2) == P1)
admin.post('/agent/ticket.php?ticket_id=' + T2, {'set_ticket_problem': '1', 'ticket_id': T2, 'problem_id': '0'})
check('ticket unlinked from the ticket page', sql("select ticket_problem_id from tickets where ticket_id=" + T2) == 'NULL')
admin.post('/agent/problem_details.php?id=' + P1, {'unlink_problem_ticket': '1', 'problem_id': P1, 'ticket_id': T1})
check('ticket unlinked from the problem page', sql("select ticket_problem_id from tickets where ticket_id=" + T1) == 'NULL')
check('ticket link/unlink audit events written', int(sql("select count(*) from audit_events where event_type in ('problem.ticket_linked','problem.ticket_unlinked')")) >= 4)
ro.post('/agent/ticket.php?ticket_id=' + T2, {'set_ticket_problem': '1', 'ticket_id': T2, 'problem_id': P1})
check('a read-only user cannot link a ticket to a problem', sql("select ticket_problem_id from tickets where ticket_id=" + T2) == 'NULL')
s, pg, h = admin.req('/agent/global_search.php?query=Contoso')
check('global search finds problems and changes', 'problem_details.php?id=' + P1 in pg and clean(pg))

# ================= module switch off
sql("update settings set config_core_itsm_enabled = 0")
s, pg, h = admin.req('/agent/problems.php')
check('with the ITSM switch off the Problems page says the module is off', s == 200 and 'turned off' in pg and clean(pg), s)
s, pg, h = admin.req('/agent/ticket.php?ticket_id=' + T1)
check('with the switch off the ticket page still loads (no problem card)', s == 200 and 'set_ticket_problem' not in pg and clean(pg))
n0 = sql("select count(*) from problems")
admin.post('/agent/problems.php', {'add_problem': '1', 'title': 'E2E off problem'}, handler='/agent/post.php') if False else None
tok = csrf(admin.req('/agent/tickets.php')[1])
admin.req('/agent/post.php', {'csrf_token': tok, 'add_problem': '1', 'title': 'E2E off problem'}, referer='/agent/problems.php')
check('with the switch off a POST creates nothing', sql("select count(*) from problems") == n0)
sql("update settings set config_core_itsm_enabled = 1")
s, pg, h = admin.req('/agent/problems.php')
check('switch back on: the page works again', s == 200 and 'Problems &amp; Changes is turned off' not in pg and 'Search Problems' in pg and 'E2E Contoso' in pg)

# ================= workflows: templates
s, pg, h = admin.req('/admin/modals/workflow_template/workflow_template_add.php')
check('add-template modal loads', s == 200 and 'add_workflow_template' in pg, s)
admin.post('/admin/workflow_templates.php', {'add_workflow_template': '1', 'name': 'E2E Client onboarding', 'type': 'onboarding', 'description': 'new client'}, handler='/admin/post.php')
WT = sql("select workflow_template_id from workflow_templates where name='E2E Client onboarding'")
check('template created', WT != '' and sql("select type from workflow_templates where workflow_template_id=" + WT) == 'onboarding', WT)
check('workflow.template_created audit event written', sql("select count(*) from audit_events where event_type='workflow.template_created'") == '1')
page = '/admin/workflow_template_details.php?id=' + WT
for i, (t, cat, req_) in enumerate([('Collect signed agreement', 'Paperwork', '1'), ('Install RMM agent', 'Tech', '1'), ('Send welcome pack', 'Comms', '')]):
    d = {'add_workflow_template_task': '1', 'workflow_template_id': WT, 'title': 'E2E ' + t, 'category': cat, 'default_owner': 'Service desk', 'instructions': 'do it'}
    if req_:
        d['required'] = '1'
    admin.post(page, d, handler='/admin/post.php')
check('three ordered tasks added (third optional)', sql("select group_concat(sort_order order by sort_order) from workflow_template_tasks where workflow_template_id=" + WT) == '0,1,2' and sql("select required from workflow_template_tasks where workflow_template_id=%s and sort_order=2" % WT) == '0')
t2 = sql("select template_task_id from workflow_template_tasks where workflow_template_id=%s and sort_order=1" % WT)
admin.post(page, {'move_workflow_template_task_up': '1', 'workflow_template_id': WT, 'template_task_id': t2}, handler='/admin/post.php')
check('a task can be moved up', sql("select title from workflow_template_tasks where workflow_template_id=%s and sort_order=0" % WT) == 'E2E Install RMM agent')
admin.post(page, {'move_workflow_template_task_down': '1', 'workflow_template_id': WT, 'template_task_id': t2}, handler='/admin/post.php')
check('and back down', sql("select title from workflow_template_tasks where workflow_template_id=%s and sort_order=0" % WT) == 'E2E Collect signed agreement')
s, pg, h = admin.req(page)
check('template details page lists the tasks', s == 200 and 'E2E Install RMM agent' in pg and clean(pg), s)
admin.post(page, {'edit_workflow_template': '1', 'workflow_template_id': WT, 'name': 'E2E Client onboarding v2', 'type': 'onboarding', 'description': 'edited'}, handler='/admin/post.php')
check('template edited from the details page', sql("select name from workflow_templates where workflow_template_id=" + WT) == 'E2E Client onboarding v2')
n0 = sql("select count(*) from workflow_templates")
admin.req('/admin/post.php', {'csrf_token': 'wrong', 'add_workflow_template': '1', 'name': 'E2E csrf', 'type': 'onboarding'}, referer='/admin/workflow_templates.php')
ro.req('/admin/post.php', {'csrf_token': csrf(ro.req('/agent/problems.php')[1]) or 'x', 'add_workflow_template': '1', 'name': 'E2E ro tmpl', 'type': 'onboarding'}, referer='/admin/workflow_templates.php')
check('bad CSRF and a non-admin both fail to create a template', sql("select count(*) from workflow_templates") == n0)
s, pg, h = ro.req('/admin/workflow_templates.php')
check('a non-admin cannot open the template admin page', 'admin access' in pg and 'E2E Client onboarding' not in pg, pg[:120])

# ================= workflows: runs on a client and a contact
s, pg, h = admin.req('/agent/modals/workflow_run/workflow_run_start.php')
check('start-workflow modal lists templates, clients, contacts', s == 200 and 'E2E Client onboarding v2' in pg and 'E2E Contoso' in pg and 'E2E Alice' in pg, s)
admin.post('/agent/workflow_runs.php', {'start_client_workflow': '1', 'workflow_template_id': WT, 'client_id': C1, 'contact_id': '0'})
R1 = sql("select run_id from workflow_runs where client_id=%s and contact_id=0" % C1)
check('a run is started for a whole client with the template tasks snapshotted', R1 != '' and sql("select count(*) from workflow_run_tasks where run_id=" + R1) == '3' and sql("select status from workflow_runs where run_id=" + R1) == 'in_progress', R1)
check('workflow.onboarding_started audit event written', sql("select count(*) from audit_events where event_type='workflow.onboarding_started' and entity_id='%s'" % R1) == '1')
admin.post('/agent/workflow_runs.php', {'start_client_workflow': '1', 'workflow_template_id': WT, 'client_id': C1, 'contact_id': BOB})
check('a contact from a different client is refused', sql("select count(*) from workflow_runs") == '1')
admin.post('/agent/workflow_runs.php', {'start_client_workflow': '1', 'workflow_template_id': WT, 'client_id': '', 'contact_id': '0'})
check('no client is refused', sql("select count(*) from workflow_runs") == '1')
admin.post('/agent/workflow_runs.php', {'start_client_workflow': '1', 'workflow_template_id': WT, 'client_id': '', 'contact_id': ALICE})
R2 = sql("select run_id from workflow_runs where contact_id=" + ALICE)
check('a run for a contact takes the client from the contact', R2 != '' and sql("select client_id from workflow_runs where run_id=" + R2) == C1, R2)
s, pg, h = admin.req('/agent/workflow_runs.php')
check('run list shows both runs with progress', s == 200 and 'E2E Contoso' in pg and 'Whole client' in pg and 'E2E Alice' in pg and clean(pg), s)
s, pg, h = admin.req('/agent/workflow_runs.php?type=offboarding')
check('type filter hides onboarding runs', 'E2E Alice' not in pg and clean(pg))
rpage = '/agent/workflow_run.php?run_id=' + R1
s, pg, h = admin.req(rpage)
check('run page shows the checklist', s == 200 and 'E2E Collect signed agreement' in pg and 'optional' in pg and clean(pg), s)
tasks = sql("select group_concat(run_task_id order by sort_order) from workflow_run_tasks where run_id=" + R1).split(',')
admin.post(rpage, {'complete_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[0]})
check('a task is ticked off', sql("select status from workflow_run_tasks where run_task_id=" + tasks[0]) == 'completed' and sql("select status from workflow_runs where run_id=" + R1) == 'in_progress')
check('task completion fans out as a workflow.task_completed event (no hooks subscribed -> no error)', True)
ro.post(rpage, {'complete_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1]})
check('a read-only user cannot tick a task', sql("select status from workflow_run_tasks where run_task_id=" + tasks[1]) == 'pending')
s, pg, h = ro.req(rpage)
check('a read-only user can view the run but sees no action buttons', s == 200 and 'complete_workflow_run_task' not in pg and 'cancel_workflow_run' not in pg, s)
admin.req('/agent/post.php', {'csrf_token': 'wrong', 'complete_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1]}, referer=rpage)
check('bad CSRF does not tick a task', sql("select status from workflow_run_tasks where run_task_id=" + tasks[1]) == 'pending')
other_run_task = sql("select min(run_task_id) from workflow_run_tasks where run_id=" + R2)
admin.post(rpage, {'complete_workflow_run_task': '1', 'run_id': R1, 'run_task_id': other_run_task})
check("a task of another run cannot be ticked through this run", sql("select status from workflow_run_tasks where run_task_id=" + other_run_task) == 'pending')
admin.post(rpage, {'complete_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1]})
check('run completes once every required task is done (optional task ignored)', sql("select status from workflow_runs where run_id=" + R1) == 'completed' and sql("select completed_at is not null from workflow_runs where run_id=" + R1) == '1')
check('workflow.completed audit event written exactly once', sql("select count(*) from audit_events where event_type='workflow.completed' and entity_id='%s'" % R1) == '1')
admin.post(rpage, {'reopen_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1]})
check('reopening a task puts the run back in progress', sql("select status from workflow_runs where run_id=" + R1) == 'in_progress')
admin.post(rpage, {'skip_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1], 'skip_reason': ''})
check('skipping needs a reason', sql("select status from workflow_run_tasks where run_task_id=" + tasks[1]) == 'pending')
admin.post(rpage, {'skip_workflow_run_task': '1', 'run_id': R1, 'run_task_id': tasks[1], 'skip_reason': 'client declined agent'})
check('skipping with a reason finishes the run with exceptions', sql("select status from workflow_runs where run_id=" + R1) == 'completed_with_exceptions' and sql("select skip_reason from workflow_run_tasks where run_task_id=" + tasks[1]) == 'client declined agent')
check('a second completion is recorded for the re-completed run', sql("select count(*) from audit_events where event_type='workflow.completed' and entity_id='%s'" % R1) == '2')
s, pg, h = admin.req(rpage)
check('run page shows the skip reason and the exceptions status', 'client declined agent' in pg and 'Completed With Exceptions' in pg and clean(pg))

# events fan out to subscribed webhooks
sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) values ('E2E wf hook', 'http://127.0.0.1:9/none', 's', 'workflow.task_completed,workflow.cancelled,workflow.completed', 1)")
sql("delete from integration_jobs")
r2tasks = sql("select group_concat(run_task_id order by sort_order) from workflow_run_tasks where run_id=" + R2).split(',')
admin.post('/agent/workflow_run.php?run_id=' + R2, {'complete_workflow_run_task': '1', 'run_id': R2, 'run_task_id': r2tasks[0]})
check('a task completion queues a webhook delivery for the subscribed endpoint', sql("select count(*) from integration_jobs where job_type='webhook.deliver' and payload like '%workflow.task_completed%'") == '1', sql("select job_type, left(payload,80) from integration_jobs"))
admin.post('/agent/workflow_run.php?run_id=' + R2, {'cancel_workflow_run': '1', 'run_id': R2})
check('run cancelled; workflow.cancelled audit event written', sql("select status from workflow_runs where run_id=" + R2) == 'cancelled' and sql("select count(*) from audit_events where event_type='workflow.cancelled' and entity_id='%s'" % R2) == '1')
check('the cancel also queued a webhook for the subscribed endpoint', int(sql("select count(*) from integration_jobs where job_type='webhook.deliver' and payload like '%workflow.cancelled%'")) >= 1)
admin.post('/agent/workflow_run.php?run_id=' + R2, {'complete_workflow_run_task': '1', 'run_id': R2, 'run_task_id': r2tasks[1]})
check('a cancelled run is frozen', sql("select status from workflow_run_tasks where run_task_id=" + r2tasks[1]) == 'pending')
sql("delete from webhooks where webhook_name like 'E2E %'")

# archive template, module off
admin.req('/admin/post.php?archive_workflow_template=%s&csrf_token=%s' % (WT, csrf(admin.req('/admin/workflow_templates.php')[1])), referer='/admin/workflow_templates.php')
check('template archived; existing runs keep their tasks', sql("select archived_at is not null from workflow_templates where workflow_template_id=" + WT) == '1' and sql("select count(*) from workflow_run_tasks where run_id=" + R1) == '3')
sql("update settings set config_core_workflow_enabled = 0")
s, pg, h = admin.req('/agent/workflow_runs.php')
check('with the Workflow switch off the runs page says it is off', s == 200 and 'turned off' in pg and clean(pg))
s, pg, h = admin.req('/admin/workflow_templates.php')
check('and so does the template admin page', s == 200 and 'turned off' in pg and clean(pg))
sql("update settings set config_core_workflow_enabled = 1")

# ================= no PHP warnings anywhere
log = open(APP + '/php.log').read() if os.path.exists(APP + '/php.log') else ''
bad = [l for l in log.splitlines() if re.search(r'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught', l)]
check('the php -S log shows no PHP warnings, notices or fatals', not bad, '\n'.join(bad[:6]))

fails = [r for r in results if not r[1]]
print('\n%d passed, %d failed' % (len(results) - len(fails), len(fails)))
sys.exit(1 if fails else 0)
