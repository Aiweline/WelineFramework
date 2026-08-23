const root = document.querySelector('[data-w-offcanvas-result]');

if (root instanceof HTMLElement) {
    const parentWindow = window.parent;
    const type = root.dataset.resultType || 'info';
    const message = root.dataset.resultMessage || '';
    const destination = root.dataset.resultUrl || '';
    const shouldReload = root.dataset.resultReload === 'true';
    const countdownLabel = root.dataset.resultCountdownLabel || '%s seconds remaining';
    const isEmbedded = parentWindow !== window;
    const delay = Math.max(0, Number.parseInt(root.dataset.resultDelay || '0', 10) || 0);
    const countdown = root.querySelector('[data-result-countdown]');
    const finishButton = root.querySelector('[data-result-finish]');
    let remaining = delay;
    let timer = null;
    let finished = false;

    const closeOwningDrawer = () => {
        try {
            const frame = window.frameElement;
            const drawer = frame instanceof Element
                ? frame.closest('[data-w-component~="drawer"]')
                : null;
            if (drawer && parentWindow.Weline?.UI?.drawer) {
                parentWindow.Weline.UI.drawer.close(drawer, 'result');
            }
        } catch (_error) {
            // A cross-origin parent is outside the supported remote-drawer contract.
        }
    };

    const navigate = () => {
        if (shouldReload && isEmbedded) {
            parentWindow.location.reload();
            return;
        }
        if (destination === '') return;
        try {
            const target = new URL(destination, parentWindow.location.href);
            if (target.origin === parentWindow.location.origin) parentWindow.location.assign(target.href);
        } catch (_error) {
            // Invalid and cross-origin destinations are intentionally ignored.
        }
    };

    const finish = () => {
        if (finished) return;
        finished = true;
        if (timer !== null) window.clearTimeout(timer);
        closeOwningDrawer();
        navigate();
    };

    const updateCountdown = () => {
        if (countdown instanceof HTMLElement) {
            countdown.textContent = remaining > 0 ? countdownLabel.replace('%s', String(remaining)) : '';
        }
        if (remaining <= 0) {
            finish();
            return;
        }
        remaining -= 1;
        timer = window.setTimeout(updateCountdown, 1000);
    };

    try {
        parentWindow.Weline?.UI?.toast?.show(message, { tone: type === 'error' ? 'danger' : type });
    } catch (_error) {
        // A cross-origin parent is outside the supported remote-drawer contract.
    }
    finishButton?.addEventListener('click', finish, { once: true });
    if (isEmbedded || destination !== '') updateCountdown();
}
