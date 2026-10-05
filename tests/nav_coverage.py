#!/usr/bin/env python3
"""Navigation coverage check: every page the OLD RivetMSP side navs linked to must still be
linked from the NEW agent side nav, admin side nav, or an admin directory (settings.php,
maintenance.php, catalog_setup.php, ticketing_setup.php, template_library.php).

The old hrefs are frozen in tests/fixtures/old_nav_hrefs.json (taken with
`git show fdc34d23d:agent/includes/side_nav.php` / `...admin/includes/side_nav.php`), so the check keeps working
after the unification is committed. Pass --from-git <rev> to rebuild that list from another revision.

Exit status 1 and a loud ORPHAN list on any miss. Run from the repo root: python3 tests/nav_coverage.py
"""
import json, os, re, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Deliberately not linked: admin/modules.php sat inside an HTML comment in the old admin nav
# ("2025-12-05 JQ - Hide Permission Modules"), and /agent/ is the brand link to the start page.
INTENTIONALLY_UNLINKED = {'/admin/modules.php', '/agent/'}

def read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8') as fh:
        return fh.read()

def old_hrefs():
    if '--from-git' in sys.argv:
        rev = sys.argv[sys.argv.index('--from-git') + 1]
        hrefs = set()
        for f in ('agent/includes/side_nav.php', 'admin/includes/side_nav.php'):
            s = subprocess.check_output(['git', '-C', ROOT, 'show', f'{rev}:{f}'], text=True)
            hrefs |= set(re.findall(r'href="(/(?:agent|admin)/[^"?#<]*)', s))
        return hrefs
    data = json.load(open(os.path.join(ROOT, 'tests/fixtures/old_nav_hrefs.json')))
    return {h for v in data['hrefs'].values() for h in v}

def new_links():
    links = set()
    for f in ('agent/includes/side_nav.php', 'admin/includes/side_nav.php'):
        links |= set(re.findall(r'''href=["'](/(?:agent|admin)/[^"'?#<]*)''', read(f)))
    for f in ('settings', 'maintenance', 'catalog_setup', 'ticketing_setup', 'template_library'):
        s = read(f'admin/{f}.php')
        # directory items: ['Label', 'Description', 'page.php', 'fa-icon', ...]; the page may carry ?query/#anchor
        for m in re.finditer(r"\[\s*'[^']*',\s*'[^']*',\s*'([a-z0-9_]+\.php)(?:[?#][^']*)?'\s*,\s*'fa-", s):
            links.add('/admin/' + m.group(1))
    return links

def main():
    old = old_hrefs() - INTENTIONALLY_UNLINKED
    new = new_links()
    orphans = sorted(old - new)
    missing_files = sorted(h for h in new if h.endswith('.php') and not os.path.isfile(os.path.join(ROOT, h.lstrip('/'))))
    print(f'old nav pages: {len(old)}  new nav+directory links: {len(new)}')
    bad = False
    if orphans:
        bad = True
        print('\n' + '!' * 70 + '\nORPHANED PAGES (linked by the old nav, not by the new nav/directories):')
        for o in orphans:
            print('  ORPHAN', o)
        print('!' * 70)
    if missing_files:
        bad = True
        print('\nLINKS TO FILES THAT DO NOT EXIST:')
        for m in missing_files:
            print('  MISSING', m)
    if bad:
        sys.exit(1)
    print('OK: every old nav page is still reachable; no dead links.')

if __name__ == '__main__':
    main()
