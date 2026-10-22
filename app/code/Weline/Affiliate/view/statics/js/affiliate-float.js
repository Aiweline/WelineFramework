/**
 * Affiliate float widget (storefront-float-side slot):
 * - entry pill toggles the panel (widget-level collapse, mirrors customer-service)
 * - panel body hydrates commission summary via Weline.Api BinQuery on first expand
 * - guest: neutral promo entry only (no data fetch required to be useful)
 *
 * Owning module: Weline_Affiliate (content only; container/dock is Weline_Theme).
 * Marker: affiliate-float-widget-v1
 */
(function (global) {
  'use strict';

  var SEL = '[data-affiliate-float]';

  function fmtAmount(value, currency) {
    var num = Number(value || 0);
    var cur = String(currency || '').toUpperCase();
    var text = num.toFixed(2);
    return cur ? cur + ' ' + text : text;
  }

  function text(el, value) {
    if (el) {
      el.textContent = value;
    }
  }

  function summaryOf(data) {
    return (data && data.data && typeof data.data === 'object') ? data.data : (data || {});
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderSummary(widget, data) {
    var body = widget.querySelector('[data-affiliate-float-body]');
    if (!body) {
      return;
    }
    var summary = summaryOf(data);
    var pending = summary.pending_commission;
    var referralLink = String(summary.referral_link || '');

    var html = '';
    if (typeof pending !== 'undefined' && pending !== null) {
      html += '<div class="affiliate-float-widget__metric">' +
        '<span class="affiliate-float-widget__metric-label">待结算佣金</span>' +
        '<strong class="affiliate-float-widget__metric-value">' +
        escapeHtml(fmtAmount(pending, summary.currency_code)) +
        '</strong></div>';
    }
    if (referralLink) {
      html += '<div class="affiliate-float-widget__link-row">' +
        '<input class="affiliate-float-widget__link" type="text" readonly value="' +
        escapeHtml(referralLink) +
        '" aria-label="邀请链接">' +
        '<button type="button" class="affiliate-float-widget__copy" data-affiliate-float-copy>' +
        '复制链接</button></div>';
    }
    html += '<a class="affiliate-float-widget__cta" href="' + widget.getAttribute('data-affiliate-center-url') + '">进入分销中心</a>';
    body.innerHTML = html;

    var copyBtn = body.querySelector('[data-affiliate-float-copy]');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var input = body.querySelector('.affiliate-float-widget__link');
        if (!input) {
          return;
        }
        input.select();
        try {
          if (global.navigator && global.navigator.clipboard) {
            global.navigator.clipboard.writeText(input.value);
          } else {
            document.execCommand('copy');
          }
        } catch (e) { /* selection fallback already covers legacy */ }
      });
    }
  }

  function renderGuest(widget) {
    var body = widget.querySelector('[data-affiliate-float-body]');
    if (!body) {
      return;
    }
    body.innerHTML =
      '<a class="affiliate-float-widget__cta" href="' +
      widget.getAttribute('data-affiliate-center-url') +
      '">进入分销中心</a>';
  }

  function renderError(widget, message) {
    var status = widget.querySelector('[data-affiliate-float-status]');
    if (status && message) {
      status.textContent = message;
    }
  }

  function loadSummary(widget, state) {
    if (state.loaded || state.loading) {
      return;
    }
    state.loading = true;
    try {
      var api = global.Weline && global.Weline.Api;
      if (!api || typeof api.resource !== 'function') {
        state.loading = false;
        renderGuest(widget);
        return;
      }
      Promise.resolve(api.resource('affiliate').getMySummary({}))
        .then(function (data) {
          state.loaded = true;
          state.loading = false;
          var summary = summaryOf(data);
          if (summary && (summary.login_required || summary.success === false)) {
            renderGuest(widget);
            return;
          }
          renderSummary(widget, data);
        })
        .catch(function (error) {
          state.loading = false;
          renderGuest(widget);
          renderError(widget, (error && error.message) || '');
        });
    } catch (e) {
      state.loading = false;
      renderGuest(widget);
    }
  }

  function bindWidget(widget) {
    if (widget.__affiliateFloatBound) {
      return;
    }
    widget.__affiliateFloatBound = true;
    var state = { loading: false, loaded: false };
    var entry = widget.querySelector('[data-affiliate-float-toggle]');
    var panel = widget.querySelector('[data-affiliate-float-panel]');
    if (!entry || !panel) {
      return;
    }
    entry.addEventListener('click', function (event) {
      event.preventDefault();
      var open = panel.hidden;
      panel.hidden = !open;
      entry.setAttribute('aria-expanded', open ? 'true' : 'false');
      widget.classList.toggle('is-panel-open', open);
      if (open) {
        loadSummary(widget, state);
      }
    });
  }

  function bindAll() {
    var nodes = document.querySelectorAll(SEL);
    for (var i = 0; i < nodes.length; i++) {
      bindWidget(nodes[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAll);
  } else {
    bindAll();
  }

  global.WelineAffiliateFloat = { bindAll: bindAll };
})(typeof window !== 'undefined' ? window : this);
