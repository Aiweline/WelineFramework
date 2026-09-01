function initSystemConfigFilter() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    const SEARCH_STORAGE_KEY = 'weline.system-config.search.v1';
    const filterForm = document.getElementById('wsc-filter-form');
    const searchRoot = document.getElementById('wsc-search-root')
        || root.querySelector('[data-w-system-config-search]')
        || root.querySelector('.w-system-config__filter-search .w-search-bar');
    const searchInput = document.getElementById('wsc-search-input')
        || searchRoot?.querySelector('[data-w-system-config-search-input]')
        || searchRoot?.querySelector('.w-search-bar__input')
        || filterForm?.querySelector('.w-system-config__filter-search .w-search-bar__input');
    const searchTrigger = searchRoot?.querySelector('[data-w-system-config-search-trigger]')
        || searchRoot?.querySelector('.w-search-bar__submit');
    const searchClear = searchRoot?.querySelector('[data-w-system-config-search-clear]');
    const searchEmpty = document.getElementById('wsc-search-empty');
    const statTemplates = root.querySelector('.w-system-config__stats .w-system-config__stat-tile:nth-child(1) .w-system-config__stat-value');
    const statFields = root.querySelector('.w-system-config__stats .w-system-config__stat-tile:nth-child(2) .w-system-config__stat-value');
    const statAdapters = root.querySelector('.w-system-config__stats .w-system-config__stat-tile:nth-child(3) .w-system-config__stat-value');
    let searchTimer = 0;
    const guideProtectKeys = (() => {
        const node = document.getElementById('w-system-config-guide-config');
        if (!(node instanceof HTMLScriptElement)) {
            return new Set();
        }
        try {
            const config = JSON.parse(node.textContent || '{}');
            const keys = Array.isArray(config.guideKeys) ? config.guideKeys : [];
            return new Set(keys.map((key) => String(key || '').trim()).filter(Boolean));
        } catch (_error) {
            return new Set();
        }
    })();

    function isGuideProtected(element) {
        if (!(element instanceof HTMLElement) || guideProtectKeys.size === 0) {
            return false;
        }
        const ownKey = element.getAttribute('data-guide-key');
        if (ownKey && guideProtectKeys.has(ownKey)) {
            return true;
        }
        return [...element.querySelectorAll('[data-guide-key]')].some((child) => {
            const key = child.getAttribute('data-guide-key');
            return key !== null && guideProtectKeys.has(key);
        });
    }

    function normalizeQuery(value) {
        return String(value || '').trim().toLowerCase();
    }

    function haystackFrom(element) {
        if (!(element instanceof HTMLElement)) {
            return '';
        }
        const explicit = element.getAttribute('data-search-text');
        if (explicit === null) {
            return '';
        }
        return explicit.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function setHidden(element, hidden) {
        if (!(element instanceof HTMLElement)) {
            return;
        }
        if (hidden && isGuideProtected(element)) {
            hidden = false;
        }
        element.hidden = hidden;
        element.classList.toggle('is-search-hidden', hidden);
    }

    function openDisclosure(disclosure) {
        if (!(disclosure instanceof HTMLElement)) {
            return;
        }
        const trigger = disclosure.querySelector('[data-w-disclosure-trigger]');
        const panel = disclosure.querySelector('[data-w-disclosure-panel]');
        if (!(trigger instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
            return;
        }
        if (panel.hidden) {
            trigger.setAttribute('aria-expanded', 'true');
            panel.hidden = false;
            disclosure.dataset.state = 'open';
        }
    }

    function updateClearButton(query) {
        if (!(searchClear instanceof HTMLButtonElement)) {
            return;
        }
        const hasQuery = normalizeQuery(query) !== '';
        searchClear.hidden = !hasQuery;
    }

    function persistSearch(query) {
        const normalized = String(query || '').trim();
        try {
            if (normalized === '') {
                sessionStorage.removeItem(SEARCH_STORAGE_KEY);
            } else {
                sessionStorage.setItem(SEARCH_STORAGE_KEY, normalized);
            }
        } catch (_error) {
            // ignore
        }
    }

    function restoreSearch() {
        try {
            return String(sessionStorage.getItem(SEARCH_STORAGE_KEY) || '').trim();
        } catch (_error) {
            return '';
        }
    }

    function filterGroup(groupEl, q) {
        const groupHay = haystackFrom(groupEl);
        let groupVisible = !q || groupHay.includes(q);
        let visibleFieldCount = 0;
        let visibleAdapterCount = 0;

        groupEl.querySelectorAll('[data-testid="system-config-field"]').forEach((row) => {
            const rowHay = haystackFrom(row);
            const rowVisible = !q || rowHay.includes(q);
            setHidden(row, !rowVisible);
            if (rowVisible) {
                groupVisible = true;
                visibleFieldCount += 1;
            }
        });

        groupEl.querySelectorAll('.w-system-config__adapter').forEach((adapterEl) => {
            const adapterVisible = !q || haystackFrom(adapterEl).includes(q);
            setHidden(adapterEl, !adapterVisible);
            if (adapterVisible) {
                groupVisible = true;
                visibleAdapterCount += 1;
            }
        });

        setHidden(groupEl, !groupVisible);
        if (groupVisible && q) {
            openDisclosure(groupEl);
        }

        return {
            groupVisible,
            visibleFieldCount,
            visibleAdapterCount,
        };
    }

    function filterTemplate(templateEl, q) {
        const templateHay = haystackFrom(templateEl);
        let templateVisible = !q;
        let visibleFieldCount = 0;
        let visibleAdapterCount = 0;

        if (q && templateHay.includes(q)) {
            templateVisible = true;
        }

        templateEl.querySelectorAll('.w-system-config__group-disclosure').forEach((groupEl) => {
            const groupResult = filterGroup(groupEl, q);
            if (groupResult.groupVisible) {
                templateVisible = true;
                visibleFieldCount += groupResult.visibleFieldCount;
                visibleAdapterCount += groupResult.visibleAdapterCount;
            }
        });

        if (q && templateEl.querySelectorAll('.w-system-config__group-disclosure').length === 0) {
            templateEl.querySelectorAll('[data-testid="system-config-field"]').forEach((row) => {
                const rowVisible = haystackFrom(row).includes(q);
                setHidden(row, !rowVisible);
                if (rowVisible) {
                    templateVisible = true;
                    visibleFieldCount += 1;
                }
            });

            templateEl.querySelectorAll('.w-system-config__adapter').forEach((adapterEl) => {
                const adapterVisible = haystackFrom(adapterEl).includes(q);
                setHidden(adapterEl, !adapterVisible);
                if (adapterVisible) {
                    templateVisible = true;
                    visibleAdapterCount += 1;
                }
            });
        }

        setHidden(templateEl, !templateVisible);

        return {
            templateVisible,
            visibleFieldCount: q ? visibleFieldCount : countVisible(templateEl, '[data-testid="system-config-field"]'),
            visibleAdapterCount: q ? visibleAdapterCount : countVisible(templateEl, '.w-system-config__adapter'),
        };
    }

    function countVisible(scope, selector) {
        if (!(scope instanceof HTMLElement)) {
            return 0;
        }
        return scope.querySelectorAll(selector).length;
    }

    function applySearch(query) {
        const q = normalizeQuery(query);
        let visibleModules = 0;
        let visibleTemplates = 0;
        let visibleFields = 0;
        let visibleAdapters = 0;

        root.querySelectorAll('.w-system-config__module').forEach((moduleEl) => {
            let moduleVisible = false;
            const moduleHay = haystackFrom(moduleEl);
            let moduleTemplateCount = 0;
            let moduleFieldCount = 0;
            let moduleAdapterCount = 0;

            if (!q || moduleHay.includes(q)) {
                moduleEl.querySelectorAll('.w-system-config__template').forEach((templateEl) => {
                    const templateResult = filterTemplate(templateEl, q);
                    if (templateResult.templateVisible) {
                        moduleVisible = true;
                        moduleTemplateCount += 1;
                        moduleFieldCount += templateResult.visibleFieldCount;
                        moduleAdapterCount += templateResult.visibleAdapterCount;
                    }
                });
            } else {
                moduleEl.querySelectorAll('.w-system-config__template').forEach((templateEl) => {
                    const templateResult = filterTemplate(templateEl, q);
                    if (templateResult.templateVisible) {
                        moduleVisible = true;
                        moduleTemplateCount += 1;
                        moduleFieldCount += templateResult.visibleFieldCount;
                        moduleAdapterCount += templateResult.visibleAdapterCount;
                    }
                });
            }

            setHidden(moduleEl, !moduleVisible);
            if (moduleVisible) {
                visibleModules += 1;
                visibleTemplates += moduleTemplateCount;
                visibleFields += moduleFieldCount;
                visibleAdapters += moduleAdapterCount;
            }
        });

        if (searchEmpty instanceof HTMLElement) {
            searchEmpty.hidden = q === '' || visibleModules > 0;
        }

        if (statTemplates instanceof HTMLElement) {
            statTemplates.textContent = q === '' ? (statTemplates.dataset.total || statTemplates.textContent || '0') : String(visibleTemplates);
        }
        if (statFields instanceof HTMLElement) {
            statFields.textContent = q === '' ? (statFields.dataset.total || statFields.textContent || '0') : String(visibleFields);
        }
        if (statAdapters instanceof HTMLElement) {
            statAdapters.textContent = q === '' ? (statAdapters.dataset.total || statAdapters.textContent || '0') : String(visibleAdapters);
        }

        updateClearButton(query);
    }

    function captureStatTotals() {
        if (statTemplates instanceof HTMLElement && !statTemplates.dataset.total) {
            statTemplates.dataset.total = statTemplates.textContent || '0';
        }
        if (statFields instanceof HTMLElement && !statFields.dataset.total) {
            statFields.dataset.total = statFields.textContent || '0';
        }
        if (statAdapters instanceof HTMLElement && !statAdapters.dataset.total) {
            statAdapters.dataset.total = statAdapters.textContent || '0';
        }
    }

    function scheduleSearch() {
        if (!(searchInput instanceof HTMLInputElement)) {
            return;
        }
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            persistSearch(searchInput.value);
            applySearch(searchInput.value);
        }, 180);
    }

    function clearSearch() {
        if (!(searchInput instanceof HTMLInputElement)) {
            return;
        }
        searchInput.value = '';
        persistSearch('');
        applySearch('');
        searchInput.focus();
    }

    function sanitizeLocationSearchParams() {
        try {
            const url = new URL(window.location.href);
            let changed = false;

            if (url.searchParams.has('search')) {
                url.searchParams.delete('search');
                changed = true;
            }

            ['website_code', 'store_code', 'channel_code', 'locale', 'module', 'area'].forEach((name) => {
                if (!url.searchParams.has(name)) {
                    return;
                }
                if (String(url.searchParams.get(name) || '').trim() === '') {
                    url.searchParams.delete(name);
                    changed = true;
                }
            });

            if (changed) {
                window.history.replaceState({}, '', url.toString());
            }
        } catch (_error) {
            // ignore
        }
    }

    function buildFilterUrl(form) {
        const url = new URL(window.location.href);
        url.search = '';
        const data = new FormData(form);
        data.forEach((value, key) => {
            const normalized = String(value ?? '').trim();
            if (normalized === '' || key === 'search') {
                return;
            }
            url.searchParams.set(key, normalized);
        });
        return url;
    }

    captureStatTotals();

    if (filterForm instanceof HTMLFormElement) {
        filterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (searchInput instanceof HTMLInputElement) {
                persistSearch(searchInput.value);
            }
            window.location.assign(buildFilterUrl(filterForm).toString());
        });
    }

    if (searchInput instanceof HTMLInputElement) {
        searchInput.addEventListener('input', scheduleSearch);
        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(searchTimer);
                persistSearch(searchInput.value);
                applySearch(searchInput.value);
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                clearSearch();
            }
        });
    } else {
        console.warn('[w-system-config] search input not found; client-side filter disabled');
    }

    if (searchTrigger instanceof HTMLButtonElement) {
        searchTrigger.addEventListener('click', (event) => {
            event.preventDefault();
            if (searchInput instanceof HTMLInputElement) {
                persistSearch(searchInput.value);
                applySearch(searchInput.value);
            }
        });
    }

    if (searchClear instanceof HTMLButtonElement) {
        searchClear.addEventListener('click', (event) => {
            event.preventDefault();
            clearSearch();
        });
    }

    sanitizeLocationSearchParams();

    if (searchInput instanceof HTMLInputElement) {
        const restored = restoreSearch();
        if (restored !== '') {
            searchInput.value = restored;
        }
        if (searchInput.value.trim() !== '') {
            applySearch(searchInput.value);
        } else {
            updateClearButton('');
        }
    }

    window.setTimeout(() => {
        document.dispatchEvent(new CustomEvent('w-system-config:filter-ready', { bubbles: true }));
    }, 0);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigFilter, { once: true });
} else {
    initSystemConfigFilter();
}

function initSystemConfigInherit() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    function syncRow(toggle) {
        if (!(toggle instanceof HTMLInputElement)) {
            return;
        }
        const row = toggle.closest('[data-testid="system-config-field"]');
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const inherited = toggle.checked;
        row.classList.toggle('is-inherited', inherited);
        if (row.classList.contains('is-locked') || row.classList.contains('is-scope-readonly')) {
            return;
        }
        row.querySelectorAll('.w-system-config__field-control :is(input, select, textarea)').forEach((control) => {
            if (!(control instanceof HTMLElement)) {
                return;
            }
            if (inherited) {
                control.setAttribute('disabled', 'disabled');
            } else {
                control.removeAttribute('disabled');
            }
        });
    }

    root.querySelectorAll('[data-w-system-config-inherit-toggle]').forEach((toggle) => {
        if (!(toggle instanceof HTMLInputElement) || toggle.disabled) {
            return;
        }
        syncRow(toggle);
        toggle.addEventListener('change', () => syncRow(toggle));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigInherit, { once: true });
} else {
    initSystemConfigInherit();
}

function initSystemConfigCopy() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }
    if (root.dataset.wSystemConfigCopyBound === '1') {
        return;
    }
    root.dataset.wSystemConfigCopyBound = '1';

    const copiedLabel = '已复制';
    const defaultLabel = '复制';

    async function copyText(text, input) {
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_error) {
            // fall through to execCommand
        }
        if (!(input instanceof HTMLInputElement)) {
            return false;
        }
        const previousReadOnly = input.readOnly;
        input.readOnly = false;
        input.focus();
        input.select();
        input.setSelectionRange(0, input.value.length);
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (_error) {
            ok = false;
        }
        input.readOnly = previousReadOnly;
        return ok;
    }

    function flashButton(button, ok) {
        const label = button.querySelector('[data-w-system-config-copy-label]') || button;
        const original = String(label.textContent || defaultLabel).trim() || defaultLabel;
        label.textContent = ok ? copiedLabel : '复制失败';
        button.setAttribute('data-tone', ok ? 'success' : 'danger');
        window.setTimeout(() => {
            label.textContent = original;
            button.setAttribute('data-tone', 'neutral');
        }, 1600);
    }

    root.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const button = target.closest('[data-w-system-config-copy]');
        if (!(button instanceof HTMLElement) || !root.contains(button)) {
            return;
        }
        const text = String(button.getAttribute('data-w-system-config-copy') || '').trim();
        if (text === '') {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const block = button.closest('.w-system-config__adapter-callback');
        const input = block instanceof HTMLElement
            ? block.querySelector('[data-testid="system-config-adapter-callback-url"]')
            : null;

        void (async () => {
            const ok = await copyText(text, input instanceof HTMLInputElement ? input : null);
            flashButton(button, ok);
            if (!ok) {
                window.prompt('请手动复制 Return URL：', text);
            }
        })();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigCopy, { once: true });
} else {
    initSystemConfigCopy();
}
