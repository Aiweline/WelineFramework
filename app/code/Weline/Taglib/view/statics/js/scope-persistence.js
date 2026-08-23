export function register(UI) {
    UI.define('scope-persistence', ({ element, listen, UI: componentUI }) => {
        const endpoint = String(element.dataset.wScopeUrl || '').trim();
        const containerId = String(element.dataset.wScopeContainer || '').trim();
        const eventNames = [...new Set(String(element.dataset.wScopeEvents || 'input change').split(/\s+/).filter(Boolean))];
        const storageKey = 'weline_scope_data.v2';
        const loadedScopes = new Set();
        let saveTimer = 0;

        const container = () => document.getElementById(containerId) || document.body;
        const fields = () => [...container().querySelectorAll('[scope][name]')]
            .filter((field) => field instanceof HTMLElement);
        const readStore = () => {
            try {
                const value = JSON.parse(localStorage.getItem(storageKey) || '{}');
                return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
            } catch (_error) {
                return {};
            }
        };
        const writeStore = (value) => {
            try {
                localStorage.setItem(storageKey, JSON.stringify(value));
            } catch (_error) {
            }
        };
        const api = () => {
            if (typeof window.Weline?.Api?.get !== 'function' || typeof window.Weline?.Api?.post !== 'function') {
                throw new Error('Weline.Api is unavailable.');
            }
            return window.Weline.Api;
        };
        const fieldValue = (field) => {
            if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                return field.checked ? field.value : '';
            }
            if (field instanceof HTMLInputElement && field.type === 'radio') {
                return field.checked ? field.value : undefined;
            }
            return 'value' in field ? field.value : field.getAttribute('value') || '';
        };
        const applyValue = (field, value) => {
            const normalized = value == null ? '' : String(value);
            if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                if (!field.checked) field.checked = !['', '0', 'false'].includes(normalized.toLowerCase());
                return;
            }
            if (field instanceof HTMLInputElement && field.type === 'radio') {
                if (!field.checked && field.value === normalized) field.checked = true;
                return;
            }
            if (field instanceof HTMLSelectElement) {
                if (![...field.options].some((option) => option.value === normalized) && normalized !== '') {
                    field.add(new Option(normalized, normalized));
                }
                if (field.value !== normalized) {
                    field.value = normalized;
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }
            if ('value' in field && field.value === '') field.value = normalized;
        };
        const applyScope = (scope, values) => {
            if (!values || typeof values !== 'object') return;
            fields().forEach((field) => {
                const name = field.getAttribute('name') || '';
                if (field.getAttribute('scope') !== scope || !Object.prototype.hasOwnProperty.call(values, name)) return;
                applyValue(field, values[name]);
            });
        };
        const loadScope = async (scope) => {
            if (scope === '' || loadedScopes.has(scope) || endpoint === '') return;
            loadedScopes.add(scope);
            try {
                const url = new URL(endpoint, window.location.href);
                if (url.origin !== window.location.origin) throw new Error('Scope persistence endpoint must be same-origin.');
                url.searchParams.set('scope', scope);
                const response = await api().get(url.href);
                applyScope(scope, response?.json || {});
            } catch (error) {
                loadedScopes.delete(scope);
                componentUI.toast.error(error instanceof Error ? error.message : String(error));
            }
        };
        const discoverScopes = () => {
            const scopes = new Set(fields().map((field) => field.getAttribute('scope') || '').filter(Boolean));
            scopes.forEach((scope) => void loadScope(scope));
        };
        const saveChangedScopes = async () => {
            const store = readStore();
            for (const [scope, entry] of Object.entries(store)) {
                if (!entry?.hasChange || !entry.data || Object.keys(entry.data).length === 0) continue;
                try {
                    await api().post(endpoint, { scope, data: entry.data });
                    const latest = readStore();
                    if (latest[scope]) {
                        latest[scope].hasChange = false;
                        writeStore(latest);
                    }
                } catch (error) {
                    componentUI.toast.error(error instanceof Error ? error.message : String(error));
                }
            }
        };
        const scheduleSave = () => {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(() => void saveChangedScopes(), 5000);
        };
        const record = (event) => {
            const field = event.target instanceof HTMLElement ? event.target.closest('[scope][name]') : null;
            if (!(field instanceof HTMLElement) || !container().contains(field)) return;
            const allowedEvents = String(field.getAttribute('event') || eventNames.join(' ')).split(/\s+/);
            if (!allowedEvents.includes(event.type)) return;
            const value = fieldValue(field);
            if (value === undefined) return;
            const scope = field.getAttribute('scope') || '';
            const name = field.getAttribute('name') || '';
            if (scope === '' || name === '') return;
            const store = readStore();
            store[scope] = store[scope] || { data: {}, hasChange: false };
            store[scope].data[name] = value;
            store[scope].hasChange = true;
            writeStore(store);
            scheduleSave();
        };

        eventNames.forEach((eventName) => listen(container(), eventName, record));
        const observer = new MutationObserver(discoverScopes);
        observer.observe(container(), { childList: true, subtree: true });
        discoverScopes();

        return {
            flush: saveChangedScopes,
            element,
            destroy() {
                window.clearTimeout(saveTimer);
                observer.disconnect();
            },
        };
    });
}
