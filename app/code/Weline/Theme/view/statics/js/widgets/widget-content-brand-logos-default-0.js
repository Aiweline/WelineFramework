window.WelineWidgetAssets.register('theme-content-brand-logos-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    var grid = root.querySelector('.brands-grid');
    var prevBtn = root.querySelector('.carousel-prev');
    var nextBtn = root.querySelector('.carousel-next');
    if (!grid || !prevBtn || !nextBtn) return;

    var offset = 0;
    var step = 0;

    function recalc() {
        var item = grid.querySelector('.brand-item');
        if (!item) return;
        step = item.offsetWidth + 20;
    }

    function slide(direction) {
        recalc();
        if (!step) return;
        var maxOffset = Math.max(0, grid.scrollWidth - grid.parentElement.offsetWidth);
        offset = Math.max(0, Math.min(maxOffset, offset + direction * step));
        grid.style.transform = 'translateX(-' + offset + 'px)';
    }

    prevBtn.addEventListener('click', function () { slide(-1); });
    nextBtn.addEventListener('click', function () { slide(1); });
    window.addEventListener('resize', recalc);
    recalc();
})();
});
