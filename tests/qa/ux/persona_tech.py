"""Phase C - technician persona (priya.nair). Public+Email replies are NOT sent (would email); Public Note and Internal only."""
import json, sys
from flow import *

def run():
    p, b, ctx, pg = start('tech')
    out = []
    def task(name, fn):
        f = Flow(pg, 'technician', name)
        pg.goto(BASE + '/agent/dashboard.php'); pg.wait_for_timeout(500)
        try: fn(f); res = f.done(f.outcome if f.outcome != 'not-run' else 'completed')
        except Exception as e:
            f.note('FAILED: ' + str(e).split('\n')[0][:120]); res = f.done('blocked'); import traceback; print(traceback.format_exc()[-400:]); shot(pg, 'tech_' + name[:12].replace(' ', '_'))
        out.append(res); print(res['task'], res['outcome'], res['clicks'], res['notes'][:3])

    def t_triage(f):
        f.note('Landing page after login: ' + pg.url.replace(BASE, ''))
        f.nav('Service Desk', 'Tickets')
        f.click("text=Unassigned >> nth=0", 'Unassigned tile'); f.note('after tile click url=' + pg.url.replace(BASE, ''))
        rows = pg.locator('table tbody tr').count(); f.note(f'rows shown={rows}')
        f.click("text=Overdue >> nth=0", 'Overdue tile'); f.note('after overdue url=' + pg.url.replace(BASE, ''))
    task('Triage queue: find unassigned and overdue', t_triage)

    def t_note(f):
        pg.goto(BASE + '/agent/tickets.php'); pg.wait_for_timeout(800)
        f.clicks = 0; f.steps = []
        f.nav('Service Desk', 'Tickets')
        f.click(pg.locator("table tbody tr a[href*='ticket.php?ticket_id=']:visible").nth(1), 'open first ticket (subject link)')
        pg.wait_for_selector('iframe.tox-edit-area__iframe', timeout=8000)
        f.click("input[name=public_reply_type] >> nth=0", 'Internal tab (default)') if False else None
        fr = pg.frame_locator('iframe.tox-edit-area__iframe').first
        fr.locator('body').click(); f.clicks += 1
        fr.locator('body').type('QA-UI- internal note from technician flow'); f.typed += 1
        f.click("button[name=add_ticket_reply]", 'Submit note')
        pg.wait_for_timeout(1500)
        f.note('note visible after submit: ' + str('QA-UI- internal note' in pg.inner_text('body')))
    task('Open ticket and add internal note', t_note)

    def t_status_assign(f):
        f.nav('Service Desk', 'Tickets'); f.click(pg.locator("table tbody tr a[href*='ticket.php?ticket_id=']:visible").nth(1), 'open a ticket (subject link)'); pg.wait_for_selector('.tkt-pill-badge', timeout=8000)
        f.click("text=Open >> nth=0", 'status pill') if False else None
        f.note('status pill -> dropdown')
        pill = pg.locator('.tkt-pill-badge').first
        f.click(pill, 'status pill')
        opts = pg.locator('.dropdown-menu.show a, .dropdown-menu.show button').all_inner_texts()
        f.note('status options: ' + ', '.join(o.strip() for o in opts)[:100])
        f.click(".dropdown-menu.show >> text=On Hold", 'choose On Hold'); pg.wait_for_timeout(900)
        sel = pg.locator('#quickAssignSelect')
        f.click(sel, 'assign select')
        f.note('assign select options: ' + str(pg.evaluate("[...document.querySelectorAll('#quickAssignSelect option')].map(o=>o.text).join(',')")))
    task('Change status and assign a technician', t_status_assign)

    def t_time(f):
        pg.goto(BASE + '/agent/tickets.php'); pg.wait_for_timeout(600)
        f.click(pg.locator("table tbody tr a[href*='ticket.php?ticket_id=']:visible").nth(1), 'open ticket')
        pg.wait_for_selector('input[name=minutes]', timeout=6000)
        f.type('input[name=minutes]', '15', 'minutes')
        fr = pg.frame_locator('iframe.tox-edit-area__iframe').first
        fr.locator('body').click(); f.clicks += 1; fr.locator('body').type('QA-UI- time logged 15m'); f.typed += 1
        f.click("button[name=add_ticket_reply]", 'Submit with time')
        pg.wait_for_timeout(1200)
        f.note('time visible: ' + str('15m' in pg.inner_text('body')))
        f.note('Time can only be logged inside a reply/note; separate Time Entry panel is a timer.')
    task('Log 15 minutes of time on a ticket', t_time)

    def t_priority(f):
        f.nav('Service Desk', 'Tickets')
        f.click("a.nav-link:has-text('Assigned to me')", 'My tickets view')
        f.click("button.tvf-toggle:has-text('Priority'):visible", 'Priority filter')
        f.note('options: ' + ', '.join(pg.locator('.dropdown-menu.show label, .dropdown-menu.show .form-check-label').all_inner_texts())[:100])
        f.click(".dropdown-menu.show >> text=High", 'High')
        pg.wait_for_timeout(1200); f.note('url=' + pg.url.replace(BASE, ''))
    task('Filter my tickets by priority', t_priority)

    def t_asset(f):
        pg.goto(BASE + '/agent/tickets.php'); pg.wait_for_timeout(600)
        f.type('input[placeholder*="Search everywhere"]', 'BBC-DES', 'global search'); pg.wait_for_timeout(1500)
        f.note('suggestions: ' + pg.locator('.app-header-search-result, .app-header-search-results a').all_inner_texts().__str__()[:150])
        f.click(".app-header-search-results a >> nth=0", 'first result')
        f.note('landed: ' + pg.url.replace(BASE, ''))
        f.note('asset page shows history/tickets: ' + str('History' in pg.inner_text('body') or 'Tickets' in pg.inner_text('body')))
    task('Find an asset and see its history', t_asset)

    def t_contact(f):
        f.type('input[placeholder*="Search everywhere"]', 'Morgan', 'global search'); pg.wait_for_timeout(1500)
        f.click(".app-header-search-results a >> nth=0", 'first result'); f.note('landed: ' + pg.url.replace(BASE, ''))
        f.note('tel/mailto links present: ' + str(pg.locator('a[href^="tel:"], a[href^="mailto:"]').count()))
    task('Find a contact and reach their phone/email', t_contact)

    def t_kb(f):
        f.note('KB reachable from sidebar for technician: ' + str(pg.locator('.navbar-vertical a[href*=kb]').count() > 0))
        pg.goto(BASE + '/agent/dashboard.php')
        f.note('Sidebar has no Knowledge Base entry; Docs points to external docs.itflow.org')
        f.outcome = 'blocked-no-nav-path'
    task('Look up a KB answer and attach it to a reply', t_kb)

    def t_create(f):
        pg.goto(BASE + '/agent/tickets.php'); pg.wait_for_timeout(600); f.clicks = 0; f.steps = []
        f.click("text=New Ticket", 'New Ticket')
        pg.wait_for_selector('.modal.show', timeout=6000); f.note('modal title: ' + pg.locator('.modal.show .modal-title').first.inner_text()[:40])
        f.note('required marks: ' + str(pg.locator('.modal.show .text-danger, .modal.show [required]').count()))
        shot(pg, 'tech_new_ticket_modal')
        f.type('.modal.show input[name=subject]', 'QA-UI- ticket for a user', 'subject')
        pg.evaluate("""()=>{for(const n of ['client','contact']){const s=document.querySelector('.modal.show select[name='+n+'], .modal.show select[name='+n+'_id]'); if(s&&s.tomselect){const o=[...s.options].filter(o=>o.value);if(o[0]) s.tomselect.setValue(o[0].value)}}}"""); f.clicks += 2
        f.note('client+contact picked via 2 dropdown interactions (counted)')
        f.click(".modal.show button[type=submit], .modal.show button[name=add_ticket]", 'Create')
        pg.wait_for_timeout(1500); f.note('after create url=' + pg.url.replace(BASE, ''))
    task('Create a ticket on behalf of a user', t_create)

    def t_bulk(f):
        pg.goto(BASE + '/agent/tickets.php'); pg.wait_for_timeout(800); f.clicks = 0; f.steps = []
        for i in range(3): f.click(f"input.bulk-select >> nth={i}", f'select row {i+1}')
        f.note('bulk toolbar buttons: ' + ', '.join(pg.locator('.bulk-actions button, #bulkActions button, [data-bulk-action], .bulk-bar button').all_inner_texts())[:150])
        shot(pg, 'tech_bulk')
    task('Handle three similar tickets at once (bulk)', t_bulk)

    json.dump(out, open(f"{OUT}/persona_tech.json", 'w'), indent=1)
    b.close(); p.stop()
run()
