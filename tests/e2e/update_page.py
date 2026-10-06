"""
End-to-end check of the Administration > Update page "Checks" panel: it must DIAGNOSE (git, .git writable by the web user, update
source configured, vendor/composer clean, database vs code version) instead of failing silently, and Update App must stop with a plain
explanation when the server cannot update itself. Normal state, dirty vendor/composer, read-only .git, missing remote, hand-edited file,
database newer/older than the code, not a git checkout.

Needs a THROWAWAY copy of the app WITHOUT a .git folder (never a real site): installed with scripts/setup_cli.php against a scratch
database, $config_https_only = FALSE, served with `php -S 127.0.0.1:<port> -t <app dir>`. The script makes a git repository inside that
copy with a local bare repository as the "fork" remote (a file path in a temp folder): nothing ever contacts GitHub or any network.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/update_page.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, os, subprocess, shutil, tempfile, http.cookiejar, urllib.request, urllib.parse, urllib.error, stat
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5].rstrip('/')
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
if os.path.exists(APP + '/.git'):
    sys.exit('refusing to run: %s already has a .git (use a scratch copy without one)' % APP)
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {'Referer': BASE + referer} if referer else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        resp = opener.open(urllib.request.Request(BASE + path, data=body, headers=headers), timeout=90); return resp.status, resp.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')
def sql(q):
    return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'csrf_token=([A-Za-z0-9%_-]+)', html); return urllib.parse.unquote(m.group(1)) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
WORK = tempfile.mkdtemp(prefix='updpage_')
def git(*a, cwd=APP): return subprocess.run(['git'] + list(a), cwd=cwd, capture_output=True, text=True)
def chmod_tree(path, add=0, remove=0):
    for root, dirs, files in os.walk(path):
        for n in dirs + files:
            p = os.path.join(root, n)
            if os.path.islink(p): continue
            os.chmod(p, (os.stat(p).st_mode | add) & ~remove)
    os.chmod(path, (os.stat(path).st_mode | add) & ~remove)
def checks_of(html):
    return {m.group(1): m.group(2) for m in re.finditer(r'data-check="([a-z]+)" data-status="([a-z]+)"', html)}
def update_page():
    s, html = req('/admin/update.php'); return html
def pull_update():
    t = csrf(update_page())
    req('/admin/post.php?update&csrf_token=' + urllib.parse.quote(t), referer='/admin/update.php')
    return update_page()   # flash shows on the next page
ORIG_DBV = None
try:
    # ---- a repository inside the scratch copy, with a local bare "fork" remote
    git('init', '-q', '-b', 'master'); git('config', 'user.email', 't@t.test'); git('config', 'user.name', 't')
    git('add', 'vendor/composer', 'includes/database_version.php', 'admin/update.php', 'admin/database_updates.php')
    assert git('commit', '-q', '-m', 'scratch').returncode == 0
    git('init', '-q', '--bare', WORK + '/remote.git')
    git('remote', 'add', 'fork', WORK + '/remote.git'); git('push', '-q', 'fork', 'master'); git('fetch', '-q', 'fork')
    s, html = req('/login.php'); d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
    if csrf(html): d['csrf_token'] = csrf(html)
    check('sign in', req('/login.php', d)[0] in (200, 302, 303))
    ORIG_DBV = sql('select config_current_database_version from settings')

    # ---- normal state
    page = update_page(); c = checks_of(page)
    check('the Checks panel is on the page', 'id="update-checks"' in page and len(c) >= 8, c)
    check('normal state: no problem and no warning (badge "All good")', 'All good' in page and not [k for k, v in c.items() if v in ('fail', 'warn')], c)
    check('normal state: git, repo, .git writable, app writable, remote, fetched, composer, db version all OK',
          all(c.get(k) == 'ok' for k in ['git', 'repo', 'gitwrite', 'treewrite', 'remote', 'fetched', 'composer', 'dirty', 'dbversion']), c)
    check('normal state: no "Could not find execute git fetch" warning (the fetch worked)', "Could not find execute" not in page)
    check('the remote address is shown and nothing contacted it', WORK + '/remote.git' in page and 'not contacted' in page)

    # ---- dirty vendor/composer (Composer rewrote it)
    with open(APP + '/vendor/composer/installed.json', 'a') as f: f.write('\n')
    page = update_page(); c = checks_of(page)
    check('a dirty vendor/composer is reported as a warning with the plain-English fix', c.get('composer') == 'warn' and 'checkout -- vendor/composer' in page and 'puts them back automatically' in page, c)
    page = pull_update()
    check('Update App resets the generated files and succeeds', 'Update successful' in page, re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', page))[:0])
    check('...and vendor/composer is clean afterwards', checks_of(page).get('composer') == 'ok' and git('status', '--porcelain', '--', 'vendor/composer').stdout.strip() == '')

    # ---- hand-edited file
    with open(APP + '/includes/database_version.php', 'a') as f: f.write('// hand edit\n')
    page = update_page(); c = checks_of(page)
    check('a hand-edited tracked file is a warning that names FORCE Update', c.get('dirty') == 'warn' and 'FORCE Update App' in page, c)
    git('checkout', '--', 'includes/database_version.php')

    # ---- read-only .git
    head_before = git('rev-parse', 'HEAD').stdout.strip()
    chmod_tree(APP + '/.git', remove=stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH)
    page = update_page(); c = checks_of(page)
    check('a read-only .git is a red problem naming what cannot be written', c.get('gitwrite') == 'fail' and 'cannot write to' in page and '1 problem' in page, c)
    check('...with the shared-group fix (chgrp/chmod/core.sharedRepository)', 'core.sharedRepository group' in page and 'chmod -R g+rwX' in page)
    check('...and the failed fetch is explained with its real error, pointing at the Checks', "Could not find execute" in page and 'Error details' in page and '#update-checks' in page)
    page = pull_update()
    check('Update App refuses to start, with the reason, instead of failing half way', 'The update did not run' in page and 'cannot write to' in page, '')
    check('...and the checkout was not touched', git('rev-parse', 'HEAD').stdout.strip() == head_before)
    chmod_tree(APP + '/.git', add=stat.S_IWUSR)
    check('after making .git writable again the problem is gone', checks_of(update_page()).get('gitwrite') == 'ok')

    # ---- read-only app files
    os.chmod(APP + '/includes/database_version.php', 0o444)
    check('a read-only database-version file is a red problem for the app files', checks_of(update_page()).get('treewrite') == 'fail')
    os.chmod(APP + '/includes/database_version.php', 0o644)

    # ---- missing update source
    git('remote', 'remove', 'fork')
    page = update_page(); c = checks_of(page)
    check('no "fork" remote is a red problem with the git remote add fix', c.get('remote') == 'fail' and 'remote add fork' in page, c)
    git('remote', 'add', 'fork', WORK + '/remote.git')

    # ---- ssh remote without a key for the web user
    git('remote', 'set-url', 'fork', 'git@github.invalid:x/y.git')
    page = update_page(); c = checks_of(page)
    check('an SSH remote is checked for a readable key (config only, no network)', c.get('remote') in ('ok', 'warn') and 'github.invalid' in page)
    git('remote', 'set-url', 'fork', WORK + '/remote.git')

    # ---- database vs code
    sql("update settings set config_current_database_version='2.6.1'")
    page = update_page(); c = checks_of(page)
    check('a database older than the code is a warning that says to press Update Database', c.get('dbversion') == 'warn' and 'Update Database' in page, c)
    sql("update settings set config_current_database_version='9.9.9'")
    page = update_page(); c = checks_of(page)
    check('a database NEWER than the code is a red problem (never run older code)', c.get('dbversion') == 'fail' and 'newer than this code' in page, c)
    sql("update settings set config_current_database_version='%s'" % ORIG_DBV)

    # ---- not a git checkout
    os.rename(APP + '/.git', APP + '/.git_off')
    page = update_page(); c = checks_of(page)
    check('no .git folder is a red problem that explains it', c.get('repo') == 'fail' and 'nothing to update from' in page, c)
    os.rename(APP + '/.git_off', APP + '/.git')
    check('everything is back to normal at the end', not [k for k, v in checks_of(update_page()).items() if v == 'fail'])
finally:
    if ORIG_DBV: sql("update settings set config_current_database_version='%s'" % ORIG_DBV)
    if os.path.isdir(APP + '/.git_off'): os.rename(APP + '/.git_off', APP + '/.git')
    if os.path.isdir(APP + '/.git'):
        chmod_tree(APP + '/.git', add=stat.S_IWUSR); shutil.rmtree(APP + '/.git', ignore_errors=True)
    shutil.rmtree(WORK, ignore_errors=True)
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
