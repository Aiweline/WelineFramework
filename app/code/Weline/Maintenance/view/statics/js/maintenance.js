const initialDelay = 5000;
const maximumDelay = 30000;
const jitter = 1500;
let timer = 0;

function probeUrl() {
    const url = new URL(window.location.href);
    if (/^\/pub\/errors\/maintenance\//.test(url.pathname)) url.pathname = '/';
    url.hash = '';
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

function probe(method) {
    return fetch(probeUrl(), {
        method,
        cache: 'no-store',
        credentials: 'same-origin',
        redirect: 'follow',
        headers: {
            Accept: 'text/html,application/xhtml+xml,*/*;q=0.8',
            'X-Maintenance-Recovery-Check': '1',
        },
    });
}

function handle(response) {
    if (response.status === 200) {
        window.location.reload();
        return;
    }
    schedule(response.status === 503 ? initialDelay : maximumDelay);
}

function check() {
    if (document.hidden) return;
    probe('HEAD')
        .then((response) => ([405, 501].includes(response.status) ? probe('GET') : response))
        .then(handle)
        .catch(() => schedule(maximumDelay));
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        window.clearTimeout(timer);
        timer = 0;
        return;
    }
    schedule(0);
});

schedule(initialDelay);
