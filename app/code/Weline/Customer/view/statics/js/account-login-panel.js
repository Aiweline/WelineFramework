/**
 * Mountable storefront login panel (social quick + account/password).
 * Surface: customer/login-panel — see doc/挂载面.md
 */
(function (window, document) {
  'use strict';

  var MOUNT_LOGIN_PANEL = 'customer/login-panel';
  var MOUNT_SOCIAL_QUICK = 'customer/social-quick';

  function attr(host, name, fallback) {
    try {
      var v = host && host.getAttribute(name);
      if (v != null && String(v).trim() !== '') {
        return String(v);
      }
    } catch (_e) { /* ignore */ }
    return fallback == null ? '' : String(fallback);
  }

  function currentReturnUrl(host) {
    var fromHost = attr(host, 'data-w-auth-return', '')
      || attr(host, 'data-login-return', '');
    if (fromHost) {
      return fromHost;
    }
    return (window.location.pathname || '/')
      + (window.location.search || '')
      + (window.location.hash || '');
  }

  function loginActionUrl(host) {
    return attr(host, 'data-login-action', '/customer/account/login');
  }

  function registerUrl(host) {
    var base = attr(host, 'data-register-url', '/customer/account/register');
    var ret = currentReturnUrl(host);
    if (!ret) {
      return base;
    }
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'redirect_url=' + encodeURIComponent(ret);
  }

  function forgotUrl(host) {
    var base = attr(host, 'data-forgot-url', '/customer/account/forgot-password');
    var ret = currentReturnUrl(host);
    if (!ret) {
      return base;
    }
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'redirect_url=' + encodeURIComponent(ret);
  }

  function ensurePanelStyles() {
    var STYLE_ID = 'weline-login-panel-style';
    var STYLE_TOKEN = 'remember-clock-align-margin0-20260910';
    var existing = document.getElementById(STYLE_ID);
    if (existing && existing.getAttribute('data-weline-style-token') === STYLE_TOKEN) {
      return;
    }
    if (existing) {
      existing.parentNode && existing.parentNode.removeChild(existing);
    }
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.setAttribute('data-weline-style-token', STYLE_TOKEN);
    style.textContent = [
      '.weline-login-panel{box-sizing:border-box;width:100%;display:flex;flex-direction:column;gap:12px;',
      'font:400 13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;',
      'color:var(--color-text-primary,var(--weline-theme-text,#0f1111))}',
      '.weline-login-panel__title{margin:0;font-weight:700;font-size:15px}',
      '.weline-login-panel__subtitle{margin:0;opacity:.82}',
      '.weline-login-panel__social-title{margin:0;font-size:12px;font-weight:650;',
      'color:var(--color-text-secondary,var(--weline-theme-text-muted,#565959))}',
      '.weline-login-panel__social[data-weline-mount]{min-height:0}',
      /* 审图：内嵌 social 去嵌套卡片底/边框，只留按钮列 */
      '.weline-login-panel .weline-social-quick-inline,',
      '.weline-login-panel .weline-social-quick-inline--flush{',
      'background:transparent;border:0;border-radius:0;padding:0;box-shadow:none}',
      '.weline-login-panel__divider{display:flex;align-items:center;gap:10px;margin:2px 0;',
      'color:var(--color-text-secondary,var(--weline-theme-text-muted,#565959));font-size:12px}',
      '.weline-login-panel__divider::before,.weline-login-panel__divider::after{content:"";flex:1;height:1px;',
      'background:var(--color-border-light,var(--weline-theme-border-color,#d5d9d9))}',
      '.weline-login-panel__form{display:flex;flex-direction:column;gap:14px;margin:0;',
      '--weline-theme-field-label-bg:var(--weline-theme-surface,var(--color-bg,#fff))}',
      '.weline-login-panel .w-field{gap:0}',
      '.weline-login-panel .w-input{min-height:2.5rem}',
      '.weline-login-panel__meta{display:block;width:100%;min-width:0}',
      '.weline-login-panel__meta-tools{display:flex;align-items:center;justify-content:space-between;',
      'gap:10px 12px;flex-wrap:nowrap;min-width:0;width:100%}',
      '.weline-login-panel__remember{flex:1 1 auto;align-self:center;min-width:0;max-width:12.5rem;margin:0!important;gap:0}',
      '.weline-login-panel__remember .w-field__label{display:inline-flex;align-items:center;gap:.25rem}',
      '.weline-login-panel__remember .w-field__label .w-icon{width:1rem;height:1rem;min-width:1rem;min-height:1rem;flex:0 0 1rem}',
      '.weline-login-panel__remember .w-select{min-height:var(--weline-control-height-sm,1.75rem);width:100%;',
      'padding-inline-end:calc(1rem + 1.15rem);background-position:right 1rem center}',
      '.weline-login-panel__meta-tools .w-auth-login__forgot-link{flex:0 0 auto;white-space:nowrap;',
      'display:inline-flex;align-items:center;min-height:var(--weline-control-height-sm,1.75rem);',
      'position:relative;z-index:1;padding-inline-end:2px}',
      '.weline-login-panel__feedback{margin:0}',
      '.weline-login-panel__feedback[hidden]{display:none!important}',
      '.weline-login-panel__footer{margin:0;font-size:12px}',
      '.weline-login-panel__footer a{color:var(--color-link,var(--weline-theme-link,#007185))}'
    ].join('');
    document.head.appendChild(style);
  }

  function normalizeLoginPayload(raw) {
    if (raw == null) return null;
    if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) {
      if (typeof raw.success !== 'undefined' || typeof raw.redirect !== 'undefined'
        || raw.status === 'authenticated' || raw.status === 'challenge_required') {
        return raw;
      }
      if (raw.data && typeof raw.data === 'object') {
        return normalizeLoginPayload(raw.data);
      }
    }
    return null;
  }

  function isLoginSuccessPayload(payload) {
    if (!payload || typeof payload !== 'object') return false;
    if (payload.success === true || payload.success === 'true') return true;
    return payload.status === 'authenticated';
  }

  function safeDestination(target, fallback) {
    var defaultPath = String(fallback || '/');
    try {
      var parsed = new URL(String(target || defaultPath), window.location.origin);
      if (parsed.origin === window.location.origin) {
        return parsed.pathname + parsed.search + parsed.hash;
      }
    } catch (_e) { /* ignore */ }
    return defaultPath;
  }

  function bindForm(panel, host) {
    var form = panel.querySelector('[data-w-login-form]');
    var submitBtn = panel.querySelector('[data-w-login-submit]');
    var feedback = panel.querySelector('[data-w-login-feedback]');
    var username = panel.querySelector('[data-w-login-username]');
    var password = panel.querySelector('[data-w-login-password]');
    var remember = panel.querySelector('[data-w-login-remember]');
    if (!form || !username || !password || !submitBtn) {
      return;
    }
    var submitting = false;
    var setBusy = function (busy) {
      submitting = busy;
      submitBtn.disabled = !!busy;
      submitBtn.setAttribute('aria-busy', String(!!busy));
      var idle = panel.querySelector('[data-w-login-idle-label]');
      var busyLabel = panel.querySelector('[data-w-login-busy-label]');
      if (idle) idle.hidden = !!busy;
      if (busyLabel) busyLabel.hidden = !busy;
    };
    var showFeedback = function (message) {
      if (!feedback) return;
      feedback.textContent = String(message || '');
      feedback.hidden = !message;
    };
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (submitting) return;
      showFeedback('');
      var userVal = String(username.value || '').trim();
      if (!userVal) {
        showFeedback(attr(host, 'data-i18n-username-required', '请输入用户名或邮箱'));
        username.focus();
        return;
      }
      if (!password.value) {
        showFeedback(attr(host, 'data-i18n-password-required', '请输入密码'));
        password.focus();
        return;
      }
      setBusy(true);
      var formData = new FormData(form);
      formData.set('username', userVal);
      formData.set('password', password.value);
      formData.set('remember_duration', String((remember && remember.value) || '0'));
      formData.set('redirect_url', currentReturnUrl(host));
      fetch(loginActionUrl(host), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      }).then(function (response) {
        return response.json().catch(function () { return null; }).then(function (raw) {
          return { okHttp: response.ok, raw: raw };
        });
      }).then(function (pack) {
        var payload = normalizeLoginPayload(pack && pack.raw);
        var ok = !!(pack && pack.raw && pack.raw.success !== false);
        if (ok && payload && payload.status === 'challenge_required' && payload.redirect) {
          window.location.assign(safeDestination(payload.redirect, loginActionUrl(host)));
          return;
        }
        if (ok && isLoginSuccessPayload(payload)) {
          try {
            window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
              detail: { user: (payload && payload.user) || null }
            }));
          } catch (_e) { /* ignore */ }
          var dest = safeDestination(
            (payload && payload.redirect) || currentReturnUrl(host),
            currentReturnUrl(host) || '/'
          );
          window.location.assign(dest);
          return;
        }
        setBusy(false);
        showFeedback(
          (payload && payload.message)
          || attr(host, 'data-i18n-login-failure', '登录失败，请检查用户名和密码')
        );
      }).catch(function () {
        setBusy(false);
        showFeedback(attr(host, 'data-i18n-request-failure', '登录请求失败，请稍后重试'));
      });
    });
  }

  function mountSocialSlot(slot, host) {
    if (!slot) {
      return Promise.resolve(false);
    }
    slot.setAttribute('data-weline-mount', MOUNT_SOCIAL_QUICK);
    slot.setAttribute('data-weline-mount-variant', 'inline');
    if (host.getAttribute('data-weline-mount-suppress-floating') === '1') {
      slot.setAttribute('data-weline-mount-suppress-floating', '1');
    }
    slot.setAttribute('data-weline-mount-state', 'pending');
    // Prefer Account prompt API directly — avoid nested Weline.mount.scan re-entering login-panel.
    if (window.WelineAccountModule && typeof window.WelineAccountModule.maybeStartSocialQuickPrompt === 'function') {
      return Promise.resolve(window.WelineAccountModule.maybeStartSocialQuickPrompt({
        force: true,
        host: slot
      })).then(function (r) {
        var ok = !!(r && r.started);
        if (!ok) {
          try {
            var panel = slot.closest('[data-weline-login-panel]');
            var title = panel && panel.querySelector('.weline-login-panel__social-title');
            if (title) {
              title.hidden = true;
            }
            slot.hidden = true;
          } catch (_h) { /* ignore */ }
        }
        return ok;
      });
    }
    return Promise.resolve(false);
  }

  function buildPanel(host) {
    ensurePanelStyles();
    var hideHeading = String(host.getAttribute('data-weline-login-panel-heading') || '') === '0';
    var title = attr(host, 'data-i18n-title', '登录');
    var subtitle = attr(host, 'data-i18n-subtitle', '使用您的账户继续');
    var socialTitle = attr(host, 'data-i18n-social-title', '快捷登录');
    var orLabel = attr(host, 'data-i18n-or-account', '或使用账户登录');
    var userLabel = attr(host, 'data-i18n-username', '用户名或邮箱');
    var passLabel = attr(host, 'data-i18n-password', '密码');
    var rememberLabel = attr(host, 'data-i18n-remember', '记住');
    var forgotLabel = attr(host, 'data-i18n-forgot', '忘记密码？');
    var submitLabel = attr(host, 'data-i18n-submit', '登录');
    var busyLabel = attr(host, 'data-i18n-busy', '登录中...');
    var newCustomer = attr(host, 'data-i18n-new-customer', '新客户？');
    var createAccount = attr(host, 'data-i18n-create-account', '创建您的账户');

    var panel = document.createElement('div');
    panel.className = 'weline-login-panel';
    panel.setAttribute('data-weline-login-panel', '1');
    panel.setAttribute('data-testid', 'customer-login-panel');
    if (hideHeading) {
      panel.setAttribute('data-weline-login-panel-heading', '0');
    }
    var headingHtml = hideHeading
      ? ''
      : ('<p class="weline-login-panel__title">' + title + '</p>'
        + '<p class="weline-login-panel__subtitle">' + subtitle + '</p>');
    panel.innerHTML = ''
      + headingHtml
      + '<p class="weline-login-panel__social-title product-native-detail__price-label">' + socialTitle + '</p>'
      + '<div class="weline-login-panel__social" data-weline-login-panel-social></div>'
      + '<p class="weline-login-panel__divider" role="presentation"><span>' + orLabel + '</span></p>'
      + '<div class="w-alert weline-login-panel__feedback" data-w-login-feedback role="alert" hidden></div>'
      + '<form class="weline-login-panel__form w-form" data-w-login-form method="post" action="'
      + loginActionUrl(host).replace(/"/g, '&quot;') + '">'
      + '<input type="hidden" name="redirect_url" value="">'
      + '<div class="w-field"><label class="w-field__label">' + userLabel + '</label>'
      + '<input class="w-input" type="text" name="username" data-w-login-username autocomplete="username" required></div>'
      + '<div class="w-field"><label class="w-field__label">' + passLabel + '</label>'
      + '<input class="w-input" type="password" name="password" data-w-login-password autocomplete="current-password" required></div>'
      + '<div class="weline-login-panel__meta">'
      + '<div class="weline-login-panel__meta-tools">'
      + '<div class="w-field weline-login-panel__remember">'
      + '<label class="w-field__label" for="weline-login-panel-remember">'
      + '<svg class="w-icon" data-size="md" data-icon="clock" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/></svg>'
      + rememberLabel + '</label>'
      + '<select class="w-select" id="weline-login-panel-remember" name="remember_duration" data-w-login-remember>'
      + '<option value="0">本次会话</option>'
      + '<option value="21600">6小时</option>'
      + '<option value="86400">1天</option>'
      + '<option value="604800" selected>1周</option>'
      + '<option value="2592000">1个月</option>'
      + '</select></div>'
      + '<a class="w-auth-login__forgot-link" href="' + forgotUrl(host).replace(/"/g, '&quot;') + '">'
      + forgotLabel + '</a></div></div>'
      + '<button type="submit" class="w-button" data-variant="primary" data-w-login-submit>'
      + '<span data-w-login-idle-label>' + submitLabel + '</span>'
      + '<span data-w-login-busy-label hidden>' + busyLabel + '</span>'
      + '</button></form>'
      + '<p class="weline-login-panel__footer">' + newCustomer + ' '
      + '<a href="' + registerUrl(host).replace(/"/g, '&quot;') + '">' + createAccount + '</a></p>';

    var hidden = panel.querySelector('input[name="redirect_url"]');
    if (hidden) {
      hidden.value = currentReturnUrl(host);
    }
    return panel;
  }

  function mountIntoHost(host, options) {
    var opts = options || {};
    if (!host || !host.getAttribute) {
      return Promise.resolve({ started: false, reason: 'no_host' });
    }
    if (String(host.getAttribute('data-weline-mount') || '') !== MOUNT_LOGIN_PANEL) {
      return Promise.resolve({ started: false, reason: 'wrong_surface' });
    }
    if (!opts.force
      && host.getAttribute('data-weline-mount-state') === 'ready'
      && host.querySelector('[data-weline-login-panel]')) {
      return Promise.resolve({ started: true, reason: 'already_mounted' });
    }
    var panel = buildPanel(host);
    host.innerHTML = '';
    host.appendChild(panel);
    bindForm(panel, host);
    var socialSlot = panel.querySelector('[data-weline-login-panel-social]');
    return mountSocialSlot(socialSlot, host).then(function () {
      host.setAttribute('data-weline-mount-state', 'ready');
      return { started: true, host: true };
    });
  }

  var api = {
    MOUNT_LOGIN_PANEL: MOUNT_LOGIN_PANEL,
    mount: mountIntoHost
  };
  window.WelineLoginPanel = api;

  // Self-provide when loaded via data-weline-mount-load — covers account-not-yet-provide races.
  (function selfProvide() {
    if (!window.Weline || !window.Weline.mount || typeof window.Weline.mount.provide !== 'function') {
      return;
    }
    window.Weline.mount.provide(MOUNT_LOGIN_PANEL, function (host, ctx) {
      return mountIntoHost(host, { force: !!(ctx && ctx.force) });
    });
  })();
})(window, document);
