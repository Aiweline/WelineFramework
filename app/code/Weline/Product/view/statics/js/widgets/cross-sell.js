(function () {
  function money(n) {
    return '¥' + (Math.round(n * 100) / 100).toFixed(2);
  }

  function bindImageFallback(root) {
    root.querySelectorAll('.wpf-media img[data-fallback]').forEach(function (img) {
      var fallback = img.getAttribute('data-fallback') || '';
      if (!fallback) {
        return;
      }
      function useFallback() {
        if (img.getAttribute('data-failed') === '1') {
          return;
        }
        img.setAttribute('data-failed', '1');
        img.removeAttribute('srcset');
        img.src = fallback;
      }
      img.addEventListener('error', useFallback);
      if (img.complete && img.naturalWidth === 0) {
        useFallback();
      }
    });
  }

  function init(root) {
    if (!root || root.getAttribute('data-wpf-ready') === '1') {
      return;
    }

    var checkboxes = root.querySelectorAll('.wpf-checkbox');
    var totalEl = root.querySelector('[data-wpf-total]');
    var addAll = root.querySelector('[data-wpf-add-all]');
    var status = root.querySelector('[data-wpf-status]');
    var msgSelect = root.getAttribute('data-msg-select-one') || '';
    var msgAdded = root.getAttribute('data-msg-added') || '';
    var msgAddAll = root.getAttribute('data-msg-add-all') || '';

    function notice(message, type) {
      if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
        window.Weline.UI.toast.show(message, { tone: type || 'info' });
        return;
      }
      if (!status) {
        return;
      }
      status.textContent = message;
      status.className = 'wpf-status is-' + (type || 'info');
    }

    function updateTotal() {
      if (!totalEl) {
        return;
      }
      var total = 0;
      checkboxes.forEach(function (cb) {
        if (cb.checked) {
          total += parseFloat(cb.getAttribute('data-price') || '0') || 0;
        }
      });
      totalEl.textContent = money(total);
    }

    bindImageFallback(root);

    checkboxes.forEach(function (cb) {
      cb.addEventListener('change', updateTotal);
    });
    updateTotal();

    if (addAll) {
      addAll.addEventListener('click', function () {
        var selected = [];
        checkboxes.forEach(function (cb) {
          if (cb.checked) {
            var item = cb.closest('.wpf-item');
            if (item) {
              selected.push({
                productId: item.getAttribute('data-product-id') || '',
                offerUuid: item.getAttribute('data-global-offer-uuid') || ''
              });
            }
          }
        });
        if (selected.length === 0) {
          notice(msgSelect, 'warning');
          return;
        }
        notice(msgAdded, 'success');
        addAll.setAttribute('data-state', 'saved');
        var label = addAll.querySelector('[data-wpf-cta-label]');
        if (label) {
          label.textContent = msgAdded;
        }
        window.setTimeout(function () {
          addAll.setAttribute('data-state', 'idle');
          if (label) {
            label.textContent = msgAddAll;
          }
        }, 1800);
      });
    }

    root.setAttribute('data-wpf-ready', '1');
  }

  function boot(scope) {
    (scope || document).querySelectorAll('.weline-product-fbt[data-js-ns]').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})();
