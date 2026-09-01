(function (global) {
  'use strict';

  var GUEST_TOKEN_STORAGE_KEY = 'weline.cart.guest_token';
  var guestTokenPromise = null;

  function money(n) {
    return '¥' + (Math.round(n * 100) / 100).toFixed(2);
  }

  function bindImageFallback(root) {
    root.querySelectorAll('.wpf-media img[data-fallback]').forEach(function (img) {
      var fallback = img.getAttribute('data-fallback') || '';
      if (!fallback) {
        return;
      }
      function useFallback() {
        if (img.getAttribute('data-failed') === '1') {
          return;
        }
        img.setAttribute('data-failed', '1');
        img.removeAttribute('srcset');
        img.src = fallback;
      }
      img.addEventListener('error', useFallback);
      if (img.complete && img.naturalWidth === 0) {
        useFallback();
      }
    });
  }

  function unwrapCartPayload(result) {
    if (!result || typeof result !== 'object') {
      return null;
    }
    var nested = result.data && typeof result.data === 'object' ? result.data : null;
    if (nested) {
      return Object.assign({}, nested, result);
    }
    return result;
  }

  function normalizeCartSummary(summary) {
    if (!summary || typeof summary !== 'object') {
      return null;
    }
    var normalized = Object.assign({}, summary);
    if (normalized.subtotal_minor != null) {
      normalized.subtotal = Number(normalized.subtotal_minor) / 100;
    }
    if (normalized.grand_total_minor != null) {
      normalized.grand_total = Number(normalized.grand_total_minor) / 100;
    }
    if (Array.isArray(normalized.items)) {
      normalized.items = normalized.items.map(function (item) {
        if (!item || typeof item !== 'object' || item.price != null || item.unit_price_minor == null) {
          return item;
        }
        return Object.assign({}, item, {
          price: Number(item.unit_price_minor) / 100,
        });
      });
    }
    return normalized;
  }

  function notifyCartUpdated(result) {
    var summary = normalizeCartSummary(unwrapCartPayload(result));
    var count = summary
      ? Number(summary.cart_count != null ? summary.cart_count : summary.item_count)
      : null;

    global.dispatchEvent(new CustomEvent('weline:cart-updated', {
      detail: summary || {},
    }));

    var detail = {
      count: count != null && !Number.isNaN(count) ? count : undefined,
      cart_count: count != null && !Number.isNaN(count) ? count : undefined,
      summary: summary || {},
    };
    global.dispatchEvent(new CustomEvent('weline:cart:update', { detail: detail }));
    global.dispatchEvent(new CustomEvent('weshop:cart:updated', { detail: detail }));
  }

  async function waitForCartApi() {
    if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
      return global.Weline.Api.resource('cart');
    }
    if (global.Weline && typeof global.Weline.use === 'function') {
      await global.Weline.use('api');
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      throw new Error('Weline.Api unavailable');
    }
    return global.Weline.Api.resource('cart');
  }

  async function ensureGuestToken() {
    var guestToken = '';
    try {
      guestToken = String(global.sessionStorage.getItem(GUEST_TOKEN_STORAGE_KEY) || '').trim();
    } catch (error) {
      // Storage can be unavailable in privacy-restricted browser contexts.
    }
    if (guestToken) {
      return guestToken;
    }

    if (!guestTokenPromise) {
      guestTokenPromise = (async function () {
        var result = await (await waitForCartApi()).issueGuestToken({}, { silent: true });
        var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
        var issued = String((payload && payload.guest_token) || (result && result.guest_token) || '').trim();
        if (!issued) {
          throw new Error('guest_token_unavailable');
        }
        try {
          global.sessionStorage.setItem(GUEST_TOKEN_STORAGE_KEY, issued);
        } catch (error) {
          // The active mutation can still use this request-owned token.
        }
        return issued;
      })().finally(function () {
        guestTokenPromise = null;
      });
    }

    return guestTokenPromise;
  }

  async function addSelectedItems(selected) {
    var api = await waitForCartApi();
    var guestToken = await ensureGuestToken();
    var lastResult = null;

    for (var i = 0; i < selected.length; i += 1) {
      var item = selected[i];
      var result = await api.addV2({
        provider_code: 'product',
        global_offer_uuid: item.offerUuid,
        legacy_product_id: Number(item.productId || 0),
        guest_token: guestToken,
        qty: Math.max(1, Number(item.qty || 1) || 1),
      }, { silent: true });
      if (!result || result.success === false) {
        throw new Error(result && result.message ? result.message : 'add_failed');
      }
      lastResult = result;
    }

    return lastResult;
  }

  function init(root) {
    if (!root || root.getAttribute('data-wpf-ready') === '1') {
      return;
    }

    var checkboxes = root.querySelectorAll('.wpf-checkbox');
    var totalEl = root.querySelector('[data-wpf-total]');
    var addAll = root.querySelector('[data-wpf-add-all]');
    var status = root.querySelector('[data-wpf-status]');
    var msgSelect = root.getAttribute('data-msg-select-one') || '';
    var msgAdded = root.getAttribute('data-msg-added') || '';
    var msgSuccess = root.getAttribute('data-msg-success') || msgAdded;
    var msgAddAll = root.getAttribute('data-msg-add-all') || '';
    var msgLoading = root.getAttribute('data-msg-loading') || '';
    var msgError = root.getAttribute('data-msg-error') || '';
    var cartUrl = root.getAttribute('data-cart-url') || '/cart';

    function notice(message, type) {
      if (global.Weline && global.Weline.UI && global.Weline.UI.toast && typeof global.Weline.UI.toast.show === 'function') {
        global.Weline.UI.toast.show(message, { tone: type || 'info' });
        return;
      }
      if (!status) {
        return;
      }
      status.textContent = message;
      status.className = 'wpf-status is-' + (type || 'info');
    }

    function showCartAddedSuccess(result) {
      var summary = normalizeCartSummary(unwrapCartPayload(result));
      var title = String(msgSuccess || msgAdded || '').trim();
      if (title !== ''
          && summary
          && global.WelineCartPurchaseActions
          && typeof global.WelineCartPurchaseActions.showCartAddedNotice === 'function') {
        global.WelineCartPurchaseActions.showCartAddedNotice(title, summary, { url: cartUrl });
        return;
      }
      notice(title || msgAdded, 'success');
    }

    function updateTotal() {
      if (!totalEl) {
        return;
      }
      var total = 0;
      checkboxes.forEach(function (cb) {
        if (cb.checked) {
          total += parseFloat(cb.getAttribute('data-price') || '0') || 0;
        }
      });
      totalEl.textContent = money(total);
    }

    bindImageFallback(root);

    checkboxes.forEach(function (cb) {
      cb.addEventListener('change', updateTotal);
    });
    updateTotal();

    if (addAll) {
      addAll.addEventListener('click', async function () {
        if (addAll.disabled || addAll.getAttribute('data-state') === 'loading') {
          return;
        }

        var selected = [];
        checkboxes.forEach(function (cb) {
          if (!cb.checked) {
            return;
          }
          var item = cb.closest('.wpf-item');
          if (!item) {
            return;
          }
          var offerUuid = String(item.getAttribute('data-global-offer-uuid') || '').trim();
          var productId = String(item.getAttribute('data-product-id') || '').trim();
          if (offerUuid === '' && productId === '') {
            return;
          }
          selected.push({
            productId: productId,
            offerUuid: offerUuid,
            qty: Math.max(1, Number(item.getAttribute('data-qty') || 1) || 1),
          });
        });

        if (selected.length === 0) {
          notice(msgSelect, 'warning');
          return;
        }

        var label = addAll.querySelector('[data-wpf-cta-label]');
        addAll.disabled = true;
        addAll.setAttribute('data-state', 'loading');
        if (label && msgLoading) {
          label.textContent = msgLoading;
        }

        try {
          var result = await addSelectedItems(selected);
          notifyCartUpdated(result);
          showCartAddedSuccess(result);
          addAll.setAttribute('data-state', 'saved');
          if (label) {
            label.textContent = msgAdded;
          }
          global.setTimeout(function () {
            addAll.setAttribute('data-state', 'idle');
            addAll.disabled = false;
            if (label) {
              label.textContent = msgAddAll;
            }
          }, 1800);
        } catch (error) {
          addAll.setAttribute('data-state', 'idle');
          addAll.disabled = false;
          if (label) {
            label.textContent = msgAddAll;
          }
          var errorText = error && error.message && error.message !== 'add_failed'
            ? error.message
            : msgError;
          notice(errorText, 'error');
        }
      });
    }

    root.setAttribute('data-wpf-ready', '1');
  }

  function boot(scope) {
    (scope || document).querySelectorAll('.weline-product-fbt[data-js-ns]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})(window);
