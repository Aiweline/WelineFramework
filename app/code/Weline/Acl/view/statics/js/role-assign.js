const root = document.querySelector('[data-acl-assign]');

if (root instanceof HTMLFormElement) {
    const menuTree = root.querySelector('[data-acl-tree="menu"]');
    const tagTree = root.querySelector('[data-acl-tree="tag"]');
    const search = root.querySelector('[data-acl-search]');
    const moduleFilter = root.querySelector('[data-acl-module]');
    const typeFilter = root.querySelector('[data-acl-type]');
    const result = root.querySelector('[data-acl-result]');
    const selectedCount = root.querySelector('[data-acl-selected-count]');
    const progress = root.querySelector('[data-acl-progress]');
    const progressRoot = progress?.closest('[role="progressbar"]');
    const progressLabel = root.querySelector('[data-acl-progress-label]');
    const saveButton = root.querySelector('[data-acl-save]');
    const total = Math.max(0, Number(root.dataset.totalPermissions || 0));
    let activeView = 'menu';

    function sourceInputs(sourceId = '') {
        const selector = sourceId
            ? `[data-acl-source="${CSS.escape(sourceId)}"]`
            : '[data-acl-source]';
        return [...root.querySelectorAll(selector)].filter((input) => input instanceof HTMLInputElement);
    }

    function activeTree() {
        return activeView === 'tag' ? tagTree : menuTree;
    }

    function setSource(sourceId, checked, origin = null) {
        sourceInputs(sourceId).forEach((input) => {
            input.checked = checked;
            input.indeterminate = false;
            if (input !== origin) input.dataset.aclSynced = 'true';
        });
    }

    function clearCoveringTagGrants(sourceId) {
        sourceInputs(sourceId).forEach((leaf) => {
            if (!tagTree?.contains(leaf)) return;
            let branch = leaf.closest('.w-acl-tree__branch');
            while (branch && tagTree.contains(branch)) {
                const tag = branch.querySelector(':scope > summary input[data-acl-tag]');
                if (tag instanceof HTMLInputElement) tag.checked = false;
                branch = branch.parentElement?.closest('.w-acl-tree__branch') || null;
            }
        });
    }

    function updateMenuBranches() {
        if (!menuTree) return;
        const branches = [...menuTree.querySelectorAll('.w-acl-tree__branch')].reverse();
        branches.forEach((branch) => {
            const parent = branch.querySelector(':scope > summary input[data-acl-source]');
            if (!(parent instanceof HTMLInputElement)) return;
            const descendants = [...branch.querySelectorAll(':scope > .w-acl-tree__list input[data-acl-source]')]
                .filter((input) => input instanceof HTMLInputElement);
            if (!descendants.length) return;
            const checked = descendants.filter((input) => input.checked).length;
            parent.checked = checked === descendants.length;
            parent.indeterminate = checked > 0 && checked < descendants.length;
        });
    }

    function updateStatistics() {
        const granted = new Set();
        sourceInputs().forEach((input) => {
            if (input.checked) granted.add(String(input.dataset.aclSource || input.value));
        });
        const count = granted.size;
        const percent = total > 0 ? Math.min(100, Math.round((count / total) * 100)) : 0;
        if (selectedCount) selectedCount.textContent = String(count);
        if (progressLabel) progressLabel.textContent = `${percent}%`;
        if (progress instanceof HTMLElement) progress.style.setProperty('--w-progress', `${percent}%`);
        progressRoot?.setAttribute('aria-valuenow', String(percent));
    }

    function syncInitialSources() {
        const states = new Map();
        sourceInputs().forEach((input) => {
            const id = String(input.dataset.aclSource || input.value);
            states.set(id, Boolean(states.get(id) || input.checked));
        });
        states.forEach((checked, id) => setSource(id, checked));
        updateMenuBranches();
        updateStatistics();
    }

    function childNodes(node) {
        const lists = [...node.children].flatMap((child) => {
            if (child.classList.contains('w-acl-tree__list')) return [child];
            if (child.classList.contains('w-acl-tree__branch')) {
                return [...child.children].filter((entry) => entry.classList.contains('w-acl-tree__list'));
            }
            return [];
        });
        return lists.flatMap((list) => [...list.children].filter((child) => child.matches('.w-acl-tree__node')));
    }

    function filterNode(node, query, moduleName, type) {
        const children = childNodes(node);
        let visibleChildren = 0;
        children.forEach((child) => {
            if (filterNode(child, query, moduleName, type)) visibleChildren += 1;
        });
        const searchable = String(node.dataset.search || '').toLocaleLowerCase();
        const queryMatch = !query || searchable.includes(query);
        const hasOwnDimension = Boolean(node.dataset.module || node.dataset.type);
        const moduleMatch = !moduleName || String(node.dataset.module || '').includes(moduleName);
        const typeMatch = !type || String(node.dataset.type || '') === type;
        const ownMatch = queryMatch && (hasOwnDimension ? moduleMatch && typeMatch : !moduleName && !type);
        const visible = ownMatch || visibleChildren > 0;
        node.hidden = !visible;
        node.dataset.searchMatch = query && ownMatch ? 'true' : 'false';
        if (visible && (query || moduleName || type)) {
            const details = node.querySelector(':scope > .w-acl-tree__branch');
            if (details instanceof HTMLDetailsElement) details.open = true;
        }
        return visible;
    }

    function applyFilters() {
        const tree = activeTree();
        if (!tree) return;
        const query = String(search?.value || '').trim().toLocaleLowerCase();
        const moduleName = String(moduleFilter?.value || '');
        const type = String(typeFilter?.value || '');
        let visible = 0;
        tree.querySelectorAll(':scope > .w-acl-tree__list > .w-acl-tree__node').forEach((node) => {
            if (filterNode(node, query, moduleName, type)) visible += 1;
        });
        if (result) {
            result.textContent = query || moduleName || type
                ? (visible > 0 ? String(root.dataset.textSelected || '').replace('%{1}', String(visible)) : String(root.dataset.textEmpty || ''))
                : '';
        }
    }

    function cascadeMenu(input) {
        const branch = input.closest('.w-acl-tree__branch');
        if (!branch || input.closest('summary') !== branch.querySelector(':scope > summary')) return;
        branch.querySelectorAll('input[data-acl-source]').forEach((child) => {
            if (!(child instanceof HTMLInputElement)) return;
            child.checked = input.checked;
            child.indeterminate = false;
            setSource(String(child.dataset.aclSource || child.value), input.checked, child);
            if (!input.checked) clearCoveringTagGrants(String(child.dataset.aclSource || child.value));
        });
    }

    function cascadeTag(input) {
        const branch = input.closest('.w-acl-tree__branch');
        if (!branch) return;
        if (!input.checked) {
            branch.querySelectorAll('input[data-acl-tag]').forEach((tag) => {
                if (tag instanceof HTMLInputElement) tag.checked = false;
            });
        }
        branch.querySelectorAll('input[data-acl-source]').forEach((leaf) => {
            if (!(leaf instanceof HTMLInputElement)) return;
            leaf.checked = input.checked;
            setSource(String(leaf.dataset.aclSource || leaf.value), input.checked, leaf);
        });
    }

    root.addEventListener('click', (event) => {
        if (event.target.closest('summary .w-acl-tree__check')) event.stopPropagation();
        const view = event.target.closest('[data-acl-view]');
        if (view instanceof HTMLButtonElement) {
            activeView = view.dataset.aclView === 'tag' ? 'tag' : 'menu';
            root.querySelectorAll('[data-acl-view]').forEach((button) => {
                button.setAttribute('aria-selected', button === view ? 'true' : 'false');
            });
            root.querySelectorAll('[data-acl-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.aclPanel !== activeView;
            });
            applyFilters();
            return;
        }

        const command = event.target.closest('[data-acl-command]');
        if (!(command instanceof HTMLButtonElement)) return;
        const tree = activeTree();
        if (!tree) return;
        const action = String(command.dataset.aclCommand || '');
        if (action === 'expand' || action === 'collapse') {
            tree.querySelectorAll('details').forEach((details) => { details.open = action === 'expand'; });
            return;
        }
        const checked = action === 'select';
        tree.querySelectorAll('input[data-acl-source]').forEach((input) => {
            if (!(input instanceof HTMLInputElement) || input.disabled || input.closest('[data-acl-node]')?.hidden) return;
            input.checked = checked;
            input.indeterminate = false;
            setSource(String(input.dataset.aclSource || input.value), checked, input);
            if (!checked) clearCoveringTagGrants(String(input.dataset.aclSource || input.value));
        });
        if (!checked && activeView === 'tag') {
            tree.querySelectorAll('input[data-acl-tag]').forEach((input) => { input.checked = false; });
        }
        updateMenuBranches();
        updateStatistics();
    });

    root.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') return;
        if (input.matches('[data-acl-tag]')) {
            cascadeTag(input);
        } else if (input.matches('[data-acl-source]')) {
            const sourceId = String(input.dataset.aclSource || input.value);
            setSource(sourceId, input.checked, input);
            if (menuTree?.contains(input)) cascadeMenu(input);
            if (!input.checked) clearCoveringTagGrants(sourceId);
        }
        updateMenuBranches();
        updateStatistics();
    });

    search?.addEventListener('input', applyFilters);
    moduleFilter?.addEventListener('change', applyFilters);
    typeFilter?.addEventListener('change', applyFilters);

    root.addEventListener('submit', () => {
        if (!(saveButton instanceof HTMLButtonElement)) return;
        saveButton.disabled = true;
        const label = saveButton.querySelector('span');
        if (label) label.textContent = String(root.dataset.textSaving || '');
    });

    document.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey)) return;
        if (event.key.toLocaleLowerCase() === 'f') {
            event.preventDefault();
            search?.focus();
        }
        if (event.key.toLocaleLowerCase() === 's') {
            event.preventDefault();
            root.requestSubmit();
        }
    });

    syncInitialSources();
}
