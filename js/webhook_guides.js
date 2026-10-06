/*
 * Webhook guides hub (admin/webhook_guides.php only). Progressive enhancement: without this file the page is one long, fully
 * readable document. With it: one guide at a time (deep links like #n8n, hash updates), live search and category chips, a sidebar
 * that becomes a dropdown on phones, language tabs with arrow keys, code highlighting, copy and wrap buttons, step tick boxes
 * remembered per platform in localStorage, and a scroll-spy for the "On this page" list. Everything the server rendered is escaped;
 * this script only ever uses textContent / DOM nodes, never markup built from page data.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-wg]');
  if (!root) return;
  root.classList.add('wg-js');

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function store(op, key, val) {
    try { return op === 'get' ? window.localStorage.getItem(key) : window.localStorage.setItem(key, val); } catch (e) { return null; }
  }
  var live = document.createElement('div');
  live.className = 'visually-hidden'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
  root.appendChild(live);
  function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); }

  // ------------------------------------------------------------------ syntax highlighting (tiny tokenizer, no library)
  var JS_KW = 'const|let|var|function|return|if|else|throw|new|await|async|for|while|true|false|null|undefined|typeof|try|catch|of|in|this';
  var PY_KW = 'def|return|if|elif|else|not|import|from|in|and|or|True|False|None|class|with|as|for|while|try|except|raise|lambda|pass|is';
  var PHP_KW = 'function|return|if|else|elseif|true|false|null|new|foreach|as|echo|use|namespace|class|public|private|static|string|int|bool|array|abs|time|hash_equals|hash_hmac|preg_match|file_get_contents|isset';
  var SH_KW = 'if|then|else|fi|exit|echo|printf|cat|sed|date|openssl|curl|for|do|done|while|case|esac|in|test';
  var DQ = '"(?:\\\\[\\s\\S]|[^"\\\\])*"';
  var SQ = "'(?:\\\\.|[^'\\\\\\n])*'";
  var RULES = {
    json: [['key', DQ + '(?=\\s*:)'], ['str', DQ], ['num', '-?\\b\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?\\b'], ['kw', '\\b(?:true|false|null)\\b']],
    javascript: [['com', '\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/'], ['str', DQ + '|' + SQ + '|`(?:\\\\[\\s\\S]|[^`\\\\])*`'], ['var', '\\$[A-Za-z_][\\w]*'], ['num', '\\b\\d+(?:\\.\\d+)?\\b'], ['kw', '\\b(?:' + JS_KW + ')\\b'], ['fn', '\\b[A-Za-z_][\\w]*(?=\\()']],
    python: [['com', '#[^\\n]*'], ['str', '"""[\\s\\S]*?"""|' + DQ + '|' + SQ], ['num', '\\b\\d+(?:\\.\\d+)?\\b'], ['kw', '\\b(?:' + PY_KW + ')\\b'], ['fn', '\\b[A-Za-z_][\\w]*(?=\\()']],
    php: [['com', '\\/\\/[^\\n]*|#[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/'], ['str', DQ + '|' + SQ], ['var', '\\$[A-Za-z_][\\w]*'], ['num', '\\b\\d+(?:\\.\\d+)?\\b'], ['kw', '\\b(?:' + PHP_KW + ')\\b'], ['fn', '\\b[A-Za-z_][\\w]*(?=\\()']],
    bash: [['com', '(?:^|(?<=\\s))#[^\\n]*'], ['str', DQ + "|'[^']*'"], ['var', '\\$(?:\\{[^}\\n]*\\}|\\([^)\\n]*\\)|[A-Za-z_@#?0-9][\\w]*)'], ['fn', '(?<=\\s)--?[A-Za-z][\\w-]*'], ['num', '\\b\\d+\\b'], ['kw', '\\b(?:' + SH_KW + ')\\b']]
  };
  var compiled = {};
  function compile(lang) {
    if (compiled[lang]) return compiled[lang];
    var rules = RULES[lang];
    if (!rules) return null;
    try {
      compiled[lang] = { names: rules.map(function (r) { return r[0]; }), re: new RegExp(rules.map(function (r) { return '(' + r[1] + ')'; }).join('|'), 'gm') };
    } catch (e) { compiled[lang] = null; }
    return compiled[lang];
  }
  function highlight(code) {
    var lang = code.getAttribute('data-lang') || 'text', c = compile(lang), text = code.textContent;
    if (!c || text.length > 60000) return;
    var frag = document.createDocumentFragment(), last = 0, m;
    c.re.lastIndex = 0;
    while ((m = c.re.exec(text)) !== null) {
      if (m[0] === '') { c.re.lastIndex++; continue; }
      var which = -1;
      for (var i = 1; i < m.length; i++) { if (m[i] !== undefined) { which = i - 1; break; } }
      if (which < 0) continue;
      if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
      var span = document.createElement('span');
      span.className = 'wg-t-' + c.names[which];
      span.textContent = m[0];
      frag.appendChild(span);
      last = m.index + m[0].length;
    }
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    code.textContent = '';
    code.appendChild(frag);
    code.setAttribute('data-hl', '1');
  }
  $$('code[data-lang]', root).forEach(highlight);

  // ------------------------------------------------------------------ copy + wrap
  function legacyCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(ta); ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
    }
    return Promise.resolve(legacyCopy(text));
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-wg-copy]');
    if (btn) {
      var target = $(btn.getAttribute('data-wg-copy'));
      if (!target) return;
      var label = $('span', btn), old = label.textContent;
      copyText(target.textContent).then(function (ok) {
        label.textContent = ok ? 'Copied' : 'Press Ctrl+C';
        btn.classList.toggle('is-done', ok);
        say(ok ? 'Copied to the clipboard' : 'Copy failed, select the text and press Ctrl+C');
        clearTimeout(btn._t);
        btn._t = setTimeout(function () { label.textContent = old; btn.classList.remove('is-done'); }, 1800);
      });
      return;
    }
    var wrap = e.target.closest('[data-wg-wrap]');
    if (wrap) {
      var box = wrap.closest('[data-wg-code]'), on = !box.classList.contains('is-wrapped');
      box.classList.toggle('is-wrapped', on);
      wrap.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  });

  // ------------------------------------------------------------------ language tabs (arrow keys, remembered choice)
  function selectTab(tab, focus) {
    var group = tab.closest('[data-wg-tabs]');
    $$('[role="tab"]', group).forEach(function (t) {
      var on = t === tab;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      var p = document.getElementById(t.getAttribute('aria-controls'));
      if (p) p.hidden = !on;
    });
    if (focus) tab.focus();
  }
  root.addEventListener('click', function (e) {
    var tab = e.target.closest('[role="tab"][data-wg-lang]');
    if (!tab) return;
    var lang = tab.getAttribute('data-wg-lang');
    selectTab(tab, false);
    store('set', 'wg:lang', lang);
    // keep every other tab group on the same language when it offers it
    $$('[data-wg-tabs]', root).forEach(function (g) {
      var same = $('[data-wg-lang="' + lang + '"]', g);
      if (same && g !== tab.closest('[data-wg-tabs]')) selectTab(same, false);
    });
  });
  root.addEventListener('keydown', function (e) {
    var tab = e.target.closest && e.target.closest('[role="tab"]');
    if (!tab) return;
    var tabs = $$('[role="tab"]', tab.closest('[data-wg-tabs]')), i = tabs.indexOf(tab), n = -1;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    if (n < 0) return;
    e.preventDefault();
    selectTab(tabs[n], true);
    tabs[n].click();
  });
  var savedLang = store('get', 'wg:lang');
  if (savedLang) {
    $$('[data-wg-tabs]', root).forEach(function (g) {
      var t = $('[data-wg-lang="' + savedLang + '"]', g);
      if (t) selectTab(t, false);
    });
  }

  // ------------------------------------------------------------------ step tick boxes (per platform, localStorage)
  $$('[data-wg-steps]', root).forEach(function (list) {
    var id = list.getAttribute('data-wg-steps'), key = 'wg:steps:' + id, done = [];
    try { done = JSON.parse(store('get', key) || '[]') || []; } catch (e) { done = []; }
    var section = list.closest('.wg-block'), progress = $('[data-wg-progress]', section), reset = $('[data-wg-reset]', section);
    var boxes = $$('[data-wg-tick]', list);
    function paint() {
      var n = 0;
      boxes.forEach(function (b, i) {
        var on = done.indexOf(i) !== -1;
        b.checked = on;
        b.closest('.wg-step').classList.toggle('is-done', on);
        if (on) n++;
      });
      if (progress) progress.textContent = n ? n + ' of ' + boxes.length + ' steps done' : '';
      if (reset) reset.hidden = n === 0;
    }
    boxes.forEach(function (b, i) {
      b.addEventListener('change', function () {
        done = done.filter(function (x) { return x !== i; });
        if (b.checked) done.push(i);
        store('set', key, JSON.stringify(done));
        paint();
      });
    });
    if (reset) reset.addEventListener('click', function () { done = []; store('set', key, '[]'); paint(); });
    paint();
  });

  // ------------------------------------------------------------------ views, deep links and navigation
  var views = $$('[data-wg-view]', root), links = $$('[data-wg-link]', root);
  var pageIds = {};
  views.forEach(function (v) { if (v.id) pageIds[v.id] = v; });
  var indexView = $('[data-wg-view="index"]', root), side = $('.wg-side', root);
  var toggle = $('[data-wg-side-toggle]', root), toggleLabel = $('[data-wg-side-label]', root);
  var spy = null;

  function setSide(open) {
    if (!side || !toggle) return;
    side.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (toggle) toggle.addEventListener('click', function () { setSide(!side.classList.contains('is-open')); });

  function scrollTo(el, behavior) {
    if (!el) return;
    el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : (behavior || 'auto'), block: 'start' });
  }
  function watchToc(view) {
    if (spy) { spy.disconnect(); spy = null; }
    if (!view || !('IntersectionObserver' in window)) return;
    var tocLinks = $$('[data-wg-jump]', view);
    if (!tocLinks.length) return;
    spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        tocLinks.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-wg-jump') === en.target.id); });
      });
    }, { rootMargin: '-15% 0px -70% 0px' });
    tocLinks.forEach(function (a) { var t = document.getElementById(a.getAttribute('data-wg-jump')); if (t) spy.observe(t); });
  }

  var current = null;
  function route(fromUser) {
    var id = decodeURIComponent((location.hash || '').replace(/^#/, ''));
    var target = id ? document.getElementById(id) : null;
    var view = indexView, scrollTarget = null, name = 'All guides', linkId = '';
    if (target && pageIds[id] && pageIds[id] !== indexView) {
      view = pageIds[id]; linkId = id;
      name = view.getAttribute('data-wg-name') || (id === 'receiving-in-n8n' ? 'Receiving in n8n' : id);
    } else if (target && indexView.contains(target)) {
      scrollTarget = target; linkId = ['signing', 'networks'].indexOf(id) !== -1 ? id : '';
      if (linkId) name = target.querySelector('h4') ? target.querySelector('h4').textContent : name;
    }
    views.forEach(function (v) { v.classList.toggle('is-current', v === view); });
    links.forEach(function (a) {
      var on = a.getAttribute('data-wg-link') === linkId;
      a.classList.toggle('is-current', on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (toggleLabel) toggleLabel.textContent = name;
    setSide(false);
    watchToc(view === indexView ? null : view);
    var changed = view !== current;
    current = view;
    if (scrollTarget) scrollTo(scrollTarget, 'smooth');
    else if (changed && (fromUser || id)) scrollTo(view === indexView ? $('.wg-layout', root) : view);
    if (fromUser && view !== indexView) { var h = $('h4', view); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); } }
  }
  window.addEventListener('hashchange', function () { route(true); });
  route(false);

  // "On this page" jumps scroll inside the open guide without touching the hash
  root.addEventListener('click', function (e) {
    var a = e.target.closest('[data-wg-jump]');
    if (!a) return;
    e.preventDefault();
    var t = document.getElementById(a.getAttribute('data-wg-jump'));
    scrollTo(t, 'smooth');
    if (t) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); }
  });

  // ------------------------------------------------------------------ search + category chips
  var input = $('[data-wg-search]', root), chips = $$('[data-wg-cat]', root), cat = 'all';
  var navLinks = $$('.wg-nav-link[data-wg-hay]', root), tiles = $$('[data-wg-tile]', root), count = $('[data-wg-count]', root), empties = $$('[data-wg-empty]', root);
  function filter() {
    var terms = (input ? input.value : '').toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    function ok(el) {
      var hay = el.getAttribute('data-wg-hay') || '';
      return (cat === 'all' || el.getAttribute('data-wg-cat-of') === cat) && terms.every(function (t) { return hay.indexOf(t) !== -1; });
    }
    navLinks.forEach(function (a) { a.hidden = !ok(a); });
    tiles.forEach(function (t) { var v = ok(t); t.hidden = !v; if (v) shown++; });
    $$('[data-wg-group]', root).forEach(function (g) { g.hidden = !$$('.wg-nav-link:not([hidden])', g).length; });
    var stat = $('[data-wg-static]', root);
    if (stat) stat.hidden = !!terms.length || cat !== 'all';
    if (count) count.textContent = String(shown);
    empties.forEach(function (n) { n.hidden = shown > 0; });
  }
  if (input) {
    input.addEventListener('input', filter);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = ''; filter(); }
      if (e.key === 'Enter') {
        var first = navLinks.filter(function (a) { return !a.hidden; })[0];
        if (first) { e.preventDefault(); location.hash = first.getAttribute('data-wg-link'); }
      }
    });
  }
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      cat = c.getAttribute('data-wg-cat');
      chips.forEach(function (x) { var on = x === c; x.classList.toggle('is-active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      filter();
    });
  });
  filter();

  // ------------------------------------------------------------------ print: expand the collapsed example payloads
  window.addEventListener('beforeprint', function () { $$('details', root).forEach(function (d) { d.setAttribute('open', ''); }); });
})();
