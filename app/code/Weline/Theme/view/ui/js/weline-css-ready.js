(function welineCssReadyGate() {
    const root = document.documentElement;
    if (root.dataset.welineCss !== 'pending') {
        root.dataset.welineCss = 'pending';
    }

    const markReady = () => {
        root.dataset.welineCss = 'ready';
    };

    const failOpenMs = 1800;
    let failOpenTimer = setTimeout(markReady, failOpenMs);

    const clearFailOpen = () => {
        if (failOpenTimer !== null) {
            clearTimeout(failOpenTimer);
            failOpenTimer = null;
        }
    };

    const finish = () => {
        clearFailOpen();
        markReady();
    };

    const links = document.querySelectorAll('link[data-weline-layout-css]');
    if (links.length === 0) {
        finish();
        return;
    }

    let pending = links.length;
    const done = () => {
        pending -= 1;
        if (pending <= 0) {
            finish();
        }
    };

    links.forEach((link) => {
        if (link.rel && link.rel !== 'stylesheet') {
            done();
            return;
        }
        try {
            if (link.sheet) {
                done();
                return;
            }
        } catch (_error) {
        }
        link.addEventListener('load', done, { once: true });
        link.addEventListener('error', done, { once: true });
    });
})();
