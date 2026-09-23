window.WelineWidgetAssets.register('theme-hanfu-container-footer-default-0', function (widgetScript) {
(function () {
            var btn = document.getElementById('navBackToTop');
            if (!btn || btn.getAttribute('data-weline-back-to-top-bound') === '1') {
                return;
            }
            btn.setAttribute('data-weline-back-to-top-bound', '1');
            btn.addEventListener('click', function () {
                var reduce = false;
                try {
                    reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                } catch (e) {}
                try {
                    window.scrollTo({ top: 0, left: 0, behavior: reduce ? 'auto' : 'smooth' });
                } catch (e2) {
                    window.scrollTo(0, 0);
                }
                if (document.documentElement) {
                    document.documentElement.scrollTop = 0;
                }
                if (document.body) {
                    document.body.scrollTop = 0;
                }
            });
        })();
});
