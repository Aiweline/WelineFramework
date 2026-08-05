/**
 * Queue wizard visual-state synchronizer.
 *
 * Queue form persistence is owned by the queue_admin QueryProvider from the
 * source template. This file intentionally contains no transport behavior.
 */
(function () {
    'use strict';

    function syncWizard(root) {
        var steps = Array.prototype.slice.call(root.querySelectorAll('.twitter-bs-wizard-nav .nav-link'));
        var activeIndex = steps.findIndex(function (step) {
            return step.classList.contains('active');
        });
        var progress = root.querySelector('.progress-bar');

        steps.forEach(function (step, index) {
            var active = index === activeIndex;
            step.setAttribute('aria-selected', active ? 'true' : 'false');
            step.setAttribute('tabindex', active ? '0' : '-1');
        });

        if (progress && activeIndex >= 0) {
            var percent = Math.round(((activeIndex + 1) / Math.max(steps.length, 1)) * 100);
            progress.style.width = percent + '%';
            progress.setAttribute('aria-valuenow', String(percent));
        }
    }

    function initWizard(root) {
        syncWizard(root);
        var navigation = root.querySelector('.twitter-bs-wizard-nav');
        if (!navigation || typeof MutationObserver === 'undefined') {
            return;
        }
        var observer = new MutationObserver(function () {
            syncWizard(root);
        });
        observer.observe(navigation, {
            attributes: true,
            attributeFilter: ['class'],
            subtree: true
        });
    }

    function init() {
        document.querySelectorAll('.twitter-bs-wizard').forEach(initWizard);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
