window.WelineWidgetAssets.register('theme-sidebar-sidebar-menu-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    root.querySelectorAll('.has-children > .menu-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var item = this.closest('.menu-item');
            var submenu = item?.querySelector('.submenu');
            if (!submenu) return;
            item.classList.toggle('expanded');
            submenu.hidden = !submenu.hidden;
        });
    });
})();
});
