(function (document) {
    'use strict';
    function fallback(image) {
        if (!image.matches('[data-widget-logo-image]')) return;
        image.classList.add('logo-image-failed');
        if (image.nextElementSibling) image.nextElementSibling.classList.add('logo-text-fallback');
    }
    document.addEventListener('error', function (event) { if (event.target.matches) fallback(event.target); }, true);
    document.querySelectorAll('[data-widget-logo-image]').forEach(function (image) {
        if (image.complete && !image.naturalWidth) fallback(image);
    });
})(document);
