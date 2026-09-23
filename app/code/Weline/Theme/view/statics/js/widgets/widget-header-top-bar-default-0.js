window.WelineWidgetAssets.register('theme-header-top-bar-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;
    var btn = root.querySelector('[data-notice-close]');
    if (!btn) return;
    var key = 'weline-notice-hidden:' + root.getAttribute('data-uid');
    btn.addEventListener('click', function () {
        root.classList.add('is-hidden');
        try { sessionStorage.setItem(key, '1'); } catch (e) {}
    });
    try {
        if (sessionStorage.getItem(key) === '1') {
            root.classList.add('is-hidden');
        }
    } catch (e) {}
})();
});
