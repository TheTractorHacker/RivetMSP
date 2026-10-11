/*
 * Administration > Endpoint agent > MeshCentral (RivetCore 1.0.0-rc.12): the small helpers of the install fields. Linked by includes/footer.php only on the page that sets
 * $rmm_mesh_scripts. Everything works without it: the group id box only toggles the display of a masked field,
 * and the proposed pin is printed in the page and can be copied by hand. No window.confirm, no inline handlers, no network requests; nothing is saved from here.
 */
(function () {
    'use strict';

    // "Show the group id": switch a masked field to text and back.
    document.addEventListener('change', function (e) {
        var box = e.target && e.target.closest ? e.target.closest('[data-rmm-mesh-show]') : null;
        if (!box) { return; }
        var field = document.getElementById(box.getAttribute('data-rmm-mesh-show'));
        if (field) { field.type = box.checked ? 'text' : 'password'; }
    });

    // "Put this hash in the pin field": fills the field and moves focus to it. It never submits anything; the administrator still presses Save.
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-rmm-mesh-use-pin]') : null;
        if (!b) { return; }
        e.preventDefault();
        var field = document.getElementById('mc_pin');
        if (!field) { return; }
        field.value = b.getAttribute('data-rmm-mesh-use-pin') || '';
        field.focus();
        field.scrollIntoView({ block: 'center' });
        var note = document.createElement('span');
        note.className = 'small text-success ms-2';
        note.setAttribute('role', 'status');
        note.textContent = 'Put in the pin field. Press Save to keep it.';
        if (b.parentNode && !b.parentNode.querySelector('[data-rmm-mesh-pin-note]')) {
            note.setAttribute('data-rmm-mesh-pin-note', '1');
            b.parentNode.appendChild(note);
        }
    });
})();
