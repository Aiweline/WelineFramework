window.WelineWidgetAssets.register('theme-product-featured-products-default-0', function (widgetScript) {
(function() {
    const root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;
    
    const layout = (widgetScript.dataset.v1);
    
    if (layout === 'carousel') {
        const wrapper = root.querySelector('.products-grid');
        const prevBtn = root.querySelector('.carousel-prev');
        const nextBtn = root.querySelector('.carousel-next');
        
        if (!wrapper || !prevBtn || !nextBtn) return;
        
        const cards = wrapper.querySelectorAll('.product-card');
        const cardWidth = cards[0]?.offsetWidth || 0;
        const gap = 20;
        let currentPosition = 0;
        const maxPosition = Math.max(0, (cards.length - 4) * (cardWidth + gap));
        
        function updatePosition() {
            wrapper.style.transform = 'translateX(-' + currentPosition + 'px)';
        }
        
        prevBtn.addEventListener('click', function() {
            currentPosition = Math.max(0, currentPosition - (cardWidth + gap));
            updatePosition();
        });
        
        nextBtn.addEventListener('click', function() {
            currentPosition = Math.min(maxPosition, currentPosition + (cardWidth + gap));
            updatePosition();
        });
    }
})();
});
