# Guest links

A **guest link** is a web address that opens one thing, such as an invoice, a quote, a ticket, a shared document or a sign-off form, for a person who has no portal login. The address carries a secret key, so only someone who was given the link can open it. RivetMSP puts these links in its e-mails, and you can copy most of them to send yourself. This guide shows every kind of guest page, what the person on the other end can do, and what it does on your side.

| | |
|---|---|
| **Where to find it** | The links come from e-mails RivetMSP sends, or are copied from the technician side: **Copy Guest URL** on an invoice or quote, **Share Link** on a document, file or credential, the change page's client approval box, a task's **Add Approvers**, and the outtake and worksheet pages. |
| **Who can use it** | The person with the link needs nothing. Technicians need **Sales** at **Read** to open invoices and quotes, **Support** at **Modify** to share items, add approvers and send change approval requests, and **Credentials** for sharing a credential (see [Users, roles and security](34-users-roles-security.md)). |
| **Turn it on** | Nothing to turn on. E-mailing a link needs outgoing mail set up. Pay Now needs an active payment provider (Administration → **Billing** → **Payment Providers**). |

## What it's for

- Letting a client **view, print and pay** an invoice from an e-mail.
- Letting a client **accept or decline** a quote, and send a signed PDF back.
- Showing a ticket to someone without a portal login, and letting them rate it.
- Getting a **task approved** or a **change approved or rejected** with one click.
- Sending a document, file or login **securely**, with an expiry time and an optional single view.
- Collecting a signature on a **device pickup (outtake) form** or a worksheet.

> **Note:** Anyone who has a link can use it. Treat guest links like a key: send them only to the person they are for. Pages are marked "do not index" and shares expire, but a forwarded e-mail forwards the access.

## A quick tour

Guest pages share one look: a plain page with your company name, no menu and no sign-in. Opening one is recorded, and most create a bell notification for your team.

| Page | Opens | Visitor can | Recorded on your side |
|---|---|---|---|
| Invoice | `guest_view_invoice.php` | View, print, download PDF, **Pay Now** | Status **Sent** becomes **Viewed**; history line; **Invoice Viewed** notification |
| Quote | `guest_view_quote.php` | View, print, download, **Accept**, **Decline**, **Upload File** (after acceptance) | **Sent** becomes **Viewed**; **Quote Accepted** or **Quote Declined** notification and e-mail |
| Ticket | `guest_view_ticket.php` | Read, **Reopen ticket**, **Close ticket**, rate | Ticket status, rating, notifications |
| Task approval | `guest_approve_ticket_task.php` | **Approve Task** | Task approval turns **approved**; the technician is notified |
| Change approval | `guest_view_change.php` | **Approve** or **Reject** | Client decision on the change, audit event |
| Shared item | `guest_view_item.php` | Read a document, download a file, read a login | **Share Viewed** notification; view count |
| Outtake form | `outtake_sign.php` | Read and sign | Signature stored |
| Worksheet | `worksheet_sign.php` | Fill in and sign | Signature stored |

## Common tasks

### Invoices

Open an invoice on the technician side and use the **⋮** menu → **Copy Guest URL**. **Send Email** (same menu) mails the link when outgoing mail is set up and the client has a primary contact e-mail. Clients also reach it from the portal's invoice list (see [Client portal](32-client-portal.md)).

![The invoice page's action menu with Copy Guest URL highlighted](images/portal/guest-02-agent-invoice-copy-url.png)

*Figure 1 — **Copy Guest URL** (1) on an invoice. Example data.*

![The guest invoice page with call-outs on Account Balance, Print, Download, Pay Now and the status badge](images/portal/guest-01-invoice.png)

*Figure 2 — What the client sees. Account Balance (1), **Print** (2), **Download** (3), **Pay Now** (4), status (5). Example data, not a live connection.*

1. **Account Balance (1)** is the client's total unpaid balance across all invoices, and not only this one.
2. **Print (2)** and **Download (3)** give a PDF.
3. **Pay Now (4)** appears unless the invoice is **Paid**, **Cancelled** or a **Draft**, a payment provider is active, and the invoice is under the provider's amount limit (0 means no limit). It opens a card form hosted by Stripe. Card payment was not verified on a live service for this guide.
4. Below the invoice the page lists the client's other unpaid invoices, **Current Invoices** and **Outstanding Invoices**, each with its own link.

> **Note:** Because that list links to the client's other open invoices, one invoice link gives access to all of the client's unpaid ones. A paid invoice or a draft can still be viewed by its link; the page shows its status.

### Quotes

Open a quote and use **⋮** → **Copy Guest URL** (or **Send Email**).

![The quote action menu with Copy Guest URL highlighted](images/portal/guest-04-agent-quote-copy-url.png)

*Figure 3 — **Copy Guest URL** on a quote.*

![The guest quote page with status, expiry date, Accept and Decline](images/portal/guest-03-quote.png)

*Figure 4 — A quote waiting for an answer: status (1), **Expires** (2), **Accept** (3), **Decline** (4). Example data.*

- **Accept** and **Decline** ask for confirmation, then change the quote to **Accepted** or **Declined**, add a history line, notify your team and, if outgoing mail and a quote notification address (Administration → **Settings** → **Quotes**) are set, e-mail that address.
- A quote can be answered only while it is **Sent** or **Viewed** and not past its **Expires** date. Otherwise the page says "This quote can no longer be changed".
- After acceptance the page offers **Upload File** (PDF only), for a signed copy. The PDF is filed under the client's **Client Uploads** folder and your team is notified.

![An accepted quote with the Upload File button](images/portal/guest-05-quote-accepted.png)

*Figure 5 — After acceptance: status (1) and **Upload File** (2). Captured on a throw-away copy of the demo.*

> **Note:** For a quote that is **Sent** and already expired the **Accept** and **Decline** buttons are still drawn, but pressing them is refused with the message above.

### Tickets

Ticket e-mails (new, updated, resolved, closed, rating requests and collaborator invitations) contain a **portal link** that is a guest ticket link. It shows the ticket's status, priority, technician, details and the public replies, never internal notes.

![A guest ticket that is still open, with a note to sign in or reply by e-mail](images/portal/guest-06-ticket-open.png)

*Figure 6 — An open ticket. A guest cannot reply here (1); they sign in to the portal or reply to the e-mail, so the reply is tied to a person.*

![A resolved ticket with Reopen ticket and Close ticket](images/portal/guest-07-ticket-resolved.png)

*Figure 7 — A resolved ticket: status (1), **Reopen ticket** (2), **Close ticket** (3).*

![A closed ticket asking How did we do with five faces and a comment box](images/portal/guest-08-ticket-rating.png)

*Figure 8 — A closed ticket can be rated (1) with an optional comment (2) when **Ticket CSAT** is on.*

The one-click face links in rating e-mails open `guest_rate.php`. It records the rating once (the first click wins), then shows this page. A low rating reopens the ticket. See [Recurring tickets, catalog, CSAT and requests](07-recurring-tickets-catalog-csat-requests.md#csat-ratings).

### Approve a task

A task can need approval before work goes ahead. On the ticket, open the task's **⋮** menu → **Add Approvers**.

![The task menu on a ticket with Add Approvers](images/portal/guest-09-agent-task-menu.png)

*Figure 9 — **Add Approvers** (1) on a task.*

![The Add Approvers pop-up with Approval scope and Who can approve](images/portal/guest-11-agent-task-approvers-modal.png)

*Figure 10 — **Approval scope** (1): **Internal** or **Client**; **Who can approve?** (2): **any**, **technical**, **billing** or a specific person (internal only).*

With scope **Client** and **any**, the contact on the ticket is e-mailed a link. Internal approvals never use a guest link: they must be done by a signed-in technician.

![The guest task approval page with the Approve Task link](images/portal/guest-10-task-approval.png)

*Figure 11 — The guest approval page: task details (1) and **Approve Task** (2). Example data.*

Approving sets the approval to **approved** and tells the technician who asked. There is no decline button on this page. Contacts who are signed in to the portal can approve from the ticket's **Approvals** box instead.

### Approve a change

A change can need the client's own sign-off. On the change page use **Request Client Approval** (later **Resend Approval Request**). The link is shown in the box so you can copy it.

![The change page's client approval box with the link and Send Approval Request button](images/portal/guest-13-agent-change-approval.png)

*Figure 12 — Client approval box (1) with the copyable link and **Resend Approval Request** (2).*

![The guest change page with risk badge, decision badge, Approve and Reject](images/portal/guest-12-change.png)

*Figure 13 — Risk (1), decision (2), **Approve** (3), **Reject** (4). Example data.*

The page can be answered once, and only while the change is not **successful**, **failed**, **rolled back** or **cancelled**. Afterwards it says "This change was already approved".

### Share a document, file or login

On a document, file or credential choose **Share Link**.

![The Share Link pop-up with recipient, expiration, Delete after view, note and Send and Show Link](images/portal/guest-14-agent-share-link.png)

*Figure 14 — **Share with** (1), **Expiration** (2): 1 hour, 1 day, 1 week or 1 month; **Delete after view** (3), a **Client visible note** (4), **Send and Show Link** (5).*

1. Pick or type the recipient's e-mail under **Share with**. If outgoing mail is set up and the address is valid, the link is e-mailed; it is always shown on screen too.
2. Choose how long it lasts. **Delete after view** allows one view.
3. Click **Send and Show Link** and copy the address.

The recipient sees:

![A shared document page](images/portal/guest-16-shared-document.png)

*Figure 15 — A shared document: who it is for and the view count and expiry (1), content (2).*

![A shared login with the username and password decrypted in the browser](images/portal/guest-17-shared-credential.png)

*Figure 16 — A shared login: username (1) and password (2). Demo values only.*

For a **Credential**, the secret is encrypted on the server and the key to open it sits only in the part of the link after `#ek=`. Browsers never send that part to the server, so it is not in logs. If it is missing the page says so:

![The credential page when the link has lost its decryption key](images/portal/guest-18-shared-credential-no-key.png)

*Figure 17 — A link copied without its `#ek=` part (1). Use the original link.*

A shared **File** shows a **Download** link (a download counts as a view):

![A shared file page with a download link](images/portal/guest-19-shared-file.png)

*Figure 18 — A shared file (1).*

If the link is wrong, expired, used up or switched off, the page says "No item to view" or "Item cannot be viewed at this time":

![The page shown for an invalid share link](images/portal/guest-24-invalid-link.png)

*Figure 19 — An invalid link.*

To see or cancel shares, open the client's **Overview**: the **Shared Items** card lists the first five active shares with views, recipient and expiry; the calendar icon (**Deactivate Link**) deletes one. A daily job removes expired and used-up shares.

![The Shared Items card on the client overview](images/portal/guest-15-agent-shared-items.png)

*Figure 20 — **Shared Items** (1) and **Deactivate Link** (2).*

> **Note:** A link to a **Document** or **File** can be passed on to anyone, and every view is logged under **Share**. Use a short expiry and **Delete after view** for anything sensitive. Credential links should travel by a different channel than the password's owner would use.

### Sign a device pickup form

Outtake forms and worksheets marked as outtake are signed on `outtake_sign.php` and `worksheet_sign.php`. The visitor types a full name, draws a signature, ticks the terms box and submits.

![The outtake form signing page](images/portal/guest-21-outtake-sign.png)

*Figure 21 — The signing block at the foot of the form: **Full Name**, a **Signature** box to draw in, the terms tick and **Submit & Sign Outtake Form**. Example data.*

The form's disclaimer text links to a third-party terms page that is built into the form template. A signed form shows **Form Signed**, who signed and when, and can be printed to PDF. See [Field work](10-problems-and-changes.md) for creating the forms.

## Reference

### Link addresses

| Kind | Address pattern | Key |
|---|---|---|
| Invoice | `/guest/guest_view_invoice.php?invoice_id=N&url_key=KEY` | per-invoice key |
| Quote | `/guest/guest_view_quote.php?quote_id=N&url_key=KEY` | per-quote key |
| Ticket | `/guest/guest_view_ticket.php?ticket_id=N&url_key=KEY` | per-ticket key |
| Task approval | `/guest/guest_approve_ticket_task.php?task_approval_id=N&url_key=KEY` | per-approval key |
| Change | `/guest/guest_view_change.php?change_id=N&url_key=KEY` | per-change key |
| Shared item | `/guest/guest_view_item.php?id=N&key=KEY` (credentials add `#ek=…`) | 32-character key |
| Outtake / worksheet | `/guest/outtake_sign.php?token=T`, `/guest/worksheet_sign.php?token=T` | per-form token |
| Rating star | `/guest/guest_rate.php?ticket_id=N&url_key=KEY&rating=1..5` | ticket key |

Opening `/guest/` alone redirects to `/client`.

### Share options

| Option | Values |
|---|---|
| **Expiration** | 1 hour, 1 day, 1 week, 1 month |
| **Delete after view** | Off (unlimited views until expiry) or On (1 view) |
| Item types | **Document**, **File**, **Credential** |

## Tips and good practice

- Send invoice and quote links by e-mail from RivetMSP, so the history records who got them.
- Prefer the portal for clients who log in often; guest links are best for one-off actions.
- Share credentials with **Delete after view** and a 1 hour expiry, and phone the recipient.
- Check **Shared Items** on the client overview now and then and deactivate what is no longer needed.
- Do not paste guest links into public tickets or chats.

> **Note:** The reset-password, welcome and share e-mails build their links with `https://`. Without HTTPS in front of the site those links will not open.

## Related guides

- [Client portal](32-client-portal.md)
- [Service desk tickets](05-service-desk-tickets.md)
- [Clients, contacts and locations](03-clients-contacts-locations.md)
- [Problems and changes](10-problems-and-changes.md)
