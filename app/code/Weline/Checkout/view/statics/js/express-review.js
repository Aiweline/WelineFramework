/**
 * Express review page: load snapshot, gap fields, shipping, confirm capture, cancel.
 */
(function (global) {
  'use strict';

  function text(v) {
    return String(v == null ? '' : v).trim();
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

  async function boot(root) {
    if (!root || root.dataset.expressReviewBound === '1') {
      return;
    }
    root.dataset.expressReviewBound = '1';
    var transactionNo = text(root.getAttribute('data-transaction-no'));
    if (!transactionNo) {
      try {
        transactionNo = text(new URLSearchParams(global.location.search).get('transaction_no'));
      } catch (e) {}
    }
    var statusEl = root.querySelector('[data-express-status]');
    var confirmBtn = root.querySelector('[data-express-confirm]');
    var cancelBtn = root.querySelector('[data-express-cancel]');
    var setStatus = function (msg, isError) {
      if (!statusEl) {
        return;
      }
      statusEl.textContent = msg || '';
      statusEl.classList.toggle('is-error', !!isError);
    };

    if (!transactionNo) {
      setStatus('缺少支付单号', true);
      return;
    }
    if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
      setStatus('API 不可用', true);
      return;
    }

    var api = await global.Weline.Api.resource('checkout');
    var review = await api.getExpressReview({ transaction_no: transactionNo });
    if (!review || review.success === false) {
      setStatus((review && review.message) || '加载失败', true);
      return;
    }
    var data = review.data && typeof review.data === 'object' ? review.data : review;
    var address = data.address || {};
    var requiresShipping = data.requires_shipping !== false;
    var addressBlock = root.querySelector('[data-express-address-block]');
    if (addressBlock && !requiresShipping) {
      addressBlock.hidden = true;
    }
    var summary = root.querySelector('[data-express-address-summary]');
    if (summary) {
      summary.textContent = formatAddress(address) || '—';
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
    var selectedServiceCode = selectedCode;
    if (shipBlock) {
      if (!requiresShipping) {
        shipBlock.hidden = true;
      } else if (methods.length === 0) {
        shipBlock.hidden = false;
        if (shipEmpty) {
          shipEmpty.hidden = false;
        }
        setStatus(text(data.embargo_message) || '该地区暂不支持配送', true);
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
          if ((selectedCode && code === selectedCode) || (!selectedCode && index === 0)) {
            input.checked = true;
            selectedServiceCode = code;
          }
          input.addEventListener('change', function () {
            if (input.checked) {
              selectedServiceCode = code;
            }
          });
          var span = global.document.createElement('span');
          span.textContent = text(method.title || method.code)
            + (method.amount != null ? (' — ' + money(method.amount, currency)) : '');
          label.appendChild(input);
          label.appendChild(span);
          shipList.appendChild(label);
        });
      }
    }

    var missing = Array.isArray(data.missing_fields) ? data.missing_fields : [];
    var gapsBlock = root.querySelector('[data-express-gaps-block]');
    var showGaps = false;
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
    if (gapsBlock) {
      gapsBlock.hidden = !showGaps;
    }

    if (data.embargo_blocked) {
      setStatus(text(data.embargo_message) || '当前地址不可配送', true);
    }

    var canConfirm = !!data.can_confirm && !data.embargo_blocked
      && (!requiresShipping || methods.length > 0);
    if (confirmBtn) {
      confirmBtn.disabled = !canConfirm;
      confirmBtn.addEventListener('click', async function () {
        confirmBtn.disabled = true;
        setStatus('确认收款中…', false);
        try {
          var phoneInput = root.querySelector('#express-phone');
          var emailInput = root.querySelector('#express-email');
          var result = await api.confirmExpressCheckout({
            transaction_no: transactionNo,
            contact_phone: phoneInput ? text(phoneInput.value) : '',
            email: emailInput ? text(emailInput.value) : '',
            service_code: selectedServiceCode || selectedCode,
          });
          if (!result || result.success === false) {
            throw new Error((result && result.message) || '确认失败');
          }
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
        api.cancelExpressCheckout({ transaction_no: transactionNo }).then(function (result) {
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

    if (data.copy_pending || (data.copy && data.copy.not_charged)) {
      var hint = root.querySelector('[data-express-copy-pending]');
      if (hint) {
        hint.textContent = text(data.copy_pending || (data.copy && data.copy.not_charged));
      }
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
