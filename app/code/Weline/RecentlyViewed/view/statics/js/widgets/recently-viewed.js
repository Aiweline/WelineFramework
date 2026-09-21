(function () {
  var COOKIE_BASE = 'weline_recently_viewed';
  var MAX_ITEMS = 24;
  var MAX_AGE = 60 * 60 * 24 * 180;

  function cookiePairs() {
    return String(document.cookie || '').split(';').map(function (part) {
      var idx = part.indexOf('=');
      if (idx < 0) {
        return { name: part.trim(), value: '' };
      }
      return {
        name: part.slice(0, idx).trim(),
        value: part.slice(idx + 1).trim(),
      };
    });
  }

  function resolveCookieName() {
    var pairs = cookiePairs();
    var i;
    for (i = 0; i < pairs.length; i += 1) {
      if (pairs[i].name === COOKIE_BASE || pairs[i].name.indexOf(COOKIE_BASE + '_') === 0) {
        if (pairs[i].value) {
          return pairs[i].name;
        }
      }
    }
    return COOKIE_BASE;
  }

  function readIds(name) {
    var pairs = cookiePairs();
    var raw = '';
    var i;
    for (i = 0; i < pairs.length; i += 1) {
      if (pairs[i].name === name) {
        raw = pairs[i].value;
        break;
      }
    }
    if (!raw) {
      return [];
    }
    try {
      var decoded = JSON.parse(decodeURIComponent(raw));
      if (!Array.isArray(decoded)) {
        return [];
      }
      return decoded
        .map(function (id) { return parseInt(id, 10) || 0; })
        .filter(function (id) { return id > 0; });
    } catch (e) {
      return [];
    }
  }

  function writeIds(name, ids) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name
      + '='
      + encodeURIComponent(JSON.stringify(ids))
      + '; Path=/'
      + '; Max-Age='
      + MAX_AGE
      + '; SameSite=Lax'
      + secure;
  }

  function recordProductId(productId) {
    productId = parseInt(productId, 10) || 0;
    if (productId <= 0) {
      return;
    }
    var name = resolveCookieName();
    var ids = readIds(name).filter(function (id) { return id !== productId; });
    ids.unshift(productId);
    if (ids.length > MAX_ITEMS) {
      ids = ids.slice(0, MAX_ITEMS);
    }
    writeIds(name, ids);
  }

  function recordFromDocument(scope) {
    var root = (scope || document).querySelector(
      '.product-native-detail--amazon[data-product-id], [data-wrv-record-product-id]'
    );
    if (!root) {
      return;
    }
    var productId = root.getAttribute('data-product-id')
      || root.getAttribute('data-wrv-record-product-id')
      || '0';
    recordProductId(productId);
  }

  function init(root) {
    if (!root || root.getAttribute('data-wrv-ready') === '1') {
      return;
    }
    if (root.getAttribute('data-layout') !== 'carousel') {
      root.setAttribute('data-wrv-ready', '1');
      return;
    }

    var viewport = root.querySelector('[data-wrv-viewport]');
    var track = root.querySelector('[data-wrv-track]');
    var prev = root.querySelector('[data-wrv-prev]');
    var next = root.querySelector('[data-wrv-next]');
    if (!viewport || !track) {
      return;
    }

    function step() {
      var card = track.querySelector('.wrv-card');
      if (!card) {
        return Math.max(200, viewport.clientWidth * 0.8);
      }
      var styles = window.getComputedStyle(track);
      var gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
      return card.getBoundingClientRect().width + gap;
    }

    function sync() {
      var max = Math.max(0, track.scrollWidth - viewport.clientWidth - 2);
      if (prev) {
        prev.disabled = track.scrollLeft <= 2;
      }
      if (next) {
        next.disabled = track.scrollLeft >= max;
      }
    }

    if (prev) {
      prev.addEventListener('click', function () {
        track.scrollBy({ left: -step(), behavior: 'smooth' });
      });
    }
    if (next) {
      next.addEventListener('click', function () {
        track.scrollBy({ left: step(), behavior: 'smooth' });
      });
    }
    track.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
    root.setAttribute('data-wrv-ready', '1');
  }

  function boot(scope) {
    recordFromDocument(scope || document);
    var roots = (scope || document).querySelectorAll('.weline-recently-viewed[data-js-ns]');
    roots.forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      boot(document);
    });
  } else {
    boot(document);
  }

  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})();
