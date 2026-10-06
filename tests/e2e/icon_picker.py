"""
End-to-end check of the icon catalog picker (includes/icon_picker.php, js/icon_picker.js) on every form that asks for an icon:
saved ticket views, tags and custom links. Verifies, through the real web stack, that each modal ships the picker markup and a hidden
input carrying the current value, that the catalog JSON is emitted once per page, that the shared footer loads the JS, and that the
handlers store the NORMALISED value (catalogued, 'fas fa-fire', uncatalogued-but-valid, empty, hostile, over-long).
Saved views store 'fa-xxx'; tags and custom links keep their historical bare name ('fire') because every template renders "fa-<icon>".

Needs a THROWAWAY app (scripts/setup_cli.php run from scripts/, $config_https_only = FALSE, `php -S`), TEST_DB_USER / TEST_DB_PASS:
  TEST_DB_USER=.. TEST_DB_PASS=.. python3 tests/e2e/icon_picker.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> [<app dir>]
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
APP_DIR = sys.argv[5] if len(sys.argv) > 5 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
    def req(path, data=None, referer=None):
        headers = {'Referer': BASE + referer} if referer else {}
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            r = op.open(urllib.request.Request(BASE + path, data=body, headers=headers)); return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')
    return req
def sql(q): return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(h):
    m = re.search(r'name="csrf_token" value="([^"]+)"', h); return m.group(1) if m else None
res = []
def check(n, ok, d=''):
    res.append(bool(ok)); print(('PASS' if ok else 'FAIL') + '  ' + n + ((' [' + str(d)[:300] + ']') if d and not ok else ''))
def modal(req, path):
    s, b = req(path)
    try: return s, json.loads(b).get('content', '')
    except Exception: return s, b

req = session()
t = csrf(req('/login.php')[1])
check('sign in', req('/login.php', {'email': EMAIL, 'password': PASSWORD, 'login': '', 'csrf_token': t})[0] in (302, 303))
def token():
    return csrf(req('/agent/tickets.php')[1]) or csrf(modal(req, '/agent/modals/ticket/ticket_saved_view_add.php')[1])

sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from tags where tag_name like 'E2E %'; delete from custom_links where custom_link_name like 'E2E %'")
sql("insert into tags (tag_name, tag_type, tag_color, tag_icon) values ('E2E Tag Bare', 6, '#ff0000', 'handshake'), ('E2E Tag Legacy', 6, '#00ff00', 'fas fa-cog')")
sql("insert into custom_links (custom_link_name, custom_link_uri, custom_link_new_tab, custom_link_icon, custom_link_order, custom_link_location) values ('E2E Link Custom', 'https://example.com', 0, 'dragon', 1, 1)")
sql("insert into ticket_saved_views (ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query) values ('E2E View Custom', 'fa-dragon', 'status=Open')")
TAG_BARE = sql("select tag_id from tags where tag_name='E2E Tag Bare'"); TAG_LEG = sql("select tag_id from tags where tag_name='E2E Tag Legacy'")
LINK = sql("select custom_link_id from custom_links where custom_link_name='E2E Link Custom'")
VIEW = sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='E2E View Custom'")

def picker_ok(html, value):
    return ('data-icon-picker' in html and 'class="icon-picker-button"' in html and 'aria-haspopup="dialog"' in html
            and re.search(r'<input type="hidden" name="icon" [^>]*value="%s"' % re.escape(value), html) is not None
            and '<input type="text" class="form-control" name="icon"' not in html)

forms = {
  'saved view add':   ('/agent/modals/ticket/ticket_saved_view_add.php?status=Open', 'fa-filter'),
  'saved view edit':  ('/agent/modals/ticket/ticket_saved_view_edit.php?id=' + VIEW, 'fa-dragon'),
  'tag add':          ('/admin/modals/tag/tag_add.php?type=6', 'fa-tag'),
  'tag edit (bare)':  ('/admin/modals/tag/tag_edit.php?id=' + TAG_BARE, 'fa-handshake'),
  'tag edit (legacy "fas fa-cog")': ('/admin/modals/tag/tag_edit.php?id=' + TAG_LEG, 'fa-cog'),
  'custom link add':  ('/admin/modals/custom_link/custom_link_add.php', 'fa-link'),
  'custom link edit': ('/admin/modals/custom_link/custom_link_edit.php?id=' + LINK, 'fa-dragon'),
}
for n, (path, val) in forms.items():
    s, m = modal(req, path)
    check('%s: picker markup + hidden input with the current value (%s)' % (n, val), s == 200 and picker_ok(m, val), s)
    check('%s: catalog JSON present exactly once' % n, m.count('class="icon-picker-catalog"') == 1, m.count('class="icon-picker-catalog"'))
s, m = modal(req, forms['saved view add'][0])
j = re.search(r'<script type="application/json" class="icon-picker-catalog"[^>]*>(.*?)</script>', m, re.S)
try: cat = json.loads(j.group(1)); good = len(cat['icons']) > 100 and cat['version'] >= 1 and 'fa-fire' in [i['c'] for i in cat['icons']]
except Exception as e: good = False
check('catalog JSON parses and lists icons (incl. fa-fire)', good)
check('catalog JSON cannot terminate its script element early', '</' not in j.group(1) if j else False)
s, page = req('/agent/tickets.php')
check('the shared footer loads js/icon_picker.js', re.search(r'<script src="/js/icon_picker\.js\?v=\d+" defer></script>', page) is not None)
check('js/icon_picker.js is served', req('/js/icon_picker.js')[0] == 200)

# ---- handler normalisation
CASES = [('catalogued', 'fa-fire', 'fa-fire'), ('style prefix', 'fas fa-fire', 'fa-fire'), ('uncatalogued-but-valid', 'fa-zzz-unknownicon9', 'fa-zzz-unknownicon9'),
         ('empty', '', None), ('hostile quote/script', '"><script>', None), ('two words', 'fa-x y', None), ('300 chars', 'fa-' + 'a' * 297, None), ('uppercase/bare', 'Fire', 'fa-fire')]
def post(path, referer, d):
    d = dict(d); d['csrf_token'] = token(); return req(path, d, referer=referer)
for label, raw, want in CASES:
    nm = 'E2E ' + label
    post('/agent/post.php', '/agent/tickets.php', {'name': nm + ' v', 'icon': raw, 'filters_present': '1', 'f_status_mode': 'all', 'add_ticket_saved_view': '1'})
    got = sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_name='%s'" % (nm + ' v'))
    exp = want or 'fa-filter'
    check('saved view [%s] stores %s' % (label, exp), got == exp, got)
    post('/admin/post.php', '/admin/tag.php', {'name': nm + ' t', 'type': '6', 'color': '#123456', 'icon': raw, 'add_tag': '1'})
    got = sql("select tag_icon from tags where tag_name='%s'" % (nm + ' t'))
    exp = (want or 'fa-tag')[3:]
    check('tag [%s] stores bare %s' % (label, exp), got == exp, got)
    post('/admin/post.php', '/admin/custom_link.php', {'name': nm + ' l', 'uri': 'https://example.com', 'icon': raw, 'location': '1', 'add_custom_link': '1'})
    got = sql("select custom_link_icon from custom_links where custom_link_name='%s'" % (nm + ' l'))
    exp = (want or 'fa-link')[3:]
    check('custom link [%s] stores bare %s' % (label, exp), got == exp, got)

# ---- edits keep / replace the value
post('/agent/post.php', '/agent/tickets.php', {'ticket_saved_view_id': VIEW, 'name': 'E2E View Custom', 'icon': 'fa-dragon', 'edit_ticket_saved_view': '1'})
check('saved view edit round-trips an uncatalogued class', sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_id=%s" % VIEW) == 'fa-dragon')
post('/admin/post.php', '/admin/tag.php', {'tag_id': TAG_BARE, 'name': 'E2E Tag Bare', 'type': '6', 'color': '#ff0000', 'icon': 'fa-handshake', 'edit_tag': '1'})
check('tag edit keeps the bare storage format', sql("select tag_icon from tags where tag_id=%s" % TAG_BARE) == 'handshake')
post('/admin/post.php', '/admin/custom_link.php', {'custom_link_id': LINK, 'name': 'E2E Link Custom', 'uri': 'https://example.com', 'icon': 'fa-server', 'location': '1', 'edit_custom_link': '1'})
check('custom link edit stores the newly picked icon', sql("select custom_link_icon from custom_links where custom_link_id=%s" % LINK) == 'server')

# ---- permissions unchanged: anonymous visitors get no modal and cannot post
anon = session()
for path in ('/admin/modals/tag/tag_add.php', '/admin/modals/custom_link/custom_link_add.php', '/agent/modals/ticket/ticket_saved_view_add.php'):
    s, b = anon(path)
    check('anonymous request for %s is refused' % path.rsplit('/', 1)[1], s in (301, 302, 303, 401, 403) or 'icon-picker' not in b, s)
n = sql("select count(*) from tags")
anon('/admin/post.php', {'name': 'E2E anon', 'type': '6', 'color': '#000000', 'icon': 'fa-fire', 'add_tag': '1', 'csrf_token': 'x'}, referer='/admin/tag.php')
check('anonymous tag post creates nothing', sql("select count(*) from tags") == n)
check('wrong CSRF token creates nothing', (req('/admin/post.php', {'name': 'E2E csrf', 'type': '6', 'color': '#000000', 'icon': 'fa-fire', 'add_tag': '1', 'csrf_token': 'wrong'}, referer='/admin/tag.php'), sql("select count(*) from tags where tag_name='E2E csrf'"))[1] == '0')

# ---- static: no free-text icon boxes remain in the converted forms
for f in ('agent/modals/ticket/ticket_saved_view_add.php', 'agent/modals/ticket/ticket_saved_view_edit.php', 'admin/modals/tag/tag_add.php', 'admin/modals/tag/tag_edit.php', 'admin/modals/custom_link/custom_link_add.php', 'admin/modals/custom_link/custom_link_edit.php'):
    src = open(os.path.join(APP_DIR, f)).read()
    check('%s uses iconPickerField and no text input named icon' % f, 'iconPickerField(' in src and 'name="icon"' not in src)

sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from tags where tag_name like 'E2E %'; delete from custom_links where custom_link_name like 'E2E %'")
print('\n%d/%d passed' % (sum(res), len(res))); sys.exit(0 if all(res) else 1)
