(function () {
    var ready = function (callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    };

    ready(function () {
        document.documentElement.classList.add('csd-ready');

        var backToTop = document.getElementById('backToTop');
        if (!backToTop) {
            backToTop = document.createElement('a');
            backToTop.id = 'backToTop';
            backToTop.className = 'back-top';
            backToTop.href = '#top';
            backToTop.setAttribute('aria-label', 'Back to top');
            document.body.appendChild(backToTop);
        }

        var toggleBackToTop = function () {
            if (window.scrollY > 600) {
                backToTop.style.display = 'block';
                return;
            }
            backToTop.style.display = 'none';
        };

        toggleBackToTop();
        window.addEventListener('scroll', toggleBackToTop, { passive: true });

        backToTop.addEventListener('click', function (event) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        document.querySelectorAll('.share-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var gtagData = button.getAttribute('data-gtag');
                if (gtagData && typeof window.gtag === 'function') {
                    window.gtag('event', gtagData);
                }
            });
        });
    });
}());
