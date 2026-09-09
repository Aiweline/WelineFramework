/* Weline UI source: js/maintenance.js */
/* Maintenance recovery probe + simple wait-gift: same-URL token only. */
const initialDelay = 5000;
const maximumDelay = 30000;
const hardReloadDelay = 30000;
const jitter = 1500;
let timer = 0;
let hardTimer = 0;

const WAIT_STORAGE_KEY = 'weline_mw_wait_gift';
const RECOVERY_CHECK_PATH = '/maintenance/frontend/recovery-check';
const endpoints = {
    issue: '/maintenance/frontend/wait-gift/issue',
    redeem: '/maintenance/frontend/wait-gift/redeem',
};

function waitGiftEnabled() {
    if (document.documentElement.getAttribute('data-w-wait-gift') !== '1') {
        return false;
    }
    // Retail ToC only; wholesale tob skips wait-gift on full maintenance page.
    try {
        if (window.WelineB2BSellingMode && typeof window.WelineB2BSellingMode.preferredMode === 'function') {
            return String(window.WelineB2BSellingMode.preferredMode() || 'toc').toLowerCase() !== 'tob';
        }
    } catch (e) {}
    try {
        const raw = String(document.cookie || '');
        if (/(?:^|; )\s*weline_selling_mode(?:_w\d+)?=tob(?:;|$)/i.test(raw)) {
            return false;
        }
    } catch (e) {}
    return true;
}

function scrubWholesaleGiftCopy() {
    if (waitGiftEnabled()) {
        return;
    }
    if (document.documentElement.getAttribute('data-w-wait-gift') !== '1') {
        return;
    }
    document.documentElement.setAttribute('data-w-wait-gift', '0');
    const status = document.querySelector('[data-w-maintenance-status]');
    if (status) {
        status.textContent = '正在尝试现场抢修，恢复后会自动进入，请耐心等待';
    }
    document.querySelectorAll('[data-w-mw-gift],[data-w-mw-gift-copy="1"]').forEach((el) => {
        if (el.getAttribute('data-w-mw-gift-copy') === '1') {
            el.textContent = '请稍等片刻';
            el.setAttribute('data-w-mw-gift-copy', '0');
            return;
        }
        el.remove();
    });
}

function pageKey(href) {
    try {
        const url = new URL(href || window.location.href);
        return url.pathname + url.search;
    } catch (e) {
        return String(href || '');
    }
}

function readWaitGift() {
    try {
        const raw = sessionStorage.getItem(WAIT_STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const data = JSON.parse(raw);
        if (!data || !data.token || !data.url) {
            return null;
        }
        return { token: String(data.token), url: String(data.url) };
    } catch (e) {
        return null;
    }
}

function storeWaitGift(token) {
    const value = String(token || '');
    try {
        if (!value) {
            sessionStorage.removeItem(WAIT_STORAGE_KEY);
            return;
        }
        sessionStorage.setItem(WAIT_STORAGE_KEY, JSON.stringify({
            token: value,
            url: pageKey(),
        }));
    } catch (e) {
        // ignore
    }
}

function clearWaitGift() {
    storeWaitGift('');
}

function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body || {}),
    }).then(async (response) => {
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data: data || {} };
    });
}

function issueWaitToken() {
    if (!waitGiftEnabled()) {
        return Promise.resolve();
    }
    const existing = readWaitGift();
    const body = existing && existing.url === pageKey() ? { token: existing.token } : {};
    body.selling_mode = 'toc';
    return postJson(endpoints.issue, body).then((result) => {
        if (result.data && result.data.success && result.data.token) {
            storeWaitGift(result.data.token);
        }
    }).catch(() => undefined);
}

function probeUrl() {
    // Never probe the document URL: full HTML/HEAD under load starves workers.
    const url = new URL(RECOVERY_CHECK_PATH, window.location.origin);
    url.searchParams.set('_maintenance_recovery_probe', String(Date.now()));
    return url;
}

function schedule(delay) {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        timer = 0;
        check();
    }, delay + Math.floor(Math.random() * jitter));
}

function scheduleHardReload() {
    window.clearTimeout(hardTimer);
    hardTimer = window.setTimeout(() => {
        hardTimer = 0;
        if (!document.hidden) {
            window.location.reload();
            return;
        }
        scheduleHardReload();
    }, hardReloadDelay);
}

function probe(method) {
    return fetch(probeUrl(), {
        method,
        cache: 'no-store',
        credentials: 'same-origin',
        redirect: 'manual',
        headers: {
            Accept: 'text/plain,*/*;q=0.8',
            'X-Maintenance-Recovery-Check': '1',
        },
    });
}

function isRecovered(response) {
    if (response.type === 'opaqueredirect') {
        return true;
    }
    const status = response.status;
    if (status === 503) {
        return false;
    }
    if (status === 0 || status === 500 || status === 502 || status === 504) {
        return false;
    }
    return true;
}

function handle(response) {
    if (isRecovered(response)) {
        // Same document URL reload; storefront JS will redeem if token+url still match.
        window.location.reload();
        return;
    }
    schedule(response.status === 503 ? initialDelay : maximumDelay);
}

function check() {
    if (document.hidden) {
        return;
    }
    probe('HEAD')
        .then((response) => ([405, 501].includes(response.status) ? probe('GET') : response))
        .then(handle)
        .catch(() => schedule(maximumDelay));
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        window.clearTimeout(timer);
        timer = 0;
        window.clearTimeout(hardTimer);
        hardTimer = 0;
        return;
    }
    schedule(0);
    scheduleHardReload();
});

issueWaitToken();
scrubWholesaleGiftCopy();
schedule(initialDelay);
scheduleHardReload();
