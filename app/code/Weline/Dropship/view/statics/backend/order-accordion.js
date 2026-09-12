/**
 * 履约订单手风琴：展开后异步加载订单商品行。
 */
(function (global) {
  'use strict';

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderLines(payload, i18n) {
    if (!payload || !payload.ok) {
      var msg = (payload && payload.message) ? payload.message : i18n.loadFailed;
      if (msg === 'no_lines') {
        msg = i18n.noLines;
      }
      return '<div class="w-text" data-tone="muted" data-size="sm" data-testid="dropship-order-lines-empty">' +
        escapeHtml(msg) + '</div>';
    }
    var lines = Array.isArray(payload.lines) ? payload.lines : [];
    if (!lines.length) {
      return '<div class="w-text" data-tone="muted" data-size="sm" data-testid="dropship-order-lines-empty">' +
        escapeHtml(i18n.noLines) + '</div>';
    }
    var rows = lines.map(function (line) {
      var img = String((line && (line.image_url || line.image)) || '').trim();
      var thumb = img
        ? '<img class="ds-order-line__thumb" src="' + escapeHtml(img) + '" alt="" width="40" height="40" loading="lazy" data-testid="dropship-order-line-thumb">'
        : '<span class="ds-order-line__thumb-ph" aria-hidden="true" data-testid="dropship-order-line-thumb-ph"></span>';
      return '<tr data-testid="dropship-order-line-row">' +
        '<td><div class="ds-order-line__product">' + thumb +
        '<span class="w-text" data-weight="strong">' + escapeHtml(line.name || '—') + '</span></div></td>' +
        '<td><code>' + escapeHtml(line.sku || '—') + '</code></td>' +
        '<td>' + escapeHtml(String(line.qty != null ? line.qty : '—')) + '</td>' +
        '<td>' + escapeHtml(line.unit_price_display || '—') + '</td>' +
        '<td>' + escapeHtml(line.row_total_display || '—') + '</td>' +
        '</tr>';
    }).join('');
    return '<div class="ds-order-lines" data-testid="dropship-order-lines">' +
      '<div class="w-text" data-weight="strong" data-size="sm" style="margin-bottom:var(--weline-space-2);">' +
      escapeHtml(i18n.productsTitle) + '</div>' +
      '<div class="w-table-wrap"><table class="w-table" data-w-vertical="middle" data-size="sm">' +
      '<thead><tr>' +
      '<th>' + escapeHtml(i18n.colName) + '</th>' +
      '<th>' + escapeHtml(i18n.colSku) + '</th>' +
      '<th>' + escapeHtml(i18n.colQty) + '</th>' +
      '<th>' + escapeHtml(i18n.colUnit) + '</th>' +
      '<th>' + escapeHtml(i18n.colRow) + '</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
  }

  function eventEl(ev) {
    var t = ev && ev.target;
    if (!t) {
      return null;
    }
    if (t.nodeType === 3) {
      t = t.parentElement;
    }
    if (t && t.nodeType !== 1) {
      t = t.parentElement;
    }
    return t && typeof t.closest === 'function' ? t : null;
  }

  function init(root) {
    if (!root || root.getAttribute('data-order-accordion-ready') === '1') {
      return;
    }
    root.setAttribute('data-order-accordion-ready', '1');
    var detailUrl = root.getAttribute('data-order-lines-url') || '';
    var i18n = {
      loading: root.getAttribute('data-i18n-loading') || 'Loading…',
      loadFailed: root.getAttribute('data-i18n-failed') || 'Load failed',
      noLines: root.getAttribute('data-i18n-no-lines') || 'No products',
      productsTitle: root.getAttribute('data-i18n-products') || 'Products',
      colName: root.getAttribute('data-i18n-col-name') || 'Name',
      colSku: root.getAttribute('data-i18n-col-sku') || 'SKU',
      colQty: root.getAttribute('data-i18n-col-qty') || 'Qty',
      colUnit: root.getAttribute('data-i18n-col-unit') || 'Unit',
      colRow: root.getAttribute('data-i18n-col-row') || 'Subtotal',
      expand: root.getAttribute('data-i18n-expand') || 'Expand',
      collapse: root.getAttribute('data-i18n-collapse') || 'Collapse',
    };
    var cache = Object.create(null);

    function syncHint(row, open) {
      var hint = row.querySelector('[data-testid="dropship-order-expand-hint"]');
      if (!hint) {
        return;
      }
      hint.setAttribute('aria-expanded', open ? 'true' : 'false');
      hint.classList.toggle('is-expanded', open);
      var label = hint.querySelector('[data-order-expand-label]');
      if (label) {
        label.textContent = open ? i18n.collapse : i18n.expand;
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
        if (open) {
          detail.removeAttribute('hidden');
          detail.hidden = false;
        } else {
          detail.setAttribute('hidden', '');
          detail.hidden = true;
        }
        detail.classList.toggle('is-open', open);
      }
    }

    function findDetail(row) {
      var next = row.nextElementSibling;
      if (next && next.getAttribute('data-testid') === 'dropship-order-detail') {
        return next;
      }
      return null;
    }

    function loadDetail(row, detail) {
      var uuid = row.getAttribute('data-order-uuid') || '';
      var body = detail.querySelector('[data-order-detail-body]');
      if (!body) {
        return;
      }
      if (cache[uuid]) {
        body.innerHTML = renderLines(cache[uuid], i18n);
        return;
      }
      body.innerHTML = '<div class="w-text" data-tone="muted" data-size="sm" data-testid="dropship-order-lines-loading">' +
        escapeHtml(i18n.loading) + '</div>';
      if (!detailUrl || !uuid) {
        body.innerHTML = renderLines({ ok: false, message: 'detail_url_missing' }, i18n);
        return;
      }
      var url = detailUrl + (detailUrl.indexOf('?') >= 0 ? '&' : '?') + 'order_uuid=' + encodeURIComponent(uuid);
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
          cache[uuid] = payload;
          body.innerHTML = renderLines(payload, i18n);
        })
        .catch(function () {
          body.innerHTML = renderLines({ ok: false, message: i18n.loadFailed }, i18n);
        });
    }

    function toggleRow(row) {
      var detail = findDetail(row);
      if (!detail) {
        return;
      }
      var open = row.getAttribute('aria-expanded') === 'true';
      if (open) {
        setExpanded(row, detail, false);
        return;
      }
      root.querySelectorAll('[data-testid="dropship-order-row"][data-order-expandable="1"][aria-expanded="true"]').forEach(function (other) {
        if (other !== row) {
          setExpanded(other, findDetail(other), false);
        }
      });
      setExpanded(row, detail, true);
      loadDetail(row, detail);
    }

    root.addEventListener('click', function (ev) {
      var t = eventEl(ev);
      if (!t) {
        return;
      }
      var toggle = t.closest('[data-order-expand-toggle]');
      if (toggle) {
        var toggleRowEl = toggle.closest('[data-testid="dropship-order-row"][data-order-expandable="1"]');
        if (toggleRowEl && root.contains(toggleRowEl)) {
          ev.preventDefault();
          toggleRow(toggleRowEl);
        }
        return;
      }
      if (t.closest('a, button, input, label, select, textarea, .w-button')) {
        return;
      }
      var row = t.closest('[data-testid="dropship-order-row"][data-order-expandable="1"]');
      if (!row || !root.contains(row)) {
        return;
      }
      toggleRow(row);
    }, true);

    root.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' && ev.key !== ' ') {
        return;
      }
      var t = eventEl(ev);
      if (!t) {
        return;
      }
      var toggle = t.closest('[data-order-expand-toggle]');
      var row = toggle
        ? toggle.closest('[data-testid="dropship-order-row"][data-order-expandable="1"]')
        : t.closest('[data-testid="dropship-order-row"][data-order-expandable="1"]');
      if (!row || !root.contains(row)) {
        return;
      }
      ev.preventDefault();
      toggleRow(row);
    });
  }

  function boot() {
    document.querySelectorAll('[data-dropship-order-accordion="1"]').forEach(init);
  }

  global.WelineDropshipOrderAccordion = { init: init, boot: boot, renderLines: renderLines };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : this);
