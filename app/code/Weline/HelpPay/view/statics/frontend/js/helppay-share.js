/**
 * HelpPay share UX: modal flow + copy URL / copy QR image.
 * Registered as weline module `helpPayShare`.
 */
(function (w, d) {
  'use strict';

  function resolveShareCssHref() {
    // Bust token on Module:: so PROD resolveStaticPath / flat publish picks it up.
    var modulePath = 'Weline_HelpPay::css/helppay-share.css?v=20260925-no-dev-css1';
    var loader = w.Weline && w.Weline.loader;
    if (loader && typeof loader.resolveStaticPath === 'function') {
      var resolved = loader.resolveStaticPath(modulePath);
      if (resolved) {
        return resolved;
      }
    }
    // Sibling of this script in the same published tree — never DEV /Weline/*/view/statics/.
    var cur = d.currentScript && d.currentScript.src;
    if (cur) {
      var sibling = cur.replace(/\/(?:frontend\/)?js\/helppay-share\.js(\?.*)?$/i, '/css/helppay-share.css$1');
      if (sibling !== cur) {
        return sibling;
      }
      sibling = cur.replace(/\/js\/helppay-share\.js(\?.*)?$/i, '/css/helppay-share.css$1');
      if (sibling !== cur) {
        return sibling;
      }
    }
    return '/static/Weline/HelpPay/css/helppay-share.css?v=20260925-no-dev-css1';
  }

  function ensureShareCss() {
    var href = resolveShareCssHref();
    var existing = d.querySelector('link[data-helppay-share-css]');
    if (existing) {
      if (existing.getAttribute('href') !== href) {
        existing.setAttribute('href', href);
      }
      return;
    }
    var link = d.createElement('link');
    link.rel = 'stylesheet';
    link.setAttribute('data-helppay-share-css', '1');
    link.href = href;
    d.head.appendChild(link);
  }
  ensureShareCss();

  /** @type {Record<string, string>} */
  var shareI18n = {};

  function qs(root, sel) {
    return (root || d).querySelector(sel);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /** Visitor 像素过程事件（事件链中间步） */
  function trackPixel(name, payload, el) {
    try {
      if (w.WelinePixel && typeof w.WelinePixel.track === 'function') {
        w.WelinePixel.track(name, payload || {}, { element: el || null, keepalive: true });
      }
    } catch (e) {}
  }

  function parseShareI18n(raw) {
    if (!raw) return {};
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function mergeShareI18n(el) {
    var node = el;
    if (!node || !node.getAttribute || !node.getAttribute('data-helppay-i18n')) {
      node = qs(d, '[data-helppay-i18n]');
    }
    if (node && node.getAttribute) {
      shareI18n = Object.assign({}, shareI18n, parseShareI18n(node.getAttribute('data-helppay-i18n')));
    }
    return shareI18n;
  }

  function t(key, fallback) {
    var value = shareI18n && shareI18n[key];
    if (value != null && String(value) !== '') {
      return String(value);
    }
    return fallback;
  }

  function absoluteUrl(url) {
    try {
      return new URL(url, w.location.origin).href;
    } catch (e) {
      return String(url || '');
    }
  }

  function humanizeApiError(err, context) {
    var msg = '';
    if (err && typeof err === 'object') {
      msg = String(err.message || err.error || err.code || '');
      if (!msg && err.data) msg = String(err.data.message || err.data.error || '');
    } else if (err) {
      msg = String(err);
    }
    var ctx = String(context || '');
    if (/not exposed to frontend worker/i.test(msg)) {
      return ctx === 'share'
        ? '分享服务暂未开通，请刷新页面后重试。'
        : '快捷购买服务暂未开通，请刷新页面后重试，或使用加入购物车。';
    }
    if (/Unknown frontend worker param/i.test(msg)) {
      return '分享参数异常，请刷新页面后重试。若仍失败，请稍后再试。';
    }
    if (/helppay_toc_only/i.test(msg)) {
      return ctx === 'share'
        ? '规格分享目前仅支持零售模式，请切换到「零售」后再试。'
        : '快捷购买仅支持零售模式，请切换到「零售」后再试。';
    }
    if (/helppay_shipping_incomplete/i.test(msg)) {
      return t('addressIncomplete', '请先完善收货地址后再生成快捷购买链接。');
    }
    if (/helppay_missing_weight|missing_weight/i.test(msg)) {
      return t(
        'missingWeight',
        '购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。'
      );
    }
    if (/helppay_shipping_unavailable|helppay_shipping_mismatch|helppay_shipping_required/i.test(msg)) {
      return t('shippingUnavailable', '该地址暂无可用配送方式，请更换地址后重试。');
    }
    if (/helppay_product_required/i.test(msg)) {
      return t('cannotComplete', '无法完成操作');
    }
    if (/Frontend worker operation is not allowed/i.test(msg)) {
      return ctx === 'payer'
        ? t('payFailed', '无法发起支付，请刷新后重试。')
        : ctx === 'share'
          ? '分享服务暂未开通，请刷新页面后重试。'
          : '快捷购买服务暂未开通，请刷新页面后重试，或使用加入购物车。';
    }
    if (/helppay_payment_start_failed|helppay_payment_method_unavailable|helppay_payment_unavailable|payer_type_not_allowed|付款人类型不能使用/i.test(msg)) {
      return t('payFailed', '无法发起支付，请刷新后重试。');
    }
    if (/capability_denied|auth_error|403/i.test(msg)) {
      return ctx === 'share'
        ? '当前无法生成分享链接，请稍后重试。'
        : ctx === 'payer'
          ? t('payFailed', '无法发起支付，请刷新后重试。')
          : '当前无法完成快捷购买，请稍后重试或改用结账流程。';
    }
    if (ctx === 'payer' && /RuntimeException|in \/Users\/|PaymentService\.php/i.test(msg)) {
      return t('payFailed', '无法发起支付，请刷新后重试。');
    }
    return msg || (ctx === 'payer' ? t('payFailed', '无法发起支付，请刷新后重试。') : '生成链接失败，请稍后重试。');
  }

  async function copyText(text) {
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await Promise.race([
          navigator.clipboard.writeText(text),
          new Promise(function (_, reject) {
            setTimeout(function () {
              reject(new Error('clipboard_timeout'));
            }, 900);
          }),
        ]);
        return true;
      }
    } catch (e) {
      /* fall through to execCommand */
    }
    try {
      var input = d.createElement('input');
      input.value = text;
      input.setAttribute('readonly', 'readonly');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      d.body.appendChild(input);
      input.focus();
      input.select();
      var ok = false;
      try {
        ok = d.execCommand('copy');
      } catch (err) {}
      input.remove();
      return !!ok;
    } catch (e2) {
      return false;
    }
  }

  async function fetchQrDataUri(url) {
    try {
      if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
        var res = await w.Weline.Api.resource('helpPay').qrPng({ url: url });
        if (res && res.data_uri) return res.data_uri;
      }
    } catch (e) {}
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

  function flashCopyFeedback(btn, ok, tipEl) {
    if (!btn) return;
    var original = btn.getAttribute('data-helppay-label') || btn.textContent || '';
    if (!btn.getAttribute('data-helppay-label')) {
      btn.setAttribute('data-helppay-label', original);
    }
    var prevTone = btn.getAttribute('data-helppay-prev-tone') || btn.getAttribute('data-tone') || 'primary';
    var prevVariant = btn.getAttribute('data-helppay-prev-variant');
    if (!btn.hasAttribute('data-helppay-prev-tone')) {
      btn.setAttribute('data-helppay-prev-tone', btn.getAttribute('data-tone') || 'primary');
      if (btn.getAttribute('data-variant')) {
        btn.setAttribute('data-helppay-prev-variant', btn.getAttribute('data-variant'));
      }
      prevTone = btn.getAttribute('data-helppay-prev-tone');
      prevVariant = btn.getAttribute('data-helppay-prev-variant');
    }
    var okText = btn.getAttribute('data-helppay-copied-label') || '已复制';
    var failText = btn.getAttribute('data-helppay-fail-label') || '复制失败';
    if (btn._helppayCopyTimer) {
      clearTimeout(btn._helppayCopyTimer);
      btn._helppayCopyTimer = null;
    }
    btn.disabled = false;
    btn.textContent = ok ? okText : failText;
    btn.setAttribute('data-tone', ok ? 'success' : 'danger');
    if (prevVariant === 'outline') {
      btn.setAttribute('data-variant', 'outline');
    }
    btn.classList.toggle('is-helppay-copied', !!ok);
    btn.classList.toggle('is-helppay-copy-failed', !ok);
    if (tipEl) {
      var tipOk = tipEl.getAttribute('data-helppay-tip-copied') || tipEl.textContent || '';
      var tipFail = tipEl.getAttribute('data-helppay-tip-fail') || '复制没成功，请再点一次试试';
      tipEl.textContent = ok ? tipOk : tipFail;
      tipEl.hidden = false;
      tipEl.setAttribute('data-tone', ok ? 'success' : 'danger');
      tipEl.setAttribute('data-state', ok ? 'copied' : 'failed');
    }
    // 按钮短时反馈后还原；小贴士常驻不消失
    btn._helppayCopyTimer = setTimeout(function () {
      btn.textContent = btn.getAttribute('data-helppay-label') || original;
      btn.setAttribute('data-tone', prevTone || 'primary');
      if (prevVariant) {
        btn.setAttribute('data-variant', prevVariant);
      } else {
        btn.removeAttribute('data-variant');
      }
      btn.classList.remove('is-helppay-copied', 'is-helppay-copy-failed');
      btn._helppayCopyTimer = null;
    }, 2000);
  }

  function markCopyPending(btn, tipEl) {
    if (!btn) return;
    if (!btn.getAttribute('data-helppay-label')) {
      btn.setAttribute('data-helppay-label', btn.textContent || '');
    }
    if (!btn.hasAttribute('data-helppay-prev-tone')) {
      btn.setAttribute('data-helppay-prev-tone', btn.getAttribute('data-tone') || 'primary');
      if (btn.getAttribute('data-variant')) {
        btn.setAttribute('data-helppay-prev-variant', btn.getAttribute('data-variant'));
      }
    }
    btn.disabled = true;
    btn.textContent = '复制中…';
    if (tipEl) {
      tipEl.setAttribute('data-tone', 'muted');
      tipEl.setAttribute('data-state', 'pending');
    }
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
    if (existing) {
      hydrateDialogCopy(existing);
      return existing;
    }
    var dialog = d.createElement('div');
    dialog.className = 'w-helppay-dialog';
    dialog.setAttribute('data-testid', 'help-pay-dialog');
    dialog.hidden = true;
    dialog.innerHTML =
      '<div class="w-helppay-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="helppay-dialog-title">' +
      '<button type="button" class="w-helppay-dialog__close" data-helppay-close aria-label="' +
      escapeHtml(t('close', '关闭')) +
      '">&times;</button>' +
      '<div class="w-helppay-dialog__steps" data-helppay-steps aria-hidden="true">' +
      '<span class="w-helppay-dialog__step is-active" data-helppay-step-dot="rules">' +
      escapeHtml(t('dialogStepRules', '规则')) +
      '</span>' +
      '<span class="w-helppay-dialog__step-sep" aria-hidden="true"></span>' +
      '<span class="w-helppay-dialog__step" data-helppay-step-dot="address">' +
      escapeHtml(t('dialogStepAddress', '地址')) +
      '</span>' +
      '<span class="w-helppay-dialog__step-sep" data-helppay-step-sep="shipping" aria-hidden="true"></span>' +
      '<span class="w-helppay-dialog__step" data-helppay-step-dot="shipping" hidden>' +
      escapeHtml(t('dialogStepShipping', '物流')) +
      '</span>' +
      '<span class="w-helppay-dialog__step-sep" data-helppay-step-sep="payment" aria-hidden="true"></span>' +
      '<span class="w-helppay-dialog__step" data-helppay-step-dot="payment" hidden>' +
      escapeHtml(t('dialogStepPay', '付款')) +
      '</span>' +
      '<span class="w-helppay-dialog__step-sep" aria-hidden="true"></span>' +
      '<span class="w-helppay-dialog__step" data-helppay-step-dot="result">' +
      escapeHtml(t('dialogStepShare', '分享')) +
      '</span>' +
      '</div>' +
      '<div data-helppay-step="rules" class="w-helppay-dialog__body">' +
      '<header class="w-helppay-dialog__header">' +
      '<h2 class="w-helppay-dialog__title" id="helppay-dialog-title" data-helppay-i18n-text="dialogTitle">' +
      escapeHtml(t('dialogTitle', '找朋友代付')) +
      '</h2>' +
      '<p class="w-helppay-dialog__lead" data-helppay-i18n-text="dialogLead">' +
      escapeHtml(t('dialogLead', '生成付款链接发给朋友。订单仍归你，朋友只负责付款。')) +
      '</p>' +
      '</header>' +
      '<ul class="w-helppay-dialog__bullets" role="list">' +
      '<li data-helppay-i18n-text="dialogBulletOwner">' +
      escapeHtml(t('dialogBulletOwner', '订单与收货信息归你，不会转给付款人')) +
      '</li>' +
      '<li data-helppay-i18n-text="dialogBulletPay">' +
      escapeHtml(t('dialogBulletPay', '朋友打开链接后完成支付即可')) +
      '</li>' +
      '<li data-helppay-i18n-text="dialogBulletNoDiscount">' +
      escapeHtml(t('dialogBulletNoDiscount', '代付不可用优惠券与积分')) +
      '</li>' +
      '</ul>' +
      '<p class="w-helppay-dialog__rules-line">' +
      '<a class="w-helppay-dialog__rules-link" data-testid="help-pay-rules-link" href="/faq/help-pay-rules" target="_blank" rel="noopener" data-helppay-i18n-text="dialogRulesLink">' +
      escapeHtml(t('dialogRulesLink', '查看完整规则')) +
      '</a>' +
      '</p>' +
      '<label class="w-helppay-dialog__check">' +
      '<input type="checkbox" data-testid="help-pay-rules-accepted" data-helppay-rules-accepted checked> ' +
      '<span data-helppay-i18n-text="dialogRulesAccepted">' +
      escapeHtml(t('dialogRulesAccepted', '我已阅读并同意帮我付规则')) +
      '</span></label>' +
      '<div class="w-helppay-dialog__actions">' +
      '<button type="button" class="w-button w-helppay-dialog__primary" data-helppay-next-address data-helppay-i18n-text="dialogNextAddress">' +
      escapeHtml(t('dialogNextAddress', '下一步：确认收货地址')) +
      '</button>' +
      '</div>' +
      '</div>' +
      '<div data-helppay-step="address-pick" class="w-helppay-dialog__body" hidden data-testid="helppay-address-pick">' +
      '<header class="w-helppay-dialog__header">' +
      '<h2 class="w-helppay-dialog__title" data-helppay-address-title data-helppay-i18n-text="dialogAddressTitle">' +
      escapeHtml(t('dialogAddressTitle', '确认收货地址')) +
      '</h2>' +
      '<p class="w-text w-helppay-dialog__lead" data-tone="muted" data-size="sm" data-helppay-address-hint data-helppay-i18n-text="confirmHelpPayHint">' +
      escapeHtml(t('confirmHelpPayHint', '请填写、选择或更换收货地址后再生成代付链接。')) +
      '</p>' +
      '</header>' +
      '<div class="w-helppay-address-pick__body" data-helppay-address-mount></div>' +
      '<p class="w-text" data-tone="danger" data-size="sm" data-helppay-address-msg hidden role="alert"></p>' +
      '<div class="w-helppay-dialog__actions">' +
      '<button type="button" class="w-button w-helppay-dialog__primary" data-helppay-confirm-quick data-testid="helppay-confirm-quick" data-helppay-i18n-text="confirmHelpPay">' +
      escapeHtml(t('confirmHelpPay', '确认并生成代付链接')) +
      '</button>' +
      '</div>' +
      '</div>' +
      '<div data-helppay-step="shipping-pick" class="w-helppay-dialog__body" hidden data-testid="helppay-shipping-pick">' +
      '<header class="w-helppay-dialog__header">' +
      '<h2 class="w-helppay-dialog__title">' +
      escapeHtml(t('dialogShippingTitle', '选择配送方式')) +
      '</h2>' +
      '<p class="w-text w-helppay-dialog__lead" data-tone="muted" data-size="sm">' +
      escapeHtml(t('dialogShippingHint', '运费按收货地址报价，仅用于本单快捷购买，不影响结账页。')) +
      '</p>' +
      '</header>' +
      '<div class="w-stack" style="--w-gap:var(--weline-space-2);" data-helppay-shipping-list data-testid="helppay-shipping-list"></div>' +
      '<p class="w-text" data-tone="danger" data-size="sm" data-helppay-shipping-msg hidden role="alert"></p>' +
      '<div class="w-helppay-dialog__actions">' +
      '<button type="button" class="w-button" data-variant="outline" data-tone="neutral" data-helppay-back-address data-testid="helppay-back-address">' +
      escapeHtml(t('backToAddress', '返回地址')) +
      '</button>' +
      '<button type="button" class="w-button w-helppay-dialog__primary" data-helppay-confirm-shipping data-testid="helppay-confirm-shipping">' +
      escapeHtml(t('confirmShipping', '下一步：付款')) +
      '</button>' +
      '</div>' +
      '</div>' +
      '<div data-helppay-step="payment" class="w-helppay-dialog__body" hidden data-testid="helppay-payment-step">' +
      '<header class="w-helppay-dialog__header">' +
      '<h2 class="w-helppay-dialog__title">' +
      escapeHtml(t('dialogPaymentTitle', '确认支付')) +
      '</h2>' +
      '<p class="w-text w-helppay-dialog__lead" data-tone="muted" data-size="sm">' +
      escapeHtml(t('dialogPaymentHint', '在弹窗内完成支付。支付商可能打开小窗，完成后回到此处。')) +
      '</p>' +
      '</header>' +
      '<div class="w-helppay-panel" data-helppay-payment-summary data-testid="helppay-payment-summary"></div>' +
      '<p class="w-text" data-tone="danger" data-size="sm" data-helppay-payment-msg hidden role="alert"></p>' +
      '<div class="w-helppay-dialog__actions">' +
      '<button type="button" class="w-button" data-variant="outline" data-tone="neutral" data-helppay-back-shipping data-testid="helppay-back-shipping">' +
      escapeHtml(t('backToShipping', '返回物流')) +
      '</button>' +
      '<button type="button" class="w-button w-helppay-dialog__primary" data-helppay-start-pay data-testid="helppay-start-pay">' +
      escapeHtml(t('confirmPay', '确认支付')) +
      '</button>' +
      '</div>' +
      '<details class="w-helppay-quick__cross-device" data-helppay-cross-device hidden data-testid="helppay-cross-device">' +
      '<summary class="w-text" data-size="sm">' +
      escapeHtml(t('crossDevicePay', '换设备支付（链接）')) +
      '</summary>' +
      '<p class="w-text" data-size="sm" data-tone="muted" data-helppay-pay-url-text></p>' +
      '</details>' +
      '</div>' +
      '<div data-helppay-step="result" class="w-helppay-dialog__body" hidden data-testid="help-pay-share-result-host"></div>' +
      '<div data-helppay-step="error" class="w-helppay-dialog__body" hidden data-testid="help-pay-error-host"></div>' +
      '</div>';
    d.body.appendChild(dialog);
    dialog.addEventListener('click', function (ev) {
      if (ev.target === dialog || ev.target.getAttribute('data-helppay-close') !== null) {
        hideDialog(dialog);
      }
    });
    return dialog;
  }

  /**
   * Native showModal() dialogs own the browser top layer — body z-index cannot
   * paint above them. Host HelpPay inside the open dialog (same as Theme toast),
   * then elevate z-index to host/sibling peak + 1 (Theme stack.apply).
   */
  function resolveOverlayHost(from) {
    var start = from instanceof Element ? from : null;
    if (!start && d.activeElement instanceof Element) {
      start = d.activeElement;
    }
    try {
      var nested = start && start.closest ? start.closest('dialog') : null;
      if (nested && nested.open) return nested;
    } catch (e0) {}
    try {
      var modal = d.querySelector('dialog:modal');
      if (modal) return modal;
    } catch (e1) {}
    var openNative = d.querySelectorAll('dialog[open]');
    if (openNative.length) {
      return openNative[openNative.length - 1];
    }
    var openShell = d.querySelector('.w-dialog[data-state="open"]:not(dialog)');
    if (openShell) return openShell;
    return d.body;
  }

  function readNumericZ(el) {
    if (!el || el === d.body || el === d.documentElement) return 0;
    try {
      var z = parseInt((w.getComputedStyle(el).zIndex || '').trim(), 10);
      return isFinite(z) ? z : 0;
    } catch (e) {
      return 0;
    }
  }

  function elevateOverlay(dialog, host, origin) {
    if (!dialog) return;
    dialog.setAttribute('data-w-floating-portal', 'true');
    try {
      var stack = w.Weline && w.Weline.UI && w.Weline.UI.stack;
      if (stack && typeof stack.apply === 'function') {
        stack.apply(dialog, host, origin || null);
        return;
      }
    } catch (e) {}
    var floor = 80;
    var base = floor;
    if (host && host !== d.body) {
      base = Math.max(base, readNumericZ(host));
    } else {
      d.querySelectorAll(
        'dialog[open], .w-dialog[data-state="open"], .w-overlay, .w-drawer[data-state="open"]'
      ).forEach(function (el) {
        base = Math.max(base, readNumericZ(el));
      });
    }
    var peak = base + 1;
    var scope = host instanceof Element ? host : d.body;
    if (scope && scope.querySelectorAll) {
      scope.querySelectorAll('[data-w-floating-portal]').forEach(function (sib) {
        if (sib === dialog) return;
        var z = readNumericZ(sib);
        if (z >= peak) peak = z + 1;
      });
    }
    dialog.style.zIndex = String(peak);
  }

  function restoreHostOverflow(host) {
    if (!(host instanceof HTMLElement) || !host.hasAttribute('data-helppay-overflow-prev')) {
      return;
    }
    host.style.overflow = host.getAttribute('data-helppay-overflow-prev') || '';
    host.removeAttribute('data-helppay-overflow-prev');
  }

  function revealDialog(dialog, from) {
    if (!dialog) return dialog;
    var host = resolveOverlayHost(from);
    var prev = dialog._helppayStackHost;
    if (prev && prev !== host) {
      restoreHostOverflow(prev);
    }
    host.appendChild(dialog);
    if (host instanceof HTMLElement && host !== d.body) {
      if (!host.hasAttribute('data-helppay-overflow-prev')) {
        host.setAttribute('data-helppay-overflow-prev', host.style.overflow || '');
      }
      host.style.overflow = 'visible';
    }
    dialog._helppayStackHost = host;
    elevateOverlay(dialog, host, from instanceof Element ? from : null);
    dialog.hidden = false;
    return dialog;
  }

  function hideDialog(dialog) {
    if (!dialog) return;
    dialog.hidden = true;
    try {
      var stack = w.Weline && w.Weline.UI && w.Weline.UI.stack;
      if (stack && typeof stack.clear === 'function') {
        stack.clear(dialog);
      }
    } catch (e) {}
    restoreHostOverflow(dialog._helppayStackHost);
    dialog._helppayStackHost = null;
    dialog.removeAttribute('data-w-floating-portal');
    dialog.style.removeProperty('z-index');
    if (dialog.parentNode !== d.body) {
      d.body.appendChild(dialog);
    }
    restorePageShippingAddressApi();
  }

  function hydrateDialogCopy(dialog) {
    if (!dialog) return;
    dialog.querySelectorAll('[data-helppay-i18n-text]').forEach(function (el) {
      var key = el.getAttribute('data-helppay-i18n-text');
      if (!key) return;
      var fallback = el.textContent || '';
      el.textContent = t(key, fallback);
    });
    var closeBtn = qs(dialog, '[data-helppay-close]');
    if (closeBtn) closeBtn.setAttribute('aria-label', t('close', '关闭'));
    var stepRules = qs(dialog, '[data-helppay-step-dot="rules"]');
    var stepAddr = qs(dialog, '[data-helppay-step-dot="address"]');
    var stepShipping = qs(dialog, '[data-helppay-step-dot="shipping"]');
    var stepPayment = qs(dialog, '[data-helppay-step-dot="payment"]');
    var stepShare = qs(dialog, '[data-helppay-step-dot="result"]');
    if (stepRules) stepRules.textContent = t('dialogStepRules', '规则');
    if (stepAddr) stepAddr.textContent = t('dialogStepAddress', '地址');
    if (stepShipping) stepShipping.textContent = t('dialogStepShipping', '物流');
    if (stepPayment) stepPayment.textContent = t('dialogStepPay', '付款');
    if (stepShare) {
      var flow = dialog.getAttribute('data-helppay-flow') || dialog._helppayFlow || 'help';
      stepShare.textContent =
        flow === 'quick' ? t('dialogStepPay', '付款') : t('dialogStepShare', '分享');
    }
  }

  function syncDialogSteps(dialog, step) {
    if (!dialog) return;
    var flow = dialog.getAttribute('data-helppay-flow') || dialog._helppayFlow || 'help';
    var isQuick = flow === 'quick';
    var map = {
      rules: 'rules',
      address: 'address',
      'address-pick': 'address',
      'shipping-pick': 'shipping',
      payment: 'payment',
      result: isQuick ? 'payment' : 'result',
      error: isQuick ? 'payment' : 'result',
    };
    var active = map[step] || 'rules';
    var order = isQuick
      ? ['rules', 'address', 'shipping', 'payment']
      : ['rules', 'address', 'result'];
    var activeIdx = order.indexOf(active);
    dialog.querySelectorAll('[data-helppay-step-dot]').forEach(function (dot) {
      var key = dot.getAttribute('data-helppay-step-dot');
      var idx = order.indexOf(key);
      var inFlow = idx > -1;
      dot.hidden = !inFlow && (key === 'shipping' || key === 'payment');
      if (key === 'result') {
        dot.hidden = isQuick;
      }
      if (key === 'rules') {
        dot.hidden = isQuick;
      }
      dot.classList.toggle('is-active', inFlow && key === active);
      dot.classList.toggle('is-done', inFlow && idx > -1 && idx < activeIdx);
    });
    dialog.querySelectorAll('[data-helppay-step-sep="shipping"],[data-helppay-step-sep="payment"]').forEach(function (sep) {
      sep.hidden = !isQuick;
    });
  }

  function showStep(dialog, step) {
    dialog.querySelectorAll('[data-helppay-step]').forEach(function (s) {
      s.hidden = s.getAttribute('data-helppay-step') !== step;
    });
    syncDialogSteps(dialog, step);
  }

  function renderError(host, message) {
    host.innerHTML =
      '<div class="w-helppay-error w-helppay-panel" data-testid="help-pay-error" data-helppay-error="1" role="alert">' +
      '<p class="w-text" data-weight="strong">' +
      escapeHtml(t('cannotComplete', '无法完成操作')) +
      '</p>' +
      '<p class="w-text" data-tone="muted" data-size="sm">' +
      escapeHtml(message || '') +
      '</p>' +
      '<button type="button" class="w-button" data-variant="outline" data-tone="neutral" data-helppay-close>' +
      escapeHtml(t('close', '关闭')) +
      '</button>' +
      '</div>';
  }

  function openErrorDialog(message, from) {
    mergeShareI18n(from || null);
    var dialog = ensureDialog();
    revealDialog(dialog, from || null);
    showStep(dialog, 'error');
    renderError(qs(dialog, '[data-helppay-step="error"]'), message);
  }

  function applyDialogFlow(dialog, flow) {
    var mode = flow === 'quick' || flow === 'share' || flow === 'help' ? flow : 'help';
    if (!dialog) return mode;
    dialog.setAttribute('data-helppay-flow', mode);
    dialog._helppayFlow = mode;
    var stepResult = qs(dialog, '[data-helppay-step-dot="result"]');
    if (stepResult) {
      stepResult.textContent =
        mode === 'quick' ? t('dialogStepPay', '付款') : t('dialogStepShare', '分享');
    }
    var stepShipping = qs(dialog, '[data-helppay-step-dot="shipping"]');
    var stepPayment = qs(dialog, '[data-helppay-step-dot="payment"]');
    if (stepShipping) {
      stepShipping.hidden = mode !== 'quick';
      stepShipping.textContent = t('dialogStepShipping', '物流');
    }
    if (stepPayment) {
      stepPayment.hidden = mode !== 'quick';
      stepPayment.textContent = t('dialogStepPay', '付款');
    }
    if (stepResult) stepResult.hidden = mode === 'quick';
    var stepRules = qs(dialog, '[data-helppay-step-dot="rules"]');
    if (stepRules) stepRules.hidden = mode === 'quick';
    dialog.querySelectorAll('[data-helppay-step-sep="shipping"],[data-helppay-step-sep="payment"]').forEach(function (sep) {
      sep.hidden = mode !== 'quick';
    });
    return mode;
  }

  /**
   * 快捷购买成功态：本人结账完成（非分享网格）。
   * @param {HTMLElement} host
   * @param {string} url
   */
  function renderQuickCheckoutLaunch(host, url) {
    var safe = escapeHtml(url || '');
    host.innerHTML =
      '<div class="w-helppay-quick-checkout w-helppay-panel" data-testid="quick-pay-checkout-launch" data-helppay-result-flow="quick" data-pay-url="' +
      safe +
      '">' +
      '<p class="w-text" data-weight="strong" style="--w-m:0;">' +
      escapeHtml(t('quickTitle', '本人快捷购买')) +
      '</p>' +
      '<p class="w-text" data-tone="muted" data-size="sm">' +
      escapeHtml(t('quickPaidHint', '可在支付窗口完成付款。若已支付，可关闭本弹窗。')) +
      '</p>' +
      '<div class="w-helppay-quick-checkout__actions">' +
      '<button type="button" class="w-button" data-tone="primary" data-testid="quick-pay-reopen" data-helppay-reopen-pay>' +
      escapeHtml(t('quickPayNow', '打开支付窗口')) +
      '</button>' +
      '<button type="button" class="w-button" data-variant="outline" data-tone="neutral" data-helppay-close data-testid="quick-pay-done">' +
      escapeHtml(t('close', '关闭')) +
      '</button>' +
      '</div>' +
      '</div>';
    var reopen = qs(host, '[data-helppay-reopen-pay]');
    if (reopen) {
      reopen.onclick = function () {
        openPayPopup(url);
      };
    }
  }

  /** Open /q/ in a child window — never navigate the PDP main window. */
  function openPayPopup(url) {
    var target = absoluteUrl(url || '');
    if (!target) return null;
    try {
      var popup = w.open(target, 'weline_quick_pay', 'width=520,height=720,scrollbars=yes,resizable=yes');
      if (popup && typeof popup.focus === 'function') {
        popup.focus();
      }
      return popup;
    } catch (e) {
      return null;
    }
  }

  /** @deprecated kept for tests; must not be used as quick-pay primary path */
  function launchQuickPayCheckout(url) {
    openPayPopup(url);
  }

  function readCurrentSpecAxes() {
    var root = qs(d, '[data-testid="storefront-product-detail"], .product-native-detail');
    if (!root) return [];
    var axes = [];
    root.querySelectorAll('.product-native-detail__variant-axis').forEach(function (axisEl) {
      var labelEl = qs(axisEl, '.product-native-detail__variant-axis-label');
      var label = String((labelEl && labelEl.textContent) || '')
        .replace(/:\s*$/, '')
        .replace(/\s+/g, ' ')
        .trim();
      var selected = qs(
        axisEl,
        '[data-variant-option].is-selected, [data-variant-option][aria-current="true"]'
      );
      var value = '';
      if (selected) {
        value = String(
          selected.getAttribute('data-variant-label')
            || selected.getAttribute('aria-label')
            || selected.getAttribute('title')
            || ''
        ).trim();
        if (!value) {
          var swatchLabel = qs(selected, '.product-native-detail__variant-swatch-label');
          value = String((swatchLabel && swatchLabel.textContent) || selected.textContent || '')
            .replace(/\s+/g, ' ')
            .trim();
        }
      } else {
        var valueEl = qs(axisEl, '.product-native-detail__variant-axis-value');
        value = String((valueEl && valueEl.textContent) || '').replace(/\s+/g, ' ').trim();
      }
      if (label !== '' && value !== '') {
        axes.push({ label: label, value: value });
      }
    });
    return axes;
  }

  function readCurrentSpecImage() {
    var root = qs(d, '[data-testid="storefront-product-detail"], .product-native-detail');
    if (!root) return '';
    var selected = qs(
      root,
      '[data-variant-option].is-selected, [data-variant-option][aria-current="true"]'
    );
    if (selected) {
      var swatchImg = qs(selected, 'img.product-native-detail__variant-swatch-image, img');
      if (swatchImg) {
        var swatchSrc = String(swatchImg.currentSrc || swatchImg.src || swatchImg.getAttribute('src') || '').trim();
        if (swatchSrc !== '' && swatchSrc.indexOf('data:') !== 0) {
          return swatchSrc;
        }
      }
    }
    var primary = qs(root, '.product-native-detail__primary-image');
    if (primary && !primary.hidden) {
      var primarySrc = String(primary.currentSrc || primary.src || primary.getAttribute('src') || '').trim();
      if (primarySrc !== '') return primarySrc;
    }
    var activeThumb = qs(root, '[data-gallery-src].is-active, [data-gallery-src][aria-current="true"]');
    if (activeThumb) {
      var thumbSrc = String(
        activeThumb.getAttribute('data-gallery-src')
          || activeThumb.getAttribute('data-gallery-poster')
          || ''
      ).trim();
      if (thumbSrc !== '') return thumbSrc;
      var thumbImg = qs(activeThumb, 'img');
      if (thumbImg) {
        var fromThumb = String(thumbImg.currentSrc || thumbImg.src || '').trim();
        if (fromThumb !== '') return fromThumb;
      }
    }
    return '';
  }

  /**
   * @param {HTMLElement|null} btn
   * @returns {{title:string,sku:string,qty:number,image:string,axes:Array<{label:string,value:string}>}|null}
   */
  function readCurrentSpecSummary(btn) {
    var title = readProductTitle();
    var sku = readProductSku(btn);
    var qty = Math.max(1, readSelectedQty(btn) || 1);
    var axes = readCurrentSpecAxes();
    var image = readCurrentSpecImage();
    if (title === '' && sku === 'SKU' && axes.length === 0 && image === '') {
      return null;
    }
    return { title: title, sku: sku, qty: qty, image: image, axes: axes };
  }

  /**
   * @param {{title?:string,sku?:string,qty?:number,image?:string,axes?:Array<{label:string,value:string}>}|null} spec
   * @param {string} [sectionLabel]
   */
  function buildShareSpecHtml(spec, sectionLabel) {
    if (!spec || typeof spec !== 'object') {
      return '';
    }
    var title = String(spec.title || '').trim();
    var sku = String(spec.sku || '').trim();
    var qty = Math.max(1, Number(spec.qty || 1) || 1);
    var image = String(spec.image || '').trim();
    var axes = Array.isArray(spec.axes) ? spec.axes : [];
    if (title === '' && (sku === '' || sku === 'SKU') && axes.length === 0 && image === '') {
      return '';
    }
    var section = escapeHtml(sectionLabel || t('specSection', '当前规格'));
    var qtyLabel = escapeHtml(t('specQty', '数量'));
    var skuLabel = escapeHtml(t('specSku', 'SKU'));
    var imgAlt = escapeHtml(title !== '' ? title : sectionLabel || t('specSection', '当前规格'));
    var rows = '';
    axes.forEach(function (axis) {
      var label = String((axis && axis.label) || '').trim();
      var value = String((axis && axis.value) || '').trim();
      if (label === '' || value === '') return;
      rows +=
        '<li class="w-helppay-share-result__spec-row">' +
        '<span class="w-helppay-share-result__spec-key">' +
        escapeHtml(label) +
        '</span>' +
        '<span class="w-helppay-share-result__spec-val">' +
        escapeHtml(value) +
        '</span>' +
        '</li>';
    });
    var metaParts = [];
    metaParts.push(qtyLabel + ' ×' + qty);
    if (sku !== '' && sku !== 'SKU') {
      metaParts.push(skuLabel + ' ' + sku);
    }
    var media =
      image !== ''
        ? '<div class="w-helppay-share-result__spec-media">' +
          '<img class="w-helppay-share-result__spec-image" src="' +
          escapeHtml(image) +
          '" alt="' +
          imgAlt +
          '" width="72" height="72" loading="lazy" decoding="async" data-testid="help-pay-share-spec-image">' +
          '</div>'
        : '';
    var body =
      '<div class="w-helppay-share-result__spec-body">' +
      (title !== ''
        ? '<p class="w-helppay-share-result__spec-title w-text" data-weight="strong">' +
          escapeHtml(title) +
          '</p>'
        : '') +
      (rows !== '' ? '<ul class="w-helppay-share-result__spec-list">' + rows + '</ul>' : '') +
      '<p class="w-helppay-share-result__spec-meta w-text" data-size="sm" data-tone="muted">' +
      escapeHtml(metaParts.join(' · ')) +
      '</p>' +
      '</div>';
    return (
      '<div class="w-helppay-share-result__spec" data-testid="help-pay-share-spec">' +
      '<p class="w-helppay-share-result__section-label">' +
      section +
      '</p>' +
      '<div class="w-helppay-share-result__spec-main">' +
      media +
      body +
      '</div>' +
      '</div>'
    );
  }

  /**
   * @param {HTMLElement} host
   * @param {string} url
   * @param {string} title
   * @param {'quick'|'help'|'share'} [mode]
   * @param {{title?:string,sku?:string,qty?:number,axes?:Array<{label:string,value:string}>}|null} [spec]
   */
  function renderShareResult(host, url, title, mode, spec) {
    var flow = mode === 'quick' || mode === 'share' || mode === 'help' ? mode : 'share';
    var isQuick = flow === 'quick';
    var safe = escapeHtml(url || '');
    var heading = escapeHtml(
      title || (isQuick ? t('quickTitle', '本人快捷购买') : t('shareWithPayer', '分享给付款人'))
    );
    var hint = escapeHtml(
      isQuick
        ? t('quickHint', '复制链接或扫码，在本机或其它设备打开即可完成付款')
        : t('hint', '复制链接或扫码分享当前规格')
    );
    var linkSection = escapeHtml(
      isQuick ? t('quickLinkSection', '付款链接') : t('linkSection', '链接分享')
    );
    var linkLabel = escapeHtml(t('linkLabel', '链接地址'));
    var copyLink = escapeHtml(t('copyLink', '复制链接'));
    var tipUrl = escapeHtml(
      isQuick
        ? t('quickTipUrl', '复制后在浏览器打开，用你自己的账号完成支付')
        : t('tipUrl', '复制后，粘贴发给朋友即可打开这份规格')
    );
    var tipUrlCopied = escapeHtml(
      isQuick
        ? t('quickTipUrlCopied', '已复制，请自行打开链接付款')
        : t('tipUrlCopied', '已复制，可以分发给朋友')
    );
    var tipUrlFail = escapeHtml(t('tipUrlFail', '复制没成功，请再点一次试试'));
    var qrSection = escapeHtml(
      isQuick ? t('quickQrSection', '扫码付款') : t('qrSection', '扫码分享')
    );
    var shareQrAria = escapeHtml(
      isQuick ? t('quickQrAria', '快捷购买付款二维码') : t('shareQrAria', '分享二维码')
    );
    var copyQr = escapeHtml(t('copyQr', '复制二维码图片'));
    var downloadQr = escapeHtml(t('downloadQr', '下载二维码'));
    var tipQr = escapeHtml(
      isQuick
        ? t('quickTipQr', '扫码后在本机打开付款页，完成你自己的支付')
        : t('tipQr', '复制后，可直接把图片粘贴到聊天里发送')
    );
    var tipQrCopied = escapeHtml(
      isQuick
        ? t('quickTipQrCopied', '已复制，可粘贴到本机浏览器打开付款')
        : t('tipQrCopied', '已复制，可直接粘贴图片到聊天里发送')
    );
    var tipQrFail = escapeHtml(t('tipQrFail', '复制没成功，请再点一次或改用下载'));
    var copied = escapeHtml(t('copied', '已复制'));
    var copyFail = escapeHtml(t('copyFail', '复制失败'));
    var specHtml = buildShareSpecHtml(spec || null);
    host.innerHTML =
      '<div class="w-helppay-share-result w-helppay-panel" data-testid="help-pay-share-result" data-helppay-result-flow="' +
      escapeHtml(flow) +
      '" data-share-url="' +
      safe +
      '">' +
      '<div class="w-helppay-share-result__header">' +
      '<p class="w-helppay-share-result__title w-text" data-weight="strong">' +
      heading +
      '</p>' +
      '<p class="w-helppay-share-result__hint">' +
      hint +
      '</p>' +
      '</div>' +
      specHtml +
      '<div class="w-helppay-share-result__grid">' +
      '<div class="w-helppay-share-result__url-block">' +
      '<p class="w-helppay-share-result__section-label">' +
      linkSection +
      '</p>' +
      '<label class="w-field"><span class="w-field__label">' +
      linkLabel +
      '</span>' +
      '<input class="w-input" readonly data-testid="help-pay-share-url" value="' +
      safe +
      '"></label>' +
      '<div class="w-helppay-share-result__actions">' +
      '<button type="button" class="w-button" data-tone="primary" data-testid="help-pay-copy-url" data-helppay-copy-url data-helppay-copied-label="' +
      copied +
      '" data-helppay-fail-label="' +
      copyFail +
      '">' +
      copyLink +
      '</button>' +
      '</div>' +
      '<p class="w-helppay-share-result__tip w-text" data-size="sm" data-tone="muted" data-testid="help-pay-copy-tip-url" data-helppay-copy-tip="url" data-helppay-tip-copied="' +
      tipUrlCopied +
      '" data-helppay-tip-fail="' +
      tipUrlFail +
      '" aria-live="polite">' +
      tipUrl +
      '</p>' +
      '</div>' +
      '<div class="w-helppay-share-result__qr-block">' +
      '<p class="w-helppay-share-result__section-label">' +
      qrSection +
      '</p>' +
      '<div class="w-helppay-share-result__qr-frame">' +
      '<canvas width="160" height="160" data-testid="help-pay-share-qr" data-helppay-qr-canvas aria-label="' +
      shareQrAria +
      '"></canvas>' +
      '</div>' +
      '<div class="w-helppay-share-result__actions">' +
      '<button type="button" class="w-button" data-variant="outline" data-tone="primary" data-testid="help-pay-copy-qr" data-helppay-copy-qr data-helppay-copied-label="' +
      copied +
      '" data-helppay-fail-label="' +
      copyFail +
      '">' +
      copyQr +
      '</button>' +
      '<a class="w-helppay-share-result__dl" hidden data-testid="help-pay-download-qr" data-helppay-download-qr download="helppay-qr.png">' +
      downloadQr +
      '</a>' +
      '</div>' +
      '<p class="w-helppay-share-result__tip w-text" data-size="sm" data-tone="muted" data-testid="help-pay-copy-tip-qr" data-helppay-copy-tip="qr" data-helppay-tip-copied="' +
      tipQrCopied +
      '" data-helppay-tip-fail="' +
      tipQrFail +
      '" aria-live="polite">' +
      tipQr +
      '</p>' +
      '</div></div></div>';
  }

  async function wireShareResult(root) {
    var url = absoluteUrl(root.getAttribute('data-share-url') || (qs(root, '[data-testid="help-pay-share-url"]') || {}).value || '');
    var tipUrl = qs(root, '[data-helppay-copy-tip="url"]');
    var tipQr = qs(root, '[data-helppay-copy-tip="qr"]');
    var copyUrlBtn = qs(root, '[data-helppay-copy-url]');
    var copyQrBtn = qs(root, '[data-helppay-copy-qr]');
    var dl = qs(root, '[data-helppay-download-qr]');
    var canvas = qs(root, '[data-helppay-qr-canvas]');
    // 链接复制先绑定：不因二维码接口慢而点不动
    if (copyUrlBtn) {
      copyUrlBtn.onclick = async function () {
        markCopyPending(copyUrlBtn, tipUrl);
        var ok = await copyText(url);
        flashCopyFeedback(copyUrlBtn, ok, tipUrl);
      };
    }
    if (copyQrBtn) {
      copyQrBtn.disabled = true;
      copyQrBtn.onclick = async function () {
        markCopyPending(copyQrBtn, tipQr);
        var dataUri = root._helppayQr || '';
        if (!dataUri) {
          dataUri = await fetchQrDataUri(url);
          root._helppayQr = dataUri;
          paintQrCanvas(canvas, dataUri);
        }
        var ok = await copyImage(dataUri, dl);
        flashCopyFeedback(copyQrBtn, ok, tipQr);
        if (!ok && dl && !dl.hidden && tipQr) {
          tipQr.textContent = t('qrCopyFallback', '当前环境不支持复制图片，请改用下方下载');
          tipQr.setAttribute('data-tone', 'muted');
          tipQr.setAttribute('data-state', 'fallback');
        }
      };
    }
    var dataUri = await fetchQrDataUri(url);
    paintQrCanvas(canvas, dataUri);
    root._helppayQr = dataUri;
    if (copyQrBtn) {
      copyQrBtn.disabled = false;
    }
  }

  function fieldValue(root, selectors) {
    var scope = root || d;
    for (var i = 0; i < selectors.length; i += 1) {
      var el = scope.querySelector(selectors[i]);
      if (!el) continue;
      var val = String(el.value != null ? el.value : el.getAttribute('value') || '').trim();
      if (val) return val;
    }
    return '';
  }

  function selectedCheckoutAddressPayload() {
    var widget = qs(d, '[data-shipping-checkout-address]');
    var scope = widget || d;
    var card = scope.querySelector('[data-address-card].is-selected');
    if (!card) {
      var radio = scope.querySelector('[data-address-radio]:checked');
      card = radio && radio.closest ? radio.closest('[data-address-card]') : null;
    }
    if (!card) return null;
    try {
      var payload = JSON.parse(card.getAttribute('data-address-json') || '{}') || {};
      return payload && typeof payload === 'object' ? payload : null;
    } catch (e) {
      return null;
    }
  }

  function readCheckoutAddressPreview() {
    var payload = selectedCheckoutAddressPayload();
    var widget = qs(d, '[data-shipping-checkout-address]');
    var formRoot = widget || d;
    var name = '';
    var line1 = '';
    var phone = '';
    var country = '';
    var countryCode = '';
    var city = '';
    var province = '';
    var district = '';
    var postal = '';

    if (payload) {
      name = String(payload.name || payload.contact_name || '').trim();
      phone = String(payload.phone || payload.contact_phone || '').trim();
      line1 = String(payload.address1 || payload.street || payload.line1 || '').trim();
      countryCode = String(payload.country_code || '').trim().toUpperCase();
      country = String(payload.country || payload.country_name || countryCode || '').trim();
      province = String(payload.province || '').trim();
      city = String(payload.city || '').trim();
      district = String(payload.district || '').trim();
      postal = String(payload.postal_code || payload.postcode || '').trim();
    }

    if (!name) {
      name = fieldValue(formRoot, [
        '[data-shipping-field][name="name"]',
        '[data-shipping-name]',
        '[name="shipping_name"]',
        '[name="name"]',
      ]);
    }
    if (!phone) {
      phone = fieldValue(formRoot, [
        '[data-shipping-field][name="phone"]',
        '[data-shipping-phone]',
        '[name="shipping_phone"]',
        '[name="phone"]',
      ]);
    }
    if (!line1) {
      line1 = fieldValue(formRoot, [
        '[data-shipping-field][name="address1"]',
        '[data-shipping-field][name="street"]',
        '[data-shipping-line1]',
        '[name="street"]',
        '[name="line1"]',
        '[name="address1"]',
      ]);
    }
    if (!countryCode && !country) {
      countryCode = fieldValue(formRoot, [
        '[data-shipping-field][name="country_code"]',
        '[data-shipping-country-code]',
        '[name="country_code"]',
      ]).toUpperCase();
      country = fieldValue(formRoot, [
        '[data-shipping-field][name="country"]',
        '[data-shipping-country]',
        '[name="country"]',
      ]);
    }
    if (!city) {
      city = fieldValue(formRoot, [
        '[data-shipping-field][name="city"]',
        '[data-shipping-city]',
        '[name="city"]',
      ]);
    }
    if (!province) {
      province = fieldValue(formRoot, [
        '[data-shipping-field][name="province"]',
        '[name="province"]',
      ]);
    }
    if (!district) {
      district = fieldValue(formRoot, [
        '[data-shipping-field][name="district"]',
        '[name="district"]',
      ]);
    }
    if (!postal) {
      postal = fieldValue(formRoot, [
        '[data-shipping-field][name="postal_code"]',
        '[name="postal_code"]',
      ]);
    }

    var countryOut = countryCode || country || 'CN';
    var regionLine = [country || countryCode, province, city, district, postal].filter(Boolean).join(' / ');
    var preview = [name, phone, regionLine, line1].filter(Boolean).join('\n');
    return {
      name: name,
      line1: line1,
      phone: phone,
      country: countryOut,
      country_code: countryCode || countryOut,
      city: city,
      province: province,
      district: district,
      postal_code: postal,
      preview: preview,
      complete: !!(name && line1 && phone && countryOut),
    };
  }

  function cartHasLines() {
    var rows = d.querySelectorAll('[data-cart-item], [data-cart-line], .weline-cart-shell__item');
    if (rows && rows.length) return true;
    var blocked = d.querySelector('[data-checkout-blocked="1"]');
    return !blocked;
  }

  function currentSellingMode() {
    var fromDoc = String(d.documentElement.getAttribute('data-selling-mode') || '').toLowerCase();
    if (fromDoc === 'toc' || fromDoc === 'tob') return fromDoc;
    var detail = qs(d, '[data-selling-mode]');
    var fromNode = detail ? String(detail.getAttribute('data-selling-mode') || '').toLowerCase() : '';
    if (fromNode === 'toc' || fromNode === 'tob') return fromNode;
    return 'toc';
  }

  function isPurchaseUnavailable() {
    var add = qs(d, '[data-action="add"], [data-action="buy-now"]');
    if (add && add.disabled) return true;
    var stock = qs(d, '.product-native-detail__stock, [data-stock-tone="out-stock"]');
    if (stock && /暂时缺货|暂不可售|out of stock/i.test(stock.textContent || '')) return true;
    var offerMsg = qs(d, '.product-native-detail__offer-message, [data-offer-unavailable]');
    if (offerMsg && /暂不可售|缺货/i.test(offerMsg.textContent || '')) return true;
    return false;
  }

  function setPurchaseGate(btn, tob, unavailable, tobTitle) {
    if (!btn) return;
    var disable = tob || unavailable;
    btn.disabled = !!disable;
    btn.setAttribute('aria-disabled', disable ? 'true' : 'false');
    if (tob) {
      btn.title = tobTitle;
    } else if (unavailable) {
      btn.title = '当前规格暂不可售';
    } else {
      btn.removeAttribute('title');
    }
  }

  function syncQuickPayCtas() {
    var tob = currentSellingMode() === 'tob';
    var unavailable = isPurchaseUnavailable();
    d.querySelectorAll('[data-helppay-quick-pay], [data-testid="product-quick-pay"]').forEach(function (btn) {
      setPurchaseGate(btn, tob, unavailable, '快捷购买仅支持零售模式');
    });
    d.querySelectorAll('[data-helppay-product-help-pay], [data-testid="product-help-pay"] [data-helppay-open]').forEach(function (btn) {
      setPurchaseGate(btn, tob, unavailable, '找朋友代付仅支持零售模式');
    });
  }

  function revealCtas() {
    d.querySelectorAll('.w-helppay-cta[data-helppay-placement]').forEach(function (el) {
      // 购物车空时隐藏；结账默认可见（勿用初始 hidden，否则 extras 页签会跳过）。
      if (!cartHasLines() && el.getAttribute('data-helppay-placement') === 'cart') {
        el.hidden = true;
        return;
      }
      el.hidden = false;
    });
    syncQuickPayCtas();
  }

  function openFlow(placement, from) {
    mergeShareI18n(from || null);
    var dialog = ensureDialog();
    applyDialogFlow(dialog, 'help');
    dialog._helppayPlacement = placement || 'cart';
    dialog._helppayProductBtn = null;
    revealDialog(dialog, from || null);
    showStep(dialog, 'rules');

    qs(dialog, '[data-helppay-next-address]').onclick = function () {
      var accepted = qs(dialog, '[data-helppay-rules-accepted]');
      if (!accepted || !accepted.checked) return;
      openHelpPayAddressStep(dialog);
    };
  }

  function readProductSku(btn) {
    var sku =
      (btn && btn.getAttribute && btn.getAttribute('data-sku')) ||
      (qs(d, '[data-product-sku]') || {}).textContent ||
      (qs(d, '[data-product-sku]') || {}).value ||
      'SKU';
    return String(sku).replace(/^SKU:\s*/i, '').trim() || 'SKU';
  }

  function readProductTitle() {
    var node = qs(d, '[data-testid="product-title"], .product-native-detail__title, h1');
    return String((node && node.textContent) || '').replace(/\s+/g, ' ').trim();
  }

  function readCurrencyCode() {
    var node = qs(d, '[data-cart-currency], [data-currency-code], [data-product-currency]');
    if (node) {
      var fromVal = String(node.value || node.getAttribute('data-currency-code') || '').trim();
      if (fromVal) return fromVal;
    }
    return String(d.documentElement.getAttribute('data-currency') || 'USD').trim() || 'USD';
  }

  function readProductLineSummary(btn) {
    return [
      {
        sku: readProductSku(btn),
        qty: readSelectedQty(btn),
        title: readProductTitle(),
      },
    ];
  }

  function readCartAmountMinor() {
    return Number((qs(d, '[data-cart-grand-total-minor], [data-grand-total-minor]') || {}).value || 0) || 0;
  }

  async function openHelpPayAddressStep(dialog) {
    showStep(dialog, 'address-pick');
    var title = qs(dialog, '[data-helppay-address-title]');
    var hint = qs(dialog, '[data-helppay-address-hint]');
    var confirmBtn = qs(dialog, '[data-helppay-confirm-quick]');
    if (title) title.textContent = t('dialogAddressTitle', '确认收货地址');
    if (hint) hint.textContent = t('confirmHelpPayHint', '请填写、选择或更换收货地址后再生成代付链接。');
    if (confirmBtn) {
      confirmBtn.textContent = t('confirmHelpPay', '确认并生成代付链接');
      confirmBtn.disabled = false;
      confirmBtn.onclick = function () {
        confirmHelpPayFromAddress(dialog);
      };
    }
    setAddressPickMessage(dialog, '');
    try {
      var placement = dialog._helppayPlacement || 'cart';
      if (placement === 'product') {
        if (currentSellingMode() === 'tob') {
          throw new Error('helppay_toc_only');
        }
        if (isPurchaseUnavailable()) {
          throw new Error('当前规格暂不可售，无法生成代付链接。');
        }
      }
      await ensureHelpPayAddressMounted(dialog);
    } catch (err) {
      showStep(dialog, 'error');
      renderError(qs(dialog, '[data-helppay-step="error"]'), humanizeApiError(err));
    }
  }

  function openProductHelpPayFlow(btn) {
    mergeShareI18n(btn);
    if (btn && btn.disabled) return;
    if (currentSellingMode() === 'tob') {
      openErrorDialog(humanizeApiError(new Error('helppay_toc_only')), btn);
      return;
    }
    if (isPurchaseUnavailable()) {
      openErrorDialog('当前规格暂不可售，无法生成代付链接。', btn);
      return;
    }
    var dialog = ensureDialog();
    applyDialogFlow(dialog, 'help');
    dialog._helppayProductBtn = btn;
    dialog._helppayPlacement = 'product';
    revealDialog(dialog, btn);
    showStep(dialog, 'rules');

    qs(dialog, '[data-helppay-next-address]').onclick = function () {
      var accepted = qs(dialog, '[data-helppay-rules-accepted]');
      if (!accepted || !accepted.checked) return;
      openHelpPayAddressStep(dialog);
    };
  }

  async function confirmHelpPayFromAddress(dialog) {
    var placement = dialog._helppayPlacement || 'cart';
    var btn = dialog._helppayProductBtn;
    var confirmBtn = qs(dialog, '[data-helppay-confirm-quick]');
    var rules = qs(dialog, '[data-helppay-rules-accepted]');
    setAddressPickMessage(dialog, '');
    if (confirmBtn) confirmBtn.disabled = true;
    try {
      if (!rules || !rules.checked) {
        throw new Error('helppay_rules_not_accepted');
      }
      if (placement === 'product') {
        if (!btn || btn.disabled) {
          throw new Error('当前规格暂不可售，无法生成代付链接。');
        }
        if (currentSellingMode() === 'tob') {
          throw new Error('helppay_toc_only');
        }
        if (isPurchaseUnavailable()) {
          throw new Error('当前规格暂不可售，无法生成代付链接。');
        }
      }
      var api = resolveDialogShippingApi(dialog);
      if (!api || typeof api.resolveQuoteAddress !== 'function') {
        throw new Error(t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'));
      }
      var resolved = api.resolveQuoteAddress();
      if (typeof api.validateShippingFields === 'function') {
        var ok = await api.validateShippingFields(resolved || {});
        if (ok === false) {
          setAddressPickMessage(dialog, t('addressIncompleteHelpPay', '请先完善收货地址后再生成代付链接。'), true);
          return;
        }
      }
      var addr = mapResolvedToShipping(resolved);
      if (!addr.complete) {
        setAddressPickMessage(dialog, t('addressIncompleteHelpPay', '请先完善收货地址后再生成代付链接。'), true);
        return;
      }
      var amount = 0;
      var currency = readCurrencyCode();
      var lineSummary = [];
      if (placement === 'product') {
        amount = readAmountMinor(btn);
        if (!(amount > 0)) {
          throw new Error('无法读取商品金额，请刷新后重试。');
        }
        lineSummary = readProductLineSummary(btn);
      } else {
        amount = readCartAmountMinor();
        var cartCurrency = (qs(d, '[data-cart-currency]') || {}).value;
        if (cartCurrency) currency = String(cartCurrency);
      }
      showStep(dialog, 'result');
      var host = qs(dialog, '[data-helppay-step="result"]');
      host.innerHTML =
        '<p class="w-text" data-tone="muted">' +
        escapeHtml(t('generatingHelpPay', '正在生成代付链接…')) +
        '</p>';
      var result = null;
      if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
        result = await w.Weline.Api.resource('helpPay').createHelpPay({
          amount_minor: amount,
          currency_code: currency,
          shipping_address: {
            name: addr.name,
            line1: addr.line1,
            phone: addr.phone,
            country: addr.country || 'CN',
            country_code: addr.country_code || addr.country || 'CN',
            city: addr.city || '',
            province: addr.province || '',
            district: addr.district || '',
            postal_code: addr.postal_code || '',
          },
          address_confirmed: true,
          rules_accepted: true,
          cart_type: 'toc',
          line_summary: lineSummary,
        });
      }
      if (!result || !result.url) {
        throw new Error('未返回分享链接');
      }
      trackPixel(
        'friend_help_pay_link_ready',
        {
          share_url: String(result.url || ''),
          source: 'helppay',
          trigger: 'link_ready',
          placement: placement || 'product',
        },
        btn
      );
      renderShareResult(
        host,
        absoluteUrl(result.url),
        t('shareWithPayer', '分享给付款人'),
        'help',
        placement === 'product' ? readCurrentSpecSummary(btn) : null
      );
      await wireShareResult(qs(host, '[data-testid="help-pay-share-result"]'));
    } catch (err) {
      showStep(dialog, 'error');
      renderError(qs(dialog, '[data-helppay-step="error"]'), humanizeApiError(err));
    } finally {
      if (confirmBtn) confirmBtn.disabled = false;
    }
  }

  async function createSelectionShareAndShow(btn) {
    mergeShareI18n(btn);
    var dialog = ensureDialog();
    applyDialogFlow(dialog, 'share');
    revealDialog(dialog, btn);
    showStep(dialog, 'result');
    var host = qs(dialog, '[data-helppay-step="result"]');
    host.innerHTML =
      '<p class="w-text" data-tone="muted">' +
      escapeHtml(t('generatingShare', '正在生成分享链接…')) +
      '</p>';
    var sku = (btn.getAttribute('data-sku') || (qs(d, '[data-product-sku]') || {}).textContent || (qs(d, '[data-product-sku]') || {}).value || 'SKU');
    sku = String(sku).replace(/^SKU:\s*/i, '').trim() || 'SKU';
    var qty = Number(btn.getAttribute('data-qty') || 1) || 1;
    try {
      if (currentSellingMode() === 'tob') {
        throw new Error('helppay_toc_only');
      }
      var result = null;
      if (w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function') {
        result = await w.Weline.Api.resource('helpPay').createSelectionShare({
          selection_snapshot: { lines: [{ sku: sku, qty: qty }] },
          cart_type: 'toc',
        });
      }
      if (!result || !result.url) {
        throw new Error('未返回分享链接');
      }
      trackPixel(
        'selection_share_link_ready',
        {
          share_url: String(result.url || ''),
          source: 'helppay',
          trigger: 'link_ready',
        },
        btn
      );
      renderShareResult(
        host,
        absoluteUrl(result.url),
        t('shareTitle', '分享规格给朋友'),
        'share',
        readCurrentSpecSummary(btn)
      );
      await wireShareResult(qs(host, '[data-testid="help-pay-share-result"]'));
    } catch (err) {
      showStep(dialog, 'error');
      renderError(qs(dialog, '[data-helppay-step="error"]'), humanizeApiError(err, 'share'));
    }
  }

  function readAttrMinor(el, names) {
    if (!el || !el.getAttribute) return 0;
    for (var i = 0; i < names.length; i++) {
      var v = Number(el.getAttribute(names[i]) || 0);
      if (v > 0) return v;
    }
    if (el.value != null && el.value !== '') {
      var n = Number(el.value || 0);
      if (n > 0) return n;
    }
    return 0;
  }

  /** PDP 现价文案回退：¥108.00 → 10800 minor */
  function readDisplayedPdpPriceMinor() {
    var root = qs(d, '[data-testid="storefront-product-detail"], .product-native-detail');
    if (!root) return 0;
    var whole = qs(root, '.product-native-detail__price-whole');
    if (!whole) return 0;
    var frac = qs(root, '.product-native-detail__price-fraction');
    var wholeN = Number(String(whole.textContent || '').replace(/[^\d]/g, '') || 0);
    var fracRaw = String((frac && frac.textContent) || '').replace(/[^\d]/g, '');
    var fracN = fracRaw === '' ? 0 : Number(fracRaw.slice(0, 2) || 0);
    var minor = wholeN * 100 + (fracN % 100);
    return minor > 0 ? minor : 0;
  }

  function readSelectedQty(btn) {
    var fromBtn = Number((btn && btn.getAttribute && btn.getAttribute('data-qty')) || 0);
    if (fromBtn > 0) return fromBtn;
    var qty = qs(d, '[data-testid="product-qty"]');
    if (qty && qty.value != null && qty.value !== '') {
      var n = Number(qty.value || 1);
      if (n > 0) return n;
    }
    return 1;
  }

  /**
   * 快捷购买金额：按钮 → PDP offer/现价属性 → 目录价 → 价格文案；再 × 数量。
   * PDP 权威属性为 data-offer-price-minor / data-catalog-price-minor（非旧名 data-price-minor）。
   */
  function readAmountMinor(btn) {
    var unitNames = [
      'data-amount-minor',
      'data-offer-price-minor',
      'data-price-minor',
      'data-product-price-minor',
      'data-reference-price-minor',
      'data-catalog-price-minor',
    ];
    var unit = readAttrMinor(btn, ['data-amount-minor']);
    if (!(unit > 0)) {
      var detail = qs(d, '[data-testid="storefront-product-detail"], .product-native-detail');
      if (detail) {
        unit = readAttrMinor(detail, [
          'data-offer-price-minor',
          'data-price-minor',
          'data-product-price-minor',
          'data-reference-price-minor',
          'data-catalog-price-minor',
        ]);
      }
    }
    if (!(unit > 0)) {
      var nodes = d.querySelectorAll(
        '[data-offer-price-minor], [data-price-minor], [data-product-price-minor], [data-reference-price-minor], [data-catalog-price-minor]'
      );
      for (var i = 0; i < nodes.length; i++) {
        unit = readAttrMinor(nodes[i], unitNames);
        if (unit > 0) break;
      }
    }
    if (!(unit > 0)) {
      unit = readDisplayedPdpPriceMinor();
    }
    if (!(unit > 0)) return 0;
    return Math.round(unit * readSelectedQty(btn));
  }

  function setAddressPickMessage(dialog, message, isError) {
    var msg = qs(dialog, '[data-helppay-address-msg]');
    if (!msg) return;
    var text = String(message || '').trim();
    if (!text) {
      msg.hidden = true;
      msg.textContent = '';
      return;
    }
    msg.hidden = false;
    msg.textContent = text;
    msg.setAttribute('data-tone', isError === false ? 'muted' : 'danger');
  }

  function mapResolvedToShipping(resolved) {
    if (!resolved || typeof resolved !== 'object') {
      return { complete: false };
    }
    var name = String(resolved.name || '').trim();
    var phone = String(resolved.phone || '').trim();
    var line1 = String(resolved.address1 || resolved.street || resolved.line1 || '').trim();
    var countryCode = String(resolved.country_code || '').trim().toUpperCase();
    var country = String(resolved.country || countryCode || 'CN').trim().toUpperCase() || 'CN';
    if (!countryCode) countryCode = country;
    var city = String(resolved.city || '').trim();
    var province = String(resolved.province || '').trim();
    var district = String(resolved.district || '').trim();
    var postal = String(resolved.postal_code || resolved.postcode || '').trim();
    return {
      name: name,
      phone: phone,
      line1: line1,
      country: country,
      country_code: countryCode,
      city: city,
      province: province,
      district: district,
      postal_code: postal,
      complete: !!(name && phone && line1 && (countryCode || country)),
    };
  }

  /** @type {object|null} */
  var pageShippingAddressApiBackup = null;
  /** @type {object|null} */
  var dialogShippingAddressApi = null;

  function backupPageShippingAddressApi(dialog) {
    var api = w.WelineShippingCheckoutAddress;
    if (!api || !api.root) return;
    if (dialog && dialog.contains(api.root)) return;
    pageShippingAddressApiBackup = api;
  }

  function restorePageShippingAddressApi() {
    if (pageShippingAddressApiBackup) {
      w.WelineShippingCheckoutAddress = pageShippingAddressApiBackup;
      pageShippingAddressApiBackup = null;
    }
  }

  function resolveDialogShippingApi(dialog) {
    if (
      dialogShippingAddressApi &&
      dialogShippingAddressApi.root &&
      dialog &&
      dialog.contains(dialogShippingAddressApi.root)
    ) {
      return dialogShippingAddressApi;
    }
    return w.WelineShippingCheckoutAddress;
  }

  async function ensureHelpPayAddressMounted(dialog) {
    var mountHost = qs(dialog, '[data-helppay-address-mount]');
    if (!mountHost) {
      throw new Error(t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'));
    }
    backupPageShippingAddressApi(dialog);
    var existing = qs(mountHost, '[data-helppay-address-host], [data-shipping-checkout-address]');
    if (existing) {
      var existingRoot = qs(mountHost, '[data-shipping-checkout-address]');
      var existingForm = qs(mountHost, '[data-helppay-address-host]');
      if (existingForm) existingForm.setAttribute('data-session-isolation', '1');
      if (existingRoot) existingRoot.setAttribute('data-session-isolation', '1');
      if (existingRoot && w.WelineShippingCheckoutAddress && typeof w.WelineShippingCheckoutAddress.mount === 'function') {
        var remounted = w.WelineShippingCheckoutAddress.mount(existingRoot);
        if (remounted) {
          dialogShippingAddressApi = remounted;
          w.WelineShippingCheckoutAddress = remounted;
        } else if (w.WelineShippingCheckoutAddress.root === existingRoot) {
          dialogShippingAddressApi = w.WelineShippingCheckoutAddress;
        }
      } else if (
        existingRoot &&
        dialogShippingAddressApi &&
        dialogShippingAddressApi.root === existingRoot
      ) {
        w.WelineShippingCheckoutAddress = dialogShippingAddressApi;
        if (typeof dialogShippingAddressApi.syncChangeAddressLabel === 'function') {
          dialogShippingAddressApi.syncChangeAddressLabel(
            existingRoot.getAttribute('data-mode') || 'collapsed'
          );
        }
      }
      return;
    }
    mountHost.innerHTML =
      '<p class="w-text" data-tone="muted" data-testid="helppay-address-loading">' +
      escapeHtml(t('loadingAddress', '正在加载收货地址…')) +
      '</p>';
    if (!w.Weline || !w.Weline.Api || typeof w.Weline.Api.resource !== 'function') {
      throw new Error(t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'));
    }
    var payload = await w.Weline.Api.resource('checkout').renderDeliveryAddressWidget({}, { silent: true });
    var data = (payload && (payload.data || payload)) || {};
    var rawHtml = String(data.html || payload.html || '');
    if (!rawHtml || data.success === false || payload.success === false) {
      var code = String(data.code || payload.code || '');
      if (code === 'shipping_address_widget_unavailable') {
        throw new Error(t('addressUnavailable', '收货地址组件暂不可用，请改用结账流程。'));
      }
      throw new Error(String(data.message || payload.message || t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。')));
    }
    if (rawHtml.indexOf('data-helppay-address-host') !== -1) {
      mountHost.innerHTML = rawHtml;
    } else {
      mountHost.innerHTML =
        '<form data-helppay-address-host data-testid="helppay-address-host" data-session-isolation="1"' +
        ' action="javascript:void(0)" method="post" onsubmit="return false;">' +
        rawHtml +
        '</form>';
    }
    var form = qs(mountHost, '[data-helppay-address-host]');
    if (form) {
      form.setAttribute('data-session-isolation', '1');
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
      });
    }
    if (w.Weline && typeof w.Weline.load === 'function') {
      await w.Weline.load('shippingCheckoutAddress');
    }
    var addrRoot = qs(mountHost, '[data-shipping-checkout-address]');
    if (!addrRoot) {
      throw new Error(t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'));
    }
    addrRoot.setAttribute('data-session-isolation', '1');
    // 弹层内允许点「更换地址」时再拉全量地址簿（避免 SSR 仅展示选中卡后误判 list-loaded）。
    addrRoot.setAttribute('data-list-loaded', '0');
    if (w.WelineShippingCheckoutAddress && typeof w.WelineShippingCheckoutAddress.mount === 'function') {
      var mounted = w.WelineShippingCheckoutAddress.mount(addrRoot);
      dialogShippingAddressApi = mounted || w.WelineShippingCheckoutAddress;
      if (dialogShippingAddressApi && typeof dialogShippingAddressApi.syncChangeAddressLabel === 'function') {
        dialogShippingAddressApi.syncChangeAddressLabel(addrRoot.getAttribute('data-mode') || 'collapsed');
      } else {
        var changeBtn = qs(addrRoot, '[data-change-address]');
        if (changeBtn && addrRoot.getAttribute('data-has-saved') === '1') {
          changeBtn.hidden = false;
        }
      }
    }
  }

  /** @deprecated alias — 快捷购买与帮我付共用懒加载挂载 */
  var ensureQuickPayAddressMounted = ensureHelpPayAddressMounted;

  async function openQuickPayAddressStep(btn) {
    mergeShareI18n(btn);
    var dialog = ensureDialog();
    applyDialogFlow(dialog, 'quick');
    dialog._helppayQuickBtn = btn;
    dialog._helppayQuickState = dialog._helppayQuickState || {};
    revealDialog(dialog, btn);
    showStep(dialog, 'address-pick');
    var title = qs(dialog, '[data-helppay-address-title]');
    var hint = qs(dialog, '[data-helppay-address-hint]');
    var confirmBtn = qs(dialog, '[data-helppay-confirm-quick]');
    if (title) title.textContent = t('confirmAddressTitle', '确认收货地址');
    if (hint) hint.textContent = t('confirmAddressHint', '请填写、选择或更换收货地址；确认后选择物流。');
    if (confirmBtn) {
      confirmBtn.textContent = t('confirmQuickNextShipping', '下一步：选择物流');
      confirmBtn.disabled = false;
      confirmBtn.onclick = function () {
        confirmQuickPayFromAddress(dialog);
      };
    }
    setAddressPickMessage(dialog, '');
    try {
      if (currentSellingMode() === 'tob') {
        throw new Error('helppay_toc_only');
      }
      if (isPurchaseUnavailable()) {
        throw new Error('当前规格暂不可售，无法生成快捷购买链接。');
      }
      await ensureQuickPayAddressMounted(dialog);
    } catch (err) {
      showStep(dialog, 'error');
      renderError(qs(dialog, '[data-helppay-step="error"]'), humanizeApiError(err, 'quick'));
    }
  }

  function setShippingPickMessage(dialog, message, isError) {
    var el = qs(dialog, '[data-helppay-shipping-msg]');
    if (!el) return;
    if (!message) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = message;
    el.setAttribute('data-tone', isError ? 'danger' : 'muted');
  }

  function setPaymentMessage(dialog, message, isError) {
    var el = qs(dialog, '[data-helppay-payment-msg]');
    if (!el) return;
    if (!message) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = message;
  }

  function formatMoneyMajor(amount, currency) {
    var n = Number(amount);
    if (!isFinite(n)) n = 0;
    return String(currency || 'CNY') + ' ' + n.toFixed(2);
  }

  function readQuickProductId(btn) {
    var fromBtn = Number((btn && (btn.dataset.productId || btn.getAttribute('data-product-id'))) || 0) || 0;
    if (fromBtn > 0) return fromBtn;
    var root =
      (btn && btn.closest && btn.closest('.product-native-detail, [data-testid="storefront-product-detail"]')) ||
      qs(d, '[data-testid="storefront-product-detail"], .product-native-detail');
    if (root) {
      var fromRoot = Number(root.getAttribute('data-product-id') || root.dataset.productId || 0) || 0;
      if (fromRoot > 0) return fromRoot;
      var buyNow =
        qs(root, '[data-action="buy-now"][data-product-id]') ||
        qs(root, '[data-testid="product-buy-now"][data-product-id]');
      var fromBuy = Number((buyNow && (buyNow.dataset.productId || buyNow.getAttribute('data-product-id'))) || 0) || 0;
      if (fromBuy > 0) return fromBuy;
    }
    return 0;
  }

  function mapQuoteOptions(payload) {
    var data = (payload && (payload.data || payload)) || {};
    var options = Array.isArray(data.options) ? data.options : [];
    var out = [];
    options.forEach(function (option) {
      if (!option || typeof option !== 'object') return;
      var code = String(option.service_code || option.code || '').trim();
      if (!code) return;
      var label = String(option.label || option.service_name || option.title || option.name || '').trim();
      if (!label) label = code;
      var amountMinor = Number(option.amount_minor || 0) || 0;
      out.push({
        code: code,
        label: label,
        title: label,
        amount_minor: amountMinor,
        amount: amountMinor / 100,
      });
    });
    return out;
  }

  async function loadQuickShippingMethods(dialog) {
    var state = dialog._helppayQuickState || {};
    var addr = state.address || {};
    var btn = dialog._helppayQuickBtn;
    var goodsMinor = Number(state.goods_amount_minor || 0) || 0;
    var currency = readCurrencyCode();
    var list = qs(dialog, '[data-helppay-shipping-list]');
    if (list) {
      list.innerHTML =
        '<p class="w-text" data-tone="muted">' +
        escapeHtml(t('loadingShipping', '正在计算运费…')) +
        '</p>';
    }
    if (!w.Weline || !w.Weline.Api || typeof w.Weline.Api.resource !== 'function') {
      throw new Error(t('shippingQuoteFailed', '运费报价失败，请稍后重试。'));
    }
    var payload = await w.Weline.Api.resource('helpPay').listQuickShippingOptions(
      {
        shipping_address: {
          name: addr.name,
          contact_name: addr.name,
          phone: addr.phone,
          contact_phone: addr.phone,
          street: addr.line1,
          address1: addr.line1,
          line1: addr.line1,
          country: addr.country || 'CN',
          country_code: addr.country_code || addr.country || 'CN',
          province: addr.province || '',
          city: addr.city || '',
          district: addr.district || '',
          postal_code: addr.postal_code || '',
        },
        product_id: readQuickProductId(btn),
        qty: Math.max(1, readSelectedQty(btn) || 1),
        goods_amount_minor: goodsMinor,
        currency_code: currency,
        cart_type: 'toc',
      },
      { silent: true }
    );
    var data = (payload && (payload.data || payload)) || {};
    if (data.missing_weight || (data.quote_diagnostics && data.quote_diagnostics.missing_weight)) {
      throw new Error(
        t(
          'missingWeight',
          '购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。'
        )
      );
    }
    var methods = mapQuoteOptions(payload);
    if (!methods.length) {
      throw new Error(t('shippingUnavailable', '该地址暂无可用配送方式，请更换地址后重试。'));
    }
    state.shipping_methods = methods;
    dialog._helppayQuickState = state;
    if (!list) return methods;
    list.innerHTML = '';
    methods.forEach(function (method, index) {
      var id = 'helppay-ship-' + index;
      var label = d.createElement('label');
      label.className = 'w-helppay-ship-option';
      label.setAttribute('data-testid', 'helppay-ship-option');
      var input = d.createElement('input');
      input.type = 'radio';
      input.name = 'helppay_service_code';
      input.value = method.code;
      input.id = id;
      if (index === 0) {
        input.checked = true;
        state.service_code = method.code;
        state.service_label = method.label;
        state.shipping_amount_minor = method.amount_minor;
      }
      input.addEventListener('change', function () {
        if (!input.checked) return;
        state.service_code = method.code;
        state.service_label = method.label;
        state.shipping_amount_minor = method.amount_minor;
      });
      var span = d.createElement('span');
      span.textContent = method.label + ' — ' + formatMoneyMajor(method.amount, currency);
      label.appendChild(input);
      label.appendChild(span);
      list.appendChild(label);
    });
    return methods;
  }

  async function openQuickShippingStep(dialog) {
    showStep(dialog, 'shipping-pick');
    setShippingPickMessage(dialog, '');
    var backBtn = qs(dialog, '[data-helppay-back-address]');
    var nextBtn = qs(dialog, '[data-helppay-confirm-shipping]');
    if (backBtn) {
      backBtn.onclick = function () {
        showStep(dialog, 'address-pick');
      };
    }
    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.onclick = function () {
        confirmQuickShipping(dialog);
      };
    }
    try {
      await loadQuickShippingMethods(dialog);
      if (nextBtn) nextBtn.disabled = false;
    } catch (err) {
      setShippingPickMessage(dialog, humanizeApiError(err, 'quick'), true);
      if (nextBtn) nextBtn.disabled = false;
    }
  }

  /**
   * @param {{name?:string,phone?:string,line1?:string,district?:string,city?:string,province?:string,postal_code?:string,country?:string,country_code?:string}|null} addr
   */
  function formatShipToLines(addr) {
    if (!addr || typeof addr !== 'object') {
      return { primary: '', secondary: '' };
    }
    var name = String(addr.name || '').trim();
    var phone = String(addr.phone || '').trim();
    var primaryParts = [];
    if (name) primaryParts.push(name);
    if (phone) primaryParts.push(phone);
    var loc = [
      String(addr.line1 || '').trim(),
      String(addr.district || '').trim(),
      String(addr.city || '').trim(),
      String(addr.province || '').trim(),
      String(addr.postal_code || '').trim(),
      String(addr.country_code || addr.country || '').trim().toUpperCase(),
    ].filter(Boolean);
    return {
      primary: primaryParts.join(' · '),
      secondary: loc.join(', '),
    };
  }

  function renderPaymentSummary(dialog) {
    var state = dialog._helppayQuickState || {};
    var currency = readCurrencyCode();
    var goods = Number(state.goods_amount_minor || 0) / 100;
    var ship = Number(state.shipping_amount_minor || 0) / 100;
    var total = goods + ship;
    var host = qs(dialog, '[data-helppay-payment-summary]');
    if (!host) return;
    var spec = state.spec || null;
    if (!spec && dialog._helppayQuickBtn) {
      spec = readCurrentSpecSummary(dialog._helppayQuickBtn);
      if (spec) {
        state.spec = spec;
        dialog._helppayQuickState = state;
      }
    }
    var specHtml = buildShareSpecHtml(spec, t('paySummaryGoods', '商品'));
    var shipTo = formatShipToLines(state.address || null);
    var shipLabel = String(state.service_label || state.service_code || '').trim();
    var addressHtml = '';
    if (shipTo.primary || shipTo.secondary) {
      addressHtml =
        '<div class="w-helppay-pay-summary__ship-to" data-testid="helppay-payment-ship-to">' +
        '<p class="w-helppay-share-result__section-label">' +
        escapeHtml(t('paySummaryShipTo', '收货')) +
        '</p>' +
        (shipTo.primary
          ? '<p class="w-helppay-pay-summary__ship-primary w-text" data-weight="strong">' +
            escapeHtml(shipTo.primary) +
            '</p>'
          : '') +
        (shipTo.secondary
          ? '<p class="w-helppay-pay-summary__ship-secondary w-text" data-size="sm" data-tone="muted">' +
            escapeHtml(shipTo.secondary) +
            '</p>'
          : '') +
        '</div>';
    }
    var moneyHtml =
      '<div class="w-helppay-pay-summary__money" data-testid="helppay-payment-money">' +
      '<div class="w-helppay-pay-summary__row">' +
      '<span class="w-text" data-size="sm" data-tone="muted">' +
      escapeHtml(t('paySummaryGoods', '商品')) +
      '</span>' +
      '<span class="w-text" data-testid="helppay-payment-goods-amount">' +
      escapeHtml(formatMoneyMajor(goods, currency)) +
      '</span>' +
      '</div>' +
      '<div class="w-helppay-pay-summary__row">' +
      '<span class="w-text" data-size="sm" data-tone="muted">' +
      escapeHtml(t('paySummaryShipping', '运费')) +
      (shipLabel !== '' ? ' · ' + escapeHtml(shipLabel) : '') +
      '</span>' +
      '<span class="w-text" data-testid="helppay-payment-ship-amount">' +
      escapeHtml(formatMoneyMajor(ship, currency)) +
      '</span>' +
      '</div>' +
      '<div class="w-helppay-pay-summary__row w-helppay-pay-summary__row--total">' +
      '<span class="w-text" data-weight="strong">' +
      escapeHtml(t('paySummaryTotal', '应付')) +
      '</span>' +
      '<span class="w-text w-helppay-panel__amount" data-weight="strong" data-testid="helppay-payment-total">' +
      escapeHtml(formatMoneyMajor(total, currency)) +
      '</span>' +
      '</div>' +
      '</div>';
    host.classList.add('w-helppay-pay-summary');
    host.innerHTML = specHtml + addressHtml + moneyHtml;
  }

  async function openQuickPaymentStep(dialog) {
    showStep(dialog, 'payment');
    setPaymentMessage(dialog, '');
    renderPaymentSummary(dialog);
    var state = dialog._helppayQuickState || {};
    var backBtn = qs(dialog, '[data-helppay-back-shipping]');
    var payBtn = qs(dialog, '[data-helppay-start-pay]');
    var cross = qs(dialog, '[data-helppay-cross-device]');
    if (backBtn) {
      backBtn.onclick = function () {
        showStep(dialog, 'shipping-pick');
      };
    }
    if (payBtn) {
      payBtn.disabled = true;
      payBtn.textContent = t('creatingPay', '正在准备支付…');
    }
    try {
      var addr = state.address || {};
      var goodsMinor = Number(state.goods_amount_minor || 0) || 0;
      var shipMinor = Number(state.shipping_amount_minor || 0) || 0;
      var result = await w.Weline.Api.resource('helpPay').createQuickPay({
        // Keep top-level params within frontend worker whitelist.
        amount_minor: goodsMinor + shipMinor,
        currency_code: readCurrencyCode(),
        product_id: readQuickProductId(dialog._helppayQuickBtn),
        qty: Math.max(1, readSelectedQty(dialog._helppayQuickBtn) || 1),
        service_code: state.service_code || '',
        service_label: state.service_label || '',
        goods_amount_minor: goodsMinor,
        shipping_amount_minor: shipMinor,
        shipping_address: {
          name: addr.name,
          line1: addr.line1,
          phone: addr.phone,
          country: addr.country || 'CN',
          country_code: addr.country_code || addr.country || 'CN',
          province: addr.province || '',
          city: addr.city || '',
          district: addr.district || '',
          postal_code: addr.postal_code || '',
          service_code: state.service_code || '',
          service_label: state.service_label || '',
          label: state.service_label || '',
          shipping_amount_minor: shipMinor,
          goods_amount_minor: goodsMinor,
        },
        cart_type: 'toc',
      });
      if (!result || !result.url) {
        throw new Error(t('quickPayCreateFailed', '未返回付款入口，请稍后重试。'));
      }
      trackPixel(
        'quick_buy_checkout_ready',
        {
          pay_url: String(result.url || ''),
          token: String(result.token || ''),
          source: 'helppay',
          trigger: 'checkout_ready',
        },
        dialog._helppayQuickBtn || payBtn
      );
      state.pay_url = absoluteUrl(result.url);
      state.token = result.token || '';
      dialog._helppayQuickState = state;
      if (cross) {
        cross.hidden = false;
        var urlText = qs(cross, '[data-helppay-pay-url-text]');
        if (urlText) urlText.textContent = state.pay_url;
      }
      if (payBtn) {
        payBtn.disabled = false;
        payBtn.textContent = t('confirmPay', '确认支付');
        payBtn.onclick = function () {
          startQuickPayInPopup(dialog);
        };
      }
    } catch (err) {
      setPaymentMessage(dialog, humanizeApiError(err, 'quick'), true);
      if (payBtn) {
        payBtn.disabled = false;
        payBtn.textContent = t('confirmPay', '确认支付');
      }
    }
  }

  function startQuickPayInPopup(dialog) {
    var state = dialog._helppayQuickState || {};
    var url = state.pay_url || '';
    if (!url) {
      setPaymentMessage(dialog, t('quickPayCreateFailed', '未返回付款入口，请稍后重试。'), true);
      return;
    }
    setPaymentMessage(dialog, t('payPopupOpened', '已打开支付窗口，请在窗口内完成付款。'), false);
    openPayPopup(url);
    showStep(dialog, 'result');
    var host = qs(dialog, '[data-helppay-step="result"]');
    renderQuickCheckoutLaunch(host, url);
  }

  function confirmQuickShipping(dialog) {
    var state = dialog._helppayQuickState || {};
    var selected = qs(dialog, 'input[name="helppay_service_code"]:checked');
    if (!selected) {
      setShippingPickMessage(dialog, t('shippingRequired', '请选择配送方式。'), true);
      return;
    }
    var code = String(selected.value || '').trim();
    var methods = Array.isArray(state.shipping_methods) ? state.shipping_methods : [];
    var matched = null;
    methods.forEach(function (m) {
      if (m && m.code === code) matched = m;
    });
    state.service_code = code;
    state.service_label = matched ? matched.label : code;
    state.shipping_amount_minor = matched ? matched.amount_minor : 0;
    dialog._helppayQuickState = state;
    openQuickPaymentStep(dialog);
  }

  async function confirmQuickPayFromAddress(dialog) {
    var btn = dialog._helppayQuickBtn;
    var confirmBtn = qs(dialog, '[data-helppay-confirm-quick]');
    setAddressPickMessage(dialog, '');
    if (confirmBtn) confirmBtn.disabled = true;
    try {
      if (!btn || btn.disabled) {
        throw new Error('当前规格暂不可售，无法生成快捷购买链接。');
      }
      if (currentSellingMode() === 'tob') {
        throw new Error('helppay_toc_only');
      }
      if (isPurchaseUnavailable()) {
        throw new Error('当前规格暂不可售，无法生成快捷购买链接。');
      }
      var api = resolveDialogShippingApi(dialog);
      if (!api || typeof api.resolveQuoteAddress !== 'function') {
        throw new Error(t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'));
      }
      var resolved = api.resolveQuoteAddress();
      if (typeof api.validateShippingFields === 'function') {
        var ok = await api.validateShippingFields(resolved || {});
        if (ok === false) {
          setAddressPickMessage(dialog, t('addressIncomplete', '请先完善收货地址后再生成快捷购买链接。'), true);
          return;
        }
      }
      var addr = mapResolvedToShipping(resolved);
      if (!addr.complete) {
        setAddressPickMessage(dialog, t('addressIncomplete', '请先完善收货地址后再生成快捷购买链接。'), true);
        return;
      }
      var amount = readAmountMinor(btn);
      if (!(amount > 0)) {
        throw new Error('无法读取商品金额，请刷新后重试。');
      }
      dialog._helppayQuickState = {
        address: addr,
        goods_amount_minor: amount,
        shipping_amount_minor: 0,
        service_code: '',
        service_label: '',
        shipping_methods: [],
        spec: readCurrentSpecSummary(btn),
      };
      // Popup checkout: address → shipping → payment (never location.assign).
      await openQuickShippingStep(dialog);
    } catch (err) {
      showStep(dialog, 'error');
      renderError(qs(dialog, '[data-helppay-step="error"]'), humanizeApiError(err, 'quick'));
    } finally {
      if (confirmBtn) confirmBtn.disabled = false;
    }
  }

  async function createQuickPayAndShow(btn) {
    if (btn.disabled) return;
    await openQuickPayAddressStep(btn);
  }

  var bootBound = false;

  function setBillingMessage(root, text, isError) {
    var msg = qs(root, '[data-helppay-billing-msg]');
    if (!msg) return;
    if (!text) {
      msg.hidden = true;
      msg.textContent = '';
      return;
    }
    msg.hidden = false;
    msg.textContent = text;
    msg.setAttribute('data-tone', isError === false ? 'muted' : 'danger');
  }

  function setPayerPayMessage(root, text, isError) {
    var msg = qs(root, '[data-helppay-pay-msg]');
    if (!msg) return;
    if (!text) {
      msg.hidden = true;
      msg.textContent = '';
      return;
    }
    msg.hidden = false;
    msg.textContent = text;
    msg.setAttribute('data-tone', isError === false ? 'success' : 'danger');
  }

  function selectedPayerPaymentMethod(root) {
    return qs(root, '[data-helppay-payment-method]:checked');
  }

  function payerBillingRequired(root) {
    var radio = selectedPayerPaymentMethod(root);
    if (!radio) return false;
    return radio.getAttribute('data-requires-billing') === '1';
  }

  function syncPayerBillingVisibility(root) {
    if (!root) return;
    var panel = qs(root, '[data-helppay-billing-panel]');
    if (!panel) return;
    var required = payerBillingRequired(root);
    panel.hidden = !required;
    if (!required) {
      setBillingMessage(root, '');
      root._helppayBillingAddress = null;
    }
  }

  function enhancePayerPaymentIntros(root) {
    if (!root) return;
    qsa(root, '[data-payment-intro]').forEach(function (wrap) {
      var text = qs(wrap, '.weline-checkout__payment-intro-text');
      var toggle = qs(wrap, '[data-payment-intro-toggle]');
      if (!text || !toggle) return;
      wrap.classList.remove('is-expanded');
      toggle.hidden = text.scrollHeight <= text.clientHeight + 1;
      toggle.textContent = toggle.getAttribute('data-label-expand') || toggle.textContent;
      toggle.setAttribute('aria-expanded', 'false');
    });
  }

  function wirePayerPaymentMethods(root) {
    if (!root || root._helppayPaymentWired) return;
    root._helppayPaymentWired = true;
    root.addEventListener('change', function (ev) {
      var t = ev && ev.target;
      if (!t || !t.getAttribute || t.getAttribute('data-helppay-payment-method') === null) return;
      syncPayerBillingVisibility(root);
      if (payerBillingRequired(root)) {
        ensurePayerBillingMounted(root)
          .then(function (api) {
            root._helppayBillingApi = api;
          })
          .catch(function () {});
      }
    });
    root.addEventListener('click', function (ev) {
      var t = ev && ev.target;
      var toggle = t && t.closest ? t.closest('[data-payment-intro-toggle]') : null;
      if (!toggle || !root.contains(toggle)) return;
      ev.preventDefault();
      ev.stopPropagation();
      var wrap = toggle.closest('[data-payment-intro]');
      if (!wrap) return;
      var expanded = wrap.classList.toggle('is-expanded');
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.textContent = expanded
        ? (toggle.getAttribute('data-label-collapse') || toggle.textContent)
        : (toggle.getAttribute('data-label-expand') || toggle.textContent);
    });
    enhancePayerPaymentIntros(root);
    syncPayerBillingVisibility(root);
  }

  async function ensurePayerBillingMounted(root) {
    var mount = qs(root, '[data-helppay-billing-mount]');
    if (!mount) return null;
    var form = qs(mount, '[data-helppay-billing-host]');
    if (form) {
      form.setAttribute('data-session-isolation', '1');
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
      });
    }
    var addrRoot = qs(mount, '[data-shipping-checkout-address]');
    if (!addrRoot) return null;
    addrRoot.setAttribute('data-session-isolation', '1');
    if (w.Weline && typeof w.Weline.load === 'function') {
      await w.Weline.load('shippingCheckoutAddress');
    }
    if (w.WelineShippingCheckoutAddress && typeof w.WelineShippingCheckoutAddress.mount === 'function') {
      var mounted = w.WelineShippingCheckoutAddress.mount(addrRoot);
      return mounted || w.WelineShippingCheckoutAddress;
    }
    if (w.WelineShippingCheckoutAddress && w.WelineShippingCheckoutAddress.root === addrRoot) {
      return w.WelineShippingCheckoutAddress;
    }
    return null;
  }

  async function confirmPayerBilling(root) {
    setBillingMessage(root, '');
    if (!payerBillingRequired(root)) {
      root._helppayBillingAddress = null;
      return { skipped: true };
    }
    var api = root._helppayBillingApi;
    if (!api || typeof api.resolveQuoteAddress !== 'function') {
      api = await ensurePayerBillingMounted(root);
      root._helppayBillingApi = api;
    }
    if (!api || typeof api.resolveQuoteAddress !== 'function') {
      setBillingMessage(root, t('addressLoadFailed', '收货地址组件加载失败，请稍后重试。'), true);
      return null;
    }
    var resolved = api.resolveQuoteAddress();
    if (typeof api.validateShippingFields === 'function') {
      var ok = await api.validateShippingFields(resolved || {});
      if (ok === false) {
        setBillingMessage(root, t('billingIncomplete', '请先完善付款账单地址。'), true);
        return null;
      }
    }
    var addr = mapResolvedToShipping(resolved);
    if (!addr.complete) {
      setBillingMessage(root, t('billingIncomplete', '请先完善付款账单地址。'), true);
      return null;
    }
    root._helppayBillingAddress = {
      name: addr.name,
      phone: addr.phone,
      line1: addr.line1,
      country: addr.country,
      country_code: addr.country_code,
      city: addr.city,
      province: addr.province,
      district: addr.district,
      postal_code: addr.postal_code,
    };
    return root._helppayBillingAddress;
  }

  async function onQuickSelfPayClick(btn) {
    var root = btn.closest('[data-testid="quick-pay-self"]') || d;
    var token = String(btn.getAttribute('data-token') || '').trim();
    if (!token) {
      var m = String(w.location.pathname || '').match(/\/q\/([^/?#]+)/);
      token = m ? decodeURIComponent(m[1]) : '';
    }
    var method = String(btn.getAttribute('data-payment-method') || '').trim();
    if (!token) {
      setPayerPayMessage(root, t('cannotComplete', '无法完成操作'), true);
      return;
    }
    if (!method) {
      // 服务端未下发可用支付方式（Provider 列表为空）：明确提示，不编造 code 兜底。
      setPayerPayMessage(root, t('noPaymentMethod', '暂无可用支付方式，请稍后重试。'), true);
      return;
    }
    btn.disabled = true;
    try {
      var payload = {
        token: token,
        payment_method: method,
        idempotency_key: 'quickpay_ui_' + token + '_' + Date.now(),
      };
      var result = await w.Weline.Api.resource('helpPay').startQuickPayment(payload);
      var data = (result && (result.data || result)) || {};
      var redirect = String(data.redirect_url || data.approve_url || data.success_url || '').trim();
      var status = String(data.status || '').toLowerCase();
      if (redirect) {
        w.location.assign(redirect);
        return;
      }
      if (data.paid || status === 'paid' || status === 'success' || status === 'succeeded') {
        var txn = String(data.transaction_no || '').trim();
        if (txn) {
          w.location.assign('/payment/success?transaction_no=' + encodeURIComponent(txn));
          return;
        }
        setPayerPayMessage(root, t('paySuccess', '付款已完成，感谢您的帮助。'), false);
        return;
      }
      setPayerPayMessage(root, t('payFailed', '无法发起支付，请刷新后重试。'), true);
    } catch (err) {
      setPayerPayMessage(root, humanizeApiError(err, 'payer') || t('payFailed', '无法发起支付，请刷新后重试。'), true);
    } finally {
      btn.disabled = false;
    }
  }

  async function onPayerPayClick(btn) {
    var root =
      (btn && btn.closest && btn.closest('[data-testid="help-pay-payer"]')) ||
      qs(d, '[data-testid="help-pay-payer"]');
    if (!root) return;
    btn.disabled = true;
    setPayerPayMessage(root, '');
    try {
      var billing = await confirmPayerBilling(root);
      if (billing === null) return;
      var method = selectedPayerPaymentMethod(root);
      var methodCode = method ? String(method.value || '').trim() : '';
      root._helppayPaymentMethod = methodCode;
      if (!methodCode) {
        setPayerPayMessage(root, t('paymentMethodRequired', '请选择支付方式。'), true);
        return;
      }
      var token = String(root.getAttribute('data-helppay-token') || '').trim();
      if (!token) {
        setPayerPayMessage(root, t('payFailed', '无法发起支付，请刷新后重试。'), true);
        return;
      }
      if (!(w.Weline && w.Weline.Api && typeof w.Weline.Api.resource === 'function')) {
        setPayerPayMessage(root, t('payFailed', '无法发起支付，请刷新后重试。'), true);
        return;
      }
      var payload = {
        token: token,
        payment_method: methodCode,
        idempotency_key: 'helppay_' + token + '_' + methodCode + '_' + Date.now(),
      };
      if (billing && !billing.skipped) {
        payload.billing_address = billing;
      }
      setPayerPayMessage(root, t('payStarting', '正在跳转支付…'), false);
      var result = await w.Weline.Api.resource('helpPay').startPayerPayment(payload);
      var data = result && typeof result === 'object' ? result : {};
      if (data.data && typeof data.data === 'object') {
        data = data.data;
      }
      var url = String(data.redirect_url || data.approve_url || '').trim();
      if (url) {
        w.location.assign(url);
        return;
      }
      var status = String(data.status || '').toLowerCase();
      if (data.paid || status === 'paid' || status === 'success' || status === 'succeeded') {
        setPayerPayMessage(root, t('paySuccess', '付款已完成，感谢您的帮助。'), false);
        return;
      }
      setPayerPayMessage(root, t('payFailed', '无法发起支付，请刷新后重试。'), true);
    } catch (err) {
      setPayerPayMessage(root, humanizeApiError(err, 'payer') || t('payFailed', '无法发起支付，请刷新后重试。'), true);
    } finally {
      btn.disabled = false;
    }
  }

  function onHelpPayDelegatedClick(ev) {
    var t = ev && ev.target;
    if (!t || typeof t.closest !== 'function') return;

    var payerPayBtn = t.closest('[data-helppay-pay]');
    if (payerPayBtn && payerPayBtn.closest('[data-testid="help-pay-payer"]')) {
      if (payerPayBtn.disabled || payerPayBtn.getAttribute('aria-disabled') === 'true') return;
      onPayerPayClick(payerPayBtn);
      return;
    }
    if (payerPayBtn && (payerPayBtn.getAttribute('data-helppay-quick-self-pay') === '1'
      || payerPayBtn.closest('[data-testid="quick-pay-self"]'))) {
      if (payerPayBtn.disabled || payerPayBtn.getAttribute('aria-disabled') === 'true') return;
      onQuickSelfPayClick(payerPayBtn);
      return;
    }

    var openBtn = t.closest('[data-helppay-open]');
    if (openBtn) {
      if (openBtn.disabled || openBtn.getAttribute('aria-disabled') === 'true') return;
      var wrap = openBtn.closest('[data-helppay-placement]');
      var placement = wrap ? wrap.getAttribute('data-helppay-placement') : 'cart';
      if (placement === 'product' || openBtn.getAttribute('data-helppay-product-help-pay') === '1') {
        openProductHelpPayFlow(openBtn);
        return;
      }
      openFlow(placement, openBtn);
      return;
    }

    var shareBtn = t.closest('[data-helppay-selection-share]');
    if (shareBtn) {
      if (shareBtn.disabled || shareBtn.getAttribute('aria-disabled') === 'true') return;
      createSelectionShareAndShow(shareBtn);
      return;
    }

    var quickBtn = t.closest('[data-helppay-quick-pay]');
    if (quickBtn) {
      if (quickBtn.disabled || quickBtn.getAttribute('aria-disabled') === 'true') return;
      createQuickPayAndShow(quickBtn);
    }
  }

  function boot() {
    ensureShareCss();
    mergeShareI18n(null);
    revealCtas();

    var payerRoot = qs(d, '[data-testid="help-pay-payer"]');
    if (payerRoot) {
      wirePayerPaymentMethods(payerRoot);
      if (payerBillingRequired(payerRoot)) {
        ensurePayerBillingMounted(payerRoot)
          .then(function (api) {
            payerRoot._helppayBillingApi = api;
          })
          .catch(function () {});
      }
    }

    if (bootBound) {
      syncQuickPayCtas();
      return;
    }
    bootBound = true;

    d.addEventListener('click', onHelpPayDelegatedClick);

    d.querySelectorAll('[data-testid="help-pay-share-result"]').forEach(function (root) {
      wireShareResult(root);
    });

    w.addEventListener('weline:selling-mode-changed', syncQuickPayCtas);
    w.addEventListener('weline:cart-type-changed', syncQuickPayCtas);
    var observeRoot = qs(d, '.product-native-detail, [data-product-detail], main') || d.body;
    if (w.MutationObserver && observeRoot) {
      var mo = new MutationObserver(function () {
        syncQuickPayCtas();
      });
      mo.observe(observeRoot, {
        attributes: true,
        subtree: true,
        attributeFilter: ['disabled', 'hidden', 'data-selling-mode', 'data-stock-tone', 'class'],
        childList: true,
      });
    }
    // Late sync after PDP offer hydration / purchase-panel inject.
    setTimeout(syncQuickPayCtas, 0);
    setTimeout(syncQuickPayCtas, 400);
    setTimeout(syncQuickPayCtas, 1200);
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot);
  else boot();

  w.WelineModules = w.WelineModules || {};
  w.WelineModules.helpPayShare = {
    boot: boot,
    wireShareResult: wireShareResult,
    syncQuickPayCtas: syncQuickPayCtas,
    ensureShareCss: ensureShareCss,
    revealDialog: revealDialog,
    hideDialog: hideDialog,
    resolveOverlayHost: resolveOverlayHost,
    ensurePayerBillingMounted: ensurePayerBillingMounted,
    confirmPayerBilling: confirmPayerBilling,
    syncPayerBillingVisibility: syncPayerBillingVisibility,
    wirePayerPaymentMethods: wirePayerPaymentMethods,
  };
})(window, document);
