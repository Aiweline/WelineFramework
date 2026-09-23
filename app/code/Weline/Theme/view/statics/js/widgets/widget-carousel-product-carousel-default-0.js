window.WelineWidgetAssets.register('theme-carousel-product-carousel-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    var track = root.querySelector('.carousel-track');
    var slides = root.querySelectorAll('.carousel-slide');
    var prevBtn = root.querySelector('.carousel-prev');
    var nextBtn = root.querySelector('.carousel-next');

    if (!track || !slides.length) return;

    var slidesPerView = parseInt(root.dataset.slidesPerView, 10) || 4;
    var autoplay = root.dataset.autoplay === 'true';
    var loop = root.dataset.loop === 'true';

    var currentIndex = 0;
    var viewport = root.querySelector('.carousel-viewport');
    var calculatedSlideWidth = 0;
    var maxIndex = 0;

    function measure() {
        var viewportWidth = viewport.offsetWidth;
        calculatedSlideWidth = (viewportWidth - (slidesPerView - 1) * 20) / slidesPerView;
        slides.forEach(function (slide) {
            slide.style.width = calculatedSlideWidth + 'px';
        });
        maxIndex = Math.max(0, slides.length - slidesPerView);
    }

    measure();
    window.addEventListener('resize', measure);

    function goToSlide(index) {
        if (loop) {
            currentIndex = ((index % slides.length) + slides.length) % slides.length;
            if (currentIndex > maxIndex) currentIndex = 0;
        } else {
            currentIndex = Math.max(0, Math.min(index, maxIndex));
        }

        var offset = currentIndex * (calculatedSlideWidth + 20);
        track.style.transform = 'translateX(-' + offset + 'px)';
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goToSlide(currentIndex - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goToSlide(currentIndex + 1); });

    var autoplayInterval;
    if (autoplay) {
        autoplayInterval = setInterval(function () { goToSlide(currentIndex + 1); }, 4000);
        root.addEventListener('mouseenter', function () { clearInterval(autoplayInterval); });
        root.addEventListener('mouseleave', function () {
            autoplayInterval = setInterval(function () { goToSlide(currentIndex + 1); }, 4000);
        });
    }
})();
});
