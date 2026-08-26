/* Weline Product category admin: document-catalog layout + drag reorder */
const root = document.querySelector('[data-catalog-admin]');

if (root) {
    const form = root.querySelector('[data-catalog-form]');
    const treeRoot = root.querySelector('[data-category-dnd-tree]');
    const websiteId = Number(root.dataset.websiteId || 0);
    const text = Object.fromEntries(
        Object.entries(root.dataset)
            .filter(([key]) => key.startsWith('text'))
            .map(([key, value]) => [key.slice(4, 5).toLowerCase() + key.slice(5), String(value || '')]),
    );

    async function apiResource() {
        const Weline = window.Weline;
        const api = Weline?.Api?.resource ? Weline.Api : await Weline?.load?.('api');
        if (!api?.resource) throw new Error('Weline API runtime is unavailable.');
        return api.resource('product_category_admin');
    }

    function resultMessage(result, fallback) {
        return String(result?.message || result?.msg || result?.data?.message || result?.data?.msg || fallback);
    }

    async function call(operation, params) {
        const resource = await apiResource();
        if (typeof resource[operation] !== 'function') {
            throw new Error(`Weline operation is unavailable: ${operation}`);
        }
        const result = await resource[operation]({ website_id: websiteId, ...params }, { keepBusinessResult: true, silent: true });
        if (result?.success === false || Number(result?.code || 200) >= 400) {
            throw new Error(resultMessage(result, text.saveFailed));
        }
        return result;
    }

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
            const confirmed = await window.Weline?.UI?.dialog?.confirm?.(text.deleteMessage, {
                title: text.deleteTitle,
                dangerous: true,
                confirmTone: 'danger',
            });
            if (!confirmed) return;
            deleteTrigger.disabled = true;
            try {
                const result = await call('categoryAdminDelete', { id });
                window.Weline?.UI?.toast?.success(resultMessage(result, text.deleteSuccess));
                window.location.assign(catalogUrl());
            } catch (error) {
                deleteTrigger.disabled = false;
                window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.deleteFailed);
            }
        }
    });

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
