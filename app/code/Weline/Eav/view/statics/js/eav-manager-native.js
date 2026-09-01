/* Weline UI source: js/eav-manager-native.js */
/* Weline UI source: js/eav-manager-native.js */
/* Weline UI source: js/eav-manager-native.js */
const UI = window.Weline?.UI;

if (UI) {
    UI.define('eav-manager', ({element, listen}) => {
        const configNode = element.querySelector('[data-w-eav-config]');
        const tree = element.querySelector('[data-w-eav-tree]');
        const search = element.querySelector('[data-w-eav-search]');
        const detail = element.querySelector('[data-w-eav-detail]');
        const placeholder = element.querySelector('[data-w-eav-placeholder]');
        const detailHeader = element.querySelector('[data-w-eav-detail-header]');
        const detailHeading = element.querySelector('[data-w-eav-detail-heading]');
        const detailTitle = element.querySelector('[data-w-eav-detail-title]');
        const detailSubtitle = element.querySelector('[data-w-eav-detail-subtitle]');
        const deleteButton = element.querySelector('[data-w-eav-delete]');
        const addButton = element.querySelector('[data-w-eav-add]');
        const addLabel = element.querySelector('[data-w-eav-add-label]');
        let config = {};
        let resourcePromise = null;
        let selected = null;
        let detailSequence = 0;
        let dragPayload = null;
        let dropTargetRow = null;

        try {
            config = JSON.parse(configNode?.textContent || '{}');
        } catch (_error) {
            config = {};
        }

        if (!(tree instanceof HTMLElement)
            || !(detail instanceof HTMLElement)
            || !(placeholder instanceof HTMLElement)
            || !(detailHeader instanceof HTMLElement)) return {};

        const messages = config.messages || {};
        const typeNames = config.types || {};
        const fieldNames = config.fields || {};
        const structureLabels = config.structures || {};
        const iconNames = {entity: 'box', set: 'folder', group: 'folder', attribute: 'tag'};
        const idFields = {entity: 'eav_entity_id', set: 'set_id', group: 'group_id', attribute: 'attribute_id'};
        const detailActions = {entity: 'entityDetail', set: 'setDetail', group: 'groupDetail', attribute: 'attributeDetail'};
        const saveActions = {entity: 'entitySave', set: 'setSave', group: 'groupSave', attribute: 'attributeSave'};
        const structureTone = {
            options: 'accent',
            multiple: 'neutral',
            swatch: 'warning',
            swatch_color: 'warning',
            swatch_image: 'warning',
            swatch_text: 'warning',
        };
        const dragMime = 'application/x-weline-eav-node';
        const dropRules = {
            group: 'set',
            attribute: 'group',
        };
        const entityScope = config.entityScope && typeof config.entityScope === 'object' ? config.entityScope : null;
        const uiMode = String(config.mode || element.dataset.wEavMode || 'manage');
        const productContext = config.productContext && typeof config.productContext === 'object'
            ? config.productContext
            : null;
        const selectBar = element.querySelector('[data-w-eav-select-bar]');
        const selectConfirm = element.querySelector('[data-w-eav-select-confirm]');
        const selectCancel = element.querySelector('[data-w-eav-select-cancel]');
        let selectionPick = null;
        const selectionPicks = new Map();
        const scopedEntityId = () => String(entityScope?.entityId || '');
        const withEntityScope = (params = {}) => {
            const next = {...params};
            if (entityScope?.code) {
                next.entity_code = entityScope.code;
            }
            if (productContext?.productId) {
                next.product_id = productContext.productId;
            }
            if (productContext?.structureScope) {
                next.structure_scope = productContext.structureScope;
            }
            if (productContext?.selectedSetId) {
                next.selected_set_id = productContext.selectedSetId;
            }
            if (uiMode === 'select-set' || uiMode === 'select-attribute') {
                next.selection_mode = uiMode;
            }
            return next;
        };

        const i18nConfig = config.i18n && typeof config.i18n === 'object' ? config.i18n : {};
        const localDescriptors = i18nConfig.descriptors && typeof i18nConfig.descriptors === 'object'
            ? i18nConfig.descriptors
            : {};

        const buildLocalSourceUrl = (descriptor, recordId, value) => {
            if (!i18nConfig.localBase || !descriptor?.model || !descriptor?.field || !recordId) {
                return '';
            }
            const url = new URL(i18nConfig.localBase, window.location.href);
            url.searchParams.set('model', String(descriptor.model));
            url.searchParams.set('field', String(descriptor.field));
            url.searchParams.set('id', String(recordId));
            url.searchParams.set('value', String(value || ''));
            return url.toString();
        };

        const drawerShellHtml = (label) => `
                <div class="w-drawer__header">
                    <h5 class="w-drawer__title">
                        <w-icon name="language" size="sm" aria-hidden="true"></w-icon>
                        <span>${String(messages.localTranslate || '多语言')}</span>
                    </h5>
                    <button class="w-button" type="button" data-w-action="drawer.close" data-w-close data-tone="quiet" data-size="sm" data-icon-only="true" aria-label="${String(messages.cancel || '关闭')}">
                        <w-icon name="close" size="sm"></w-icon>
                    </button>
                </div>
                <div class="w-drawer__body">
                    <div class="w-local-translation" data-w-local-panel
                         data-i18n-empty="${String(messages.localEmpty || '没有找到已启用的语言')}"
                         data-i18n-no-match="${String(messages.localNoMatch || '没有匹配的语言')}"
                         data-i18n-progress="${String(messages.localProgress || '已译 %{filled} / 共 %{total} 种语言')}"
                         data-i18n-filled="${String(messages.localFilled || '已填写')}"
                         data-i18n-pending="${String(messages.localPending || '待填写')}"
                         data-i18n-ai-done="${String(messages.localAiDone || 'AI 翻译完成')}"
                         data-i18n-ai-retranslate="${String(messages.localAiRetranslate || '已有翻译内容，是否重新翻译？')}"
                         data-i18n-ai-retranslate-title="${String(messages.localAiRetranslateTitle || '重新翻译')}">
                        <div class="w-local-translation__toolbar">
                            <div class="w-local-translation__source">
                                <p class="w-local-translation__source-label">${String(messages.sourceText || '原文')}</p>
                                <p class="w-local-translation__source-value" data-w-local-source></p>
                            </div>
                            <div class="w-local-translation__progress-wrap">
                                <div class="w-local-translation__progress-track" aria-hidden="true">
                                    <div class="w-local-translation__progress-bar" data-w-local-progress-bar></div>
                                </div>
                                <span class="w-local-translation__progress-text" data-w-local-progress aria-live="polite"></span>
                            </div>
                            <label class="w-local-translation__search">
                                <w-icon name="search" size="sm" aria-hidden="true"></w-icon>
                                <input class="w-input" type="search" data-w-local-search placeholder="${String(messages.searchLocales || '搜索语言、语言码或翻译')}" autocomplete="off" spellcheck="false">
                            </label>
                        </div>
                        <div class="w-local-translation__list-head" data-w-local-list-head hidden>
                            <span></span>
                            <span>${String(messages.colLanguage || '语言')}</span>
                            <span>${String(messages.colTranslation || '译文')}</span>
                            <span>${String(messages.colStatus || '状态')}</span>
                        </div>
                        <div class="w-local-translation__list" data-w-local-list role="list"></div>
                        <div class="w-local-translation__state" data-w-local-loading hidden>
                            <span class="w-spinner" data-size="sm" aria-hidden="true"></span>
                            <span>${String(messages.loadingLocales || '正在加载语言…')}</span>
                        </div>
                        <div class="w-local-translation__state" data-w-local-empty hidden>
                            <w-icon name="language" size="lg" aria-hidden="true"></w-icon>
                            <p data-w-local-empty-text></p>
                        </div>
                    </div>
                </div>
                <div class="w-drawer__footer w-cluster" data-align="center" data-justify="between" data-gap="sm">
                    <div class="w-cluster" data-gap="sm">
                        <button class="w-button" type="button" data-variant="outline" data-w-local-refresh>
                            <w-icon name="refresh" size="sm"></w-icon>
                            <span>${String(messages.refresh || '刷新')}</span>
                        </button>
                        <button class="w-button" type="button" data-variant="outline" data-tone="primary" data-w-local-ai>
                            <span class="w-spinner" data-w-local-ai-spinner hidden></span>
                            <w-icon name="sparkles" size="sm"></w-icon>
                            <span>${String(messages.localAiTranslate || 'AI翻译')}</span>
                        </button>
                    </div>
                    <div class="w-cluster" data-gap="sm">
                        <button class="w-button" type="button" data-variant="outline" data-w-action="drawer.close">${String(messages.cancel || '取消')}</button>
                        <button class="w-button" type="button" data-tone="primary" data-w-local-submit>
                            <span class="w-spinner" data-w-local-spinner hidden></span>
                            <span>${String(messages.save || '保存')}</span>
                        </button>
                    </div>
                </div>`;

        const applyLocalDrawerContext = (drawer, sourceUrl, context = {}) => {
            drawer.dataset.wSource = sourceUrl;
            drawer.dataset.wLocalAiUrl = String(i18nConfig.localAiTranslate || '');
            drawer.dataset.wLocalAiType = String(context.nodeType || '');
            drawer.dataset.wLocalAiId = String(context.recordId || '');
        };

        const ensureLocalDrawer = (drawerId, sourceUrl, context = {}) => {
            let drawer = element.querySelector(`#${drawerId}`);
            if (drawer instanceof HTMLElement) {
                applyLocalDrawerContext(drawer, sourceUrl, context);
                return drawer;
            }
            drawer = document.createElement('div');
            drawer.className = 'w-drawer w-drawer--dock w-local-translation-drawer';
            drawer.id = drawerId;
            drawer.tabIndex = -1;
            drawer.dataset.wComponent = 'drawer local-translation';
            drawer.dataset.size = 'lg';
            drawer.dataset.state = 'closed';
            drawer.hidden = true;
            drawer.setAttribute('aria-hidden', 'true');
            drawer.innerHTML = drawerShellHtml('');
            element.append(drawer);
            applyLocalDrawerContext(drawer, sourceUrl, context);
            UI.mount(drawer);
            void UI.whenReady(drawer, 'local-translation').catch(() => {});
            return drawer;
        };

        const mountLocalTranslationActions = (labelHost, {nodeType, recordId, value}) => {
            if ((uiMode !== 'manage' && uiMode !== 'select-set') || !recordId || !(labelHost instanceof HTMLElement)) {
                return;
            }
            const descriptor = localDescriptors[nodeType];
            if (!descriptor) {
                return;
            }
            const sourceUrl = buildLocalSourceUrl(descriptor, recordId, value);
            if (sourceUrl === '') {
                return;
            }
            const drawerId = `w-eav-local-${nodeType}-${recordId}-${descriptor.field}`.replace(/[^a-zA-Z0-9_-]/g, '-');
            const actions = document.createElement('span');
            actions.className = 'w-eav-manager__i18n-actions';

            const localLink = document.createElement('a');
            localLink.className = 'w-eav-manager__i18n-link';
            localLink.href = '#';
            localLink.textContent = String(messages.localTranslate || 'Translate');
            localLink.dataset.wAction = 'drawer.open';
            localLink.dataset.wTarget = `#${drawerId}`;
            listen(localLink, 'click', (event) => {
                event.preventDefault();
                ensureLocalDrawer(drawerId, sourceUrl, {nodeType, recordId});
            });

            const aiLink = document.createElement('a');
            aiLink.className = 'w-eav-manager__i18n-link';
            aiLink.href = '#';
            aiLink.textContent = String(messages.localAiTranslate || 'AI Translate');
            listen(aiLink, 'click', async (event) => {
                event.preventDefault();
                if (!i18nConfig.localAiTranslate) {
                    return;
                }
                aiLink.setAttribute('aria-disabled', 'true');
                aiLink.classList.add('is-busy');
                try {
                    const payload = new FormData();
                    payload.set('type', nodeType);
                    payload.set('id', String(recordId));
                    const response = await fetch(i18nConfig.localAiTranslate, {
                        method: 'POST',
                        body: payload,
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json'},
                    });
                    const result = await response.json();
                    if (!response.ok || !result?.success) {
                        throw new Error(String(result?.message || messages.loadFailed || 'Failed'));
                    }
                    UI.toast.success(String(result.message || messages.localAiDone || 'Done'));
                } catch (error) {
                    UI.toast.error(errorMessage(error));
                } finally {
                    aiLink.removeAttribute('aria-disabled');
                    aiLink.classList.remove('is-busy');
                }
            });

            actions.append(localLink, aiLink);
            labelHost.append(actions);
            ensureLocalDrawer(drawerId, sourceUrl, {nodeType, recordId});
        };

        const createIcon = (name, size = 'sm') => {
            const icon = document.createElement('w-icon');
            icon.setAttribute('name', name);
            icon.setAttribute('size', size);
            icon.setAttribute('aria-hidden', 'true');
            return icon;
        };
        const status = (message, tone = 'muted', spinning = false) => {
            const box = document.createElement('div');
            box.className = 'w-eav-manager__status';
            if (tone !== 'muted') box.dataset.tone = tone;
            const visual = spinning ? document.createElement('span') : createIcon(tone === 'danger' ? 'warning' : 'info', 'lg');
            if (spinning) visual.className = 'w-spinner';
            const text = document.createElement('span');
            text.textContent = String(message || '');
            box.append(visual, text);
            return box;
        };
        const errorMessage = (error) => {
            const candidates = [
                error?.response?.data?.data?.message,
                error?.response?.data?.message,
                error?.data?.message,
                error?.message,
            ];
            return String(candidates.find((value) => typeof value === 'string' && value.trim() !== '') || messages.unknownError || 'Error');
        };
        const normalizeBusiness = (response) => {
            let value = response;
            for (let depth = 0; depth < 3; depth += 1) {
                if (!value || typeof value !== 'object' || Array.isArray(value)) break;
                if (Object.prototype.hasOwnProperty.call(value, 'success')) return value;
                if (!value.data || typeof value.data !== 'object') break;
                value = value.data;
            }
            return value && typeof value === 'object' ? value : {success: true, data: value};
        };
        const resource = () => {
            resourcePromise ||= window.Weline.load('api').then((api) => api.resource('eav_admin'));
            return resourcePromise;
        };
        const request = async (action, method = 'GET', params = {}) => {
            const upperMethod = method.toUpperCase();
            const scopedParams = withEntityScope(params);
            const values = new URLSearchParams();
            Object.entries(scopedParams).forEach(([name, value]) => {
                if (value !== undefined && value !== null) values.set(name, String(value));
            });
            const query = upperMethod === 'GET' && values.size > 0 ? `?${values.toString()}` : '';
            const response = await (await resource()).adminRequest({
                url: `${String(config.apiBase || '').replace(/\/$/, '')}/${action}${query}`,
                method: upperMethod,
                headers: upperMethod === 'GET' ? {} : {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: upperMethod === 'GET' ? '' : values.toString(),
            });
            const business = normalizeBusiness(response);
            if (business.success === false) throw new Error(String(business.message || messages.unknownError || 'Error'));
            return business.data;
        };
        const setBusy = (target, busy) => {
            if (!(target instanceof HTMLElement)) return;
            target.toggleAttribute('aria-busy', busy);
            if ('disabled' in target) target.disabled = busy;
        };
        const showError = async (title, error) => {
            await UI.dialog.request({
                tone: 'danger',
                title: String(title || messages.loadFailed || 'Error'),
                message: errorMessage(error),
                confirmLabel: messages.confirm || 'OK',
            });
        };

        const buildTreeItem = (node) => {
            const item = document.createElement('li');
            const nodeType = String(node?.type || '');
            const lazy = Boolean(node?.lazy);
            item.className = 'w-eav-manager__tree-item';
            if (uiMode === 'select-set') {
                if (nodeType === 'set') {
                    item.classList.add('is-selectable');
                } else if (nodeType === 'group' || nodeType === 'attribute') {
                    item.classList.add('is-previewable');
                }
            }
            item.setAttribute('role', 'treeitem');
            item.dataset.wEavType = String(node?.type || '');
            item.dataset.wEavId = String(node?.nodeId ?? node?.id ?? '');
            item.dataset.wEavNode = JSON.stringify(node || {});
            if (lazy) item.setAttribute('aria-expanded', 'false');

            const row = document.createElement('div');
            row.className = 'w-tree__row w-eav-manager__tree-row';
            if (nodeType === 'set' || nodeType === 'group') {
                row.dataset.wEavDrop = nodeType;
            }
            if ((uiMode === 'manage' || uiMode === 'select-set') && (nodeType === 'group' || nodeType === 'attribute')) {
                row.dataset.wEavDrag = nodeType;
            }
            const toggle = document.createElement('button');
            toggle.className = 'w-eav-manager__tree-toggle';
            toggle.type = 'button';
            toggle.dataset.wTreeToggle = '';
            toggle.disabled = !lazy;
            toggle.setAttribute('aria-label', String(node?.name || node?.code || ''));
            toggle.append(createIcon('chevron-right'));

            const entry = document.createElement('div');
            entry.className = 'w-eav-manager__tree-entry';

            if (uiMode === 'select-attribute' && nodeType === 'attribute') {
                const pick = document.createElement('input');
                pick.className = 'w-eav-manager__tree-pick';
                pick.type = 'checkbox';
                pick.dataset.wEavPick = '';
                pick.setAttribute('aria-label', String(node?.name || node?.code || ''));
                entry.append(pick);
            }

            const selectButton = document.createElement('button');
            selectButton.className = 'w-eav-manager__tree-select';
            selectButton.type = 'button';
            selectButton.dataset.wEavSelect = '';

            const main = document.createElement('span');
            main.className = 'w-eav-manager__tree-select-main';
            main.append(createIcon(iconNames[node?.type] || 'file'));
            const name = document.createElement('span');
            name.className = 'w-eav-manager__tree-name';
            name.textContent = String(node?.name || node?.code || '');
            main.append(name);
            if (node?.code) {
                const code = document.createElement('span');
                code.className = 'w-eav-manager__tree-code';
                code.textContent = String(node.code);
                main.append(code);
            }
            if (node?.isSystem) {
                const badge = document.createElement('span');
                badge.className = 'w-badge';
                badge.dataset.tone = 'info';
                badge.textContent = String(messages.system || 'System');
                main.append(badge);
            }
            if (node?.type === 'attribute' && Number(node?.optionCount || 0) > 0) {
                const count = document.createElement('span');
                count.className = 'w-eav-manager__tree-meta';
                count.textContent = String(node.optionCount);
                main.append(count);
            }
            if ((uiMode === 'manage' || uiMode === 'select-set') && (nodeType === 'group' || nodeType === 'attribute')) {
                main.draggable = true;
                main.dataset.wEavDrag = nodeType;
            }
            selectButton.append(main);

            const structures = Array.isArray(node?.structures) ? node.structures : [];
            if (structures.length > 0) {
                const structuresRow = document.createElement('div');
                structuresRow.className = 'w-eav-manager__tree-structures';
                structures.forEach((structure) => {
                    const badge = document.createElement('span');
                    badge.className = 'w-badge';
                    badge.dataset.tone = structureTone[structure] || 'neutral';
                    badge.textContent = String(structureLabels[structure] || structure);
                    structuresRow.append(badge);
                });
                selectButton.append(structuresRow);
            }
            entry.append(selectButton);
            row.append(toggle, entry);
            item.append(row);

            if (lazy || (Array.isArray(node?.children) && node.children.length > 0)) {
                const group = document.createElement('ul');
                group.setAttribute('role', 'group');
                group.hidden = true;
                if (Array.isArray(node?.children) && node.children.length > 0) {
                    node.children.forEach((child) => group.append(buildTreeItem(child)));
                    item.dataset.wEavLoaded = 'true';
                }
                item.append(group);
            }
            return item;
        };

        const renderTree = (nodes) => {
            tree.replaceChildren();
            const values = Array.isArray(nodes) ? nodes : [];
            if (values.length === 0) {
                const item = document.createElement('li');
                item.append(status(messages.empty || 'No data'));
                tree.append(item);
            } else {
                values.forEach((node) => tree.append(buildTreeItem(node)));
            }
            tree.setAttribute('aria-busy', 'false');
            UI.mount(tree);
            updateAddButton();
        };

        const loadTree = async () => {
            tree.setAttribute('aria-busy', 'true');
            tree.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            try {
                const nodes = await request('tree');
                if (!element.isConnected) return;
                renderTree(nodes);
                if (uiMode === 'manage' && !defaultGroupExpanded) {
                    defaultGroupExpanded = true;
                    await expandDefaultGroup();
                }
            } catch (error) {
                if (!element.isConnected) return;
                tree.setAttribute('aria-busy', 'false');
                tree.replaceChildren(status(`${messages.loadFailed || 'Load failed'}: ${errorMessage(error)}`, 'danger'));
            }
        };

        const loadChildren = async (item) => {
            if (!(item instanceof HTMLElement) || item.dataset.wEavLoaded === 'true' || item.dataset.wEavLoaded === 'loading') return;
            const group = item.querySelector(':scope > [role="group"]');
            if (!(group instanceof HTMLElement)) return;
            item.dataset.wEavLoaded = 'loading';
            group.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            try {
                const children = await request('children', 'GET', {
                    type: item.dataset.wEavType || '',
                    id: item.dataset.wEavId || '',
                });
                group.replaceChildren();
                if (Array.isArray(children) && children.length > 0) {
                    children.forEach((node) => group.append(buildTreeItem(node)));
                    item.dataset.wEavLoaded = 'true';
                    UI.mount(group);
                } else {
                    item.dataset.wEavLoaded = 'true';
                    item.removeAttribute('aria-expanded');
                    item.querySelector(':scope > .w-eav-manager__tree-row > .w-eav-manager__tree-toggle')?.setAttribute('disabled', '');
                    group.remove();
                }
            } catch (error) {
                delete item.dataset.wEavLoaded;
                item.setAttribute('aria-expanded', 'false');
                group.hidden = true;
                group.replaceChildren();
                UI.toast.error(errorMessage(error));
            }
        };

        const expandTreeItem = async (item) => {
            if (!(item instanceof HTMLElement)) return;
            item.setAttribute('aria-expanded', 'true');
            const group = item.querySelector(':scope > [role="group"]');
            if (group instanceof HTMLElement) group.hidden = false;
            await loadChildren(item);
        };

        const firstTreeItem = (type, root = tree) => {
            if (!(root instanceof HTMLElement)) return null;
            return root.querySelector(`:scope [role="treeitem"][data-w-eav-type="${type}"]:not([hidden])`)
                || root.querySelector(`[role="treeitem"][data-w-eav-type="${type}"]:not([hidden])`);
        };

        const expandDefaultGroup = async () => {
            if (entityScope?.entityId) {
                const set = firstTreeItem('set');
                if (!(set instanceof HTMLElement)) return;
                await expandTreeItem(set);
                const group = firstTreeItem('group', set);
                if (group instanceof HTMLElement) await expandTreeItem(group);
                return;
            }
            const entity = firstTreeItem('entity');
            if (!(entity instanceof HTMLElement)) return;
            await expandTreeItem(entity);
            const set = firstTreeItem('set', entity);
            if (!(set instanceof HTMLElement)) return;
            await expandTreeItem(set);
            const group = firstTreeItem('group', set);
            if (group instanceof HTMLElement) await expandTreeItem(group);
        };

        let defaultGroupExpanded = false;

        const findTreeItem = (type, id, root = tree) => {
            if (!type || id === undefined || id === null || id === '') return null;
            if (!(root instanceof HTMLElement)) return null;
            return root.querySelector(`[role="treeitem"][data-w-eav-type="${type}"][data-w-eav-id="${String(id)}"]`);
        };

        const ensureNodeVisible = async (type, id, context = {}) => {
            const expandById = async (nodeType, nodeId) => {
                if (!nodeId) return null;
                const item = findTreeItem(nodeType, nodeId);
                if (item instanceof HTMLElement) {
                    await expandTreeItem(item);
                }
                return item;
            };

            if (type === 'attribute') {
                await expandById('entity', context.eav_entity_id);
                await expandById('set', context.set_id);
                await expandById('group', context.group_id);
            } else if (type === 'group') {
                await expandById('entity', context.eav_entity_id);
                await expandById('set', context.set_id);
            } else if (type === 'set') {
                await expandById('entity', context.eav_entity_id);
            }

            let item = findTreeItem(type, id);
            if (!(item instanceof HTMLElement) && type === 'attribute' && context.group_id) {
                const group = findTreeItem('group', context.group_id);
                if (group instanceof HTMLElement) {
                    delete group.dataset.wEavLoaded;
                    await expandTreeItem(group);
                    item = findTreeItem(type, id, group);
                }
            }
            return item instanceof HTMLElement ? item : null;
        };

        const parseNode = (item) => {
            try {
                return JSON.parse(item?.dataset?.wEavNode || '{}');
            } catch (_error) {
                return {};
            }
        };

        const resolveSetTreeItem = (item) => {
            if (!(item instanceof HTMLElement)) {
                return null;
            }
            const type = item.dataset.wEavType || '';
            if (type === 'set') {
                return item;
            }
            let current = item.parentElement?.closest('[role="treeitem"]');
            while (current instanceof HTMLElement) {
                if (current.dataset.wEavType === 'set') {
                    return current;
                }
                current = current.parentElement?.closest('[role="treeitem"]');
            }
            const node = parseNode(item);
            const setId = String(node?.setId || '');
            if (setId === '') {
                return null;
            }
            const matched = tree.querySelector(`[role="treeitem"][data-w-eav-type="set"][data-w-eav-id="${setId}"]`);
            return matched instanceof HTMLElement ? matched : null;
        };
        const addChildTypeForSelection = () => {
            const selectedType = selected?.type || '';
            const fixedEntity = scopedEntityId();
            const structureScope = String(productContext?.structureScope || 'catalog');

            if (structureScope === 'free') {
                if (selectedType === 'group' || selectedType === 'attribute') {
                    return 'attribute';
                }
                return selectedType ? null : 'group';
            }

            if (selectedType === 'entity') {
                return 'set';
            }
            if (selectedType === 'set') {
                return 'group';
            }
            if (selectedType === 'group' || selectedType === 'attribute') {
                return 'attribute';
            }
            if (fixedEntity && !productContext?.lockSet) {
                return 'set';
            }
            return null;
        };

        const formatAddLabel = (type) => String(messages.addItem || messages.add || 'Add')
            .replace('%{1}', typeNames[type] || type);

        const updateAddButton = () => {
            if (!(addButton instanceof HTMLButtonElement)) {
                return;
            }
            if (uiMode === 'select-attribute') {
                addButton.hidden = true;
                addButton.disabled = true;
                delete addButton.dataset.wEavAdd;
                if (deleteButton instanceof HTMLButtonElement) {
                    deleteButton.hidden = true;
                }
                return;
            }
            addButton.hidden = false;
            const childType = addChildTypeForSelection();
            const visible = Boolean(childType);
            addButton.disabled = !visible;
            if (childType) {
                addButton.dataset.wEavAdd = childType;
                if (addLabel instanceof HTMLElement) {
                    addLabel.textContent = formatAddLabel(childType);
                }
            } else {
                delete addButton.dataset.wEavAdd;
                if (addLabel instanceof HTMLElement) {
                    addLabel.textContent = String(messages.add || 'Add');
                }
            }
        };

        const appendQuickAddPanel = (fields, type, isNew) => {
            if (!(fields instanceof HTMLElement) || isNew) {
                return;
            }
            const childType = addChildTypeForSelection();
            if (!childType) {
                return;
            }
            const panel = document.createElement('section');
            panel.className = 'w-eav-manager__quick-add';
            const copy = document.createElement('p');
            copy.className = 'w-eav-manager__quick-add-copy';
            copy.textContent = String(messages.addHint || formatAddLabel(childType));
            const action = document.createElement('button');
            action.className = 'w-button';
            action.type = 'button';
            action.dataset.tone = 'primary';
            action.append(createIcon('plus'));
            const label = document.createElement('span');
            label.textContent = formatAddLabel(childType);
            action.append(label);
            listen(action, 'click', () => createNode());
            panel.append(copy, action);
            fields.append(panel);
        };

        const emitSelection = (name, detail) => {
            element.dispatchEvent(new CustomEvent(name, {bubbles: true, detail}));
            document.dispatchEvent(new CustomEvent(name, {bubbles: true, detail}));
        };

        const applySelectModeUi = () => {
            if (uiMode !== 'select-set' && uiMode !== 'select-attribute') {
                return;
            }
            const toolbar = element.querySelector('[data-w-eav-tree-toolbar]');
            if (toolbar instanceof HTMLElement) {
                if (uiMode === 'select-set') {
                    toolbar.textContent = String(messages.selectSetHint || 'Click an attribute set to select it.');
                } else {
                    toolbar.textContent = String(messages.selectAttributeHint || 'Expand sets and pick attributes to add.');
                }
            }
            if (selectBar instanceof HTMLElement) {
                selectBar.hidden = false;
            }
            if (uiMode === 'select-attribute') {
                if (placeholder instanceof HTMLElement) {
                    placeholder.hidden = true;
                }
                if (detail instanceof HTMLElement) {
                    detail.hidden = true;
                }
                if (detailHeading instanceof HTMLElement) {
                    detailHeading.hidden = true;
                }
            }
        };

        const updateAttributePickUi = (item, picked) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const pick = item.querySelector('[data-w-eav-pick]');
            if (pick instanceof HTMLInputElement) {
                pick.checked = picked;
            }
            item.classList.toggle('is-picked', picked);
            item.querySelector(':scope > .w-eav-manager__tree-row [data-w-eav-select]')
                ?.toggleAttribute('aria-current', picked);
        };

        const updateSelectConfirmState = () => {
            if (!(selectConfirm instanceof HTMLButtonElement)) {
                return;
            }
            if (uiMode === 'select-set') {
                selectConfirm.disabled = !(selectionPick && selectionPick.type === 'set');
                return;
            }
            if (uiMode !== 'select-attribute') {
                return;
            }
            const base = String(messages.selectAttributeConfirm || 'Confirm');
            const count = selectionPicks.size;
            selectConfirm.textContent = count > 0 ? `${base} (${count})` : base;
            selectConfirm.disabled = count === 0;
        };

        const syncAttributePickFromCheckbox = (item, picked) => {
            if (!(item instanceof HTMLElement) || item.dataset.wEavType !== 'attribute') {
                return;
            }
            const id = item.dataset.wEavId || '';
            if (id === '') {
                return;
            }
            if (picked) {
                selectionPicks.set(id, {
                    type: 'attribute',
                    id,
                    node: parseNode(item),
                });
            } else {
                selectionPicks.delete(id);
            }
            updateAttributePickUi(item, picked);
            updateSelectConfirmState();
        };

        const toggleAttributePick = (item) => {
            if (!(item instanceof HTMLElement) || item.dataset.wEavType !== 'attribute') {
                return;
            }
            const id = item.dataset.wEavId || '';
            if (id === '') {
                return;
            }
            const picked = !selectionPicks.has(id);
            syncAttributePickFromCheckbox(item, picked);
        };

        const syncSelectSetPick = (item) => {
            if (!(item instanceof HTMLElement)) {
                selectionPick = null;
                updateSelectConfirmState();
                return;
            }
            const setItem = resolveSetTreeItem(item);
            tree.querySelectorAll('.w-eav-manager__tree-item.is-selected').forEach((el) => el.classList.remove('is-selected'));
            if (!(setItem instanceof HTMLElement)) {
                selectionPick = null;
                updateSelectConfirmState();
                return;
            }
            setItem.classList.add('is-selected');
            selectionPick = {
                type: 'set',
                id: setItem.dataset.wEavId || '',
                node: parseNode(setItem),
            };
            updateSelectConfirmState();
        };

        const pickSelectSetNode = async (item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const type = item.dataset.wEavType || '';
            if (type === 'entity') {
                await selectItem(item);
                selectionPick = null;
                updateSelectConfirmState();
                return;
            }
            await selectItem(item);
            syncSelectSetPick(item);
        };

        const pickSelectionNode = (item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const type = item.dataset.wEavType || '';
            if (uiMode === 'select-attribute' && type !== 'attribute') {
                return;
            }
            if (uiMode === 'select-attribute') {
                toggleAttributePick(item);
                return;
            }
            tree.querySelectorAll('[data-w-eav-select][aria-current="true"]').forEach((button) => button.removeAttribute('aria-current'));
            tree.querySelectorAll('.w-eav-manager__tree-item.is-selected').forEach((el) => el.classList.remove('is-selected'));
            item.classList.add('is-selected');
            item.querySelector(':scope > .w-eav-manager__tree-row [data-w-eav-select]')?.setAttribute('aria-current', 'true');
            selectionPick = {
                type,
                id: item.dataset.wEavId || '',
                node: parseNode(item),
            };
            updateSelectConfirmState();
        };

        const confirmSelection = async () => {
            if (!selectionPick) {
                UI.toast.warning(messages.selectContext || 'Select a node first.');
                return;
            }
            if (uiMode === 'select-set') {
                emitSelection('weline:eav:select-set', {
                    setId: selectionPick.id,
                    code: selectionPick.node?.code || '',
                    name: selectionPick.node?.name || selectionPick.node?.code || '',
                    source: element,
                });
                UI.toast.success(messages.selectSetDone || 'Selected');
                return;
            }
            if (uiMode === 'select-attribute') {
                if (selectionPicks.size === 0) {
                    UI.toast.warning(messages.selectContext || 'Select a node first.');
                    return;
                }
                emitSelection('weline:eav:select-attribute', {
                    attributes: [...selectionPicks.values()].map((entry) => ({
                        attributeId: entry.id,
                        code: entry.node?.code || '',
                        name: entry.node?.name || entry.node?.code || '',
                    })),
                    source: element,
                });
                selectionPicks.clear();
                tree.querySelectorAll('.w-eav-manager__tree-item.is-picked').forEach((pickedItem) => {
                    updateAttributePickUi(pickedItem, false);
                });
                updateSelectConfirmState();
                UI.toast.success(messages.selectAttributeDone || 'Selected');
            }
        };

        const resolveAddContext = (type) => {
            const node = selected?.node || {};
            const selectedType = selected?.type || '';
            const fixedEntityId = scopedEntityId();
            if (type === 'entity') {
                return fixedEntityId ? null : {values: {}, missing: []};
            }
            const entityId = fixedEntityId || (selectedType === 'entity' ? selected?.id : node.entityId);
            if (!entityId) {
                return null;
            }
            const values = {eav_entity_id: entityId};
            const missing = [];
            if (type === 'set') {
                return {values, missing};
            }
            let setId = selectedType === 'set' ? selected?.id : node.setId;
            if (selectedType === 'attribute' && !setId) {
                setId = node.setId;
            }
            if (!setId) {
                missing.push('set_id');
            } else {
                values.set_id = setId;
            }
            if (type === 'group') {
                return {values, missing};
            }
            let groupId = selectedType === 'group' ? selected?.id : node.groupId;
            if (selectedType === 'attribute' && !groupId) {
                groupId = node.groupId;
            }
            if (!groupId) {
                missing.push('group_id');
            } else {
                values.group_id = groupId;
            }
            if (type === 'attribute') {
                return {values, missing};
            }
            return null;
        };

        const childOptions = async (parentType, parentId) => {
            const children = await request('children', 'GET', {type: parentType, id: parentId});
            return (Array.isArray(children) ? children : []).map((child) => ({
                value: String(child?.nodeId ?? child?.id ?? ''),
                label: String(child?.name || child?.code || child?.nodeId || child?.id || ''),
            })).filter((option) => option.value !== '');
        };

        const appendParentPickers = async (form, values, missing) => {
            const fields = form.querySelector('.w-eav-manager__form-fields');
            if (!(fields instanceof HTMLElement) || missing.length === 0) {
                return;
            }
            const entityId = values.eav_entity_id;
            if (missing.includes('set_id') && entityId) {
                const options = await childOptions('entity', entityId);
                if (options.length === 0) {
                    throw new Error(String(messages.selectSet || messages.selectContext || 'Select attribute set'));
                }
                const field = inputField({
                    name: 'set_id',
                    required: true,
                    label: fieldNames.set_id || messages.selectSet || 'set_id',
                    options,
                }, values);
                fields.prepend(field);
                const select = field.querySelector('select');
                if (select instanceof HTMLSelectElement && missing.includes('group_id')) {
                    listen(select, 'change', async () => {
                        values.set_id = select.value;
                        const nextMissing = missing.filter((name) => name !== 'set_id');
                        form.querySelectorAll('[data-w-eav-parent-picker]').forEach((node) => node.remove());
                        await appendParentPickers(form, values, nextMissing);
                    });
                }
                field.dataset.wEavParentPicker = 'set_id';
                return;
            }
            if (missing.includes('group_id') && values.set_id) {
                const options = await childOptions('set', values.set_id);
                if (options.length === 0) {
                    throw new Error(String(messages.selectGroup || messages.selectContext || 'Select attribute group'));
                }
                const field = inputField({
                    name: 'group_id',
                    required: true,
                    label: fieldNames.group_id || messages.selectGroup || 'group_id',
                    options,
                }, values);
                fields.prepend(field);
                field.dataset.wEavParentPicker = 'group_id';
            }
        };

        const inputField = (definition, values, i18nContext = null) => {
            const field = document.createElement('label');
            field.className = 'w-field';
            const labelRow = document.createElement('span');
            labelRow.className = 'w-field__label-row';
            const label = document.createElement('span');
            label.className = 'w-field__label';
            label.textContent = String(fieldNames[definition.name] || definition.label || definition.name);
            labelRow.append(label);
            field.append(labelRow);

            const resolveFieldValue = () => {
                if (definition.name === 'name') {
                    const localized = String(values.local_name ?? '').trim();
                    if (localized !== '') {
                        return localized;
                    }
                }
                return String(values[definition.name] ?? definition.defaultValue ?? '');
            };
            const fieldValue = resolveFieldValue();

            let control;
            if (definition.options) {
                control = document.createElement('select');
                control.className = 'w-select';
                for (const optionValue of definition.options) {
                    const option = document.createElement('option');
                    option.value = String(optionValue.value ?? '');
                    option.textContent = String(optionValue.label ?? optionValue.value ?? '');
                    control.append(option);
                }
            } else {
                control = document.createElement('input');
                control.className = 'w-input';
                control.type = definition.type || 'text';
                if (definition.min !== undefined) control.min = String(definition.min);
                if (definition.max !== undefined) control.max = String(definition.max);
            }
            control.name = definition.name;
            control.value = fieldValue;
            control.required = Boolean(definition.required);
            control.disabled = Boolean(definition.disabled);
            if (control instanceof HTMLInputElement) control.readOnly = Boolean(definition.readonly);
            if (definition.disabled && definition.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = definition.name;
                hidden.value = fieldValue;
                field.append(hidden);
            }
            field.append(control);
            if (i18nContext && definition.name === i18nContext.fieldName) {
                mountLocalTranslationActions(labelRow, {
                    nodeType: i18nContext.nodeType,
                    recordId: i18nContext.recordId,
                    value: String(i18nContext.sourceValue || values.source_name || values.name || fieldValue),
                });
            }
            return field;
        };

        const typeSupportsOptions = (typeMeta) => {
            if (!typeMeta) return false;
            if (typeMeta.hasOption === true) return true;
            const element = String(typeMeta.element || '').toLowerCase();
            return element === 'select' || element === 'multiselect' || element === 'radio';
        };

        const attributeHasOptions = (values, typeMeta) => {
            if (Boolean(Number(values?.data_has_option || 0))) {
                return true;
            }
            return typeSupportsOptions(typeMeta);
        };

        const buildOptionPreviewRow = (options, max = 8) => {
            const row = document.createElement('div');
            row.className = 'w-eav-manager__tree-option-preview';
            const items = Array.isArray(options) ? options : [];
            if (items.length === 0) {
                return row;
            }
            items.slice(0, max).forEach((option) => {
                const chip = document.createElement('span');
                chip.className = 'w-eav-manager__tree-option-chip';
                chip.textContent = String(option?.value || option?.label || option?.code || '');
                row.append(chip);
            });
            if (items.length > max) {
                const more = document.createElement('span');
                more.className = 'w-eav-manager__tree-option-more';
                more.textContent = '+' + String(items.length - max);
                row.append(more);
            }
            return row;
        };

        const clearTreeOptionPreviews = () => {
            tree.querySelectorAll('.w-eav-manager__tree-option-preview').forEach((node) => node.remove());
        };

        const updateTreeOptionPreview = (item, options) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const selectButton = item.querySelector('[data-w-eav-select]');
            if (!(selectButton instanceof HTMLElement)) {
                return;
            }
            selectButton.querySelector('.w-eav-manager__tree-option-preview')?.remove();
            const row = buildOptionPreviewRow(options);
            if (row.childElementCount > 0) {
                selectButton.append(row);
            }
        };

        const resolveAttributeTypeMeta = (values, attributeTypes) => {
            const typeId = Number(values.type_id || 0);
            const fromList = attributeTypes.find((entry) => Number(entry.value) === typeId);
            if (fromList) return fromList;
            if (typeId <= 0) return null;
            return {
                value: typeId,
                label: String(values.type_name || typeId),
                element: String(values.type_element || values.element || ''),
                hasOption: typeSupportsOptions({element: values.type_element || values.element || ''}),
                swatchColor: Boolean(Number(values.type_swatch_color || 0)),
                swatchImage: Boolean(Number(values.type_swatch_image || 0)),
                swatchText: Boolean(Number(values.type_swatch_text || 0)),
            };
        };

        const optionEditorRow = (index, row, typeMeta, readOnly = false) => {
            const item = document.createElement('div');
            item.className = 'w-eav-manager__options-editor-row';
            item.dataset.wEavOptionRow = '';

            if (row?.option_id) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `options[${index}][option_id]`;
                hidden.value = String(row.option_id);
                item.append(hidden);
            }

            const addField = (name, label, inputType = 'text') => {
                const field = document.createElement('label');
                field.className = 'w-field w-eav-manager__option-field';
                const caption = document.createElement('span');
                caption.className = 'w-field__label';
                caption.textContent = String(label);
                const input = document.createElement('input');
                input.className = inputType === 'color' ? 'w-eav-manager__option-color' : 'w-input';
                input.type = inputType;
                input.name = `options[${index}][${name}]`;
                input.value = String(row?.[name] ?? '');
                if (name === 'code') input.required = !readOnly;
                if (readOnly) {
                    input.readOnly = true;
                    input.disabled = true;
                }
                field.append(caption, input);
                item.append(field);
                return field;
            };

            addField('code', fieldNames.option_code || messages.optionCode || 'Code', 'text');
            const valueField = addField('value', fieldNames.option_value || messages.optionLabel || 'Label', 'text');
            if (row?.option_id) {
                mountLocalTranslationActions(valueField.querySelector('.w-field__label-row'), {
                    nodeType: 'option',
                    recordId: Number(row.option_id),
                    value: String(row?.value ?? ''),
                });
            }
            if (typeMeta?.swatchColor) {
                addField('swatch_color', fieldNames.swatch_color || structureLabels.swatch_color || 'Color', 'color');
            }
            if (typeMeta?.swatchImage) {
                addField('swatch_image', fieldNames.swatch_image || structureLabels.swatch_image || 'Image', 'text');
            }
            if (typeMeta?.swatchText) {
                addField('swatch_text', fieldNames.swatch_text || structureLabels.swatch_text || 'Text', 'text');
            }

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'w-button w-eav-manager__option-remove';
            remove.dataset.variant = 'outline';
            remove.dataset.tone = 'danger';
            remove.dataset.size = 'sm';
            remove.dataset.wEavRemoveOption = '';
            remove.append(createIcon('trash'));
            const removeLabel = document.createElement('span');
            removeLabel.textContent = String(messages.removeOption || messages.delete || 'Remove');
            remove.append(removeLabel);
            if (!readOnly) {
                item.append(remove);
            }
            return item;
        };

        const optionsEditorPanel = (values, typeMeta, onRowsChange, readOnly = false) => {
            const panel = document.createElement('section');
            panel.className = 'w-eav-manager__options';
            panel.dataset.wEavOptionsEditor = '';

            const heading = document.createElement('div');
            heading.className = 'w-eav-manager__options-head';
            const title = document.createElement('h3');
            title.textContent = String(messages.optionsTitle || fieldNames.data_has_option || 'Options');
            const meta = document.createElement('span');
            meta.dataset.wEavOptionsCount = '';
            heading.append(title, meta);
            panel.append(heading);

            const toolbar = document.createElement('div');
            toolbar.className = 'w-eav-manager__options-toolbar';
            const hint = document.createElement('p');
            hint.className = 'w-eav-manager__options-hint';
            hint.textContent = String(messages.optionsEditHint || 'Add option items for this attribute type.');
            const addButton = document.createElement('button');
            addButton.type = 'button';
            addButton.className = 'w-button';
            addButton.dataset.tone = 'primary';
            addButton.dataset.size = 'sm';
            addButton.dataset.wEavAddOption = '';
            addButton.append(createIcon('plus'));
            const addLabel = document.createElement('span');
            addLabel.textContent = String(messages.addOption || messages.add || 'Add');
            addButton.append(addLabel);
            toolbar.append(hint);
            if (!readOnly) {
                toolbar.append(addButton);
            }
            panel.append(toolbar);

            const list = document.createElement('div');
            list.className = 'w-eav-manager__options-editor';
            list.dataset.wEavOptionsList = '';
            let optionIndex = 0;

            const refreshCount = () => {
                const count = list.querySelectorAll('[data-w-eav-option-row]').length;
                meta.textContent = String(messages.optionsCount || '%{1} 项').replace('%{1}', String(count));
                onRowsChange?.(count);
            };

            const appendRow = (row = {}) => {
                list.append(optionEditorRow(optionIndex, row, typeMeta, readOnly));
                optionIndex += 1;
                refreshCount();
            };

            const rows = Array.isArray(values.options) ? values.options : [];
            if (rows.length > 0) {
                rows.forEach((row) => appendRow(row));
            } else if (!readOnly) {
                appendRow();
            }

            if (!readOnly) {
                listen(addButton, 'click', () => appendRow());
                listen(list, 'click', (event) => {
                    const button = event.target instanceof Element
                        ? event.target.closest('[data-w-eav-remove-option]')
                        : null;
                    const row = button?.closest('[data-w-eav-option-row]');
                    if (!(row instanceof HTMLElement)) return;
                    row.remove();
                    if (list.querySelectorAll('[data-w-eav-option-row]').length === 0) {
                        appendRow();
                    } else {
                        refreshCount();
                    }
                });
            }

            panel.append(list);
            refreshCount();
            return panel;
        };

        const mountAttributeOptionsSection = (fields, values, typeMeta, readOnly = false) => {
            fields.querySelector('[data-w-eav-options-section]')?.remove();
            if (!attributeHasOptions(values, typeMeta)) {
                return null;
            }
            const panel = readOnly
                ? optionsPanel(values)
                : optionsEditorPanel(values, typeMeta, undefined, readOnly);
            if (panel instanceof HTMLElement) {
                panel.dataset.wEavOptionsSection = '';
                fields.append(panel);
            }
            return panel;
        };

        const switchField = (name, values, disabled = false) => {
            const label = document.createElement('label');
            label.className = 'w-eav-manager__switch';
            const text = document.createElement('span');
            text.textContent = String(fieldNames[name] || name);
            const switchWrap = document.createElement('span');
            switchWrap.className = 'w-switch';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = name;
            input.value = '1';
            input.checked = Boolean(Number(values[name] || 0));
            input.disabled = disabled;
            switchWrap.append(input);
            label.append(text, switchWrap);
            return label;
        };

        const compareModeField = (values, disabled = false) => {
            const field = document.createElement('label');
            field.className = 'w-field';
            const label = document.createElement('span');
            label.className = 'w-field__label';
            label.textContent = String(fieldNames.compare_mode || 'compare_mode');
            field.append(label);
            const control = document.createElement('select');
            control.className = 'w-select';
            control.name = 'compare_mode';
            [
                ['none', fieldNames.compare_mode_none || 'none'],
                ['diff', fieldNames.compare_mode_diff || 'diff'],
                ['higher_better', fieldNames.compare_mode_higher_better || 'higher_better'],
                ['lower_better', fieldNames.compare_mode_lower_better || 'lower_better'],
            ].forEach(([value, textValue]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = String(textValue);
                control.append(option);
            });
            control.value = String(values.compare_mode || 'none');
            control.disabled = disabled;
            field.append(control);
            return field;
        };

        const appendEditLockNotice = (fields, values, type) => {
            if (type !== 'attribute' || values.can_edit !== false) {
                return;
            }
            const notice = document.createElement('div');
            notice.className = 'w-eav-manager__edit-lock';
            notice.append(createIcon('lock', 'sm'));
            const text = document.createElement('p');
            text.textContent = String(
                values.edit_lock_reason
                || (Number(values.is_system || 0) ? messages.editLockedSystem : messages.editLockedInUse)
                || messages.editLockedSystem
                || 'Read only',
            );
            notice.append(text);
            fields.prepend(notice);
        };

        const optionsPanel = (values) => {
            const panel = document.createElement('section');
            panel.className = 'w-eav-manager__options';
            const heading = document.createElement('div');
            heading.className = 'w-eav-manager__options-head';
            const title = document.createElement('h3');
            title.textContent = String(messages.optionsTitle || fieldNames.data_has_option || 'Options');
            const meta = document.createElement('span');
            const rows = Array.isArray(values.options) ? values.options : [];
            meta.textContent = String(messages.optionsCount || '%{1} 项').replace('%{1}', String(rows.length));
            heading.append(title, meta);
            panel.append(heading);

            if (rows.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'w-eav-manager__options-empty';
                empty.textContent = String(messages.optionsEmpty || 'No options');
                panel.append(empty);
                return panel;
            }

            const list = document.createElement('ul');
            list.className = 'w-eav-manager__options-list';
            rows.forEach((row) => {
                const item = document.createElement('li');
                item.className = 'w-eav-manager__options-item';
                const swatchColor = String(row?.swatch_color || '').trim();
                const swatchImage = String(row?.swatch_image || '').trim();
                const swatchText = String(row?.swatch_text || '').trim();
                if (swatchColor || swatchImage || swatchText) {
                    const swatch = document.createElement('span');
                    swatch.className = 'w-eav-manager__option-swatch';
                    if (swatchImage) {
                        const image = document.createElement('img');
                        image.src = swatchImage;
                        image.alt = '';
                        swatch.append(image);
                    } else if (swatchColor) {
                        swatch.style.setProperty('--w-eav-swatch', swatchColor);
                        swatch.dataset.kind = 'color';
                    } else {
                        swatch.dataset.kind = 'text';
                        swatch.textContent = swatchText;
                    }
                    item.append(swatch);
                }
                const body = document.createElement('div');
                body.className = 'w-eav-manager__option-body';
                const label = document.createElement('strong');
                label.textContent = String(row?.value || row?.label || row?.code || '');
                const code = document.createElement('code');
                code.textContent = String(row?.code || '');
                body.append(label);
                if (row?.code) body.append(code);
                item.append(body);
                const tags = document.createElement('div');
                tags.className = 'w-eav-manager__option-tags';
                if (swatchColor) {
                    const badge = document.createElement('span');
                    badge.className = 'w-badge';
                    badge.dataset.tone = 'warning';
                    badge.textContent = String(structureLabels.swatch_color || 'Color');
                    tags.append(badge);
                }
                if (swatchImage) {
                    const badge = document.createElement('span');
                    badge.className = 'w-badge';
                    badge.dataset.tone = 'warning';
                    badge.textContent = String(structureLabels.swatch_image || 'Image');
                    tags.append(badge);
                }
                if (swatchText) {
                    const badge = document.createElement('span');
                    badge.className = 'w-badge';
                    badge.dataset.tone = 'warning';
                    badge.textContent = String(structureLabels.swatch_text || 'Text');
                    tags.append(badge);
                }
                if (tags.childElementCount > 0) item.append(tags);
                list.append(item);
            });
            panel.append(list);
            return panel;
        };

        const typeOptions = async () => {
            const options = await request('types');
            return Array.isArray(options) ? options : [];
        };

        const renderForm = async (type, values = {}, isNew = false, missing = [], sequenceOverride = null) => {
            const sequence = sequenceOverride == null ? ++detailSequence : Number(sequenceOverride);
            placeholder.hidden = true;
            detail.hidden = false;
            if (detailHeading instanceof HTMLElement) detailHeading.hidden = false;
            detail.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            try {
            const system = Boolean(Number(values.is_system || 0));
            let attributeTypes = [];
            if (type === 'attribute') {
                try {
                    attributeTypes = await typeOptions();
                } catch (error) {
                    if (sequence === detailSequence) detail.replaceChildren(status(errorMessage(error), 'danger'));
                    return;
                }
            }
            if (sequence !== detailSequence || !element.isConnected) return;

            const idField = idFields[type];
            const attributeId = Number(values[idField] || 0);
            const typeLocked = type === 'attribute' && !isNew && attributeId > 0;
            const attributeTypeMeta = type === 'attribute'
                ? resolveAttributeTypeMeta(values, attributeTypes)
                : null;
            const isDefaultStructure = Boolean(Number(values.is_default || 0))
                || String(values.code || '').toLowerCase() === 'default';
            const attributeReadOnly = type === 'attribute' && !isNew && values.can_edit === false;
            const optionsEditable = type === 'attribute'
                && attributeHasOptions(values, attributeTypeMeta)
                && values.can_edit_options !== false;
            const formReadOnly = attributeReadOnly;
            const canSubmitForm = !formReadOnly || optionsEditable;
            const fieldDefinitions = {
                entity: [
                    {name: 'code', required: true, readonly: system},
                    {name: 'name', required: true},
                    {name: 'class', required: true, readonly: system},
                    {name: 'eav_entity_id_field_type', required: true, defaultValue: 'integer', options: [
                        {value: 'integer', label: 'integer'},
                        {value: 'bigint', label: 'bigint'},
                        {value: 'string', label: 'string'},
                    ]},
                    {name: 'eav_entity_id_field_length', type: 'number', min: 1, defaultValue: 11},
                ],
                set: [
                    {name: 'code', required: true, readonly: isDefaultStructure},
                    {name: 'name', required: true, readonly: isDefaultStructure},
                ],
                group: [
                    {name: 'code', required: true, readonly: isDefaultStructure},
                    {name: 'name', required: true, readonly: isDefaultStructure},
                ],
                attribute: [
                    {name: 'code', required: true, readonly: attributeReadOnly},
                    {name: 'name', required: true, readonly: attributeReadOnly},
                    {name: 'type_id', required: true, options: attributeTypes, disabled: typeLocked || attributeReadOnly},
                ],
            };

            const form = document.createElement('form');
            form.className = 'w-stack';
            form.dataset.wEavForm = type;
            if (formReadOnly && !optionsEditable) {
                form.dataset.wEavReadonly = '1';
            }
            if (optionsEditable) {
                form.dataset.wEavOptionsEditable = '1';
            }
            if (idField && values[idField]) {
                const id = document.createElement('input');
                id.type = 'hidden';
                id.name = idField;
                id.value = String(values[idField]);
                form.append(id);
            }
            for (const parentName of ['eav_entity_id', 'set_id', 'group_id']) {
                if (parentName === idField || values[parentName] === undefined) continue;
                const parent = document.createElement('input');
                parent.type = 'hidden';
                parent.name = parentName;
                parent.value = String(values[parentName]);
                form.append(parent);
            }
            const fields = document.createElement('div');
            fields.className = 'w-eav-manager__form-fields';
            const recordId = Number(values[idField] || 0);
            const nameI18nContext = !isNew && recordId > 0 && !formReadOnly
                ? {
                    nodeType: type,
                    recordId,
                    fieldName: 'name',
                    sourceValue: String(values.source_name || values.name || ''),
                }
                : null;
            appendEditLockNotice(fields, values, type);
            for (const definition of (fieldDefinitions[type] || [])) {
                fields.append(inputField(
                    {
                        ...definition,
                        readonly: formReadOnly || Boolean(definition.readonly),
                        disabled: formReadOnly || Boolean(definition.disabled),
                    },
                    values,
                    definition.name === 'name' ? nameI18nContext : null,
                ));
            }
            if (type === 'attribute') {
                const switches = document.createElement('div');
                switches.className = 'w-eav-manager__switches';
                const switchNames = [
                    'basic_is_enable',
                    'frontend_is_filterable',
                    'frontend_is_searchable',
                    'frontend_is_visible',
                    'data_is_multiple',
                ];
                if (!attributeHasOptions(values, attributeTypeMeta)) {
                    switchNames.push('data_has_option');
                }
                switchNames.forEach((name) => switches.append(switchField(name, values, formReadOnly)));
                switches.append(compareModeField(values, formReadOnly));
                fields.append(switches);
                mountAttributeOptionsSection(fields, values, attributeTypeMeta, !optionsEditable);
            }
            form.append(fields);
            if (type === 'attribute') {
                const typeSelect = form.querySelector('select[name="type_id"]');
                if (typeSelect instanceof HTMLSelectElement && isNew) {
                    listen(typeSelect, 'change', () => {
                        const nextValues = {
                            ...values,
                            type_id: typeSelect.value,
                            options: [],
                        };
                        mountAttributeOptionsSection(
                            fields,
                            nextValues,
                            resolveAttributeTypeMeta(nextValues, attributeTypes),
                        );
                    });
                }
            }
            appendQuickAddPanel(fields, type, isNew);
            const actions = document.createElement('div');
            actions.className = 'w-cluster w-eav-manager__form-actions';
            const submit = document.createElement('button');
            submit.className = 'w-button';
            submit.type = 'submit';
            submit.dataset.tone = 'primary';
            submit.append(createIcon('save'));
            const submitText = document.createElement('span');
            submitText.textContent = String(messages.save || 'Save');
            submit.append(submitText);
            if (canSubmitForm) {
                actions.append(submit);
                form.append(actions);
            }
            detail.replaceChildren(form);
            if (isNew && missing.length > 0) {
                try {
                    await appendParentPickers(form, values, missing);
                } catch (error) {
                    if (sequence === detailSequence) {
                        detail.replaceChildren(status(errorMessage(error), 'danger'));
                    }
                    return;
                }
            }
            if (sequence !== detailSequence || !element.isConnected) return;

            if (detailTitle) detailTitle.textContent = isNew
                ? String(messages.addItem || messages.add || 'Add').replace('%{1}', typeNames[type] || type)
                : String(values.local_name || values.name || typeNames[type] || type);
            if (detailSubtitle) detailSubtitle.textContent = String(values.code || '');
            if (deleteButton instanceof HTMLButtonElement) {
                const canDelete = !isNew && (
                    type === 'attribute'
                        ? !system && Boolean(values.can_delete)
                        : type === 'entity'
                            ? !system && Boolean(values.can_delete)
                            : Boolean(values.can_delete)
                );
                deleteButton.hidden = !canDelete;
            }
            updateAddButton();
            } catch (error) {
                if (sequence !== detailSequence || !element.isConnected) return;
                detail.replaceChildren(status(`${messages.loadFailed || 'Load failed'}: ${errorMessage(error)}`, 'danger'));
            }
        };

        const selectItem = async (item) => {
            if (!(item instanceof HTMLElement)) return;
            const type = item.dataset.wEavType || '';
            const id = item.dataset.wEavId || '';
            if (!detailActions[type] || id === '') return;
            clearTreeOptionPreviews();
            tree.querySelectorAll('[data-w-eav-select][aria-current="true"]').forEach((button) => button.removeAttribute('aria-current'));
            item.querySelector(':scope > .w-eav-manager__tree-row [data-w-eav-select]')?.setAttribute('aria-current', 'true');
            selected = {item, type, id, node: parseNode(item)};
            const sequence = ++detailSequence;
            placeholder.hidden = true;
            detail.hidden = false;
            if (detailHeading instanceof HTMLElement) detailHeading.hidden = false;
            detail.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            updateAddButton();
            if (deleteButton instanceof HTMLButtonElement) deleteButton.hidden = true;
            try {
                const values = await request(detailActions[type], 'GET', {id});
                if (sequence !== detailSequence || !element.isConnected) return;
                const detailValues = values && typeof values === 'object' ? values : {};
                await renderForm(type, detailValues, false, [], sequence);
                if (type === 'attribute' && Array.isArray(detailValues.options) && detailValues.options.length > 0) {
                    updateTreeOptionPreview(item, detailValues.options);
                }
            } catch (error) {
                if (sequence !== detailSequence || !element.isConnected) return;
                detail.replaceChildren(status(`${messages.loadFailed || 'Load failed'}: ${errorMessage(error)}`, 'danger'));
            }
        };

        const clearDropTarget = () => {
            if (dropTargetRow instanceof HTMLElement) {
                dropTargetRow.classList.remove('is-drop-target');
                dropTargetRow = null;
            }
        };

        const parseDragPayload = (event) => {
            if (dragPayload) return dragPayload;
            const raw = event.dataTransfer?.getData(dragMime) || '';
            if (!raw) return null;
            try {
                return JSON.parse(raw);
            } catch (_error) {
                return null;
            }
        };

        const canDropOn = (dragType, targetType) => dropRules[dragType] === targetType;

        const parseTreeNode = (item) => {
            try {
                return JSON.parse(item?.dataset?.wEavNode || '{}');
            } catch (_error) {
                return {};
            }
        };

        const attributeContextSetId = (payload) => {
            if (!payload || payload.type !== 'attribute') return 0;
            if (Number(payload.placementId || 0) > 0) {
                return Number(payload.setId || 0);
            }
            return Number(payload.homeSetId || payload.setId || 0);
        };

        const isCrossSetAttributeDrop = (payload, targetItem) => {
            if (!payload || payload.type !== 'attribute' || !(targetItem instanceof HTMLElement)) return false;
            const targetNode = parseTreeNode(targetItem);
            const sourceSetId = attributeContextSetId(payload);
            const targetSetId = Number(targetNode.setId || 0);
            return sourceSetId > 0 && targetSetId > 0 && sourceSetId !== targetSetId;
        };

        const refreshExpandedBranches = async () => {
            const expanded = [...tree.querySelectorAll('[role="treeitem"][aria-expanded="true"]')];
            await loadTree();
            for (const previous of expanded) {
                const type = previous.dataset.wEavType || '';
                const id = previous.dataset.wEavId || '';
                const item = tree.querySelector(`[role="treeitem"][data-w-eav-type="${type}"][data-w-eav-id="${id}"]`);
                if (!(item instanceof HTMLElement)) continue;
                item.setAttribute('aria-expanded', 'true');
                const group = item.querySelector(':scope > [role="group"]');
                if (group instanceof HTMLElement) group.hidden = false;
                await loadChildren(item);
            }
        };

        const selectSavedNode = async (type, id, context = {}) => {
            if (!type || !id) return false;
            await refreshExpandedBranches();
            const item = await ensureNodeVisible(type, id, context);
            if (!(item instanceof HTMLElement)) return false;
            await selectItem(item);
            item.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            return true;
        };

        const moveNode = async (payload, targetItem) => {
            if (!payload || !(targetItem instanceof HTMLElement)) return;
            const targetType = targetItem.dataset.wEavType || '';
            const targetId = targetItem.dataset.wEavId || '';
            if (!canDropOn(payload.type, targetType) || payload.id === '' || targetId === '') {
                UI.toast.warning(messages.invalidDrop || 'Invalid drop target');
                return;
            }
            if (payload.type === 'group' && payload.id === targetId && targetType === 'group') {
                return;
            }
            try {
                const result = await request('move', 'POST', {
                    type: payload.type,
                    id: payload.id,
                    target_type: targetType,
                    target_id: targetId,
                    placement_id: payload.placementId || 0,
                });
                const mode = String(result?.mode || (isCrossSetAttributeDrop(payload, targetItem) ? 'assign' : 'move'));
                UI.toast.success(mode === 'assign'
                    ? (messages.assigned || messages.moved || 'Assigned')
                    : (messages.moved || 'Moved'));
                await refreshExpandedBranches();
                if (targetType === 'set' || targetType === 'group') {
                    targetItem.setAttribute('aria-expanded', 'true');
                    const group = targetItem.querySelector(':scope > [role="group"]');
                    if (group instanceof HTMLElement) group.hidden = false;
                    await loadChildren(targetItem);
                }
                const placementId = Number(result?.placement_id || payload.placementId || 0);
                const movedSelector = placementId > 0
                    ? `[role="treeitem"][data-w-eav-type="attribute"][data-w-eav-id="${payload.id}"][data-w-eav-node*='"placementId":${placementId}']`
                    : `[role="treeitem"][data-w-eav-type="${payload.type}"][data-w-eav-id="${payload.id}"]`;
                const movedItem = tree.querySelector(movedSelector)
                    || tree.querySelector(`[role="treeitem"][data-w-eav-type="${payload.type}"][data-w-eav-id="${payload.id}"]`);
                if (movedItem instanceof HTMLElement) {
                    await selectItem(movedItem);
                }
            } catch (error) {
                await showError(messages.moveFailed || 'Move failed', error);
            }
        };

        const filterTree = (query) => {
            const needle = String(query || '').trim().toLocaleLowerCase();
            const visit = (item) => {
                const own = item.querySelector(':scope > .w-eav-manager__tree-row')?.textContent?.toLocaleLowerCase().includes(needle) || false;
                const children = [...item.querySelectorAll(':scope > [role="group"] > [role="treeitem"]')];
                const childMatch = children.map(visit).some(Boolean);
                const matches = needle === '' || own || childMatch;
                item.hidden = !matches;
                if (needle !== '' && childMatch) {
                    item.setAttribute('aria-expanded', 'true');
                    const group = item.querySelector(':scope > [role="group"]');
                    if (group) group.hidden = false;
                }
                return matches;
            };
            [...tree.querySelectorAll(':scope > [role="treeitem"]')].forEach(visit);
        };

        const createNode = async () => {
            const type = addButton instanceof HTMLButtonElement
                ? (addButton.dataset.wEavAdd || addChildTypeForSelection())
                : addChildTypeForSelection();
            if (!type) {
                UI.toast.warning(messages.selectContext || 'Select a parent node first.');
                return;
            }
            const resolved = resolveAddContext(type);
            if (resolved === null) {
                UI.toast.warning(messages.selectContext || 'Select a parent node first.');
                return;
            }
            await renderForm(type, resolved.values, true, resolved.missing || []);
        };

        const submitForm = async (form) => {
            const type = form.dataset.wEavForm || '';
            if (form.dataset.wEavReadonly === '1' && form.dataset.wEavOptionsEditable !== '1') {
                UI.toast.warning(
                    messages.editLockedSystem
                    || messages.editLockedInUse
                    || 'This attribute cannot be edited.',
                );
                return;
            }
            if (!saveActions[type] || !form.reportValidity()) {
                if (!form.checkValidity()) UI.toast.warning(messages.required || 'Required fields are missing.');
                return;
            }
            const submit = form.querySelector('[type="submit"]');
            setBusy(submit, true);
            const values = Object.fromEntries(new FormData(form).entries());
            try {
                const result = await request(saveActions[type], 'POST', values);
                UI.toast.success(messages.saved || 'Saved.');
                emitSelection('weline:eav:structure-changed', {type, source: element});
                const id = result?.[idFields[type]];
                if (id) {
                    const selectedInTree = await selectSavedNode(type, id, values);
                    if (!selectedInTree) {
                        selected = {item: null, type, id: String(id), node: {...values, ...result}};
                        const details = await request(detailActions[type], 'GET', {id});
                        await renderForm(type, details && typeof details === 'object' ? details : {}, false);
                    }
                } else {
                    await refreshExpandedBranches();
                }
            } catch (error) {
                await showError(messages.loadFailed || 'Save failed', error);
            } finally {
                setBusy(submit, false);
            }
        };

        const deleteSelected = async () => {
            if (!selected?.id || !selected?.type) return;
            const result = await UI.dialog.request({
                tone: 'danger',
                title: messages.deleteTitle || 'Delete',
                message: messages.deleteMessage || 'Delete this node?',
                cancelable: true,
                confirmLabel: messages.confirm || 'OK',
                cancelLabel: messages.cancel || 'Cancel',
            });
            if (!result.confirmed) return;
            setBusy(deleteButton, true);
            try {
                await request('delete', 'POST', {type: selected.type, id: selected.id});
                UI.toast.success(messages.deleted || 'Deleted.');
                selected = null;
                if (detailHeading instanceof HTMLElement) detailHeading.hidden = true;
                detail.hidden = true;
                placeholder.hidden = false;
                updateAddButton();
                await loadTree();
            } catch (error) {
                await showError(messages.deleteTitle || 'Delete failed', error);
            } finally {
                setBusy(deleteButton, false);
            }
        };

        listen(tree, 'weline:ui:tree:change', (event) => {
            if (event.detail?.expanded) loadChildren(event.detail.item);
        });
        const resolveTreeSelectTarget = (event) => {
            if (!(event.target instanceof Element)) return null;
            if (event.target.closest('[data-w-eav-pick], [data-w-tree-toggle]')) return null;
            const button = event.target.closest('[data-w-eav-select]');
            const item = button?.closest('[role="treeitem"]');
            if (button instanceof HTMLElement && item instanceof HTMLElement) {
                return {button, item};
            }
            const structures = event.target.closest('.w-eav-manager__tree-structures');
            const structuresItem = structures?.closest('[role="treeitem"]');
            const structuresButton = structuresItem?.querySelector(':scope > .w-eav-manager__tree-row [data-w-eav-select]');
            if (structures instanceof HTMLElement && structuresButton instanceof HTMLElement && structuresItem instanceof HTMLElement) {
                return {button: structuresButton, item: structuresItem};
            }
            return null;
        };

        listen(tree, 'dragstart', (event) => {
            if (!(event.target instanceof Element)) return;
            const handle = event.target.closest('.w-eav-manager__tree-select-main[data-w-eav-drag]');
            if (!(handle instanceof HTMLElement)) {
                event.preventDefault();
                return;
            }
            const row = handle.closest('.w-eav-manager__tree-row[data-w-eav-drag]');
            const item = row?.closest('[role="treeitem"]');
            if (!(row instanceof HTMLElement) || !(item instanceof HTMLElement)) {
                event.preventDefault();
                return;
            }
            const sourceNode = parseTreeNode(item);
            dragPayload = {
                type: row.dataset.wEavDrag || '',
                id: item.dataset.wEavId || '',
                setId: Number(sourceNode.setId || 0),
                groupId: Number(sourceNode.groupId || 0),
                homeSetId: Number(sourceNode.homeSetId || sourceNode.setId || 0),
                placementId: Number(sourceNode.placementId || 0),
            };
            event.dataTransfer?.setData(dragMime, JSON.stringify(dragPayload));
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copyMove';
            item.classList.add('is-dragging');
        });
        listen(tree, 'dragend', () => {
            dragPayload = null;
            clearDropTarget();
            tree.querySelectorAll('.w-eav-manager__tree-item.is-dragging').forEach((item) => item.classList.remove('is-dragging'));
        });
        listen(tree, 'dragover', (event) => {
            const row = event.target instanceof Element
                ? event.target.closest('.w-eav-manager__tree-row[data-w-eav-drop]')
                : null;
            const payload = parseDragPayload(event);
            const targetItem = row?.closest('[role="treeitem"]');
            if (!(row instanceof HTMLElement) || !(targetItem instanceof HTMLElement) || !payload) return;
            if (!canDropOn(payload.type, row.dataset.wEavDrop || '')) return;
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = isCrossSetAttributeDrop(payload, targetItem) ? 'copy' : 'move';
            }
            if (dropTargetRow !== row) {
                clearDropTarget();
                row.classList.add('is-drop-target');
                dropTargetRow = row;
            }
        });
        listen(tree, 'dragleave', (event) => {
            if (!(event.target instanceof Element) || !(dropTargetRow instanceof HTMLElement)) return;
            const related = event.relatedTarget instanceof Element ? event.relatedTarget : null;
            if (related && dropTargetRow.contains(related)) return;
            if (event.target.closest('.w-eav-manager__tree-row') === dropTargetRow) {
                clearDropTarget();
            }
        });
        listen(tree, 'drop', (event) => {
            event.preventDefault();
            const row = event.target instanceof Element
                ? event.target.closest('.w-eav-manager__tree-row[data-w-eav-drop]')
                : null;
            const targetItem = row?.closest('[role="treeitem"]');
            const payload = parseDragPayload(event);
            clearDropTarget();
            if (!payload || !(targetItem instanceof HTMLElement)) return;
            moveNode(payload, targetItem);
        });
        listen(tree, 'click', (event) => {
            const target = resolveTreeSelectTarget(event);
            if (!target) return;
            const {item} = target;
            if (uiMode === 'select-set') {
                pickSelectSetNode(item);
                return;
            }
            if (uiMode === 'select-attribute') {
                pickSelectionNode(item);
                return;
            }
            selectItem(item);
        });
        listen(tree, 'change', (event) => {
            const pick = event.target instanceof HTMLInputElement ? event.target.closest('[data-w-eav-pick]') : null;
            const item = pick?.closest('[role="treeitem"]');
            if (pick instanceof HTMLInputElement && item instanceof HTMLElement) {
                syncAttributePickFromCheckbox(item, pick.checked);
            }
        });
        listen(tree, 'keydown', (event) => {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const current = event.target instanceof Element ? event.target.closest('[data-w-eav-select]') : null;
            if (!(current instanceof HTMLButtonElement)) return;
            const visible = [...tree.querySelectorAll('[data-w-eav-select]')].filter((button) => button.offsetParent !== null);
            const index = visible.indexOf(current);
            if (index < 0 || visible.length === 0) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0
                : event.key === 'End' ? visible.length - 1
                    : event.key === 'ArrowDown' ? Math.min(index + 1, visible.length - 1)
                        : Math.max(index - 1, 0);
            visible[next]?.focus();
        });
        if (search instanceof HTMLInputElement) listen(search, 'input', () => filterTree(search.value));
        if (addButton instanceof HTMLButtonElement) {
            listen(addButton, 'click', () => createNode());
        }
        listen(element, 'click', (event) => {
            if (event.target instanceof Element && event.target.closest('[data-w-eav-delete]')) deleteSelected();
        });
        listen(element, 'submit', (event) => {
            const form = event.target instanceof HTMLFormElement ? event.target : null;
            if (!form?.matches('[data-w-eav-form]')) return;
            event.preventDefault();
            submitForm(form);
        });

        if (selectConfirm instanceof HTMLButtonElement) {
            listen(selectConfirm, 'click', () => {
                confirmSelection();
            });
        }
        if (selectCancel instanceof HTMLButtonElement) {
            listen(selectCancel, 'click', () => {
                emitSelection('weline:eav:select-cancel', {mode: uiMode, source: element});
            });
        }
        listen(element, 'weline:eav:reload-tree', () => {
            loadTree();
        });

        loadTree();
        updateAddButton();
        applySelectModeUi();
        updateSelectConfirmState();
        return {reload: loadTree, element};
    });

    UI.mount(document);
}
