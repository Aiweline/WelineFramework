/**
 * App 下载页：PWA beforeinstallprompt 捕获 + 安装按钮。
 * 自包含页无 weline loader，经 <script src defer> 载入。
 */
(function () {
  var deferred = null;
  var btn = document.querySelector('[data-pwa-install]');
  var hint = document.querySelector('[data-pwa-hint]');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (btn) { btn.hidden = false; }
    if (hint) { hint.hidden = true; }
  });
  if (btn) {
    btn.addEventListener('click', function () {
      if (!deferred) { return; }
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; btn.hidden = true; });
    });
  }
})();
