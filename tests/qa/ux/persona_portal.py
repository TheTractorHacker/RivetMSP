"""Phase C - client contact persona on the portal (qa251@qa.example). No payments, nothing e-mailed."""
import json
from flow import *
def run():
    p, b, ctx, pg = start('portal', portal=True)
    out = []
    def task(name, fn):
        f = Flow(pg, 'client contact', name)
        pg.goto(BASE + '/client/index.php'); pg.wait_for_timeout(500)
        try: fn(f); res = f.done(f.outcome if f.outcome != 'not-run' else 'completed')
        except Exception as e:
            f.note('FAILED: ' + str(e).split('\n')[0][:140]); res = f.done('blocked'); shot(pg, 'portal_' + name[:20].replace(' ', '_'))
        out.append(res); print(name, '|', res['outcome'], res['clicks'], res['typed'], res['notes'][:4])
    def raise_ticket(f):
        f.click('a.btn:has-text("New Ticket")', 'New Ticket (home)')
        f.type('input[name=subject]', 'QA-UI- portal ticket', 'subject')
        f.note('attachment field present: ' + str(pg.locator('input[type=file]').count() > 0))
        fr = pg.frame_locator('iframe.tox-edit-area__iframe').first; fr.locator('body').click(); f.clicks += 1; fr.locator('body').type('QA-UI- portal details'); f.typed += 1
        f.click('button:has-text("Raise ticket")', 'Raise ticket'); pg.wait_for_timeout(1500)
        f.note('landed: ' + pg.url.replace(BASE, '') + ' ticket visible: ' + str('QA-UI- portal ticket' in pg.inner_text('body')))
    task('Submit a ticket (attachment?)', raise_ticket)
    def reply(f):
        f.click('a:has-text("Tickets") >> nth=0', 'Tickets'); f.click('a:has-text("QA-UI- portal ticket") >> nth=0', 'open ticket')
        f.note('reply box present: ' + str(pg.locator('iframe.tox-edit-area__iframe, textarea').count() > 0)); f.note('status shown: ' + pg.inner_text('.page-body')[:80].replace('\n',' | '))
        shot(pg, 'portal_ticket_detail')
    task('See ticket status and reply', reply)
    def kb(f):
        f.note('portal top nav: ' + pg.inner_text('.navbar').replace('\n', ' | ')[:120]); f.outcome = 'blocked-no-KB-in-portal'
    task('Find a knowledge-base article', kb)
    def profile(f):
        f.click('.navbar >> text=QA Contact', 'user menu'); f.note('menu: ' + ', '.join(pg.locator('.dropdown-menu.show a').all_inner_texts())[:80]); f.click('.dropdown-menu.show a:has-text("Account")', 'Profile')
        f.note('editable fields: ' + pg.evaluate("[...document.querySelectorAll('.page-body input:not([type=hidden]), .page-body select')].map(e=>e.name).join(',')"))
    task('Update my profile', profile)
    def finance(f):
        f.click('.navbar >> text=Finance', 'Finance menu'); f.note('finance items: ' + ', '.join(pg.locator('.dropdown-menu.show a').all_inner_texts())[:100]); f.click('.dropdown-menu.show a:has-text("Invoices")', 'Invoices')
        f.note('invoices page: ' + pg.inner_text('.page-body')[:120].replace('\n', ' | '))
    task('See my invoices', finance)
    def quotes(f):
        f.click('.navbar >> text=Finance', 'Finance menu'); f.click('.dropdown-menu.show a:has-text("Quotes")', 'Quotes'); f.note('quotes page: ' + pg.inner_text('.page-body')[:120].replace('\n', ' | '))
        f.note('no seeded quote for this client; approve/pay not exercised (would send mail / take payment)')
    task('Find a quote to approve', quotes)
    def assets(f):
        f.click('.navbar >> text=Technical', 'Technical menu'); f.note('technical items: ' + ', '.join(pg.locator('.dropdown-menu.show a').all_inner_texts())[:100])
    task('See my assets/documents', assets)
    json.dump(out, open(f"{OUT}/persona_portal.json", 'w'), indent=1); b.close(); p.stop()
run()
