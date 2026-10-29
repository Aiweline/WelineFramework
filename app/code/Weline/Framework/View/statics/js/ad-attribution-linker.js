/**
 * Ad-attribution link propagation: carry inbound ad/channel query params
 * (utm_*, gclid, fbclid, …) onto same-origin navigational URLs.
 *
 * Why: server-rendered internal links (categories, shops, compare, home) carry
 * no query at all. Without this, a paid landing loses its click identifiers the
 * moment the visitor clicks any internal link.
 *
 * Rules:
 * - Only params already present in this page's location.search are propagated.
 * - A link's own value always wins: existing keys are never overwritten.
 * - Same-origin only; assets (/static/, /pub/, /media/, *.css|js|img…) skipped.
 * - Never touches POST form actions.
 * - Opt-out: put data-no-ad-attribution on a link (or any ancestor).
 * - No cookie, no storage, no network: pure URL decoration, cache-safe.
 */
(function (global) {
  'use strict';

  var doc = global.document;
  if (!doc) {
    return;
  }

  // Exact keys used by ad platforms / affiliate channels.
  var EXACT_KEYS = {
    gclid: 1,
    gclsrc: 1,
    dclid: 1,
    gbraid: 1,
    wbraid: 1,
    fbclid: 1,
    msclkid: 1,
    ttclid: 1,
    twclid: 1,
    yclid: 1,
    igshid: 1,
    li_fat_id: 1,
    epik: 1,
    srsltid: 1,
    mc_cid: 1,
    mc_eid: 1
  };
  // Prefixed families (utm_source, utm_medium, mtm_campaign, pk_campaign, …).
  var KEY_PREFIXES = ['utm_', 'mtm_', 'pk_', 'hsa_', 'sc_'];

  var ASSET_RE = /\.(?:css|js|mjs|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|pdf|zip|gz)(?:$|\?)/i;
  var SKIP_SCHEME_RE = /^(?:mailto|tel|javascript|data|blob):/i;
  var NAV_ATTRS = ['href', 'action', 'data-href', 'data-url'];

  function isAdParam(key) {
    var name = String(key || '').trim().toLowerCase();
    if (!name) {
      return false;
    }
    if (EXACT_KEYS[name] === 1) {
      return true;
    }
    for (var i = 0; i < KEY_PREFIXES.length; i++) {
      if (name.indexOf(KEY_PREFIXES[i]) === 0) {
        return true;
      }
    }
    return false;
  }

  /** Snapshot of the inbound ad params on THIS page (empty array = inert). */
  function collectPairs() {
    var pairs = [];
    try {
      var search = String((global.location && global.location.search) || '');
      if (search === '' || search === '?') {
        return pairs;
      }
      var params = new URLSearchParams(search.charAt(0) === '?' ? search.slice(1) : search);
      params.forEach(function (value, key) {
        if (isAdParam(key) && String(value) !== '') {
          pairs.push([key, String(value)]);
        }
      });
    } catch (e) {
      return [];
    }
    return pairs;
  }

  var pairs = collectPairs();
  var booted = false;
  var setAttrBusy = false;

  function shouldStamp(raw, el) {
    var url = String(raw || '').trim();
    if (!url || url.charAt(0) === '#' || SKIP_SCHEME_RE.test(url)) {
      return false;
    }
    if (el && el.hasAttribute && el.hasAttribute('download')) {
      return false;
    }
    if (el && el.closest && el.closest('[data-no-ad-attribution]')) {
      return false;
    }
    return true;
  }

  function decorate(raw, el) {
    if (!shouldStamp(raw, el) || pairs.length === 0) {
      return raw;
    }
    var url = String(raw);
    try {
      var parsed = new URL(url, (global.location && global.location.href) || undefined);
      if (parsed.origin !== global.location.origin) {
        return raw;
      }
      var path = parsed.pathname || '';
      if (
        path.indexOf('/static/') === 0 ||
        path.indexOf('/pub/') === 0 ||
        path.indexOf('/media/') === 0 ||
        ASSET_RE.test(path)
      ) {
        return raw;
      }
      var changed = false;
      var seen = {};
      for (var i = 0; i < pairs.length; i++) {
        var key = pairs[i][0];
        if (seen[key] || parsed.searchParams.has(key)) {
          continue;
        }
        seen[key] = true;
        parsed.searchParams.set(key, pairs[i][1]);
        changed = true;
      }
      if (!changed) {
        return raw;
      }
      if (/^https?:\/\//i.test(url) || url.indexOf('//') === 0) {
        return parsed.toString();
      }
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (e) {
      return raw;
    }
  }

  function stampElement(el, attr) {
    if (!el || !el.getAttribute) {
      return;
    }
    var current = el.getAttribute(attr);
    if (current == null || current === '') {
      return;
    }
    var next = decorate(current, el);
    if (next === current) {
      return;
    }
    setAttrBusy = true;
    try {
      el.setAttribute(attr, next);
    } finally {
      setAttrBusy = false;
    }
  }

  function scan(root) {
    if (pairs.length === 0 || !root || !root.querySelectorAll) {
      return;
    }
    root.querySelectorAll('a[href]').forEach(function (a) {
      stampElement(a, 'href');
    });
    root.querySelectorAll('form[action]').forEach(function (form) {
      var method = String((form.getAttribute && form.getAttribute('method')) || 'get').toLowerCase();
      if (method === 'post') {
        return;
      }
      stampElement(form, 'action');
    });
    root.querySelectorAll('[data-href]').forEach(function (el) {
      stampElement(el, 'data-href');
    });
    root.querySelectorAll('[data-url]').forEach(function (el) {
      stampElement(el, 'data-url');
    });
  }

  function refreshPairs() {
    var next = collectPairs();
    if (next.length !== pairs.length) {
      pairs = next;
      return true;
    }
    for (var i = 0; i < next.length; i++) {
      if (next[i][0] !== pairs[i][0] || next[i][1] !== pairs[i][1]) {
        pairs = next;
        return true;
      }
    }
    return false;
  }

  function onClickCapture(event) {
    var target = event && event.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    refreshPairs();
    var anchor = target.closest('a[href]');
    if (anchor) {
      stampElement(anchor, 'href');
    }
  }

  function hookHistory() {
    var history = global.history;
    if (!history) {
      return;
    }
    ['pushState', 'replaceState'].forEach(function (name) {
      var original = history[name];
      if (typeof original !== 'function' || original.__welineAdAttributionWrapped) {
        return;
      }
      var wrapped = function () {
        var result = original.apply(this, arguments);
        try {
          refreshPairs();
          if (pairs.length > 0) {
            scan(doc);
          }
        } catch (e) {
          // never break navigation
        }
        return result;
      };
      wrapped.__welineAdAttributionWrapped = true;
      history[name] = wrapped;
    });
  }

  function boot() {
    if (booted || pairs.length === 0) {
      return;
    }
    booted = true;
    try {
      global.__WELINE_AD_ATTRIBUTION_LINKER__ = {
        booted: true,
        keys: pairs.map(function (p) { return p[0]; })
      };
    } catch (e) {
      // ignore
    }
    scan(doc);
    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function (mutations) {
        refreshPairs();
        for (var i = 0; i < mutations.length; i++) {
          var mutation = mutations[i];
          if (mutation.type === 'attributes') {
            var node = mutation.target;
            if (node && node.nodeType === 1) {
              stampElement(node, mutation.attributeName);
            }
            continue;
          }
          var added = mutation.addedNodes;
          for (var j = 0; j < added.length; j++) {
            var child = added[j];
            if (child && child.nodeType === 1) {
              scan(child);
            }
          }
        }
      });
      obs.observe(doc.documentElement || doc.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: NAV_ATTRS
      });
    }
    var delays = [0, 50, 250, 1000, 2500];
    for (var d = 0; d < delays.length; d++) {
      (function (ms) {
        global.setTimeout(function () {
          scan(doc);
        }, ms);
      })(delays[d]);
    }
    if (typeof global.requestAnimationFrame === 'function') {
      global.requestAnimationFrame(function () {
        scan(doc);
      });
    }
    global.addEventListener('load', function () { scan(doc); }, { once: true });
  }

  // Click capture + history hooks are cheap and must be ready even when this
  // page arrived without ad params (SPA may add them on a later transition).
  doc.addEventListener('click', onClickCapture, true);
  hookHistory();

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : this);
