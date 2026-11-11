# UI sweep report: billing area (partial)

Honest status: the run covered a small part of the assigned scope. The environment note: the config copied from qa-scan-rivetmsp points at DB `rivetmsp_scan`, which does not exist; I used the demo DB `rivetmsp_demo` credentials (from demos/rivetmsp/config.php) with base URL localhost:8213.

## Coverage (executed and, where marked, screenshot viewed)
| Item | Result |
|---|---|
| 32 list pages (invoices, quotes, payments, revenues, expenses, recurring_*, products, services, service_catalog, contracts, accounts, transfers, trips, client_autopay, projects, vendors, kb_articles, files, credentials, domains, certificates, software, assets, networks, network_drives, printers, racks, firewalls, structured_docs, backups, reports index) at 1920 light + dark | loaded, automated audit (h-scroll, tiny text, unnamed buttons, raw PHP errors, console/4xx): clean except projects progress bar text 10.5px; screenshots VIEWED only for invoices, quotes, payments, revenues, expenses, recurring_expenses |
| Modals opened (light+dark shots, automated audit) on products, revenues, expenses, recurring_expenses, recurring_invoices, accounts, transfers, trips, vendors, payments, quotes, invoices | all open without errors/overflow; screenshots VIEWED only for client_add, invoice_add, recurring_expense_add, trip_add |
| invoice.php (draft, empty) | viewed light |
| Create client / invoice via UI | works |

## Defects
| # | Severity | Defect | Status |
|---|---|---|---|
| 1 | Medium | Any form value longer than its DB column (e.g. client Shortened Name, which has no maxlength) ended in a blank HTTP 500 | Fixed: agent/post.php now catches mysqli data errors, flashes a message, redirects back. Root cause (missing maxlength on client_add abbreviation) is in the clients area, not fixed |
| 2 | Low | quotes.php: Date and Expire headers showed the sort arrow permanently (copy-paste condition on quote_number) | Fixed |
| 3 | Low | invoice.php / recurring_invoice.php add-item submit button had no accessible name (recurring also used a different button style) | Fixed |
| 4 | Low | trip add/edit/copy: destination field had no label | Fixed |
| 5 | Low | projects.php progress bar text 10.5px | Unfixed |
| 6 | Info | Invoice with only a discount shows negative total/balance (-$10.00) | Unfixed, see proposals |
| 7 | Info | topnav New Ticket modal (modals/ticket/ticket_add_v2.php) throws JS TypeError (reading 'assets' / 'vendors' of null) when opened from my pages | Unfixed, tickets area |

Files changed: agent/post.php, agent/quotes.php, agent/invoice.php, agent/recurring_invoice.php, agent/modals/trip/trip_add.php, trip_edit.php, trip_copy.php. Scripts in tests/qa/ui-sweep/.

## Proposals
- Block or warn when discount exceeds invoice subtotal.
- Add maxlength attributes to all text inputs matching column sizes (systemic).

## Not tested (and why)
Most of the scope: submit/validation/edit/delete flows on all forms, invoice/quote totals math with items/taxes/discounts (no taxes/products exist in demo DB; seeding not completed), PDFs/print views, all detail pages (project_*, vendor_details, kb_article*, document_details, credential_reveal, asset_details, network, structured_doc, racks, firewalls), all agent/reports/*, 1366 and 390 widths, axe contrast runs, sort/filter/pagination clicks, files/KB uploads. Run ran out of working budget; these remain open.
