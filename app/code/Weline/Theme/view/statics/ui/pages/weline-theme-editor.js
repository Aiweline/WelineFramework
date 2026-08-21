/* Weline UI source: js/pages/theme-editor.js */
const Weline = window.Weline = window.Weline || {};

function element(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
}

function icon(name, size = 'sm') {
    return Weline.UI.icon.create(name, { size });
}

function parseObject(value) {
    try {
        const parsed = JSON.parse(String(value || '{}'));
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (_error) {
        return {};
    }
}

function responseMessage(result, fallback) {
    return String(result?.message || result?.data?.message || fallback);
}

function identity(root) {
    return {
        theme_id: Number.parseInt(root.dataset.themeId || '0', 10) || 0,
        frontend_theme_id: Number.parseInt(root.dataset.frontendThemeId || '0', 10) || 0,
        backend_theme_id: Number.parseInt(root.dataset.backendThemeId || '0', 10) || 0,
        editor_area: root.dataset.editorArea || 'frontend',
        page_type: root.dataset.pageType || 'homepage',
        layout_type: root.dataset.pageType || 'homepage',
        layout_option: root.dataset.layoutOption || 'default',
        scope: root.dataset.scope || 'default',
        target_type: root.dataset.targetType || 'global',
        target_id: Number.parseInt(root.dataset.targetId || '0', 10) || 0,
        status: root.dataset.previewStatus || 'draft',
    };
}

async function themeRequest(url, options = {}) {
    if (typeof Weline.load === 'function') await Weline.load('api');
    if (!Weline.Api || typeof Weline.Api.resource !== 'function') {
        throw new Error('Weline.Api is unavailable.');
    }
    const method = String(options.method || 'GET').toUpperCase();
    const headers = { ...(options.headers || {}) };
    let body = '';
    if (options.body !== undefined && options.body !== null) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/json';
        body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
    }
    return Weline.Api.resource('theme').editorRequest({
        url: String(url),
        method,
        headers,
        body,
    });
}

function flattenWidgets(result) {
    if (Array.isArray(result?.items)) return result.items.filter((item) => item && typeof item === 'object');
    const groups = result?.data && typeof result.data === 'object' ? result.data : {};
    const widgets = [];
    Object.entries(groups).forEach(([groupCode, group]) => {
        if (!group || typeof group !== 'object' || !Array.isArray(group.widgets)) return;
        group.widgets.forEach((widget) => {
            if (!widget || typeof widget !== 'object') return;
            widgets.push({
                ...widget,
                group_code: groupCode,
                group_label: String(group.label || groupCode),
            });
        });
    });
    return widgets;
}

function registerThemeEditor(UI) {
    UI.define('theme-editor', ({ element: root, listen, emit }) => {
        const preview = root.querySelector('[data-w-preview-frame]');
        const previewSpinner = root.querySelector('[data-w-preview-spinner]');
        const widgetList = root.querySelector('[data-w-widget-list]');
        const widgetSearch = root.querySelector('[data-w-widget-search]');
        const widgetCount = root.querySelector('[data-w-widget-count]');
        const versionsDialog = root.querySelector('#w-theme-editor-versions');
        const versionList = root.querySelector('[data-w-version-list]');
        const versionForm = root.querySelector('[data-w-version-form]');
        const layoutOptions = parseObject(root.dataset.layoutOptions);
        let widgets = [];
        let widgetRequest = 0;

        const setPreviewBusy = (busy) => {
            if (previewSpinner) previewSpinner.hidden = !busy;
            if (preview) preview.setAttribute('aria-busy', String(busy));
        };

        const requestUrl = (base, params = {}) => {
            const url = new URL(base, window.location.href);
            Object.entries(params).forEach(([key, value]) => {
                if (value === '' || value === null || value === undefined) return;
                url.searchParams.set(key, String(value));
            });
            return url.toString();
        };

        const renderWidgets = () => {
            if (!(widgetList instanceof HTMLElement)) return;
            const query = widgetSearch instanceof HTMLInputElement
                ? widgetSearch.value.trim().toLocaleLowerCase()
                : '';
            const visible = widgets.filter((widget) => {
                if (query === '') return true;
                return [widget.name, widget.code, widget.description, widget.group_label]
                    .some((value) => String(value || '').toLocaleLowerCase().includes(query));
            });
            widgetList.replaceChildren();
            if (visible.length === 0) {
                const empty = element('div', 'w-empty-state');
                empty.append(icon('search', 'lg'), element('p', '', query ? '没有匹配的部件' : '当前布局没有可用部件'));
                widgetList.append(empty);
            } else {
                visible.forEach((widget) => {
                    const item = element('article', 'w-theme-editor__widget');
                    const glyph = element('span', 'w-theme-editor__widget-icon');
                    glyph.append(icon(String(widget.icon || widget.type || 'puzzle')));
                    const copy = element('div', 'w-theme-editor__widget-copy');
                    copy.append(
                        element('strong', '', String(widget.name || widget.code || '未命名部件')),
                        element('small', '', String(widget.description || widget.group_label || widget.module || '')),
                    );
                    const code = element('code', 'w-theme-editor__widget-code', String(widget.code || ''));
                    item.append(glyph, copy, code);
                    widgetList.append(item);
                });
            }
            if (widgetCount) widgetCount.textContent = String(visible.length);
            emit('widgets-rendered', { total: widgets.length, visible: visible.length }, false);
        };

        const loadWidgets = async () => {
            if (!(widgetList instanceof HTMLElement) || !root.dataset.apiWidgets) return;
            const currentRequest = ++widgetRequest;
            widgetList.setAttribute('aria-busy', 'true');
            try {
                const current = identity(root);
                const result = await themeRequest(requestUrl(root.dataset.apiWidgets, {
                    theme_id: current.theme_id,
                    frontend_theme_id: current.frontend_theme_id,
                    backend_theme_id: current.backend_theme_id,
                    editor_area: current.editor_area,
                    page_type: current.page_type,
                    limit: 100,
                }));
                if (currentRequest !== widgetRequest) return;
                if (result?.success === false) throw new Error(responseMessage(result, '部件目录加载失败'));
                widgets = flattenWidgets(result);
                renderWidgets();
            } catch (error) {
                if (currentRequest !== widgetRequest) return;
                widgetList.replaceChildren();
                const alert = element('div', 'w-alert');
                alert.dataset.tone = 'danger';
                alert.setAttribute('role', 'alert');
                alert.append(icon('warning'), element('span', '', error instanceof Error ? error.message : String(error)));
                widgetList.append(alert);
                if (widgetCount) widgetCount.textContent = '0';
            } finally {
                if (currentRequest === widgetRequest) widgetList.removeAttribute('aria-busy');
            }
        };

        const refreshPreview = () => {
            if (!(preview instanceof HTMLIFrameElement)) return;
            const url = new URL(preview.src || root.dataset.previewUrl || 'about:blank', window.location.href);
            if (url.protocol !== 'about:') url.searchParams.set('_w_refresh', String(Date.now()));
            setPreviewBusy(true);
            preview.src = url.toString();
        };

        const navigateForSelection = (select) => {
            if (!(select instanceof HTMLSelectElement)) return;
            const key = select.dataset.wEditorSelect || select.name;
            if (!key) return;
            const url = new URL(window.location.href);
            if (select.value === '') url.searchParams.delete(key);
            else url.searchParams.set(key, select.value);
            url.searchParams.delete('version_id');
            if (key === 'page_type') {
                const options = Array.isArray(layoutOptions[select.value]) ? layoutOptions[select.value] : [];
                const preferred = String(options[0]?.value || 'default');
                url.searchParams.set('layout_option', preferred);
            }
            window.location.assign(url.toString());
        };

        const versionParams = () => ({ ...identity(root), limit: 30 });

        const renderVersions = (result) => {
            if (!(versionList instanceof HTMLElement)) return;
            versionList.replaceChildren();
            const data = result?.data && typeof result.data === 'object' ? result.data : {};
            const versions = Array.isArray(data.versions) ? data.versions : [];
            if (versions.length === 0) {
                versionList.append(element('div', 'w-empty-state', '暂无版本记录'));
                return;
            }
            versions.forEach((version) => {
                const row = element('article', 'w-theme-editor__version');
                const copy = element('div', 'w-theme-editor__version-copy');
                copy.append(
                    element('strong', '', String(version.display_name || version.name || `#${version.version_id || version.id || ''}`)),
                    element('small', '', String(version.description || version.created_at || version.create_time || '')),
                );
                const states = element('div', 'w-cluster');
                const id = String(version.version_id || version.id || '');
                if (id !== '' && String(data.current_version_id ?? '') === id) {
                    const badge = element('span', 'w-badge', '当前');
                    badge.dataset.tone = 'info';
                    states.append(badge);
                }
                if (id !== '' && String(data.published_version_id ?? '') === id) {
                    const badge = element('span', 'w-badge', '已发布');
                    badge.dataset.tone = 'success';
                    states.append(badge);
                }
                row.append(copy, states);
                versionList.append(row);
            });
        };

        const loadVersions = async () => {
            if (!(versionList instanceof HTMLElement) || !root.dataset.apiVersions) return;
            versionList.setAttribute('aria-busy', 'true');
            versionList.replaceChildren(element('div', 'w-spinner'));
            try {
                const result = await themeRequest(requestUrl(root.dataset.apiVersions, versionParams()));
                if (result?.success === false) throw new Error(responseMessage(result, '版本加载失败'));
                renderVersions(result);
            } catch (error) {
                versionList.replaceChildren();
                const alert = element('div', 'w-alert');
                alert.dataset.tone = 'danger';
                alert.textContent = error instanceof Error ? error.message : String(error);
                versionList.append(alert);
            } finally {
                versionList.removeAttribute('aria-busy');
            }
        };

        const publish = async (button) => {
            const accepted = await UI.dialog.confirm('这会发布当前布局草稿并刷新主题缓存。', {
                title: '发布当前主题？',
                confirmLabel: '发布',
                cancelLabel: '取消',
            });
            if (!accepted) return;
            button.disabled = true;
            try {
                const result = await themeRequest(root.dataset.apiPublish, {
                    method: 'POST',
                    body: identity(root),
                });
                if (result?.success === false) throw new Error(responseMessage(result, '主题发布失败'));
                UI.toast.success(responseMessage(result, '主题已发布'));
                refreshPreview();
            } catch (error) {
                UI.toast.error(error instanceof Error ? error.message : String(error));
            } finally {
                button.disabled = false;
            }
        };

        listen(root, 'change', (event) => {
            const select = event.target instanceof Element
                ? event.target.closest('[data-w-editor-select]')
                : null;
            if (select) navigateForSelection(select);
        });
        if (widgetSearch) listen(widgetSearch, 'input', renderWidgets);
        if (preview) {
            listen(preview, 'load', () => setPreviewBusy(false));
            listen(preview, 'error', () => {
                setPreviewBusy(false);
                UI.toast.error('主题预览加载失败');
            });
        }
        listen(root, 'click', (event) => {
            const action = event.target instanceof Element
                ? event.target.closest('[data-w-editor-action]')
                : null;
            if (!(action instanceof HTMLButtonElement)) return;
            const name = action.dataset.wEditorAction || '';
            if (name === 'refresh-preview') refreshPreview();
            if (name === 'open-preview' && preview instanceof HTMLIFrameElement) {
                window.open(preview.src, '_blank', 'noopener,noreferrer');
            }
            if (name === 'versions' && versionsDialog instanceof HTMLDialogElement) {
                UI.dialog.open(versionsDialog);
                loadVersions();
            }
            if (name === 'publish') publish(action);
        });
        if (versionForm) {
            listen(versionForm, 'submit', async (event) => {
                event.preventDefault();
                if (!(versionForm instanceof HTMLFormElement) || !versionForm.reportValidity()) return;
                const submit = versionForm.querySelector('[type="submit"]');
                if (submit instanceof HTMLButtonElement) submit.disabled = true;
                try {
                    const data = new FormData(versionForm);
                    const result = await themeRequest(root.dataset.apiSaveVersion, {
                        method: 'POST',
                        body: {
                            ...identity(root),
                            version_name: String(data.get('version_name') || '').trim(),
                        },
                    });
                    if (result?.success === false) throw new Error(responseMessage(result, '版本保存失败'));
                    versionForm.reset();
                    UI.toast.success(responseMessage(result, '版本已保存'));
                    await loadVersions();
                } catch (error) {
                    UI.toast.error(error instanceof Error ? error.message : String(error));
                } finally {
                    if (submit instanceof HTMLButtonElement) submit.disabled = false;
                }
            });
        }

        setPreviewBusy(preview instanceof HTMLIFrameElement && preview.src !== 'about:blank');
        loadWidgets();

        return {
            refreshPreview,
            loadWidgets,
            loadVersions,
            element: root,
        };
    });
}

function start() {
    if (!Weline.UI) {
        document.addEventListener('weline:ui:ready', start, { once: true });
        return;
    }
    registerThemeEditor(Weline.UI);
    Weline.UI.mount(document);
}

start();
