(function () {
  var DEFAULT_SRC = '/Weline/Theme/view/statics/images/storefront-placeholder/default.svg';
  var DEFAULT_PREDICT_MARGIN = '600px 0px';
  var predictObserver = null;

  function theme() {
    window.Weline = window.Weline || {};
    window.Weline.Theme = window.Weline.Theme || {};
    return window.Weline.Theme;
  }

  function policyConfig() {
    var t = theme();
    return {
      autoLazy: t.storefrontAutoLazy !== false,
      predict: t.storefrontPredictPrefetch !== false,
      predictMargin: (typeof t.storefrontPredictRootMargin === 'string' && t.storefrontPredictRootMargin.trim() !== '')
        ? t.storefrontPredictRootMargin.trim()
        : DEFAULT_PREDICT_MARGIN
    };
  }

  function placeholder(/* seed */) {
    var t = theme();
    var configured = t.storefrontPlaceholderSrc;
    if (typeof configured === 'string' && configured.trim() !== '') {
      return configured;
    }
    var urls = t.storefrontPlaceholderUrls || [];
    if (urls.length && typeof urls[0] === 'string' && urls[0].trim() !== '') {
      return urls[0];
    }
    return DEFAULT_SRC;
  }

  function classText(img) {
    if (!img) {
      return '';
    }
    if (typeof img.className === 'string') {
      return img.className;
    }
    if (img.className && typeof img.className.baseVal === 'string') {
      return img.className.baseVal;
    }
    return '';
  }

  function shouldSkipAutoLazy(img) {
    if (!img || img.nodeType !== 1) {
      return true;
    }
    if (img.hasAttribute('loading')) {
      return true;
    }
    var policy = (img.getAttribute('data-loading-policy') || '').toLowerCase();
    if (policy === 'eager' || policy === 'manual' || policy === 'off') {
      return true;
    }
    if (img.getAttribute('data-no-auto-lazy') === '1') {
      return true;
    }
    var fp = (img.getAttribute('fetchpriority') || img.getAttribute('fetchPriority') || '').toLowerCase();
    if (fp === 'high') {
      return true;
    }
    var cls = classText(img);
    if (/\blogo(-image)?\b/i.test(cls) || /\bcentered-header__logo-image\b/i.test(cls) || /captcha/i.test(cls)) {
      return true;
    }
    var src = (img.getAttribute('src') || '').trim();
    if (src.indexOf('data:image/') === 0) {
      return true;
    }
    if (img.hasAttribute('data-account-avatar') || img.hasAttribute('data-site-logo')) {
      return true;
    }
    if (img.closest) {
      if (img.closest('[data-loading-policy="eager"], [data-loading-policy="manual"], [data-no-auto-lazy="1"]')) {
        return true;
      }
      if (img.closest('[data-site-logo], .site-logo, .header-logo')) {
        return true;
      }
    }
    return false;
  }

  function applyAutoLazy(img) {
    if (!policyConfig().autoLazy || shouldSkipAutoLazy(img)) {
      return;
    }
    img.setAttribute('loading', 'lazy');
    if (!img.hasAttribute('decoding')) {
      img.setAttribute('decoding', 'async');
    }
    img.setAttribute('data-auto-lazy', '1');
  }

  function ensurePredictObserver() {
    var cfg = policyConfig();
    if (!cfg.predict || !window.IntersectionObserver) {
      return null;
    }
    if (predictObserver) {
      return predictObserver;
    }
    predictObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) {
          return;
        }
        var img = entry.target;
        predictObserver.unobserve(img);
        if (!img || img.getAttribute('data-predict-prefetch') === '1') {
          return;
        }
        if ((img.getAttribute('loading') || '').toLowerCase() === 'lazy') {
          img.loading = 'eager';
          img.setAttribute('data-predict-prefetch', '1');
        }
        if (typeof img.decode === 'function') {
          try {
            img.decode().catch(function () {});
          } catch (e) {}
        }
      });
    }, {
      root: null,
      rootMargin: cfg.predictMargin,
      threshold: 0.01
    });
    return predictObserver;
  }

  function schedulePredict(img) {
    if (!img || img.nodeType !== 1) {
      return;
    }
    if ((img.getAttribute('loading') || '').toLowerCase() !== 'lazy') {
      return;
    }
    if (img.getAttribute('data-predict-prefetch') === '1') {
      return;
    }
    if (img.complete && img.naturalWidth > 0) {
      return;
    }
    var obs = ensurePredictObserver();
    if (!obs) {
      return;
    }
    obs.observe(img);
  }

  function bindImage(img) {
    if (!img || img.getAttribute('data-storefront-img-ready') === '1') {
      return;
    }
    var fallback = img.getAttribute('data-fallback') || '';
    if (!fallback || fallback.indexOf('data:image/') === 0) {
      fallback = placeholder();
      img.setAttribute('data-fallback', fallback);
    }
    // Never leave a data URI on media src.
    var current = (img.getAttribute('src') || '').trim();
    if (current.indexOf('data:image/') === 0) {
      img.setAttribute('src', fallback);
      current = fallback;
    }
    function useFallback() {
      if (img.getAttribute('data-failed') === '1') {
        return;
      }
      img.setAttribute('data-failed', '1');
      img.removeAttribute('srcset');
      img.alt = '';
      img.src = fallback;
    }
    img.addEventListener('error', useFallback);
    if (!current) {
      useFallback();
    } else if (img.complete && img.naturalWidth === 0) {
      useFallback();
    }
    img.setAttribute('data-storefront-img-ready', '1');
  }

  function applyLoadingPolicy(img) {
    applyAutoLazy(img);
    schedulePredict(img);
  }

  function boot(scope) {
    var root = scope || document;
    var nodes = root.querySelectorAll ? root.querySelectorAll('img') : [];
    nodes.forEach(function (img) {
      applyLoadingPolicy(img);
      if (img.hasAttribute('data-storefront-img') || img.hasAttribute('data-fallback')) {
        bindImage(img);
      }
    });
  }

  var t = theme();
  t.storefrontImagePlaceholder = placeholder;
  t.bindStorefrontImages = boot;
  t.applyStorefrontImageLoadingPolicy = applyLoadingPolicy;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})();
