window.WelineWidgetAssets.register('theme-product-deals-of-day-default-0', function (widgetScript) {
(function() {
    const root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;
    
    const countdown = root.querySelector('.header-countdown');
    if (!countdown) return;

    const endRaw = (countdown.dataset.end || '').trim();
    const endDate = endRaw ? new Date(endRaw).getTime() : NaN;
    if (!Number.isFinite(endDate)) {
        countdown.hidden = true;
        return;
    }

    function updateCountdown() {
        const now = Date.now();
        const diff = endDate - now;

        if (diff <= 0) {
            countdown.hidden = true;
            return;
        }
        
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        const hoursEl = countdown.querySelector('[data-unit="hours"]');
        const minutesEl = countdown.querySelector('[data-unit="minutes"]');
        const secondsEl = countdown.querySelector('[data-unit="seconds"]');
        
        if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
        if (minutesEl) minutesEl.textContent = String(minutes).padStart(2, '0');
        if (secondsEl) secondsEl.textContent = String(seconds).padStart(2, '0');
    }
    
    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
});
