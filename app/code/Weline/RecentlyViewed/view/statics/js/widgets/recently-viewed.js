(function (global) {
  'use strict';

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

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function unwrapCards(result) {
    if (Array.isArray(result)) {
      return result;
    }
    if (!result || typeof result !== 'object') {
      return [];
    }
    if (Array.isArray(result.cards)) {
      return result.cards;
    }
    if (Array.isArray(result.data)) {
      return result.data;
    }
    if (result.data && Array.isArray(result.data.cards)) {
      return result.data.cards;
    }
    return [];
  }

  function cardHtml(card) {
    var id = parseInt(card && (card.id || card.product_id), 10) || 0;
    if (id <= 0) {
      return '';
    }
    var url = String((card && card.url) || ('/product/' + id));
    var name = String((card && card.name) || '');
    var image = String((card && (card.image || card.thumbnail)) || '');
    var price = card && card.price != null ? Number(card.price) : null;
    var priceHtml = price != null && !Number.isNaN(price)
      ? '<span class="wrv-hydrate-price">' + esc(price.toFixed(2)) + '</span>'
      : '';
    return (
      '<a class="wpc-listing-card wrv-card wrv-hydrate-card" href="' + esc(url) + '" data-product-id="' + id + '">' +
        '<span class="wrv-hydrate-media">' +
          (image ? '<img src="' + esc(image) + '" alt="' + esc(name) + '" loading="lazy">' : '') +
        '</span>' +
        '<span class="wrv-hydrate-name">' + esc(name) + '</span>' +
        priceHtml +
      '</a>'
    );
  }

  async function waitForApi() {
    if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
      return global.Weline.Api;
    }
    if (global.Weline && typeof global.Weline.load === 'function') {
      await global.Weline.load('api');
    } else if (global.Weline && typeof global.Weline.use === 'function') {
      await global.Weline.use('api');
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      throw new Error('Weline.Api unavailable');
    }
    return global.Weline.Api;
  }

  async function hydrate(root) {
    if (!root || root.getAttribute('data-weline-hydrate') !== '1') {
      return;
    }
    if (root.getAttribute('data-pdp-hydrated') === '1') {
      return;
    }
    root.setAttribute('data-pdp-hydrated', '1');

    var provider = root.getAttribute('data-hydrate-provider') || 'product_storefront';
    var operation = root.getAttribute('data-hydrate-operation') || 'recentlyViewedCards';
    var excludeId = parseInt(root.getAttribute('data-exclude-product-id') || '0', 10) || 0;
    var limit = parseInt(root.getAttribute('data-limit') || '6', 10) || 6;
    var track = root.querySelector('[data-pdp-lazy-track], [data-wrv-track]');
    if (!track) {
      return;
    }

    try {
      var api = await waitForApi();
      var resource = await api.resource(provider);
      if (!resource || typeof resource[operation] !== 'function') {
        throw new Error('hydrate_op_unavailable:' + operation);
      }
      var result = await resource[operation]({
        exclude_product_id: excludeId,
        limit: limit,
      }, { silent: true });
      var cards = unwrapCards(result);
      if (!cards.length) {
        root.classList.add('is-empty');
        root.setAttribute('hidden', '');
        root.setAttribute('aria-hidden', 'true');
        root.removeAttribute('aria-busy');
        return;
      }
      track.innerHTML = cards.map(cardHtml).join('');
      track.classList.remove('is-skeleton');
      root.classList.remove('is-deferred');
      root.removeAttribute('aria-busy');
      root.setAttribute('data-testid', 'storefront-recently-viewed');
    } catch (error) {
      root.removeAttribute('aria-busy');
      root.classList.add('is-hydrate-failed');
    }
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
    roots.forEach(function (root) {
      hydrate(root).finally(function () {
        init(root);
      });
    });
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
})(typeof window !== 'undefined' ? window : this);
