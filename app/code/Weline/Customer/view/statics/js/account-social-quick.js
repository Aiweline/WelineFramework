/**
 * Social quick-auth bootstrap: Google One Tap + Facebook FedCM prompt.
 * No silent auto-login: user must click the prompt/chip (or Logo OAuth).
 * Safe to include once from login widget and/or global body-end hook.
 */
(function (window, document) {
  'use strict';

  // Do not abort the whole IIFE when already booted — mount/scan must remain available.

  function parseBootstrap(root) {
    // Prefer <script type="application/json"> — FPC/HTML rewriters often empty
    // data-* attributes that contain JSON (&quot; entities).
    try {
      var script = root.querySelector('script[type="application/json"][data-social-quick-config]');
      if (!script) {
        var next = root.nextElementSibling;
        if (next && next.matches && next.matches('script[type="application/json"][data-social-quick-config]')) {
          script = next;
        }
      }
      if (script && script.textContent) {
        return JSON.parse(String(script.textContent));
      }
    } catch (e) { /* fall through */ }
    var raw = root.getAttribute('data-social-quick');
    if (!raw || raw === '1') return null;
    try {
      return JSON.parse(raw);
    } catch (e2) {
      return null;
    }
  }

  function postForm(url, fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) {
      if (fields[key] != null && fields[key] !== '') {
        body.set(key, String(fields[key]));
      }
    });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString()
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, message: 'invalid_json' };
      });
    });
  }

  function currentStorefrontPath() {
    try {
      return String(window.location.pathname || '/')
        + String(window.location.search || '')
        + String(window.location.hash || '');
    } catch (e) {
      return '/';
    }
  }

  function isAuthRoutePath(path) {
    var bare = String(path || '').split('?')[0].split('#')[0].toLowerCase();
    return /(^|\/)customer\/account\/(login|register|forgot-password|challenge|logout|social-login)(\/|$)/.test(bare);
  }

  function isAccountHomePath(path) {
    var bare = String(path || '').split('?')[0].split('#')[0].replace(/\/+$/, '').toLowerCase();
    return /(^|\/)customer\/account$/.test(bare)
      || /(^|\/)customer\/account\/index$/.test(bare);
  }

  function withAuthRefresh(path) {
    try {
      var url = new URL(String(path || '/'), window.location.origin);
      url.searchParams.set('w_auth', '1');
      return url.pathname + url.search + url.hash;
    } catch (e) {
      var base = String(path || '/');
      return base.indexOf('?') >= 0 ? (base + '&w_auth=1') : (base + '?w_auth=1');
    }
  }

  function resolveReturnUrl(cfg) {
    var fromCfg = String((cfg && cfg.return_url) || '').trim();
    try {
      var root = document.querySelector('[data-w-auth-return]');
      var fromDom = root ? String(root.getAttribute('data-w-auth-return') || '').trim() : '';
      if (fromDom && !isAuthRoutePath(fromDom)) {
        return fromDom.charAt(0) === '/' ? fromDom : ('/' + fromDom);
      }
    } catch (e) { /* ignore */ }
    try {
      var params = new URLSearchParams(window.location.search || '');
      var fromQuery = String(
        params.get('redirect_url')
        || params.get('redirect')
        || params.get('return_url')
        || ''
      ).trim();
      if (fromQuery && !isAuthRoutePath(fromQuery)) {
        return fromQuery.charAt(0) === '/' || /^https?:\/\//i.test(fromQuery)
          ? fromQuery
          : ('/' + fromQuery);
      }
    } catch (e) { /* ignore */ }
    if (fromCfg && !isAuthRoutePath(fromCfg)) {
      return fromCfg.charAt(0) === '/' || /^https?:\/\//i.test(fromCfg)
        ? fromCfg
        : ('/' + fromCfg);
    }
    var here = currentStorefrontPath();
    return isAuthRoutePath(here) ? '' : here;
  }

  function hydrateAuthReturnTargets(returnUrl) {
    if (!returnUrl) return;
    try {
      document.querySelectorAll('input[name="redirect_url"]').forEach(function (input) {
        if (input instanceof HTMLInputElement && !String(input.value || '').trim()) {
          input.value = returnUrl;
        }
      });
      document.querySelectorAll('a[href*="customer/account/social-login/start"]').forEach(function (link) {
        if (!(link instanceof HTMLAnchorElement) || !link.href) return;
        try {
          var url = new URL(link.href, window.location.origin);
          if (!url.searchParams.get('return_url') && !url.searchParams.get('redirect_url')) {
            url.searchParams.set('return_url', returnUrl);
            link.href = url.toString();
          }
        } catch (e) { /* ignore */ }
      });
    } catch (e) { /* ignore */ }
  }

  function go(redirect, preferredReturnUrl) {
    var target = String(redirect || '').trim();
    var preferred = String(preferredReturnUrl || '').trim();
    if (preferred && !isAuthRoutePath(preferred) && (!target || isAccountHomePath(target))) {
      target = withAuthRefresh(preferred.charAt(0) === '/' || /^https?:\/\//i.test(preferred)
        ? preferred
        : ('/' + preferred));
    }
    if (!target) {
      return;
    }
    // Already on the destination (ignoring w_auth): soft reload signal only.
    try {
      var next = new URL(target, window.location.origin);
      var cur = new URL(window.location.href);
      if (next.origin === cur.origin
        && next.pathname === cur.pathname
        && next.hash === cur.hash) {
        var sameQuery = next.searchParams.get('w_auth') === '1'
          || (cur.searchParams.get('w_auth') === '1' && next.searchParams.get('w_auth') == null);
        if (sameQuery || next.search === cur.search) {
          cur.searchParams.set('w_auth', '1');
          window.history.replaceState({}, '', cur.pathname + cur.search + cur.hash);
          window.location.reload();
          return;
        }
      }
    } catch (e) { /* fall through */ }
    window.location.assign(target);
  }

  function loadScript(src, id) {
    return new Promise(function (resolve, reject) {
      var existing = id ? document.getElementById(id) : null;
      if (existing) {
        // Another boot may have inserted the tag; wait for real load, do not
        // resolve early while window.google / FB is still undefined.
        if (existing.getAttribute('data-weline-script-ready') === '1') {
          resolve();
          return;
        }
        existing.addEventListener('load', function () { resolve(); });
        existing.addEventListener('error', function () {
          reject(new Error('script_load_failed'));
        });
        return;
      }
      var s = document.createElement('script');
      if (id) s.id = id;
      s.async = true;
      // Identity SDKs (GSI / FB) must not use crossOrigin=anonymous — CORS can
      // fire onload without executing, leaving window.google undefined and
      // skipping One Tap for Facebook fallback.
      s.src = src;
      s.onload = function () {
        try { s.setAttribute('data-weline-script-ready', '1'); } catch (e) { /* ignore */ }
        resolve();
      };
      s.onerror = function () { reject(new Error('script_load_failed')); };
      document.head.appendChild(s);
    });
  }

  function whenReady(check, attempts, delayMs) {
    return new Promise(function (resolve) {
      var left = attempts || 20;
      var wait = delayMs || 50;
      function tick() {
        if (check()) {
          resolve(true);
          return;
        }
        left -= 1;
        if (left <= 0) {
          resolve(false);
          return;
        }
        setTimeout(tick, wait);
      }
      tick();
    });
  }

  function ensurePromptRoot(cfg) {
    var root = document.querySelector('[data-w-component="social-login-quick-prompt"]');
    if (!root) {
      root = document.createElement('div');
      root.hidden = true;
      root.setAttribute('data-w-component', 'social-login-quick-prompt');
      root.setAttribute('data-social-quick', '1');
      root.setAttribute('data-testid', 'social-login-quick-prompt');
      (document.body || document.documentElement).appendChild(root);
    }
    if (cfg && typeof cfg === 'object') {
      var script = root.querySelector('script[type="application/json"][data-social-quick-config]');
      if (!script) {
        script = document.createElement('script');
        script.type = 'application/json';
        script.setAttribute('data-social-quick-config', '');
        root.appendChild(script);
      }
      try {
        script.textContent = JSON.stringify(cfg);
      } catch (e) { /* ignore */ }
    }
    return root;
  }

  function init(root, presetCfg, options) {
    var opts = options || {};
    var cfg = presetCfg || parseBootstrap(root);
    if (!cfg) {
      return;
    }
    // Prefer live account.current unless the caller (account JS) already gated guest.
    function startPrompt() {
      if (window.__welineSocialQuickBooted && !opts.force) {
        revealVisibleFallback();
        return;
      }
      // Force CTA: allow a second pass and re-open a dismissed chooser.
      if (opts.force) {
        try { window.__welineSocialQuickBooted = false; } catch (_rf) { /* ignore */ }
      }
      window.__welineSocialQuickBooted = true;

      var settled = false;
      var returnUrl = resolveReturnUrl(cfg);
      var googlePromptReason = '';
      hydrateAuthReturnTargets(returnUrl);
      var endpoints = cfg.endpoints || {};

      function markSettled() {
        settled = true;
        try {
          var bar = document.querySelector('[data-w-social-quick-fallback-ui]');
          if (bar) {
            bar.hidden = true;
          }
        } catch (eHide) { /* ignore */ }
      }

      function finishGoogle(credential) {
        if (settled || !credential) return;
        markSettled();
        postForm(endpoints.google, {
          credential: credential,
          return_url: returnUrl || currentStorefrontPath()
        }).then(function (data) {
          if (data && data.ok && data.redirect) {
            go(data.redirect, returnUrl || currentStorefrontPath());
            return;
          }
          settled = false;
          maybeFacebook();
        }).catch(function () {
          settled = false;
          maybeFacebook();
        });
      }

      function finishFacebook(accessToken) {
        if (settled || !accessToken) return;
        markSettled();
        root.setAttribute('data-social-quick-status', 'facebook_connected');
        postForm(endpoints.facebook, {
          access_token: accessToken,
          return_url: returnUrl || currentStorefrontPath()
        }).then(function (data) {
          if (data && data.ok && data.redirect) {
            go(data.redirect, returnUrl || currentStorefrontPath());
          }
        }).catch(function () { /* keep logo fallback */ });
      }

      function takeFacebookAuth(response) {
        var token = response
          && response.authResponse
          && response.authResponse.accessToken;
        if (token) {
          finishFacebook(token);
        }
      }

      function maybeFacebook() {
        // Legacy no-op: chooser UI only — do not open native FedCM chips.
        if (settled) return;
        showVisibleFallback(googlePromptReason || 'chooser_only');
      }

      function ensureFallbackStyles() {
        if (document.getElementById('weline-social-quick-fallback-style')) {
          return;
        }
        var style = document.createElement('style');
        style.id = 'weline-social-quick-fallback-style';
        style.textContent = [
          '.weline-social-quick-bar{position:fixed;z-index:2147483000;right:16px;bottom:16px;',
          'box-sizing:border-box;width:min(300px,calc(100vw - 32px));',
          'background:#161616;color:#fff;border-radius:14px;box-shadow:0 10px 32px rgba(0,0,0,.24);',
          'padding:12px;font:600 13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
          '.weline-social-quick-bar[hidden]{display:none!important}',
          '.weline-social-quick-bar__title{margin:0 32px 10px 2px;font-weight:600;letter-spacing:.01em}',
          '.weline-social-quick-bar__actions{display:flex;flex-direction:column;gap:8px;width:100%;align-items:stretch}',
          '.weline-social-quick-bar__btn{appearance:none;display:flex;align-items:center;justify-content:flex-start;',
          'gap:12px;width:100%;min-height:44px;box-sizing:border-box;border-radius:10px;',
          'padding:10px 14px;cursor:pointer;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;',
          'text-align:left}',
          '.weline-social-quick-bar__btn-icon{flex:0 0 20px;width:20px;height:20px;display:inline-flex;',
          'align-items:center;justify-content:center}',
          '.weline-social-quick-bar__btn-icon svg{width:20px;height:20px;display:block}',
          '.weline-social-quick-bar__btn-label{flex:1 1 auto;min-width:0}',
          '.weline-social-quick-bar__btn--google{background:#fff;color:#1f1f1f;border:1px solid #dadce0}',
          '.weline-social-quick-bar__btn--google:hover{background:#f8f9fa}',
          '.weline-social-quick-bar__btn--facebook{background:#1877f2;color:#fff;border:1px solid #1877f2}',
          '.weline-social-quick-bar__btn--facebook:hover{filter:brightness(1.06)}',
          '.weline-social-quick-bar__close{position:absolute;top:8px;right:8px;width:28px;height:28px;border:0;',
          'border-radius:6px;background:transparent;color:#bbb;cursor:pointer;font-size:18px;line-height:1}',
          '.weline-social-quick-bar__close:hover{color:#fff;background:rgba(255,255,255,.08)}',
          '@media (max-width:640px){.weline-social-quick-bar{left:12px;right:12px;bottom:12px;width:auto}}',
          '.weline-social-quick-inline{box-sizing:border-box;width:100%;',
          'background:var(--color-bg-secondary,var(--weline-theme-surface-subtle,#f7fafa));',
          'color:var(--color-text-primary,var(--weline-theme-text,#0f1111));',
          'border:1px solid var(--color-border-light,var(--weline-theme-border-color,#d5d9d9));',
          'border-radius:var(--weline-theme-radius-md,10px);padding:12px;',
          'font:600 13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
          '.weline-social-quick-inline--flush{background:transparent;border:0;border-radius:0;padding:0;',
          'box-shadow:none}',
          '.weline-social-quick-inline__title{margin:0 0 10px;font-weight:700;',
          'color:var(--color-text-primary,inherit)}',
          '.weline-social-quick-inline .weline-social-quick-bar__actions{display:flex;',
          'flex-direction:column;gap:8px;width:100%;align-items:stretch}'
        ].join('');
        document.head.appendChild(style);
      }

      function resolveOauthStart(provider) {
        var oauthUrl = '';
        try {
          oauthUrl = String((cfg.oauth && cfg.oauth[provider]) || '').trim();
        } catch (eOauth) { oauthUrl = ''; }
        if (!oauthUrl) {
          oauthUrl = '/customer/account/social-login/start?provider=' + encodeURIComponent(provider);
          var ret = returnUrl || currentStorefrontPath();
          if (ret) {
            oauthUrl += '&return_url=' + encodeURIComponent(ret);
          }
        }
        return oauthUrl;
      }

      function providerIconSvg(kind) {
        if (kind === 'google') {
          return '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true">'
            + '<path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>'
            + '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>'
            + '<path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>'
            + '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>'
            + '</svg>';
        }
        // White glyph so it stays visible on the Facebook blue fill.
        return '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true">'
          + '<path fill="#fff" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>'
          + '</svg>';
      }

      function providerButtonHtml(kind, label) {
        return '<button type="button" class="weline-social-quick-bar__btn weline-social-quick-bar__btn--'
          + kind + '" data-w-social-quick-' + (kind === 'google' ? 'google' : 'fb') + '-btn'
          + ' data-provider="' + kind + '">'
          + '<span class="weline-social-quick-bar__btn-icon">' + providerIconSvg(kind) + '</span>'
          + '<span class="weline-social-quick-bar__btn-label">' + label + '</span>'
          + '</button>';
      }

      /**
       * Explicit isomorphic chooser: matching icon+label buttons (no GSI renderButton).
       * Both providers use the same OAuth start URL as login-page Logos.
       */
      function showVisibleFallback(reason) {
        if (settled) return;
        var existingBar = document.querySelector('[data-w-social-quick-fallback-ui]');
        if (existingBar) {
          // Explicit CTA (e.g. B2B「本页登录」) must re-open a dismissed chooser.
          existingBar.hidden = false;
          return;
        }
        ensureFallbackStyles();
        var bar = document.createElement('div');
        bar.className = 'weline-social-quick-bar';
        bar.setAttribute('data-w-social-quick-fallback-ui', '1');
        bar.setAttribute('data-testid', 'social-login-quick-fallback');
        bar.setAttribute('role', 'dialog');
        bar.setAttribute('aria-label', 'Quick sign-in');
        var actions = '';
        if (cfg.google && cfg.google.client_id) {
          actions += providerButtonHtml('google', '使用 Google 登录');
        }
        if (cfg.facebook && cfg.facebook.app_id) {
          actions += providerButtonHtml('facebook', '使用 Facebook 登录');
        }
        bar.innerHTML = ''
          + '<button type="button" class="weline-social-quick-bar__close" data-w-social-quick-dismiss aria-label="Close">&times;</button>'
          + '<p class="weline-social-quick-bar__title">快捷登录</p>'
          + '<div class="weline-social-quick-bar__actions">' + actions + '</div>';
        (document.body || document.documentElement).appendChild(bar);
        root.setAttribute(
          'data-social-quick-status',
          (googlePromptReason || reason || 'fallback') + ';visible_fallback'
        );

        var closeBtn = bar.querySelector('[data-w-social-quick-dismiss]');
        if (closeBtn) {
          closeBtn.addEventListener('click', function () {
            bar.hidden = true;
            root.setAttribute('data-social-quick-status', 'fallback_dismissed');
          });
        }

        bar.querySelectorAll('[data-provider]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var provider = String(btn.getAttribute('data-provider') || '').trim();
            if (!provider) return;
            window.location.href = resolveOauthStart(provider);
          });
        });
      }

      function prepareGoogleClient(done) {
        if (!cfg.google || !cfg.google.client_id) {
          done(false);
          return;
        }
        loadScript('https://accounts.google.com/gsi/client', 'google-gsi-client').then(function () {
          return whenReady(function () {
            return !!(window.google && window.google.accounts && window.google.accounts.id);
          }, 40, 50);
        }).then(function (ready) {
          if (!ready || settled) {
            done(false);
            return;
          }
          try {
            window.google.accounts.id.initialize({
              client_id: String(cfg.google.client_id),
              callback: function (response) {
                finishGoogle(response && response.credential);
              },
              auto_select: false,
              cancel_on_tap_outside: true,
              context: 'signin',
              // Never call prompt() — that flashes a top-corner One Tap beside our bar.
              use_fedcm_for_prompt: false
            });
            try {
              if (window.google.accounts.id.cancel) {
                window.google.accounts.id.cancel();
              }
            } catch (eCancel) { /* ignore */ }
            done(true);
          } catch (eInit) {
            done(false);
          }
        }).catch(function () {
          done(false);
        });
      }

      /**
       * Single storefront chooser: bottom-right bar only.
       * Native Google One Tap / Facebook FedCM autoPrompt are disabled here —
       * they flash in the top corner and stack with the bar.
       */
      /**
       * Single storefront chooser: bottom-right bar only.
       * Native Google One Tap / Facebook FedCM autoPrompt are disabled here —
       * they flash in the top corner and stack with the bar.
       */
      function startChooserOnly() {
        if (settled) return;
        // Login/register already render Logo OAuth buttons — do not stack another chooser.
        if (document.querySelector('[data-w-component="account-social-login"]')) {
          root.setAttribute('data-social-quick-status', 'suppressed_login_widget');
          return;
        }
        // Custom isomorphic buttons — no GSI renderButton wait.
        showVisibleFallback('chooser_only');
        root.setAttribute('data-social-quick-status', 'chooser_only');
      }

      startChooserOnly();
    }

    if (opts.skipLoginCheck) {
      startPrompt();
      return;
    }
    try {
      if (window.Weline && Weline.Account && typeof Weline.Account.checkFrontendUserLogin === 'function') {
        Weline.Account.checkFrontendUserLogin().then(function (status) {
          if (status && status.isLogin) {
            window.__welineSocialQuickBooted = true;
            return;
          }
          startPrompt();
        }).catch(function () {
          startPrompt();
        });
        return;
      }
    } catch (e) { /* fall through */ }
    startPrompt();
  }

  function startFromConfig(cfg, options) {
    var opts = options || {};
    var force = !!opts.force;
    var host = opts.host || null;
    if (host) {
      return mountIntoHost(host, cfg, opts);
    }
    if (window.__welineSocialQuickBooted && !force) {
      return false;
    }
    if (force) {
      try { window.__welineSocialQuickBooted = false; } catch (_f) { /* ignore */ }
    }
    if (!cfg || typeof cfg !== 'object') {
      return false;
    }
    if (cfg.logged_in) {
      window.__welineSocialQuickBooted = true;
      return false;
    }
    // Login/register already have Logo buttons — never stack the global chooser.
    if (document.querySelector('[data-w-component="account-social-login"]')) {
      window.__welineSocialQuickBooted = true;
      return false;
    }
    if (!(cfg.google && cfg.google.client_id) && !(cfg.facebook && cfg.facebook.app_id)) {
      return false;
    }
    var root = ensurePromptRoot(cfg);
    init(root, cfg, { skipLoginCheck: true, force: force });
    return true;
  }

  function revealVisibleFallback() {
    var bar = document.querySelector('[data-w-social-quick-fallback-ui]');
    if (!bar) {
      return false;
    }
    bar.hidden = false;
    return true;
  }

  /** Customer-owned mount id — see doc/挂载面.md */
  var MOUNT_SOCIAL_QUICK = 'customer/social-quick';

  function hasProvider(cfg) {
    return !!(cfg && ((cfg.google && cfg.google.client_id) || (cfg.facebook && cfg.facebook.app_id)));
  }

  function resolveOauthUrl(cfg, provider) {
    var oauthUrl = '';
    try {
      oauthUrl = String((cfg.oauth && cfg.oauth[provider]) || '').trim();
    } catch (eOauth) { oauthUrl = ''; }
    if (!oauthUrl) {
      oauthUrl = '/customer/account/social-login/start?provider=' + encodeURIComponent(provider);
      var ret = resolveReturnUrl(cfg) || currentStorefrontPath();
      if (ret) {
        oauthUrl += '&return_url=' + encodeURIComponent(ret);
      }
    }
    return oauthUrl;
  }

  function providerIconSvgStandalone(kind) {
    if (kind === 'google') {
      return '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true">'
        + '<path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>'
        + '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>'
        + '<path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>'
        + '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>'
        + '</svg>';
    }
    return '<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true">'
      + '<path fill="#fff" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>'
      + '</svg>';
  }

  function providerButtonHtmlStandalone(kind, label) {
    return '<button type="button" class="weline-social-quick-bar__btn weline-social-quick-bar__btn--'
      + kind + '" data-w-social-quick-' + (kind === 'google' ? 'google' : 'fb') + '-btn'
      + ' data-provider="' + kind + '">'
      + '<span class="weline-social-quick-bar__btn-icon">' + providerIconSvgStandalone(kind) + '</span>'
      + '<span class="weline-social-quick-bar__btn-label">' + label + '</span>'
      + '</button>';
  }

  function ensureInlineStyles() {
    // Reuse floating stylesheet which also defines inline rules.
    var probe = document.createElement('div');
    try {
      // Trigger style injection via a no-op path: create temp floating styles by calling
      // ensureFallbackStyles through a throwaway init is heavy; inject id if missing.
      if (document.getElementById('weline-social-quick-fallback-style')) {
        return;
      }
      var style = document.createElement('style');
      style.id = 'weline-social-quick-fallback-style';
      style.textContent = [
        '.weline-social-quick-bar{position:fixed;z-index:2147483000;right:16px;bottom:16px;',
        'box-sizing:border-box;width:min(300px,calc(100vw - 32px));',
        'background:#161616;color:#fff;border-radius:14px;box-shadow:0 10px 32px rgba(0,0,0,.24);',
        'padding:12px;font:600 13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
        '.weline-social-quick-bar[hidden]{display:none!important}',
        '.weline-social-quick-bar__title{margin:0 32px 10px 2px;font-weight:600;letter-spacing:.01em}',
        '.weline-social-quick-bar__actions{display:flex;flex-direction:column;gap:8px;width:100%;align-items:stretch}',
        '.weline-social-quick-bar__btn{appearance:none;display:flex;align-items:center;justify-content:flex-start;',
        'gap:12px;width:100%;min-height:44px;box-sizing:border-box;border-radius:10px;',
        'padding:10px 14px;cursor:pointer;font:600 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;',
        'text-align:left}',
        '.weline-social-quick-bar__btn-icon{flex:0 0 20px;width:20px;height:20px;display:inline-flex;',
        'align-items:center;justify-content:center}',
        '.weline-social-quick-bar__btn-icon svg{width:20px;height:20px;display:block}',
        '.weline-social-quick-bar__btn-label{flex:1 1 auto;min-width:0}',
        '.weline-social-quick-bar__btn--google{background:#fff;color:#1f1f1f;border:1px solid #dadce0}',
        '.weline-social-quick-bar__btn--google:hover{background:#f8f9fa}',
        '.weline-social-quick-bar__btn--facebook{background:#1877f2;color:#fff;border:1px solid #1877f2}',
        '.weline-social-quick-bar__btn--facebook:hover{filter:brightness(1.06)}',
        '.weline-social-quick-inline{box-sizing:border-box;width:100%;',
        'background:var(--color-bg-secondary,var(--weline-theme-surface-subtle,#f7fafa));',
        'color:var(--color-text-primary,var(--weline-theme-text,#0f1111));',
        'border:1px solid var(--color-border-light,var(--weline-theme-border-color,#d5d9d9));',
        'border-radius:var(--weline-theme-radius-md,10px);padding:12px;',
        'font:600 13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
        '.weline-social-quick-inline--flush{background:transparent;border:0;border-radius:0;padding:0;',
        'box-shadow:none}',
        '.weline-social-quick-inline__title{margin:0 0 10px;font-weight:700}',
        '.weline-social-quick-inline .weline-social-quick-bar__actions{display:flex;flex-direction:column;gap:8px}'
      ].join('');
      document.head.appendChild(style);
    } catch (eStyle) { /* ignore */ }
    try { probe.remove(); } catch (eRem) { /* ignore */ }
  }

  function syncFloatingVisibility() {
    var suppress = false;
    document.querySelectorAll('[data-weline-mount="' + MOUNT_SOCIAL_QUICK + '"]').forEach(function (host) {
      if (host.getAttribute('data-weline-mount-suppress-floating') !== '1') {
        return;
      }
      if (host.getAttribute('data-weline-mount-state') !== 'ready') {
        return;
      }
      if (host.hidden) {
        return;
      }
      var drawer = host.closest('[data-w-component="drawer"], [data-b2b-apply-drawer]');
      if (drawer && (drawer.hidden || drawer.getAttribute('data-state') === 'closed'
        || drawer.getAttribute('aria-hidden') === 'true')) {
        return;
      }
      suppress = true;
    });
    var bar = document.querySelector('[data-w-social-quick-fallback-ui]');
    if (!bar) {
      return;
    }
    if (suppress) {
      bar.setAttribute('data-w-social-quick-suppressed-by-mount', '1');
      bar.hidden = true;
    } else if (bar.getAttribute('data-w-social-quick-suppressed-by-mount') === '1') {
      bar.removeAttribute('data-w-social-quick-suppressed-by-mount');
      // Do not auto-unhide if user dismissed; leave hidden unless status not dismissed.
      var root = document.querySelector('[data-w-component="social-login-quick-prompt"]');
      var status = root && root.getAttribute('data-social-quick-status');
      if (status !== 'fallback_dismissed') {
        bar.hidden = false;
      }
    }
  }

  function bindProviderClicks(container, cfg) {
    container.querySelectorAll('[data-provider]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var provider = String(btn.getAttribute('data-provider') || '').trim();
        if (!provider) return;
        window.location.href = resolveOauthUrl(cfg, provider);
      });
    });
  }

  /**
   * Mount customer/social-quick into a declarative host.
   * Host must declare data-weline-mount="customer/social-quick".
   */
  function mountIntoHost(host, cfg, options) {
    var opts = options || {};
    if (!host || !(host.getAttribute)) {
      return false;
    }
    if (String(host.getAttribute('data-weline-mount') || '') !== MOUNT_SOCIAL_QUICK) {
      return false;
    }
    if (document.querySelector('[data-w-component="account-social-login"]')) {
      host.setAttribute('data-weline-mount-state', 'empty');
      host.innerHTML = '';
      syncFloatingVisibility();
      return false;
    }
    if (!cfg || typeof cfg !== 'object' || cfg.logged_in) {
      host.setAttribute('data-weline-mount-state', 'empty');
      host.innerHTML = '';
      syncFloatingVisibility();
      return false;
    }
    if (!hasProvider(cfg)) {
      host.setAttribute('data-weline-mount-state', 'empty');
      host.innerHTML = '';
      syncFloatingVisibility();
      return false;
    }
    if (!opts.force
      && host.getAttribute('data-weline-mount-state') === 'ready'
      && host.querySelector('[data-provider]')) {
      syncFloatingVisibility();
      return true;
    }
    ensureInlineStyles();
    ensurePromptRoot(cfg);
    hydrateAuthReturnTargets(resolveReturnUrl(cfg));
    var actions = '';
    if (cfg.google && cfg.google.client_id) {
      actions += providerButtonHtmlStandalone('google', '使用 Google 登录');
    }
    if (cfg.facebook && cfg.facebook.app_id) {
      actions += providerButtonHtmlStandalone('facebook', '使用 Facebook 登录');
    }
    var shell = document.createElement('div');
    shell.className = 'weline-social-quick-inline';
    shell.setAttribute('data-w-social-quick-inline-ui', '1');
    shell.setAttribute('data-testid', 'customer-social-quick-inline');
    // Nested login-panel already shows「快捷登录」— skip duplicate title for inline hosts.
    var skipTitle = host.getAttribute('data-weline-mount-variant') === 'inline'
      || !!host.closest('[data-weline-login-panel]');
    if (skipTitle) {
      shell.className += ' weline-social-quick-inline--flush';
    }
    shell.innerHTML = (skipTitle ? '' : '<p class="weline-social-quick-inline__title">快捷登录</p>')
      + '<div class="weline-social-quick-bar__actions">' + actions + '</div>';
    host.innerHTML = '';
    host.appendChild(shell);
    bindProviderClicks(shell, cfg);
    host.setAttribute('data-weline-mount-state', 'ready');
    syncFloatingVisibility();
    return true;
  }

  function hostIsActivatable(host, force) {
    if (!host) {
      return false;
    }
    if (String(host.getAttribute('data-weline-mount') || '') !== MOUNT_SOCIAL_QUICK) {
      return false;
    }
    if (!force) {
      if (host.hidden) {
        return false;
      }
      var drawer = host.closest('[data-w-component="drawer"], [data-b2b-apply-drawer]');
      if (drawer && (drawer.hidden || drawer.getAttribute('data-state') === 'closed'
        || drawer.getAttribute('aria-hidden') === 'true')) {
        return false;
      }
      if (host.getAttribute('data-weline-mount-state') === 'ready'
        && host.querySelector('[data-provider]')) {
        return false;
      }
    }
    return true;
  }

  function listMountHosts(force) {
    var nodes = document.querySelectorAll('[data-weline-mount="' + MOUNT_SOCIAL_QUICK + '"]');
    var out = [];
    nodes.forEach(function (host) {
      if (hostIsActivatable(host, !!force)) {
        out.push(host);
      }
    });
    return out;
  }

  /**
   * Scan declarative hosts owned by this module (customer/social-quick only).
   * Returns host elements that need Account bootstrap + mount.
   */
  function scanMountHosts(options) {
    var opts = options || {};
    syncFloatingVisibility();
    return listMountHosts(!!opts.force);
  }

  function bootFromDom() {
    if (window.__welineSocialQuickBooted) {
      var revealed = revealVisibleFallback();
      if (revealed) {
        return;
      }
    }
    var root = document.querySelector('[data-w-component="social-login-quick-prompt"]')
      || document.querySelector('[data-w-component="account-social-login"][data-social-quick]')
      || document.querySelector('[data-social-quick]');
    if (root) {
      init(root);
    }
  }

  var api = {
    MOUNT_SOCIAL_QUICK: MOUNT_SOCIAL_QUICK,
    start: startFromConfig,
    boot: bootFromDom,
    reveal: revealVisibleFallback,
    mount: mountIntoHost,
    scanMountHosts: scanMountHosts,
    syncFloatingVisibility: syncFloatingVisibility,
    ensureRoot: ensurePromptRoot,
    __supportsVisibleFallback: true
  };
  window.WelineSocialQuick = api;

  // Login-page widget may still embed a bootstrap root; skip auto-boot when
  // account JS owns the global guest prompt (header present).
  if (!document.querySelector('[data-w-header-account="1"]')) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootFromDom);
    } else {
      bootFromDom();
    }
  }

  document.addEventListener('weline:mount:scan', function () {
    try { syncFloatingVisibility(); } catch (eScan) { /* ignore */ }
  });
  document.addEventListener('weline:mount:ready', function (event) {
    var detail = (event && event.detail) || {};
    if (String(detail.surface || '') !== MOUNT_SOCIAL_QUICK) {
      return;
    }
    try { syncFloatingVisibility(); } catch (eReady) { /* ignore */ }
  });
})(window, document);
