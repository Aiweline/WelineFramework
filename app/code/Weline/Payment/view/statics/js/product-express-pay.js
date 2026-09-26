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

  /**
   * Open the PayPal/express window synchronously on the user click.
   * Browsers block window.open after await (add-to-cart / startExpressCheckout),
   * so the blank shell must be created before any async work.
   */
  function openBlankProviderWindow() {
    var popup = null;
    try {
      popup = global.open(
        'about:blank',
        'weline_express_pay',
        'width=520,height=720,scrollbars=yes,resizable=yes'
      );
    } catch (e) {
      popup = null;
    }
    if (!popup || popup.closed) {
      return null;
    }
    try {
      popup.document.open();
      popup.document.write(
        '<!doctype html><html><head><meta charset="utf-8">'
        + '<title>PayPal</title>'
        + '<style>html,body{margin:0;height:100%;font:15px/1.45 system-ui,sans-serif;'
        + 'background:#f7f7f7;color:#111}'
        + '.wrap{min-height:100%;display:flex;align-items:center;justify-content:center;'
        + 'padding:24px;text-align:center}'
        + '.card{max-width:280px}'
        + '.spin{width:28px;height:28px;margin:0 auto 12px;border:2px solid #ccc;'
        + 'border-top-color:#003087;border-radius:50%;animation:s .8s linear infinite}'
        + '@keyframes s{to{transform:rotate(360deg)}}</style></head><body>'
        + '<div class="wrap"><div class="card"><div class="spin" aria-hidden="true"></div>'
        + '<p>Connecting to PayPal…</p></div></div></body></html>'
      );
      popup.document.close();
    } catch (e2) {}
    try {
      popup.focus();
    } catch (e3) {}
    return popup;
  }

  function closeProviderWindow(popup) {
    if (!popup) {
      return;
    }
    try {
      if (!popup.closed) {
        popup.close();
      }
    } catch (e) {}
  }

  function navigateProviderWindow(popup, url) {
    var href = text(url);
    if (!href) {
      return false;
    }
    if (popup && !popup.closed) {
      try {
        popup.location.href = href;
        try {
          popup.focus();
        } catch (eFocus) {}
        return true;
      } catch (eNav) {
        closeProviderWindow(popup);
      }
    }
    // Popup blocked or navigable: fall back to same-tab redirect.
    global.location.assign(href);
    return false;
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

  function resolveMethodLabel(button, methodCode) {
    var fromData = text(button && (button.getAttribute('data-method-label') || button.getAttribute('data-method-title')));
    if (fromData) {
      return fromData;
    }
    var aria = text(button && button.getAttribute('aria-label'));
    if (aria) {
      return aria;
    }
    return methodCode ? ('使用 ' + methodCode + ' 快捷支付') : '快捷支付';
  }

  function toastPaymentMethod(message) {
    var msg = text(message);
    if (!msg) {
      return;
    }
    try {
      var toast = global.Weline && global.Weline.UI && global.Weline.UI.toast;
      if (toast && typeof toast.show === 'function') {
        toast.show(msg, { tone: 'info', duration: 2800 });
        return;
      }
      if (toast && typeof toast.info === 'function') {
        toast.info(msg);
        return;
      }
    } catch (e) {}
  }

  async function startExpressFromSection(section, button, popup) {
    var methodCode = text(button.getAttribute('data-method-code')
      || section.getAttribute('data-method-code')
      || 'paypal') || 'paypal';
    var methodLabel = resolveMethodLabel(button, methodCode);
    toastPaymentMethod(methodLabel);
    trackPixel('express_pay', {
      payment_method: methodCode,
      payment_type: methodCode,
      method_label: methodLabel,
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
    if (!offerUuid) {
      throw new Error(text(section.getAttribute('data-offer-missing-message'))
        || '快捷支付缺少商品信息');
    }

    var guestToken = await ensureGuestToken();
    var qtySelect = qs(detailRoot(section), '[data-testid="product-qty"]');
    var qty = qtySelect
      ? Math.max(1, Number(qtySelect.value || 1) || 1)
      : Math.max(1, Number((buyNow && buyNow.dataset.qty) || 1) || 1);

    // PDP 快捷支付只结当前商品：服务端 buy_now 隔离浏览车，禁止先加购再结整车。
    var checkoutApi = await global.Weline.Api.resource('checkout');
    var started = await checkoutApi.startExpressCheckout({
      payment_method: methodCode,
      guest_token: guestToken,
      cart_type: cartType,
      selling_mode: cartType,
      buy_now: true,
      product_express: true,
      provider_code: (buyNow && buyNow.dataset.providerCode) || 'product',
      global_offer_uuid: offerUuid,
      legacy_product_id: productId,
      product_id: productId,
      selection: readEavSelection(section),
      qty: qty,
    }, { silent: true });
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
    // buy_now 服务端已还原浏览车；通知迷你车刷新。
    notifyCartUpdated(started);
    trackPixel('express_pay_started', {
      payment_method: methodCode,
      payment_type: methodCode,
      method_label: methodLabel,
      product_id: productId,
      source: 'product_express_pay',
      trigger: 'express_started',
      transaction_no: text(data.transaction_no || ''),
      transaction_id: text(data.transaction_no || ''),
    }, button);
    navigateProviderWindow(popup, redirectUrl);
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
      // Must open the blank popup in the same synchronous turn as the click.
      // Async add-to-cart / startExpressCheckout lose the user-gesture token
      // and browsers then block window.open (no PayPal express popup).
      var popup = openBlankProviderWindow();
      // stopPropagation 会挡住声明式像素点击；起点/拉起在 startExpressFromSection 内 track
      setBusy(button, true);
      startExpressFromSection(section, button, popup).catch(function (error) {
        closeProviderWindow(popup);
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
        try {
          var toast = global.Weline && global.Weline.UI && global.Weline.UI.toast;
          if (toast && typeof toast.error === 'function') {
            toast.error(message);
          } else if (toast && typeof toast.show === 'function') {
            toast.show(message, { tone: 'danger' });
          }
        } catch (e3) {}
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
