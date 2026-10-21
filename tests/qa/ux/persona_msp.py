"""Phase C - admin, billing, account manager, sales personas (alex.morgan as admin). Nothing is sent: no invoices, quotes or e-mail."""
import json, sys
from flow import *

def run():
    p, b, ctx, pg = start('admin')
    out = []
    def task(persona, name, fn):
        f = Flow(pg, persona, name)
        pg.goto(BASE + '/agent/dashboard.php'); pg.wait_for_timeout(500)
        try: fn(f); res = f.done(f.outcome if f.outcome != 'not-run' else 'completed')
        except Exception as e:
            f.note('FAILED: ' + str(e).split('\n')[0][:140]); res = f.done('blocked'); shot(pg, (persona + '_' + name)[:30].replace(' ', '_').replace(':', ''))
        out.append(res); print(persona, '|', name, '|', res['outcome'], res['clicks'], res['typed'], res['notes'][:4])
    def to_admin(f, *labels):
        f.click(pg.get_by_text('Alex Morgan').first, 'user menu'); f.click('.dropdown-menu.show >> text=Administration', 'Administration')
        pg.wait_for_timeout(600)
        for l in labels: f.nav(l)

    # ---- Admin
    def a_user(f):
        to_admin(f, 'Users')
        f.click('text=New User', 'New User'); pg.wait_for_selector('.modal.show', timeout=5000)
        f.type('.modal.show input[name=name]', 'QA-UI- Test User', 'name'); f.type('.modal.show input[name=email]', 'qa-ui-user@qa.example', 'email')
        f.type('.modal.show input[name=password]', 'QaUi#Passw0rd-2026x', 'password')
        f.note('role picker: ' + str(pg.evaluate("[...document.querySelectorAll('.modal.show select[name=role] option')].map(o=>o.text).join(',')")))
        shot(pg, 'admin_new_user_modal')
        f.click('.modal.show button[type=submit]', 'Create'); pg.wait_for_timeout(1200)
        f.note('user listed: ' + str('QA-UI- Test User' in pg.inner_text('body')))
    task('admin', 'Create a user with a role', a_user)

    def a_disable(f):
        to_admin(f, 'Users')
        row = pg.locator('tr', has_text='QA-UI- Test User').first
        f.click(row.locator('.dropdown-toggle, button:has-text("...")').last if row.locator('.dropdown-toggle').count() else row.locator('button').last, 'row menu')
        f.note('menu: ' + ', '.join(pg.locator('.dropdown-menu.show a').all_inner_texts())[:100])
        f.click('.dropdown-menu.show >> text=Disable', 'Disable') if pg.locator('.dropdown-menu.show >> text=Disable').count() else f.note('no Disable item')
        pg.wait_for_timeout(800)
        f.note('confirm dialog shown: ' + str(pg.locator('.modal.show').count() > 0))
    task('admin', 'Disable a user', a_disable)

    def a_audit(f):
        to_admin(f, 'Settings')
        f.note('Settings page links: ' + ', '.join(pg.locator('.page-body a').all_inner_texts()).replace('\n', ' ')[:200])
        pg.goto(BASE + '/admin/settings.php')
        al = pg.locator('a:has-text("Audit")').first
        if al.count(): f.click(al, 'Audit log')
        else: f.note('no Audit log link on Settings page'); f.outcome = 'partial'
    task('admin', 'Find who changed something (audit log)', a_audit)

    def a_report_csv(f):
        f.nav('Reports'); f.note('after Reports nav: ' + pg.url.replace(BASE, ''))
        f.click('.navbar-vertical >> text=Clients with a Balance', 'Clients with a Balance')
        with pg.expect_download(timeout=8000) as d: f.click('text=Export CSV', 'Export CSV')
        f.note('csv filename: ' + d.value.suggested_filename)
    task('admin', 'Run and export a report', a_report_csv)

    def a_health(f):
        to_admin(f, 'Maintenance')
        f.note('maintenance text: ' + pg.inner_text('.page-body')[:200].replace('\n', ' | '))
    task('admin', 'Review system health (view only)', a_health)

    def a_config(f):
        to_admin(f, 'Ticketing')
        f.note('ticketing setup text: ' + pg.inner_text('.page-body')[:260].replace('\n', ' | '))
    task('admin', 'Manage ticket statuses, categories, templates, SLAs', a_config)

    # ---- Billing
    def b_invoice_from_time(f):
        f.nav('Reports'); f.click('.navbar-vertical >> text=Unbilled Tickets', 'Unbilled Tickets report'); 
        f.note('report is per-client counts, no drill-down links: ' + str(pg.locator('.page-body table a').count()) + ' links in table')
        pg.goto(BASE + '/agent/dashboard.php'); f.nav('Service Desk', 'Tickets')
        f.click(pg.locator("table tbody tr a[href*='ticket.php?ticket_id=']:visible").nth(1), 'open ticket')
        f.click('a:has-text("Invoice")', 'Invoice button'); pg.wait_for_selector('.modal.show', timeout=5000)
        f.note('invoice modal: ' + pg.inner_text('.modal.show')[:200].replace('\n', ' | '))
        shot(pg, 'billing_ticket_invoice_modal')
    task('billing user', 'Create an invoice from a ticket with tracked time', b_invoice_from_time)

    def b_payment(f):
        f.nav('Billing', 'Invoices'); f.click(pg.locator('table tbody tr a[href*="invoice.php"]').first, 'open invoice')
        f.click('text=Add Payment', 'Add Payment'); pg.wait_for_selector('.modal.show', timeout=5000)
        f.note('payment modal fields: ' + ', '.join(pg.evaluate("[...document.querySelectorAll('.modal.show input:not([type=hidden]), .modal.show select')].map(e=>e.name).join(',')").split(',')))
        shot(pg, 'billing_payment_modal')
    task('billing user', 'Record a payment (modal opened, not submitted on seeded invoice)', b_payment)

    def b_overdue(f):
        f.nav('Billing', 'Invoices')
        f.note('status filter options: ' + str(pg.evaluate("[...document.querySelectorAll('select[name=status] option, #statusFilter option, .tvf-toggle')].map(o=>(o.text||o.textContent).trim()).join(',')")[:200]))
        f.note('invoice list has overdue/aging filter: ' + str('Overdue' in pg.inner_text('body')))
        f.note('invoice list has per-row Send reminder: ' + str(pg.locator('text=Remind').count() > 0))
    task('billing user', 'Chase an overdue invoice', b_overdue)

    def b_aged(f):
        f.nav('Reports'); f.click('.navbar-vertical >> text=Clients with a Balance', 'Clients with a Balance')
        f.note('AR aging buckets visible: ' + str('31-60' in pg.inner_text('body')))
    task('billing user', 'Produce an aged-debt report', b_aged)

    # ---- Account manager
    def am_health(f):
        f.nav('Organization', 'Clients'); f.click(pg.locator('table tbody tr a[href*="client_overview.php"]').first, 'open client')
        t = pg.inner_text('.page-body')
        f.note('overview shows: open tickets=%s assets=%s contracts=%s expiring=%s balance=%s spend/revenue=%s' % ('Open Tickets' in t, 'Assets' in t, 'Contracts' in t or 'Contract' in t, 'Expiring' in t, 'Balance' in t, 'Spend' in t or 'Revenue' in t or 'Income' in t))
    task('account manager', "See one client's health", am_health)

    def am_report(f):
        f.nav('Reports'); f.note('reports menu entries: ' + ', '.join(pg.locator('.navbar-vertical a').all_inner_texts()).replace('\n', ' ')[:420])
        f.note('no per-client monthly report/QBR; closest: Income by Client, Tickets by Client, Client Time Detail Audit')
        f.outcome = 'partial'
    task('account manager', 'Prepare a monthly client report', am_report)

    # ---- Sales
    def s_lead(f):
        f.nav('Organization', 'Clients'); f.click('a:has-text("Leads")', 'Leads tab'); f.click('text=New Lead', 'New Lead'); pg.wait_for_selector('.modal.show', timeout=5000)
        f.type('.modal.show input[name=name]', 'QA-UI- Lead Co', 'lead name')
        shot(pg, 'sales_new_lead_modal'); f.note('lead modal fields: ' + pg.evaluate("[...document.querySelectorAll('.modal.show input:not([type=hidden]), .modal.show select, .modal.show textarea')].map(e=>e.name).join(',')"))
        f.click('.modal.show button[type=submit]', 'Save lead'); pg.wait_for_timeout(1200); f.note('lead listed: ' + str('QA-UI- Lead Co' in pg.inner_text('body')))
    task('sales/lead owner', 'Capture a lead', s_lead)

    def s_pipeline(f):
        f.nav('CRM', 'Pipeline'); f.click('text=New Opportunity', 'New Opportunity'); pg.wait_for_selector('.modal.show', timeout=5000)
        f.type('.modal.show input[name=name]', 'QA-UI- Deal', 'name'); shot(pg, 'sales_new_opportunity_modal')
        f.note('opp fields: ' + pg.evaluate("[...document.querySelectorAll('.modal.show input:not([type=hidden]), .modal.show select, .modal.show textarea')].map(e=>e.name).join(',')"))
    task('sales/lead owner', 'Create an opportunity and see the pipeline', s_pipeline)

    def s_convert(f):
        f.nav('Organization', 'Clients'); f.click('a:has-text("Leads")', 'Leads tab')
        row = pg.locator('tr', has_text='QA-UI- Lead Co').first
        f.click(row.locator('.dropdown-toggle, button').last, 'row menu'); f.note('lead row menu: ' + ', '.join(pg.locator('.dropdown-menu.show a').all_inner_texts()).replace('\n', ' ')[:160])
        f.note('Lead statuses (New/Contacted/Qualified...) - is there a lead status field? ' + str('Status' in pg.inner_text('.page-body')))
    task('sales/lead owner', 'Move a lead through statuses and convert it', s_convert)

    json.dump(out, open(f"{OUT}/persona_msp.json", 'w'), indent=1)
    b.close(); p.stop()
run()
