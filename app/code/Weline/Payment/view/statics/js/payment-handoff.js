/**
 * 支付交接页轮询 (Weline_Payment).
 * Moved verbatim from the inline <script> in templates/frontend/checkout/handoff.phtml.
 * Registered as module `paymentHandoff`; page root declares data-weline-load.
 */
(function () {
    var root = document.querySelector('[data-testid="payment-handoff"]');
    if (!root) {
        return;
    }
    var sessionCode = root.getAttribute('data-checkout-session-code') || '';
    var maxWait = parseInt(root.getAttribute('data-handoff-max-wait-ms') || '15000', 10);
    if (!isFinite(maxWait) || maxWait <= 0) {
        maxWait = 15000;
    }
    var elapsed = 0;
    var intervals = [500, 1000, 2000];
    var intervalIndex = 0;
    var messageEl = document.getElementById('payment-handoff-message');
    var progressEl = root.querySelector('[data-testid="payment-handoff-progress"]');
    var progressBar = document.getElementById('payment-handoff-progress-bar');
    var syncStep = document.getElementById('payment-handoff-step-sync');
    var redirectStep = document.getElementById('payment-handoff-step-redirect');

    function setProgress(pct) {
        var value = Math.max(8, Math.min(100, Math.round(pct)));
        if (progressBar) {
            progressBar.style.width = value + '%';
        }
        if (progressEl) {
            progressEl.setAttribute('aria-valuenow', String(value));
        }
    }

    function markReady() {
        root.classList.add('is-ready');
        root.setAttribute('aria-busy', 'false');
        if (syncStep) {
            syncStep.classList.remove('is-active');
            syncStep.classList.add('is-done');
        }
        if (redirectStep) {
            redirectStep.classList.add('is-active');
        }
        setProgress(100);
    }

    function tickProgress() {
        var ratio = maxWait > 0 ? (elapsed / maxWait) : 0;
        setProgress(12 + ratio * 78);
    }

    function poll() {
        if (!sessionCode) {
            // 无会话时仍展示确认中界面，避免纯空白；进度缓慢推进到约 40%。
            setProgress(28);
            return;
        }
        var statusUrl = '/payment/handoff/status?checkout_session_code='
            + encodeURIComponent(sessionCode) + '&elapsed_ms=' + elapsed;
        var request = (window.Weline && window.Weline.Api && typeof window.Weline.Api.get === 'function')
            ? window.Weline.Api.get(statusUrl, { silent: true })
            : fetch(statusUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) { return response.json(); });
        request.then(function (payload) {
            if (messageEl && payload && payload.message) {
                messageEl.textContent = payload.message;
            }
            if (payload && payload.ready && payload.landing_url) {
                markReady();
                window.location.href = payload.landing_url;
                return;
            }
            if (elapsed >= maxWait && payload && payload.landing_url) {
                markReady();
                window.location.href = payload.landing_url;
                return;
            }
            var wait = (payload && payload.poll_after_ms) ? payload.poll_after_ms : (intervals[intervalIndex] || 2000);
            intervalIndex = Math.min(intervalIndex + 1, intervals.length - 1);
            elapsed += wait;
            tickProgress();
            if (elapsed < maxWait || !(payload && payload.landing_url)) {
                setTimeout(poll, wait);
            }
        }).catch(function () {
            setTimeout(poll, intervals[intervalIndex] || 2000);
        });
    }

    tickProgress();
    poll();
})();
