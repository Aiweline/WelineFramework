/**
 * PDP 快捷智能支付：加购后 startExpressCheckout，打开支付商窗体（不跳完整结账页）。
 */
(function (global) {
  'use strict';

  var GUEST_TOKEN_STORAGE_KEY = 'weline.cart.guest_token';

  function qs(root, sel) {
    return root && root.querySelector ? root.querySelector(sel) : null;
  }

  function text(value) {
    return String(value == null ? '' : value).trim();
  }

  function detailRoot(section) {
    return (section && section.closest && section.closest('.product-native-detail')) || document;
  }

  function findBuyNow(section) {
    var root = detailRoot(section);
    return qs(root, '[data-action="buy-now"][data-product-id]')
      || qs(root, '[data-testid="product-buy-now"]')
      || qs(document, '[data-action="buy-now"]');
  }

  function readEavSelection(section) {
    var root = detailRoot(section);
    var selection = {};
    root.querySelectorAll('[data-variant-option].is-selected').forEach(function (option) {
      var axisCode = text(option.dataset.variantAxis || '');
      var axisValue = text(option.dataset.variantValue || '');
      if (axisCode && axisValue) {
        selection[axisCode] = axisValue;
      }
    });
    return selection;
  }

  function resolveCartType(button) {
    var preferred = '';
    if (global.WelineB2BSellingMode && typeof global.WelineB2BSellingMode.preferredMode === 'function') {
      preferred = text(global.WelineB2BSellingMode.preferredMode() || '').toLowerCase();
    }
    var fromButton = text((button && (button.dataset.sellingMode || button.dataset.cartType)) || '').toLowerCase();
    var mode = preferred || fromButton || 'toc';
    return mode === 'tob' ? 'tob' : 'toc';
  }

  async function ensureGuestToken() {
    if (global.WelineCart && typeof global.WelineCart.getGuestSession === 'function') {
      var existing = global.WelineCart.getGuestSession();
      if (existing && existing.token) {
        return text(existing.token);
      }
    }
    try {
      var cached = text(global.sessionStorage.getItem(GUEST_TOKEN_STORAGE_KEY) || '');
      if (cached) {
        return cached;
      }
    } catch (e) {}
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      return '';
    }
    var cart = await global.Weline.Api.resource('cart');
    var result = await cart.issueGuestToken({}, { silent: true });
    var payload = result && result.data && typeof result.data === 'object' ? result.data : result;
    var issued = text((payload && payload.guest_token) || (result && result.guest_token) || '');
    if (issued) {
      try {
        global.sessionStorage.setItem(GUEST_TOKEN_STORAGE_KEY, issued);
      } catch (e2) {}
      if (global.WelineCart && typeof global.WelineCart.rememberGuestSession === 'function') {
        global.WelineCart.rememberGuestSession(issued);
      }
    }
    return issued;
  }

  function trackPixel(name, payload, element) {
    try {
      if (global.WelinePixel && typeof global.WelinePixel.track === 'function') {
        global.WelinePixel.track(name, payload || {}, {
          element: element || null,
          keepalive: true,
        });
      }
    } catch (e) {}
  }

  function openProviderWindow(url) {
    var href = text(url);
    if (!href) {
      return false;
    }
    var popup = null;
    try {
      popup = global.open(href, 'weline_express_pay', 'width=520,height=720,scrollbars=yes,resizable=yes');
    } catch (e) {
      popup = null;
    }
    if (!popup || popup.closed) {
      global.location.assign(href);
      return false;
    }
    try {
      popup.focus();
    } catch (e2) {}
    return true;
  }

  function notifyCartUpdated(result) {
    try {
      global.dispatchEvent(new CustomEvent('weline:cart-updated', {
        detail: { source: 'product-express-pay', result: result || null },
      }));
    } catch (e) {}
  }

  function setBusy(button, busy) {
    if (!button) {
      return;
    }
    button.disabled = !!busy;
    if (busy) {
      button.setAttribute('aria-busy', 'true');
    } else {
      button.removeAttribute('aria-busy');
    }
  }

  function trackPixel(name, payload, el) {
    try {
      if (global.WelinePixel && typeof global.WelinePixel.track === 'function') {
        global.WelinePixel.track(name, payload || {}, { element: el || null, keepalive: true });
      }
    } catch (e) {}
  }

  async function startExpressFromSection(section, button) {
    var methodCode = text(button.getAttribute('data-method-code')
      || section.getAttribute('data-method-code')
      || 'paypal') || 'paypal';
    trackPixel('express_pay', {
      payment_method: methodCode,
      product_id: Number(section.getAttribute('data-product-id') || 0) || 0,
      selected_options: readEavSelection(section),
      source: 'product_express_pay',
      trigger: 'click',
    }, button);
    var buyNow = findBuyNow(section);
    var productId = Number(
      (buyNow && buyNow.dataset.productId)
      || section.getAttribute('data-product-id')
      || 0
    ) || 0;
    var offerUuid = text(
      (buyNow && buyNow.dataset.globalOfferUuid)
      || section.getAttribute('data-global-offer-uuid')
      || ''
    );
    var cartType = resolveCartType(buyNow || button);
    if (cartType === 'tob') {
      throw new Error(text(section.getAttribute('data-tob-fallback-message'))
        || '批发请使用完整结账');
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      throw new Error('api_missing');
    }

    var guestToken = await ensureGuestToken();
    var cartApi = await global.Weline.Api.resource('cart');
    var qtySelect = qs(detailRoot(section), '[data-testid="product-qty"]');
    var qty = qtySelect
      ? Math.max(1, Number(qtySelect.value || 1) || 1)
      : Math.max(1, Number((buyNow && buyNow.dataset.qty) || 1) || 1);

    var addResult = await cartApi.add({
      provider_code: (buyNow && buyNow.dataset.providerCode) || 'product',
      global_offer_uuid: offerUuid,
      legacy_product_id: productId,
      selection: readEavSelection(section),
      guest_token: guestToken,
      qty: qty,
      selling_mode: cartType,
      cart_type: cartType,
    }, { silent: true });
    if (!addResult || addResult.success === false) {
      throw new Error((addResult && addResult.message) || 'add_failed');
    }
    notifyCartUpdated(addResult);

    var checkoutApi = await global.Weline.Api.resource('checkout');
    var started = await checkoutApi.startExpressCheckout({
      payment_method: methodCode,
      guest_token: guestToken,
      cart_type: cartType,
      selling_mode: cartType,
    });
    if (!started || started.success === false) {
      throw new Error((started && started.message) || 'express_start_failed');
    }
    var data = started.data && typeof started.data === 'object' ? started.data : started;
    var redirectUrl = text(
      data.redirect_url
      || data.approve_url
      || (data.payment && data.payment.redirect_url)
      || ''
    );
    if (!redirectUrl) {
      throw new Error((started && started.message) || 'express_redirect_missing');
    }
    trackPixel('express_pay_started', {
      payment_method: methodCode,
      product_id: productId,
      source: 'product_express_pay',
      trigger: 'express_started',
    }, button);
    openProviderWindow(redirectUrl);
    return started;
  }

  function bindSection(section) {
    if (!section || section.dataset.productExpressBound === '1') {
      return;
    }
    section.dataset.productExpressBound = '1';
    section.addEventListener('click', function (event) {
      var button = event.target && event.target.closest
        ? event.target.closest('[data-product-express-pay],[data-express-pay]')
        : null;
      if (!button || button.disabled) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      // stopPropagation 会挡住声明式像素点击；起点/拉起在 startExpressFromSection 内 track
      setBusy(button, true);
      startExpressFromSection(section, button).catch(function (error) {
        var message = text(error && error.message) || 'express_failed';
        try {
          if (global.console && typeof global.console.warn === 'function') {
            global.console.warn('[WelineProductExpressPay]', message);
          }
        } catch (e) {}
        if (section && typeof section.dispatchEvent === 'function') {
          section.dispatchEvent(new CustomEvent('weline:product-express:error', {
            bubbles: true,
            detail: { message: message },
          }));
        }
        window.alert(message);
      }).finally(function () {
        setBusy(button, false);
      });
    });
  }

  function boot() {
    document.querySelectorAll('[data-product-express][data-testid="product-express-payment"]').forEach(bindSection);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.WelineProductExpressPay = { boot: boot };
})(typeof window !== 'undefined' ? window : this);
