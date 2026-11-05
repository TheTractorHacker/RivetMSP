# UI sweep report: billing2 (functional + visual depth pass)

Env: DB `rivetmsp_demo` (config from demos/rivetmsp, base URL localhost:8217, own worktree). Note: the demo DB is shared with the other sweep agents, so the admin user's light/dark preference flipped between runs; dark checks were mostly done by switching `data-bs-theme` after load (so dark contrast numbers for server-rendered inputs/badges are not fully reliable). All test data is `QA-UI-*`; nothing was sent, no PDFs were emailed.

## Coverage (executed; "viewed" = screenshot looked at)
| Area | What was done | Result |
|---|---|---|
| Taxes (admin/tax.php) | seed 8.5% and 20% via UI, edit, archive, validation (-5, 1000, text) | defect 1 fixed; list viewed |
| Products | add (empty -> native validation, valid, price -5), edit, stock modal, export modal, archive | viewed; defect 2 fixed |
| Invoices | create (discount-only, then items with 8.5%/20%/no tax), totals math on screen, discount edit (500, -3, text, 25.505), item edit/neg qty/delete, Mark Sent, payments (0, -5, over balance, partial, edit, delete), note modal, copy/edit/recurring modals, PDF + packing slip rendered to PNG and viewed, print media screenshot viewed, delete from detail page | totals math correct ($324.99 subtotal, $27.00 tax, -$10 discount = $341.99); defects 3-9 fixed |
| Quotes | create, discount via edit, items, totals, PDF viewed, guest "view as client" page at 1366/390 light+dark viewed, convert modal (blocked correctly unless accepted), delete from detail | defects fixed (see below) |
| Recurring invoices | create from client list, items, totals ($249.99 + $27.00 = $276.99), discount edit, force-send NOT clicked, delete | page viewed; defects 10-12 fixed |
| Revenues, expenses, recurring expenses, transfers, trips, accounts | empty submit, valid create, persists after reload, edit, delete (accounts have no delete/archive: proposal) | all passed; wording/typo fixes only |
| Contracts (client scoped) | create (valid + empty), edit, documents modal, delete with confirm | modal viewed; tooltips added |
| Services (client scoped) | create, edit, delete | passed |
| client_autopay | viewed (no provider configured, read only) | blank shell without `client_id`, see unfixed |
| Lists (invoices, quotes, payments + others by load audit) | sort header clicks, search no-match empty state, filter panel, pagination footer, payments CSV export downloaded and read | records-per-page widget fixed |
| Widths | 17 add/edit modals at 1366x768 and 390x844: no h-scroll, none clipped; 19 pages audited at 1366 and 390 with axe; 15 pages dark | see defects |

## Defects found and fixed
| # | Sev | Defect | Fix |
|---|---|---|---|
| 1 | Med | Tax percent accepted 1000%, text became 0%, no required mark/limit | modal `required max=100`, server rejects non-numeric/out of range with a visible message (admin/modals/tax/*, admin/post/tax.php) |
| 2 | Low | "New" + `<strong>Product</strong>` rendered as `NewService` (flex collapsed the space) | wrapped in a span (agent/products.php) |
| 3 | Med | Discount-only invoice/quote/recurring showed negative total and balance (-$10.00), also poisoned list KPI and unpaid sums | stored amount now floored at 0 in every recompute path (create, edit, add/edit/delete item); discount rounded to cents; quote and recurring edit now reject negative or larger-than-subtotal discounts like invoices already did (agent/post/invoice.php, quote.php, recurring_invoice.php, *_model.php) |
| 4 | Med | Negative item quantity accepted server-side (only client pattern blocked it): invoice balance went negative | rejected with a message in invoice/quote/recurring item add+edit |
| 5 | Med | Deleting the only payment left the invoice "Partial" with $0 paid | returns to "Sent" (agent/post/payment.php) |
| 6 | Med | Deleting an invoice/recurring invoice from its own page redirected back to a "There is no Invoice here" page; quote delete from a client link redirected to a non-existent client_quotes.php | redirect to the list |
| 7 | Low | Modal close X sat next to the title instead of at the right edge | `.modal-header > .close` margin-left:auto in css/itflow_design.edition.css (same rule as adminsettings branch: expect a trivial merge conflict) |
| 8 | Low | Records-per-page picker stretched across a third of every list footer | width 6.5rem (edition css) |
| 9 | Low | Missing space above the History/Payments cards on invoice, quote, recurring invoice | `mt-3` |
| 10 | Low | recurring_invoice.php: header block 2/12 short of the right edge, label/value rows misaligned, blank "Last Sent", totals labels without colons, un-styled tax select | aligned with invoice/quote markup |
| 11 | Low | Item edit modals: quantity used the scale icon, tax used the piggy bank | hashtag / balance-scale |
| 12 | Low | Phones: line-item names wrapped letter by letter on invoice, quote, recurring and guest views, scroll region not focusable (axe serious) | nowrap on phones (`.guest-items`), tabindex+role on guest tables |
| 13 | Low | Responsive buttons that hide their label below lg (New Contract, New Recurring Invoice, Service/Product toggle ...) had no accessible name (axe critical at 390) | js/a11y_auto.js names them from the hidden text |
| 14 | Low | PHP warnings in log: undefined `email_receipt` on every payment, `config_stripe_enable` (dropped column) on every payment delete, missing item on double delete, undefined `client_id` (recurring expense) | guarded |
| 15 | Low | Text: "Transfering Funds", "Editing expense" etc. lower case, "Editing Recur Invoice", "Create" vs "New" Recurring Expense, CSV header "Referrence", flash "Payment edited to amount $x added", "Save Changes" vs "Save", "Revenue removed", invoice PDF title "Invoice" vs web "INVOICE", arrow touching "QUO-1" in convert modal | all normalised |
| 16 | Low | contract row action buttons had no tooltip | title added |

Files changed: admin/modals/tax/tax_add.php, tax_edit.php, admin/post/tax.php, agent/post/{invoice,quote,recurring_invoice,payment,recurring_expense,revenue,invoice_model,quote_model}.php, agent/{products,invoice,quote,recurring_invoice,contracts}.php, modals (expense, recurring_expense, transfer, invoice, quote, account, service, payment, recurring_invoice), guest/guest_view_{quote,invoice}.php, css/itflow_design.edition.css, js/a11y_auto.js. Scripts in tests/qa/ui-sweep/ (m2.js harness, seed*, inv_flow, crud, pay_flow ...).

## Unfixed / observations
- Dark contrast: guest quote Print/Download buttons (white on accent teal, 2.6:1 in dark) and "Draft" badge (2.5:1) in my dark emulation; accent comes from the admin-configured theme colour, not fixed.
- `agent/client_autopay.php` and other client pages opened WITHOUT `client_id` render an empty shell with undefined `$num_*` warnings from client_side_nav.php (clients area, direct-URL only).
- axe minor: `empty-table-header` (action column) and `heading-order` on invoice/quote pages remain (cosmetic, outline levelled by a11y_auto.js).
- Toasts print on the invoice print view (cosmetic).
- Invoice list Draft KPI previously showed -$10.00 from legacy data; saving that invoice recalculates it.
- Mark Sent can be clicked repeatedly and writes duplicate history rows.

## Proposals (product decisions, not built)
- Accounts cannot be archived or deleted.
- Recurring expense "Month" is required even for Monthly and means "start month" (first run Jan 2027 when created in Nov): relabel "Starting month" or hide for monthly.
- Delete confirmation says only "Are you sure?"; naming the record would be safer.
- Contracts use three inline icon buttons while other billing lists use the "..." menu.
- maxlength on all text inputs (carried over from the previous report).

## Not tested and why
- Sending anything (Send invoice/quote, Email Notify, Force Send, reminders), Stripe/autopay saving (no provider), quote accept/convert (needs guest accept), Pax8, QuickBooks, Odoo.
- Bulk actions (bulk payment with selection, bulk category/client/account on lists), invoice "Add unbilled ticket", expense refund/copy submit, trip copy submit, product stock add submit, file upload on expense, recurring invoice force send.
- Real server-side dark mode with per-page axe (user preference was being toggled by other agents); 1920 light screenshots of every list were not re-viewed beyond those listed.
- Portal-side billing pages (client portal) belong to another area.
