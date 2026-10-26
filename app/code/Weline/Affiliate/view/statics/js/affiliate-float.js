/**
 * Affiliate float widget (storefront-float-side slot) — advisory rules + member hydration.
 * - SSR renders the panel shell and the current scope's affiliate rules.
 * - Opening the panel never calls a login-required affiliate API.
 * - Guest: rules + sign-in note only; the center link is the normal /affiliate login boundary.
 * - Signed in: hydrate personal level / commission / referral link with a silent query.
 *
 * Owning module: Weline_Affiliate (content only; container/dock is Weline_Theme).
 * Marker: affiliate-float-widget-v3-rules-login-gate
 */
(function (global) {
  'use strict';

  var SEL = '[data-affiliate-float]';
  var DEFAULT_I18N = {
    memberTitle: '我的分销',
    levelLabel: '分销等级',
    rateLabel: '佣金比例',
    statusLabel: '状态',
    scopeLabel: '所属范围',
    pendingLabel: '待结算佣金',
    linkLabel: '专属推广链接',
    copy: '复制链接',
    copied: '已复制',
    guestNote: '进入分销中心需要登录。',
    centerCta: '进入分销中心',
    active: '启用中',
    disabled: '已停用',
    loadFailed: '分销数据暂时无法加载',
    apiMissing: '分销 API 未初始化',
    defaultLevel: '分销员'
  };

  function readI18n(widget) {
    var merged = Object.assign({}, DEFAULT_I18N);
    var raw = widget && widget.getAttribute('data-affiliate-i18n');
    if (!raw) {
      return merged;
    }
    try {
      var parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        Object.keys(parsed).forEach(function (key) {
          if (typeof parsed[key] === 'string' && parsed[key] !== '') {
            merged[key] = parsed[key];
          }
        });
      }
    } catch (_e) { /* SSR JSON is best-effort */ }
    return merged;
  }

  function fmtAmount(value, currency) {
    var num = Number(value || 0);
    var cur = String(currency || '').toUpperCase();
    var text = num.toFixed(2);
    return cur ? cur + ' ' + text : text;
  }

  function fmtPercent(value) {
    var num = Number(value);
    if (!isFinite(num)) {
      return '--';
    }
    var text = (num * 100).toFixed(2).replace(/\.?0+$/, '');
    return (text || '0') + '%';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function summaryOf(data) {
    return (data && data.data && typeof data.data === 'object') ? data.data : (data || {});
  }

  function statusLabel(status, i18n) {
    var key = String(status || '').toLowerCase();
    if (key === 'active') {
      return i18n.active;
    }
    if (key === 'disabled') {
      return i18n.disabled;
    }
    return key !== '' ? String(status) : '--';
  }

  function isDefaultScopeLabel(label) {
    var text = String(label || '').trim().toLowerCase();
    if (text === '') {
      return true;
    }
    return text.split('/').every(function (part) {
      var segment = part.trim();
      return segment === 'default' || segment === 'global scope' || segment === '全站通用';
    });
  }

  function setStatus(widget, message) {
    var status = widget.querySelector('[data-affiliate-float-status]');
    if (status) {
      status.textContent = message || '';
    }
  }

  function clearMember(widget) {
    var member = widget.querySelector('[data-affiliate-float-member]');
    if (member) {
      member.hidden = true;
      member.innerHTML = '';
    }
  }

  function centerUrlOf(widget) {
    return String(widget.getAttribute('data-affiliate-center-url') || '/affiliate');
  }

  function panelOf(widget) {
    return widget.querySelector('[data-affiliate-float-panel]');
  }

  /** Panel shell is SSR; keep a minimal fallback for stale cached markup. */
  function ensurePanel(widget) {
    var panel = panelOf(widget);
    if (panel) {
      return panel;
    }
    var uid = String(widget.getAttribute('data-uid') || 'float');
    panel = document.createElement('div');
    panel.className = 'affiliate-float-widget__panel w-panel';
    panel.id = 'affiliate-float-panel-' + uid;
    panel.setAttribute('data-affiliate-float-panel', '');
    panel.hidden = true;
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', '分销面板');
    panel.setAttribute('data-testid', 'affiliate-float-panel');
    panel.innerHTML =
      '<div class="affiliate-float-widget__panel-body" data-affiliate-float-body>' +
      '<p class="affiliate-float-widget__status" data-affiliate-float-status></p>' +
      '<div class="affiliate-float-widget__member" data-affiliate-float-member hidden></div>' +
      '<p class="affiliate-float-widget__note" data-affiliate-float-note></p>' +
      '<a class="affiliate-float-widget__cta" data-affiliate-float-cta></a>' +
      '</div>';
    widget.appendChild(panel);

    return panel;
  }

  function ensureAccountModule() {
    if (global.WelineAccountModule) {
      return Promise.resolve(global.WelineAccountModule);
    }
    if (global.Weline && typeof global.Weline.load === 'function') {
      return global.Weline.load('account').then(function () {
        return global.WelineAccountModule || null;
      }).catch(function () {
        return null;
      });
    }
    return Promise.resolve(null);
  }

  function getAffiliateApi() {
    if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
      return Promise.resolve(global.Weline.Api.resource('affiliate'));
    }
    if (global.Weline && typeof global.Weline.load === 'function') {
      return global.Weline.load('api').then(function () {
        if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
          throw new Error('api-missing');
        }
        return global.Weline.Api.resource('affiliate');
      });
    }
    return Promise.reject(new Error('api-missing'));
  }

  function renderGuest(widget, i18n) {
    var note = widget.querySelector('[data-affiliate-float-note]');
    var cta = widget.querySelector('[data-affiliate-float-cta]');
    clearMember(widget);
    if (note) {
      note.hidden = false;
      note.textContent = i18n.guestNote;
    }
    if (cta) {
      cta.href = centerUrlOf(widget);
      cta.textContent = i18n.centerCta;
    }
    setStatus(widget, '');
  }

  function memberRow(label, value) {
    return '<div class="affiliate-float-widget__member-row">' +
      '<dt class="affiliate-float-widget__member-label">' + escapeHtml(label) + '</dt>' +
      '<dd class="affiliate-float-widget__member-value">' + escapeHtml(value) + '</dd>' +
      '</div>';
  }

  function bindCopy(widget, i18n) {
    var copyBtn = widget.querySelector('[data-affiliate-float-copy]');
    if (!copyBtn) {
      return;
    }
    copyBtn.addEventListener('click', function () {
      var input = widget.querySelector('.affiliate-float-widget__link');
      if (!input) {
        return;
      }
      input.select();
      var done = function () {
        if (copyBtn.textContent !== i18n.copied) {
          copyBtn.textContent = i18n.copied;
          global.setTimeout(function () {
            copyBtn.textContent = i18n.copy;
          }, 1600);
        }
      };
      try {
        if (global.navigator && global.navigator.clipboard) {
          global.navigator.clipboard.writeText(input.value).then(done, done);
        } else {
          document.execCommand('copy');
          done();
        }
      } catch (_e) {
        done();
      }
    });
  }

  function renderMember(widget, data, i18n) {
    var member = summaryOf(data);
    var memberBox = widget.querySelector('[data-affiliate-float-member]');
    var note = widget.querySelector('[data-affiliate-float-note]');
    var cta = widget.querySelector('[data-affiliate-float-cta]');
    if (!memberBox) {
      return;
    }

    var levelName = String(widget.getAttribute('data-affiliate-level-name') || i18n.defaultLevel || '');
    var rows = [
      memberRow(i18n.levelLabel, levelName),
      memberRow(i18n.rateLabel, fmtPercent(member.commission_rate)),
      memberRow(i18n.statusLabel, statusLabel(member.status, i18n))
    ];
    var scopeLabel = String(member.scope_label || '').trim();
    if (scopeLabel !== '' && !isDefaultScopeLabel(scopeLabel)) {
      rows.push(memberRow(i18n.scopeLabel, scopeLabel));
    }
    if (typeof member.pending_commission !== 'undefined' && member.pending_commission !== null) {
      rows.push(memberRow(i18n.pendingLabel, fmtAmount(member.pending_commission, member.currency_code)));
    }

    var html = '<h4 class="affiliate-float-widget__member-title">' + escapeHtml(i18n.memberTitle) + '</h4>' +
      '<dl class="affiliate-float-widget__member-list">' + rows.join('') + '</dl>';
    var referralLink = String(member.referral_link || '');
    if (referralLink !== '') {
      html += '<div class="affiliate-float-widget__link-row">' +
        '<input class="affiliate-float-widget__link" type="text" readonly value="' +
        escapeHtml(referralLink) + '" aria-label="' + escapeHtml(i18n.linkLabel) + '">' +
        '<button type="button" class="affiliate-float-widget__copy" data-affiliate-float-copy>' +
        escapeHtml(i18n.copy) + '</button></div>';
    }

    memberBox.innerHTML = html;
    memberBox.hidden = false;
    if (note) {
      note.hidden = true;
    }
    if (cta) {
      cta.href = centerUrlOf(widget);
      cta.textContent = i18n.centerCta;
    }
    setStatus(widget, '');
    bindCopy(widget, i18n);
  }

  function renderError(widget, message, i18n) {
    var memberBox = widget.querySelector('[data-affiliate-float-member]');
    var note = widget.querySelector('[data-affiliate-float-note]');
    var cta = widget.querySelector('[data-affiliate-float-cta]');
    if (memberBox) {
      memberBox.hidden = true;
      memberBox.innerHTML = '';
    }
    if (note) {
      note.hidden = false;
      note.textContent = message || i18n.loadFailed;
    }
    if (cta) {
      cta.href = centerUrlOf(widget);
      cta.textContent = i18n.centerCta;
    }
    setStatus(widget, '');
  }

  function loadMember(widget, i18n) {
    return getAffiliateApi().then(function (api) {
      if (!api || typeof api.getMySummary !== 'function') {
        throw new Error(i18n.apiMissing);
      }
      return Promise.resolve(api.getMySummary({}, { silent: true }));
    }).then(function (data) {
      var summary = summaryOf(data);
      if ((data && (data.login_required || data.success === false))
        || (summary && (summary.login_required || summary.success === false))) {
        renderGuest(widget, i18n);
        return;
      }
      renderMember(widget, data, i18n);
    }).catch(function (error) {
      var message = (error && error.message) || i18n.loadFailed;
      if (/请先登录|sign in|log ?in|未登录/i.test(message)) {
        renderGuest(widget, i18n);
        return;
      }
      renderError(widget, message, i18n);
    });
  }

  function loadPanel(widget, state) {
    if (state.loading || state.loaded) {
      return;
    }
    state.loading = true;
    var i18n = readI18n(widget);
    ensureAccountModule().then(function (account) {
      var statusPromise = account && typeof account.checkFrontendUserLogin === 'function'
        ? account.checkFrontendUserLogin({ force: false })
        : Promise.resolve({ isLogin: false, user: null });

      return Promise.resolve(statusPromise).then(function (status) {
        state.loading = false;
        state.loaded = true;
        if (!status || !status.isLogin) {
          state.isLogin = false;
          renderGuest(widget, i18n);
          return;
        }
        state.isLogin = true;
        return loadMember(widget, i18n);
      });
    }).catch(function () {
      // Session probe failure is treated as guest: never trigger a login-required affiliate API.
      state.loading = false;
      state.loaded = true;
      state.isLogin = false;
      renderGuest(widget, i18n);
    });
  }

  function bindWidget(widget) {
    if (widget.__affiliateFloatBound) {
      return;
    }
    widget.__affiliateFloatBound = true;
    var state = { loading: false, loaded: false, isLogin: false };
    widget.__affiliateFloatState = state;
    var entry = widget.querySelector('[data-affiliate-float-toggle]');
    if (!entry) {
      return;
    }
    var panel = ensurePanel(widget);
    if (!panel) {
      return;
    }
    entry.addEventListener('click', function (event) {
      event.preventDefault();
      var open = panel.hidden;
      panel.hidden = !open;
      entry.setAttribute('aria-expanded', open ? 'true' : 'false');
      widget.classList.toggle('is-panel-open', open);
      if (open) {
        loadPanel(widget, state);
      }
    });
  }

  function bindAll() {
    var nodes = document.querySelectorAll(SEL);
    for (var i = 0; i < nodes.length; i++) {
      bindWidget(nodes[i]);
    }
  }

  function resetWidgets(reloadOpenPanels) {
    var nodes = document.querySelectorAll(SEL);
    for (var i = 0; i < nodes.length; i++) {
      var widget = nodes[i];
      var state = widget.__affiliateFloatState;
      var i18n = readI18n(widget);
      if (state) {
        state.loading = false;
        state.loaded = false;
        state.isLogin = false;
      }
      clearMember(widget);
      if (!reloadOpenPanels) {
        renderGuest(widget, i18n);
        continue;
      }
      var panel = panelOf(widget);
      if (panel && !panel.hidden && state) {
        loadPanel(widget, state);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAll);
  } else {
    bindAll();
  }

  global.addEventListener('weline:account:frontend:login', function () {
    resetWidgets(true);
  });
  global.addEventListener('weline:account:frontend:logout', function () {
    resetWidgets(false);
  });

  global.WelineAffiliateFloat = { bindAll: bindAll, reset: resetWidgets };
})(typeof window !== 'undefined' ? window : this);
