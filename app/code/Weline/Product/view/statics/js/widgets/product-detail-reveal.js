/**
 * PDP detail magazine floors: scroll-triggered reveal (ecommerce-detail-suite §5.4).
 * Perf-first: only arm floors near the viewport; opacity-only motion; no will-change storm.
 * Respects prefers-reduced-motion.
 */
(function () {
    'use strict';

    var BODY_SEL = '[data-testid="product-description-body"]';
    var FLOOR_SEL = [
        '.weline-detail-prose',
        '.weline-detail-feature',
        '.weline-detail-figure-stack',
        '.weline-detail-bento',
        '.weline-detail-text',
        '[data-weline-detail-reveal]'
    ].join(',');

    function prefersReducedMotion() {
        try {
            return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {
            return false;
        }
    }

    function markFloors(body) {
        var nodes = body.querySelectorAll(FLOOR_SEL);
        var list = [];
        nodes.forEach(function (el) {
            if (el.closest('.weline-detail-feature__copy') && el.classList.contains('weline-detail-prose')) {
                return;
            }
            // Nested rows inside a stack are not independent floors.
            if (el.classList.contains('weline-detail-figure-row')
                && el.parentElement
                && el.parentElement.closest('.weline-detail-figure-stack')) {
                return;
            }
            el.classList.add('weline-detail-reveal');
            el.setAttribute('data-weline-detail-reveal', '1');
            list.push(el);
        });
        return list;
    }

    function show(el) {
        el.classList.add('is-in');
        el.setAttribute('data-inview', '1');
        el.classList.remove('is-armed');
    }

    function showInstant(el) {
        el.classList.add('is-in', 'is-in--instant');
        el.setAttribute('data-inview', '1');
        el.classList.remove('is-armed');
    }

    function isNearViewport(el) {
        var rect = el.getBoundingClientRect();
        var vh = window.innerHeight || document.documentElement.clientHeight || 0;
        // Within ~1 viewport above/below — candidate to arm, not yet animate.
        return rect.top < vh * 1.35 && rect.bottom > -vh * 0.35;
    }

    function isInViewport(el) {
        var rect = el.getBoundingClientRect();
        var vh = window.innerHeight || document.documentElement.clientHeight || 0;
        return rect.top < vh * 0.9 && rect.bottom > vh * 0.12;
    }

    function observe(body, list) {
        if (!list.length) {
            return;
        }
        if (prefersReducedMotion() || typeof IntersectionObserver !== 'function') {
            list.forEach(showInstant);
            return;
        }

        var pending = list.filter(function (el) {
            return !(el.classList.contains('is-in') || el.getAttribute('data-inview') === '1');
        });

        if (!pending.length) {
            return;
        }

        // Arm only near-viewport floors (opacity:0). Far floors stay visible — no layer storm.
        var armIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry.target;
                if (!entry.isIntersecting || el.classList.contains('is-in')) {
                    return;
                }
                el.classList.add('is-armed');
            });
        }, {
            root: null,
            rootMargin: '40% 0px 40% 0px',
            threshold: 0.01
        });

        var showIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                var el = entry.target;
                if (!el.classList.contains('is-armed')) {
                    el.classList.add('is-armed');
                }
                // Next frame so opacity:0 paints before is-in transition.
                window.requestAnimationFrame(function () {
                    show(el);
                });
                showIo.unobserve(el);
                armIo.unobserve(el);
                pending = pending.filter(function (node) {
                    return node !== el;
                });
            });
        }, {
            root: null,
            rootMargin: '0px 0px -10% 0px',
            threshold: 0.16
        });

        pending.forEach(function (el, index) {
            if (index < 4) {
                el.style.setProperty('--weline-detail-reveal-delay', (index * 40) + 'ms');
            }
            if (isInViewport(el)) {
                showInstant(el);
                return;
            }
            if (isNearViewport(el)) {
                el.classList.add('is-armed');
            }
            armIo.observe(el);
            showIo.observe(el);
        });

        body._welineDetailRevealCleanup = function () {
            try {
                armIo.disconnect();
                showIo.disconnect();
            } catch (e) {
                /* ignore */
            }
        };
    }

    function boot(scope) {
        var root = scope || document;
        var bodies = root.querySelectorAll(BODY_SEL);
        if (!bodies.length && root.matches && root.matches(BODY_SEL)) {
            bodies = [root];
        }
        bodies.forEach(function (body) {
            if (body.getAttribute('data-weline-detail-reveal-booted') === '1') {
                return;
            }
            var floors = markFloors(body);
            body.setAttribute('data-weline-detail-reveal-booted', '1');
            observe(body, floors);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            boot(document);
        });
    } else {
        boot(document);
    }

    document.addEventListener('weline:widget-rendered', function (event) {
        boot(event.target || document);
    });
})();
