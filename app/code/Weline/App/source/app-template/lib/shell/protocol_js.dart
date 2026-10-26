/// 注入 WebView 的协议识别脚本：幂等（重复注入安全）。
/// JS 通过 window.WelineApp.postMessage(JSON.stringify(state)) 回传。
/// 设计约束：
/// - 无 MutationObserver（重 JS 页面拖垮主线程，R44 对照实验定位）；状态刷新走 5s 轮询
/// - 无链接点击 preventDefault（监听顺序不可控）；站内导航交给 WKWebView 原生处理，
///   站点 JS 路由在 WKWebView 失效时的放行由主题侧 `window.WelineApp` 检测兜底
/// - 只做三件事：隐藏网页 chrome、读协议状态（layout/title/cartCount/loggedIn/primaryColor）、
///   5s 轮询回传（覆盖 AJAX 加购后的角标变化）
const String kProtocolScript = r'''
(function () {
  if (window.__welineApp) {
    window.__welineApp.refresh();
    return;
  }

  var HIDE_SELECTORS = [
    '.ca-announce',
    '[data-testid="ca-announcement"]',
    'footer',
    '[weline-code*="affiliate_float"]'
  ];

  function hideChrome() {
    for (var i = 0; i < HIDE_SELECTORS.length; i++) {
      var nodes = document.querySelectorAll(HIDE_SELECTORS[i]);
      for (var j = 0; j < nodes.length; j++) {
        nodes[j].style.visibility = 'hidden';
        nodes[j].style.height = '0';
        nodes[j].style.overflow = 'hidden';
      }
    }
  }

  function snapshot() {
    var layoutEl = document.querySelector('[data-layout]');
    var layout = layoutEl ? layoutEl.getAttribute('data-layout') : '';

    var cartCount = 0;
    var cartEl = document.querySelector('[weline-code="theme.widget.mini_cart_icon"]');
    if (cartEl) {
      var matches = (cartEl.textContent || '').match(/\d+/g);
      if (matches && matches.length) {
        cartCount = parseInt(matches[matches.length - 1], 10);
        if (isNaN(cartCount)) cartCount = 0;
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

    var primaryColor = '';
    try {
      primaryColor = getComputedStyle(document.documentElement)
        .getPropertyValue('--color-primary').trim();
    } catch (e) {}

    return {
      layout: layout,
      title: document.title,
      cartCount: cartCount,
      loggedIn: loggedIn,
      primaryColor: primaryColor
    };
  }

  function refresh() {
    hideChrome();
    var json = JSON.stringify(snapshot());
    try {
      window.WelineApp && window.WelineApp.postMessage(json);
    } catch (e) {}
  }

  // 增强壳：禁 window.open（防站点 JS 把 Safari 拉到前台打断购物流）
  try { window.open = function () { return null; }; } catch (e) {}
  // <base target=_blank> 的链接降级为当前页打开
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[target="_blank"]') : null;
    if (a) { try { e.preventDefault(); a.removeAttribute('target'); a.click(); } catch (err) {} }
  }, true);

  window.__welineApp = { refresh: refresh, snapshot: snapshot };
  try { refresh(); } catch (e) {}
  setInterval(function () { try { refresh(); } catch (e) {} }, 5000);
})();
''';
