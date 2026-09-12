/**
 * 已刊列表：点击行先展开手风琴，再异步加载本地产品详情/规格。
 * Loaded via data-weline-load="dropshipListedAccordion".
 */
(function (global) {
  'use strict';

  function money(minor, currency) {
    var n = Number(minor || 0) / 100;
    var cur = String(currency || 'CNY').toUpperCase();
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).format(n);
    } catch (e) {
      return cur + ' ' + n.toFixed(2);
    }
  }

  /** Primary amount + optional muted ≈ compare currency (e.g. ¥21.94 / ≈ US$2.74). */
  function moneyWithCompare(amountMinor, currency, compareMinor, compareCurrency) {
    var primary = money(amountMinor, currency);
    var html = '<span data-testid="dropship-listed-money-primary">' + escapeHtml(primary) + '</span>';
    if (compareMinor == null || compareMinor === '' || !compareCurrency) {
      return html;
    }
    var pCur = String(currency || '').toUpperCase();
    var cCur = String(compareCurrency || '').toUpperCase();
    if (cCur === '' || cCur === pCur) {
      return html;
    }
    return html +
      '<div class="w-text" data-tone="muted" data-size="sm" data-testid="dropship-listed-sale-compare">' +
      '≈ ' + escapeHtml(money(compareMinor, compareCurrency)) +
      '</div>';
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function upliftLabel(percent) {
    var n = Number(percent);
    if (!isFinite(n) || n === 0) {
      return '—';
    }
    return (n > 0 ? '+' : '') + String(n) + '%';
  }

  function statusPresentation(raw, i18n, explicitLabel, explicitTone) {
    var label = trimStr(explicitLabel);
    var tone = trimStr(explicitTone);
    var status = trimStr(raw).toLowerCase();
    if (!label) {
      if (status === 'draft') {
        label = i18n.statusDraft || '草稿';
      } else if (status === 'published') {
        label = i18n.statusPublished || '已发布';
      } else if (status === 'disabled') {
        label = i18n.statusDisabled || '已下架';
      } else if (status === 'archived') {
        label = i18n.statusArchived || '已归档';
      } else {
        label = status;
      }
    }
    if (!tone) {
      if (status === 'published') {
        tone = 'success';
      } else if (status === 'draft') {
        tone = 'warning';
      } else if (status === 'archived') {
        tone = 'danger';
      } else {
        tone = 'muted';
      }
    }
    return { label: label, tone: tone || 'muted', status: status };
  }

  function renderDetail(payload, i18n) {
    if (!payload || !payload.ok) {
      return '<div class="w-text" data-tone="danger" data-testid="dropship-listed-detail-error">' +
        escapeHtml((payload && payload.message) || i18n.loadFailed) + '</div>';
    }
    if (!payload.has_local) {
      return '<div class="w-text" data-tone="muted" data-testid="dropship-listed-detail-empty">' +
        escapeHtml(i18n.noLocal) + '</div>';
    }

    var sale = payload.sale || {};
    var origin = payload.origin || {};
    var product = payload.product || {};
    var eco = payload.economics || {};
    var ops = payload.ops || {};
    var uplift = sale.uplift_percent;
    var productSku = trimStr(product.sku);
    var productStatusInfo = statusPresentation(
      product.status,
      i18n,
      product.status_label,
      product.status_tone
    );
    var typeLabel = payload.is_configurable ? i18n.configurable : i18n.simple;

    var productBlock =
      '<div class="ds-listed-detail__product" data-testid="dropship-listed-detail-product">' +
      '<div class="w-cluster" data-wrap="true" style="--w-gap:var(--weline-space-2);">' +
      '<span class="w-text" data-weight="strong">' + escapeHtml(i18n.product) + '</span>' +
      '<span class="w-badge" data-w-background="muted" data-testid="dropship-listed-detail-type">' +
      escapeHtml(typeLabel) +
      '</span>' +
      (productStatusInfo.label
        ? '<span class="w-badge" data-w-background="' + escapeHtml(productStatusInfo.tone) +
          '" data-status="' + escapeHtml(productStatusInfo.status) +
          '" data-testid="dropship-listed-detail-product-status">' +
          escapeHtml(productStatusInfo.label) + '</span>'
        : '') +
      '</div>' +
      (productSku
        ? '<div class="w-text" data-size="sm" style="margin-top:var(--weline-space-1);">' +
          'SKU：<code data-testid="dropship-listed-detail-product-sku">' + escapeHtml(productSku) + '</code></div>'
        : '') +
      '</div>';

    var pricingBlock =
      '<div class="ds-listed-detail__pricing" data-testid="dropship-listed-detail-pricing">' +
      '<div class="ds-listed-detail__metric" data-testid="dropship-listed-detail-origin">' +
      '<div class="w-text" data-tone="muted" data-size="sm">' + escapeHtml(i18n.origin) + '</div>' +
      '<div class="w-text" data-weight="strong" data-size="lg">' +
      escapeHtml(money(origin.amount_minor, origin.currency || 'USD')) +
      '</div>' +
      originChangeHtml(eco, i18n) +
      '</div>' +
      '<div class="ds-listed-detail__metric" data-testid="dropship-listed-detail-uplift">' +
      '<div class="w-text" data-tone="muted" data-size="sm">' + escapeHtml(i18n.uplift) + '</div>' +
      '<div class="w-text" data-weight="strong" data-size="lg">' +
      escapeHtml(upliftLabel(uplift)) +
      '</div></div>' +
      '<div class="ds-listed-detail__metric" data-testid="dropship-listed-detail-sale">' +
      '<div class="w-text" data-tone="muted" data-size="sm">' + escapeHtml(i18n.localSale) + '</div>' +
      '<div class="w-text" data-weight="strong" data-size="lg">' +
      moneyWithCompare(
        sale.amount_minor,
        sale.currency || 'CNY',
        sale.compare_amount_minor,
        sale.compare_currency || origin.currency
      ) +
      '</div></div>' +
      '</div>';

    var economicsBlock = renderEconomics(eco, i18n);
    var opsBlock = renderOps(ops, i18n);

    var variants = Array.isArray(payload.variants) ? payload.variants : [];
    if (variants.length === 0) {
      return productBlock + pricingBlock + economicsBlock + opsBlock +
        '<div class="w-text" data-tone="muted" data-size="sm">' + escapeHtml(i18n.noVariants) + '</div>';
    }

    var rows = variants.map(function (v) {
      var thumb = v.image_url
        ? '<img class="ds-listed-detail__thumb" src="' + escapeHtml(v.image_url) + '" alt="" width="40" height="40" loading="lazy">'
        : '<span class="ds-listed-detail__thumb-ph" aria-hidden="true"></span>';
      var label = trimStr(v.label) || i18n.defaultVariant || '—';
      var sku = trimStr(v.sku);
      var rowOrigin = (v.origin_amount_minor != null)
        ? { amount_minor: v.origin_amount_minor, currency: v.origin_currency || origin.currency }
        : origin;
      var rowUplift = (v.uplift_percent != null) ? v.uplift_percent : uplift;
      var variantStatus = statusPresentation(v.status, i18n, v.status_label, v.status_tone);

      return '<tr data-testid="dropship-listed-variant-row">' +
        '<td>' + thumb + '</td>' +
        '<td data-testid="dropship-listed-variant-label">' +
        '<div class="w-text" data-weight="strong">' + escapeHtml(label) + '</div>' +
        '</td>' +
        '<td data-testid="dropship-listed-variant-sku">' +
        (sku ? '<code>' + escapeHtml(sku) + '</code>' : '—') +
        '</td>' +
        '<td data-testid="dropship-listed-variant-offer">' +
        escapeHtml(money(rowOrigin.amount_minor, rowOrigin.currency || 'USD')) +
        '</td>' +
        '<td data-testid="dropship-listed-variant-price">' +
        moneyWithCompare(
          v.amount_minor,
          v.currency || sale.currency,
          v.compare_amount_minor != null ? v.compare_amount_minor : sale.compare_amount_minor,
          v.compare_currency || sale.compare_currency || origin.currency
        ) +
        '</td>' +
        '<td data-testid="dropship-listed-variant-uplift">' +
        escapeHtml(upliftLabel(rowUplift)) +
        '</td>' +
        '<td data-testid="dropship-listed-variant-status">' +
        (variantStatus.label
          ? '<span class="w-badge" data-w-background="' + escapeHtml(variantStatus.tone) +
            '" data-status="' + escapeHtml(variantStatus.status) + '">' +
            escapeHtml(variantStatus.label) + '</span>'
          : '—') +
        '</td>' +
        '</tr>';
    }).join('');

    var table =
      '<div class="ds-listed-detail__variants">' +
      '<div class="w-text" data-weight="strong" style="margin-bottom:var(--weline-space-2);">' +
      escapeHtml(i18n.variantsTitle) +
      ' <span class="w-text" data-tone="muted" data-size="sm">(' + variants.length + ')</span>' +
      '</div>' +
      '<div class="w-table-wrap ds-listed-detail__table">' +
      '<table class="w-table" data-w-vertical="middle" data-testid="dropship-listed-variants">' +
      '<thead><tr>' +
      '<th>' + escapeHtml(i18n.colImage) + '</th>' +
      '<th>' + escapeHtml(i18n.colVariant) + '</th>' +
      '<th>' + escapeHtml(i18n.colSku) + '</th>' +
      '<th>' + escapeHtml(i18n.colOffer) + '</th>' +
      '<th>' + escapeHtml(i18n.colPrice) + '</th>' +
      '<th>' + escapeHtml(i18n.colUplift) + '</th>' +
      '<th>' + escapeHtml(i18n.colStatus) + '</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';

    return productBlock + pricingBlock + economicsBlock + opsBlock + table;
  }

  function originChangeHtml(eco, i18n) {
    var dir = trimStr(eco.origin_direction) || 'same';
    var pct = eco.origin_delta_percent;
    var label = '—';
    if (pct != null && isFinite(Number(pct))) {
      label = (Number(pct) > 0 ? '+' : '') + String(pct) + '%';
    } else if (dir === 'same') {
      label = '0%';
    }
    return '<div class="w-text" data-tone="muted" data-size="sm" data-testid="dropship-listed-detail-origin-change" data-dir="' +
      escapeHtml(dir) + '">' + escapeHtml(i18n.originChange) + ' ' + escapeHtml(label) + '</div>';
  }

  function renderEconomics(eco, i18n) {
    if (!eco || (eco.cost_minor == null && eco.margin_minor == null)) {
      return '';
    }
    var costCur = eco.cost_currency || 'CNY';
    var marginPct = eco.margin_percent;
    return '<div class="ds-listed-detail__secondary" data-testid="dropship-listed-detail-economics">' +
      '<span class="w-text" data-size="sm" data-testid="dropship-listed-detail-cost">' +
      escapeHtml(i18n.cost) + '：' + escapeHtml(money(eco.cost_minor, costCur)) +
      '</span>' +
      '<span class="w-text" data-size="sm" data-testid="dropship-listed-detail-margin">' +
      escapeHtml(i18n.margin) + '：' + escapeHtml(money(eco.margin_minor, costCur)) +
      (marginPct != null && isFinite(Number(marginPct))
        ? ' <span class="w-text" data-tone="muted">(' + escapeHtml(String(marginPct)) + '%)</span>'
        : '') +
      '</span></div>';
  }

  function renderOps(ops, i18n) {
    if (!ops) {
      return '';
    }
    var parts = [];
    if (ops.last_synced_at) {
      parts.push('<span class="w-text" data-size="sm" data-testid="dropship-listed-detail-synced">' +
        escapeHtml(i18n.synced) + '：' + escapeHtml(String(ops.last_synced_at)) + '</span>');
    }
    if (ops.price_lock) {
      parts.push('<span class="w-badge" data-w-background="warning" data-testid="dropship-listed-detail-price-lock">' +
        escapeHtml(i18n.priceLock) + '</span>');
    }
    var tip = trimStr(ops.price_drop_tip);
    var tipHtml = tip
      ? '<div class="ds-listed-detail__tip w-text" data-size="sm" data-testid="dropship-listed-detail-tip">' +
        escapeHtml(tip) + '</div>'
      : '';
    if (parts.length === 0 && tipHtml === '') {
      return '';
    }
    return (parts.length
      ? '<div class="ds-listed-detail__secondary" data-testid="dropship-listed-detail-ops">' + parts.join('') + '</div>'
      : '') + tipHtml;
  }

  function trimStr(v) {
    return String(v == null ? '' : v).trim();
  }

  function init(root) {
    if (!root || root.getAttribute('data-listed-accordion-ready') === '1') {
      return;
    }
    root.setAttribute('data-listed-accordion-ready', '1');
    var detailUrl = root.getAttribute('data-local-detail-url') || '';
    var i18n = {
      loading: root.getAttribute('data-i18n-loading') || 'Loading…',
      loadFailed: root.getAttribute('data-i18n-failed') || 'Load failed',
      noLocal: root.getAttribute('data-i18n-no-local') || 'No local product yet',
      noVariants: root.getAttribute('data-i18n-no-variants') || 'No offers',
      localSale: root.getAttribute('data-i18n-local-sale') || 'Local sale',
      origin: root.getAttribute('data-i18n-origin') || 'Origin',
      uplift: root.getAttribute('data-i18n-uplift') || 'Uplift',
      product: root.getAttribute('data-i18n-product') || 'Product',
      variantsTitle: root.getAttribute('data-i18n-variants-title') || 'Variants',
      configurable: root.getAttribute('data-i18n-configurable') || 'Configurable',
      simple: root.getAttribute('data-i18n-simple') || 'Simple',
      colImage: root.getAttribute('data-i18n-col-image') || 'Image',
      colVariant: root.getAttribute('data-i18n-col-variant') || 'Variant',
      colSku: root.getAttribute('data-i18n-col-sku') || 'SKU',
      colOffer: root.getAttribute('data-i18n-col-offer') || 'Origin',
      colPrice: root.getAttribute('data-i18n-col-price') || 'Sale',
      colUplift: root.getAttribute('data-i18n-col-uplift') || 'Uplift',
      colStatus: root.getAttribute('data-i18n-col-status') || 'Status',
      defaultVariant: root.getAttribute('data-i18n-default-variant') || 'Default',
      cost: root.getAttribute('data-i18n-cost') || 'Cost',
      margin: root.getAttribute('data-i18n-margin') || 'Margin',
      originChange: root.getAttribute('data-i18n-origin-change') || 'Origin change',
      synced: root.getAttribute('data-i18n-synced') || 'Last sync',
      priceLock: root.getAttribute('data-i18n-price-lock') || 'Price locked',
      statusDraft: root.getAttribute('data-i18n-status-draft') || '草稿',
      statusPublished: root.getAttribute('data-i18n-status-published') || '已发布',
      statusDisabled: root.getAttribute('data-i18n-status-disabled') || '已下架',
      statusArchived: root.getAttribute('data-i18n-status-archived') || '已归档',
    };
    var cache = Object.create(null);

    function syncHint(row, open) {
      var hint = row.querySelector('[data-testid="dropship-listed-expand-hint"]');
      if (!hint) {
        return;
      }
      hint.setAttribute('aria-expanded', open ? 'true' : 'false');
      hint.classList.toggle('is-expanded', open);
      var label = hint.querySelector('[data-listed-expand-label]');
      if (label) {
        label.textContent = open
          ? (root.getAttribute('data-i18n-collapse') || '点击收起')
          : (root.getAttribute('data-i18n-expand') || '点击展开本地规格与售价');
      }
      var icon = hint.querySelector('.w-icon');
      if (icon) {
        icon.style.transform = open ? 'rotate(180deg)' : '';
      }
    }

    function setExpanded(row, detail, open) {
      row.setAttribute('aria-expanded', open ? 'true' : 'false');
      row.classList.toggle('is-expanded', open);
      syncHint(row, open);
      if (detail) {
        detail.hidden = !open;
        detail.classList.toggle('is-open', open);
      }
    }

    function loadDetail(row, detail) {
      var lid = row.getAttribute('data-listing-id') || '';
      var body = detail.querySelector('[data-listed-detail-body]');
      if (!body) {
        return;
      }
      if (cache[lid]) {
        body.innerHTML = renderDetail(cache[lid], i18n);
        return;
      }
      body.innerHTML = '<div class="w-text" data-tone="muted" data-testid="dropship-listed-detail-loading">' +
        escapeHtml(i18n.loading) + '</div>';
      if (!detailUrl || !lid) {
        body.innerHTML = renderDetail({ ok: false, message: 'detail_url_missing' }, i18n);
        return;
      }
      var url = detailUrl + (detailUrl.indexOf('?') >= 0 ? '&' : '?') + 'listing_id=' + encodeURIComponent(lid);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
          cache[lid] = payload;
          body.innerHTML = renderDetail(payload, i18n);
        })
        .catch(function () {
          body.innerHTML = renderDetail({ ok: false, message: i18n.loadFailed }, i18n);
        });
    }

    root.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || !t.closest) {
        return;
      }
      var toggle = t.closest('[data-listed-expand-toggle]');
      if (toggle) {
        var toggleRowEl = toggle.closest('[data-testid="dropship-listed-row"][data-listed-expandable="1"]');
        if (toggleRowEl && root.contains(toggleRowEl)) {
          ev.preventDefault();
          toggleRow(toggleRowEl);
        }
        return;
      }
      if (t.closest('a, button, input, label, select, textarea, .w-button')) {
        return;
      }
      var row = t.closest('[data-testid="dropship-listed-row"][data-listed-expandable="1"]');
      if (!row || !root.contains(row)) {
        return;
      }
      toggleRow(row);
    });

    root.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' && ev.key !== ' ') {
        return;
      }
      var t = ev.target;
      if (!t || !t.closest) {
        return;
      }
      var toggle = t.closest('[data-listed-expand-toggle]');
      if (toggle) {
        var toggleRowEl = toggle.closest('[data-testid="dropship-listed-row"][data-listed-expandable="1"]');
        if (toggleRowEl && root.contains(toggleRowEl)) {
          ev.preventDefault();
          toggleRow(toggleRowEl);
        }
        return;
      }
      if (t.closest('a, button, input, label, select, textarea')) {
        return;
      }
      var row = t.closest('[data-testid="dropship-listed-row"][data-listed-expandable="1"]');
      if (!row || !root.contains(row)) {
        return;
      }
      ev.preventDefault();
      toggleRow(row);
    });

    function toggleRow(row) {
      var lid = row.getAttribute('data-listing-id');
      var detail = root.querySelector('[data-testid="dropship-listed-detail"][data-listing-id="' + lid + '"]');
      if (!detail) {
        return;
      }
      var open = row.getAttribute('aria-expanded') !== 'true';
      root.querySelectorAll('[data-testid="dropship-listed-row"][aria-expanded="true"]').forEach(function (other) {
        if (other === row) {
          return;
        }
        var oid = other.getAttribute('data-listing-id');
        var od = root.querySelector('[data-testid="dropship-listed-detail"][data-listing-id="' + oid + '"]');
        setExpanded(other, od, false);
      });
      setExpanded(row, detail, open);
      if (open) {
        loadDetail(row, detail);
        try {
          detail.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
        } catch (e) {
          try { detail.scrollIntoView(false); } catch (e2) {}
        }
      }
    }
  }

  function boot() {
    document.querySelectorAll('[data-dropship-listed-accordion="1"]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.WelineDropshipListedAccordion = { init: init, boot: boot, renderDetail: renderDetail };
})(typeof window !== 'undefined' ? window : this);
