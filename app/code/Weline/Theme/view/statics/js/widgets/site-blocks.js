(function () {
    'use strict';
    if (window.WelineSiteBlocks) {
        window.WelineSiteBlocks.mount(document);
        return;
    }

    const mounted = new WeakSet();
    const sliders = new Set();
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function mountHero(root) {
        const slides = Array.from(root.querySelectorAll('.slide'));
        const dots = Array.from(root.querySelectorAll('.dot'));
        if (!slides.length) return;
        let current = 0;
        let timer = null;
        let paused = root.dataset.autoplay !== 'true';
        let hovering = false;
        const pauseButton = root.querySelector('[data-slider-pause]');
        const stop = () => { clearInterval(timer); timer = null; };
        const update = (index) => {
            current = (index + slides.length) % slides.length;
            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === current);
                slide.setAttribute('aria-hidden', String(i !== current));
                slide.inert = i !== current;
            });
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === current);
                dot.setAttribute('aria-pressed', String(i === current));
            });
        };
        const start = () => {
            stop();
            if (!root.isConnected) { sliders.delete(start); return; }
            if (paused || hovering || document.hidden || reducedMotion.matches || root.contains(document.activeElement) || slides.length < 2) return;
            timer = setInterval(() => {
                if (!root.isConnected) { stop(); sliders.delete(start); return; }
                update(current + 1);
            }, Math.max(1000, Number(root.dataset.autoplaySpeed) || 5000));
        };
        root.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            if (!button) return;
            if (button.matches('.slider-prev')) update(current - 1);
            else if (button.matches('.slider-next')) update(current + 1);
            else if (button.matches('.dot')) update(Number(button.dataset.index) || 0);
            else if (button === pauseButton) {
                paused = !paused;
                button.textContent = paused ? button.dataset.playLabel : button.dataset.pauseLabel;
                button.setAttribute('aria-pressed', String(paused));
            } else return;
            start();
        });
        root.addEventListener('mouseenter', () => { hovering = true; stop(); });
        root.addEventListener('mouseleave', () => { hovering = false; start(); });
        root.addEventListener('focusin', stop);
        root.addEventListener('focusout', () => setTimeout(start, 0));
        let touchX = null;
        root.addEventListener('touchstart', (event) => { touchX = event.changedTouches[0].screenX; stop(); }, { passive: true });
        root.addEventListener('touchend', (event) => {
            const delta = touchX === null ? 0 : touchX - event.changedTouches[0].screenX;
            if (Math.abs(delta) > 50) update(current + (delta > 0 ? 1 : -1));
            touchX = null;
            start();
        }, { passive: true });
        update(0);
        sliders.add(start);
        start();
    }

    function mountFaq(root) {
        const items = Array.from(root.querySelectorAll('.sb-faq'));
        const search = root.querySelector('[data-faq-search]');
        const empty = root.querySelector('[data-faq-empty]');
        root.addEventListener('toggle', (event) => {
            if (root.dataset.allowMultiple === 'true' || !event.target.open) return;
            items.forEach((item) => { if (item !== event.target) item.open = false; });
        }, true);
        if (search) search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            items.forEach((item) => {
                item.hidden = !item.textContent.toLocaleLowerCase().includes(query);
                if (!item.hidden) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
        });
    }

    function mountPromo(root) {
        let timer = null;
        const stop = () => { clearInterval(timer); timer = null; };
        const close = root.querySelector('.promo-close');
        if (close) close.addEventListener('click', () => { root.hidden = true; root.classList.add('is-hidden'); stop(); });
        const countdown = root.querySelector('.promo-countdown');
        if (!countdown) return;
        // Explicit offsets are preserved; legacy local date/time remains supported.
        const end = Date.parse(countdown.dataset.end.replace(' ', 'T'));
        if (!Number.isFinite(end)) { countdown.hidden = true; return; }
        const tick = () => {
            if (!root.isConnected || root.hidden) { stop(); return; }
            const remaining = Math.max(0, end - Date.now());
            const values = {
                days: Math.floor(remaining / 86400000),
                hours: Math.floor(remaining % 86400000 / 3600000),
                minutes: Math.floor(remaining % 3600000 / 60000),
                seconds: Math.floor(remaining % 60000 / 1000)
            };
            Object.entries(values).forEach(([unit, value]) => {
                const output = countdown.querySelector('[data-unit="' + unit + '"] .time-value');
                if (output) output.textContent = String(value).padStart(2, '0');
            });
            if (!remaining) { root.hidden = true; root.classList.add('is-hidden'); stop(); }
        };
        tick();
        if (!root.hidden) timer = setInterval(tick, 1000);
    }

    function mountTestimonials(root) {
        const scroller = root.querySelector('.sb-scroll');
        if (!scroller) return;
        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-scroll-direction]');
            if (!button) return;
            const direction = Number(button.dataset.scrollDirection) * (getComputedStyle(root).direction === 'rtl' ? -1 : 1);
            const gap = parseFloat(getComputedStyle(scroller).gap) || 0;
            scroller.scrollBy({ left: direction * (scroller.clientWidth + gap), behavior: reducedMotion.matches ? 'instant' : 'smooth' });
        });
    }

    const handlers = { hero: mountHero, faq: mountFaq, promo: mountPromo, testimonials: mountTestimonials };
    function mount(context) {
        const roots = [];
        if (context.matches && context.matches('[data-site-block]')) roots.push(context);
        if (context.querySelectorAll) roots.push(...context.querySelectorAll('[data-site-block]'));
        roots.forEach((root) => {
            if (mounted.has(root) || !handlers[root.dataset.siteBlock]) return;
            mounted.add(root);
            handlers[root.dataset.siteBlock](root);
        });
    }
    function boot() {
        mount(document);
        new MutationObserver((records) => {
            records.forEach((record) => record.addedNodes.forEach((node) => { if (node.nodeType === 1) mount(node); }));
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
    document.addEventListener('visibilitychange', () => sliders.forEach((resume) => resume()));
    reducedMotion.addEventListener('change', () => sliders.forEach((resume) => resume()));
    window.WelineSiteBlocks = { mount: mount };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
