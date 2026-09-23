window.WelineWidgetAssets.register('theme-content-countdown-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    var endDate = new Date(root.dataset.end).getTime();
    if (!Number.isFinite(endDate)) return;

    var timer = root.querySelector('.countdown-timer');
    var expiredEl = root.querySelector('.countdown-expired');

    function updateCountdown() {
        var diff = endDate - Date.now();

        if (diff <= 0) {
            if (timer) timer.classList.add('is-hidden');
            if (expiredEl) expiredEl.classList.remove('is-hidden');
            return;
        }

        var days = Math.floor(diff / 86400000);
        var hours = Math.floor((diff % 86400000) / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);

        var map = { days: days, hours: hours, minutes: minutes, seconds: seconds };
        Object.keys(map).forEach(function (unit) {
            var el = root.querySelector('[data-unit="' + unit + '"]');
            if (el) el.textContent = String(map[unit]).padStart(2, '0');
        });
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
});
