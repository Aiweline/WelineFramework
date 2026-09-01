/* Weline UI source: js/system-config-filter.js */
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

/* Weline UI source: js/system-config-guide.js */
const root = document.querySelector('[data-w-system-config]');
const configNode = document.getElementById('w-system-config-guide-config');

if (root instanceof HTMLElement && configNode instanceof HTMLScriptElement) {
    let config = {};
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (_error) {
        config = {};
    }

    const guideKeys = Array.isArray(config.guideKeys) ? config.guideKeys : [];
    if (guideKeys.length === 0) {
        // No guide keys: nothing to initialize.
    } else {
        const initialLocate = decodeGuideValue(String(config.initialLocate || ''));
        const notFoundMsg = String(config.notFoundMsg || '');
        const currentPrefix = String(config.currentPrefix || '');
        const modeSamePage = String(config.modeSamePage || '');
        const modeJump = String(config.modeJump || '');
        const modeMissing = String(config.modeMissing || '');
        const badgeCurrent = String(config.badgeCurrent || '');
        const badgeTarget = String(config.badgeTarget || '');
        const collapseLabel = String(config.collapseLabel || '');
        const expandLabel = String(config.expandLabel || '');
        const guideParamNames = ['guide_key', 'guide_keys', 'guide_title', 'guide_summary', 'guide_locate', 'guide_step', 'guide_return'];
        let currentKey = initialLocate || '';

        function decodeGuideValue(value) {
            let cur = String(value || '').trim();
            if (!cur) {
                return '';
            }
            for (let i = 0; i < 5; i++) {
                if (!/%[0-9A-Fa-f]{2}/.test(cur)) {
                    break;
                }
                try {
                    const next = decodeURIComponent(cur.replace(/\+/g, '%20'));
                    if (next === cur) {
                        break;
                    }
                    cur = next;
                } catch (_e) {
                    break;
                }
            }
            return cur.trim();
        }

        function findTarget(key) {
            key = decodeGuideValue(key);
            if (!key) {
                return null;
            }
            const rows = document.querySelectorAll('[data-guide-key]');
            for (let i = 0; i < rows.length; i++) {
                if (decodeGuideValue(rows[i].getAttribute('data-guide-key') || '') === key) {
                    return rows[i];
                }
            }
            return null;
        }

        function findTriggerByKey(key) {
            key = decodeGuideValue(key);
            const preferred = document.querySelectorAll('.w-system-config__guide-float-item[data-wsc-guide-locate-key]');
            for (let i = 0; i < preferred.length; i++) {
                if (decodeGuideValue(preferred[i].getAttribute('data-wsc-guide-locate-key') || '') === key) {
                    return preferred[i];
                }
            }
            const buttons = document.querySelectorAll('[data-wsc-guide-locate-key]');
            for (let j = 0; j < buttons.length; j++) {
                if (decodeGuideValue(buttons[j].getAttribute('data-wsc-guide-locate-key') || '') === key) {
                    return buttons[j];
                }
            }
            return null;
        }

        function syncModeLabels() {
            document.querySelectorAll('[data-wsc-guide-locate-key]').forEach((btn) => {
                const key = decodeGuideValue(btn.getAttribute('data-wsc-guide-locate-key') || '');
                const mode = btn.querySelector('[data-wsc-guide-mode]');
                if (!mode) {
                    return;
                }
                if (findTarget(key)) {
                    mode.textContent = modeSamePage;
                    btn.classList.remove('is-missing');
                } else if (btn.getAttribute('data-wsc-guide-href')) {
                    mode.textContent = modeJump;
                } else {
                    mode.textContent = modeMissing;
                    btn.classList.add('is-missing');
                }
            });
        }

        function setCurrentUi(key, label) {
            currentKey = decodeGuideValue(key || '');
            document.querySelectorAll('.w-system-config__guide-float-item, .w-system-config__guide-chip').forEach((el) => {
                const itemKey = decodeGuideValue(el.getAttribute('data-wsc-guide-locate-key') || '');
                el.classList.toggle('is-current', itemKey !== '' && itemKey === currentKey);
            });
            document.querySelectorAll('tr.is-guide-target, .w-system-config__adapter.is-guide-target').forEach((row) => {
                const rowKey = decodeGuideValue(row.getAttribute('data-guide-key') || '');
                const isCurrent = rowKey !== '' && rowKey === currentKey;
                row.classList.toggle('is-focused', isCurrent);
                const badge = row.querySelector('[data-wsc-guide-badge]');
                if (badge) {
                    badge.classList.toggle('is-current', isCurrent);
                    badge.textContent = isCurrent ? badgeCurrent : badgeTarget;
                }
            });
            const currentLabelEl = document.querySelector('[data-wsc-guide-current-label]');
            if (currentLabelEl) {
                const text = label || key || '';
                currentLabelEl.textContent = String(currentPrefix).replace('__LABEL__', text);
                currentLabelEl.setAttribute('title', text);
            }
        }

        function sanitizeGuideSearchParams(url) {
            guideParamNames.forEach((name) => {
                if (!url.searchParams.has(name)) {
                    return;
                }
                const decoded = decodeGuideValue(url.searchParams.get(name) || '');
                if (decoded === '') {
                    url.searchParams.delete(name);
                } else {
                    url.searchParams.set(name, decoded);
                }
            });
            return url;
        }

        function replaceLocateInUrl(key) {
            try {
                const url = sanitizeGuideSearchParams(new URL(window.location.href));
                key = decodeGuideValue(key);
                if (key) {
                    url.searchParams.set('guide_locate', key);
                } else {
                    url.searchParams.delete('guide_locate');
                }
                window.history.replaceState({}, '', url.toString());
            } catch (_e) {
                // ignore
            }
        }

        function openDisclosureChain(element) {
            let node = element instanceof HTMLElement ? element : null;
            while (node && node !== root) {
                if (
                    node.classList.contains('w-system-config__group-disclosure')
                    || node.hasAttribute('data-w-system-config-filters')
                ) {
                    const disclosureTrigger = node.querySelector('[data-w-disclosure-trigger]');
                    const disclosurePanel = node.querySelector('[data-w-disclosure-panel]');
                    if (
                        disclosureTrigger instanceof HTMLElement
                        && disclosurePanel instanceof HTMLElement
                        && disclosurePanel.hidden
                    ) {
                        disclosureTrigger.setAttribute('aria-expanded', 'true');
                        disclosurePanel.hidden = false;
                        node.dataset.state = 'open';
                    }
                }
                node = node.parentElement;
            }
        }

        function revealGuidePath(target) {
            let node = target instanceof HTMLElement ? target : null;
            while (node && node !== root) {
                node.hidden = false;
                node.classList.remove('is-search-hidden');
                node = node.parentElement;
            }
            openDisclosureChain(target);
        }

        function stickyScrollOffset() {
            const sticky = root.querySelector('.w-system-config__filters-sticky');
            const stickyHeight = sticky instanceof HTMLElement ? sticky.getBoundingClientRect().height : 0;
            const topbarRaw = getComputedStyle(document.documentElement).getPropertyValue('--weline-backend-topbar-height');
            const topbarHeight = Number.parseFloat(topbarRaw) || 56;

            return topbarHeight + stickyHeight + 16;
        }

        function scrollGuideTargetIntoView(target) {
            if (!(target instanceof HTMLElement)) {
                return;
            }
            revealGuidePath(target);
            const scrollNow = () => {
                const offset = stickyScrollOffset();
                const rect = target.getBoundingClientRect();
                const absoluteTop = window.scrollY + rect.top - offset;
                window.scrollTo({
                    top: Math.max(0, absoluteTop),
                    behavior: 'auto',
                });
            };
            scrollNow();
            requestAnimationFrame(scrollNow);
        }

        function isTargetVisible(target) {
            if (!(target instanceof HTMLElement)) {
                return false;
            }
            const rect = target.getBoundingClientRect();
            const offset = stickyScrollOffset();
            return rect.top >= offset - 8 && rect.top <= window.innerHeight - 48;
        }

        function locateOnPage(key, requireVisible) {
            key = decodeGuideValue(key);
            const target = findTarget(key);
            if (!target) {
                return false;
            }
            const templateCard = target.closest('.w-system-config__template');
            if (templateCard instanceof HTMLElement) {
                templateCard.hidden = false;
                templateCard.classList.remove('is-search-hidden');
                templateCard.classList.add('is-guide-target');
            }
            const moduleCard = target.closest('.w-system-config__module');
            if (moduleCard instanceof HTMLElement) {
                moduleCard.hidden = false;
                moduleCard.classList.remove('is-search-hidden');
            }
            openDisclosureChain(target);
            let label = '';
            const floatBtn = findTriggerByKey(key);
            if (floatBtn) {
                label = floatBtn.getAttribute('data-wsc-guide-label') || '';
            }
            setCurrentUi(key, label);
            replaceLocateInUrl(key);
            scrollGuideTargetIntoView(target);
            window.setTimeout(() => {
                const control = target.querySelector('input:not([type="hidden"]), select, textarea, button, a.w-button');
                if (control && typeof control.focus === 'function') {
                    try {
                        control.focus({ preventScroll: true });
                    } catch (_e) {
                        control.focus();
                    }
                }
            }, 120);
            if (requireVisible) {
                return isTargetVisible(target);
            }

            return true;
        }

        function navigateToTarget(trigger, key) {
            key = decodeGuideValue(key);
            let href = (trigger && trigger.getAttribute)
                ? (trigger.getAttribute('data-wsc-guide-href') || '')
                : '';
            if (!href) {
                const fallback = findTriggerByKey(key);
                href = fallback ? (fallback.getAttribute('data-wsc-guide-href') || '') : '';
            }
            if (!href) {
                if (typeof Weline !== 'undefined' && Weline.UI?.toast?.warning) {
                    Weline.UI.toast.warning(notFoundMsg);
                }
                return false;
            }
            try {
                const url = sanitizeGuideSearchParams(new URL(href, window.location.href));
                if (key) {
                    url.searchParams.set('guide_locate', key);
                }
                window.location.assign(url.toString());
            } catch (_e) {
                window.location.href = href;
            }
            return true;
        }

        function locateKey(key, trigger) {
            key = decodeGuideValue(key);
            if (!key) {
                return false;
            }
            if (locateOnPage(key)) {
                return true;
            }
            return navigateToTarget(trigger || findTriggerByKey(key), key);
        }

        function autoLocateWithRetry(attempt) {
            if (!initialLocate) {
                return;
            }
            if (locateOnPage(initialLocate, true)) {
                return;
            }
            if (attempt < 16) {
                window.setTimeout(() => {
                    autoLocateWithRetry(attempt + 1);
                }, 120 + attempt * 100);
            }
        }

        function scheduleAutoLocate() {
            const run = () => {
                autoLocateWithRetry(0);
            };
            document.addEventListener('w-system-config:filter-ready', run);
            window.addEventListener('load', run, { once: true });
            run();
            window.setTimeout(run, 320);
            window.setTimeout(run, 900);
        }

        const floatPanel = document.getElementById('wsc-guide-float');
        const floatToggle = floatPanel
            ? floatPanel.querySelector('[data-wsc-guide-float-toggle]')
            : null;
        const floatStorageKey = 'wsc-guide-float-collapsed';

        function setFloatCollapsed(collapsed) {
            if (!floatPanel || !floatToggle) {
                return;
            }
            floatPanel.classList.toggle('is-collapsed', !!collapsed);
            floatToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            floatToggle.setAttribute('title', collapsed ? expandLabel : collapseLabel);
            const icon = floatToggle.querySelector('w-icon, i, .w-icon');
            if (icon instanceof Element) {
                const nextIcon = collapsed ? 'chevron-left' : 'chevron-right';
                if (icon.tagName === 'W-ICON') {
                    icon.setAttribute('name', nextIcon);
                } else if (icon instanceof SVGElement) {
                    icon.setAttribute('class', nextIcon);
                } else if (icon instanceof HTMLElement) {
                    icon.className = nextIcon;
                }
            }
            try {
                window.sessionStorage.setItem(floatStorageKey, collapsed ? '1' : '0');
            } catch (_e) {
                // ignore
            }
        }

        function readFloatCollapsed() {
            try {
                return window.sessionStorage.getItem(floatStorageKey) === '1';
            } catch (_e) {
                return false;
            }
        }

        setFloatCollapsed(readFloatCollapsed());
        syncModeLabels();

        try {
            const cleanUrl = sanitizeGuideSearchParams(new URL(window.location.href));
            if (cleanUrl.toString() !== window.location.href) {
                window.history.replaceState({}, '', cleanUrl.toString());
            }
        } catch (_e) {
            // ignore
        }

        scheduleAutoLocate();

        document.addEventListener('click', (event) => {
            if (!event.target || !event.target.closest) {
                return;
            }

            const toggle = event.target.closest('[data-wsc-guide-float-toggle]');
            if (toggle) {
                event.preventDefault();
                event.stopPropagation();
                setFloatCollapsed(!(floatPanel && floatPanel.classList.contains('is-collapsed')));
                return;
            }

            if (floatPanel && floatPanel.classList.contains('is-collapsed') && floatPanel.contains(event.target)) {
                event.preventDefault();
                setFloatCollapsed(false);
                return;
            }

            const trigger = event.target.closest('[data-wsc-guide-locate-key], [data-wsc-guide-locate]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            const key = decodeGuideValue(
                trigger.getAttribute('data-wsc-guide-locate-key') || currentKey || initialLocate || '',
            );
            if (!locateKey(key, trigger)) {
                if (typeof Weline !== 'undefined' && Weline.UI?.toast?.warning) {
                    Weline.UI.toast.warning(notFoundMsg);
                }
            }
        });
    }
}
