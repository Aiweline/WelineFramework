/**
 * Storefront money summary — shared paint contract for cart/checkout/express/helppay.
 * Sales tax / customs duty / import tax are separate rows (never muddled as one「税费」).
 */
(function (global) {
  'use strict';

  // Keep in sync with Weline\\Currency\\Helper\\CurrencySymbol::GLYPHS (symbol-first).
  var SYMBOLS = {
    CNY: '¥',
    RMB: '¥',
    USD: '$',
    EUR: '€',
    GBP: '£',
    JPY: '¥',
    AUD: 'A$',
    CAD: 'C$',
    HKD: 'HK$',
    SGD: 'S$',
    NZD: 'NZ$',
    KRW: '₩',
    INR: '₹',
    THB: '฿',
    RUB: '₽',
    BRL: 'R$',
    MXN: 'MX$',
    PHP: '₱',
    VND: '₫',
    TRY: '₺',
    ILS: '₪',
    PLN: 'zł',
    SEK: 'kr',
    NOK: 'kr',
    DKK: 'kr',
    CHF: 'Fr',
    ZAR: 'R',
    AED: 'د.إ',
    SAR: '﷼',
    MYR: 'RM',
    IDR: 'Rp',
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

  function resolveSymbol(code) {
    var key = text(code || 'CNY').toUpperCase().trim() || 'CNY';
    if (SYMBOLS[key]) {
      return SYMBOLS[key];
    }
    var clientMap = global.WelineCurrencySymbolMap
      || (global.WelineCurrency && global.WelineCurrency.symbolMap)
      || null;
    if (clientMap && typeof clientMap === 'object') {
      var fromMap = text(clientMap[key]).trim();
      if (fromMap && fromMap.toUpperCase() !== key) {
        return fromMap;
      }
    }
    return '';
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
    // Symbol-first: only fall back to ISO code when no glyph exists.
    var symbol = resolveSymbol(code);
    if (!symbol || symbol.toUpperCase() === code) {
      return code + ' ' + formatted;
    }
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

  /**
   * Resolve sales / customs / import minors.
   * Legacy: only tax_minor → treat as sales tax.
   * Split: any of sales_tax_minor / duty_minor / customs_duty_minor / import_tax_minor present.
   */
  function resolveTaxParts(dto) {
    var hasSplit = dto.sales_tax_minor != null
      || dto.duty_minor != null
      || dto.customs_duty_minor != null
      || dto.import_tax_minor != null;
    var legacyTax = toMinor(
      dto.tax_minor != null ? dto.tax_minor : dto.tax_amount_minor
    );
    var salesTaxMinor = hasSplit
      ? toMinor(dto.sales_tax_minor)
      : legacyTax;
    var customsDutyMinor = toMinor(
      dto.customs_duty_minor != null ? dto.customs_duty_minor : dto.duty_minor
    );
    var importTaxMinor = toMinor(dto.import_tax_minor);
    return {
      sales_tax_minor: Math.max(0, salesTaxMinor),
      customs_duty_minor: Math.max(0, customsDutyMinor),
      import_tax_minor: Math.max(0, importTaxMinor),
      tax_minor: Math.max(0, salesTaxMinor + customsDutyMinor + importTaxMinor),
    };
  }

  function paintTaxRow(root, rowKey, amountSel, labelSels, amountMinor, labelOverride, currency, style) {
    var row = qs(root, '[data-money-summary-row="' + rowKey + '"]');
    if (!row && rowKey === 'sales_tax') {
      row = qs(root, '[data-money-summary-row="tax"]')
        || qs(root, '[data-money-summary-row-legacy="tax"]');
    }
    if (labelOverride) {
      var labelEl = null;
      var sels = Array.isArray(labelSels) ? labelSels : [labelSels];
      for (var i = 0; i < sels.length; i++) {
        labelEl = qs(root, sels[i]);
        if (labelEl) {
          break;
        }
      }
      setText(labelEl || (row && qs(row, 'span')), labelOverride);
    }
    var amountEl = qs(root, amountSel)
      || (rowKey === 'sales_tax' ? qs(root, '[data-money-summary-tax]') : null);
    if (amountMinor > 0) {
      setHidden(row, false);
      setText(amountEl, formatMoney(majorFromMinor(amountMinor), currency, style));
    } else {
      setHidden(row, true);
      setText(amountEl, formatMoney(0, currency, style));
    }
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
    // Deposit / wholesale credit rows require an explicit tob shell — amount alone must not elevate toc.
    var depositAllowed = dto.commerce_deposit_allowed === true
      || String(dto.cart_type || '').toLowerCase() === 'tob'
      || String(root.getAttribute('data-cart-type') || '').toLowerCase() === 'tob'
      || String(root.getAttribute('data-money-summary-cart-type') || '').toLowerCase() === 'tob';
    var depositMinor = depositAllowed ? toMinor(dto.deposit_minor) : 0;
    var creditMinor = depositAllowed ? toMinor(dto.credit_minor) : 0;
    var taxParts = resolveTaxParts(dto);
    var salesTaxMinor = taxParts.sales_tax_minor;
    var customsDutyMinor = taxParts.customs_duty_minor;
    var importTaxMinor = taxParts.import_tax_minor;
    var taxMinor = taxParts.tax_minor;
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

    var salesTaxLabel = text(dto.sales_tax_label || dto.tax_label).trim();
    paintTaxRow(
      root,
      'sales_tax',
      '[data-money-summary-sales-tax]',
      ['[data-money-summary-sales-tax-label]', '[data-money-summary-tax-label]'],
      salesTaxMinor,
      salesTaxLabel,
      currency,
      style
    );
    paintTaxRow(
      root,
      'customs_duty',
      '[data-money-summary-customs-duty]',
      ['[data-money-summary-customs-duty-label]'],
      customsDutyMinor,
      text(dto.customs_duty_label || dto.duty_label).trim(),
      currency,
      style
    );
    paintTaxRow(
      root,
      'import_tax',
      '[data-money-summary-import-tax]',
      ['[data-money-summary-import-tax-label]'],
      importTaxMinor,
      text(dto.import_tax_label).trim(),
      currency,
      style
    );

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
    root.setAttribute('data-money-summary-sales-tax-minor', String(salesTaxMinor));
    root.setAttribute('data-money-summary-customs-duty-minor', String(customsDutyMinor));
    root.setAttribute('data-money-summary-import-tax-minor', String(importTaxMinor));
    root.setAttribute('data-money-summary-tax-minor', String(taxMinor));
    root.setAttribute('data-money-summary-payable-minor', String(payableMinor));
    root.setAttribute('data-money-summary-currency', currency);

    return {
      goods_subtotal_minor: goodsMinor,
      shipping_minor: shippingMinor,
      sales_tax_minor: salesTaxMinor,
      customs_duty_minor: customsDutyMinor,
      import_tax_minor: importTaxMinor,
      tax_minor: taxMinor,
      payable_minor: payableMinor,
      currency: currency,
    };
  }

  /**
   * Insert a missing paint row before payable (upgrade incomplete ensure shells).
   */
  function insertRowBeforePayable(root, html) {
    if (!root) {
      return;
    }
    var payable = qs(root, '[data-money-summary-row="payable"]');
    if (payable && typeof payable.insertAdjacentHTML === 'function') {
      payable.insertAdjacentHTML('beforebegin', html);
      return;
    }
    if (typeof root.insertAdjacentHTML === 'function') {
      root.insertAdjacentHTML('beforeend', html);
    }
  }

  /**
   * Ensure deposit/credit/cod/incentive rows exist (parity with default.phtml).
   * Older ensure() shells omitted these; paint then silently skipped B2B lines.
   */
  function ensurePaintRows(root, mode) {
    if (!root) {
      return;
    }
    var m = text(mode || root.getAttribute('data-money-summary-mode') || '').toLowerCase();
    if ((m === 'cart' || m === 'mini-cart')
      && !qs(root, '[data-money-summary-discount-lines]')) {
      var goods = qs(root, '[data-money-summary-row="goods"]');
      if (goods && typeof goods.insertAdjacentHTML === 'function') {
        goods.insertAdjacentHTML(
          'afterend',
          '<div class="w-storefront-money-summary__discount-lines"'
          + ' data-money-summary-discount-lines data-cart-discount-lines'
          + ' data-mini-cart-discount-lines data-mini-cart-discount-breakdown></div>'
        );
      }
    }
    if (!qs(root, '[data-money-summary-row="deposit"]')) {
      insertRowBeforePayable(
        root,
        '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--deposit"'
        + ' role="listitem" data-money-summary-row="deposit" data-checkout-deposit-row hidden>'
        + '<span>批发定金</span>'
        + '<strong data-money-summary-deposit data-deposit-amount="">0.00</strong>'
        + '</div>'
      );
    }
    if (!qs(root, '[data-money-summary-row="credit"]')) {
      insertRowBeforePayable(
        root,
        '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--credit"'
        + ' role="listitem" data-money-summary-row="credit" data-checkout-credit-row hidden>'
        + '<span>批发信用抵扣</span>'
        + '<strong data-money-summary-credit data-credit-amount="">0.00</strong>'
        + '</div>'
      );
    }
    if (!qs(root, '[data-money-summary-row="cod"]')) {
      insertRowBeforePayable(
        root,
        '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--cod"'
        + ' role="listitem" data-money-summary-row="cod" data-checkout-cod-fee-row hidden>'
        + '<span>货到付款手续费</span>'
        + '<strong data-money-summary-cod data-cod-fee-amount="">0.00</strong>'
        + '</div>'
      );
    }
    if (!qs(root, '[data-money-summary-row="incentive"]')) {
      insertRowBeforePayable(
        root,
        '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--incentive"'
        + ' role="listitem" data-money-summary-row="incentive"'
        + ' data-checkout-payment-incentive-row data-testid="checkout-payment-incentive-row" hidden>'
        + '<span>支付方式优惠</span>'
        + '<strong data-money-summary-incentive data-payment-incentive-amount="">0.00</strong>'
        + '</div>'
      );
    }
  }

  /**
   * Ensure a money-summary root exists under host (for JS-built dialogs).
   * Row order must match default.phtml so paint can show deposit/credit.
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
      ensurePaintRows(existing, options && options.mode);
      return existing;
    }
    var mode = text(options && options.mode || 'helppay').toLowerCase() || 'helppay';
    var wrap = global.document.createElement('div');
    wrap.className = 'w-storefront-money-summary';
    wrap.setAttribute('data-money-summary', '');
    wrap.setAttribute('data-money-summary-mode', mode);
    wrap.setAttribute('data-testid', 'storefront-money-summary');
    wrap.setAttribute('role', 'list');
    if (mode === 'cart' || mode === 'mini-cart') {
      wrap.setAttribute('data-shipping-pending', '1');
    }
    if (options && options.discounts_disabled) {
      wrap.setAttribute('data-discounts-disabled', '1');
    }
    var payableLabel = mode === 'cart' || mode === 'mini-cart' ? '小计' : '应付';
    var discountLines = (mode === 'cart' || mode === 'mini-cart')
      ? ('<div class="w-storefront-money-summary__discount-lines"'
        + ' data-money-summary-discount-lines data-cart-discount-lines'
        + ' data-mini-cart-discount-lines data-mini-cart-discount-breakdown></div>')
      : '';
    var shippingHidden = (mode === 'cart' || mode === 'mini-cart') ? ' hidden' : '';
    wrap.innerHTML =
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--goods" role="listitem" data-money-summary-row="goods" data-cart-discount-breakdown>' +
      '<span data-money-summary-goods-label>商品小计</span>' +
      '<strong data-money-summary-goods data-subtotal="" data-cart-goods-subtotal data-helppay-goods-amount data-testid="helppay-payment-goods-amount">—</strong>' +
      '</div>' +
      discountLines +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--shipping" role="listitem" data-money-summary-row="shipping"' + shippingHidden + '>' +
      '<span data-money-summary-shipping-label data-helppay-ship-label data-label-base="运费">运费</span>' +
      '<strong data-money-summary-shipping data-shipping-amount="" data-helppay-ship-amount data-testid="helppay-payment-ship-amount">—</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--discount" role="listitem" data-money-summary-row="discount" data-checkout-discount-row hidden>' +
      '<span data-money-summary-discount-label data-checkout-discount-label>优惠</span>' +
      '<strong data-money-summary-discount data-discount-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--deposit" role="listitem" data-money-summary-row="deposit" data-checkout-deposit-row hidden>' +
      '<span>批发定金</span>' +
      '<strong data-money-summary-deposit data-deposit-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--credit" role="listitem" data-money-summary-row="credit" data-checkout-credit-row hidden>' +
      '<span>批发信用抵扣</span>' +
      '<strong data-money-summary-credit data-credit-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--sales-tax" role="listitem" data-money-summary-row="sales_tax" data-money-summary-row-legacy="tax" data-checkout-tax-row data-cart-tax-row data-mini-cart-tax-row hidden>' +
      '<span data-money-summary-sales-tax-label data-money-summary-tax-label data-cart-tax-label data-mini-cart-tax-label>销售税</span>' +
      '<strong data-money-summary-sales-tax data-money-summary-tax data-tax-amount="" data-cart-tax-amount data-express-tax>0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--customs-duty" role="listitem" data-money-summary-row="customs_duty" data-checkout-customs-duty-row hidden>' +
      '<span data-money-summary-customs-duty-label>进口关税</span>' +
      '<strong data-money-summary-customs-duty>0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--import-tax" role="listitem" data-money-summary-row="import_tax" data-checkout-import-tax-row hidden>' +
      '<span data-money-summary-import-tax-label>进口税费</span>' +
      '<strong data-money-summary-import-tax>0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--cod" role="listitem" data-money-summary-row="cod" data-checkout-cod-fee-row hidden>' +
      '<span>货到付款手续费</span>' +
      '<strong data-money-summary-cod data-cod-fee-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--incentive" role="listitem" data-money-summary-row="incentive" data-checkout-payment-incentive-row data-testid="checkout-payment-incentive-row" hidden>' +
      '<span>支付方式优惠</span>' +
      '<strong data-money-summary-incentive data-payment-incentive-amount="">0.00</strong>' +
      '</div>' +
      '<div class="w-storefront-money-summary__row w-storefront-money-summary__row--payable" role="listitem" data-money-summary-row="payable">' +
      '<span data-money-summary-payable-label data-grand-total-label data-cart-subtotal-label>' + payableLabel + '</span>' +
      '<strong class="w-storefront-money-summary__grand" data-money-summary-payable data-grand-total="" data-cart-grand-total data-cart-total-amount data-helppay-total-amount data-helppay-payable-amount data-testid="helppay-payment-total">—</strong>' +
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
