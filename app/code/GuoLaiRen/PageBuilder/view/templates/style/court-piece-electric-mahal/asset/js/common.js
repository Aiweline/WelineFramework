(function () {
    document.documentElement.classList.add('cpe-js');

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    function addBackToTop() {
        if (document.getElementById('backToTop')) {
            return;
        }

        var button = document.createElement('a');
        button.id = 'backToTop';
        button.className = 'back-top';
        button.href = '#top';
        button.setAttribute('aria-label', 'Back to top');
        document.body.appendChild(button);

        button.addEventListener('click', function (event) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        var toggleButton = function () {
            button.style.display = window.scrollY > 560 ? 'block' : 'none';
        };

        window.addEventListener('scroll', toggleButton, { passive: true });
        toggleButton();
    }

    function initReveals() {
        var targets = document.querySelectorAll('[data-cpe-reveal]');
        if (!targets.length) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            targets.forEach(function (target) {
                target.classList.add('is-visible');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.16, rootMargin: '0px 0px -40px 0px' });

        targets.forEach(function (target) {
            observer.observe(target);
        });
    }

    function initShareButtons() {
        var buttons = document.querySelectorAll('.share-button');
        buttons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                var href = button.getAttribute('href');
                var target = button.getAttribute('target') || '_blank';
                if (!href) {
                    return;
                }

                event.preventDefault();
                if (window.gtag && button.dataset.gtag) {
                    window.gtag('event', button.dataset.gtag);
                }
                window.open(href, target, 'noopener');
            });
        });
    }

    function initMobileDownloadDock() {
        var dock = document.getElementById('levitateBtn');
        if (!dock) {
            return;
        }

        var updateDock = function () {
            var shouldShow = window.innerWidth < 900 && window.scrollY > window.innerHeight * 0.55;
            dock.style.display = shouldShow ? 'block' : 'none';
        };

        window.addEventListener('scroll', updateDock, { passive: true });
        window.addEventListener('resize', updateDock);
        updateDock();
    }

    onReady(function () {
        document.documentElement.id = document.documentElement.id || 'top';
        addBackToTop();
        initReveals();
        initShareButtons();
        initMobileDownloadDock();
    });
}());
