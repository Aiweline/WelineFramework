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

  // Glyph authority is server-side ProductCardRenderer / CurrencySymbol only.
  // Never invent a client currency symbol table here.
  function formatCardPrice(card) {
    return card && card.formatted_price != null
      ? String(card.formatted_price).trim()
      : '';
  }

  function storefrontProductHref(card, id) {
    var fromServer = String((card && card.url) || '').trim();
    if (fromServer) {
      return fromServer;
    }
    var path = 'product/' + id;
    try {
      var build = (global.Weline && global.Weline.Url && typeof global.Weline.Url.frontend === 'function')
        ? global.Weline.Url.frontend
        : (typeof global.frontend_url === 'function' ? global.frontend_url : null);
      if (typeof build === 'function') {
        var resolved = String(build(path) || '').trim();
        if (resolved) {
          return resolved;
        }
      }
    } catch (_e) {
      // fall through
    }
    // Last resort: keep current website mount from the open PDP path.
    var parts = String((global.location && global.location.pathname) || '/').split('/').filter(Boolean);
    var mount = parts.length && parts[0] !== 'product' ? '/' + parts[0] : '';
    return mount + '/' + path;
  }

  function cardHtml(card, cardClass) {
    var id = parseInt(card && (card.id || card.product_id), 10) || 0;
    if (id <= 0) {
      return '';
    }
    var url = storefrontProductHref(card, id);
    var name = String((card && card.name) || '');
    var image = String((card && (card.image || card.thumbnail)) || '');
    var priceLabel = formatCardPrice(card);
    var priceHtml = priceLabel !== ''
      ? '<span class="wym-hydrate-price">' + esc(priceLabel) + '</span>'
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

  function isTransientHydrateError(error) {
    if (!error) {
      return false;
    }
    var status = Number(error.status || error.statusCode || error.code || 0) || 0;
    if (status === 502 || status === 503 || status === 504) {
      return true;
    }
    var msg = String(error.message || error || '');
    return /service_unavailable|502|503|504|Bad Gateway|temporarily unavailable/i.test(msg);
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      global.setTimeout(resolve, ms);
    });
  }

  async function fetchCards(root) {
    var provider = root.getAttribute('data-hydrate-provider') || 'product_storefront';
    var operation = root.getAttribute('data-hydrate-operation') || 'youMayLikeCards';
    var excludeId = parseInt(root.getAttribute('data-exclude-product-id') || '0', 10) || 0;
    var limit = parseInt(root.getAttribute('data-limit') || '8', 10) || 8;
    var api = await waitForApi();
    var resource = await api.resource(provider);
    if (!resource || typeof resource[operation] !== 'function') {
      throw new Error('hydrate_op_unavailable:' + operation);
    }
    var result = await resource[operation]({
      exclude_product_id: excludeId,
      limit: limit,
    }, { silent: true });
    return unwrapCards(result);
  }

  async function hydrate(root) {
    if (!root || root.getAttribute('data-weline-hydrate') !== '1') {
      return;
    }
    if (root.getAttribute('data-pdp-hydrated') === '1') {
      return;
    }
    if (root.getAttribute('data-pdp-hydrating') === '1') {
      return;
    }

    var track = root.querySelector('[data-pdp-lazy-track], [data-wym-track]');
    if (!track) {
      return;
    }

    root.setAttribute('data-pdp-hydrating', '1');
    root.classList.remove('is-hydrate-failed');
    root.setAttribute('aria-busy', 'true');

    var attempt = 0;
    var maxAttempts = 3;
    try {
      while (attempt < maxAttempts) {
        attempt += 1;
        try {
          var cards = await fetchCards(root);
          if (!cards.length) {
            track.innerHTML = '';
            track.classList.remove('is-skeleton');
            root.classList.remove('is-deferred');
            root.classList.add('is-empty');
            root.setAttribute('hidden', '');
            root.setAttribute('aria-hidden', 'true');
            root.setAttribute('data-pdp-hydrated', '1');
            return;
          }
          track.innerHTML = cards.map(function (card) {
            return cardHtml(card, 'wym-card');
          }).join('');
          track.classList.remove('is-skeleton');
          root.classList.remove('is-deferred');
          root.removeAttribute('hidden');
          root.removeAttribute('aria-hidden');
          root.setAttribute('data-testid', 'storefront-you-may-like');
          root.setAttribute('data-pdp-hydrated', '1');
          return;
        } catch (error) {
          if (!isTransientHydrateError(error) || attempt >= maxAttempts) {
            throw error;
          }
          // Scroll-triggered hydrate often races worker capacity (502). Retry.
          await sleep(400 * attempt);
        }
      }
    } catch (error) {
      root.classList.add('is-hydrate-failed');
      // Allow a later scroll/IO or manual retry — do not stick hydrated=1 on failure.
      root.removeAttribute('data-pdp-hydrate-scheduled');
    } finally {
      root.removeAttribute('data-pdp-hydrating');
      root.removeAttribute('aria-busy');
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

  /**
   * Lazy shell stays skeleton until the widget script loads.
   * With data-weline-load visible-gate (default), the script itself only loads
   * near the viewport — hydrate immediately on boot (second IO was redundant
   * and raced). Keep IO only for data-weline-load-when="idle".
   */
  function scheduleHydrate(root) {
    if (!root) {
      return;
    }
    var run = function () {
      if (root.getAttribute('data-pdp-hydrate-scheduled') === '1') {
        return;
      }
      if (root.getAttribute('data-pdp-hydrated') === '1') {
        return;
      }
      root.setAttribute('data-pdp-hydrate-scheduled', '1');
      hydrate(root).finally(function () {
        initCarousel(root);
        if (root.getAttribute('data-pdp-hydrated') === '1') {
          return;
        }
        var waves = parseInt(root.getAttribute('data-pdp-hydrate-waves') || '0', 10) || 0;
        if (waves >= 2) {
          return;
        }
        root.setAttribute('data-pdp-hydrate-waves', String(waves + 1));
        global.setTimeout(function () {
          if (root.getAttribute('data-pdp-hydrated') === '1') {
            return;
          }
          root.removeAttribute('data-pdp-hydrate-scheduled');
          scheduleHydrate(root);
        }, 1600);
      });
    };

    if (root.getAttribute('data-weline-hydrate') !== '1') {
      initCarousel(root);
      return;
    }
    var when = String(root.getAttribute('data-weline-load-when') || 'visible').toLowerCase();
    if (when !== 'idle') {
      run();
      return;
    }
    if (typeof global.IntersectionObserver !== 'function') {
      run();
      return;
    }
    var io = new global.IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i += 1) {
        if (!entries[i].isIntersecting) {
          continue;
        }
        io.disconnect();
        run();
        break;
      }
    }, { root: null, rootMargin: '200px 0px', threshold: 0 });
    io.observe(root);
  }

  function boot(scope) {
    var roots = (scope || document).querySelectorAll('.weline-product-you-may-like[data-js-ns]');
    roots.forEach(scheduleHydrate);
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
