# UI sweep report: client portal (AREA=portal, port 8211)

Branch `ui-sweep-portal`, worktree `/home/sysadmin/ui-sweep-portal`. Tested against a scratch copy of the demo DB
(`rivetmsp_scratch_uisweep_portal`, schema-identical clone of `rivetmsp_demo`) with throwaway portal users
(QA-UI Billing / Tech / Plain / Pwtest) added to the Harbor Dental client plus the supplied `qa251` login (client with
almost no data, used for empty states). Seed records are named `QA-UI-...`. Dark mode was toggled in the scratch DB only.
Scripts: this folder (`lib.js`, `crawl.js`, `flow1-9.js`, `guest.js`, `probe.js`, `nav.js`).

## Coverage

Legend: L = light 1920x1080, D = dark, M = mobile 390x844 (all with the DOM audit; axe on D and guest pages).

| Page / area | Visited | What was exercised | Result |
|---|---|---|---|
| client/index.php (home) | L D M, 3 roles + empty | KPI cards, recent tickets, assets, attention list, quick links, View All, New Ticket | defects fixed (orphan KPI/quick-link cells, wide single card, last update "-", dark contrast, no gap on mobile) |
| tickets.php (Open/Closed/All, counts) | L M | all three filters, row links, New Ticket | fixed mobile spacing |
| ticket_view_all.php | L M D | status filter (Any/Open/Closed) | filter did nothing (js/app.js not loaded in portal): fixed |
| ticket_add.php | L M D | empty submit (browser validation), subject only, valid | Details was not enforced server side (blank ticket created): fixed. No attachment field on create (proposal) |
| ticket.php (open, resolved, closed, with replies) | L M D | empty reply (error shown), reply with .txt attachment (persisted, download/view links), Resolve confirm modal, resolved->closed, CSAT pick + comment submit, closed-ticket state | JS ReferenceError ($ undefined), invisible card title, reopen/close buttons, serif avatar initials: fixed |
| invoices / unpaid_invoices / recurring_invoices / quotes (billing contact) | L M D | with data and empty, invoice/quote links (no payments made) | link affordance and overdue colour fixed; PHP warnings in unpaid_invoices fixed |
| saved_payment_methods.php | L M | no provider configured | bare text replaced with card message |
| assets.php | L M D | with data and empty | name wrapped mid-token on phone: fixed |
| contracts.php | L M D | accordion expand/collapse, empty | different layout from siblings, badge clipping, a11y: fixed |
| documents.php + New Document / Upload File modals | L D | empty submit (browser validation), create doc, upload .txt, persisted after reload, eye action | raw `<strong>` in flash, `.close` button, button order, bg-white in dark, icon-only link: fixed |
| document.php | L | view created doc | ok |
| domains.php / certificates.php | L M D | expiring/expired/valid rows, empty | expiry colour was flattened to black: fixed |
| contacts.php / contact_add.php / contact_edit.php | L M D | add (empty, valid), duplicate email, edit view, primary contact blocked | header overflowed phone (offset-6); adding a contact with default "No portal access" always failed: fixed |
| kb_articles.php / kb_article.php | L M D | list, search box, article view (module enabled in scratch DB) | blank page + PHP warning when module disabled, dark footer, CSP-blocked inline styles: fixed |
| service_catalog.php | L M D | items with long name, approval badge | ok (footer bg fixed) |
| profile.php | L M D | view, short password (browser message), valid password change on throwaway user, re-login with new password | unstyled page rebuilt as cards; placeholder text wrong: fixed |
| compliance.php | redirect only | no shared compliance report in the scratch DB, page redirects to home | not rendered (see below) |
| login.php / client/login_reset.php | L M | empty and wrong password (error shown), invalid reset email (browser message) | link contrast fixed, reset button now matches Sign In |
| guest: invoice, quote, ticket views | L M | quote Accept (confirm modal, state change, Upload File modal), Print/Download buttons | quote precedence bug, mobile table, ticket header, button styles, footer gap fixed |
| guest: invalid keys, guest_view_item/change, approve task, download | L | error pages | bare headings replaced with centred card |
| Nav menus (desktop dropdowns, mobile toggler) | L M | Finance, Technical, user menu, hamburger | toggler had no accessible name: fixed |
| Permission redirects (Plain/Tech/Billing hitting pages they may not open) | L | each gated page | see "Defects" #1 |

## Defects found and fixed (severity)

1. HIGH: every access check in client pages (`header("Location: ...")` after the header HTML was already sent) raised "headers already sent" and left a blank page instead of redirecting (KB disabled, billing page for a technical contact, etc.). Fixed with `ob_start()` in `client/includes/inc_all.php`.
2. HIGH: when the redirect did work (upstream behaviour) it was `post.php?logout`: browsing to a page you lack permission for logged the user out. Now redirects home with "You do not have access to that page." (contact/document not found go to their list with a message).
3. HIGH: portal "New Contact" / "Edit Contact" rejected the form's default "No portal access" value ("Invalid authentication method"); contacts without portal access could not be saved. Now accepted.
4. HIGH: new ticket could be created with empty Details (TinyMCE textarea cannot be validated by the browser). Server now requires subject and details.
5. MED: ticket page: card title invisible (dark on dark, light and dark); jQuery ReferenceError on every ticket page (script loaded before jQuery); Reopen/Close buttons mis-spaced with an invisible icon; serif initials in reply avatars (portal and guest ticket); guest ticket header title invisible.
6. MED: ticket_view_all status filter did nothing; invoice/ticket/contact/asset links in tables looked like plain text; `text-danger` on table cells (overdue invoice, expiring domain) was flattened to black by a global `!important`.
7. MED: contacts header pushed the page 27px wider than a phone (`col offset-6`); contracts page used a different container/card layout and clipped badges on phones; status pills broke mid-word on phones; assets names wrapped inside the token.
8. MED: guest quote: Accept/Decline logic had an operator-precedence bug (expired Sent quotes still showed Accept; expired Viewed quotes silently showed nothing). Now: not expired -> Accept/Decline, expired -> explanatory message.
9. MED: dark mode: grey badges 2.5:1, outline buttons 3.3:1, hard-coded `bg-white` footers/modals, KB card footer.
10. MED: `inc_confirm_modal.php` and portal/guest modals used a BS4 `.close` button that rendered next to the title with no accessible name (now `btn-close`); modal footer order made consistent (Cancel then primary).
11. LOW: raw `<strong>` tags shown in "Document created/uploaded" flash; "Add comments.." typo, reply editor unlabeled; PHP warnings in unpaid_invoices.php and guest_view_invoice.php (no payment provider); inline styles blocked by the page CSP (KB search width, document image height, KB embed iframe fell back to 300x150); bare "Oops"/"Stripe payment error" text pages; orphaned KPI card / quick link; recent tickets "Last update" blank for never-updated tickets; icon-only links without names (documents eye, KB attachment actions, navbar toggler, KB search); heading order (welcome banner h4 above page h3); login/forgot link contrast 3.7:1; footer text glued to the card on guest pages; unstyled guest file input; empty `th` in unpaid invoices; Print/Download buttons differed between quote and invoice.

## Files changed
client/: index.php, tickets.php, ticket.php, ticket_view_all.php, ticket_add.php (none), post.php, profile.php, contacts.php, contracts.php, documents.php, document.php, assets.php, kb_articles.php, kb_article.php, saved_payment_methods.php, unpaid_invoices.php, login_reset.php, includes/inc_all.php, includes/header.php, plus the one-line access redirect in contact_add, contact_edit, certificates, domains, invoices, quotes, recurring_invoices.
guest/: guest_view_quote.php, guest_view_invoice.php, guest_view_ticket.php, guest_view_change.php, guest_approve_ticket_task.php, guest_pay_invoice_stripe.php, guest_quote_upload_file_modal.php, includes/guest_header.php.
shared: includes/inc_confirm_modal.php, includes/footer.php (guest footer paragraph classes), css/itflow_design.edition.css (portal block appended at the end; `body.client-portal` class added to the portal and guest bodies).

## Not fixed / not tested (and why)
- Dark-mode TinyMCE editor stays white (the vendored TinyMCE has no oxide-dark skin folder). Readable, cosmetic.
- Guest pages have no dark theme (they use the light accent regardless of the setting); teal accent on guest vs blue on portal is a branding decision.
- Guest invoice "current invoices" table: invoice number cell is not vertically centred (minor, left).
- Not rendered: client/compliance.php (needs a shared compliance report; page only redirects), SSO pages login_microsoft.php / login_oidc.php (need an identity provider), saved card management and "Pay" (payment provider, forbidden), sse_ticket_stream.php (live stream, only exercised implicitly), kb_media/kb_embed attachments (no attachments in the demo data), 2FA/passkey screens (none exist in the client portal; only passkey sign-in button on login.php), agent/csat.php (agent-side report, other area), worksheet_sign.php / outtake_sign.php (404 in this tree).
- Real e-mail sending (password reset request, quote accept notifications): never submitted; SMTP host was set to a dummy value in the scratch DB only to render the reset page.

## Proposals (product decisions, not built)
- Allow attachments when raising a ticket (only replies can attach).
- Keep typed Subject when the server rejects a new ticket (the form reloads empty).
- Hide "Saved Payments" in the portal nav when no payment provider is configured.
- Profile: add current-password and confirm-password fields; show/hide toggle.
- Show ticket status colours (DB has them; portal lists use a grey pill for every status).
- The large "Welcome back" banner repeats on every page; consider showing it only on Home.
- Portal accent (blue) vs guest/login accent (teal) unification.
