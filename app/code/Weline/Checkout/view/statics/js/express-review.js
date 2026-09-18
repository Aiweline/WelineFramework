/**
 * Express review page: confirm provider address (editable like checkout), shipping, capture.
 * 事件链：确认支付方式 → 交易 →（成功页 checkout_success 闭环）。
 */
(function (global) {
  'use strict';

  function text(v) {
    return String(v == null ? '' : v).trim();
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

  function money(amount, currency) {
    var n = Number(amount || 0);
    var c = text(currency || 'CNY') || 'CNY';
    return c + ' ' + (Number.isFinite(n) ? n.toFixed(2) : '0.00');
  }

  function majorFromTotals(totals, majorKey, minorKey) {
    if (!totals || typeof totals !== 'object') {
      return 0;
    }
    if (totals[majorKey] != null && totals[majorKey] !== '') {
      return Number(totals[majorKey]);
    }
    if (totals[minorKey] != null) {
      return Number(totals[minorKey]) / 100;
    }
    return 0;
  }

  function formatAddress(address) {
    if (!address || typeof address !== 'object') {
      return '';
    }
    var lines = [
      text(address.contact_name || address.name),
      [text(address.country_code), text(address.province), text(address.city), text(address.district)]
        .filter(Boolean).join(' '),
      text(address.street || address.address1),
      text(address.postal_code),
      text(address.contact_phone || address.phone),
    ].filter(Boolean);
    return lines.join('\n');
  }

  function readField(scope, name) {
    if (!scope) {
      return '';
    }
    var input = scope.querySelector('[name="' + name + '"]');
    return input ? text(input.value) : '';
  }

  function collectShippingAddress(root) {
    var form = root.querySelector('[data-express-review-form]') || root.querySelector('form');
    var widget = root.querySelector('[data-shipping-checkout-address]');
    var scope = form || widget || root;
    var address = {
      name: readField(scope, 'name'),
      phone: readField(scope, 'phone') || readField(scope, 'contact_phone'),
      email: readField(scope, 'email'),
      country_code: readField(scope, 'country_code').toUpperCase(),
      country: readField(scope, 'country'),
      province: readField(scope, 'province'),
      city: readField(scope, 'city'),
      district: readField(scope, 'district'),
      street: readField(scope, 'street') || readField(scope, 'address1'),
      address1: readField(scope, 'address1') || readField(scope, 'street'),
      postal_code: readField(scope, 'postal_code'),
    };
    if (address.phone) {
      address.contact_phone = address.phone;
    }
    if (address.name) {
      address.contact_name = address.name;
    }
    var hasCore = !!(address.country_code || address.province || address.city || address.address1 || address.street);
    return hasCore ? address : {};
  }

  function seedShippingWidget(root, address) {
    if (!address || typeof address !== 'object') {
      return;
    }
    var form = root.querySelector('[data-express-review-form]') || root.querySelector('form');
    var scope = form || root;
    ['name', 'phone', 'email', 'address1', 'postal_code'].forEach(function (field) {
      var value = text(
        field === 'phone' ? (address.phone || address.contact_phone)
          : (field === 'name' ? (address.name || address.contact_name)
            : (field === 'address1' ? (address.address1 || address.street) : address[field]))
      );
      var input = scope.querySelector('[name="' + field + '"]');
      if (!input || !value) {
        return;
      }
      if (!text(input.value)) {
        input.value = value;
      }
    });
    if (global.WelineThemeAddress && typeof global.WelineThemeAddress.applyValues === 'function') {
      global.WelineThemeAddress.applyValues('checkout-shipping-address', {
        country_code: text(address.country_code).toUpperCase(),
        country: text(address.country || address.country_name || address.country_code),
        province: text(address.province),
        city: text(address.city),
        district: text(address.district),
        street: text(address.street || address.address1),
      });
    }
  }

  async function boot(root) {
    if (!root || root.dataset.expressReviewBound === '1') {
      return;
    }
    root.dataset.expressReviewBound = '1';
    if (text(root.getAttribute('data-already-paid')) === '1') {
      var paidStatus = root.querySelector('[data-express-status]');
      if (paidStatus && !text(paidStatus.textContent)) {
        paidStatus.textContent = '该笔订单已支付，无需再次确认';
      }
      return;
    }
    var transactionNo = text(root.getAttribute('data-transaction-no'));
    var checkoutGroupUuid = text(root.getAttribute('data-checkout-group-uuid'));
    if (!transactionNo || !checkoutGroupUuid) {
      try {
        var qs = new URLSearchParams(global.location.search);
        if (!transactionNo) {
          transactionNo = text(qs.get('transaction_no'));
        }
        if (!checkoutGroupUuid) {
          checkoutGroupUuid = text(qs.get('checkout_group_uuid'));
        }
      } catch (e) {}
    }
    var statusEl = root.querySelector('[data-express-status]');
    var confirmBtn = root.querySelector('[data-express-confirm]');
    var cancelBtn = root.querySelector('[data-express-cancel]');
    var methodEl = root.querySelector('[data-express-payment-method]');
    var paymentMethod = '';
    var methodLabel = '';
    var selectedServiceCode = '';
    var lastReview = null;
    var setStatus = function (msg, isError) {
      if (!statusEl) {
        return;
      }
      statusEl.textContent = msg || '';
      statusEl.classList.toggle('is-error', !!isError);
    };

    function applyPaymentMethod(data) {
      var code = text(
        (data && (data.payment_method || data.method_code)) || ''
      ).toLowerCase();
      var label = text((data && data.method_label) || '');
      if (code) {
        paymentMethod = code;
      }
      if (label) {
        methodLabel = label;
      } else if (paymentMethod) {
        methodLabel = '支付方式：' + paymentMethod;
      }
      if (methodEl) {
        if (methodLabel) {
          methodEl.hidden = false;
          methodEl.textContent = methodLabel;
        } else {
          methodEl.hidden = true;
          methodEl.textContent = '';
        }
      }
      if (paymentMethod) {
        root.setAttribute('data-payment-method', paymentMethod);
      }
    }

    function chainPayload(extra) {
      var totals = (lastReview && lastReview.totals) || {};
      var currency = text(totals.currency || 'CNY') || 'CNY';
      var grand = 0;
      if (totals.grand_total != null && totals.grand_total !== '') {
        grand = Number(totals.grand_total);
      } else if (totals.grand_total_minor != null) {
        grand = Number(totals.grand_total_minor) / 100;
      }
      var payload = {
        payment_method: paymentMethod || 'paypal',
        payment_type: paymentMethod || 'paypal',
        transaction_no: transactionNo,
        transaction_id: transactionNo,
        checkout_group_uuid: checkoutGroupUuid,
        currency: currency,
        value: Number.isFinite(grand) ? grand : 0,
        shipping_tier: selectedServiceCode || '',
        shipping_method: selectedServiceCode || '',
        service_code: selectedServiceCode || '',
        source: 'express_review',
      };
      if (lastReview && lastReview.order_uuid) {
        payload.order_uuid = text(lastReview.order_uuid);
      }
      return Object.assign(payload, extra || {});
    }

    if (!transactionNo && !checkoutGroupUuid) {
      setStatus('缺少支付单号，请从商品页重新发起快捷支付', true);
      return;
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      setStatus('API 不可用', true);
      return;
    }

    var api = await global.Weline.Api.resource('checkout');

    function reviewPayload(extra) {
      var payload = Object.assign({}, extra || {});
      if (transactionNo) {
        payload.transaction_no = transactionNo;
      }
      if (checkoutGroupUuid) {
        payload.checkout_group_uuid = checkoutGroupUuid;
      }
      return payload;
    }

    function rememberTransactionNo(data) {
      var next = text((data && data.transaction_no) || '');
      if (!next && data && data.data) {
        next = text(data.data.transaction_no);
      }
      if (next) {
        transactionNo = next;
        root.setAttribute('data-transaction-no', next);
        try {
          var url = new URL(global.location.href);
          if (!text(url.searchParams.get('transaction_no'))) {
            url.searchParams.set('transaction_no', next);
            global.history.replaceState({}, '', url.toString());
          }
        } catch (e) {}
      }
    }

    function handleAlreadyPaid(payload) {
      var data = payload && payload.data && typeof payload.data === 'object' ? payload.data : payload;
      var paid = !!(payload && (payload.already_paid || (data && data.already_paid)));
      if (!paid) {
        return false;
      }
      rememberTransactionNo(payload);
      rememberTransactionNo(data);
      var msg = text(
        (payload && payload.message)
        || (data && data.copy && data.copy.not_charged)
        || '该笔订单已支付'
      );
      var methodLabel = text(data && data.method_label);
      if (methodLabel) {
        msg = msg + '（' + methodLabel + '）';
      }
      setStatus(msg, false);
      if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.hidden = true;
      }
      var title = root.querySelector('.w-express-review__title');
      if (title) {
        title.textContent = '订单已支付';
      }
      var hint = root.querySelector('[data-express-copy-pending]');
      if (hint) {
        hint.textContent = text((data && data.copy && data.copy.not_charged) || '该笔快捷支付已完成扣款');
      }
      // Guests without checkout_token cannot open success ACL — stay and show paid copy.
      var redirect = text(
        (payload && payload.redirect_url)
        || (data && data.redirect_url)
        || ''
      );
      var hasToken = false;
      try {
        hasToken = !!text(new URLSearchParams(global.location.search).get('checkout_token'));
      } catch (e) {}
      if (redirect && hasToken) {
        global.setTimeout(function () {
          global.location.assign(redirect);
        }, 400);
      }
      return true;
    }

    function renderReview(data) {
      data = data && typeof data === 'object' ? data : {};
      lastReview = data;
      applyPaymentMethod(data);
      var address = data.address || {};
      var requiresShipping = data.requires_shipping !== false;
      var addressBlock = root.querySelector('[data-express-address-block]');
      if (addressBlock) {
        addressBlock.hidden = !requiresShipping;
      }
      var summary = root.querySelector('[data-express-address-summary]');
      if (summary) {
        summary.textContent = formatAddress(address) || '';
        summary.hidden = true;
      }
      var payer = root.querySelector('[data-express-payer-email]');
      if (payer && text(data.payer_email)) {
        payer.hidden = false;
        payer.textContent = text(data.payer_email);
      }
      var totals = data.totals || {};
      var currency = totals.currency || 'CNY';
      var map = {
        subtotal: '[data-express-subtotal]',
        shipping: '[data-express-shipping]',
        tax: '[data-express-tax]',
        discount: '[data-express-discount]',
        grand: '[data-express-grand]',
      };
      var values = {
        subtotal: majorFromTotals(totals, 'subtotal', 'subtotal_minor'),
        shipping: majorFromTotals(totals, 'shipping_amount', 'shipping_amount_minor'),
        tax: majorFromTotals(totals, 'tax_amount', 'tax_amount_minor'),
        discount: majorFromTotals(totals, 'discount_amount', 'discount_amount_minor'),
        grand: majorFromTotals(totals, 'grand_total', 'grand_total_minor'),
      };
      Object.keys(map).forEach(function (key) {
        var el = root.querySelector(map[key]);
        if (el) {
          el.textContent = money(values[key], currency);
        }
      });

      var shipBlock = root.querySelector('[data-express-shipping-block]');
      var shipList = root.querySelector('[data-express-shipping-list]');
      var shipEmpty = root.querySelector('[data-express-shipping-empty]');
      var methods = Array.isArray(data.shipping_methods) ? data.shipping_methods : [];
      var selectedCode = text(data.selected_service_code);
      selectedServiceCode = selectedCode || selectedServiceCode;
      if (shipBlock) {
        if (!requiresShipping) {
          shipBlock.hidden = true;
        } else if (methods.length === 0) {
          shipBlock.hidden = false;
          var emptyMsg = text(data.shipping_empty_message)
            || (data.missing_weight
              ? '购物车中有商品缺少重量，国际运费需按重量计算，因此目前无法报价。请联系客服协助后再结账。'
              : '')
            || text(data.embargo_message)
            || '系统暂未返回可用的配送方案。请确认收货地址，或联系客服了解具体原因。';
          if (shipEmpty) {
            shipEmpty.hidden = false;
            shipEmpty.textContent = emptyMsg;
          }
          setStatus(emptyMsg, true);
        } else if (shipList) {
          shipBlock.hidden = false;
          if (shipEmpty) {
            shipEmpty.hidden = true;
          }
          shipList.innerHTML = '';
          methods.forEach(function (method, index) {
            var code = text(method.code);
            var id = 'express-ship-' + index;
            var label = global.document.createElement('label');
            label.className = 'w-express-review__ship-option';
            var input = global.document.createElement('input');
            input.type = 'radio';
            input.name = 'express_service_code';
            input.value = code;
            input.id = id;
            if ((selectedServiceCode && code === selectedServiceCode) || (!selectedServiceCode && index === 0)) {
              input.checked = true;
              selectedServiceCode = code;
            }
            input.addEventListener('change', function () {
              if (input.checked) {
                selectedServiceCode = code;
                refreshReview(true);
              }
            });
            var span = global.document.createElement('span');
            // Display name only; code stays on input.value for submit (express path only).
            var displayName = text(method.title || method.label || method.service_name || method.code);
            span.textContent = displayName
              + (method.amount != null ? (' — ' + money(method.amount, currency)) : '');
            label.appendChild(input);
            label.appendChild(span);
            shipList.appendChild(label);
          });
          var pkgHost = root.querySelector('[data-express-shipping-packages]');
          var packages = Array.isArray(data.shipping_packages)
            ? data.shipping_packages
            : (data.quote && Array.isArray(data.quote.packages) ? data.quote.packages : []);
          if (pkgHost) {
            if (packages.length > 1) {
              pkgHost.hidden = false;
              pkgHost.innerHTML = '';
              var title = global.document.createElement('p');
              title.className = 'w-express-review__hint';
              title.textContent = '分仓运费明细';
              pkgHost.appendChild(title);
              packages.forEach(function (pkg) {
                var row = global.document.createElement('p');
                row.className = 'w-express-review__hint';
                var wh = pkg && (pkg.warehouse_id != null ? pkg.warehouse_id : '');
                var amt = pkg && pkg.amount_minor != null ? Number(pkg.amount_minor) / 100 : 0;
                var svc = text(pkg && (pkg.label || pkg.service_name || pkg.service_code));
                row.textContent = '仓 #' + wh + (svc ? (' · ' + svc) : '') + ' — ' + money(amt, currency);
                pkgHost.appendChild(row);
              });
            } else {
              pkgHost.hidden = true;
              pkgHost.innerHTML = '';
            }
          }
        }
      }

      var widgetPresent = !!root.querySelector('[data-shipping-checkout-address]');
      var missing = Array.isArray(data.missing_fields) ? data.missing_fields : [];
      var gapsBlock = root.querySelector('[data-express-gaps-block]');
      var showGaps = false;
      if (!widgetPresent) {
        ['contact_phone', 'email'].forEach(function (field) {
          var wrap = root.querySelector('[data-express-gap="' + field + '"]');
          if (!wrap) {
            return;
          }
          var needed = missing.indexOf(field) !== -1
            || (field === 'contact_phone' && !text(address.contact_phone || address.phone))
            || (field === 'email' && !text(address.email));
          wrap.hidden = !needed;
          if (needed) {
            showGaps = true;
          }
        });
      }
      if (gapsBlock) {
        gapsBlock.hidden = !showGaps;
      }

      if (data.embargo_blocked) {
        setStatus(text(data.embargo_message) || '当前地址不可配送', true);
      } else if (!data.embargo_blocked && methods.length > 0) {
        setStatus('', false);
      }

      var canConfirm = !!data.can_confirm && !data.embargo_blocked
        && (!requiresShipping || methods.length > 0);
      if (confirmBtn) {
        confirmBtn.disabled = !canConfirm;
      }

      if (data.copy_pending || (data.copy && data.copy.not_charged)) {
        var hint = root.querySelector('[data-express-copy-pending]');
        if (hint) {
          hint.textContent = text(data.copy_pending || (data.copy && data.copy.not_charged));
        }
      }
      var addressHint = root.querySelector('[data-express-address-hint]');
      if (addressHint && data.copy && data.copy.address_hint) {
        addressHint.textContent = text(data.copy.address_hint);
      }

      return address;
    }

    async function refreshReview(fromUser) {
      var payload = reviewPayload({
        service_code: selectedServiceCode,
      });
      var selected = collectShippingAddress(root);
      if (selected && Object.keys(selected).length) {
        payload.shipping_address = selected;
        if (selected.phone) {
          payload.contact_phone = selected.phone;
        }
        if (selected.email) {
          payload.email = selected.email;
        }
      }
      if (fromUser) {
        setStatus('正在按新地址重算…', false);
      }
      var review = await api.getExpressReview(payload);
      if (!review || review.success === false) {
        setStatus((review && review.message) || '加载失败', true);
        if (confirmBtn) {
          confirmBtn.disabled = true;
        }
        return null;
      }
      if (handleAlreadyPaid(review)) {
        return null;
      }
      rememberTransactionNo(review);
      var data = review.data && typeof review.data === 'object' ? review.data : review;
      rememberTransactionNo(data);
      return renderReview(data);
    }

    var first = await api.getExpressReview(reviewPayload({}));
    if (!first || first.success === false) {
      setStatus((first && first.message) || '加载失败', true);
      return;
    }
    if (handleAlreadyPaid(first)) {
      return;
    }
    rememberTransactionNo(first);
    var firstData = first.data && typeof first.data === 'object' ? first.data : first;
    rememberTransactionNo(firstData);
    var seeded = renderReview(firstData) || firstData.address || {};
    seedShippingWidget(root, seeded);

    var refreshTimer = null;
    global.addEventListener('weline:checkout:address-updated', function () {
      if (refreshTimer) {
        global.clearTimeout(refreshTimer);
      }
      refreshTimer = global.setTimeout(function () {
        refreshReview(true).catch(function (error) {
          setStatus(text(error && error.message) || '地址更新失败', true);
        });
      }, 250);
    });

    if (confirmBtn) {
      confirmBtn.addEventListener('click', async function () {
        confirmBtn.disabled = true;
        setStatus('确认收款中…', false);
        trackPixel('express_pay_confirmed', chainPayload({
          method_label: methodLabel,
          trigger: 'confirm_click',
        }), confirmBtn);
        try {
          var selected = collectShippingAddress(root);
          var phoneInput = root.querySelector('#express-phone');
          var emailInput = root.querySelector('#express-email');
          var result = await api.confirmExpressCheckout(reviewPayload({
            shipping_address: selected,
            contact_phone: text(selected.phone)
              || (phoneInput ? text(phoneInput.value) : ''),
            email: text(selected.email)
              || (emailInput ? text(emailInput.value) : ''),
            service_code: selectedServiceCode,
            tax_identity: (function () {
              var vat = root.querySelector('[data-tax-vat-id], [name="tax_identity[tax_id]"]');
              var taxId = vat ? text(vat.value) : '';
              return taxId ? { tax_id: taxId, tax_id_type: 'eu_vat' } : {};
            })(),
          }));
          if (!result || result.success === false) {
            throw new Error((result && result.message) || '确认失败');
          }
          rememberTransactionNo(result);
          if (result.data) {
            rememberTransactionNo(result.data);
          }
          trackPixel('express_pay_transaction', chainPayload({
            method_label: methodLabel,
            trigger: 'capture_started',
            order_uuid: text((result.data && result.data.order_uuid) || result.order_uuid || ''),
          }), confirmBtn);
          var redirect = text(
            (result.data && result.data.redirect_url) || result.redirect_url || '/payment/handoff'
          );
          global.location.assign(redirect);
        } catch (error) {
          setStatus(text(error && error.message) || '确认失败', true);
          confirmBtn.disabled = false;
        }
      });
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', function (event) {
        event.preventDefault();
        cancelBtn.setAttribute('aria-disabled', 'true');
        api.cancelExpressCheckout(reviewPayload({})).then(function (result) {
          var redirect = text(
            (result && result.data && result.data.redirect_url)
            || (result && result.redirect_url)
            || '/'
          );
          global.location.assign(redirect);
        }).catch(function () {
          global.location.assign('/');
        });
      });
    }

    try {
      global.dispatchEvent(new CustomEvent('weline:cart-updated', {
        detail: { source: 'express-review' },
      }));
    } catch (e) {}
  }

  function start() {
    document.querySelectorAll('[data-weline-checkout-express-review]').forEach(function (root) {
      boot(root).catch(function (error) {
        var statusEl = root.querySelector('[data-express-status]');
        if (statusEl) {
          statusEl.textContent = text(error && error.message) || '加载失败';
          statusEl.classList.add('is-error');
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  global.WelineCheckoutExpressReview = { boot: start };
})(typeof window !== 'undefined' ? window : this);
