window.WelineWidgetAssets.register('theme-container-header-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;
    var toggle = root.querySelector('.header-mobile-menu-btn');
    var overlay = document.createElement('div');
    overlay.className = 'header-mobile-drawer-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    root.appendChild(overlay);

    function setOpen(open) {
        root.classList.toggle('is-drawer-open', open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.style.overflow = open ? 'hidden' : '';
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setOpen(!root.classList.contains('is-drawer-open'));
        });
    }
    overlay.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root.classList.contains('is-drawer-open')) {
            setOpen(false);
        }
    });
})();
});
