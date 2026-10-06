"""
End-to-end check of the redesigned Webhook guides hub (Administration > Webhooks > Guides, admin/webhook_guides.php).

Signs in as the administrator and asserts: HTTP 200 and no PHP warning/notice in the `php -S` log; the hero (title, pitch, quick links, the
five-step "How it works" strip); the sidebar and the index grid list all 24 RivetCore destinations (search + category filter markup, "Needs"
pills); every destination has a guide section (header with badge, category and docs link, "What you need", numbered steps with tick boxes,
callouts, "Try it" with a copyable curl and an "Add this webhook" link that preselects the platform, "Example payload" produced by
RivetCore's formatter, signature tabs where the platform can verify, a troubleshooting table, previous/next, "On this page"); the general
"Signing and verifying" and "Allowed internal networks" sections; deep-link anchors; that every "Add this webhook" link loads the add
form for that platform; that nothing secret or unescaped reaches the HTML; that a technician is refused; that the assets exist and the
page links only this page's own CSS/JS; and that tests/fa_icons.php still passes.

Needs a THROWAWAY app (scripts/setup_cli.php run from scripts/, $config_https_only = FALSE, `php -S`), TEST_DB_USER / TEST_DB_PASS,
and PHP_LOG = the file `php -S` writes to (optional; without it the log check is skipped):
  PHP_LOG=/path/php.log TEST_DB_USER=.. TEST_DB_PASS=.. python3 tests/e2e/webhook_guides.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, subprocess, os, html as htmlmod, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PHP_LOG = os.environ.get('PHP_LOG')
TECH_EMAIL, TECH_PASSWORD = 'tech@scratch.test', 'Scratch-Tech-1234'

DEST_IDS = ['n8n', 'node-red', 'activepieces', 'windmill', 'huginn', 'home-assistant', 'apprise', 'ntfy', 'gotify', 'discord', 'mattermost', 'rocketchat', 'slack', 'teams',
            'matrix-hookshot', 'matrix-client', 'telegram', 'zapier', 'make', 'pipedream', 'ifttt', 'generic-json', 'generic-form', 'custom-template']
HAS_SNIPPETS = ['n8n', 'node-red', 'activepieces', 'windmill', 'pipedream', 'generic-json', 'generic-form', 'custom-template']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(op, path, data=None, referer=None):
    headers = {'Referer': BASE + referer} if referer else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        r = op.open(urllib.request.Request(BASE + path, data=body, headers=headers)); return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(page):
    m = re.search(r'name="csrf_token" value="([^"]+)"', page); return m.group(1) if m else None
def login(op, email, pw):
    s, page, h = req(op, '/login.php'); d = {'email': email, 'password': pw, 'login': ''}
    t = csrf(page)
    if t: d['csrf_token'] = t
    return req(op, '/login.php', d)[0] in (302, 303)
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))

log_mark = os.path.getsize(PHP_LOG) if PHP_LOG and os.path.exists(PHP_LOG) else None
admin = session()
check('sign in as the administrator', login(admin, EMAIL, PASSWORD))
s, page, h = req(admin, '/admin/webhook_guides.php')
check('the guides page answers 200', s == 200, s)
check('the shell is intact (top bar, footer scripts)', 'webhook_form.js' in page and '</html>' in page.lower())

# ---------------------------------------------------------------- hero, layout, accessibility
check('hero: title, pitch and the quick links', 'Webhook guides' in page and 'to n8n, Home Assistant, ntfy, Discord and more' in page
      and 'How signing works' in page and 'Allowed internal networks' in page and 'href="webhook_form.php"' in page and 'Add a webhook' in page)
flow = re.search(r'<ol class="wg-flow" aria-label="How it works">(.*?)</ol>', page, re.S)
steps = re.findall(r'<strong>([A-Za-z]+)</strong>', flow.group(1)) if flow else []
check('hero: the "How it works" strip is Event, Format, Sign, Deliver, Retry with icons', steps == ['Event', 'Format', 'Sign', 'Deliver', 'Retry'] and flow.group(1).count('<i class="fas fa-') == 5, steps)
check('landmarks: skip link, main, sidebar nav, labelled search and chips', 'class="wg-skip" href="#wg-main"' in page and '<main class="wg-main" id="wg-main"' in page
      and 'aria-label="Platforms"' in page and 'for="wg-search"' in page and 'data-wg-search' in page and 'aria-label="Filter by category"' in page and 'data-wg-side-toggle' in page)
check('category filter chips: All plus the five categories', len(re.findall(r'data-wg-cat="[a-z]+" aria-pressed', page)) == 6, len(re.findall(r'data-wg-cat="', page)))
check('the page links only its own stylesheet and script (no external fonts or CDNs)', '/css/webhook_guides.css' in page and '/js/webhook_guides.js' in page
      and not re.search(r'(?:href|src)="https?://(?!(?:' + re.escape(urllib.parse.urlparse(BASE).netloc) + r'))[^"]*(?:\.css|\.js)', page.split('wg-hero')[1] if 'wg-hero' in page else page))
for f in ['css/webhook_guides.css', 'js/webhook_guides.js']:
    check('asset exists and is served: ' + f, os.path.exists(os.path.join(APP, f)) and req(admin, '/' + f)[0] == 200)

# ---------------------------------------------------------------- sidebar + index grid
nav = re.findall(r'data-wg-link="([^"]+)" data-wg-cat-of', page)
tiles = re.findall(r'data-wg-tile="([^"]+)"', page)
check('sidebar lists all 24 destinations', sorted(nav) == sorted(DEST_IDS), (len(nav), set(DEST_IDS) ^ set(nav)))
check('index grid has a card for all 24 destinations', sorted(tiles) == sorted(DEST_IDS), (len(tiles), set(DEST_IDS) ^ set(tiles)))
check('sidebar: groups by category with the Basics links (signing, networks, n8n walk-through)', all(c in page for c in ['Automation platforms', 'Team chat', 'Push and notification services', 'Home automation', 'Generic'])
      and 'data-wg-link="signing"' in page and 'data-wg-link="networks"' in page and 'data-wg-link="receiving-in-n8n"' in page)
check('search markup: every sidebar link and card carries a searchable haystack', len(re.findall(r'data-wg-hay="[^"]+"', page)) >= 48)
tile = {m.group(1): m.group(0) for m in re.finditer(r'<article class="wg-tile" data-wg-tile="([^"]+)".*?</article>', page, re.S)}
check('each card: badge, name, category chip, one-line description, "Needs" pills, View guide and Add webhook', all(
    'wg-badge' in t and '<h5>' in t and 'wg-cat ' in t and 'wg-tile-desc' in t and 'Needs:' in t and 'wg-pill' in t and 'View guide' in t and 'Add webhook' in t for t in tile.values()) and len(tile) == 24)
check('cards derive "Needs" from the destination data (ntfy: Topic; telegram: bot token and chat id; matrix client: room id and token)',
      'Topic' in tile.get('ntfy', '') and 'Bot token' in tile.get('telegram', '') and 'Chat' in tile.get('telegram', '') and 'Token' in tile.get('matrix-client', ''), (tile.get('ntfy', '')[-500:]))
check('brand glyphs where Font Awesome 5.15 has them, letter avatars otherwise', all(x in page for x in ['fab fa-discord', 'fab fa-slack', 'fab fa-telegram-plane', 'fab fa-microsoft', 'fab fa-rocketchat']) and 'wg-badge' in page)

# ---------------------------------------------------------------- general sections
check('"Signing and verifying": V2 header, legacy V1, 5 minute tolerance, tabbed snippets', 'id="signing"' in page and 'X-Rivet-Signature-V2' in page and 'Legacy (V1)' in page
      and '300 seconds' in page and 'id="signing-tab-python"' in page and 'id="signing-tab-node"' in page and 'id="signing-tab-php"' in page and 'id="signing-tab-bash"' in page)
check('"Allowed internal networks": explainer links to the Internal network access card', 'id="networks"' in page and 'href="settings_webhooks.php#internal-networks"' in page and 'endpoint URL not allowed' in page)
n8n_walk = re.search(r'<section[^>]*id="receiving-in-n8n".*?</section>', page, re.S)
check('the Receiving in n8n walk-through is kept (Raw Body, crypto env, snippet, notes)', bool(n8n_walk) and all(x in n8n_walk.group(0) for x in ['Raw Body', 'NODE_FUNCTION_ALLOW_BUILTIN', 'Verify our signature', 'Activate and test', 'Behind a firewall?', 'id="n8n-walkthrough-code"']))

# ---------------------------------------------------------------- every guide
guides = {m.group(1): m.group(0) for m in re.finditer(r'<article class="wg-card wg-section wg-guide" id="([^"]+)".*?</article>', page, re.S)}
check('there is a guide section for all 24 destinations', sorted(guides) == sorted(DEST_IDS), (len(guides), set(DEST_IDS) ^ set(guides)))
def each(pred):
    bad = [i for i, g in guides.items() if not pred(i, g)]
    return not bad, bad
ok, bad = each(lambda i, g: 'wg-guide-head' in g and 'wg-badge' in g and 'wg-cat ' in g and '<h4 class="wg-h1"' in g)
check('each guide has a header with badge, name and category chip', ok, bad)
ok, bad = each(lambda i, g: i in ('generic-json', 'generic-form', 'custom-template') or re.search(r'<a class="wg-doclink" href="https?://[^"]+" target="_blank" rel="noopener noreferrer">', g))
check('each guide links its documentation (external, noopener; the 3 generic presets have none)', ok, bad)
ok, bad = each(lambda i, g: 'What you need' in g and 'wg-checklist' in g and g.count('<li><i class="fas fa-check-circle"') >= 2)
check('each guide has a "What you need" checklist', ok, bad)
ok, bad = each(lambda i, g: 'Set it up' in g and 'data-wg-steps="%s"' % i in g and g.count('class="wg-step"') >= 3 and g.count('data-wg-tick=') == g.count('class="wg-step"'))
check('each guide has numbered steps (card + number badge + tick box per step)', ok, bad)
ok, bad = each(lambda i, g: 'wg-callout' in g and 'role="note"' in g)
check('each guide has callouts for its notes', ok, bad)
ok, bad = each(lambda i, g: re.search(r'<code id="g-%s-curl" data-lang="bash">curl -sS -X (POST|PUT) ' % re.escape(i), g) and 'data-wg-copy="#g-%s-curl"' % i in g and 'data-wg-wrap' in g)
check('each guide has the sample curl with a copy button and a wrap toggle', ok, bad)
ok, bad = each(lambda i, g: re.search(r'<a class="wg-btn wg-btn-primary wg-btn-lg" href="webhook_form\.php\?destination=%s">' % re.escape(urllib.parse.quote(i)), g) and 'Add this webhook' in g)
check('each guide has a prominent "Add this webhook" button preselecting its platform', ok, bad)
ok, bad = each(lambda i, g: 'Example payload' in g and re.search(r'id="g-%s-payload-code" data-lang="(json|text)"' % re.escape(i), g) and '<details class="wg-details"' in g)
check('each guide has a collapsible example payload', ok, bad)
ok, bad = each(lambda i, g: ('role="tablist"' in g and g.count('role="tab"') == 5 and all(k in g for k in ['>Node.js<', '>Python<', '>PHP<', '>Bash<', '>n8n Code node<']) and 'aria-controls=' in g) if i in HAS_SNIPPETS
               else ('cannot verify our signature' in g and 'role="tablist"' not in g))
check('signature tabs (node, python, php, bash, n8n) on the 8 platforms that can verify; an explanation on the other 16', ok, bad)
ok, bad = each(lambda i, g: all(x in g for x in ['<th scope="col">Symptom</th>', '401 or 403', '404 Not Found', '429 Too Many Requests', 'Timeouts or connection errors', 'endpoint URL not allowed', 'Signature mismatch', 'too old']) and 'wg-row-specific' in g)
check('each guide has a troubleshooting table (generic rows plus platform pitfalls)', ok, bad)
ok, bad = each(lambda i, g: 'wg-pager' in g and 'On this page' in g and 'data-wg-jump=' in g)
check('each guide has previous/next navigation and an "On this page" list', ok, bad)
order = re.findall(r'<article class="wg-card wg-section wg-guide" id="([^"]+)"', page)
check('previous/next walk the catalog order (first has no previous, last has no next)', 'rel="prev"' not in guides[order[0]] and 'rel="next"' not in guides[order[-1]] and 'href="#%s"' % order[1] in guides[order[0]], order[:2])
check('deep-link anchors: every guide id is unique and linked from the sidebar and its card', len(set(re.findall(r'\bid="([^"]+)"', page))) == len(re.findall(r'\bid="([^"]+)"', page)) and all('href="#%s"' % i in page for i in DEST_IDS))

# ---------------------------------------------------------------- example payloads: real formatter output, no secrets
pl = {i: htmlmod.unescape(re.search(r'id="g-%s-payload-code"[^>]*>(.*?)</code>' % re.escape(i), g, re.S).group(1)) for i, g in guides.items() if re.search(r'id="g-%s-payload-code"' % re.escape(i), g)}
check('an example payload for all 24 destinations, from the sample ticket.created event', len(pl) == 24 and all('1042' in p or 'Printer on floor 2' in p for p in pl.values()), [i for i, p in pl.items() if 'Printer on floor 2' not in p])
check('example payloads are shaped per platform (discord embeds, slack blocks, telegram HTML, ntfy text, json envelope)', '"embeds"' in pl['discord'] and '"blocks"' in pl['slack'] and 'parse_mode' in pl['telegram'] and '"event": "ticket.created"' in pl['n8n'] and 'Printer on floor 2' in pl['ntfy'], pl['ntfy'][:200])
secretish = re.compile(r'(?i)(password|passwd|secret|api[_-]?key|authorization|bearer\s+[a-z0-9]{12,})')
check('no secret-looking value in any example payload', not any(secretish.search(p) for p in pl.values()), [i for i, p in pl.items() if secretish.search(p)])
check('the page never carries a stored webhook secret, url or token', not re.search(r'\b[0-9a-f]{64}\b', re.sub(r'v1=[0-9a-f]{64}', '', page)), '')

# ---------------------------------------------------------------- escaping
s, form, h = req(admin, '/admin/webhook_form.php')
bad_html = [t for t in re.findall(r'<(?:script|iframe|object|embed)\b[^>]*>', page[page.index('id="wg"'):page.index('</main>')], re.I) if 'src="/js/' not in t and 'nonce=' not in t]
check('no stray script/iframe tags (only the shell and this page\'s own script)', not bad_html, bad_html[:3])
check('curl and snippet text is HTML-escaped (a literal "<token>" never becomes a tag)', '&lt;token&gt;' in page and '<token>' not in page and '<id>' not in page.replace('&lt;id&gt;', ''), '')
check('JavaScript never builds markup from page data (no innerHTML in webhook_guides.js)', 'innerHTML' not in open(os.path.join(APP, 'js/webhook_guides.js')).read())

# ---------------------------------------------------------------- the add flow: every "Add this webhook" link loads the preselected platform
s, g, h = req(admin, '/admin/webhook_form.php?destination=n8n')
check('Add this webhook for n8n opens the n8n form (the setup guide is one click away)', s == 200 and 'name="webhook_destination"' in g and ('Setup guide' in g or 'guide' in g.lower()))
links = sorted(set(re.findall(r'href="(webhook_form\.php\?destination=[^"]+)"', page)))
bad = []
for l in links:
    st, body, hh = req(admin, '/admin/' + l)
    pid = l.split('=')[1]
    if st != 200 or ('name="webhook_destination" value="%s"' % pid) not in body and ('value="%s"' % pid) not in body:
        bad.append((l, st))
check('every Add link (24 platforms) loads the add form with its platform preselected', len(links) == 24 and not bad, bad[:3])

# ---------------------------------------------------------------- access control
sql("delete from users where user_email='%s'" % TECH_EMAIL)
ph = subprocess.run(['php', '-r', 'echo password_hash("%s", PASSWORD_DEFAULT);' % TECH_PASSWORD], capture_output=True, text=True).stdout.strip()
sql("insert into users (user_name, user_email, user_password, user_specific_encryption_ciphertext, user_role_id, user_status, user_type) "
    "select 'Scratch Tech', '%s', '%s', user_specific_encryption_ciphertext, 2, 1, 1 from users where user_id=1" % (TECH_EMAIL, ph))
tid = sql("select user_id from users where user_email='%s'" % TECH_EMAIL)
sql("insert ignore into user_settings set user_id=%s" % tid)
tech = session()
check('technician signs in', login(tech, TECH_EMAIL, TECH_PASSWORD))
s, body, h = req(tech, '/admin/webhook_guides.php')
check('a technician is refused the guides page', s in (302, 303, 403) or 'wg-hero' not in body, s)
s, body, h = req(tech, '/admin/webhook_form.php?destination=n8n')
check('a technician is refused the add form too', s in (302, 303, 403) or 'name="webhook_destination"' not in body, s)
anon = session()
s, body, h = req(anon, '/admin/webhook_guides.php')
check('an anonymous visitor is bounced and sees nothing', s in (302, 303) and 'wg-hero' not in body, s)
sql("delete from user_settings where user_id=%s; delete from users where user_id=%s" % (tid, tid))

# ---------------------------------------------------------------- log + icons
if log_mark is not None:
    new = open(PHP_LOG, errors='replace').read()[log_mark:]
    bad = [l for l in new.splitlines() if re.search(r'(PHP )?(Warning|Notice|Deprecated|Fatal error|Parse error)', l)]
    check('no PHP warning, notice or deprecation in the server log during these requests', not bad, bad[:3])
else:
    print('SKIP  php log check (PHP_LOG not set)')
r = subprocess.run(['php', os.path.join(APP, 'tests/fa_icons.php')], capture_output=True, text=True, cwd=APP)
check('tests/fa_icons.php passes (every icon exists in Font Awesome 5.15 free)', r.returncode == 0, r.stdout[-300:])

failed = [r for r in results if not r[1]]
print('\n%d checks, %d passed, %d failed' % (len(results), len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
