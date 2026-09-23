window.WelineWidgetAssets.register('theme-header-full-header-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    var placeholder = root.nextElementSibling;
    var menuToggle = root.querySelector('.mobile-menu-toggle');
    var nav = root.querySelector('.header-nav');

    if (Number(widgetScript.dataset.v1)) {
    var headerHeight = root.offsetHeight;

    function updatePlaceholder() {
        if (placeholder && root.classList.contains('is-sticky')) {
            placeholder.style.height = headerHeight + 'px';
            placeholder.hidden = false;
        }
    }

    window.addEventListener('resize', function () {
        headerHeight = root.offsetHeight;
        updatePlaceholder();
    });

    updatePlaceholder();
    }

    if (menuToggle && nav) {
        menuToggle.addEventListener('click', function () {
            var open = root.classList.toggle('is-drawer-open');
            nav.classList.toggle('is-open', open);
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.style.overflow = open ? 'hidden' : '';
        });
    }
})();
});
