(function () {
  var DEFAULT_SRC = '/Weline/Theme/view/statics/images/storefront-placeholder/default.svg';

  function placeholder(/* seed */) {
    var configured = window.Weline && window.Weline.Theme && window.Weline.Theme.storefrontPlaceholderSrc;
    if (typeof configured === 'string' && configured.trim() !== '') {
      return configured;
    }
    var urls = (window.Weline && window.Weline.Theme && window.Weline.Theme.storefrontPlaceholderUrls) || [];
    if (urls.length && typeof urls[0] === 'string' && urls[0].trim() !== '') {
      return urls[0];
    }
    return DEFAULT_SRC;
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

  function boot(scope) {
    var root = scope || document;
    var nodes = root.querySelectorAll
      ? root.querySelectorAll('img[data-storefront-img], img[data-fallback]')
      : [];
    nodes.forEach(function (img) {
      bindImage(img);
    });
  }

  window.Weline = window.Weline || {};
  window.Weline.Theme = window.Weline.Theme || {};
  window.Weline.Theme.storefrontImagePlaceholder = placeholder;
  window.Weline.Theme.bindStorefrontImages = boot;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { boot(document); });
  } else {
    boot(document);
  }
  document.addEventListener('weline:widget-rendered', function (event) {
    boot(event.target || document);
  });
})();
