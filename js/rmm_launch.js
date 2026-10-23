/*
 * Opening a remote session (MeshCentral) in a new tab.
 *
 * A browser only lets a page open a tab inside the user's click: a window.open() that runs after an asynchronous request (the launch call that mints the login
 * token) is treated as a pop-up and may be blocked, and the session then never opens. So the click handler calls begin() FIRST, which opens a blank tab
 * synchronously, asks the server for the launch, and then calls go(url) to send that tab to the session. A failed launch calls close(); a refused tab (go()
 * returns false) is answered with a visible "Open remote session" link the person can click, which is a user gesture of its own.
 *
 * Every call opens a NEW tab (target _blank): a tab from an earlier launch is never reused, because its login token is single use and short lived.
 * The token is only ever in the URL handed to go(); it is not stored, logged or put in the page.
 */
(function () {
    'use strict';

    // The session URL comes from the server. Only an http(s) address is followed: the blank tab shares this page's origin, so a
    // javascript: or data: URL sent there would run as this app.
    function safeUrl(url) {
        try {
            var u = new URL(String(url), window.location.href);
            return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
        } catch (e) {
            return null;
        }
    }

    function begin() {
        var w = null;
        try { w = window.open('about:blank', '_blank'); } catch (e) { w = null; }
        if (w) {
            try { w.opener = null; } catch (e) { /* not fatal */ }
            try { w.document.title = 'Opening remote session'; w.document.body.textContent = 'Opening remote session…'; } catch (e) { /* a blank tab is fine */ }
        }
        return {
            // true when the tab existed (not blocked), is still open, and was sent to the session
            go: function (url) {
                if (!w || w.closed) { return false; }
                var safe = safeUrl(url);
                if (!safe) { try { w.close(); } catch (e) { /* nothing to do */ } return false; }
                try { w.opener = null; } catch (e) { /* not fatal */ }
                try { w.location.replace(safe); } catch (e) { w.location = safe; }
                return true;
            },
            // drop the blank tab after a failed launch
            close: function () {
                try { if (w && !w.closed) { w.close(); } } catch (e) { /* nothing to do */ }
            },
            blocked: !w
        };
    }

    // The fallback when the browser refused the tab: a plain link, opened by the person's own click.
    function link(url, label) {
        var safe = safeUrl(url);
        if (!safe) {
            var s = document.createElement('span');
            s.textContent = 'The remote session address was not valid.';
            return s;
        }
        var a = document.createElement('a');
        a.href = safe; a.target = '_blank'; a.rel = 'noopener noreferrer';
        a.className = 'rmm-open-session-link';
        a.textContent = label || 'Open remote session';
        return a;
    }

    window.RivetRmmLaunch = { begin: begin, link: link };
})();
