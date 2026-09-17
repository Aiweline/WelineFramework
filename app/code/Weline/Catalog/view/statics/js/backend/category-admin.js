/* Weline Product category admin: document-catalog layout + drag reorder */
const root = document.querySelector('[data-catalog-admin]');

if (root) {
    const websiteId = Number(root.dataset.websiteId || 0);
    const storeId = Number(root.dataset.storeId || 0);
    const channelId = Number(root.dataset.channelId || 0);
    const space = String(root.dataset.space || 'product');
    const scopeLevel = String(root.dataset.scopeLevel || 'website');
    const grantVersions = {
        create: Number(root.dataset.grantVersionCreate || 0),
        update: Number(root.dataset.grantVersionUpdate || 0),
        delete: Number(root.dataset.grantVersionDelete || 0),
    };
    const displayForm = root.querySelector('[data-catalog-display-form]');
    const form = root.querySelector('[data-catalog-form]');
    const treeRoot = root.querySelector('[data-category-dnd-tree]');
    const treeNav = root.querySelector('[data-testid="catalog-category-tree"]');
    let blockDragFromToggle = false;
    const text = Object.fromEntries(
        Object.entries(root.dataset)
            .filter(([key]) => key.startsWith('text'))
            .map(([key, value]) => [key.slice(4, 5).toLowerCase() + key.slice(5), String(value || '')]),
    );

    function initTreeCollapse(nav) {
        if (!(nav instanceof HTMLElement)) return;
        const storageKey = `weline.catalog.tree.collapsed.${space}.${websiteId}.${scopeLevel}`;
        let collapsed = {};
        try {
            collapsed = JSON.parse(sessionStorage.getItem(storageKey) || '{}') || {};
        } catch (_error) {
            collapsed = {};
        }

        const setExpanded = (item, expanded, persist = true) => {
            if (!(item instanceof HTMLElement) || !item.hasAttribute('aria-expanded')) return;
            item.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const group = item.querySelector(':scope > [data-category-tree-list]');
            if (group instanceof HTMLElement) {
                group.hidden = !expanded;
            }
            const id = String(item.dataset.id || '');
            if (persist && id) {
                if (expanded) delete collapsed[id];
                else collapsed[id] = 1;
                try {
                    sessionStorage.setItem(storageKey, JSON.stringify(collapsed));
                } catch (_error) {
                    /* ignore quota */
                }
            }
        };

        nav.querySelectorAll('[data-category-node][aria-expanded]').forEach((item) => {
            const id = String(item.dataset.id || '');
            if (id && collapsed[id]) {
                setExpanded(item, false, false);
            }
        });

        const selectedRow = nav.querySelector('.w-catalog-tree__row[data-state="selected"]');
        if (selectedRow instanceof HTMLElement) {
            let cursor = selectedRow.closest('[data-category-node]');
            while (cursor instanceof HTMLElement) {
                setExpanded(cursor, true, false);
                cursor = cursor.parentElement?.closest('[data-category-node]') || null;
            }
        }

        nav.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-catalog-tree-toggle]');
            if (!(toggle instanceof HTMLElement)) return;
            event.preventDefault();
            event.stopPropagation();
            const item = toggle.closest('[data-category-node][aria-expanded]');
            if (!(item instanceof HTMLElement)) return;
            const expanded = item.getAttribute('aria-expanded') === 'true';
            setExpanded(item, !expanded, true);
        });

        nav.addEventListener('mousedown', (event) => {
            blockDragFromToggle = !!event.target.closest('[data-catalog-tree-toggle]');
            if (blockDragFromToggle) {
                event.stopPropagation();
            }
        }, true);

        nav.addEventListener('mouseup', () => {
            blockDragFromToggle = false;
        }, true);
    }

    initTreeCollapse(treeNav);

    async function apiResource() {
        const Weline = window.Weline;
        const api = Weline?.Api?.resource ? Weline.Api : await Weline?.load?.('api');
        if (!api?.resource) throw new Error('Weline API runtime is unavailable.');
        return api.resource('catalog_category_admin');
    }

    function resultMessage(result, fallback) {
        return String(result?.message || result?.msg || result?.data?.message || result?.data?.msg || fallback);
    }

    function grantVersionFor(operation, params) {
        if (operation === 'categoryAdminDelete') {
            return grantVersions.delete;
        }
        if (operation === 'categoryAdminSave' && Number(params?.id || 0) <= 0) {
            return grantVersions.create;
        }
        return grantVersions.update;
    }

    function requiresGrantVersion(operation) {
        return [
            'categoryAdminSave',
            'categoryAdminDelete',
            'categoryAdminReorder',
            'categoryAdminSaveDisplay',
        ].includes(operation);
    }

    async function call(operation, params) {
        const resource = await apiResource();
        if (typeof resource[operation] !== 'function') {
            throw new Error(`Weline operation is unavailable: ${operation}`);
        }
        const payload = {
            space,
            scope_level: scopeLevel,
            website_id: websiteId,
            store_id: storeId,
            channel_id: channelId,
            ...params,
        };
        if (requiresGrantVersion(operation)) {
            payload.expected_grant_version = Number(
                params?.expected_grant_version || grantVersionFor(operation, params) || 0,
            );
        }
        const result = await resource[operation](payload, { keepBusinessResult: true, silent: true });
        if (result?.success === false || Number(result?.code || 200) >= 400) {
            throw new Error(resultMessage(result, text.saveFailed));
        }
        return result;
    }

    displayForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = displayForm.querySelector('button[type="submit"]');
        const rows = [...displayForm.querySelectorAll('[data-display-row]')].map((row, index) => {
            const categoryId = Number(row.dataset.categoryId || 0);
            const enabled = row.querySelector('[data-display-enabled]')?.checked ? 1 : 0;
            return { category_id: categoryId, enabled, position: index + 1 };
        }).filter((row) => row.category_id > 0);
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
            const result = await call('categoryAdminSaveDisplay', { rows });
            window.Weline?.UI?.toast?.success(resultMessage(result, text.displaySaveSuccess || text.saveSuccess));
            window.location.reload();
        } catch (error) {
            if (submit instanceof HTMLButtonElement) submit.disabled = false;
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : (text.displaySaveFailed || text.saveFailed));
        }
    });

    function catalogUrl(id = 0) {
        const url = new URL(window.location.href);
        url.searchParams.delete('new');
        url.searchParams.delete('pid');
        url.searchParams.delete('category_id');
        url.searchParams.delete('parent_id');
        if (id > 0) url.searchParams.set('id', String(id));
        else url.searchParams.delete('id');
        return url.href;
    }

    function slugify(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '') || 'category';
    }

    function bindPathPreview() {
        if (!form) return;
        const nameInput = form.querySelector('[data-category-name-input]');
        const codeInput = form.querySelector('[data-category-code-input]');
        const parentSelect = form.querySelector('[data-category-parent-select]');
        const preview = form.querySelector('[data-category-path-preview]');
        if (!codeInput || !preview) return;

        const parentPath = () => {
            if (!(parentSelect instanceof HTMLSelectElement)) return '';
            const option = parentSelect.options[parentSelect.selectedIndex];
            return String(option?.dataset.path || '').replace(/\/+$/, '');
        };

        const syncCodeFromName = () => {
            if (!(nameInput instanceof HTMLInputElement) || !(codeInput instanceof HTMLInputElement)) return;
            if (codeInput.dataset.touched === '1') return;
            if (codeInput.value.trim() !== '') return;
            codeInput.value = slugify(nameInput.value);
        };

        const updatePreview = () => {
            const code = slugify(codeInput.value || (nameInput instanceof HTMLInputElement ? nameInput.value : ''));
            const prefix = parentPath();
            const path = `${prefix}/${code}`.replace(/\/+/g, '/');
            preview.textContent = path.startsWith('/') ? path : `/${path}`;
        };

        codeInput.addEventListener('input', () => {
            codeInput.dataset.touched = '1';
            codeInput.value = slugify(codeInput.value);
            updatePreview();
        });
        nameInput?.addEventListener('input', () => {
            syncCodeFromName();
            updatePreview();
        });
        parentSelect?.addEventListener('change', updatePreview);
        syncCodeFromName();
        updatePreview();
    }

    bindPathPreview();

    (function bindCategoryMediaPickers() {
        const dialog = document.querySelector('[data-catalog-media-dialog]');
        const frame = dialog?.querySelector('[data-catalog-media-frame]');
        const closeBtn = dialog?.querySelector('[data-catalog-media-close]');
        if (!(dialog instanceof HTMLDialogElement) || !(frame instanceof HTMLIFrameElement)) {
            return;
        }

        const imageMax = Number(root.dataset.imageMaxBytes || 32768);
        const bannerMax = Number(root.dataset.bannerMaxBytes || 102400);
        let activeKind = '';

        const resolvePickerUrl = (file) => {
            const candidates = [
                file?.url,
                file?.path,
                file?.display_url,
                file?.editor_preview_url,
                file?.preview_url,
            ];
            for (const candidate of candidates) {
                const value = String(candidate || '').trim();
                if (value !== '') {
                    return value;
                }
            }
            return '';
        };

        const setMediaValue = (kind, url) => {
            const block = root.querySelector(`[data-catalog-media="${kind}"]`);
            if (!(block instanceof HTMLElement)) {
                return;
            }
            const input = block.querySelector('[data-catalog-media-input]');
            const preview = block.querySelector('[data-catalog-media-preview]');
            const img = block.querySelector('[data-catalog-media-img]');
            const clear = block.querySelector('[data-catalog-media-clear]');
            if (input instanceof HTMLInputElement) {
                input.value = url;
            }
            if (preview instanceof HTMLElement) {
                preview.hidden = url === '';
            }
            if (img instanceof HTMLImageElement) {
                if (url === '') {
                    img.removeAttribute('src');
                    img.hidden = true;
                } else {
                    img.src = url;
                    img.hidden = false;
                }
            }
            if (clear instanceof HTMLElement) {
                clear.hidden = url === '';
            }
        };

        const openPicker = (kind) => {
            activeKind = kind;
            const srcAttr = kind === 'banner' ? 'srcBanner' : 'srcImage';
            const src = String(frame.dataset[srcAttr] || '').trim();
            if (src === '') {
                return;
            }
            let pickerUrl = new URL(src, window.location.href);
            const helper = window.Weline && window.Weline.MediaIdentityPicker;
            if (helper) {
                const ambient = helper.ambientFrom(root);
                const pathPreview = String(root.querySelector('[data-category-path-preview]')?.textContent || '').trim();
                const code = pathPreview && pathPreview !== '/'
                    ? pathPreview
                    : (ambient.code || ('id-' + String(root.dataset.selectedId || '0')));
                const identity = helper.buildIdentity({
                    root: 'catalog',
                    code: code.replace(/^\/+/, ''),
                    scope: ambient.scope || null,
                    kind: kind,
                    field: kind,
                });
                if (identity) {
                    pickerUrl = helper.appendIdentityParams(pickerUrl, identity);
                    dialog.__mediaIdentity = identity;
                    dialog.__mediaAmbient = ambient;
                }
            }
            frame.src = pickerUrl.href;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        };

        const closePicker = () => {
            activeKind = '';
            frame.removeAttribute('src');
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        };

        root.querySelectorAll('[data-catalog-media]').forEach((block) => {
            if (!(block instanceof HTMLElement)) {
                return;
            }
            const kind = String(block.dataset.catalogMedia || '');
            block.querySelector('[data-catalog-media-pick]')?.addEventListener('click', () => openPicker(kind));
            block.querySelector('[data-catalog-media-clear]')?.addEventListener('click', () => setMediaValue(kind, ''));
        });

        closeBtn?.addEventListener('click', closePicker);
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closePicker();
        });

        window.addEventListener('message', (event) => {
            if (!event?.data || typeof event.data !== 'object') {
                return;
            }
            const target = String(event.data.target || '');
            const kind = target === 'catalog-category-banner'
                ? 'banner'
                : (target === 'catalog-category-image' ? 'image' : '');
            if (kind === '' || (activeKind !== '' && activeKind !== kind)) {
                return;
            }
            const files = Array.isArray(event.data.files)
                ? event.data.files
                : (event.data.file ? [event.data.file] : []);
            const file = files[0];
            if (!file || typeof file !== 'object') {
                return;
            }
            const bytes = Number(file.file_size ?? file.size ?? file.bytes ?? NaN);
            const maxBytes = kind === 'banner' ? bannerMax : imageMax;
            if (Number.isFinite(bytes) && bytes > maxBytes) {
                const message = kind === 'banner'
                    ? (root.dataset.textBannerTooLarge || 'Banner too large')
                    : (root.dataset.textImageTooLarge || 'Image too large');
                window.Weline?.UI?.toast?.error(message);
                return;
            }
            const url = resolvePickerUrl(file);
            if (url === '') {
                return;
            }
            setMediaValue(kind, url);
            const helper = window.Weline && window.Weline.MediaIdentityPicker;
            if (helper && dialog.__mediaIdentity) {
                const ambient = dialog.__mediaAmbient || helper.ambientFrom(root);
                helper.bindSelection(dialog.__mediaIdentity, files, {
                    bindUrl: ambient.bindUrl || root.dataset.mediaBindUrl || '',
                    ownerType: 'catalog_category',
                    ownerId: dialog.__mediaIdentity.code,
                    ownerVersion: 1,
                    refMode: 'single',
                });
            }
            closePicker();
        });
    })();

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submit = form.querySelector('button[type="submit"]');
        const fields = new FormData(form);
        const active = form.querySelector('input[type="checkbox"][name="is_active"]');
        const payload = {
            id: Number(fields.get('id') || 0),
            pid: Number(fields.get('pid') || 0),
            name: String(fields.get('name') || '').trim(),
            code: String(fields.get('code') || '').trim(),
            google_taxonomy_id: String(fields.get('google_taxonomy_id') || '').trim(),
            image: String(fields.get('image') || '').trim(),
            banner: String(fields.get('banner') || '').trim(),
            summary: String(fields.get('summary') || '').trim(),
            description: String(fields.get('description') || '').trim(),
            is_active: active?.checked ? 1 : 0,
        };
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
            const result = await call('categoryAdminSave', payload);
            window.Weline?.UI?.toast?.success(resultMessage(result, text.saveSuccess));
            const id = Number(result?.data?.id || result?.data?.category_id || payload.id || 0);
            window.location.assign(catalogUrl(id));
        } catch (error) {
            if (submit instanceof HTMLButtonElement) submit.disabled = false;
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.saveFailed);
        }
    });

    root.addEventListener('click', async (event) => {
        const deleteTrigger = event.target.closest('[data-catalog-delete]');
        if (deleteTrigger instanceof HTMLButtonElement) {
            const id = Number(deleteTrigger.dataset.catalogDelete || 0);
            if (!id) return;
            deleteTrigger.disabled = true;
            try {
                const confirmed = await confirmCategoryDelete(id);
                if (!confirmed) {
                    deleteTrigger.disabled = false;
                    return;
                }
                const productIds = Array.isArray(confirmed.product_ids) ? confirmed.product_ids : [];
                const result = await call('categoryAdminDelete', { id, product_ids: productIds });
                window.Weline?.UI?.toast?.success(resultMessage(result, text.deleteSuccess));
                window.location.assign(catalogUrl());
            } catch (error) {
                deleteTrigger.disabled = false;
                window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.deleteFailed);
            }
        }
    });

    async function confirmCategoryDelete(id) {
        if (space !== 'product') {
            const ok = await window.Weline?.UI?.dialog?.confirm?.(text.deleteMessage, {
                title: text.deleteTitle,
                dangerous: true,
                confirmTone: 'danger',
            });
            return ok ? { product_ids: [] } : null;
        }

        let products = [];
        try {
            const listed = await call('categoryAdminListProductsForDelete', { id });
            const candidates = [
                listed?.data?.products,
                listed?.data?.data?.products,
                listed?.products,
            ];
            products = candidates.find((rows) => Array.isArray(rows)) || [];
        } catch (error) {
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.deleteListFailed);
            return null;
        }

        if (!products.length) {
            const ok = await window.Weline?.UI?.dialog?.confirm?.(text.deleteMessage, {
                title: text.deleteTitle,
                dangerous: true,
                confirmTone: 'danger',
            });
            return ok ? { product_ids: [] } : null;
        }

        const wrap = document.createElement('div');
        wrap.className = 'w-catalog-delete-products';

        const intro = document.createElement('p');
        intro.className = 'w-catalog-delete-products__intro';
        intro.textContent = text.deleteProductsIntro || text.deleteMessage;
        wrap.appendChild(intro);

        const summary = document.createElement('p');
        summary.className = 'w-catalog-delete-products__summary';
        summary.dataset.physicalSummary = '1';
        wrap.appendChild(summary);

        const actions = document.createElement('div');
        actions.className = 'w-catalog-delete-products__actions';
        const mkBtn = (label, onClick) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-catalog-delete-products__chip';
            btn.textContent = label;
            btn.addEventListener('click', onClick);
            return btn;
        };
        actions.appendChild(mkBtn(text.deleteSelectExclusive || '全选独占', () => {
            wrap.querySelectorAll('input[data-product-id][data-exclusive="1"]').forEach((el) => { el.checked = true; });
            refreshSummary();
        }));
        actions.appendChild(mkBtn(text.deleteSelectAll || '全选', () => {
            wrap.querySelectorAll('input[data-product-id]').forEach((el) => { el.checked = true; });
            refreshSummary();
        }));
        wrap.appendChild(actions);

        const list = document.createElement('div');
        list.className = 'w-catalog-delete-products__list';
        products.forEach((product) => {
            const productId = Number(product?.product_id || 0);
            if (!productId) return;
            const exclusive = !!product?.exclusive;
            const row = document.createElement('label');
            row.className = 'w-catalog-delete-products__row';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.dataset.productId = String(productId);
            input.dataset.exclusive = exclusive ? '1' : '0';
            input.addEventListener('change', refreshSummary);
            const body = document.createElement('span');
            body.className = 'w-catalog-delete-products__body';
            const title = document.createElement('span');
            title.className = 'w-catalog-delete-products__title';
            const sku = String(product?.sku || '').trim();
            title.textContent = String(product?.name || ('#' + productId)) + (sku ? ` (${sku})` : '');
            const badge = document.createElement('span');
            badge.className = exclusive
                ? 'w-catalog-delete-products__badge w-catalog-delete-products__badge--danger'
                : 'w-catalog-delete-products__badge';
            badge.textContent = exclusive
                ? (text.deleteExclusive || '物理删除')
                : (text.deleteShared || '仅解绑');
            body.appendChild(title);
            body.appendChild(badge);
            row.appendChild(input);
            row.appendChild(body);
            list.appendChild(row);
        });
        wrap.appendChild(list);

        function refreshSummary() {
            const n = [...wrap.querySelectorAll('input[data-product-id][data-exclusive="1"]:checked')].length;
            const template = text.deletePhysicalSummary || '将物理删除 %{n} 个产品';
            summary.textContent = n > 0 ? template.replace('%{n}', String(n)) : '';
            summary.hidden = n <= 0;
        }
        refreshSummary();

        const result = await window.Weline?.UI?.dialog?.request?.({
            title: text.deleteTitle,
            message: wrap,
            size: 'lg',
            dangerous: true,
            confirmTone: 'danger',
            cancelable: true,
            confirmLabel: text.deleteConfirm || '确认删除',
            cancelLabel: text.deleteCancel || '取消',
        });
        if (!result?.confirmed) return null;
        const selected = [...wrap.querySelectorAll('input[data-product-id]:checked')]
            .map((el) => Number(el.dataset.productId || 0))
            .filter((value) => value > 0);
        return { product_ids: selected };
    }

    if (treeRoot) {
        let dragId = 0;
        let dropMode = '';
        let dropTargetItem = null;

        function nodeMeta(item) {
            const row = item.querySelector(':scope > .w-catalog-tree__row');
            return {
                id: Number(row?.dataset.id || item.dataset.id || 0),
                pid: Number(row?.dataset.pid || item.dataset.pid || 0),
                level: Number(row?.dataset.level || item.dataset.level || 1),
            };
        }

        function listItemParent(item) {
            const parentList = item.parentElement;
            if (!(parentList instanceof HTMLElement) || !parentList.matches('[data-category-tree-list]')) {
                return null;
            }
            const parentItem = parentList.closest('[data-category-node]');
            return parentItem instanceof HTMLElement ? parentItem : null;
        }

        function siblingPosition(item, mode) {
            const parentItem = listItemParent(item);
            const pid = parentItem ? nodeMeta(parentItem).id : 0;
            const siblings = parentItem
                ? [...parentItem.querySelectorAll(':scope > [data-category-tree-list] > [data-category-node]')]
                : [...treeRoot.querySelectorAll(':scope > [data-category-tree-list] > [data-category-node]')];
            const index = siblings.indexOf(item);
            if (mode === 'inside') {
                return { pid: nodeMeta(item).id, level: nodeMeta(item).level + 1, position: 1 };
            }
            if (mode === 'before') {
                return { pid, level: parentItem ? nodeMeta(parentItem).level + 1 : 1, position: Math.max(1, index + 1) };
            }
            return { pid, level: parentItem ? nodeMeta(parentItem).level + 1 : 1, position: Math.max(1, index + 2) };
        }

        function clearDropState() {
            treeRoot.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
            treeRoot.querySelectorAll('[data-drop-zone]').forEach((el) => {
                el.hidden = true;
            });
            dropMode = '';
            dropTargetItem = null;
        }

        function isDescendantOf(candidate, ancestorId) {
            let cursor = candidate;
            while (cursor) {
                if (nodeMeta(cursor).id === ancestorId) return true;
                cursor = listItemParent(cursor);
            }
            return false;
        }

        treeRoot.addEventListener('dragstart', (event) => {
            if (blockDragFromToggle) {
                event.preventDefault();
                return;
            }
            const row = event.target.closest('.w-catalog-tree__row--draggable');
            if (!(row instanceof HTMLElement)) return;
            const item = row.closest('[data-category-node]');
            if (!(item instanceof HTMLElement)) return;
            dragId = nodeMeta(item).id;
            row.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(dragId));
            }
        });

        treeRoot.addEventListener('dragend', () => {
            treeRoot.querySelectorAll('.is-dragging').forEach((el) => el.classList.remove('is-dragging'));
            clearDropState();
            dragId = 0;
        });

        treeRoot.addEventListener('dragover', (event) => {
            if (!dragId) return;
            event.preventDefault();
            const targetItem = event.target.closest('[data-category-node]');
            if (!(targetItem instanceof HTMLElement)) return;
            const targetId = nodeMeta(targetItem).id;
            if (targetId === dragId || isDescendantOf(targetItem, dragId)) {
                return;
            }
            const rect = targetItem.getBoundingClientRect();
            const offsetY = event.clientY - rect.top;
            const zone = offsetY < rect.height * 0.25 ? 'before' : offsetY > rect.height * 0.75 ? 'after' : 'inside';
            clearDropState();
            dropMode = zone;
            dropTargetItem = targetItem;
            targetItem.querySelector(`:scope > [data-drop-zone="${zone}"]`)?.removeAttribute('hidden');
            targetItem.querySelector(':scope > .w-catalog-tree__row')?.classList.add('is-drop-target');
        });

        treeRoot.addEventListener('drop', async (event) => {
            event.preventDefault();
            if (!dragId || !dropTargetItem || !dropMode) {
                clearDropState();
                return;
            }
            const meta = siblingPosition(dropTargetItem, dropMode);
            treeRoot.classList.add('is-dnd-busy');
            try {
                const result = await call('categoryAdminReorder', {
                    id: dragId,
                    pid: meta.pid,
                    level: meta.level,
                    position: meta.position,
                });
                window.Weline?.UI?.toast?.success(resultMessage(result, text.reorderSuccess));
                window.location.reload();
            } catch (error) {
                treeRoot.classList.remove('is-dnd-busy');
                window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.reorderFailed);
            } finally {
                clearDropState();
            }
        });

        treeRoot.querySelectorAll('.w-catalog-tree__link').forEach((link) => {
            link.addEventListener('dragstart', (event) => event.preventDefault());
        });
    }
}
