/**
 * PayPal Card Fields（站内银行卡收银台 / Advanced Credit and Debit Card）。
 *
 * 只在结账页、且选中 PayPal 时激活：
 *   1. 读取 Payment 壳下发的公开配置（client id + SDK URL）；
 *   2. 加载 PayPal JS SDK，检测 advanced_cards 资格；
 *   3. 在 PayPal 支付方式下方展开 Card Fields（卡数据始终在 PayPal iframe 内）；
 *   4. Payment 通过结账页通用扩展点注册 handler；主提交由本模块接管：
 *      submitV2(client_action) 建单 -> cardSession.submit(orderId)
 *      -> 结账页注入的 confirmPayment 回调 -> handlePaymentResult 跳转。
 *
 * 任一环节失败都静默回退到原 PayPal 跳转，不阻断结账。
 */
(function (global) {
  'use strict';

  var state = {
    booted: false,
    config: null,
    sdk: null,
    cardSession: null,
    container: null,
    mounted: false,
    initializing: false,
    initPromise: null,
    submitting: false,
    eligible: false,
    pendingOrderId: '',
    idempotencyKey: '',
    observer: null,
    observeTimer: null
  };

  function text(value) {
    return value === null || value === undefined ? '' : String(value);
  }

  function labels() {
    return state.config && state.config.labels && typeof state.config.labels === 'object'
      ? state.config.labels
      : {};
  }

  function label(key, fallback) {
    var value = text(labels()[key]).trim();
    return value !== '' ? value : fallback;
  }

  function applyLabels(container, values) {
    if (!container || !values || typeof values !== 'object') {
      return;
    }
    ['card_number', 'expiry', 'cvv', 'hint'].forEach(function (key) {
      var el = container.querySelector('[data-label="' + key + '"]');
      if (el && text(values[key]).trim() !== '') {
        el.textContent = text(values[key]).trim();
      }
    });
  }

  function selectedPaymentMethod() {
    var el = document.querySelector('input[name="payment_method"]:checked');
    return el ? text(el.value).trim().toLowerCase() : '';
  }

  function isContinuePayMode() {
    try {
      var params = new URLSearchParams(global.location.search || '');
      if (params.get('continue_pay') === '1' || params.get('continue_pay') === 'true') {
        return true;
      }
      if (params.get('recoverable') === '1') {
        return true;
      }
    } catch (e) {
      // ignore
    }
    // 结账页 recovery 区块常驻 DOM（hidden 也在）；只有可见时才算续付模式。
    var recovery = document.querySelector('#payment-recovery, [data-checkout-payment-recovery]');
    if (recovery && !recovery.hidden && recovery.getAttribute('aria-hidden') !== 'true') {
      return true;
    }
    return !!document.querySelector('[data-continue-pay]:not([hidden])');
  }

  function readCheckoutCurrency() {
    try {
      var boot = document.querySelector('[data-checkout-ssr-boot]');
      if (boot) {
        var parsed = JSON.parse(text(boot.textContent).trim() || '{}');
        var currency = text(parsed && parsed.currency).trim().toUpperCase();
        if (/^[A-Z]{3}$/.test(currency)) {
          return currency;
        }
        var cartCurrency = text(parsed && parsed.cart && parsed.cart.currency).trim().toUpperCase();
        if (/^[A-Z]{3}$/.test(cartCurrency)) {
          return cartCurrency;
        }
      }
    } catch (e) {
      // ignore
    }
    var meta = document.querySelector('meta[name="weline-currency"], meta[property="product:price:currency"]');
    var metaCurrency = meta ? text(meta.getAttribute('content')).trim().toUpperCase() : '';
    return /^[A-Z]{3}$/.test(metaCurrency) ? metaCurrency : '';
  }

  function paypalLabel() {
    return document.querySelector('[data-payment-method="paypal"]');
  }

  function ensureStylesheet(url) {
    if (!url || document.querySelector('link[data-weline-paypal-card-fields-css="1"]')) {
      return;
    }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    link.setAttribute('data-weline-paypal-card-fields-css', '1');
    document.head.appendChild(link);
  }

  function ensureContainer() {
    if (state.container && document.body.contains(state.container)) {
      return state.container;
    }
    var label = paypalLabel();
    if (!label) {
      return null;
    }
    var wrapper = document.createElement('div');
    wrapper.className = 'weline-paypal-card-fields';
    wrapper.setAttribute('data-paypal-card-fields', '1');
    wrapper.hidden = true;
    wrapper.innerHTML = ''
      + '<div class="weline-paypal-card-fields__fields">'
      + '  <div class="weline-paypal-card-fields__row">'
      + '    <label class="weline-paypal-card-fields__label" data-label="card_number">卡号</label>'
      + '    <div class="weline-paypal-card-fields__field" data-card-field="number"></div>'
      + '  </div>'
      + '  <div class="weline-paypal-card-fields__row weline-paypal-card-fields__row--split">'
      + '    <div class="weline-paypal-card-fields__col">'
      + '      <label class="weline-paypal-card-fields__label" data-label="expiry">有效期</label>'
      + '      <div class="weline-paypal-card-fields__field" data-card-field="expiry"></div>'
      + '    </div>'
      + '    <div class="weline-paypal-card-fields__col">'
      + '      <label class="weline-paypal-card-fields__label" data-label="cvv">安全码</label>'
      + '      <div class="weline-paypal-card-fields__field" data-card-field="cvv"></div>'
      + '    </div>'
      + '  </div>'
      + '</div>'
      + '<p class="weline-paypal-card-fields__hint" data-label="hint">卡信息由 PayPal 安全托管，本站不接触卡号与安全码。'
      + '若需使用 PayPal 账户余额或钱包，请直接点「提交订单」走 PayPal 跳转。</p>'
      + '<p class="weline-paypal-card-fields__error" data-paypal-card-fields-error hidden></p>';
    label.insertAdjacentElement('afterend', wrapper);
    state.container = wrapper;
    return wrapper;
  }

  function showError(message) {
    var box = state.container || ensureContainer();
    if (!box) {
      return;
    }
    var el = box.querySelector('[data-paypal-card-fields-error]');
    if (!el) {
      return;
    }
    el.textContent = text(message).trim();
    el.hidden = el.textContent === '';
  }

  function clearError() {
    showError('');
  }

  function setVisible(visible) {
    var box = state.container || ensureContainer();
    if (!box) {
      return;
    }
    box.hidden = !visible;
    box.setAttribute('aria-hidden', visible ? 'false' : 'true');
  }

  function fetchConfig() {
    if (state.config) {
      return Promise.resolve(state.config);
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      return Promise.resolve(null);
    }
    // Weline.Api.resource 返回同步 Proxy 客户端（非 Promise），方法返回 Promise。
    var api;
    try {
      api = global.Weline.Api.resource('payment');
    } catch (eCfg) {
      return Promise.resolve(null);
    }
    return Promise.resolve().then(function () {
      if (!api || typeof api.paypalCardFieldsConfig !== 'function') {
        return null;
      }
      return api.paypalCardFieldsConfig({ currency: readCheckoutCurrency() });
    }).then(function (res) {
      if (!res || res.enabled !== true || !text(res.sdk_src).trim() || !text(res.client_id).trim()) {
        return null;
      }
      state.config = res;
      return res;
    }).catch(function () {
      return null;
    });
  }

  function loadPayPalSdk(src) {
    if (global.paypal && typeof global.paypal.createInstance === 'function') {
      return Promise.resolve(global.paypal);
    }
    if (!text(src).trim()) {
      return Promise.reject(new Error('missing_paypal_sdk_src'));
    }
    var existing = document.querySelector('script[data-weline-paypal-card-fields-sdk="1"]');
    if (existing) {
      return new Promise(function (resolve, reject) {
        existing.addEventListener('load', function () { resolve(global.paypal); });
        existing.addEventListener('error', function () { reject(new Error('paypal_sdk_load_failed')); });
      });
    }
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.setAttribute('data-weline-paypal-card-fields-sdk', '1');
      script.onload = function () { resolve(global.paypal); };
      script.onerror = function () { reject(new Error('paypal_sdk_load_failed')); };
      document.head.appendChild(script);
    });
  }

  function mountFields(cardSession, container) {
    var number = cardSession.createCardFieldsComponent({
      type: 'number',
      placeholder: '卡号'
    });
    var expiry = cardSession.createCardFieldsComponent({
      type: 'expiry',
      placeholder: 'MM/YY'
    });
    var cvv = cardSession.createCardFieldsComponent({
      type: 'cvv',
      placeholder: 'CVV'
    });
    container.querySelector('[data-card-field="number"]').appendChild(number);
    container.querySelector('[data-card-field="expiry"]').appendChild(expiry);
    container.querySelector('[data-card-field="cvv"]').appendChild(cvv);
  }

  function init() {
    if (state.mounted) {
      return Promise.resolve(state.eligible);
    }
    if (state.initializing && state.initPromise) {
      return state.initPromise;
    }
    if (isContinuePayMode()) {
      return Promise.resolve(false);
    }
    state.initializing = true;
    state.initPromise = fetchConfig().then(function (config) {
      if (!config) {
        return false;
      }
      ensureStylesheet(text(config.css_url).trim());
      var container = ensureContainer();
      if (!container) {
        return false;
      }
      applyLabels(container, config.labels);
      return loadPayPalSdk(text(config.sdk_src).trim()).then(function (paypal) {
        // 兼容两代 SDK：新式 createInstance（card-fields 组件 + session API）
        // 与经典 CardFields 顶层工厂（components=card-fields 时挂在 window.paypal）。
        if (paypal && typeof paypal.createInstance === 'function') {
          return paypal.createInstance({
            clientId: text(config.client_id).trim(),
            components: ['card-fields'],
            pageType: 'checkout'
          }).then(function (sdk) {
            if (!sdk || typeof sdk.findEligibleMethods !== 'function') {
              return false;
            }
            return sdk.findEligibleMethods({
              currencyCode: text(config.currency).trim() || readCheckoutCurrency() || 'USD'
            }).then(function (paymentMethods) {
              if (!paymentMethods || typeof paymentMethods.isEligible !== 'function'
                || !paymentMethods.isEligible('advanced_cards')) {
                return false;
              }
              if (typeof sdk.createCardFieldsOneTimePaymentSession !== 'function') {
                return false;
              }
              var cardSession = sdk.createCardFieldsOneTimePaymentSession();
              mountFields(cardSession, container);
              state.sdk = sdk;
              state.cardSession = cardSession;
              state.mounted = true;
              state.eligible = true;
              return true;
            });
          });
        }
        if (paypal && typeof paypal.CardFields === 'function') {
          var cfInstance = null;
          try {
            cfInstance = paypal.CardFields({
              style: { input: { 'font-size': '16px' } },
              createOrder: function () {
                if (!state.pendingOrderId) {
                  return Promise.reject(new Error('order_not_created'));
                }
                return Promise.resolve(state.pendingOrderId);
              },
              onError: function (err) {
                showError((err && err.detail && err.detail.message)
                  ? String(err.detail.message)
                  : label('error_card_failed', '银行卡支付失败，请检查卡信息后重试。'));
              },
            });
          } catch (eCf) {
            return false;
          }
          var eligible = false;
          try { eligible = cfInstance.isEligible() === true; } catch (eElig) { eligible = false; }
          if (!eligible) {
            return false;
          }
          state.sdk = paypal;
          state.cardSession = cfInstance;
          state.mounted = true;
          state.eligible = true;
          return true;
        }
        return false;
      });
    }).catch(function () {
      return false;
    }).then(function (ok) {
      state.initializing = false;
      state.initPromise = null;
      state.eligible = ok === true;
      if (!ok) {
        setVisible(false);
      }
      return state.eligible;
    });

    return state.initPromise;
  }

  function isActive(paymentMethod) {
    if (text(paymentMethod).trim().toLowerCase() !== 'paypal') {
      return false;
    }
    if (isContinuePayMode()) {
      return false;
    }
    return state.eligible || state.initializing;
  }

  function readBillingAddress() {
    var form = document.querySelector('form[data-checkout-form], form[name="checkout"], form');
    if (!form) {
      return null;
    }
    var postal = form.querySelector('[name="postal_code"], [name="billing[postal_code]"]');
    var country = form.querySelector('[name="country_code"], [name="billing[country_code]"]');
    var out = {};
    var postalValue = postal ? text(postal.value).trim() : '';
    var countryValue = country ? text(country.value).trim().toUpperCase() : '';
    if (postalValue !== '') {
      out.postalCode = postalValue;
    }
    if (countryValue !== '') {
      out.countryCode = countryValue;
    }
    return Object.keys(out).length > 0 ? out : null;
  }

  function extractResultContext(result) {
    var data = result && result.data && typeof result.data === 'object' ? result.data : (result || {});
    var payment = result && result.payment && typeof result.payment === 'object' ? result.payment : {};
    var orderUuids = Array.isArray(result && result.order_uuids)
      ? result.order_uuids
      : (Array.isArray(data.order_uuids) ? data.order_uuids : []);
    var transactions = Array.isArray(payment.transactions) ? payment.transactions : [];
    var transaction = transactions[0] && typeof transactions[0] === 'object' ? transactions[0] : {};
    var clientAction = payment.client_action && typeof payment.client_action === 'object'
      ? payment.client_action
      : null;
    var actionData = clientAction && clientAction.data && typeof clientAction.data === 'object'
      ? clientAction.data
      : null;
    return {
      payment: payment,
      clientAction: clientAction,
      actionData: actionData,
      orderUuid: text(orderUuids[0] || '').trim(),
      checkoutGroupUuid: text(result && result.checkout_group_uuid || data.checkout_group_uuid || '').trim(),
      quoteToken: text(result && result.checkout_token || '').trim(),
      transactionNo: text(transaction.transaction_no || '').trim()
    };
  }

  function paymentState(ctx, resultContext) {
    return {
      quote_token: resultContext.quoteToken,
      idempotency_key: state.idempotencyKey,
      payment_method: 'paypal',
      country_code: '',
      order_uuid: resultContext.orderUuid,
      checkout_group_uuid: resultContext.checkoutGroupUuid,
      outcome: '',
      redirect_url: '',
      recoverable: true
    };
  }

  function captureWithRetry(confirmPayment, payload, attempt) {
    var tries = typeof attempt === 'number' ? attempt : 0;
    return confirmPayment(payload).catch(function (error) {
      if (tries < 1) {
        return new Promise(function (resolve) {
          setTimeout(resolve, 800);
        }).then(function () {
          return captureWithRetry(confirmPayment, payload, tries + 1);
        });
      }
      throw error;
    }).then(function (captured) {
      if (captured && captured.success !== false) {
        return captured;
      }
      if (tries < 1) {
        return new Promise(function (resolve) {
          setTimeout(resolve, 800);
        }).then(function () {
          return captureWithRetry(confirmPayment, payload, tries + 1);
        });
      }
      return captured;
    });
  }

  function handleSubmit(ctx) {
    if (!isActive(ctx && ctx.paymentMethod)) {
      return Promise.resolve(false);
    }
    var submitCheckoutPayment = ctx && ctx.submitCheckoutPayment;
    var handlePaymentResult = ctx && ctx.handlePaymentResult;
    var confirmPayment = ctx && ctx.confirmPayment;
    if (typeof submitCheckoutPayment !== 'function'
      || typeof handlePaymentResult !== 'function'
      || typeof confirmPayment !== 'function') {
      return Promise.resolve(false);
    }
    if (!state.eligible && state.initializing) {
      return init().then(function (ok) {
        return ok ? handleSubmit(ctx) : false;
      });
    }
    if (!state.eligible) {
      return Promise.resolve(false);
    }
    if (!state.idempotencyKey) {
      state.idempotencyKey = 'checkout_paypal_card_'
        + Math.random().toString(36).slice(2, 10)
        + Date.now().toString(36);
    }
    clearError();
    state.submitting = true;

    return submitCheckoutPayment('paypal', {
      client_action: true,
      defer_result: true,
      require_form_validity: true,
      idempotency_key: state.idempotencyKey,
      idempotency_prefix: 'checkout_paypal_card_'
    }).then(function (result) {
      if (!result || result.success === false) {
        throw new Error(text(result && result.message) || label('error_create_order', '创建支付订单失败，请重试。'));
      }
      if (result.cancelled) {
        return true;
      }
      var rc = extractResultContext(result);
      if (!rc.clientAction || rc.clientAction.type !== 'card_fields'
        || !rc.actionData || !text(rc.actionData.order_id).trim()) {
        // 服务端降级（不符合卡资格/多订单/币种需折算/配置关闭）→ 走原跳转。
        handlePaymentResult(result, paymentState(ctx, rc));
        return true;
      }
      var submitOptions = {};
      var billing = readBillingAddress();
      if (billing) {
        submitOptions.billingAddress = billing;
      }
      // 经典 CardFields：先回填 createOrder 回调用的 orderId，再 submit()（无参数）。
      // 新 session API：submit(orderId, options)。
      var isClassic = state.sdk && typeof state.sdk.createInstance !== 'function';
      var submitCall;
      if (isClassic) {
        state.pendingOrderId = text(rc.actionData.order_id).trim();
        submitCall = Promise.resolve(state.cardSession.submit());
      } else {
        submitCall = state.cardSession.submit(text(rc.actionData.order_id).trim(), submitOptions);
      }
      return submitCall.then(function (submitResult) {
        var data = submitResult && submitResult.data ? submitResult.data : {};
        var submitState = text(submitResult && submitResult.state).trim().toLowerCase();
        if (submitState === '') {
          // 经典 CardFields.submit() 无 state 返回（成功即回调 createOrder+approve）。
          submitState = 'succeeded';
          data = data || {};
        }
        if (submitState === 'canceled') {
          showError(label('error_3ds_canceled', '3D Secure 验证已取消，请重试。'));
          return true;
        }
        if (submitState !== 'succeeded') {
          showError(text(data.message) || label('error_card_failed', '银行卡支付失败，请检查卡信息后重试。'));
          return true;
        }
        return captureWithRetry(confirmPayment, {
          order_uuid: rc.orderUuid,
          checkout_group_uuid: rc.checkoutGroupUuid,
          quote_token: rc.quoteToken,
          transaction_no: rc.transactionNo,
          idempotency_key: state.idempotencyKey,
          provider_reference: text(data.orderId || rc.actionData.order_id).trim()
        }).then(function (captured) {
          if (!captured || captured.success === false) {
            throw new Error(text(captured && captured.message) || label('error_capture_failed', '支付确认失败，请稍后在订单页查看状态。'));
          }
          handlePaymentResult(captured, paymentState(ctx, rc));
          return true;
        });
      });
    }).catch(function (error) {
      showError(error && error.message ? error.message : label('error_generic', '支付失败，请重试。'));
      return true;
    }).finally(function () {
      state.submitting = false;
    });
  }

  function syncVisibility() {
    var selected = selectedPaymentMethod();
    if (isContinuePayMode()) {
      setVisible(false);
      return;
    }
    if (selected !== 'paypal') {
      setVisible(false);
      return;
    }
    if (state.eligible) {
      setVisible(true);
      return;
    }
    if (!state.initializing) {
      init().then(function (ok) {
        setVisible(ok === true && selectedPaymentMethod() === 'paypal');
      });
    }
  }

  function watchPaymentMethods() {
    if (state.observer || typeof MutationObserver !== 'function') {
      return;
    }
    var target = document.querySelector('[data-payment-methods]') || document.body;
    state.observer = new MutationObserver(function () {
      if (state.observeTimer) {
        clearTimeout(state.observeTimer);
      }
      state.observeTimer = setTimeout(function () {
        if (!state.submitting && state.container && !document.body.contains(state.container)) {
          // 结账页 soft-reload 会重建支付方式 DOM；旧卡字段 iframe 已失效，需重新挂载。
          state.container = null;
          state.cardSession = null;
          state.mounted = false;
          state.eligible = false;
          state.sdk = null;
        }
        if (selectedPaymentMethod() === 'paypal') {
          syncVisibility();
        }
      }, 50);
    });
    state.observer.observe(target, { childList: true, subtree: true });
  }

  function registerCheckoutHandler() {
    var checkout = global.WelineCheckout;
    if (checkout && typeof checkout.registerPaymentHandler === 'function') {
      return checkout.registerPaymentHandler('paypal', {
        isActive: isActive,
        handleSubmit: handleSubmit
      });
    }
    return false;
  }

  function boot() {
    if (state.booted) {
      return;
    }
    // 结账页支付方式由 getData 异步渲染：boot 时可能还没有 radio。
    // 事件/观察器先挂上，radio 出现后 syncVisibility 再真正初始化。
    state.booted = true;
    ensureContainer();
    document.addEventListener('change', function (event) {
      if (event.target && event.target.name === 'payment_method') {
        syncVisibility();
      }
    });
    // Payment 只依赖 Checkout 的通用扩展点，不反向引用结账页内部函数。
    if (!registerCheckoutHandler()) {
      setTimeout(registerCheckoutHandler, 0);
      setTimeout(registerCheckoutHandler, 500);
    }
    document.addEventListener('weline:checkout:payment-handlers-ready', registerCheckoutHandler);
    watchPaymentMethods();
    if (selectedPaymentMethod() === 'paypal') {
      init().then(function (ok) {
        setVisible(ok === true && selectedPaymentMethod() === 'paypal');
      });
    } else {
      syncVisibility();
    }
  }

  global.WelineModules = global.WelineModules || {};
  global.WelineModules.paypalCardFields = {
    boot: boot,
    init: init,
    isActive: isActive,
    handleSubmit: handleSubmit
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : this);
