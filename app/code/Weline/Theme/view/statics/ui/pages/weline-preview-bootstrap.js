/* Weline UI source: ui/js/pages/preview-bootstrap.js */
const TOKEN_KEY = 'weline_preview_token';
const STORAGE_KEY = 'weline_live_preview_token';

function readUrlToken() {
    try {
        return new URLSearchParams(window.location.search).get(TOKEN_KEY) || '';
    } catch (error) {
        return '';
    }
}

function readStoredToken() {
    try {
        return sessionStorage.getItem(STORAGE_KEY) || '';
    } catch (error) {
        return '';
    }
}

function storeClientToken(token) {
    if (!token) {
        return;
    }

    try {
        sessionStorage.setItem(STORAGE_KEY, token);
    } catch (error) {
        // Ignore storage failures; HttpOnly cookie remains authoritative.
    }
}

function clearClientToken() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch (error) {
        // Ignore storage failures.
    }
}

function stripTokenFromUrl() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has(TOKEN_KEY)) {
            return false;
        }
        url.searchParams.delete(TOKEN_KEY);
        history.replaceState(null, '', url.pathname + url.search + url.hash);
        return true;
    } catch (error) {
        return false;
    }
}

function bootstrapEndpoint() {
    const root = document.documentElement;
    const configured = String(root.dataset.wPreviewBootstrapUrl || '').trim();
    if (configured) {
        return configured;
    }

    return '/theme/frontend/theme-preview/bootstrap';
}

async function persistPreviewToken(token) {
    if (!token) {
        return false;
    }

    storeClientToken(token);

    const body = new URLSearchParams();
    body.set(TOKEN_KEY, token);

    try {
        const response = await fetch(bootstrapEndpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            },
            body: body.toString(),
        });

        if (!response.ok) {
            return false;
        }

        const payload = await response.json().catch(() => ({}));
        return payload?.success === true;
    } catch (error) {
        return false;
    }
}

async function bootstrapLivePreview() {
    const urlToken = readUrlToken();
    const storedToken = readStoredToken();
    const token = urlToken || storedToken;
    if (!token) {
        return;
    }

    const persisted = await persistPreviewToken(token);
    if (!persisted) {
        // URL / sessionStorage 任一来源失效都清掉客户端态，避免退出后被 bootstrap 重新种回 Cookie
        clearClientToken();
        return;
    }

    if (urlToken) {
        stripTokenFromUrl();
        if (!document.getElementById('weline-preview-exit-float')) {
            window.location.replace(window.location.pathname + window.location.search + window.location.hash);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapLivePreview, { once: true });
} else {
    bootstrapLivePreview();
}

window.WelineThemePreviewBootstrap = {
    clearClientToken,
    persistPreviewToken,
    readStoredToken,
    readUrlToken,
    stripTokenFromUrl,
};
