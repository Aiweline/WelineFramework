/**
 * Storefront money summary — shared paint contract for cart/checkout/express/helppay.
 */
(function (global) {
  'use strict';

  var SYMBOLS = {
    CNY: '¥',
    RMB: '¥',
    USD: '$',
    EUR: '€',
    GBP: '£',
    JPY: '¥',
  };

  function text(value) {
    return value == null ? '' : String(value);
  }

  function toMinor(value) {
    var n = Number(value);
    if (!isFinite(n)) {
      return 0;
    }
    return Math.round(n);
  }

  function majorFromMinor(minor) {
    return toMinor(minor) / 100;
  }

  function formatMoney(major, currency, style) {
    var amount = Number(major);
    if (!isFinite(amount)) {
      amount = 0;
    }
    var code = text(currency || 'CNY').toUpperCase().trim() || 'CNY';
    var formatted = new Intl.NumberFormat(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount);
    if (style === 'code') {
      return code + ' ' + formatted;
    }
    var symbol = SYMBOLS[code] || code;
    return symbol + formatted;
  }

  function qs(root, sel) {
    return root && root.querySelector ? root.querySelector(sel) : null;
  }

  function setText(el, value) {
    if (el) {
      el.textContent = value;
    }
  }

  function setHidden(el, hidden) {
    if (!el) {
      return;
    }
    if (hidden) {
      el.hidden = true;
      el.setAttribute('hidden', '');
    } else {
      el.hidden = false;
      el.removeAttribute('hidden');
    }
  }

  function resolveRoot(target) {
    if (!target) {
      return null;
    }
    if (target.getAttribute && target.getAttribute('data-money-summary') != null) {
      return target;
    }
    return qs(target, '[data-money-summary]') || target.closest && target.closest('[data-money-summary]');
  }

  function modeOf(root, dto) {
    var fromDto = text(dto && dto.mode).toLowerCase();
    if (fromDto) {
      return fromDto;
    }
    return text(root && root.getAttribute('data-money-summary-mode')).toLowerCase() || 'checkout';
  }

  function moneyStyle(mode, dto) {
    if (dto && dto.format) {
      return text(dto.format);
    }
    return mode === 'helppay' ? 'code' : 'symbol';
  }

  function paint(target, dto) {
    var root = resolveRoot(target);
    if (!root || !dto || typeof dto !== 'object') {
      return null;
    }

    var mode = modeOf(root, dto);
    root.setAttribute('data-money-summary-mode', mode);

    var currency = text(dto.currency || 'CNY').toUpperCase().trim() || 'CNY';
    var style = moneyStyle(mode, dto);
    var discountsDisabled = !!(dto.discounts_disabled
      || root.getAttribute('data-discounts-disabled') === '1');
    var shippingPending = !!(dto.shipping_pending
      || root.getAttribute('data-shipping-pending') === '1'
      || mode === 'cart'
      || mode === 'mini-cart');

    var goodsMinor = toMinor(dto.goods_subtotal_minor);
    var shippingMinor = toMinor(dto.shipping_minor);
    var discountMinor = toMinor(dto.discount_minor);
    var depositMinor = toMinor(dto.deposit_minor);
    var creditMinor = toMinor(dto.credit_minor);
    // Accept tax_minor or server cart field tax_amount_minor (same paint contract).
    var taxMinor = toMinor(
      dto.tax_minor != null ? dto.tax_minor : dto.tax_amount_minor
    );
    var codMinor = toMinor(dto.cod_fee_minor);
    var incentiveMinor = toMinor(dto.payment_incentive_minor);
    var payableMinor = dto.payable_minor != null
      ? toMinor(dto.payable_minor)
      : Math.max(
        0,
        goodsMinor + shippingMinor - discountMinor + taxMinor + codMinor - incentiveMinor
      );

    var shippingLabelBase = text(
      qs(root, '[data-money-summary-shipping-label]')
        && qs(root, '[data-money-summary-shipping-label]').getAttribute('data-label-base')
    );
    if (!shippingLabelBase) {
      var shipLabelEl = qs(root, '[data-money-summary-shipping-label]');
      shippingLabelBase = shipLabelEl
        ? text(shipLabelEl.textContent).split(' · ')[0].trim() || '运费'
        : '运费';
      if (shipLabelEl) {
        shipLabelEl.setAttribute('data-label-base', shippingLabelBase);
      }
    }

    var serviceLabel = text(dto.shipping_service_label).trim();
    setText(
      qs(root, '[data-money-summary-shipping-label]'),
      shippingLabelBase + (serviceLabel ? ' · ' + serviceLabel : '')
    );

    setText(
      qs(root, '[data-money-summary-goods]'),
      formatMoney(majorFromMinor(goodsMinor), currency, style)
    );
    setText(
      qs(root, '[data-money-summary-shipping]'),
      formatMoney(majorFromMinor(shippingMinor), currency, style)
    );

    var discountRow = qs(root, '[data-money-summary-row="discount"]');
    var discountLabel = text(dto.discount_label).trim();
    var discountLabelEl = qs(root, '[data-money-summary-discount-label]');
    if (discountLabelEl && discountLabel) {
      setText(discountLabelEl, discountLabel);
    }
    if (!discountsDisabled && discountMinor > 0) {
      setHidden(discountRow, false);
      setText(
        qs(root, '[data-money-summary-discount]'),
        '-' + formatMoney(majorFromMinor(discountMinor), currency, style)
      );
    } else {
      setHidden(discountRow, true);
      setText(qs(root, '[data-money-summary-discount]'), formatMoney(0, currency, style));
    }

    var depositRow = qs(root, '[data-money-summary-row="deposit"]');
    if (depositMinor > 0) {
      setHidden(depositRow, false);
      setText(
        qs(root, '[data-money-summary-deposit]'),
        formatMoney(majorFromMinor(depositMinor), currency, style)
      );
    } else {
      setHidden(depositRow, true);
      setText(qs(root, '[data-money-summary-deposit]'), formatMoney(0, currency, style));
    }

    var creditRow = qs(root, '[data-money-summary-row="credit"]');
    if (creditMinor > 0) {
      setHidden(creditRow, false);
      setText(
        qs(root, '[data-money-summary-credit]'),
        '-' + formatMoney(majorFromMinor(creditMinor), currency, style)
      );
    } else {
      setHidden(creditRow, true);
      setText(qs(root, '[data-money-summary-credit]'), formatMoney(0, currency, style));
    }

    var taxRow = qs(root, '[data-money-summary-row="tax"]');
    var taxLabel = text(dto.tax_label).trim();
    if (taxLabel) {
      setText(qs(root, '[data-money-summary-tax-label]'), taxLabel);
    }
    if (taxMinor > 0) {
      setHidden(taxRow, false);
      setText(
        qs(root, '[data-money-summary-tax]'),
        formatMoney(majorFromMinor(taxMinor), currency, style)
      );
    } else {
      setHidden(taxRow, true);
      setText(qs(root, '[data-money-summary-tax]'), formatMoney(0, currency, style));
    }

    var codRow = qs(root, '[data-money-summary-row="cod"]');
    if (codMinor > 0) {
      setHidden(codRow, false);
      setText(
        qs(root, '[data-money-summary-cod]'),
        formatMoney(majorFromMinor(codMinor), currency, style)
      );
    } else {
      setHidden(codRow, true);
      setText(qs(root, '[data-money-summary-cod]'), formatMoney(0, currency, style));
    }

    var incentiveRow = qs(root, '[data-money-summary-row="incentive"]');
    if (!discountsDisabled && incentiveMinor > 0) {
      setHidden(incentiveRow, false);
      setText(
        qs(root, '[data-money-summary-incentive]'),
        '-' + formatMoney(majorFromMinor(incentiveMinor), currency, style)
      );
    } else {
      setHidden(incentiveRow, true);
      setText(qs(root, '[data-money-summary-incentive]'), formatMoney(0, currency, style));
    }

    var shippingRow = qs(root, '[data-money-summary-row="shipping"]');
    if (shippingPending) {
      setHidden(shippingRow, true);
    } else {
      setHidden(shippingRow, false);
    }

    var payableLabel = text(dto.payable_label).trim();
    if (payableLabel) {
      setText(qs(root, '[data-money-summary-payable-label]'), payableLabel);
    }
    setText(
      qs(root, '[data-money-summary-payable]'),
      formatMoney(majorFromMinor(payableMinor), currency, style)
    );

    // HelpPay alias for goods amount node used by legacy selectors.
    var goodsAlias = qs(root, '[data-helppay-goods-amount]');
    if (goodsAlias) {
      setText(goodsAlias, formatMoney(majorFromMinor(goodsMinor), currency, style));
    } else {
      var goodsNode = qs(root, '[data-money-summary-goods]');
      if (goodsNode && !goodsNode.hasAttribute('data-helppay-goods-amount')) {
        goodsNode.setAttribute('data-helppay-goods-amount', '');
        goodsNode.setAttribute('data-testid', 'helppay-payment-goods-amount');
      }
    }

    var noteEl = qs(root, '[data-money-summary-note]');
    if (noteEl) {
      if (dto.note != null) {
        var noteText = text(dto.note).trim();
        setText(noteEl, noteText);
        setHidden(noteEl, noteText === '');
      } else if (shippingPending) {
        if (taxMinor > 0) {
          setText(noteEl, text(dto.note_shipping_only) || noteEl.getAttribute('data-note-shipping') || '运费将在结算时计算');
          setHidden(noteEl, false);
        } else {
          var defaultNote = text(noteEl.getAttribute('data-note-default')) || text(noteEl.textContent).trim();
          if (defaultNote) {
            setText(noteEl, defaultNote);
            setHidden(noteEl, false);
          }
        }
      }
    }

    root.setAttribute('data-money-summary-goods-minor', String(goodsMinor));
    root.setAttribute('data-money-summary-shipping-minor', String(shippingMinor));
    root.setAttribute('data-money-summary-tax-minor', String(taxMinor));
    root.setAttribute('data-money-summary-payable-minor', String(payableMinor));
    root.setAttribute('data-money-summary-currency', currency);

    return {
      goods_subtotal_minor: goodsMinor,
      shipping_minor: shippingMinor,
      tax_minor: taxMinor,
      payable_minor: payableMinor,
      currency: currency,
    };
  }

  /**
   * Ensure a money-summary root exists under host (for JS-built dialogs).
   */
  function ensure(host, options) {
    if (!host) {
      return null;
    }
    var existing = resolveRoot(host);
    if (existing) {
      if (options && options.mode) {
        existing.setAttribute('data-money-summary-mode', text(options.mode));
      }
      return existing;
    }
    var mode = text(options && options.mode || 'helppay').toLowerCase() || 'helppay';
    var wrap = global.document.createElement('div');
    wrap.className = 'w-storefront-money-summary';
    wrap.setAttribute('data-money-summary', '');
    wrap.setAttribute('data-money-summary-mode', mode);
    wrap.setAttribute('data-testid', 'storefront-money-summary');
    wrap.setAttribute('role', 'list');
    if (options && options.discounts_disabled) {
      wrap.setAttribute('data-discounts-disabled', '1');
    }
    var payableLabel = mode === 'cart' || mode === 'mini-cart' ? '小计' : '应付';
    var taxLabel = mode === 'checkout' ? '关税与税费（预估）' : '税费（预估）';
    wrap.innerHTML =
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--goods" role="listitem" data-money-summary-row="goods">' +
      '<span data-money-summary-goods-label>商品小计</span>' +
      '<strong data-money-summary-goods data-subtotal="" data-helppay-goods-amount data-testid="helppay-payment-goods-amount">—</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--shipping" role="listitem" data-money-summary-row="shipping">' +
      '<span data-money-summary-shipping-label data-helppay-ship-label data-label-base="运费">运费</span>' +
      '<strong data-money-summary-shipping data-shipping-amount="" data-helppay-ship-amount data-testid="helppay-payment-ship-amount">—</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--discount" role="listitem" data-money-summary-row="discount" data-checkout-discount-row hidden>' +
      '<span data-money-summary-discount-label data-checkout-discount-label>优惠</span>' +
      '<strong data-money-summary-discount data-discount-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--tax" role="listitem" data-money-summary-row="tax" data-checkout-tax-row data-cart-tax-row data-mini-cart-tax-row hidden>' +
      '<span data-money-summary-tax-label data-cart-tax-label data-mini-cart-tax-label>' + taxLabel + '</span>' +
      '<strong data-money-summary-tax data-tax-amount="" data-cart-tax-amount data-express-tax>0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--payable" role="listitem" data-money-summary-row="payable">' +
      '<span data-money-summary-payable-label data-grand-total-label data-cart-subtotal-label>' + payableLabel + '</span>' +
      '<strong class="w-storefront-money-summary__grand" data-money-summary-payable data-grand-total="" data-helppay-total-amount data-helppay-payable-amount data-testid="helppay-payment-total">—</strong>' +
      '</div>' +
      '<p class="w-storefront-money-summary__note" data-money-summary-note data-cart-summary-note data-mini-cart-note data-note-default="税费与运费将在结算时计算" data-note-shipping="运费将在结算时计算" hidden></p>';
    host.appendChild(wrap);
    return wrap;
  }

  function find(scope) {
    var base = scope && scope.querySelector ? scope : global.document;
    return qs(base, '[data-money-summary]');
  }

  var api = {
    paint: paint,
    ensure: ensure,
    find: find,
    formatMoney: formatMoney,
  };

  global.WelineStorefrontMoneySummary = api;

  if (typeof global.Weline !== 'undefined' && typeof global.Weline.declare === 'function') {
    global.Weline.declare('storefrontMoneySummary', function () {
      return api;
    });
  }
})(typeof window !== 'undefined' ? window : this);
