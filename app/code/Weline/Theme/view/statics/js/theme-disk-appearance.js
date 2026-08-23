/**
 * Theme disk appearance (整盘) — additive overlay for the classic ThemeEditor.
 * Does not touch widget drag/drop / layout editing.
 */
(function () {
    'use strict';

    function ui() {
        const current = window.Weline?.UI;
        if (!current) throw new Error('Weline.UI must be loaded before Theme Disk Appearance.');
        return current;
    }

    function toast(message, type) {
        const tone = type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info';
        return ui().toast.show(String(message ?? ''), { tone, duration: 3000 });
    }

    function looksLikeColorValue(value) {
        return /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(String(value || '').trim());
    }

    /** <input type="color"> 只接受 #rrggbb；3 位 hex 需展开，否则赋值会抛错并中断整表渲染。 */
    function toColorInputValue(value) {
        const text = String(value || '').trim();
        const m3 = text.match(/^#([0-9a-f]{3})$/i);
        if (m3) {
            const [r, g, b] = m3[1].split('');
            return `#${r}${r}${g}${g}${b}${b}`.toLowerCase();
        }
        const m6 = text.match(/^#([0-9a-f]{6})([0-9a-f]{2})?$/i);
        if (m6) {
            return `#${m6[1]}`.toLowerCase();
        }
        return '#000000';
    }

    function collectInheritTokens(panel, disk) {
        const tokens = {};
        const list = Array.isArray(disk && disk.tokens) ? disk.tokens : [];
        list.forEach((token) => {
            if (!token || typeof token !== 'object') return;
            const name = String(token.variable_name || token.name || '').trim();
            if (!name.startsWith('--')) return;
            if (panel === 'color') {
                const role = String(token.role || token.palette_role || '').toLowerCase();
                const lateSafe = role === 'brand' || role === 'functional'
                    || /primary|accent|secondary|success|warning|danger|info|link/.test(name);
                if (!lateSafe) return;
            }
            tokens[name] = String(token.default_value ?? token.value ?? '');
        });
        return tokens;
    }

    async function requestJson(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(options.headers || {}),
        };
        let body = options.body;
        if (body && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof URLSearchParams)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
        const editor = window.Weline?.Theme?.Editor;
        if (!editor || typeof editor.apiJson !== 'function') {
            throw new Error('Weline.Theme.Editor API is unavailable.');
        }
        const payload = await editor.apiJson(url, {
            method,
            headers,
            body: method === 'GET' || method === 'HEAD' ? undefined : body,
        });
        if (payload && payload.success === false) {
            throw new Error(String(payload.message || '请求失败'));
        }
        return payload;
    }

    function boot() {
        const root = document.getElementById('themeEditor');
        const modalEl = document.getElementById('themeDiskAppearanceModal');
        if (!(root instanceof HTMLElement) || !(modalEl instanceof HTMLElement)) {
            return;
        }

        const panelSelect = modalEl.querySelector('[data-w-appearance-panel]');
        const disksEl = modalEl.querySelector('[data-w-appearance-disks]');
        const editorEl = modalEl.querySelector('[data-w-appearance-editor]');
        const emptyEl = modalEl.querySelector('[data-w-appearance-empty]');
        const footerEl = modalEl.querySelector('[data-w-appearance-footer]');
        const editorTitleEl = modalEl.querySelector('[data-w-appearance-editor-title]');
        const nameInput = modalEl.querySelector('[data-w-appearance-name]');
        const tokensEl = modalEl.querySelector('[data-w-appearance-tokens]');

        const openPanel = () => {
            return ui().drawer.open(modalEl);
        };

        const setEditorVisible = (visible, title) => {
            if (editorEl instanceof HTMLElement) editorEl.hidden = !visible;
            if (emptyEl instanceof HTMLElement) emptyEl.hidden = !!visible;
            if (footerEl instanceof HTMLElement) footerEl.hidden = !visible;
            if (visible && editorTitleEl instanceof HTMLElement && title) {
                editorTitleEl.textContent = title;
            }
        };

        let appearanceState = null;
        let scopedWorkspace = null;
        const draft = { panel: 'color', base_file: '', disk_key: '', tokens: {}, mode: '' };

        const identity = () => {
            const themeSelect = document.getElementById('themeSelect');
            const areaSelect = document.getElementById('editorAreaSelect');
            const editor = window.Weline?.Theme?.Editor;
            const diskScope = String(editor?.getLegacyScope?.() || root.dataset.scope || 'default.default.default').trim();
            return {
                theme_id: Number(themeSelect && themeSelect.value ? themeSelect.value : root.dataset.themeId || 0),
                editor_area: String(areaSelect && areaSelect.value ? areaSelect.value : root.dataset.editorArea || 'frontend'),
                scope: diskScope,
                editor_context: JSON.stringify(editor?.buildTypedEditorContext?.('appearance') || {}),
            };
        };

        const pointerSegment = (value) => String(value ?? '').replace(/~/g, '~0').replace(/\//g, '~1');

        const queueAppearanceChanges = async (changes, summary) => {
            const editor = window.Weline?.Theme?.Editor;
            if (!editor || typeof editor.queueScopedChanges !== 'function') {
                throw new Error('主题 Scope 工作区不可用');
            }
            scopedWorkspace = await editor.queueScopedChanges('appearance', changes, { summary });
            return scopedWorkspace;
        };

        const ownedPaths = () => new Set(Array.isArray(scopedWorkspace?.owned_paths)
            ? scopedWorkspace.owned_paths
            : []);

        const ownershipBadge = (path) => {
            const owned = ownedPaths().has(path);
            const badge = document.createElement('span');
            badge.className = 'w-badge theme-config-ownership__badge';
            badge.dataset.owned = owned ? 'true' : 'false';
            badge.textContent = owned ? '本级修改' : '继承值';
            return badge;
        };

        const apiUrl = (key, query) => {
            const base = root.dataset[key] || '';
            if (!base) return '';
            const url = new URL(base, window.location.origin);
            Object.entries(query || {}).forEach(([k, v]) => {
                if (v === undefined || v === null || v === '') return;
                url.searchParams.set(k, String(v));
            });
            return url.pathname + url.search;
        };

        const findCatalogDisk = (panel, key) => {
            const disks = appearanceState?.catalog?.panels?.[panel]?.disks;
            if (!Array.isArray(disks)) return null;
            const needle = String(key || '').replace(/^_+/, '');
            return disks.find((item) => String(item.key || '').replace(/^_+/, '') === needle) || null;
        };

        /** Meta 只存 delta：编辑时用「原生可继承基线 + 已存 delta」合成完整可编辑表。 */
        const resolveEditableTokens = (panel, baseFile, deltaTokens) => {
            const baseDisk = findCatalogDisk(panel, baseFile);
            const base = baseDisk ? collectInheritTokens(panel, baseDisk) : {};
            const delta = deltaTokens && typeof deltaTokens === 'object' ? deltaTokens : {};
            return { ...base, ...delta };
        };

        const makeButton = (label, tone = 'neutral', variant = 'outline') => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-button';
            btn.dataset.tone = tone;
            btn.dataset.size = 'sm';
            if (variant) btn.dataset.variant = variant;
            btn.textContent = label;
            return btn;
        };

        const renderTokenEditor = () => {
            if (!(tokensEl instanceof HTMLElement)) return;
            tokensEl.replaceChildren();
            const entries = Object.entries(draft.tokens);
            if (!entries.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = draft.mode === 'custom'
                    ? '未找到可编辑 Token（请确认该盘的 base_file 仍存在于原生盘中）'
                    : (draft.panel === 'color'
                        ? '该原生盘没有可继承的品牌/功能色 Token'
                        : '该原生盘没有可继承的变量 Token');
                tokensEl.append(empty);
                return;
            }
            entries.forEach(([name, value]) => {
                const card = document.createElement('div');
                card.className = 'w-theme-disk-token';
                const label = document.createElement('div');
                label.className = 'w-theme-disk-token__label';
                label.textContent = name;
                const controls = document.createElement('div');
                controls.className = 'w-theme-disk-token__controls';
                const text = String(value || '');
                const textInput = document.createElement('input');
                textInput.className = 'w-input w-theme-disk-token__value';
                textInput.dataset.size = 'sm';
                textInput.type = 'text';
                textInput.value = text;
                textInput.addEventListener('input', () => {
                    draft.tokens[name] = textInput.value;
                    if (colorInput && looksLikeColorValue(textInput.value)) {
                        colorInput.value = toColorInputValue(textInput.value);
                    }
                });
                let colorInput = null;
                if (looksLikeColorValue(text) || draft.panel === 'color') {
                    colorInput = document.createElement('input');
                    colorInput.className = 'w-theme-disk-token__color';
                    colorInput.type = 'color';
                    colorInput.value = toColorInputValue(text);
                    colorInput.title = name;
                    colorInput.addEventListener('input', () => {
                        draft.tokens[name] = colorInput.value;
                        textInput.value = colorInput.value;
                    });
                    controls.append(colorInput);
                }
                controls.append(textInput);
                card.append(label, controls);
                tokensEl.append(card);
            });
        };

        const startInheritEdit = (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'inherit';
            draft.base_file = String(disk.key || '');
            draft.disk_key = '';
            draft.tokens = collectInheritTokens(panel, disk);
            if (nameInput instanceof HTMLInputElement) {
                nameInput.value = `${disk.name || disk.key}-自定义`;
            }
            renderTokenEditor();
            setEditorVisible(true, '继承编辑 · 另存为我的盘');
            renderAppearance();
        };

        const startCustomEdit = (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'custom';
            draft.base_file = String(disk.base_file || '');
            draft.disk_key = String(disk.disk_key || '');
            draft.tokens = resolveEditableTokens(panel, draft.base_file, disk.tokens || {});
            if (nameInput instanceof HTMLInputElement) {
                nameInput.value = String(disk.name || disk.disk_key || '');
            }
            renderTokenEditor();
            setEditorVisible(true, '编辑我的盘');
            renderAppearance();
            if (!Object.keys(draft.tokens).length) {
                toast('该我的盘没有可编辑 Token，请检查继承来源原生盘是否仍存在', 'error');
            }
        };

        /** 当前已选用的盘直接展开右侧编辑，无需再点「编辑 / 继承编辑」。 */
        const openActiveEditor = () => {
            if (!appearanceState) {
                setEditorVisible(false);
                return;
            }
            const panelState = (appearanceState.state?.panels || []).find((item) => item.panel === draft.panel)
                || { active: '', custom: [] };
            const active = String(panelState.active || '').trim();
            if (!active) {
                setEditorVisible(false);
                return;
            }
            if (active.startsWith('custom:')) {
                const customs = Array.isArray(panelState.custom) ? panelState.custom : [];
                const current = customs.find((item) =>
                    String(item.ref || '') === active
                    || `custom:${String(item.disk_key || '')}` === active);
                if (current) {
                    startCustomEdit(current);
                    return;
                }
                setEditorVisible(false);
                return;
            }
            const panelCatalog = appearanceState.catalog?.panels?.[draft.panel] || { disks: [] };
            const native = (panelCatalog.disks || []).find((item) => String(item.ref || '') === active);
            if (native && native.palette_role !== 'mode') {
                startInheritEdit(native);
                return;
            }
            setEditorVisible(false);
        };

        const applyScopedDraft = () => {
            const payload = scopedWorkspace?.draft_payload;
            if (!appearanceState || !payload || typeof payload !== 'object') return;
            const active = payload.tokens && typeof payload.tokens === 'object' ? payload.tokens : {};
            const disks = payload.disks && typeof payload.disks === 'object' ? payload.disks : {};
            const panelStates = Array.isArray(appearanceState.state?.panels)
                ? appearanceState.state.panels
                : [];
            const panelCodes = new Set([
                ...Object.keys(appearanceState.catalog?.panels || {}),
                ...Object.keys(active),
                ...Object.keys(disks),
            ]);
            appearanceState.state = appearanceState.state || {};
            appearanceState.state.panels = Array.from(panelCodes).map((panel) => {
                const previous = panelStates.find((item) => item.panel === panel) || {};
                const scopedDisks = disks[panel] && typeof disks[panel] === 'object' ? disks[panel] : {};
                const custom = Object.entries(scopedDisks)
                    .filter(([, item]) => item && typeof item === 'object')
                    .map(([diskKey, item]) => ({
                        ...item,
                        disk_key: diskKey,
                        ref: `custom:${diskKey}`,
                    }));
                return {
                    ...previous,
                    panel,
                    active: Object.prototype.hasOwnProperty.call(active, panel)
                        ? String(active[panel] ?? '')
                        : String(previous.active || ''),
                    custom,
                };
            });
        };

        const addPanelOwnership = (container, panel) => {
            const path = `/tokens/${pointerSegment(panel)}`;
            const row = document.createElement('div');
            row.className = 'theme-config-ownership w-theme-disk-scope-ownership';
            row.append(ownershipBadge(path));
            if (ownedPaths().has(path)) {
                const inherit = makeButton('恢复继承', 'neutral', 'link');
                inherit.addEventListener('click', async () => {
                    try {
                        await queueAppearanceChanges([{ op: 'inherit', path }], 'appearance_active_inherit');
                        applyScopedDraft();
                        renderAppearance();
                        toast('已恢复继承（发布后生效）', 'success');
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                row.append(inherit);
            }
            container.append(row);
        };

        const renderAppearance = () => {
            if (!appearanceState || !(panelSelect instanceof HTMLSelectElement) || !(disksEl instanceof HTMLElement)) return;
            const panels = appearanceState.catalog?.panels || {};
            const codes = Object.keys(panels);
            if (!codes.includes(draft.panel) && codes.length) {
                draft.panel = codes.includes('color') ? 'color' : codes[0];
            }
            panelSelect.replaceChildren();
            codes.forEach((code) => {
                const opt = document.createElement('option');
                opt.value = code;
                opt.textContent = code;
                if (code === draft.panel) opt.selected = true;
                panelSelect.append(opt);
            });

            disksEl.replaceChildren();
            const panel = panels[draft.panel] || { disks: [] };
            const panelState = (appearanceState.state?.panels || []).find((item) => item.panel === draft.panel)
                || { active: '', custom: [] };

            addPanelOwnership(disksEl, draft.panel);

            const nativeTitle = document.createElement('div');
            nativeTitle.className = 'w-theme-disk-section-title';
            nativeTitle.textContent = '原生盘';
            disksEl.append(nativeTitle);

            (panel.disks || []).forEach((disk) => {
                if (disk.palette_role === 'mode') {
                    const note = document.createElement('p');
                    note.className = 'w-theme-disk-mode-note';
                    note.textContent = `${disk.name || disk.key}（模式层，双载，不可单选替换）`;
                    disksEl.append(note);
                    return;
                }
                const row = document.createElement('div');
                row.className = 'w-theme-disk-row';
                if (panelState.active === disk.ref) row.classList.add('is-active');
                if (draft.mode === 'inherit' && draft.base_file === String(disk.key || '')) {
                    row.classList.add('is-editing');
                }
                const label = document.createElement('span');
                label.className = 'w-theme-disk-row__name';
                label.textContent = disk.name || disk.key;
                const actions = document.createElement('div');
                actions.className = 'w-theme-disk-row__actions';
                const selectBtn = makeButton(
                    panelState.active === disk.ref ? '已选用' : '选用',
                    'primary',
                );
                selectBtn.disabled = panelState.active === disk.ref;
                selectBtn.addEventListener('click', async () => {
                    try {
                        await requestJson(root.dataset.apiDiskSelect, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, ref: disk.ref },
                        });
                        await queueAppearanceChanges([{
                            op: 'set',
                            path: `/tokens/${pointerSegment(draft.panel)}`,
                            value: disk.ref,
                        }], 'appearance_disk_selected');
                        toast('色盘选择已保存', 'success');
                        await loadAppearance();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                const inheritBtn = makeButton('继承编辑');
                inheritBtn.addEventListener('click', () => startInheritEdit(disk));
                actions.append(selectBtn, inheritBtn);
                row.append(label, actions);
                disksEl.append(row);
            });

            const customTitle = document.createElement('div');
            customTitle.className = 'w-theme-disk-section-title';
            customTitle.textContent = '我的盘';
            disksEl.append(customTitle);
            const customs = Array.isArray(panelState.custom) ? panelState.custom : [];
            if (!customs.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = '暂无我的盘';
                disksEl.append(empty);
                return;
            }
            customs.forEach((disk) => {
                const row = document.createElement('div');
                row.className = 'w-theme-disk-row';
                if (panelState.active === disk.ref) row.classList.add('is-active');
                if (draft.mode === 'custom' && draft.disk_key === String(disk.disk_key || '')) {
                    row.classList.add('is-editing');
                }
                const label = document.createElement('span');
                label.className = 'w-theme-disk-row__name';
                label.textContent = disk.name || disk.disk_key;
                const diskPath = `/disks/${pointerSegment(draft.panel)}/${pointerSegment(disk.disk_key)}`;
                label.append(ownershipBadge(diskPath));
                const actions = document.createElement('div');
                actions.className = 'w-theme-disk-row__actions';
                const selectBtn = makeButton(
                    panelState.active === disk.ref ? '已选用' : '选用',
                    'primary',
                );
                selectBtn.disabled = panelState.active === disk.ref;
                selectBtn.addEventListener('click', async () => {
                    try {
                        await requestJson(root.dataset.apiDiskSelect, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, ref: disk.ref },
                        });
                        await queueAppearanceChanges([{
                            op: 'set',
                            path: `/tokens/${pointerSegment(draft.panel)}`,
                            value: disk.ref,
                        }], 'appearance_disk_selected');
                        toast('色盘选择已保存', 'success');
                        await loadAppearance();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                const editBtn = makeButton('编辑');
                editBtn.addEventListener('click', () => startCustomEdit(disk));
                const deleteBtn = makeButton('删除', 'danger');
                deleteBtn.addEventListener('click', async () => {
                    const confirmed = await ui().dialog.confirm('删除后无法恢复该自定义主题盘。', {
                        title: '删除我的盘',
                        confirmLabel: '删除',
                        cancelLabel: '取消',
                        dangerous: true,
                    });
                    if (!confirmed) return;
                    try {
                        await requestJson(root.dataset.apiDiskDelete, {
                            method: 'POST',
                            body: { ...identity(), panel: draft.panel, disk_key: disk.disk_key },
                        });
                        const changes = [{
                            op: 'set',
                            path: diskPath,
                            value: null,
                        }];
                        if (panelState.active === disk.ref) {
                            changes.push({
                                op: 'set',
                                path: `/tokens/${pointerSegment(draft.panel)}`,
                                value: '',
                            });
                        }
                        await queueAppearanceChanges(changes, 'appearance_disk_deleted');
                        toast('已删除我的盘', 'success');
                        await loadAppearance();
                    } catch (error) {
                        toast(error instanceof Error ? error.message : String(error), 'error');
                    }
                });
                actions.append(selectBtn, editBtn, deleteBtn);
                if (ownedPaths().has(diskPath)) {
                    const inheritBtn = makeButton('恢复继承', 'neutral', 'link');
                    inheritBtn.addEventListener('click', async () => {
                        try {
                            await queueAppearanceChanges([{ op: 'inherit', path: diskPath }], 'appearance_disk_inherit');
                            applyScopedDraft();
                            renderAppearance();
                            toast('已恢复继承（发布后生效）', 'success');
                        } catch (error) {
                            toast(error instanceof Error ? error.message : String(error), 'error');
                        }
                    });
                    actions.append(inheritBtn);
                }
                row.append(label, actions);
                disksEl.append(row);
            });
        };

        const loadAppearance = async () => {
            const url = apiUrl('apiThemeTokens', identity());
            const loaded = await requestJson(url, { method: 'GET' });
            appearanceState = loaded?.data || loaded;
            const editor = window.Weline?.Theme?.Editor;
            scopedWorkspace = typeof editor?.loadScopedWorkspace === 'function'
                ? await editor.loadScopedWorkspace('appearance')
                : null;
            applyScopedDraft();
            draft.mode = '';
            draft.disk_key = '';
            draft.base_file = '';
            draft.tokens = {};
            setEditorVisible(false);
            renderAppearance();
            openActiveEditor();
        };

        const saveAppearance = async (asNew) => {
            const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
            if (!Object.keys(draft.tokens).length) {
                throw new Error('没有可保存的 Token，请先点「继承编辑」或「编辑」');
            }
            const endpoint = asNew ? root.dataset.apiDiskSaveAs : root.dataset.apiDiskSave;
            const result = await requestJson(endpoint, {
                method: 'POST',
                body: {
                    ...identity(),
                    panel: draft.panel,
                    name,
                    base_file: draft.base_file,
                    disk_key: asNew ? '' : draft.disk_key,
                    tokens: draft.tokens,
                },
            });
            draft.disk_key = String(result?.data?.disk_key || draft.disk_key || '');
            const savedKey = draft.disk_key;
            const baseTokens = draft.base_file
                ? collectInheritTokens(draft.panel, findCatalogDisk(draft.panel, draft.base_file) || {})
                : {};
            const deltaTokens = Object.fromEntries(Object.entries(draft.tokens).filter(([token, value]) =>
                !Object.prototype.hasOwnProperty.call(baseTokens, token)
                    || String(baseTokens[token]) !== String(value)));
            await queueAppearanceChanges([
                {
                    op: 'set',
                    path: `/disks/${pointerSegment(draft.panel)}/${pointerSegment(savedKey)}`,
                    value: {
                        name: name || savedKey,
                        base_file: draft.base_file,
                        disk_kind: draft.panel === 'color' ? 'colors' : 'variables',
                        tokens: deltaTokens,
                    },
                },
                {
                    op: 'set',
                    path: `/tokens/${pointerSegment(draft.panel)}`,
                    value: `custom:${savedKey}`,
                },
            ], 'appearance_disk_saved');
            toast(String(result?.message || '主题盘已保存'), 'success');
            // loadAppearance → openActiveEditor：保存后继续展开当前已选用盘
            await loadAppearance();
        };

        const openAndLoad = async () => {
            openPanel();
            try {
                await loadAppearance();
            } catch (error) {
                toast(error instanceof Error ? error.message : String(error), 'error');
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;
            const openHit = target.closest('#btnThemeDiskAppearance');
            if (openHit) {
                event.preventDefault();
                openAndLoad();
                return;
            }
            const action = target.closest('[data-w-appearance-action]');
            if (!(action instanceof HTMLButtonElement) || !modalEl.contains(action)) return;
            const kind = action.dataset.wAppearanceAction || '';
            if (kind !== 'save-as' && kind !== 'save') return;
            action.disabled = true;
            saveAppearance(kind === 'save-as')
                .catch((error) => toast(error instanceof Error ? error.message : String(error), 'error'))
                .finally(() => {
                    action.disabled = false;
                });
        });

        if (panelSelect instanceof HTMLSelectElement) {
            panelSelect.addEventListener('change', () => {
                draft.panel = panelSelect.value || 'color';
                draft.base_file = '';
                draft.disk_key = '';
                draft.tokens = {};
                draft.mode = '';
                setEditorVisible(false);
                renderAppearance();
                openActiveEditor();
            });
        }

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
