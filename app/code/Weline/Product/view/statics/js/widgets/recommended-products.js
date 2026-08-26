(function () {
  function init(root) {
    if (!root || root.getAttribute('data-wpc-ready') === '1') {
      return;
    }
    if (root.getAttribute('data-layout') !== 'carousel') {
      root.setAttribute('data-wpc-ready', '1');
      return;
    }

    var viewport = root.querySelector('[data-wpc-viewport]');
    var track = root.querySelector('[data-wpc-track]');
    var prev = root.querySelector('[data-wpc-prev]');
    var next = root.querySelector('[data-wpc-next]');
    if (!viewport || !track) {
      return;
    }

    function step() {
      var card = track.querySelector('.wpc-card');
      if (!card) {
        return Math.max(200, viewport.clientWidth * 0.8);
      }
      var styles = window.getComputedStyle(track);
      var gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
      return card.getBoundingClientRect().width + gap;
    }

    function sync() {
      var max = Math.max(0, track.scrollWidth - viewport.clientWidth - 2);
      if (prev) {
        prev.disabled = track.scrollLeft <= 2;
      }
      if (next) {
        next.disabled = track.scrollLeft >= max;
      }
    }

    if (prev) {
      prev.addEventListener('click', function () {
        track.scrollBy({ left: -step(), behavior: 'smooth' });
      });
    }
    if (next) {
      next.addEventListener('click', function () {
        track.scrollBy({ left: step(), behavior: 'smooth' });
      });
    }
    track.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
    root.setAttribute('data-wpc-ready', '1');
  }

  function boot(scope) {
    var roots = (scope || document).querySelectorAll('.weline-product-recommended[data-js-ns]');
    roots.forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      boot(document);
    });
  } else {
    boot(document);
  }

  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})();
