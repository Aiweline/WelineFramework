/**
 * Theme editor chrome collapse / pin — additive overlay.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'weline.theme.editor.chrome.v1';

    function readState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return { collapsed: false, pinned: false };
            const parsed = JSON.parse(raw);
            return {
                collapsed: !!parsed.collapsed,
                pinned: !!parsed.pinned,
            };
        } catch (_e) {
            return { collapsed: false, pinned: false };
        }
    }

    function writeState(next) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch (_e) {
            /* ignore quota */
        }
    }

    function scopeLabel() {
        const scopeSelect = document.getElementById('scopeSelect');
        const text = String(scopeSelect?.textContent || scopeSelect?.value || '').replace(/\s+/g, ' ').trim();
        return text || (window.Weline?.Theme?.Editor?.state?.scopeIdentity
            ? String(window.Weline.Theme.Editor.state.scopeIdentity.website_code || 'default')
            : '默认');
    }

    function applyChrome(state) {
        const root = document.getElementById('themeEditor');
        const strip = document.getElementById('themeEditorChromeStrip');
        const pinBtn = document.getElementById('btnThemeChromePin');
        if (!root) return;
        const collapsed = !!state.collapsed && !state.pinned;
        root.classList.toggle('theme-editor-chrome-collapsed', collapsed);
        if (strip) {
            strip.hidden = !collapsed;
        }
        const label = document.getElementById('themeChromeStripScope');
        if (label) {
            label.textContent = scopeLabel();
        }
        if (pinBtn) {
            pinBtn.setAttribute('aria-pressed', state.pinned ? 'true' : 'false');
            pinBtn.dataset.tone = state.pinned ? 'warning' : 'neutral';
        }
    }

    function bind() {
        const root = document.getElementById('themeEditor');
        if (!root || root.dataset.chromeBound === '1') return;
        root.dataset.chromeBound = '1';
        let state = readState();
        if (state.pinned) state.collapsed = false;
        applyChrome(state);

        document.getElementById('btnThemeChromeCollapse')?.addEventListener('click', () => {
            state = { ...state, collapsed: true };
            writeState(state);
            applyChrome(state);
        });
        document.getElementById('btnThemeChromeExpand')?.addEventListener('click', () => {
            state = { ...state, collapsed: false };
            writeState(state);
            applyChrome(state);
        });
        document.getElementById('btnThemeChromePin')?.addEventListener('click', () => {
            state = { ...state, pinned: !state.pinned, collapsed: state.pinned ? state.collapsed : false };
            writeState(state);
            applyChrome(state);
        });
        document.getElementById('btnPublishStrip')?.addEventListener('click', () => {
            document.getElementById('btnPublish')?.click();
        });
        document.getElementById('scopeSelect')?.addEventListener('change', () => applyChrome(state));

        window.Weline = window.Weline || {};
        window.Weline.Theme = window.Weline.Theme || {};
        window.Weline.Theme.EditorChrome = {
            refresh() { applyChrome(readState()); },
            expand() {
                state = { ...readState(), collapsed: false };
                writeState(state);
                applyChrome(state);
            },
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
