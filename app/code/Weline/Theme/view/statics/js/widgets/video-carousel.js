/**
 * Theme video-carousel: slide switching + Weline.UI.dialog for related products.
 * No native fetch — product cards are SSR-pre-rendered into dialog bodies.
 */
(function () {
    'use strict';

    if (window.WelineVideoCarousel && typeof window.WelineVideoCarousel.mount === 'function') {
        window.WelineVideoCarousel.mount(document);
        return;
    }

    const mounted = new WeakSet();

    function openDialog(target) {
        if (!target) {
            return false;
        }
        // Ensure native <dialog hidden> does not keep display:none after open.
        target.hidden = false;
        target.removeAttribute('hidden');
        const ui = window.Weline && window.Weline.UI && window.Weline.UI.dialog;
        if (ui && typeof ui.open === 'function') {
            return !!ui.open(target);
        }
        if (typeof target.showModal === 'function') {
            target.setAttribute('data-state', 'open');
            target.showModal();
            return true;
        }
        target.setAttribute('data-state', 'open');
        return true;
    }

    function mountRoot(root) {
        if (!(root instanceof HTMLElement) || mounted.has(root)) {
            return;
        }
        mounted.add(root);

        const slides = Array.from(root.querySelectorAll('[data-carousel-slide]'));
        const dots = Array.from(root.querySelectorAll('[data-action="carousel-dot"]'));
        if (!slides.length) {
            return;
        }

        let current = Math.max(0, slides.findIndex((slide) => slide.classList.contains('is-active')));
        if (current < 0) {
            current = 0;
        }

        const update = (index) => {
            current = ((index % slides.length) + slides.length) % slides.length;
            slides.forEach((slide, i) => {
                const active = i === current;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                if (active) {
                    slide.setAttribute('aria-current', 'true');
                    slide.removeAttribute('inert');
                } else {
                    slide.removeAttribute('aria-current');
                    slide.inert = true;
                }
            });
            dots.forEach((dot, i) => {
                const active = i === current;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            root.setAttribute('data-carousel-index', String(current));
        };

        root.addEventListener('click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('button,[data-action]')
                : null;
            if (!(button instanceof HTMLElement) || !root.contains(button)) {
                return;
            }

            const action = button.getAttribute('data-action') || '';
            if (action === 'carousel-prev') {
                event.preventDefault();
                update(current - 1);
                return;
            }
            if (action === 'carousel-next') {
                event.preventDefault();
                update(current + 1);
                return;
            }
            if (action === 'carousel-dot') {
                event.preventDefault();
                update(Number(button.getAttribute('data-index')) || 0);
                return;
            }
            if (action === 'open-related-products') {
                event.preventDefault();
                const selector = button.getAttribute('data-dialog-target') || '';
                const dialog = selector
                    ? root.querySelector(selector) || document.querySelector(selector)
                    : null;
                openDialog(dialog);
            }
        });

        update(current);
    }

    function mount(scope) {
        const roots = (scope || document).querySelectorAll
            ? (scope || document).querySelectorAll('[data-site-block="video-carousel"],[data-testid="video-carousel"]')
            : [];
        Array.from(roots).forEach(mountRoot);
    }

    window.WelineVideoCarousel = { mount: mount };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mount(document), { once: true });
    } else {
        mount(document);
    }
})();
