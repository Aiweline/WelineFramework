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

function isPreviewCaptureDocument() {
    try {
        const params = new URLSearchParams(window.location.search);
        return params.get('weline_preview_capture') === '1' || params.get('preview_gen') === '1';
    } catch (error) {
        return false;
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
    // Persist only from an explicit URL token. sessionStorage must never alone
    // re-seed the HttpOnly cookie after exit (formal storefront would look "dirty").
    const urlToken = readUrlToken();
    if (!urlToken) {
        if (readStoredToken()) {
            clearClientToken();
        }
        return;
    }

    const persisted = await persistPreviewToken(urlToken);
    if (!persisted) {
        clearClientToken();
        return;
    }

    stripTokenFromUrl();
    // Capture must stay on the already painted document. A second navigation
    // keeps headless Chrome waiting for load, so the preview file never appears.
    if (!isPreviewCaptureDocument() && !document.getElementById('weline-preview-exit-float')) {
        window.location.replace(window.location.pathname + window.location.search + window.location.hash);
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
