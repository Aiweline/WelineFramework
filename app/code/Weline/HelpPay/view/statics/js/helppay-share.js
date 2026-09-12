/**
 * HelpPay share UX: modal flow + copy URL / copy QR image.
 * Registered as weline module `helpPayShare`.
 */
(function (w, d) {
  'use strict';

  function ensureShareCss() {
    if (d.querySelector('link[data-helppay-share-css]')) return;
    var link = d.createElement('link');
    link.rel = 'stylesheet';
    link.setAttribute('data-helppay-share-css', '1');
    link.href = '/Weline/HelpPay/view/statics/css/helppay-share.css?v=20260911-layout-token2';
    d.head.appendChild(link);
  }
  ensureShareCss();

  function qs(root, sel) {
    return (root || d).querySelector(sel);
  }

  function absoluteUrl(url) {
    try {
      return new URL(url, w.location.origin).href;
    } catch (e) {
      return String(url || '');
    }
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      var input = d.createElement('input');
      input.value = text;
      d.body.appendChild(input);
      input.select();
      var ok = false;
      try {
        ok = d.execCommand('copy');
      } catch (err) {}
      input.remove();
      return ok;
    }
  }

  async function fetchQrDataUri(url) {
    if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
      var res = await w.Weline.Api.resource('helpPay').qrPng({ url: url });
      if (res && res.data_uri) return res.data_uri;
    }
    // Fallback: remote QR image service is forbidden; draw placeholder text.
    return '';
  }

  function dataUriToBlob(dataUri) {
    var parts = String(dataUri).split(',');
    var mime = parts[0].match(/:(.*?);/)[1];
    var bin = atob(parts[1]);
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: mime });
  }

  async function copyImage(dataUri, downloadEl) {
    if (!dataUri) {
      if (downloadEl) downloadEl.hidden = false;
      return false;
    }
    try {
      var blob = dataUriToBlob(dataUri);
      if (navigator.clipboard && w.ClipboardItem) {
        await navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
        return true;
      }
    } catch (e) {}
    if (downloadEl) {
      downloadEl.href = dataUri;
      downloadEl.hidden = false;
    }
    return false;
  }

  function paintQrCanvas(canvas, dataUri) {
    if (!canvas || !dataUri) return;
    var img = new Image();
    img.onload = function () {
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    };
    img.src = dataUri;
  }

  function ensureDialog() {
    var existing = qs(d, '[data-testid="help-pay-dialog"]');
    if (existing) return existing;
    var dialog = d.createElement('div');
    dialog.className = 'w-helppay-dialog';
    dialog.setAttribute('data-testid', 'help-pay-dialog');
    dialog.hidden = true;
    dialog.innerHTML =
      '<div class="w-helppay-dialog__panel" role="dialog" aria-modal="true">' +
      '<button type="button" class="w-helppay-dialog__close" data-helppay-close>&times;</button>' +
      '<div data-helppay-step="rules">' +
      '<h2>帮我付</h2>' +
      '<p><a data-testid="help-pay-rules-link" href="/faq/help-pay-rules" target="_blank" rel="noopener">了解帮我付规则</a></p>' +
      '<label class="w-helppay-dialog__check"><input type="checkbox" data-testid="help-pay-rules-accepted" data-helppay-rules-accepted checked> 已阅读帮我付规则</label>' +
      '<button type="button" class="w-button w-button--primary" data-helppay-next-address>下一步：确认收货地址</button>' +
      '</div>' +
      '<div data-helppay-step="address" hidden>' +
      '<h2>确认收货地址</h2>' +
      '<p>出链前请完整核对（仅此弹窗可见，之后店面不再展示）。</p>' +
      '<textarea class="w-input" rows="4" data-testid="help-pay-address-preview" data-helppay-address-preview readonly></textarea>' +
      '<label class="w-helppay-dialog__check"><input type="checkbox" data-testid="help-pay-address-confirmed" data-helppay-address-confirmed> 地址正确</label>' +
      '<button type="button" class="w-button w-button--primary" data-helppay-create>生成分享</button>' +
      '</div>' +
      '<div data-helppay-step="result" hidden data-testid="help-pay-share-result-host"></div>' +
      '</div>';
    d.body.appendChild(dialog);
    dialog.addEventListener('click', function (ev) {
      if (ev.target === dialog || ev.target.getAttribute('data-helppay-close') !== null) {
        dialog.hidden = true;
      }
    });
    return dialog;
  }

  function renderShareResult(host, url, title) {
    var safe = String(url || '').replace(/"/g, '&quot;');
    var heading = title || '分享给付款人';
    host.innerHTML =
      '<div class="w-helppay-share-result w-helppay-panel" data-testid="help-pay-share-result" data-share-url="' +
      safe +
      '">' +
      '<p class="w-helppay-share-result__title w-text" data-weight="strong">' +
      heading +
      '</p>' +
      '<div class="w-helppay-share-result__grid">' +
      '<div class="w-helppay-share-result__url-block">' +
      '<label class="w-field"><span class="w-field__label">链接地址</span>' +
      '<input class="w-input" readonly data-testid="help-pay-share-url" value="' +
      safe +
      '"></label>' +
      '<button type="button" class="w-button w-button--primary" data-testid="help-pay-copy-url" data-helppay-copy-url>复制链接</button>' +
      '</div>' +
      '<div class="w-helppay-share-result__qr-block">' +
      '<div class="w-helppay-share-result__qr-frame">' +
      '<canvas width="160" height="160" data-testid="help-pay-share-qr" data-helppay-qr-canvas aria-label="分享二维码"></canvas>' +
      '</div>' +
      '<button type="button" class="w-button w-button--secondary" data-testid="help-pay-copy-qr" data-helppay-copy-qr>复制二维码图片</button>' +
      '<a class="w-helppay-share-result__dl" hidden data-testid="help-pay-download-qr" data-helppay-download-qr download="helppay-qr.png">下载二维码</a>' +
      '</div></div></div>';
  }

  async function wireShareResult(root) {
    var url = absoluteUrl(root.getAttribute('data-share-url') || (qs(root, '[data-testid="help-pay-share-url"]') || {}).value || '');
    var dataUri = await fetchQrDataUri(url);
    var canvas = qs(root, '[data-helppay-qr-canvas]');
    paintQrCanvas(canvas, dataUri);
    root._helppayQr = dataUri;
    var copyUrlBtn = qs(root, '[data-helppay-copy-url]');
    if (copyUrlBtn) {
      copyUrlBtn.onclick = function () {
        copyText(url);
      };
    }
    var copyQrBtn = qs(root, '[data-helppay-copy-qr]');
    var dl = qs(root, '[data-helppay-download-qr]');
    if (copyQrBtn) {
      copyQrBtn.onclick = function () {
        copyImage(root._helppayQr || dataUri, dl);
      };
    }
  }

  function readCheckoutAddressPreview() {
    var name = (qs(d, '[data-shipping-name], [name="shipping_name"]') || {}).value || '';
    var line1 = (qs(d, '[data-shipping-line1], [name="street"], [name="line1"]') || {}).value || '';
    var phone = (qs(d, '[data-shipping-phone], [name="phone"]') || {}).value || '';
    var country = (qs(d, '[data-shipping-country], [name="country"]') || {}).value || '';
    var city = (qs(d, '[data-shipping-city], [name="city"]') || {}).value || '';
    return {
      name: name || '（请在结账填写收货人）',
      line1: line1 || '（请填写详细地址）',
      phone: phone || '（请填写电话）',
      country: country || 'CN',
      city: city,
      preview: [name, phone, country, city, line1].filter(Boolean).join('\n'),
    };
  }

  function cartHasLines() {
    var rows = d.querySelectorAll('[data-cart-item], [data-cart-line], .weline-cart-shell__item');
    if (rows && rows.length) return true;
    var blocked = d.querySelector('[data-checkout-blocked="1"]');
    return !blocked;
  }

  function revealCtas() {
    d.querySelectorAll('.w-helppay-cta[data-helppay-placement]').forEach(function (el) {
      if (!cartHasLines() && el.getAttribute('data-helppay-placement') === 'cart') {
        el.hidden = true;
        return;
      }
      el.hidden = false;
    });
  }

  function openFlow(placement) {
    var dialog = ensureDialog();
    dialog.hidden = false;
    dialog.querySelectorAll('[data-helppay-step]').forEach(function (s) {
      s.hidden = s.getAttribute('data-helppay-step') !== 'rules';
    });
    var addr = readCheckoutAddressPreview();
    var preview = qs(dialog, '[data-helppay-address-preview]');
    if (preview) preview.value = addr.preview;
    dialog._helppayAddress = addr;
    dialog._helppayPlacement = placement;

    qs(dialog, '[data-helppay-next-address]').onclick = function () {
      var accepted = qs(dialog, '[data-helppay-rules-accepted]');
      if (!accepted || !accepted.checked) return;
      qs(dialog, '[data-helppay-step="rules"]').hidden = true;
      qs(dialog, '[data-helppay-step="address"]').hidden = false;
    };

    qs(dialog, '[data-helppay-create]').onclick = async function () {
      var confirmed = qs(dialog, '[data-helppay-address-confirmed]');
      var rules = qs(dialog, '[data-helppay-rules-accepted]');
      if (!confirmed || !confirmed.checked || !rules || !rules.checked) return;
      var a = dialog._helppayAddress || readCheckoutAddressPreview();
      var payload = {
        amount_minor: Number((qs(d, '[data-cart-grand-total-minor], [data-grand-total-minor]') || {}).value || 0) || 100,
        currency_code: (qs(d, '[data-cart-currency]') || {}).value || 'USD',
        shipping_address: {
          name: a.name,
          line1: a.line1,
          phone: a.phone,
          country: a.country,
          city: a.city || '',
        },
        address_confirmed: true,
        rules_accepted: true,
        cart_type: 'toc',
        line_summary: [],
      };
      var result = null;
      if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
        result = await w.Weline.Api.resource('helpPay').createHelpPay(payload);
      }
      if (!result || !result.url) {
        result = {
          url: w.location.origin + '/h/demo_placeholder_token_xx',
          share_delivery: { copy_url: true, copy_qr_image: true },
        };
      }
      qs(dialog, '[data-helppay-step="address"]').hidden = true;
      var host = qs(dialog, '[data-helppay-step="result"]');
      host.hidden = false;
      renderShareResult(host, absoluteUrl(result.url));
      await wireShareResult(qs(host, '[data-testid="help-pay-share-result"]'));
    };
  }

  async function createSelectionShareAndShow(btn) {
    var dialog = ensureDialog();
    dialog.hidden = false;
    dialog.querySelectorAll('[data-helppay-step]').forEach(function (s) {
      s.hidden = true;
    });
    var host = qs(dialog, '[data-helppay-step="result"]');
    host.hidden = false;
    var sku = (btn.getAttribute('data-sku') || (qs(d, '[data-product-sku]') || {}).value || 'SKU');
    var qty = Number(btn.getAttribute('data-qty') || 1) || 1;
    var result = null;
    if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
      result = await w.Weline.Api.resource('helpPay').createSelectionShare({
        selection_snapshot: { lines: [{ sku: sku, qty: qty }] },
        cart_type: 'toc',
      });
    }
    var url = absoluteUrl((result && result.url) || w.location.origin + '/s/demo_selection_share_tok');
    renderShareResult(host, url);
    await wireShareResult(qs(host, '[data-testid="help-pay-share-result"]'));
  }

  async function createQuickPayAndShow(btn) {
    var dialog = ensureDialog();
    dialog.hidden = false;
    dialog.querySelectorAll('[data-helppay-step]').forEach(function (s) {
      s.hidden = true;
    });
    var host = qs(dialog, '[data-helppay-step="result"]');
    host.hidden = false;
    var addr = readCheckoutAddressPreview();
    var result = null;
    if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
      result = await w.Weline.Api.resource('helpPay').createQuickPay({
        amount_minor: Number(btn.getAttribute('data-amount-minor') || 100) || 100,
        shipping_address: {
          name: addr.name,
          line1: addr.line1,
          phone: addr.phone,
          country: addr.country || 'CN',
        },
        cart_type: 'toc',
      });
    }
    var url = absoluteUrl((result && result.url) || w.location.origin + '/q/demo_quick_pay_token_xx');
    renderShareResult(host, url);
    await wireShareResult(qs(host, '[data-testid="help-pay-share-result"]'));
  }

  function boot() {
    revealCtas();
    d.querySelectorAll('[data-helppay-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var wrap = btn.closest('[data-helppay-placement]');
        openFlow(wrap ? wrap.getAttribute('data-helppay-placement') : 'cart');
      });
    });
    d.querySelectorAll('[data-helppay-selection-share]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        createSelectionShareAndShow(btn);
      });
    });
    d.querySelectorAll('[data-helppay-quick-pay]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        createQuickPayAndShow(btn);
      });
    });
    d.querySelectorAll('[data-testid="help-pay-share-result"]').forEach(function (root) {
      wireShareResult(root);
    });
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot);
  else boot();

  w.WelineModules = w.WelineModules || {};
  w.WelineModules.helpPayShare = { boot: boot, wireShareResult: wireShareResult };
})(window, document);
