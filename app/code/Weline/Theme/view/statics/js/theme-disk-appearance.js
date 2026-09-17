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
        const tokenMeta = {};
        const list = Array.isArray(disk && disk.tokens) ? disk.tokens : [];
        list.forEach((token) => {
            if (!token || typeof token !== 'object') return;
            const name = String(token.variable_name || token.name || '').trim();
            if (!name.startsWith('--')) return;
            if (panel === 'color') {
                const role = String(token.role || token.palette_role || '').toLowerCase();
                // 默认可继承语义合同：brand/status + neutral 字/面/边，编辑后须能驱动前台基础组件
                const lateSafe = role === 'brand' || role === 'functional' || role === 'neutral'
                    || /primary|accent|secondary|success|warning|danger|error|info|link|text|surface|border|canvas|overlay|on-|bg-/.test(name);
                if (!lateSafe) return;
            }
            tokens[name] = String(token.default_value ?? token.value ?? '');
            const category = String(token.category || '').trim();
            if (!category || category === '其他') {
                return;
            }
            const groupId = String(token.category_id || '').trim();
            const label = String(token.category_label || category).trim() || category;
            tokenMeta[name] = {
                groupId: groupId || label,
                label,
            };
        });
        return { tokens, tokenMeta };
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
        const tokenSearchEl = modalEl.querySelector('[data-w-appearance-token-search]');
        const tokenCountEl = modalEl.querySelector('[data-w-appearance-token-count]');

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
        const draft = { panel: 'color', base_file: '', disk_key: '', tokens: {}, tokenMeta: {}, mode: '' };
        let previewTokenTimer = 0;

        const getPreviewDocument = () => {
            const frame = document.getElementById('previewFrame');
            if (!(frame instanceof HTMLIFrameElement)) return null;
            try {
                return frame.contentDocument || frame.contentWindow?.document || null;
            } catch (_error) {
                return null;
            }
        };

        /** 编辑中即时把 Token 写进预览 iframe，不等待保存/整页刷新。 */
        const applyAppearancePreviewTokens = (tokens) => {
            const doc = getPreviewDocument();
            if (!doc || !doc.documentElement) return false;
            const map = tokens && typeof tokens === 'object' ? tokens : {};
            const root = doc.documentElement;
            const lines = [];
            Object.entries(map).forEach(([name, value]) => {
                const token = String(name || '').trim();
                const cssValue = String(value ?? '').trim();
                if (!token.startsWith('--') || !cssValue) return;
                root.style.setProperty(token, cssValue);
                lines.push(`  ${token}: ${cssValue};`);
            });
            let styleEl = doc.querySelector('style[data-theme-scoped-preview-appearance]');
            if (!(styleEl instanceof HTMLStyleElement)) {
                styleEl = doc.createElement('style');
                styleEl.setAttribute('data-theme-scoped-preview-appearance', '1');
                (doc.head || doc.documentElement).appendChild(styleEl);
            }
            styleEl.textContent = lines.length
                ? `:root {\n${lines.join('\n')}\n}`
                : '';
            return true;
        };

        const scheduleAppearancePreviewTokens = () => {
            window.clearTimeout(previewTokenTimer);
            previewTokenTimer = window.setTimeout(() => {
                applyAppearancePreviewTokens(draft.tokens);
            }, 80);
        };

        const refreshLayoutPreview = () => {
            const editor = window.Weline?.Theme?.Editor;
            if (typeof editor?.refreshPreview === 'function') {
                editor.refreshPreview();
                return;
            }
            applyAppearancePreviewTokens(draft.tokens);
        };

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

        const loadDiskTokens = async (panel, disk) => {
            if (!disk || typeof disk !== 'object') {
                return { tokens: {}, tokenMeta: {} };
            }
            if (Array.isArray(disk.tokens) && disk.tokens.length > 0) {
                return collectInheritTokens(panel, disk);
            }
            const ref = String(disk.ref || '').trim();
            if (!ref) {
                return { tokens: {}, tokenMeta: {} };
            }
            const url = apiUrl('apiThemeDiskTokens', {
                ...identity(),
                panel,
                ref,
            });
            const loaded = await requestJson(url, { method: 'GET' });
            const tokensJson = loaded?.data?.tokens_json;
            if (typeof tokensJson === 'string' && tokensJson.trim() !== '') {
                try {
                    const parsed = JSON.parse(tokensJson);
                    if (Array.isArray(parsed)) {
                        disk.tokens = parsed;
                    }
                } catch (_error) {
                    // ignore malformed token payload
                }
            } else if (Array.isArray(loaded?.data?.tokens)) {
                disk.tokens = loaded.data.tokens;
            }
            return collectInheritTokens(panel, disk);
        };

        /** Meta 只存 delta：编辑时用「原生可继承基线 + 已存 delta」合成完整可编辑表。 */
        const resolveEditableTokens = async (panel, baseFile, deltaTokens) => {
            const baseDisk = findCatalogDisk(panel, baseFile);
            const base = baseDisk
                ? await loadDiskTokens(panel, baseDisk)
                : { tokens: {}, tokenMeta: {} };
            const delta = deltaTokens && typeof deltaTokens === 'object' ? deltaTokens : {};
            return {
                tokens: { ...base.tokens, ...delta },
                tokenMeta: base.tokenMeta || {},
            };
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

        const tokenSearchQuery = () => {
            if (!(tokenSearchEl instanceof HTMLInputElement)) return '';
            return String(tokenSearchEl.value || '').trim().toLowerCase();
        };

        const updateTokenCount = (shown, total, filtered) => {
            if (!(tokenCountEl instanceof HTMLElement)) return;
            if (!total) {
                tokenCountEl.hidden = true;
                tokenCountEl.textContent = '';
                return;
            }
            tokenCountEl.hidden = false;
            tokenCountEl.textContent = filtered
                ? `显示 ${shown} / ${total}`
                : `${total} 个变量`;
        };

        const matchesTokenSearch = (name, value, query) => {
            if (!query) return true;
            return String(name || '').toLowerCase().includes(query)
                || String(value || '').toLowerCase().includes(query);
        };

        const FONT_PRESET_OPTIONS = {
            '--font-family-base': [
                { id: 'noto-serif-sc', label: '思源宋体（正文默认）', stack: '"Noto Serif SC", "Songti SC", "Source Han Serif SC", "STSong", "SimSun", "Noto Sans SC", "PingFang SC", serif' },
                { id: 'lxgw-wenkai', label: '霞鹜文楷', stack: '"LXGW WenKai", "Kaiti SC", "STKaiti", "KaiTi", "Noto Serif SC", "Songti SC", serif' },
                { id: 'noto-sans-sc', label: '思源黑体', stack: '"Noto Sans SC", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif' },
                { id: 'songti-system', label: '系统宋体', stack: '"Songti SC", "STSong", "SimSun", "Noto Serif SC", serif' },
            ],
            '--font-family-display': [
                { id: 'lxgw-wenkai', label: '霞鹜文楷（标题默认）', stack: '"LXGW WenKai", "Kaiti SC", "STKaiti", "KaiTi", "Noto Serif SC", "Songti SC", serif' },
                { id: 'zcool-xiaowei', label: '站酷小薇', stack: '"ZCOOL XiaoWei", "LXGW WenKai", "Kaiti SC", "Noto Serif SC", "Songti SC", serif' },
                { id: 'kaiti-system', label: '系统楷体', stack: '"Kaiti SC", "STKaiti", "KaiTi", "LXGW WenKai", "Noto Serif SC", serif' },
                { id: 'noto-serif-sc', label: '思源宋体', stack: '"Noto Serif SC", "Songti SC", "Source Han Serif SC", "STSong", "SimSun", "Noto Sans SC", "PingFang SC", serif' },
            ],
            '--font-family-ui': [
                { id: 'noto-serif-sc', label: '思源宋体（界面默认）', stack: '"Noto Serif SC", "Songti SC", "Noto Sans SC", "PingFang SC", serif' },
                { id: 'lxgw-wenkai', label: '霞鹜文楷', stack: '"LXGW WenKai", "Kaiti SC", "STKaiti", "KaiTi", "Noto Serif SC", "Songti SC", serif' },
                { id: 'noto-sans-sc', label: '思源黑体', stack: '"Noto Sans SC", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif' },
            ],
            '--font-family-serif': [
                { id: 'noto-serif-sc', label: '思源宋体', stack: '"Noto Serif SC", "Songti SC", "Source Han Serif SC", "STSong", "SimSun", Georgia, "Times New Roman", serif' },
                { id: 'songti-system', label: '系统宋体', stack: '"Songti SC", "STSong", "SimSun", "Noto Serif SC", serif' },
            ],
        };

        const normalizeFontStack = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const matchFontPresetId = (tokenName, value) => {
            const opts = FONT_PRESET_OPTIONS[tokenName] || [];
            const needle = normalizeFontStack(value);
            const hit = opts.find((item) => normalizeFontStack(item.stack) === needle);
            return hit ? hit.id : '';
        };

        const appendFontPresetSelect = (controls, tokenName, textInput) => {
            const opts = FONT_PRESET_OPTIONS[tokenName];
            if (!opts || !opts.length) {
                return;
            }
            const select = document.createElement('select');
            select.className = 'w-select w-theme-disk-token__font-preset';
            select.dataset.size = 'sm';
            select.setAttribute('aria-label', `${tokenName} 字体预设`);
            const custom = document.createElement('option');
            custom.value = '';
            custom.textContent = '自定义…';
            select.append(custom);
            opts.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.label;
                option.dataset.stack = item.stack;
                select.append(option);
            });
            select.value = matchFontPresetId(tokenName, textInput.value);
            select.addEventListener('change', () => {
                const chosen = opts.find((item) => item.id === select.value);
                if (!chosen) {
                    return;
                }
                textInput.value = chosen.stack;
                draft.tokens[tokenName] = chosen.stack;
                scheduleAppearancePreviewTokens();
            });
            textInput.addEventListener('input', () => {
                select.value = matchFontPresetId(tokenName, textInput.value);
            });
            controls.append(select);
        };

        /**
         * Comment-section groups first (category_label from API / Meta i18n),
         * then universal prefix stem fallback (technical --stem, not translated).
         */
        const APPEARANCE_TOKEN_LEAF = /^(xs|sm|md|lg|xl|xxl|2xl|3xl|4xl|2_5xl|0_5|1_5|2_5|3_5|4_5|5_5|7|9|11|13|15|px|none|thin|thick|medium|full|base|normal|tight|relaxed|loose|wide|light|dark|soft|mid|strong|hot|deep|ember|amber|brown|soft-2|soft-3|soft-4|hover|active|visited|focus|disabled|placeholder|inverse|subtle|emphasis|muted|tertiary|secondary|primary|rgb|bg|text|border|on|ink|title|glow|inset)$/i;

        const appearanceTokenPrefix = (name) => {
            const bare = String(name || '').replace(/^--/, '').trim();
            if (!bare) return 'other';
            const parts = bare.split('-').filter(Boolean);
            if (!parts.length) return 'other';
            while (parts.length > 1 && APPEARANCE_TOKEN_LEAF.test(parts[parts.length - 1])) {
                parts.pop();
            }
            if (parts.length === 1) return parts[0];
            if (parts.length === 2) return parts.join('-');
            // Keep semantic families like backend-color-success / color-primary together.
            if (/^(backend-color|color|weline-theme|weline-chrome)$/i.test(parts.slice(0, 2).join('-'))) {
                return parts.slice(0, 3).join('-');
            }
            return parts.slice(0, 2).join('-');
        };

        const resolveAppearanceTokenGroup = (tokenName) => {
            const meta = draft.tokenMeta && draft.tokenMeta[tokenName];
            const label = meta && String(meta.label || '').trim();
            if (label && label !== '其他') {
                const groupId = String(meta.groupId || label).trim() || label;
                return {
                    id: `c:${groupId}`,
                    label,
                };
            }
            const prefix = appearanceTokenPrefix(tokenName);
            return {
                id: `p:${prefix}`,
                label: `--${prefix}`,
            };
        };

        const groupAppearanceTokenEntries = (entries) => {
            const order = [];
            const buckets = new Map();
            const groupMeta = new Map();
            entries.forEach((entry) => {
                const group = resolveAppearanceTokenGroup(entry[0]);
                if (!buckets.has(group.id)) {
                    buckets.set(group.id, []);
                    groupMeta.set(group.id, group);
                    order.push(group.id);
                }
                buckets.get(group.id).push(entry);
            });
            return order.map((id) => ({
                group: groupMeta.get(id),
                entries: buckets.get(id) || [],
            }));
        };

        const renderTokenEditor = () => {
            if (!(tokensEl instanceof HTMLElement)) return;
            tokensEl.replaceChildren();
            const query = tokenSearchQuery();
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
                updateTokenCount(0, 0, false);
                return;
            }
            const matched = query
                ? entries.filter(([name, value]) => matchesTokenSearch(name, value, query))
                : entries;
            if (!matched.length) {
                const empty = document.createElement('p');
                empty.className = 'w-theme-disk-empty';
                empty.textContent = '没有匹配的变量';
                tokensEl.append(empty);
                updateTokenCount(0, entries.length, true);
                return;
            }
            const grouped = groupAppearanceTokenEntries(matched);
            grouped.forEach(({ group, entries: groupEntries }) => {
                if (group) {
                    const heading = document.createElement('h6');
                    heading.className = 'w-theme-disk-token-group';
                    heading.setAttribute('data-w-appearance-token-group', group.id);
                    heading.textContent = group.label;
                    tokensEl.append(heading);
                }
                groupEntries.forEach(([name, value]) => {
                    const card = document.createElement('div');
                    card.className = 'w-theme-disk-token';
                    card.dataset.tokenName = name;
                    if (group) {
                        card.dataset.tokenGroup = group.id;
                    }
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
                        scheduleAppearancePreviewTokens();
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
                            scheduleAppearancePreviewTokens();
                        });
                        controls.append(colorInput);
                    }
                    controls.append(textInput);
                    appendFontPresetSelect(controls, name, textInput);
                    card.append(label, controls);
                    tokensEl.append(card);
                });
            });
            updateTokenCount(matched.length, entries.length, !!query);
            scheduleAppearancePreviewTokens();
        };

        const startInheritEdit = async (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'inherit';
            draft.base_file = String(disk.key || '');
            draft.disk_key = '';
            const loaded = await loadDiskTokens(panel, disk);
            draft.tokens = loaded.tokens || {};
            draft.tokenMeta = loaded.tokenMeta || {};
            if (nameInput instanceof HTMLInputElement) {
                nameInput.value = `${disk.name || disk.key}-自定义`;
            }
            renderTokenEditor();
            setEditorVisible(true, '继承编辑 · 另存为我的盘');
            renderAppearance();
        };

        const startCustomEdit = async (disk) => {
            const panel = panelSelect instanceof HTMLSelectElement && panelSelect.value
                ? panelSelect.value
                : draft.panel;
            draft.panel = panel;
            draft.mode = 'custom';
            draft.base_file = String(disk.base_file || '');
            draft.disk_key = String(disk.disk_key || '');
            const loaded = await resolveEditableTokens(panel, draft.base_file, disk.tokens || {});
            draft.tokens = loaded.tokens || {};
            draft.tokenMeta = loaded.tokenMeta || {};
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
        const openActiveEditor = async () => {
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
                    await startCustomEdit(current);
                    return;
                }
                setEditorVisible(false);
                return;
            }
            const panelCatalog = appearanceState.catalog?.panels?.[draft.panel] || { disks: [] };
            const native = (panelCatalog.disks || []).find((item) => String(item.ref || '') === active);
            if (native && native.palette_role !== 'mode') {
                await startInheritEdit(native);
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
                        refreshLayoutPreview();
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
                        refreshLayoutPreview();
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
            draft.tokenMeta = {};
            setEditorVisible(false);
            renderAppearance();
            await openActiveEditor();
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
            const baseDisk = draft.base_file ? findCatalogDisk(draft.panel, draft.base_file) : null;
            const baseLoaded = baseDisk ? await loadDiskTokens(draft.panel, baseDisk) : { tokens: {} };
            const baseTokens = baseLoaded.tokens || {};
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
            refreshLayoutPreview();
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

        if (tokenSearchEl instanceof HTMLInputElement) {
            tokenSearchEl.addEventListener('input', () => {
                renderTokenEditor();
            });
        }

        if (panelSelect instanceof HTMLSelectElement) {
            panelSelect.addEventListener('change', () => {
                draft.panel = panelSelect.value || 'color';
                draft.base_file = '';
                draft.disk_key = '';
                draft.tokens = {};
                draft.tokenMeta = {};
                draft.mode = '';
                if (tokenSearchEl instanceof HTMLInputElement) {
                    tokenSearchEl.value = '';
                }
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
