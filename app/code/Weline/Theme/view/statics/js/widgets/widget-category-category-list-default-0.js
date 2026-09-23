window.WelineWidgetAssets.register('theme-category-category-list-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    root.querySelectorAll('.toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.category-item');
            if (!item) return;
            var subList = item.querySelector(':scope > .category-list');
            if (!subList) return;

            var isHidden = subList.hasAttribute('hidden');
            if (isHidden) {
                subList.removeAttribute('hidden');
            } else {
                subList.setAttribute('hidden', '');
            }
            btn.classList.toggle('is-collapsed', !isHidden);
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        });
    });
})();
});
