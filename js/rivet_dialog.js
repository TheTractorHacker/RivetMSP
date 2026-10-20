/*
 * RivetDialog: the app's replacement for window.alert / window.confirm / window.prompt.
 *
 *   RivetDialog.alert(message, opts)    -> Promise<true>         (resolves when dismissed)
 *   RivetDialog.confirm(message, opts)  -> Promise<boolean>      (true = confirmed; Esc / backdrop / Cancel = false)
 *   RivetDialog.prompt(message, opts)   -> Promise<string|null>  (null = cancelled)
 *
 * opts: title, okText, cancelText, variant ('primary' | 'danger' | 'warning'), defaultValue, placeholder, type ('text' | 'password'; inputType is an alias), required.
 * gate(event, target, message, opts) wraps click / submit handlers that used to call confirm() synchronously (same API as RivetIT).
 * The message is plain text: it is only ever assigned with textContent (never innerHTML), so callers may pass server or user supplied
 * strings. Newlines are kept. Dialogs are queued and shown one at a time. The modal DOM is built on first use, so every layout that
 * loads this file has the dialog without carrying any markup. No inline handlers, no inline style attributes (CSP safe).
 * It deliberately does not use bootstrap.Modal: that class closes or fights an already open modal (focus trap, one modal at a time), and
 * these dialogs must be able to sit on top of one (a confirm inside an AJAX modal).
 * Also: [data-confirm-submit] forms, .js-confirm-native links and [data-rivet-confirm] buttons/links are handled by the delegates at the bottom.
 */
(function (global) {
    'use strict';
    if (global.RivetDialog) { return; }

    var queue = [];
    var busy = false;
    var seq = 0;
    var pending = new WeakSet();   // forms / links with a dialog open (no double submit)
    var approved = new WeakSet();  // forms / links whose next submit/click is the confirmed one

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = String(text); }
        return e;
    }

    function focusables(root) {
        return Array.prototype.filter.call(
            root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; }
        );
    }

    function run(task, done) {
        var id = 'rivetDialog' + (++seq);
        var returnTo = document.activeElement;
        var inerted = [];
        var finished = false;

        var backdrop = el('div', 'modal-backdrop fade show');
        backdrop.style.zIndex = '2090';
        var root = el('div', 'modal fade show rivet-dialog');
        root.style.display = 'block';
        root.style.zIndex = '2100';
        root.tabIndex = -1;
        root.setAttribute('role', 'alertdialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', id + 'T');
        root.setAttribute('aria-describedby', id + 'D');

        var dlg = el('div', 'modal-dialog modal-dialog-centered modal-dialog-scrollable');
        var content = el('div', 'modal-content');
        var header = el('div', 'modal-header');
        var title = el('h5', 'modal-title', task.title);
        title.id = id + 'T';
        var x = el('button', 'btn-close');
        x.type = 'button';
        x.setAttribute('aria-label', 'Close');
        header.appendChild(title);
        header.appendChild(x);

        var body = el('div', 'modal-body');
        var msg = el('div', '', task.message);
        msg.id = id + 'D';
        msg.style.whiteSpace = 'pre-line';
        msg.style.overflowWrap = 'anywhere';
        body.appendChild(msg);
        var input = null;
        if (task.kind === 'prompt') {
            input = el('input', 'form-control mt-3');
            input.type = task.inputType;
            input.value = task.defaultValue;
            if (task.placeholder) { input.placeholder = task.placeholder; }
            input.setAttribute('aria-labelledby', id + 'D');
            input.autocomplete = task.inputType === 'password' ? 'current-password' : 'off';
            body.appendChild(input);
        }

        var footer = el('div', 'modal-footer');
        var cancel = null;
        if (task.kind !== 'alert') {
            cancel = el('button', 'btn btn-secondary', task.cancelText);
            cancel.type = 'button';
            footer.appendChild(cancel);
        }
        var ok = el('button', 'btn ' + (task.variant === 'danger' ? 'btn-danger' : task.variant === 'warning' ? 'btn-warning' : 'btn-primary'), task.okText);
        ok.type = 'button';
        footer.appendChild(ok);

        content.appendChild(header);
        content.appendChild(body);
        content.appendChild(footer);
        dlg.appendChild(content);
        root.appendChild(dlg);

        function finish(accepted) {
            if (finished) { return; }
            finished = true;
            var value;
            if (task.kind === 'alert') { value = true; }
            else if (task.kind === 'confirm') { value = !!accepted; }
            else { value = accepted ? input.value : null; }
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('focusin', onFocusIn, true);
            if (root.parentNode) { root.parentNode.removeChild(root); }
            if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
            inerted.forEach(function (n) { n.inert = false; if (n.__rdAria === null) { n.removeAttribute('aria-hidden'); } });
            if (returnTo && returnTo.focus && document.contains(returnTo)) { try { returnTo.focus(); } catch (e) { /* gone */ } }
            task.resolve(value);
            done();
        }
        function accept() {
            if (task.kind === 'prompt' && task.required && !input.value) { input.focus(); return; }
            finish(true);
        }

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                finish(false);
            } else if (e.key === 'Enter' && input && e.target === input) {
                e.preventDefault(); e.stopPropagation();
                accept();
            } else if (e.key === 'Tab') {
                var f = focusables(root);
                if (!f.length) { e.preventDefault(); return; }
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
            }
        }
        // Capture phase on document: runs before Bootstrap's own focus trap / Esc handling of a modal underneath.
        function onFocusIn(e) {
            if (root.contains(e.target)) { e.stopPropagation(); return; }
            e.stopPropagation();
            var f = focusables(root);
            if (f.length) { f[0].focus(); }
        }

        x.addEventListener('click', function () { finish(false); });
        ok.addEventListener('click', accept);
        if (cancel) { cancel.addEventListener('click', function () { finish(false); }); }
        // Backdrop click = cancel. Only a click that starts and ends on the dim area, not a text selection dragged out of the dialog.
        var downOnRoot = false;
        root.addEventListener('mousedown', function (e) { downOnRoot = (e.target === root); });
        root.addEventListener('click', function (e) { if (e.target === root && downOnRoot) { finish(false); } });

        // Make everything else inert (real focus trap for keyboard and screen readers).
        Array.prototype.forEach.call(document.body.children, function (n) {
            if (n === root || n === backdrop || n.tagName === 'SCRIPT' || n.inert) { return; }
            n.__rdAria = n.getAttribute('aria-hidden');
            n.inert = true;
            inerted.push(n);
        });
        document.body.appendChild(backdrop);
        document.body.appendChild(root);
        document.addEventListener('keydown', onKey, true);
        document.addEventListener('focusin', onFocusIn, true);

        // Safe default: Cancel for a confirm, the input for a prompt, OK for an alert.
        var initial = input || cancel || ok;
        initial.focus();
        if (input) { input.select(); }
    }

    function pump() {
        if (busy || !queue.length) { return; }
        busy = true;
        var task = queue.shift();
        if (!document.body) { // called before <body> exists: wait, never fall back to a native dialog
            busy = false; queue.unshift(task);
            document.addEventListener('DOMContentLoaded', pump, { once: true });
            return;
        }
        run(task, function () { busy = false; pump(); });
    }

    function enqueue(kind, message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            queue.push({
                kind: kind,
                message: message === undefined || message === null ? '' : String(message),
                title: opts.title || (kind === 'alert' ? 'Notice' : kind === 'confirm' ? 'Please confirm' : 'Input needed'),
                okText: opts.okText || (kind === 'alert' ? 'OK' : kind === 'confirm' ? 'Yes' : 'OK'),
                cancelText: opts.cancelText || 'Cancel',
                variant: opts.variant === 'danger' || opts.variant === 'warning' ? opts.variant : 'primary',
                defaultValue: opts.defaultValue === undefined || opts.defaultValue === null ? '' : String(opts.defaultValue),
                placeholder: opts.placeholder || '',
                inputType: (opts.type || opts.inputType) === 'password' ? 'password' : 'text',
                required: !!opts.required,
                resolve: resolve
            });
            pump();
        });
    }

    var RivetDialog = {
        alert: function (message, opts) { return enqueue('alert', message, opts); },
        confirm: function (message, opts) { return enqueue('confirm', message, opts); },
        prompt: function (message, opts) { return enqueue('prompt', message, opts); },
        // gate(event, target, message, opts): for click / submit handlers that used to call confirm() synchronously.
        // Returns true when the caller may let the event run natively now (this target was just approved); false when it cancelled the
        // event and opened the confirm (a yes re-runs the click / form submit exactly once; a second attempt while the dialog is open is ignored).
        gate: function (e, target, message, o) {
            if (approved.has(target)) { approved.delete(target); return true; }
            e.preventDefault();
            e.stopImmediatePropagation();
            if (pending.has(target)) { return false; }
            pending.add(target);
            var submitter = e.type === 'submit' ? e.submitter : null;
            enqueue('confirm', message, o).then(function (yes) {
                pending.delete(target);
                if (!yes) { return; }
                approved.add(target);
                if (e.type === 'submit') {
                    if (typeof target.requestSubmit === 'function') { target.requestSubmit(submitter && submitter.form === target ? submitter : undefined); }
                    else { target.submit(); }
                } else if (typeof target.click === 'function') {
                    target.click();
                }
                approved.delete(target);   // a vetoed (invalid) submit must not leave the target pre-approved
            });
            return false;
        }
    };
    global.RivetDialog = RivetDialog;

    // ---- delegated, CSP-safe opt-in markup --------------------------------------------------------------------------------------
    if (global.__rivetDialogDelegates) { return; }
    global.__rivetDialogDelegates = true;
    function opts(node) {
        return { variant: node.getAttribute('data-confirm-variant') === 'danger' ? 'danger' : 'primary',
                 title: node.getAttribute('data-confirm-title') || undefined,
                 okText: node.getAttribute('data-confirm-ok') || undefined };
    }

    // <form data-confirm-submit="Message">
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var m = form && form.getAttribute ? form.getAttribute('data-confirm-submit') : null;
        if (m) { RivetDialog.gate(e, form, m, opts(form)); }
    }, true);

    // [data-rivet-confirm="Message"] on a link or button (and .js-confirm-native with data-confirm-message): ask, then continue the click.
    document.addEventListener('click', function (e) {
        var n = e.target && e.target.closest ? e.target.closest('[data-rivet-confirm], .js-confirm-native') : null;
        if (!n) { return; }
        RivetDialog.gate(e, n, n.getAttribute('data-rivet-confirm') || n.getAttribute('data-confirm-message') || 'Are you sure?', opts(n));
    }, true);
})(window);
