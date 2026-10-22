"""Phase B: create a QA-UI- record through each module's New modal and check it persists after reload.
Generic filler: required text -> QA-UI- name, selects -> first real option, numbers -> 10, dates -> today."""
import json, sys, time
from ux_common import *
TS = str(int(time.time()))[-5:]
MODS = [  # (label, page, new-button text, search text to find after reload)
 ('contact', '/agent/contacts.php?client_id=1', 'New Contact'), ('location', '/agent/locations.php?client_id=1', 'New Location'),
 ('asset', '/agent/assets.php?client_id=1', 'New Asset'), ('vendor', '/agent/vendors.php', 'New Vendor'),
 ('product', '/agent/products.php', 'New Service'), ('expense', '/agent/expenses.php', 'New Expense'),
 ('revenue', '/agent/revenues.php', 'New Revenue'), ('account', '/agent/accounts.php', 'New Account'),
 ('project', '/agent/projects.php', 'New Project'), ('domain', '/agent/domains.php?client_id=1', 'New Domain'),
 ('certificate', '/agent/certificates.php?client_id=1', 'New Certificate'), ('credential', '/agent/credentials.php?client_id=1', 'New Credential'),
 ('network', '/agent/networks.php?client_id=1', 'New Network'), ('trip', '/agent/trips.php', 'New Trip'),
 ('quote', '/agent/quotes.php', 'New Quote'), ('invoice', '/agent/invoices.php', 'New Invoice'),
 ('recurring invoice', '/agent/recurring_invoices.php', 'New Recurring'), ('recurring ticket', '/agent/recurring_tickets.php', 'New Recurring'),
 ('recurring expense', '/agent/recurring_expenses.php', 'New Recurring'), ('contract', '/agent/contracts.php?client_id=1', 'New Contract'),
 ('software', '/agent/software.php', 'New'), ('campaign', '/agent/campaigns.php', 'New'), ('segment', '/agent/segments.php', 'New'),
 ('problem', '/agent/problems.php', 'New Problem'), ('change', '/agent/changes.php', 'New Change'),
]
FILL = """(tag) => {
  const m = document.querySelector('.modal.show'); const log = [];
  m.querySelectorAll('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=submit]), textarea, select').forEach(e => {
    if (e.disabled || e.readOnly) return;
    const req = e.required; const t = e.type;
    if (e.tagName === 'SELECT') { const o = [...e.options].filter(o => o.value && !o.disabled); if (req && o[0] && !e.value) { if (e.tomselect) e.tomselect.setValue(o[0].value); else e.value = o[0].value; e.dispatchEvent(new Event('change', {bubbles:true})); } return; }
    if (!req || e.value) return;
    if (t === 'number' || /amount|price|balance|miles|^day$|rate/i.test(e.name)) e.value = '10'; else if (t === 'date') e.value = new Date().toISOString().slice(0,10); else if (t === 'email') e.value = 'qa-ui-' + tag + '@qa.example';
    else if (t === 'url') e.value = 'https://qa-ui-' + tag + '.example'; else if (t === 'time') e.value = '09:00';
    else e.value = 'QA-UI- ' + tag + ' ' + (e.name || 'x');
    e.dispatchEvent(new Event('input', {bubbles:true})); e.dispatchEvent(new Event('change', {bubbles:true}));
  });
}"""
def run():
    p, b, ctx, pg = start('admin')
    errs = []; pg.on('console', lambda m: errs.append(m.text[:100]) if m.type == 'error' else None)
    res = []
    import os
    only = os.environ.get('QA_ONLY')
    for label, page, btn in MODS:
        if only and label not in only.split(','): continue
        r = {'module': label, 'page': page}; errs.clear()
        try:
            pg.goto(BASE + page, wait_until='domcontentloaded'); pg.wait_for_timeout(900)
            nb = pg.locator(f'.page-body a:has-text("{btn}"):visible, .page-body button:has-text("{btn}"):visible').first
            if not nb.count(): r['result'] = 'NO-NEW-BUTTON'; res.append(r); print(r); continue
            nb.click(timeout=3000)
            pg.wait_for_selector('.modal.show', timeout=6000); pg.wait_for_timeout(900)
            txt = pg.inner_text('.modal.show')
            if any(x in txt for x in ('Fatal error', 'Warning:', 'Uncaught')): r['rawerr'] = True
            tag = f'{label.replace(" ","")}{TS}'
            pg.evaluate(FILL, tag)
            before = pg.locator('.modal.show :invalid').count()
            r['invalid_after_fill'] = pg.evaluate("[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name+(e.closest('.tab-pane')?'@'+e.closest('.tab-pane').id:'')).join(',')")
            sub = pg.locator('.modal.show button[type=submit]:visible').first
            sub.click(timeout=3000); pg.wait_for_timeout(1800)
            still = pg.locator('.modal.show').count() > 0
            r['modal_still_open'] = still
            if still: r['validation'] = pg.evaluate("[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name+':'+e.validationMessage).join(' | ')"); r['result'] = 'NOT-SAVED' if r['validation'] else 'NOT-SAVED(no message)'
            else:
                pg.goto(BASE + page, wait_until='domcontentloaded'); pg.wait_for_timeout(900)
                body = pg.inner_text('body')
                r['result'] = 'saved' if f'QA-UI- {tag}' in body or f'qa-ui-{tag}' in body else 'saved?(name not found in list)'
            if 'Fatal' in pg.inner_text('body')[:2000]: r['rawerr'] = True
            if errs: r['console'] = errs[:2]
        except Exception as e:
            r['result'] = 'ERROR ' + str(e).split('\n')[0][:100]
            try: pg.screenshot(path=f"{OUT}/crud_{label.replace(' ','_')}.png")
            except Exception: pass
        res.append(r); print(r)
    json.dump(res, open(f"{OUT}/crud.json", 'w'), indent=1); b.close(); p.stop()
run()
