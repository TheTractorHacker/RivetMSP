// Agent updates > Build a new version: follows a running build (polls admin/post/agent_build_poll.php every 8 s while one is active) and reloads the card when the release is in
// the list. No inline script; the CSRF token comes from the data attribute.
(function () {
  'use strict';
  var box = document.querySelector('[data-build-box]');
  if (!box || box.getAttribute('data-build-active') !== '1') return;
  var status = box.querySelector('[data-build-status]');
  var busy = false, timer = null;
  function tick() {
    if (busy) return;
    busy = true;
    var body = new URLSearchParams();
    body.set('csrf_token', box.getAttribute('data-csrf') || '');
    fetch(box.getAttribute('data-poll-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (!d || !d.ok || !d.build) return;
        var b = d.build;
        if (status) {
          status.setAttribute('data-build-state', b.state);
          var l = status.querySelector('[data-build-label]'), t = status.querySelector('[data-build-detail]'), a = status.querySelector('[data-build-run]');
          if (l) l.textContent = b.label;
          if (t) t.textContent = b.detail;
          if (a && b.run_url) { a.href = b.run_url; a.textContent = 'Open the run on GitHub'; }
        }
        var done = b.state === 'finished' && b.release_seen && (!status || !status.querySelector('[data-build-auto]') || b.published);
        if (done || b.state === 'failed' || b.state === 'lost') {
          clearInterval(timer);
          if (done) setTimeout(function () { location.reload(); }, 1200);
        }
      })
      .catch(function () { busy = false; });
  }
  timer = setInterval(tick, 8000);
  tick();
})();
