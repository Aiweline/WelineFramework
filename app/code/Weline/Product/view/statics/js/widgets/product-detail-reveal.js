/**
 * PDP detail magazine floors: scroll-triggered reveal (ecommerce-detail-suite §5.4).
 * Perf-first: only arm floors near the viewport; opacity-only motion; no will-change storm.
 * Respects prefers-reduced-motion.
 *
 * Description imgs ship as data-src placeholders (SSR DESCRIPTION_SSR_SRC_BUDGET=0).
 * Hydrate must not depend on 1×1 GIF self-intersection alone — observe media wrappers
 * and hydrate whenever a floor is armed / shown / near the viewport.
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
    var MEDIA_WRAP_SEL = '.weline-detail-feature__media, .weline-detail-figure, .weline-detail-figure-stack, .w-frame, figure';

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

    function hydrateDescImage(img) {
        if (!img || img.getAttribute('data-pdp-desc-lazy') !== '1') {
            return;
        }
        var real = (img.getAttribute('data-src') || '').trim();
        if (!real) {
            return;
        }
        img.setAttribute('src', real);
        img.removeAttribute('data-src');
        img.removeAttribute('data-pdp-desc-lazy');
        img.setAttribute('loading', 'lazy');
        img.setAttribute('decoding', 'async');
    }

    function hydrateDescImagesIn(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        var imgs = root.querySelectorAll('img[data-pdp-desc-lazy="1"][data-src]');
        for (var i = 0; i < imgs.length; i += 1) {
            hydrateDescImage(imgs[i]);
        }
    }

    function show(el) {
        el.classList.add('is-in');
        el.setAttribute('data-inview', '1');
        el.classList.remove('is-armed');
        hydrateDescImagesIn(el);
    }

    function showInstant(el) {
        el.classList.add('is-in', 'is-in--instant');
        el.setAttribute('data-inview', '1');
        el.classList.remove('is-armed');
        hydrateDescImagesIn(el);
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

    /**
     * Visible-gate scripts often boot after the user has already scrolled past
     * early magazine floors. Those floors never get a future intersecting=true.
     */
    function hasBeenReached(el) {
        var rect = el.getBoundingClientRect();
        var vh = window.innerHeight || document.documentElement.clientHeight || 0;
        return rect.top < vh * 0.9;
    }

    /**
     * Description magazine imgs ship as data-src placeholders (SSR).
     * Assign real src only when near the viewport — native loading=lazy still storms PDP.
     */
    function observeDescImages(body) {
        var imgs = body.querySelectorAll('img[data-pdp-desc-lazy="1"][data-src]');
        if (!imgs.length) {
            return;
        }
        var list = Array.prototype.slice.call(imgs);
        var hydrateAll = function () {
            list.forEach(hydrateDescImage);
        };
        if (typeof IntersectionObserver !== 'function') {
            hydrateAll();
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                var target = entry.target;
                if (target && target.tagName === 'IMG') {
                    hydrateDescImage(target);
                } else {
                    hydrateDescImagesIn(target);
                }
                io.unobserve(target);
            });
        }, { root: null, rootMargin: '280px 0px', threshold: 0 });

        list.forEach(function (img) {
            var wrap = img.closest(MEDIA_WRAP_SEL) || img;
            var rect = wrap.getBoundingClientRect();
            var vh = window.innerHeight || 0;
            // Anything already above, in view, or within preload margin — hydrate now.
            // Do not require rect.bottom > -280: scrolled-past floors must still get src.
            if (rect.top < vh + 280) {
                hydrateDescImage(img);
                return;
            }
            // Observe the media wrapper: 1×1 GIF often has a useless self-box;
            // the figure/frame usually reserves the real layout height.
            io.observe(wrap);
        });

        var prevCleanup = body._welineDetailRevealCleanup;
        body._welineDetailRevealCleanup = function () {
            try {
                io.disconnect();
            } catch (e) {
                /* ignore */
            }
            if (typeof prevCleanup === 'function') {
                prevCleanup();
            }
        };
    }

    function observe(body, list) {
        // Image hydrate is independent of floor animation — always arm it.
        observeDescImages(body);

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

        // Preload near-viewport floors WITHOUT is-armed (opacity:0). Early arm + miss show
        // left blank magazine media after visible-gate script boot / fast scroll.
        var armIo = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry.target;
                if (!entry.isIntersecting || el.classList.contains('is-in')) {
                    return;
                }
                hydrateDescImagesIn(el);
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
                hydrateDescImagesIn(el);
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
            // Tall magazine floors rarely reach 0.16 ratio while scrolling —
            // any pixel in view is enough to reveal + hydrate.
            rootMargin: '80px 0px 80px 0px',
            threshold: 0.01
        });

        pending.forEach(function (el, index) {
            if (index < 4) {
                el.style.setProperty('--weline-detail-reveal-delay', (index * 40) + 'ms');
            }
            // Already scrolled to/past this floor (common after data-weline-load visible gate).
            if (hasBeenReached(el)) {
                showInstant(el);
                return;
            }
            if (isNearViewport(el)) {
                hydrateDescImagesIn(el);
            }
            armIo.observe(el);
            showIo.observe(el);
        });

        var prevCleanup = body._welineDetailRevealCleanup;
        body._welineDetailRevealCleanup = function () {
            try {
                armIo.disconnect();
                showIo.disconnect();
            } catch (e) {
                /* ignore */
            }
            if (typeof prevCleanup === 'function') {
                prevCleanup();
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
                // Re-entry after widget re-render: still hydrate any leftover placeholders.
                if (body.querySelector('img[data-pdp-desc-lazy="1"][data-src]')) {
                    observeDescImages(body);
                }
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
