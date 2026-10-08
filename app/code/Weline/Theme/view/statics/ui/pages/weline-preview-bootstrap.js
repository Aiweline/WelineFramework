/* Weline UI source: ui/js/pages/preview-bootstrap.js */
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
        // Ignore storage failures; path mount is authoritative.
    }
}

function clearClientToken() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch (error) {
        // Ignore storage failures.
    }
    // Drain leftover historical HttpOnly / document cookies (no longer carriers).
    try {
        document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        document.cookie = 'weline_preview_token=; path=/; max-age=0';
    } catch (error) {
        // Ignore.
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

/** Canvas markers that must never remain on a live storefront preview URL. */
const CANVAS_QUERY_KEYS = [
    'editor_mode',
    'shell',
    'editor_context',
    'theme_id',
    'frontend_theme_id',
    'backend_theme_id',
    'editor_area',
    'preview_area',
    'interaction_mode',
    'selection_target',
    'link_block',
    'visual_editor',
    'preview_theme',
    'preview_mode',
    'status',
    'version_id',
    'layout_type',
    'layout_option',
    'page_type',
];

function stripTokenFromUrl() {
    try {
        const url = new URL(window.location.href);
        let changed = false;
        if (url.searchParams.has(TOKEN_KEY)) {
            url.searchParams.delete(TOKEN_KEY);
            changed = true;
        }
        // Real preview is path-mount scoped — drop any leaked visual-editor identity.
        CANVAS_QUERY_KEYS.forEach(function(key) {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });
        if (!changed) {
            return false;
        }
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

function readPathMountToken() {
    try {
        const path = String(window.location.pathname || '');
        const match = path.match(/^\/~preview\/(pv_[A-Za-z0-9_-]{43}|pv_[1-9][0-9]{0,18}_[0-9]{9,12}_[a-f0-9]{16})(?:\/|$)/);
        return match && match[1] ? match[1] : '';
    } catch (error) {
        return '';
    }
}

async function bootstrapLivePreview() {
    // Path mount /~preview/{token}/… is the authoritative live-preview carrier.
    // Query token remains a one-shot compatibility bootstrap only (no Cookie).
    const pathToken = readPathMountToken();
    const urlToken = pathToken || readUrlToken();
    if (!urlToken) {
        if (readStoredToken()) {
            clearClientToken();
        }
        return;
    }

    storeClientToken(urlToken);
    // Session context hydrate (no Cookie write). Failures do not block path mount.
    await persistPreviewToken(urlToken);

    // Keep /~preview/{token}/… in the address bar — never collapse to formal paths.
    // Only strip leaked query canvas markers / legacy ?weline_preview_token=.
    stripTokenFromUrl();
    if (pathToken) {
        return;
    }
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
