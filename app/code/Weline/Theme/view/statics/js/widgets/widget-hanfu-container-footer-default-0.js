window.WelineWidgetAssets.register('theme-hanfu-container-footer-default-0', function (widgetScript) {
(function () {
            var btn = document.getElementById('navBackToTop');
            if (btn && btn.getAttribute('data-weline-back-to-top-bound') !== '1') {
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
            }

            var root = widgetScript && widgetScript.closest
                ? widgetScript.closest('.weline-footer, footer.weline-footer, [data-widget-code="footer-container"]')
                : null;
            var scope = root || document;
            var sections = scope.querySelectorAll('details.footer-section[data-footer-accordion]');
            if (!sections.length) {
                return;
            }
            var mq = null;
            try {
                mq = window.matchMedia('(max-width: 560px)');
            } catch (e3) {
                mq = null;
            }
            function isMobile() {
                return !!(mq && mq.matches);
            }
            function syncAccordion() {
                var mobile = isMobile();
                for (var i = 0; i < sections.length; i++) {
                    var el = sections[i];
                    if (mobile) {
                        if (el.open && el.getAttribute('data-footer-accordion-user') !== '1') {
                            el.open = false;
                        }
                    } else if (!el.open) {
                        el.open = true;
                        el.removeAttribute('data-footer-accordion-user');
                    }
                }
            }
            for (var j = 0; j < sections.length; j++) {
                (function (el) {
                    if (el.getAttribute('data-footer-accordion-bound') === '1') {
                        return;
                    }
                    el.setAttribute('data-footer-accordion-bound', '1');
                    el.addEventListener('toggle', function () {
                        if (isMobile()) {
                            el.setAttribute('data-footer-accordion-user', '1');
                            return;
                        }
                        if (!el.open) {
                            el.open = true;
                        }
                    });
                })(sections[j]);
            }
            syncAccordion();
            if (mq) {
                if (typeof mq.addEventListener === 'function') {
                    mq.addEventListener('change', syncAccordion);
                } else if (typeof mq.addListener === 'function') {
                    mq.addListener(syncAccordion);
                }
            }
        })();
});
