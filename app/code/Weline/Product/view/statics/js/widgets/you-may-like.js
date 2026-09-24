(function (global) {
  'use strict';

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

  function cardHtml(card, cardClass) {
    var id = parseInt(card && (card.id || card.product_id), 10) || 0;
    if (id <= 0) {
      return '';
    }
    var url = String((card && card.url) || ('/product/' + id));
    var name = String((card && card.name) || '');
    var image = String((card && (card.image || card.thumbnail)) || '');
    var price = card && card.price != null ? Number(card.price) : null;
    var priceHtml = price != null && !Number.isNaN(price)
      ? '<span class="wym-hydrate-price">' + esc(price.toFixed(2)) + '</span>'
      : '';
    return (
      '<a class="wpc-listing-card ' + cardClass + ' wym-hydrate-card" href="' + esc(url) + '" data-product-id="' + id + '">' +
        '<span class="wym-hydrate-media">' +
          (image ? '<img src="' + esc(image) + '" alt="' + esc(name) + '" loading="lazy">' : '') +
        '</span>' +
        '<span class="wym-hydrate-name">' + esc(name) + '</span>' +
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
    var operation = root.getAttribute('data-hydrate-operation') || 'youMayLikeCards';
    var excludeId = parseInt(root.getAttribute('data-exclude-product-id') || '0', 10) || 0;
    var limit = parseInt(root.getAttribute('data-limit') || '8', 10) || 8;
    var track = root.querySelector('[data-pdp-lazy-track], [data-wym-track]');
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
      track.innerHTML = cards.map(function (card) {
        return cardHtml(card, 'wym-card');
      }).join('');
      track.classList.remove('is-skeleton');
      root.classList.remove('is-deferred');
      root.removeAttribute('aria-busy');
      root.setAttribute('data-testid', 'storefront-you-may-like');
    } catch (error) {
      root.removeAttribute('aria-busy');
      root.classList.add('is-hydrate-failed');
    }
  }

  function initCarousel(root) {
    if (!root || root.getAttribute('data-wym-ready') === '1') {
      return;
    }
    if (root.getAttribute('data-layout') !== 'carousel') {
      root.setAttribute('data-wym-ready', '1');
      return;
    }

    var viewport = root.querySelector('[data-wym-viewport]');
    var track = root.querySelector('[data-wym-track]');
    var prev = root.querySelector('[data-wym-prev]');
    var next = root.querySelector('[data-wym-next]');
    if (!viewport || !track) {
      return;
    }

    function step() {
      var card = track.querySelector('.wym-card');
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
    root.setAttribute('data-wym-ready', '1');
  }

  function boot(scope) {
    var roots = (scope || document).querySelectorAll('.weline-product-you-may-like[data-js-ns]');
    roots.forEach(function (root) {
      hydrate(root).finally(function () {
        initCarousel(root);
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
