const UI = window.Weline?.UI;

if (UI) {
    UI.define('eav-manager', ({element, listen}) => {
        const configNode = document.querySelector('[data-w-eav-config]');
        const tree = element.querySelector('[data-w-eav-tree]');
        const search = element.querySelector('[data-w-eav-search]');
        const detail = element.querySelector('[data-w-eav-detail]');
        const placeholder = element.querySelector('[data-w-eav-placeholder]');
        const detailHeader = element.querySelector('[data-w-eav-detail-header]');
        const detailTitle = element.querySelector('[data-w-eav-detail-title]');
        const detailSubtitle = element.querySelector('[data-w-eav-detail-subtitle]');
        const deleteButton = element.querySelector('[data-w-eav-delete]');
        let config = {};
        let resourcePromise = null;
        let selected = null;
        let detailSequence = 0;

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
        const iconNames = {entity: 'box', set: 'folder', group: 'folder', attribute: 'tag'};
        const idFields = {entity: 'eav_entity_id', set: 'set_id', group: 'group_id', attribute: 'attribute_id'};
        const detailActions = {entity: 'entityDetail', set: 'setDetail', group: 'groupDetail', attribute: 'attributeDetail'};
        const saveActions = {entity: 'entitySave', set: 'setSave', group: 'groupSave', attribute: 'attributeSave'};

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
            const values = new URLSearchParams();
            Object.entries(params).forEach(([name, value]) => {
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
            const lazy = Boolean(node?.lazy);
            item.className = 'w-eav-manager__tree-item';
            item.setAttribute('role', 'treeitem');
            item.dataset.wEavType = String(node?.type || '');
            item.dataset.wEavId = String(node?.nodeId ?? node?.id ?? '');
            item.dataset.wEavNode = JSON.stringify(node || {});
            if (lazy) item.setAttribute('aria-expanded', 'false');

            const row = document.createElement('div');
            row.className = 'w-tree__row w-eav-manager__tree-row';
            const toggle = document.createElement('button');
            toggle.className = 'w-eav-manager__tree-toggle';
            toggle.type = 'button';
            toggle.dataset.wTreeToggle = '';
            toggle.disabled = !lazy;
            toggle.setAttribute('aria-label', String(node?.name || node?.code || ''));
            toggle.append(createIcon('chevron-right'));

            const selectButton = document.createElement('button');
            selectButton.className = 'w-eav-manager__tree-select';
            selectButton.type = 'button';
            selectButton.dataset.wEavSelect = '';
            selectButton.append(createIcon(iconNames[node?.type] || 'file'));
            const name = document.createElement('span');
            name.className = 'w-eav-manager__tree-name';
            name.textContent = String(node?.name || node?.code || '');
            selectButton.append(name);
            if (node?.code) {
                const code = document.createElement('span');
                code.className = 'w-eav-manager__tree-code';
                code.textContent = String(node.code);
                selectButton.append(code);
            }
            if (node?.isSystem) {
                const badge = document.createElement('span');
                badge.className = 'w-badge';
                badge.dataset.tone = 'info';
                badge.textContent = String(messages.system || 'System');
                selectButton.append(badge);
            }
            row.append(toggle, selectButton);
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
        };

        const loadTree = async () => {
            tree.setAttribute('aria-busy', 'true');
            tree.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            try {
                const nodes = await request('tree');
                if (element.isConnected) renderTree(nodes);
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

        const parseNode = (item) => {
            try {
                return JSON.parse(item?.dataset?.wEavNode || '{}');
            } catch (_error) {
                return {};
            }
        };
        const contextFor = (type) => {
            const node = selected?.node || {};
            const selectedType = selected?.type || '';
            if (type === 'entity') return {};
            const entityId = selectedType === 'entity' ? selected.id : node.entityId;
            if (type === 'set') return entityId ? {eav_entity_id: entityId} : null;
            const setId = selectedType === 'set' ? selected.id : node.setId;
            if (type === 'group') return entityId && setId ? {eav_entity_id: entityId, set_id: setId} : null;
            const groupId = selectedType === 'group' ? selected.id : node.groupId;
            if (type === 'attribute') return entityId && setId && groupId
                ? {eav_entity_id: entityId, set_id: setId, group_id: groupId}
                : null;
            return null;
        };

        const inputField = (definition, values) => {
            const field = document.createElement('label');
            field.className = 'w-field';
            const label = document.createElement('span');
            label.className = 'w-field__label';
            label.textContent = String(fieldNames[definition.name] || definition.label || definition.name);
            field.append(label);

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
            control.value = String(values[definition.name] ?? definition.defaultValue ?? '');
            control.required = Boolean(definition.required);
            control.disabled = Boolean(definition.disabled);
            if (control instanceof HTMLInputElement) control.readOnly = Boolean(definition.readonly);
            field.append(control);
            return field;
        };

        const switchField = (name, values) => {
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
            switchWrap.append(input);
            label.append(text, switchWrap);
            return label;
        };

        const typeOptions = async () => {
            const options = await request('types');
            return Array.isArray(options) ? options : [];
        };

        const renderForm = async (type, values = {}, isNew = false) => {
            const sequence = ++detailSequence;
            placeholder.hidden = true;
            detail.hidden = false;
            detailHeader.hidden = false;
            detail.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
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
                set: [{name: 'code', required: true}, {name: 'name', required: true}],
                group: [{name: 'code', required: true}, {name: 'name', required: true}],
                attribute: [
                    {name: 'code', required: true},
                    {name: 'name', required: true},
                    {name: 'type_id', required: true, options: attributeTypes},
                ],
            };

            const form = document.createElement('form');
            form.className = 'w-stack';
            form.dataset.wEavForm = type;
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
            for (const definition of (fieldDefinitions[type] || [])) fields.append(inputField(definition, values));
            if (type === 'attribute') {
                const switches = document.createElement('div');
                switches.className = 'w-eav-manager__switches';
                ['basic_is_enable', 'frontend_is_filterable', 'frontend_is_searchable', 'frontend_is_visible', 'data_is_multiple', 'data_has_option']
                    .forEach((name) => switches.append(switchField(name, values)));
                fields.append(switches);
            }
            form.append(fields);
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
            actions.append(submit);
            form.append(actions);
            detail.replaceChildren(form);

            if (detailTitle) detailTitle.textContent = isNew
                ? `${messages.new || 'New'} ${typeNames[type] || type}`
                : String(values.local_name || values.name || typeNames[type] || type);
            if (detailSubtitle) detailSubtitle.textContent = String(values.code || '');
            if (deleteButton instanceof HTMLButtonElement) deleteButton.hidden = isNew || system;
        };

        const selectItem = async (item) => {
            if (!(item instanceof HTMLElement)) return;
            const type = item.dataset.wEavType || '';
            const id = item.dataset.wEavId || '';
            if (!detailActions[type] || id === '') return;
            tree.querySelectorAll('[data-w-eav-select][aria-current="true"]').forEach((button) => button.removeAttribute('aria-current'));
            item.querySelector(':scope > .w-eav-manager__tree-row > [data-w-eav-select]')?.setAttribute('aria-current', 'true');
            selected = {item, type, id, node: parseNode(item)};
            const sequence = ++detailSequence;
            placeholder.hidden = true;
            detail.hidden = false;
            detailHeader.hidden = false;
            detail.replaceChildren(status(messages.loading || 'Loading…', 'muted', true));
            if (deleteButton instanceof HTMLButtonElement) deleteButton.hidden = true;
            try {
                const values = await request(detailActions[type], 'GET', {id});
                if (sequence !== detailSequence || !element.isConnected) return;
                await renderForm(type, values && typeof values === 'object' ? values : {}, false);
            } catch (error) {
                if (sequence !== detailSequence || !element.isConnected) return;
                detail.replaceChildren(status(`${messages.loadFailed || 'Load failed'}: ${errorMessage(error)}`, 'danger'));
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

        const createNode = async (type) => {
            const context = contextFor(type);
            if (context === null) {
                UI.toast.warning(messages.selectContext || 'Select a parent node first.');
                return;
            }
            selected = {item: null, type, id: '', node: context};
            await renderForm(type, context, true);
        };

        const submitForm = async (form) => {
            const type = form.dataset.wEavForm || '';
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
                await loadTree();
                const id = result?.[idFields[type]];
                if (id) {
                    selected = {item: null, type, id: String(id), node: {...values, ...result}};
                    const details = await request(detailActions[type], 'GET', {id});
                    await renderForm(type, details && typeof details === 'object' ? details : {}, false);
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
                detailHeader.hidden = true;
                detail.hidden = true;
                placeholder.hidden = false;
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
        listen(tree, 'click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-w-eav-select]') : null;
            const item = button?.closest('[role="treeitem"]');
            if (button && item) selectItem(item);
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
        listen(element, 'click', (event) => {
            const create = event.target instanceof Element ? event.target.closest('[data-w-eav-create]') : null;
            if (create instanceof HTMLButtonElement) createNode(create.dataset.wEavCreate || '');
            if (event.target instanceof Element && event.target.closest('[data-w-eav-delete]')) deleteSelected();
        });
        listen(element, 'submit', (event) => {
            const form = event.target instanceof HTMLFormElement ? event.target : null;
            if (!form?.matches('[data-w-eav-form]')) return;
            event.preventDefault();
            submitForm(form);
        });

        loadTree();
        return {reload: loadTree, element};
    });

    UI.mount(document);
}
