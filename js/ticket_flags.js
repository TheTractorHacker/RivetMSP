/*
 * Ticket page: the "Count toward SLA" and "Email notifications" switches (DB 2.6.105). Each change asks first (RivetDialog, never a native
 * dialog), then posts the hidden #ticketFlagForm; the switch snaps back until the server has saved it. Turning SLA on asks when the SLA starts.
 */
(function () {
    'use strict';
    var form = document.getElementById('ticketFlagForm');
    if (!form || !window.RivetDialog) { return; }

    function send(action, value, anchor) {
        form.querySelector('[name="flag_action"]').name = action;
        form.querySelector('[name="value"]').value = value;
        form.querySelector('[name="sla_anchor"]').value = anchor || '';
        form.submit();
    }

    document.querySelectorAll('.js-ticket-flag').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var on = cb.checked;
            cb.checked = !on; // unchanged until the server says so
            var flag = cb.getAttribute('data-flag');
            if (flag === 'counts_sla' && on) {
                RivetDialog.choice('Count this ticket toward SLA. When should the SLA clock start?\n\n"From now" gives the full response and resolution time from this moment. "From ticket creation" applies the SLA as if it had always been on, so it may already be at risk or breached.', [
                    { value: 'now', label: 'Start from now' },
                    { value: 'created', label: 'Start from ticket creation', variant: 'secondary' }
                ], { title: 'Count toward SLA' }).then(function (v) { if (v) { send('set_ticket_counts_sla', '1', v); } });
            } else if (flag === 'counts_sla') {
                RivetDialog.confirm('Stop counting this ticket toward SLA? Its SLA due dates are cleared, it can no longer breach, and it is left out of the SLA numbers.', { title: 'Count toward SLA', okText: 'Stop counting', variant: 'warning' })
                    .then(function (yes) { if (yes) { send('set_ticket_counts_sla', '0'); } });
            } else if (flag === 'send_email' && !on) {
                RivetDialog.confirm('Turn off email notifications for this ticket? No email about it is sent (new ticket, replies, status changes, assignment, closing, satisfaction surveys) until it is turned back on. Email already queued for it is cancelled. Internal notes, in-app notifications and webhooks are not affected.', { title: 'Email notifications', okText: 'Turn off', variant: 'warning' })
                    .then(function (yes) { if (yes) { send('set_ticket_send_email', '0'); } });
            } else if (flag === 'send_email') {
                send('set_ticket_send_email', '1');
            }
        });
    });
})();
