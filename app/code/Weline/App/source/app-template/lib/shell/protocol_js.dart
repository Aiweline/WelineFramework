/// 注入 WebView 的协议识别脚本：幂等（重复注入安全）。
/// JS 通过 window.WelineApp.postMessage(JSON.stringify(state)) 回传。
const String kProtocolScript = r'''
(function () {
  if (window.__welineApp) {
    window.__welineApp.refresh();
    return;
  }

  function snapshot() {
    var layoutEl = document.querySelector('[data-layout]');
    var layout = layoutEl ? layoutEl.getAttribute('data-layout') : '';

    var cartCount = 0;
    var cartEl = document.querySelector('[weline-code="theme.widget.mini_cart_icon"]');
    if (cartEl) {
      var matches = cartEl.textContent.match(/\d+/g);
      if (matches && matches.length) {
        cartCount = parseInt(matches[matches.length - 1], 10);
        if (isNaN(cartCount)) {
          cartCount = 0;
        }
      }
    }

    var loggedIn = false;
    var accountEl = document.querySelector('[weline-code="theme.widget.account"]');
    if (accountEl) {
      if (accountEl.querySelector('a[href*="logout"]')) {
        loggedIn = true;
      } else {
        var text = accountEl.textContent || '';
        loggedIn = text.indexOf('Sign out') !== -1 || text.indexOf('退出') !== -1;
      }
    }

    return {
      layout: layout,
      title: document.title,
      cartCount: cartCount,
      loggedIn: loggedIn,
      primaryColor: getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim()
    };
  }

  function hideChrome() {
    var selectors = ['.ca-announce', '[data-testid="ca-announcement"]', 'footer', '[weline-code*="affiliate_float"]'];
    for (var i = 0; i < selectors.length; i++) {
      var nodes = document.querySelectorAll(selectors[i]);
      for (var j = 0; j < nodes.length; j++) {
        nodes[j].style.visibility = 'hidden';
        nodes[j].style.height = '0';
        nodes[j].style.overflow = 'hidden';
      }
    }
  }

  var lastJson = '';

  function refresh() {
    hideChrome();
    var json = JSON.stringify(snapshot());
    lastJson = json;
    try {
      window.WelineApp && window.WelineApp.postMessage(json);
    } catch (e) {
    }
  }
  var timer = null;
  if (typeof MutationObserver === 'function' && document.body) {
    new MutationObserver(function () {
      if (timer) {
        clearTimeout(timer);
      }
      timer = setTimeout(function () {
        timer = null;
        var nextJson = JSON.stringify(snapshot());
        if (nextJson !== lastJson) {
          lastJson = nextJson;
          window.WelineApp && window.WelineApp.postMessage(nextJson);
        }
      }, 300);
    }).observe(document.body, {childList: true, subtree: true});
  }

  window.__welineApp = {refresh: refresh, snapshot: snapshot};
  refresh();
})();
''';
