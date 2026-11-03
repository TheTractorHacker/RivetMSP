/*
 * Leads board: drag cards between pipeline stages. Each move is saved at once (ajax.php lead_set_status);
 * a failed save puts the card back where it came from and says why. Dropping on the green bar converts the lead.
 */
(function () {
    var board = document.getElementById('leadsBoard');
    if (!board || !window.Sortable) { return; }
    var live = document.getElementById('lbLive');
    var csrf = board.getAttribute('data-csrf');

    function say(msg) { if (live) { live.textContent = msg; } }
    function counts() {
        board.querySelectorAll('.lb-col').forEach(function (col) {
            col.querySelector('.lb-count').textContent = col.querySelectorAll('.lb-card').length;
        });
        var open = board.querySelectorAll('.lb-card').length - board.querySelectorAll('.lb-list[data-stage="Lost"] .lb-card').length;
        var el = document.getElementById('lbOpen'); if (el) { el.textContent = open; }
    }
    function save(card, stage, from, fromIndex) {
        var body = new URLSearchParams({ lead_set_status: 1, client_id: card.getAttribute('data-client-id'), status: stage, csrf_token: csrf });
        card.classList.add('lb-saving');
        return fetch('ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response' }; }); })
            .then(function (d) {
                card.classList.remove('lb-saving');
                if (!d.ok) { throw new Error(d.error || 'Not saved'); }
                say(card.querySelector('.lb-name').textContent + ' moved to ' + stage);
                if (d.converted) { card.remove(); }
                counts();
            })
            .catch(function (e) {
                card.classList.remove('lb-saving');
                from.insertBefore(card, from.children[fromIndex] || null);
                counts();
                say('Could not move lead: ' + e.message);
                alert('Could not move the lead: ' + e.message);
            });
    }
    var opts = { group: 'leads', animation: 150, ghostClass: 'lb-ghost', draggable: '.lb-card', filter: 'a', preventOnFilter: false };
    function make(el) {
        Sortable.create(el, Object.assign({}, opts, {
            onMove: function (ev) { document.querySelectorAll('.lb-over').forEach(function (n) { n.classList.remove('lb-over'); }); ev.to.classList.add('lb-over'); },
            onEnd: function (ev) {
                document.querySelectorAll('.lb-over').forEach(function (n) { n.classList.remove('lb-over'); });
                var to = ev.to; var stage = to.getAttribute('data-stage');
                if (!stage) { return; }
                if (to === ev.from) { return; }
                counts();
                save(ev.item, stage, ev.from, ev.oldIndex);
            }
        }));
    }
    board.querySelectorAll('.lb-list').forEach(make);
    var conv = document.getElementById('leadsConvert');
    if (conv) { make(conv); }
})();
