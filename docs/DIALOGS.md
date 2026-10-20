# No native browser dialogs (RivetMSP 26.10.35)

The app never calls `window.alert()`, `window.confirm()` or `window.prompt()`. Use `RivetDialog` (`js/rivet_dialog.js`, loaded by every layout).

```js
await RivetDialog.alert('Saved.');                                       // resolves true
if (!(await RivetDialog.confirm('Delete it?', { variant: 'danger', okText: 'Delete' }))) return;   // true / false
const pw = await RivetDialog.prompt('Password?', { type: 'password', required: true });          // string / null
```

Options: `title`, `okText`, `cancelText`, `variant` (`primary`, `danger`, `warning`), and for prompts `defaultValue`, `placeholder`, `type` (`text` or `password`), `required`.
The message and title are set with `textContent`; markup is shown literally, so server or user text can be passed without escaping. `\n` makes a line break.

Event handlers that used to confirm synchronously:

```js
form.addEventListener('submit', function (e) {
    if (!RivetDialog.gate(e, form, 'Send it now?', { okText: 'Send' })) { return; }   // false = cancelled + dialog open; Yes re-submits once
});
```

Markup needs no JavaScript: `<form data-confirm-submit="Message">`, `<button data-rivet-confirm="Message">`, `<a class="js-confirm-native" data-confirm-message="Message">`, plus `data-confirm-variant="danger"`, `data-confirm-title`, `data-confirm-ok`. (`.confirm-link` keeps using the page's `#confirmationModal`.)

Behaviour: one dialog at a time (queued), Cancel has the initial focus, Esc / backdrop / X cancel (`false` / `null`), Tab is trapped and the page behind is inert, focus returns to the opener, `role="alertdialog"` with `aria-labelledby` / `aria-describedby`, shown above any open Bootstrap modal, dark theme and phone width follow the Bootstrap modal styles.

A page that sends its own bare HTML (no layout) must load the helper itself: `<script src="/js/rivet_dialog.js"></script>` (see `guest/guest_post.php`).

## Enforcement

* `php tests/no_native_dialogs.php` fails on any native dialog call in first-party code. The only exceptions are listed in `tests/no_native_dialogs.allow`: `beforeunload` (the browser's own "Leave site?" prompt, which a page cannot replace).
* `node tests/browser/no_native_dialogs_smoke.mjs` (see `tests/browser/README.md` for the scratch install) makes the three functions throw, fails on any CDP dialog event and checks the helper's behaviour in real Chrome.
