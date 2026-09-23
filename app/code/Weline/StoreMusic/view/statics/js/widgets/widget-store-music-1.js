window.WelineWidgetAssets.register('storemusic-store-music-1', function (widgetScript) {
/* P0：悬浮头像避让货架 H2（#homepage-featured / Recommended product / 特色产品），不拆音乐功能 */
(function () {
    var ROOT_SEL = '.w-store-music';
    var TARGET_SEL = [
        '#homepage-featured .widget-title',
        '#homepage-featured h2',
        '#homepage-featured .widget-header',
        '.homepage-section--featured-lead .widget-title',
        '.homepage-section--featured-lead h2',
        '.homepage-section--featured-lead .widget-header'
    ].join(',');
    var GAP = 20;
    var ticking = false;

    function roots() {
        return Array.prototype.slice.call(document.querySelectorAll(ROOT_SEL));
    }

    function readLiftPx(root) {
        var raw = root.style.getPropertyValue('--w-store-music-heading-lift')
            || window.getComputedStyle(root).getPropertyValue('--w-store-music-heading-lift')
            || '0';
        var n = parseFloat(raw);
        return Number.isFinite(n) ? n : 0;
    }

    function syncOne(root) {
        if (!root || !root.isConnected) {
            return;
        }
        var boxEl = root.querySelector('.w-store-music__avatar') || root;
        var box = boxEl.getBoundingClientRect();
        if (box.width < 1 || box.height < 1) {
            return;
        }
        // bottom 有 transition：勿先清零再量；用当前 lift 还原静息几何再算需抬量
        var currentLift = readLiftPx(root);
        var restBottom = box.bottom + currentLift;
        var restTop = box.top + currentLift;
        var restLeft = box.left;
        var restRight = box.right;
        var lift = 0;
        var nodes = document.querySelectorAll(TARGET_SEL);
        for (var i = 0; i < nodes.length; i++) {
            var tr = nodes[i].getBoundingClientRect();
            if (tr.width < 1 || tr.height < 1) {
                continue;
            }
            if (tr.bottom < 0 || tr.top > window.innerHeight) {
                continue;
            }
            var ox = Math.max(0, Math.min(restRight, tr.right) - Math.max(restLeft, tr.left));
            var oy = Math.max(0, Math.min(restBottom, tr.bottom) - Math.max(restTop, tr.top));
            if (ox <= 0 || oy <= 0) {
                continue;
            }
            var need = restBottom - (tr.top - GAP);
            if (need > lift) {
                lift = need;
            }
        }
        lift = Math.max(0, Math.ceil(lift));
        if (lift > 0) {
            root.style.setProperty('--w-store-music-heading-lift', lift + 'px');
            root.classList.add('is-heading-clear');
        } else {
            root.style.setProperty('--w-store-music-heading-lift', '0px');
            root.classList.remove('is-heading-clear');
        }
    }

    function syncAll() {
        var list = roots();
        for (var i = 0; i < list.length; i++) {
            syncOne(list[i]);
        }
    }

    function requestSync() {
        if (ticking) {
            return;
        }
        ticking = true;
        window.requestAnimationFrame(function () {
            ticking = false;
            syncAll();
        });
    }

    window.addEventListener('scroll', requestSync, {passive: true});
    window.addEventListener('resize', requestSync);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', requestSync);
    } else {
        requestSync();
    }
    window.setTimeout(requestSync, 120);
    window.setTimeout(requestSync, 480);
})();
});
