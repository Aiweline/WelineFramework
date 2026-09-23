window.WelineWidgetAssets.register('tax-checkout-tax-identity-0', function (widgetScript) {
(function () {
  var root = document.querySelector('[data-tax-identity-root]');
  if (!root || root.getAttribute('data-tax-identity-bound') === '1') return;
  root.setAttribute('data-tax-identity-bound', '1');
  var eu = (root.getAttribute('data-eu-countries') || '').split(',').filter(Boolean);

  function iso2(value) {
    var v = String(value || '').toUpperCase().trim();
    return /^[A-Z]{2}$/.test(v) ? v : '';
  }

  function readIsoFrom(scope, selectors) {
    if (!scope || !scope.querySelector) return '';
    for (var i = 0; i < selectors.length; i++) {
      var el = scope.querySelector(selectors[i]);
      if (!el) continue;
      var v = iso2(el.value || el.getAttribute('data-address-country') || el.getAttribute('data-address-meta'));
      if (v) return v;
    }
    return '';
  }

  /**
   * Saved-card path (default refresh): country lives in data-address-json, not cascade.
   * Cascade inputs stay hidden/stale while a saved card is selected.
   */
  function countryFromSelectedCard() {
    var widget = document.querySelector('[data-shipping-checkout-address]')
      || document.querySelector('.w-shipping-checkout-address');
    if (!widget) return '';
    var card = widget.querySelector('[data-address-card].is-selected');
    if (!card) {
      var radio = widget.querySelector('[data-address-radio]:checked');
      card = radio && radio.closest ? radio.closest('[data-address-card]') : null;
    }
    if (!card) return '';
    try {
      var payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
      return iso2(payload.country_code || payload.country);
    } catch (e) {
      return '';
    }
  }

  function shippingEditorOpen() {
    var editor = document.querySelector('[data-shipping-checkout-address] [data-shipping-editor], [data-shipping-checkout-address] [data-address-editor], .w-shipping-checkout-address [data-shipping-editor], .w-shipping-checkout-address [data-address-editor]');
    if (!editor) return false;
    if (editor.hasAttribute('hidden') || editor.hidden) return false;
    var style = window.getComputedStyle ? window.getComputedStyle(editor) : null;
    if (style && (style.display === 'none' || style.visibility === 'hidden')) return false;
    return true;
  }

  function countryFromShippingCascade() {
    var scopes = [
      document.querySelector('[data-shipping-address-cascade]'),
      document.querySelector('.w-shipping-checkout-address [data-w-address]'),
      document.querySelector('[data-w-address][data-address-config*="checkout-shipping-address"]'),
      document.querySelector('.w-shipping-checkout-address'),
      document.querySelector('[data-checkout-root]'),
      document.querySelector('.weline-checkout')
    ];
    var shippingSelectors = [
      '[name="country_code"]',
      '[data-address-meta="country_code"]',
      '[name="shipping_address[country_code]"]',
      '[data-address-country]',
      '[data-theme-address-country]'
    ];
    for (var s = 0; s < scopes.length; s++) {
      var hit = readIsoFrom(scopes[s], shippingSelectors);
      if (hit) return hit;
    }
    return '';
  }

  /**
   * Billing country drives VAT; when billing_same, use shipping.
   * Default selected card → data-address-json; new/edit form → cascade.
   * Must NOT read header delivery picker / other page-level country_code inputs.
   */
  function countryFromCheckout() {
    var billingSame = document.querySelector('[data-billing-same]');
    var useShipping = !billingSame || !!billingSame.checked;
    if (!useShipping) {
      var billingIso = readIsoFrom(document, [
        '[data-billing-field][name="billing_country_code"]',
        '[name="billing_country_code"]',
        '[name="billing_address[country_code]"]'
      ]);
      if (billingIso) return billingIso;
    }

    if (shippingEditorOpen()) {
      var cascadeIso = countryFromShippingCascade();
      if (cascadeIso) return cascadeIso;
      return countryFromSelectedCard();
    }

    var cardIso = countryFromSelectedCard();
    if (cardIso) return cardIso;
    return countryFromShippingCascade();
  }

  function sync() {
    var c = countryFromCheckout();
    var show = !!(c && eu.indexOf(c) >= 0);
    root.hidden = !show;
    var host = root.closest('[data-tax-identity-host]');
    if (host) host.hidden = !show;
    if (!show) {
      var input = root.querySelector('[data-tax-vat-id]');
      if (input) input.value = '';
      var details = root.querySelector('[data-tax-identity-details]');
      if (details) details.open = false;
    }
  }

  document.addEventListener('change', sync, true);
  document.addEventListener('input', sync, true);
  document.addEventListener('weline:checkout:address-updated', sync);
  document.addEventListener('weline:address:multi-change', sync);
  sync();
})();
});
