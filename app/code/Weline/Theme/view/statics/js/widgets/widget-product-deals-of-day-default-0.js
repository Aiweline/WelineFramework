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

    let tickId = 0;

    function setUnitText(el, value) {
        if (!el) {
            return;
        }
        const next = String(value).padStart(2, '0');
        if (el.textContent !== next) {
            el.textContent = next;
        }
    }

    function stopCountdownTick() {
        if (tickId) {
            clearInterval(tickId);
            tickId = 0;
        }
    }

    function updateCountdown() {
        const now = Date.now();
        const diff = endDate - now;

        if (diff <= 0) {
            // Expired: hide once and stop the 1Hz timer. Re-assigning
            // `hidden=true` every second still notifies MutationObservers and
            // can blank Chrome DevTools Elements on large storefront DOMs.
            if (!countdown.hidden) {
                countdown.hidden = true;
            }
            stopCountdownTick();
            return;
        }

        if (countdown.hidden) {
            countdown.hidden = false;
        }

        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

        setUnitText(countdown.querySelector('[data-unit="hours"]'), hours);
        setUnitText(countdown.querySelector('[data-unit="minutes"]'), minutes);
        setUnitText(countdown.querySelector('[data-unit="seconds"]'), seconds);
    }

    updateCountdown();
    if (!countdown.hidden) {
        tickId = setInterval(updateCountdown, 1000);
    }
})();
});
