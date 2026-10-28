/**
 * FPC pierce propagation: stamp same-origin navigational URLs with w_fpc_pierce.
 *
 * Tiny on purpose. Two stamp sources:
 * - admin「链接自带签章」: data-pierce-secret on the loader script tag;
 * - self-detect (always injected, secret-free tag): this page's own
 *   location.search — so a pierced visit keeps piercing after clicks even when
 *   the HTML itself came from an FPC/CDN cache.
 * Existing query params are preserved (incremental, never wiped).
 */
(function (global) {
  'use strict';

  var doc = global.document;
  if (!doc) {
    return;
  }

  // defer scripts: document.currentScript is null at run time — fall back to marker.
  var loader = doc.currentScript
    || doc.querySelector('script[data-weline-fpc-dev-auto-pierce]');
  if (!loader || !loader.getAttribute) {
    return;
  }

  var queryName = String(loader.getAttribute('data-pierce-query-name') || 'w_fpc_pierce').trim();
  if (!queryName) {
    return;
  }

  function readSecret() {
    var fromLoader = String(loader.getAttribute('data-pierce-secret') || '').trim();
    if (fromLoader) {
      return fromLoader;
    }
    try {
      var query = new URLSearchParams(String(global.location.search || ''));
      return String(query.get(queryName) || '').trim();
    } catch (e) {
      return '';
    }
  }

  // No stamp on this visit → stay completely inert (no hooks, no timers).
  var secret = readSecret();
  if (secret === '') {
    return;
  }

  var ASSET_RE = /\.(?:css|js|mjs|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|pdf)(?:$|\?)/i;
  var SKIP_SCHEME_RE = /^(?:mailto|tel|javascript|data|blob):/i;

  function shouldStamp(raw) {
    var url = String(raw || '').trim();
    if (!url || url.charAt(0) === '#') {
      return false;
    }
    if (SKIP_SCHEME_RE.test(url)) {
      return false;
    }
    if (url.indexOf(queryName + '=') !== -1) {
      return false;
    }
    return true;
  }

  function stampUrl(raw) {
    if (!shouldStamp(raw)) {
      return raw;
    }
    var url = String(raw);
    try {
      var parsed = new URL(url, global.location.href);
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
      parsed.searchParams.set(queryName, secret);
      if (/^https?:\/\//i.test(url) || url.indexOf('//') === 0) {
        return parsed.toString();
      }
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (e) {
      return raw;
    }
  }

  var setAttrBusy = false;

  function stampAttr(el, attr) {
    if (!el || !el.getAttribute) {
      return;
    }
    var current = el.getAttribute(attr);
    if (current == null || current === '') {
      return;
    }
    var next = stampUrl(current);
    if (next !== current) {
      setAttrBusy = true;
      try {
        el.setAttribute(attr, next);
      } finally {
        setAttrBusy = false;
      }
    }
  }

  function hookUrlProperty(Ctor, propName) {
    if (!Ctor || !Ctor.prototype) {
      return;
    }
    var proto = Ctor.prototype;
    var flag = '__welineFpcPierceProp_' + propName;
    if (proto[flag]) {
      return;
    }
    var desc = Object.getOwnPropertyDescriptor(proto, propName);
    if (!desc || typeof desc.set !== 'function') {
      return;
    }
    Object.defineProperty(proto, propName, {
      configurable: true,
      enumerable: desc.enumerable,
      get: desc.get,
      set: function (value) {
        if (!setAttrBusy) {
          value = stampUrl(value);
        }
        return desc.set.call(this, value);
      },
    });
    proto[flag] = true;
  }

  /**
   * Hook setAttribute / href|action setters so theme healers that rewrite
   * navigational URLs after paint cannot leave them bare when auto-pierce is on.
   */
  function installSetAttributeHook() {
    var proto = global.Element && global.Element.prototype;
    if (proto && typeof proto.setAttribute === 'function' && !proto.__welineFpcPierceHooked) {
      var original = proto.setAttribute;
      proto.setAttribute = function (name, value) {
        if (
          !setAttrBusy &&
          (name === 'href' || name === 'action' || name === 'data-href' || name === 'data-url')
        ) {
          value = stampUrl(value);
        }
        return original.call(this, name, value);
      };
      proto.__welineFpcPierceHooked = true;
    }
    try {
      hookUrlProperty(global.HTMLAnchorElement, 'href');
      hookUrlProperty(global.HTMLFormElement, 'action');
    } catch (e) {
      // ignore
    }
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }
    root.querySelectorAll('a[href]').forEach(function (a) {
      stampAttr(a, 'href');
    });
    root.querySelectorAll('form[action]').forEach(function (form) {
      stampAttr(form, 'action');
    });
    root.querySelectorAll('[data-href]').forEach(function (el) {
      stampAttr(el, 'data-href');
    });
    root.querySelectorAll('[data-url]').forEach(function (el) {
      stampAttr(el, 'data-url');
    });
  }

  function boot() {
    try {
      global.__WELINE_FPC_DEV_AUTO_PIERCE__ = {
        queryName: queryName,
        secret: secret,
        booted: true,
        stampedAt: Date.now(),
      };
    } catch (e) {
      // ignore
    }
    installSetAttributeHook();
    scan(doc);
    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var mutation = mutations[i];
          // Theme healers (e.g. hanfu-trust-strip) rewrite href via setAttribute
          // after first paint — childList-only observers miss those.
          if (mutation.type === 'attributes') {
            var target = mutation.target;
            if (target && target.nodeType === 1) {
              var attr = mutation.attributeName;
              if (attr === 'href' || attr === 'action' || attr === 'data-href' || attr === 'data-url') {
                stampAttr(target, attr);
              }
            }
            continue;
          }
          var nodes = mutation.addedNodes;
          for (var j = 0; j < nodes.length; j++) {
            var node = nodes[j];
            if (node && node.nodeType === 1) {
              scan(node);
            }
          }
        }
      });
      obs.observe(doc.documentElement || doc.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['href', 'action', 'data-href', 'data-url'],
      });
    }
    doc.addEventListener(
      'click',
      function (event) {
        var target = event.target;
        if (!target || !target.closest) {
          return;
        }
        var anchor = target.closest('a[href]');
        if (anchor) {
          stampAttr(anchor, 'href');
        }
      },
      true
    );
    // Late widgets / Bag hydrate / design-theme healers: re-scan on load + rAF + delayed.
    global.addEventListener(
      'load',
      function () {
        scan(doc);
      },
      { once: true }
    );
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
    // Theme healers may rewrite href on a timer; keep stamps sticky while loader is present.
    var keepAlive = global.setInterval(function () {
      if (!doc.querySelector('script[data-weline-fpc-dev-auto-pierce]')) {
        global.clearInterval(keepAlive);
        return;
      }
      // Self-detect mode follows the address bar (SPA history may add/drop the stamp).
      var current = readSecret();
      if (current !== '') {
        secret = current;
      }
      scan(doc);
    }, 400);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : this);

/* pierce-stamp-v2 */
