"""
End-to-end check of the shared date-range filter (RivetCore DateRange, includes/date_range.php + the picker) through the real
web stack: every preset on the ticket list for two date fields, custom / swapped / open-ended ranges, junk input, saved views
that stay rolling, kanban, the Service Desk report, picker markup on every converted page, and legacy URLs.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database (timezone America/Chicago), set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>` (error output to a log file) and pass that log + the app dir so PHP warnings are caught.

  TEST_DB_USER=... TEST_DB_PASS=... PHP_LOG=<php -S log> python3 tests/e2e/date_range.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> [app dir]
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
from datetime import datetime, date, timedelta
from zoneinfo import ZoneInfo
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
TZ = ZoneInfo('America/Chicago')
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PHP_LOG = os.environ.get('PHP_LOG', '')
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION sql_mode=''; " + q], capture_output=True, text=True)
    if out.returncode: print(out.stderr)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
def log_size():
    return os.path.getsize(PHP_LOG) if PHP_LOG and os.path.exists(PHP_LOG) else 0
def log_since(n):
    if not PHP_LOG or not os.path.exists(PHP_LOG): return ''
    with open(PHP_LOG, 'rb') as f:
        f.seek(n); return f.read().decode('utf-8', 'replace')
BAD = re.compile(r'PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Warning:|Notice:|Deprecated:|Fatal error:|Uncaught')

# ---- seed ---------------------------------------------------------------------------------------------------------------
today = datetime.now(TZ).date()
def q_first(y, m):  # first day of the calendar quarter containing month m
    return date(y, (m - 1) // 3 * 3 + 1, 1)
this_q = q_first(today.year, today.month)
last_q_mid = this_q - timedelta(days=45)
last_month_15 = (today.replace(day=1) - timedelta(days=1)).replace(day=15)
POINTS = {
    'today': today, 'yesterday': today - timedelta(days=1), 'd3': today - timedelta(days=3), 'd10': today - timedelta(days=10),
    'd40': today - timedelta(days=40), 'lastmonth': last_month_15, 'lastquarter': last_q_mid,
    'lastyear': date(today.year - 1, 6, 15), 'next5': today + timedelta(days=5), 'next20': today + timedelta(days=20),
}
names = list(POINTS)
def noon(d): return d.strftime('%Y-%m-%d') + ' 12:00:00'
sql("delete from tickets where ticket_subject like 'DRT-%'; delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from invoices where invoice_scope='E2EDR'; delete from clients where client_name='E2E DR Client'")
sql("insert into clients (client_name) values ('E2E DR Client')")
CLIENT = sql("select client_id from clients where client_name='E2E DR Client'")
for i, n in enumerate(names):
    p = POINTS[n]
    created = noon(p); updated = noon(POINTS[names[(i + 1) % len(names)]])
    due = noon(POINTS[names[(i + 2) % len(names)]])
    # ticket_status 2 (Open) with resolved/closed stamped, so Status=All Closed lists every seeded ticket
    sql("insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_status, ticket_priority, ticket_client_id, "
        "ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at, ticket_sla_resolution_due, ticket_due_at, ticket_url_key) "
        "values ('DR', %d, 'DRT-%s', 'seed', 2, 'Low', %s, '%s', '%s', '%s', '%s', '%s', '%s', 'k%d')" % (9100 + i, n, CLIENT, created, updated, created, created, due, due, i))
sql("update user_settings set user_config_records_per_page = 100 where user_id = (select user_id from users where user_email='%s')" % EMAIL)
CREATED = {n: POINTS[n] for n in names}
UPDATED = {n: POINTS[names[(i + 1) % len(names)]] for i, n in enumerate(names)}
DUE = {n: POINTS[names[(i + 2) % len(names)]] for i, n in enumerate(names)}

# ---- independent expectation of every preset (Python re-implementation, Monday weeks) -----------------------------------
def add_months(d, k):
    m = d.month - 1 + k; y = d.year + m // 12; m = m % 12 + 1
    return date(y, m, 1)
def expect(preset):
    t = today
    wk = t - timedelta(days=t.weekday())
    first = t.replace(day=1)
    qs = q_first(t.year, t.month)
    return {
        'today': (t, t), 'yesterday': (t - timedelta(days=1), t - timedelta(days=1)),
        'last7': (t - timedelta(days=6), t), 'last14': (t - timedelta(days=13), t), 'last30': (t - timedelta(days=29), t), 'last90': (t - timedelta(days=89), t),
        'thisweek': (wk, t), 'lastweek': (wk - timedelta(days=7), wk - timedelta(days=1)),
        'thismonth': (first, t), 'lastmonth': (add_months(first, -1), first - timedelta(days=1)),
        'last12months': (add_months(first, -11), t),
        'thisquarter': (qs, t), 'lastquarter': (add_months(qs, -3), qs - timedelta(days=1)),
        'thisyear': (date(t.year, 1, 1), t), 'lastyear': (date(t.year - 1, 1, 1), date(t.year - 1, 12, 31)),
        'next7': (t, t + timedelta(days=6)), 'next30': (t, t + timedelta(days=29)),
        'alltime': (date(1970, 1, 1), date(2099, 12, 31)),
    }[preset]
def in_range(d, rng): return rng[0] <= d <= rng[1]
def expected_set(preset, field):
    m = {'created': CREATED, 'updated': UPDATED, 'resolved': CREATED, 'closed': CREATED, 'due': DUE, 'duedate': DUE}[field]
    if preset == 'alltime': return set(names)
    return {n for n in names if in_range(m[n], expect(preset))}
def custom_set(a, b, field='created'):
    m = {'created': CREATED, 'updated': UPDATED, 'resolved': CREATED, 'closed': CREATED, 'due': DUE, 'duedate': DUE}[field]
    return {n for n in names if (a is None or m[n] >= a) and (b is None or m[n] <= b)}
PRESETS = ['today', 'yesterday', 'last7', 'last14', 'last30', 'last90', 'thisweek', 'lastweek', 'thismonth', 'lastmonth', 'last12months',
           'thisquarter', 'lastquarter', 'thisyear', 'lastyear', 'next7', 'next30', 'alltime']

# ---- sign in ------------------------------------------------------------------------------------------------------------
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))
log0 = log_size()

def listed(path):
    s, page, h = req(path)
    return s, set(re.findall(r'DRT-([a-z0-9]+)', page)), page

# ---- ticket list: each preset x two fields -----------------------------------------------------------------------------
bad_presets = []
for preset in PRESETS:
    for field in ('created', 'updated'):
        s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=%s&datefield=%s' % (preset, field))
        if s != 200 or got != expected_set(preset, field):
            bad_presets.append((preset, field, s, sorted(got ^ expected_set(preset, field))))
check('ticket list: all %d presets x created/updated return exactly the expected tickets' % len(PRESETS), not bad_presets, bad_presets[:3])
bad = []
for preset in ('last7', 'last30', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'next30'):
    for field in ('resolved', 'closed', 'due', 'duedate'):
        s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=%s&datefield=%s' % (preset, field))
        if s != 200 or got != expected_set(preset, field): bad.append((preset, field, sorted(got ^ expected_set(preset, field))))
check('ticket list: resolved / closed / SLA due / due-date fields filter on their own column', not bad, bad[:3])
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=last7')
check('the datefield select and picker are on the ticket list', 'name="datefield"' in page and 'data-date-range-picker' in page and 'name="canned_date"' in page and 'value="last7"' in page, s)
check('preset ranges disable the dtf/dtt inputs (clean URLs)', re.search(r'name="dtf"[^>]*disabled', page) is not None)
check('the button shows the preset and its resolved dates', 'Last 7 days · ' in page)
s, got, page = listed('/agent/tickets.php?status=Open&canned_date=last30&datefield=resolved')
check('Resolved date + Open status shows the explanatory hint', 'Only resolved tickets have a resolved date' in page)

# ---- custom ranges -----------------------------------------------------------------------------------------------------
a, b = today - timedelta(days=12), today - timedelta(days=2)
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtf=%s&dtt=%s' % (a, b))
check('custom range', got == custom_set(a, b), sorted(got ^ custom_set(a, b)))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtf=%s&dtt=%s' % (b, a))
check('custom range with swapped dates is swapped, not empty', got == custom_set(a, b), sorted(got ^ custom_set(a, b)))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtf=%s' % a)
check('custom range with only a start is open-ended', got == custom_set(a, None), sorted(got ^ custom_set(a, None)))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtt=%s' % b)
check('custom range with only an end is open-ended', got == custom_set(None, b), sorted(got ^ custom_set(None, b)))
s, got, page = listed('/agent/tickets.php?status=Closed&dtf=%s&dtt=%s' % (a, b))
check('legacy link with only dtf/dtt (no canned_date) is a custom range', got == custom_set(a, b))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=thismonth&dtf=2001-01-01&dtt=2001-01-02')
check('a preset wins over stray dtf/dtt', got == expected_set('thismonth', 'created'))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtf=%s&dtt=%s' % (a, b))
check('custom range renders enabled dtf/dtt hidden inputs with the dates', re.search(r'name="dtf" value="%s"(?![^>]*disabled)' % a, page) is not None)

# ---- invalid input: fall back, no warnings -----------------------------------------------------------------------------
n0 = log_size()
bad = []
for qs in ['canned_date=bogus', 'canned_date=custom&dtf=garbage&dtt=also', 'canned_date=custom&dtf=2026-02-31', 'canned_date[]=x', 'dtf[]=1&dtt[]=2',
           'canned_date=custom&dtf=0000-00-00&dtt=9999-99-99', 'datefield=evil%27%3B--&canned_date=last7', 'datefield[]=x', 'canned_date=%27%20OR%201%3D1--',
           'canned_date=custom&dtf=1500-01-01&dtt=3000-01-01']:
    s, got, page = listed('/agent/tickets.php?status=Closed&' + qs)
    if s != 200 or BAD.search(page) or 'mysqli_sql_exception' in page: bad.append((qs, s))
check('junk date input returns 200 with no PHP warnings in the page', not bad, bad)
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=bogus')
check('an unknown preset falls back to all time', got == set(names))
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=last7&datefield=evil%27%3B--')
check('an unknown datefield falls back to created', got == expected_set('last7', 'created') and 'value="created" selected' in page)
check('no PHP warnings/notices in the php -S log for those requests', not BAD.search(log_since(n0)), BAD.findall(log_since(n0))[:3])

# ---- kanban and the Service Desk report --------------------------------------------------------------------------------
bad = []
for preset in ('last7', 'lastmonth', 'thisyear'):
    for field in ('created', 'updated'):
        s, page = req('/agent/tickets.php?view=kanban&status=Closed&canned_date=%s&datefield=%s' % (preset, field))[:2]
        got = set(re.findall(r'DRT-([a-z0-9]+)', page))
        if s != 200 or got != expected_set(preset, field): bad.append((preset, field, s, sorted(got ^ expected_set(preset, field))))
check('kanban respects the range and date field', not bad, bad[:3])
bad = []
for preset in ('today', 'last7', 'last30', 'lastmonth', 'thisyear', 'lastyear', 'last12months'):
    s, page = req('/agent/reports/service_desk.php?canned_date=' + preset)[:2]
    m = re.search(r'info-box-text">Opened</span>\s*<span class="info-box-number">(\d+)', page)
    exp = len([n for n in names if in_range(CREATED[n], expect(preset))])
    if s != 200 or not m or int(m.group(1)) != exp: bad.append((preset, s, m.group(1) if m else None, exp))
check('Service Desk report counts exactly the tickets opened in the range', not bad, bad)
s, page = req('/agent/reports/service_desk.php?canned_date=custom&dtf=%s&dtt=%s' % (a, b))[:2]
m = re.search(r'info-box-text">Opened</span>\s*<span class="info-box-number">(\d+)', page)
check('Service Desk report custom range', m and int(m.group(1)) == len(custom_set(a, b)), m.group(1) if m else None)

# ---- saved views stay rolling ------------------------------------------------------------------------------------------
def token():
    s, page, h = req('/agent/tickets.php'); return csrf(page)
def post(d):
    d = dict(d); d['csrf_token'] = token(); return req('/agent/post.php', d, referer='/agent/tickets.php')
def stored(name): return sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_name='%s'" % name)
def vid(name): return sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='%s'" % name)
s, m, h = req('/agent/modals/ticket/ticket_saved_view_add.php?status=Open&canned_date=last7&datefield=updated')
m = json.loads(m).get('content', '') if s == 200 else ''
check('the Save View dialog carries the current range and date field into the form', 'name="f_canned_date" value="last7"' in m and 'name="f_datefield"' in m and 'value="updated" selected' in m, s)
post({'name': 'E2E Rolling', 'icon': 'fa-fire', 'filters_present': '1', 'f_status_mode': 'closed', 'f_canned_date': 'last7', 'f_dtf': '2020-01-01', 'f_dtt': '2020-01-31', 'f_datefield': 'updated', 'add_ticket_saved_view': '1'})
st = stored('E2E Rolling')
check('a preset view stores canned_date + datefield and NO dtf/dtt (stays rolling)', 'canned_date=last7' in st and 'datefield=updated' in st and 'dtf' not in st and 'dtt' not in st, st)
post({'name': 'E2E Fixed', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'closed', 'f_canned_date': 'custom', 'f_dtf': str(b), 'f_dtt': str(a), 'add_ticket_saved_view': '1'})
st = stored('E2E Fixed')
check('a custom view stores the (normalised) fixed dates', 'canned_date=custom' in st and 'dtf=%s' % a in st and 'dtt=%s' % b in st and 'datefield' not in st, st)
post({'name': 'E2E AllTime', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'closed', 'f_canned_date': 'alltime', 'f_datefield': 'updated', 'add_ticket_saved_view': '1'})
check('an all-time view stores no date parameters', stored('E2E AllTime') == 'status=Closed', stored('E2E AllTime'))
post({'name': 'E2E Junk', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'closed', 'f_canned_date': "x' OR 1=1", 'f_dtf': 'zzz', 'f_datefield': "x'", 'add_ticket_saved_view': '1'})
check('a junk date in the view form stores nothing', stored('E2E Junk') == 'status=Closed', stored('E2E Junk'))
s, page, h = req('/agent/tickets.php')
check('the saved views sidebar links the rolling view with canned_date (no dates)', re.search(r'href="\?status=Closed&amp;canned_date=last7&amp;datefield=updated"', page) is not None, re.findall(r'href="\?status=Closed[^"]*"', page)[:5])
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=last7&datefield=updated')
check('following the rolling view resolves the range today (expected set)', got == expected_set('last7', 'updated'))
check('the rolling view is highlighted as active on its own URL', re.search(r'nav-link flex-grow-1 active"[^>]*href="\?status=Closed&amp;canned_date=last7', page) is not None or re.search(r'active"\s+href="\?status=Closed&amp;canned_date=last7', page) is not None)
V = vid('E2E Rolling')
s, m, h = req('/agent/modals/ticket/ticket_saved_view_edit.php?id=' + V)
m = json.loads(m).get('content', '') if s == 200 else ''
check('the Edit dialog says "Date range: Last 7 days (rolling) on updated"', 'Date range: Last 7 days (rolling) on updated' in m, s)
post({'name': 'E2E Rolling', 'icon': 'fa-fire', 'ticket_saved_view_id': V, 'filters_present': '1', 'f_status_mode': 'closed', 'f_canned_date': 'lastmonth', 'f_datefield': 'created', 'edit_ticket_saved_view': '1'})
check('editing the view changes its range', 'canned_date=lastmonth' in stored('E2E Rolling') and 'datefield' not in stored('E2E Rolling'), stored('E2E Rolling'))
post({'name': 'E2E Rolling renamed', 'ticket_saved_view_id': V, 'icon': 'fa-fire', 'edit_ticket_saved_view': '1'})
check('a plain rename leaves the date range alone', 'canned_date=lastmonth' in sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_id=" + V))
# an old view with absolute dates keeps working
sql("insert into ticket_saved_views (ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query, ticket_saved_view_user_id, ticket_saved_view_order) values ('E2E Old', 'fa-filter', 'status=Closed&canned_date=custom&dtf=%s&dtt=%s', 0, 99)" % (a, b))
s, page, h = req('/agent/tickets.php')
check('an old absolute-date view is still listed and its link works', 'dtf=%s' % a in page)
s, got, page = listed('/agent/tickets.php?status=Closed&canned_date=custom&dtf=%s&dtt=%s' % (a, b))
check('an old absolute-date view still filters', got == custom_set(a, b) and re.search(r'active"[^>]*href="\?status=Closed&amp;canned_date=custom&amp;dtf=%s' % a, page) is not None)

# ---- picker on every converted page; legacy URLs --------------------------------------------------------------------------
PAGES = ['/agent/invoices.php', '/agent/payments.php', '/agent/recurring_invoices.php', '/agent/expenses.php', '/agent/recurring_expenses.php',
         '/agent/revenues.php', '/agent/transfers.php', '/agent/quotes.php', '/agent/projects.php', '/agent/clients.php', '/agent/trips.php',
         '/agent/notifications.php', '/agent/tickets.php', '/agent/tickets.php?client_id=' + CLIENT, '/agent/reports/service_desk.php',
         '/agent/reports/technician_performance.php', '/agent/reports/csat.php', '/agent/reports/rmm_health.php',
         '/agent/reports/client_ticket_time_detail.php', '/admin/app_log.php', '/admin/audit_log.php', '/admin/email_log.php', '/admin/mail_queue.php']
bad = []
n0 = log_size()
for p in PAGES:
    s, page, h = req(p)
    ok = s == 200 and 'data-date-range-picker' in page and 'name="canned_date"' in page and 'dateFilter' not in page and not BAD.search(page)
    if p.endswith('client_ticket_time_detail.php'): ok = ok and 'name="from"' in page and 'name="to"' in page
    else: ok = ok and 'name="dtf"' in page and 'name="dtt"' in page
    if not ok: bad.append((p, s))
check('picker markup (+ hidden canned_date/dtf/dtt) on all %d converted pages' % len(PAGES), not bad, bad)
check('every page loads js/date_range_picker.js and the old #dateFilter is gone', 'date_range_picker.js' in req('/agent/tickets.php')[1])
bad = []
for p in PAGES:
    for qs in ('canned_date=thismonth', 'canned_date=custom&dtf=2020-01-01&dtt=2020-12-31', 'dtf=2020-01-01&dtt=2020-12-31', 'canned_date=bogus&dtf=x'):
        s, page, h = req(p + ('&' if '?' in p else '?') + qs)
        if s != 200 or BAD.search(page): bad.append((p, qs, s))
check('legacy and junk URLs return 200 with no warnings on every converted page', not bad, bad[:4])
check('no PHP warnings in the php -S log across all page loads', not BAD.search(log_since(n0)), BAD.findall(log_since(n0))[:3])

sql("insert into invoices (invoice_prefix, invoice_number, invoice_scope, invoice_status, invoice_date, invoice_due, invoice_amount, invoice_url_key, invoice_client_id, invoice_category_id, invoice_currency_code) values "
    "('E2E', 7001, 'E2EDR', 'Draft', '%s', '%s', 1, 'e2edr1', %s, 0, 'USD'), ('E2E', 7002, 'E2EDR', 'Draft', '%s', '%s', 1, 'e2edr2', %s, 0, 'USD')" % (today, today, CLIENT, today - timedelta(days=400), today - timedelta(days=400), CLIENT))
s, page, h = req('/agent/invoices.php?canned_date=thismonth&status=')
check('legacy ?canned_date=thismonth filters invoices (today in, 400 days ago out)', s == 200 and 'E2E7001' in page and 'E2E7002' not in page, s)
s, page, h = req('/agent/invoices.php?canned_date=custom&dtf=%s&dtt=%s' % (today - timedelta(days=401), today - timedelta(days=399)))
check('legacy ?dtf=..&dtt=..&canned_date=custom filters invoices', s == 200 and 'E2E7002' in page and 'E2E7001' not in page, s)
s, page, h = req('/agent/invoices.php?canned_date=alltime')
check('all time shows both invoices', 'E2E7001' in page and 'E2E7002' in page)
s, page, h = req('/agent/payments.php?canned_date=custom&dtf=2020-01-01&dtt=2020-12-31')
check('legacy custom link on payments renders the custom dates in the hidden inputs', 'name="dtf" value="2020-01-01"' in page and 'name="dtt" value="2020-12-31"' in page)

# ---- weeks / timezone: server "today" equals the app timezone's today ------------------------------------------------------
s, page, h = req('/agent/tickets.php?status=Closed&canned_date=today')
check('"today" is the app timezone\'s today (Chicago), not the server\'s', ('DRT-today' in page) and ('DRT-yesterday' not in page))

# ---- EXPLAIN (report only) ------------------------------------------------------------------------------------------------
lo, hi = '%s 00:00:00' % (today - timedelta(days=6)), '%s 00:00:00' % (today + timedelta(days=1))
new = sql("EXPLAIN SELECT * FROM tickets WHERE ticket_resolved_at IS NULL AND (ticket_created_at >= '%s' AND ticket_created_at < '%s')" % (lo, hi))
old = sql("EXPLAIN SELECT * FROM tickets WHERE ticket_resolved_at IS NULL AND DATE(ticket_created_at) BETWEEN '%s' AND '%s'" % (lo[:10], hi[:10]))
print('EXPLAIN new (sargable):', new.replace('\n', ' | '))
print('EXPLAIN old (DATE() BETWEEN):', old.replace('\n', ' | '))
print('tickets indexes:', sql("select group_concat(distinct index_name) from information_schema.statistics where table_schema=database() and table_name='tickets'"))

sql("delete from tickets where ticket_subject like 'DRT-%'; delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from invoices where invoice_scope='E2EDR'; delete from clients where client_name='E2E DR Client'")
failed = [r for r in results if not r[1]]
print('\n%d passed, %d failed' % (len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
