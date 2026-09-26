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
    if (root.dataset.wSystemConfigInheritBound === '1') {
        return;
    }
    root.dataset.wSystemConfigInheritBound = '1';

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
            // theme:address / theme:search-select 由下方专用逻辑处理。
            if (control.closest('[data-w-address], [data-w-system-config-address-countries], [data-w-map-country-host], [data-w-map-provider-host], [data-w-search-select]')) {
                return;
            }
            if (inherited) {
                control.setAttribute('disabled', 'disabled');
            } else {
                control.removeAttribute('disabled');
            }
        });
        row.querySelectorAll('[data-w-search-select="1"]').forEach((root) => {
            if (!(root instanceof HTMLElement)) {
                return;
            }
            // 继承态保持可点（同 address）：首次点击会取消继承；仅锁定行真正禁用。
            const forceDisabled = row.classList.contains('is-locked') || row.classList.contains('is-scope-readonly');
            if (window.WelineThemeSearchSelect && typeof window.WelineThemeSearchSelect.setDisabled === 'function') {
                window.WelineThemeSearchSelect.setDisabled(root, forceDisabled);
            } else {
                const input = root.querySelector('.w-search-select-input');
                if (forceDisabled) {
                    root.setAttribute('data-disabled', 'true');
                    if (input instanceof HTMLInputElement) {
                        input.disabled = true;
                        input.setAttribute('disabled', 'disabled');
                    }
                } else {
                    root.removeAttribute('data-disabled');
                    if (input instanceof HTMLInputElement) {
                        input.disabled = false;
                        input.removeAttribute('disabled');
                    }
                }
            }
            root.style.opacity = inherited ? '0.72' : '';
        });
        row.querySelectorAll('[data-w-system-config-address-shell="1"]').forEach((shell) => {
            if (!(shell instanceof HTMLElement)) {
                return;
            }
            shell.classList.toggle('is-inherit-dimmed', inherited);
            shell.style.opacity = inherited ? '0.72' : '';
        });
        // 继承时仍禁用配置值隐藏域，避免误提交覆盖。
        row.querySelectorAll('.w-system-config__field-control :is(input[type="hidden"][name^="values["], textarea[name^="values["], textarea[data-w-system-config-map-value])').forEach((control) => {
            if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)) {
                return;
            }
            if (inherited) {
                control.setAttribute('disabled', 'disabled');
            } else {
                control.removeAttribute('disabled');
            }
        });
    }

    function unlockInheritForAddress(target) {
        if (!(target instanceof Element)) {
            return false;
        }
        if (!target.closest('[data-w-address], [data-w-system-config-address-countries], [data-w-map-country-host], [data-w-system-config-address-shell], [data-w-map-provider-host], [data-w-search-select]')) {
            return false;
        }
        const row = target.closest('[data-testid="system-config-field"]');
        if (!(row instanceof HTMLElement) || !row.classList.contains('is-inherited')) {
            return false;
        }
        if (row.classList.contains('is-locked') || row.classList.contains('is-scope-readonly')) {
            return false;
        }
        const toggle = row.querySelector('[data-w-system-config-inherit-toggle]');
        if (!(toggle instanceof HTMLInputElement) || toggle.disabled || !toggle.checked) {
            return false;
        }
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    root.querySelectorAll('[data-w-system-config-inherit-toggle]').forEach((toggle) => {
        if (!(toggle instanceof HTMLInputElement) || toggle.disabled) {
            return;
        }
        syncRow(toggle);
        toggle.addEventListener('change', () => syncRow(toggle));
    });

    // Address widgets remount after inherit sync; first click should clear inherit so the menu can open.
    root.addEventListener('pointerdown', (event) => {
        unlockInheritForAddress(event.target);
    }, true);

    window.WelineSystemConfigInherit = {
        syncAll() {
            root.querySelectorAll('[data-w-system-config-inherit-toggle]').forEach((toggle) => {
                if (toggle instanceof HTMLInputElement && !toggle.disabled) {
                    syncRow(toggle);
                }
            });
        },
        unlockInheritForAddress,
    };
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

function initSystemConfigGoogleOauthJsonImport() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }
    if (root.dataset.wGoogleOauthJsonBound === '1') {
        return;
    }
    root.dataset.wGoogleOauthJsonBound = '1';

    const clientIdKey = 'customer/social_login/google/client_id';
    const clientSecretKey = 'customer/social_login/google/client_secret';

    function toast(kind, message) {
        if (typeof Weline !== 'undefined' && Weline.UI && Weline.UI.toast && typeof Weline.UI.toast[kind] === 'function') {
            Weline.UI.toast[kind](message);
            return;
        }
        if (kind === 'error') {
            window.alert(message);
        }
    }

    function extractGoogleOAuthCredentials(raw) {
        const text = String(raw || '').trim();
        if (text === '') {
            throw new Error('文件为空');
        }
        let decoded;
        try {
            decoded = JSON.parse(text);
        } catch (_error) {
            throw new Error('不是合法 JSON');
        }
        if (!decoded || typeof decoded !== 'object') {
            throw new Error('不是合法 JSON 对象');
        }
        let section = null;
        for (const key of ['web', 'installed', 'ios', 'android']) {
            if (decoded[key] && typeof decoded[key] === 'object') {
                section = decoded[key];
                break;
            }
        }
        if (!section && decoded.client_id && decoded.client_secret) {
            section = decoded;
        }
        if (!section || typeof section !== 'object') {
            throw new Error('缺少 web/installed 段或顶层 client_id/client_secret');
        }
        const clientId = String(section.client_id || '').trim();
        const clientSecret = String(section.client_secret || '').trim();
        if (clientId === '' || clientSecret === '') {
            throw new Error('缺少 client_id 或 client_secret');
        }
        if (!clientId.includes('.apps.googleusercontent.com')) {
            throw new Error('client_id 不像 Google OAuth 客户端');
        }
        return { clientId, clientSecret };
    }

    function findValueControl(form, key) {
        const name = 'values[' + key + ']';
        const controls = form.querySelectorAll('input, textarea, select');
        for (const control of controls) {
            if (
                (control instanceof HTMLInputElement
                    || control instanceof HTMLTextAreaElement
                    || control instanceof HTMLSelectElement)
                && control.name === name
            ) {
                return control;
            }
        }
        return null;
    }

    function unlockAndFill(form, key, value) {
        const toggle = form.querySelector(
            '[data-w-system-config-inherit-toggle][value="' + key.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]',
        );
        if (toggle instanceof HTMLInputElement && toggle.checked && !toggle.disabled) {
            toggle.checked = false;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const control = findValueControl(form, key);
        if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)) {
            return false;
        }
        control.disabled = false;
        control.removeAttribute('disabled');
        control.readOnly = false;
        control.value = value;
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function parseSelectedFile(input) {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) {
            return;
        }
        const form = input.closest('form');
        if (!(form instanceof HTMLFormElement)) {
            toast('error', '未找到配置表单，无法填入 Client ID / Secret');
            return;
        }
        const status = input.closest('td, .w-field, .w-system-config__field-control')
            ?.querySelector('[data-w-google-oauth-json-status]')
            || form.querySelector('[data-w-google-oauth-json-status]');
        if (status instanceof HTMLElement) {
            status.textContent = '正在解析…';
        }
        const reader = new FileReader();
        reader.onerror = () => {
            toast('error', '读取 JSON 文件失败');
            if (status instanceof HTMLElement) {
                status.textContent = '读取失败，请重选文件';
            }
        };
        reader.onload = () => {
            try {
                const credentials = extractGoogleOAuthCredentials(reader.result);
                const filledId = unlockAndFill(form, clientIdKey, credentials.clientId);
                const filledSecret = unlockAndFill(form, clientSecretKey, credentials.clientSecret);
                if (!filledId || !filledSecret) {
                    throw new Error('未找到 Client ID / Secret 输入框');
                }
                toast('success', '已解析并填入 Client ID / Secret，请填写底部后台登录密码后保存');
                if (status instanceof HTMLElement) {
                    status.textContent = '已解析填入下方 Client ID / Secret（文件仍不落库，请保存）';
                }
                const rootEl = document.querySelector('[data-w-system-config]');
                if (rootEl && typeof rootEl._wSystemConfigSyncReauth === 'function') {
                    rootEl._wSystemConfigSyncReauth(form, { forceExpand: true, focus: true });
                } else {
                    const reauth = form.querySelector('[data-w-system-config-reauth="1"]');
                    if (reauth instanceof HTMLInputElement) {
                        const reauthField = reauth.closest('.w-system-config__reauth-field');
                        if (reauthField instanceof HTMLElement) {
                            reauthField.hidden = false;
                            reauthField.classList.add('is-required-flash');
                        }
                        reauth.disabled = false;
                        reauth.focus();
                    }
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : '解析失败';
                toast('error', 'Google OAuth JSON 无法识别：' + message);
                if (status instanceof HTMLElement) {
                    status.textContent = '解析失败：' + message;
                }
                input.value = '';
            }
        };
        reader.readAsText(file, 'UTF-8');
    }

    // Event delegation: survives disclosure re-open and avoids brittle name=[] selectors.
    root.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.type !== 'file') {
            return;
        }
        if (target.getAttribute('data-w-google-oauth-json-file') !== '1') {
            return;
        }
        parseSelectedFile(target);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigGoogleOauthJsonImport, { once: true });
} else {
    initSystemConfigGoogleOauthJsonImport();
}

function initSystemConfigSaveLoading() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }
    if (root.dataset.wSystemConfigSaveLoadingBound === '1') {
        return;
    }
    root.dataset.wSystemConfigSaveLoadingBound = '1';

    function isSensitivePlaceholder(value) {
        const raw = String(value || '').trim();
        return raw === '' || raw === '***' || raw === '********' || /^\*+$/.test(raw);
    }

    function sensitiveValueInputs(form) {
        return [...form.querySelectorAll(
            'input[data-w-system-config-sensitive="1"], input[type="password"][name^="values["], input[name*="/client_secret"], input[name*="/secret"]',
        )].filter((input) => input instanceof HTMLInputElement
            && input.getAttribute('data-w-system-config-reauth') !== '1'
            && !input.disabled);
    }

    function formNeedsReauth(form) {
        for (const input of sensitiveValueInputs(form)) {
            if (!isSensitivePlaceholder(input.value)) {
                return true;
            }
        }
        return false;
    }

    function syncReauthVisibility(form, options = {}) {
        const expand = formNeedsReauth(form);
        const force = options.forceExpand === true;
        const shouldShow = expand || force;
        const panel = form.querySelector('[data-w-system-config-reauth-panel="1"]');
        const hint = form.querySelector('[data-w-system-config-reauth-hint="1"]');
        const reauth = form.querySelector('[data-w-system-config-reauth="1"]');
        if (panel instanceof HTMLElement) {
            panel.hidden = !shouldShow;
        }
        if (hint instanceof HTMLElement) {
            hint.hidden = !shouldShow;
        }
        if (reauth instanceof HTMLInputElement) {
            reauth.disabled = !shouldShow;
            if (!shouldShow) {
                reauth.value = '';
                reauth.removeAttribute('required');
            } else if (options.focus === true) {
                reauth.focus();
            }
        }
        return shouldShow;
    }

    function resetSaveButton(button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.removeAttribute('aria-busy');
        button.disabled = false;
        const label = button.querySelector('lang, span, .w-button__label');
        if (label && button.dataset.wSaveLabel) {
            label.textContent = button.dataset.wSaveLabel;
        }
    }

    root.querySelectorAll('form[data-w-system-config-save-form="1"]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        syncReauthVisibility(form);
        form.addEventListener('input', () => {
            syncReauthVisibility(form);
        });
        form.addEventListener('change', () => {
            syncReauthVisibility(form);
        });
        form.addEventListener('submit', (event) => {
            // Control binding: gather data-cache-namespaces from non-inherited fields.
            form.querySelectorAll('input[name="cache_namespaces[]"][data-w-cache-ns-collected="1"]').forEach((node) => {
                node.remove();
            });
            const nsSeen = {};
            form.querySelectorAll('[data-testid="system-config-field"][data-cache-namespaces]').forEach((row) => {
                if (!(row instanceof HTMLElement) || row.classList.contains('is-inherited')) {
                    return;
                }
                const raw = String(row.getAttribute('data-cache-namespaces') || '').trim();
                if (!raw) {
                    return;
                }
                raw.split(/[\s,]+/).forEach((token) => {
                    const ns = String(token || '').trim();
                    if (!ns || nsSeen[ns]) {
                        return;
                    }
                    nsSeen[ns] = true;
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'cache_namespaces[]';
                    input.value = ns;
                    input.setAttribute('data-w-cache-ns-collected', '1');
                    form.appendChild(input);
                });
            });

            const button = form.querySelector('[data-w-system-config-save-submit="1"]');
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            if (formNeedsReauth(form)) {
                syncReauthVisibility(form, { forceExpand: true, focus: true });
                const reauth = form.querySelector('[data-w-system-config-reauth="1"]');
                if (!(reauth instanceof HTMLInputElement) || String(reauth.value || '').trim() === '') {
                    event.preventDefault();
                    resetSaveButton(button);
                    const reauthField = reauth instanceof HTMLElement
                        ? reauth.closest('.w-system-config__reauth-field')
                        : null;
                    if (reauthField instanceof HTMLElement) {
                        reauthField.classList.add('is-required-flash');
                    }
                    if (typeof Weline !== 'undefined' && Weline.UI?.toast?.error) {
                        Weline.UI.toast.error('保存敏感配置需要重新输入登录密码。');
                    } else {
                        window.alert('保存敏感配置需要重新输入登录密码。');
                    }
                    return;
                }
            } else {
                syncReauthVisibility(form);
            }
            if (button.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
            const label = button.querySelector('lang, span, .w-button__label');
            if (label) {
                button.dataset.wSaveLabel = label.textContent || '';
                label.textContent = '保存中…';
            }
        });
    });

    // Expose for Google OAuth JSON import fill path.
    root._wSystemConfigSyncReauth = syncReauthVisibility;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigSaveLoading, { once: true });
} else {
    initSystemConfigSaveLoading();
}

/**
 * 统一配置中心：多模板同页时每条表单都有 fixed 底部保存条。
 * 同一时刻只激活一条（焦点所在表单 / 视口内占比最大的模板），避免叠出「两个保存提示」。
 */
function initSystemConfigSaveDock() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }
    if (root.dataset.wSystemConfigSaveDockBound === '1') {
        return;
    }
    root.dataset.wSystemConfigSaveDockBound = '1';

    function listDocks() {
        return [...root.querySelectorAll('[data-w-system-config-save-dock="1"]')]
            .filter((node) => node instanceof HTMLElement);
    }

    function isEffectivelyVisible(element) {
        if (!(element instanceof HTMLElement)) {
            return false;
        }
        // 判定所属模板/表单是否可见；忽略 dock 自身因未激活而产生的 display:none。
        const scope = element.closest('.w-system-config__template')
            || element.closest('form[data-w-system-config-save-form="1"]')
            || element.parentElement;
        let node = scope instanceof HTMLElement ? scope : element;
        while (node && node !== document.documentElement) {
            if (node instanceof HTMLElement) {
                if (node.hidden || node.classList.contains('is-search-hidden')) {
                    return false;
                }
                if (node !== element) {
                    const style = window.getComputedStyle(node);
                    if (style.display === 'none' || style.visibility === 'hidden') {
                        return false;
                    }
                }
            }
            if (node === root) {
                break;
            }
            node = node.parentElement;
        }
        return true;
    }

    function activateDock(target) {
        listDocks().forEach((dock) => {
            const on = dock === target;
            dock.classList.toggle('is-active', on);
            dock.setAttribute('aria-hidden', on ? 'false' : 'true');
        });
    }

    function pickDock() {
        const visible = listDocks().filter(isEffectivelyVisible);
        if (visible.length === 0) {
            return null;
        }
        if (visible.length === 1) {
            return visible[0];
        }

        const active = document.activeElement;
        if (active instanceof HTMLElement && root.contains(active)) {
            const form = active.closest('form[data-w-system-config-save-form="1"]');
            const focusedDock = form instanceof HTMLFormElement
                ? form.querySelector('[data-w-system-config-save-dock="1"]')
                : null;
            if (focusedDock instanceof HTMLElement && visible.includes(focusedDock)) {
                return focusedDock;
            }
        }

        const viewportHeight = window.innerHeight || 1;
        let best = visible[0];
        let bestScore = -1;
        visible.forEach((dock) => {
            const template = dock.closest('.w-system-config__template');
            const box = (template instanceof HTMLElement ? template : dock).getBoundingClientRect();
            const top = Math.max(box.top, 0);
            const bottom = Math.min(box.bottom, viewportHeight - 72);
            const score = Math.max(0, bottom - top);
            if (score > bestScore) {
                bestScore = score;
                best = dock;
            }
        });
        return best;
    }

    let syncTimer = 0;
    function syncSaveDocks() {
        activateDock(pickDock());
    }

    function scheduleSync() {
        if (syncTimer) {
            window.clearTimeout(syncTimer);
        }
        syncTimer = window.setTimeout(() => {
            syncTimer = 0;
            syncSaveDocks();
        }, 32);
    }

    root.addEventListener('focusin', scheduleSync);
    root.addEventListener('pointerdown', scheduleSync);
    window.addEventListener('scroll', scheduleSync, { passive: true });
    window.addEventListener('resize', scheduleSync);
    document.addEventListener('w-system-config:filter-ready', scheduleSync);
    const observer = new MutationObserver(scheduleSync);
    observer.observe(root, {
        attributes: true,
        subtree: true,
        attributeFilter: ['hidden', 'class'],
    });

    syncSaveDocks();
    root.setAttribute('data-save-dock-ready', '1');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigSaveDock, { once: true });
} else {
    initSystemConfigSaveDock();
}

function initSystemConfigCountryProviderMap() {
    const root = document.querySelector('[data-w-system-config]');
    if (!(root instanceof HTMLElement)) {
        return;
    }
    if (root.dataset.wCountryProviderMapBound === '1') {
        return;
    }
    root.dataset.wCountryProviderMapBound = '1';

    function text(value) {
        return value == null ? '' : String(value).trim();
    }

    function normalizeCountryCode(value) {
        const code = text(value).toUpperCase();
        return /^[A-Z]{2}$/.test(code) ? code : '';
    }

    function bootAddress() {
        if (!window.WelineThemeAddress || typeof window.WelineThemeAddress.boot !== 'function') {
            return false;
        }
        window.WelineThemeAddress.boot();
        return true;
    }

    function createCountryAddressRoot(code) {
        const addressRoot = document.createElement('div');
        addressRoot.className = 'w-address';
        addressRoot.setAttribute('data-w-address', '');
        const config = {
            for: 'country',
            code: code,
            names: {country: '_wsc_map_' + code},
            labels: {},
            filters: {country: '', province: '', city: '', district: '', street: ''},
            sourceUrl: '',
            searchable: true,
            cascade: false,
            catalog: 'global',
            selection: 'multi',
            multiLevels: ['country'],
        };
        addressRoot.setAttribute('data-address-config', JSON.stringify(config));
        return addressRoot;
    }

    function countrySeedRows(code) {
        const normalized = normalizeCountryCode(code);
        if (!normalized) {
            return [];
        }
        return [{
            country_code: normalized,
            region_code: normalized,
            region_type: 'country',
            region_name: normalized,
            label: normalized,
        }];
    }

    function remountMapCountryAddress(host, seedRows) {
        if (!(host instanceof HTMLElement)) {
            return null;
        }
        let addressRoot = host.querySelector('[data-w-address]');
        const prevCode = addressRoot instanceof HTMLElement
            ? text(addressRoot.dataset.addressCode || '')
            : '';
        if (prevCode && window.WelineThemeAddress && window.WelineThemeAddress.groups) {
            try {
                delete window.WelineThemeAddress.groups[prevCode];
            } catch (e) {}
        }
        if (addressRoot instanceof HTMLElement) {
            addressRoot.remove();
        }
        const code = 'wsc-map-js-' + Math.random().toString(36).slice(2, 10);
        addressRoot = createCountryAddressRoot(code);
        addressRoot.setAttribute('data-multi-seed', JSON.stringify(Array.isArray(seedRows) ? seedRows : []));
        host.appendChild(addressRoot);
        host.dataset.wMapCountryApplied = '';
        bootAddress();
        return addressRoot;
    }

    function countryCodesFromHost(host) {
        if (!(host instanceof HTMLElement)) {
            return [];
        }
        const codes = [];
        const seen = {};
        const push = (raw) => {
            const code = normalizeCountryCode(raw);
            if (!code || seen[code]) {
                return;
            }
            seen[code] = true;
            codes.push(code);
        };
        const addressRoot = host.querySelector('[data-w-address]');
        if (addressRoot instanceof HTMLElement) {
            try {
                const raw = addressRoot.getAttribute('data-multi-selection')
                    || addressRoot.getAttribute('data-multi-seed')
                    || '[]';
                const list = JSON.parse(raw);
                if (Array.isArray(list)) {
                    list.forEach((row) => {
                        push(row && (row.country_code || row.region_code));
                    });
                }
            } catch (e) {}
        }
        if (codes.length === 0) {
            const hidden = host.querySelector('[data-w-map-country="1"]');
            if (hidden instanceof HTMLInputElement) {
                String(hidden.value || '').split(/[,\s]+/).forEach(push);
            }
            push(host.getAttribute('data-initial-country') || '');
        }
        return codes;
    }

    function countryCodeFromHost(host) {
        const codes = countryCodesFromHost(host);
        return codes.length ? codes[codes.length - 1] : '';
    }

    function syncCountryHidden(host) {
        if (!(host instanceof HTMLElement)) {
            return '';
        }
        const codes = countryCodesFromHost(host);
        let hidden = host.querySelector('[data-w-map-country="1"]');
        if (!(hidden instanceof HTMLInputElement)) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.setAttribute('data-w-map-country', '1');
            host.insertBefore(hidden, host.firstChild);
        }
        hidden.value = codes.join(',');
        host.setAttribute('data-initial-country', codes[0] || '');
        return codes[0] || '';
    }

    function applyInitialCountry(host) {
        if (!(host instanceof HTMLElement) || host.dataset.wMapCountryApplied === '1') {
            return;
        }
        const initial = normalizeCountryCode(host.getAttribute('data-initial-country') || '');
        let addressRoot = host.querySelector('[data-w-address]');
        if (!(addressRoot instanceof HTMLElement)) {
            return;
        }
        host.dataset.wMapCountryApplied = '1';
        const seedAttr = host.getAttribute('data-seed');
        let seedRows = countrySeedRows(initial);
        if (seedAttr) {
            try {
                const parsed = JSON.parse(seedAttr);
                if (Array.isArray(parsed) && parsed.length) {
                    seedRows = parsed;
                }
            } catch (e) {}
        }
        addressRoot.setAttribute('data-multi-seed', JSON.stringify(seedRows));
        // 旧 single 控件改成 multi 可搜索 chips。
        let cfg = {};
        try {
            cfg = JSON.parse(addressRoot.getAttribute('data-address-config') || '{}');
        } catch (e) {
            cfg = {};
        }
        if (text(cfg.selection || '') !== 'multi') {
            remountMapCountryAddress(host, seedRows);
            host.dataset.wMapCountryApplied = '1';
            syncCountryHidden(host);
            return;
        }
        if (addressRoot.dataset.wAddressReady !== 'true') {
            bootAddress();
        }
        syncCountryHidden(host);
    }

    function ensureRowAddress(row) {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const host = row.querySelector('[data-w-map-country-host="1"]');
        if (!(host instanceof HTMLElement)) {
            return;
        }
        if (host.querySelector('[data-w-address]')) {
            applyInitialCountry(host);
            return;
        }
        const slot = host.querySelector('[data-w-map-country-slot="1"]');
        const initial = normalizeCountryCode(host.getAttribute('data-initial-country') || '');
        const addressRoot = createCountryAddressRoot('wsc-map-js-' + Math.random().toString(36).slice(2, 10));
        addressRoot.setAttribute('data-multi-seed', JSON.stringify(countrySeedRows(initial)));
        if (slot instanceof HTMLElement) {
            slot.replaceWith(addressRoot);
        } else {
            host.appendChild(addressRoot);
        }
        host.dataset.wMapCountryApplied = '1';
        bootAddress();
        syncCountryHidden(host);
    }

    function parseProviderOptions(container) {
        if (!(container instanceof HTMLElement)) {
            return [];
        }
        try {
            const raw = container.getAttribute('data-provider-options-json') || '[]';
            const list = JSON.parse(raw);
            if (!Array.isArray(list)) {
                return [];
            }
            return list.map((row) => ({
                value: String(row && row.value != null ? row.value : ''),
                label: String(row && row.label != null ? row.label : (row && row.value != null ? row.value : '')),
            })).filter((row) => row.value !== '');
        } catch (e) {
            return [];
        }
    }

    function providerValue(row) {
        if (!(row instanceof HTMLElement)) {
            return '';
        }
        const provider = row.querySelector('[data-w-map-provider="1"]');
        if (provider instanceof HTMLInputElement || provider instanceof HTMLSelectElement) {
            return text(provider.value).toLowerCase();
        }
        return '';
    }

    function ensureRowProvider(row, container) {
        if (!(row instanceof HTMLElement) || !(container instanceof HTMLElement)) {
            return;
        }
        if (row.closest('[data-w-map-row-template="1"]')) {
            return;
        }
        const host = row.querySelector('[data-w-map-provider-host="1"]');
        if (!(host instanceof HTMLElement)) {
            return;
        }
        if (host.querySelector('[data-w-search-select="1"]')) {
            return;
        }
        if (!window.WelineThemeSearchSelect || typeof window.WelineThemeSearchSelect.mountInto !== 'function') {
            return;
        }
        const initial = text(host.getAttribute('data-initial-provider') || '');
        const placeholder = text(container.getAttribute('data-provider-placeholder') || '') || 'Search...';
        window.WelineThemeSearchSelect.mountInto(host, {
            id: 'wsc-map-provider-' + Math.random().toString(36).slice(2, 10),
            name: '',
            value: initial,
            options: parseProviderOptions(container),
            clearable: true,
            multiple: false,
            placeholder: placeholder,
            hiddenAttrs: {
                'data-w-map-provider': '1',
            },
        });
    }

    function unlockMapFieldInherit(fromEl) {
        if (!(fromEl instanceof Element)) {
            return;
        }
        if (window.WelineSystemConfigInherit
            && typeof window.WelineSystemConfigInherit.unlockInheritForAddress === 'function') {
            window.WelineSystemConfigInherit.unlockInheritForAddress(fromEl);
        }
    }

    function syncMap(container) {
        if (!(container instanceof HTMLElement)) {
            return { ok: true, incomplete: false, lines: [] };
        }
        const inputId = String(container.getAttribute('data-input-id') || '');
        const hidden = inputId
            ? document.getElementById(inputId)
            : container.querySelector('textarea[name^="values["], input[type="hidden"][name^="values["]');
        if (!(hidden instanceof HTMLInputElement || hidden instanceof HTMLTextAreaElement)) {
            return { ok: false, incomplete: false, lines: [] };
        }
        const byCountry = {};
        let incomplete = false;
        const rowsHost = container.querySelector('[data-w-map-rows="1"]');
        const rows = rowsHost instanceof HTMLElement
            ? rowsHost.querySelectorAll('[data-w-map-row="1"]')
            : [];
        rows.forEach((row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const host = row.querySelector('[data-w-map-country-host="1"]');
            syncCountryHidden(host);
            const countryCodes = countryCodesFromHost(host);
            const providerCode = providerValue(row);
            if (countryCodes.length === 0 && providerCode === '') {
                return;
            }
            if (countryCodes.length === 0 || providerCode === '') {
                // 半成品行：国家或供应商缺一。勿把整段 values[] 清空。
                incomplete = true;
                return;
            }
            // 同一行多国 chips → 多条映射（共享该行供应商）；后写覆盖同国。
            countryCodes.forEach((countryCode) => {
                byCountry[countryCode] = providerCode;
            });
        });
        const lines = Object.keys(byCountry).sort().map((countryCode) => countryCode + '=' + byCountry[countryCode]);
        if (lines.length === 0 && incomplete) {
            container.setAttribute('data-map-incomplete', '1');
            return { ok: true, incomplete: true, lines: [] };
        }
        container.removeAttribute('data-map-incomplete');
        if (lines.length > 0 && hidden.disabled) {
            unlockMapFieldInherit(container);
            if (hidden.disabled) {
                hidden.disabled = false;
                hidden.removeAttribute('disabled');
            }
        }
        hidden.value = lines.join('\n');
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        return { ok: true, incomplete: false, lines: lines };
    }

    function addRow(container) {
        const rowsHost = container.querySelector('[data-w-map-rows="1"]');
        const template = container.querySelector('[data-w-map-row-template="1"]');
        const source = template instanceof HTMLElement ? template.firstElementChild : null;
        if (!(rowsHost instanceof HTMLElement) || !(source instanceof HTMLElement)) {
            return;
        }
        const node = source.cloneNode(true);
        if (!(node instanceof HTMLElement)) {
            return;
        }
        rowsHost.appendChild(node);
        ensureRowAddress(node);
        ensureRowProvider(node, container);
        syncMap(container);
    }

    function clearRow(row) {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const host = row.querySelector('[data-w-map-country-host="1"]');
        if (host instanceof HTMLElement) {
            host.setAttribute('data-initial-country', '');
            host.setAttribute('data-seed', '[]');
            host.dataset.wMapCountryApplied = '';
            const hidden = host.querySelector('[data-w-map-country="1"]');
            if (hidden instanceof HTMLInputElement) {
                hidden.value = '';
            }
            remountMapCountryAddress(host, []);
        }
        const providerHost = row.querySelector('[data-w-map-provider-host="1"]');
        if (providerHost instanceof HTMLElement) {
            providerHost.setAttribute('data-initial-provider', '');
            const provider = providerHost.querySelector('[data-w-map-provider="1"]');
            if (provider instanceof HTMLInputElement) {
                provider.value = '';
                provider.dispatchEvent(new Event('input', { bubbles: true }));
                provider.dispatchEvent(new Event('change', { bubbles: true }));
            }
            const display = providerHost.querySelector('.w-search-select-display');
            if (display instanceof HTMLElement) {
                display.textContent = '';
            }
            const chips = providerHost.querySelector('.w-search-select-chips');
            if (chips instanceof HTMLElement) {
                chips.innerHTML = '';
            }
            const root = providerHost.querySelector('[data-w-search-select="1"]');
            if (root instanceof HTMLElement) {
                root.classList.remove('has-value');
            }
        }
    }

    root.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const addButton = target.closest('[data-w-map-add="1"]');
        if (addButton instanceof HTMLElement) {
            const container = addButton.closest('[data-w-system-config-country-provider-map="1"]');
            if (container instanceof HTMLElement) {
                event.preventDefault();
                addRow(container);
            }
            return;
        }
        const removeButton = target.closest('[data-w-map-remove="1"]');
        if (removeButton instanceof HTMLElement) {
            const container = removeButton.closest('[data-w-system-config-country-provider-map="1"]');
            const row = removeButton.closest('[data-w-map-row="1"]');
            if (container instanceof HTMLElement && row instanceof HTMLElement) {
                event.preventDefault();
                if (row.closest('[data-w-map-row-template="1"]')) {
                    return;
                }
                const rowsHost = container.querySelector('[data-w-map-rows="1"]');
                const rows = rowsHost instanceof HTMLElement
                    ? rowsHost.querySelectorAll('[data-w-map-row="1"]')
                    : [];
                if (rows.length <= 1) {
                    clearRow(row);
                } else {
                    row.remove();
                }
                syncMap(container);
            }
        }
    });

    root.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const mapContainer = target.closest('[data-w-system-config-country-provider-map="1"]');
        if (!(mapContainer instanceof HTMLElement)) {
            return;
        }
        if (target.matches('[data-w-map-provider="1"]') || target.closest('[data-w-map-country-host="1"]') || target.closest('[data-w-map-provider-host="1"]')) {
            unlockMapFieldInherit(target);
            const host = target.closest('[data-w-map-country-host="1"]');
            if (host instanceof HTMLElement) {
                syncCountryHidden(host);
            }
            syncMap(mapContainer);
        }
    });

    root.addEventListener('weline:address:multi-change', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const countries = target.closest('[data-w-system-config-address-countries="1"]');
        if (countries instanceof HTMLElement) {
            unlockMapFieldInherit(countries);
            syncAddressCountries(countries);
        }
        const mapHost = target.closest('[data-w-map-country-host="1"]');
        if (mapHost instanceof HTMLElement) {
            unlockMapFieldInherit(mapHost);
            const mapContainer = mapHost.closest('[data-w-system-config-country-provider-map="1"]');
            syncCountryHidden(mapHost);
            if (mapContainer instanceof HTMLElement) {
                syncMap(mapContainer);
            }
        }
    });

    function syncAddressCountries(container) {
        if (!(container instanceof HTMLElement)) {
            return;
        }
        const inputId = String(container.getAttribute('data-input-id') || '');
        const hidden = inputId
            ? document.getElementById(inputId)
            : container.querySelector('input[type="hidden"]');
        if (!(hidden instanceof HTMLInputElement)) {
            return;
        }
        const addressRoot = container.querySelector('[data-w-address]');
        let codes = [];
        if (addressRoot instanceof HTMLElement) {
            try {
                const raw = addressRoot.getAttribute('data-multi-selection') || '[]';
                const list = JSON.parse(raw);
                if (Array.isArray(list)) {
                    const map = {};
                    list.forEach((row) => {
                        const code = normalizeCountryCode(row && (row.country_code || row.region_code));
                        if (code) {
                            map[code] = true;
                        }
                    });
                    codes = Object.keys(map).sort();
                }
            } catch (e) {
                codes = [];
            }
        }
        hidden.value = codes.join(',');
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bindAddressCountries(container) {
        if (!(container instanceof HTMLElement) || container.dataset.wAddressCountriesBound === '1') {
            return;
        }
        container.dataset.wAddressCountriesBound = '1';
        const addressRoot = container.querySelector('[data-w-address]');
        if (!(addressRoot instanceof HTMLElement)) {
            return;
        }
        const seed = container.getAttribute('data-seed') || '[]';
        try {
            addressRoot.setAttribute('data-multi-seed', seed);
        } catch (e) {}
        addressRoot.addEventListener('weline:address:multi-change', function () {
            syncAddressCountries(container);
        });
        syncAddressCountries(container);
    }

    function bootAll() {
        root.querySelectorAll('[data-w-system-config-address-countries="1"]').forEach((container) => {
            if (!(container instanceof HTMLElement)) {
                return;
            }
            const addressRoot = container.querySelector('[data-w-address]');
            if (!(addressRoot instanceof HTMLElement)) {
                return;
            }
            const seed = container.getAttribute('data-seed') || '[]';
            try {
                addressRoot.setAttribute('data-multi-seed', seed);
            } catch (e) {}
        });
        if (!bootAddress()) {
            return false;
        }
        root.querySelectorAll('[data-w-system-config-address-countries="1"]').forEach((container) => {
            if (container instanceof HTMLElement) {
                bindAddressCountries(container);
            }
        });
        root.querySelectorAll('[data-w-system-config-country-provider-map="1"] [data-w-map-row="1"]').forEach((row) => {
            if (row instanceof HTMLElement && !row.closest('[data-w-map-row-template="1"]')) {
                ensureRowAddress(row);
                const mapContainer = row.closest('[data-w-system-config-country-provider-map="1"]');
                if (mapContainer instanceof HTMLElement) {
                    ensureRowProvider(row, mapContainer);
                }
            }
        });
        root.querySelectorAll('[data-w-system-config-country-provider-map="1"]').forEach((container) => {
            if (container instanceof HTMLElement) {
                syncMap(container);
            }
        });
        if (window.WelineSystemConfigInherit && typeof window.WelineSystemConfigInherit.syncAll === 'function') {
            window.WelineSystemConfigInherit.syncAll();
        }
        // Provider search-select may load after address; keep retrying until mountInto exists.
        const pending = root.querySelectorAll('[data-w-map-provider-host="1"]:not(:has([data-w-search-select="1"]))');
        if (pending.length > 0 && !(window.WelineThemeSearchSelect && typeof window.WelineThemeSearchSelect.mountInto === 'function')) {
            return false;
        }
        return true;
    }

    root.querySelectorAll('form[data-w-system-config-save-form="1"]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            form.querySelectorAll('[data-w-system-config-address-countries="1"]').forEach((container) => {
                if (container instanceof HTMLElement) {
                    unlockMapFieldInherit(container);
                    syncAddressCountries(container);
                }
            });
            let mapIncomplete = false;
            form.querySelectorAll('[data-w-system-config-country-provider-map="1"]').forEach((container) => {
                if (!(container instanceof HTMLElement)) {
                    return;
                }
                unlockMapFieldInherit(container);
                const result = syncMap(container);
                if (result && result.incomplete) {
                    mapIncomplete = true;
                }
                const inputId = String(container.getAttribute('data-input-id') || '');
                const hidden = inputId ? document.getElementById(inputId) : null;
                if ((hidden instanceof HTMLInputElement || hidden instanceof HTMLTextAreaElement) && hidden.disabled
                    && container.closest('[data-testid="system-config-field"]:not(.is-inherited)')) {
                    hidden.disabled = false;
                    hidden.removeAttribute('disabled');
                }
            });
            if (mapIncomplete) {
                event.preventDefault();
                event.stopPropagation();
                window.alert('国家覆盖：请为每一行同时选择国家与供应商后再保存（可在同一行多选国家共享供应商，或点「添加覆盖」分多行）。');
            }
        });
    });

    if (!bootAll()) {
        let tries = 0;
        const timer = window.setInterval(function () {
            tries += 1;
            if (bootAll() || tries >= 40) {
                window.clearInterval(timer);
            }
        }, 100);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemConfigCountryProviderMap, { once: true });
} else {
    initSystemConfigCountryProviderMap();
}
