/**
 * Weline Theme Visual Editor
 */

document.addEventListener('DOMContentLoaded', function () {
    const config = window.ThemeEditorConfig;

    // DOM Elements
    const elements = {
        container: document.querySelector('.visual-editor-container'),
        iframe: document.getElementById('preview-iframe'),
        loading: document.getElementById('preview-loading'),
        propertiesPanel: document.getElementById('properties-panel'),
        propertiesTitle: document.getElementById('properties-title'),
        propertiesContent: document.getElementById('properties-content'),
        propertiesEmpty: document.getElementById('properties-empty'),
        propertiesFooter: document.getElementById('properties-footer'),
        layoutContainer: document.getElementById('layout-options-container'),
        settingsForm: document.getElementById('properties-form'),
        saveBtn: document.getElementById('save-properties-btn'),
        globalSaveBtn: document.getElementById('global-save-btn'),
        discardPreviewBtn: document.getElementById('discard-preview-btn'),
        layoutSwitcherContainer: document.getElementById('layout-switcher-container'),
        deviceButtons: document.querySelectorAll('[data-device]'),
        zoneWrappers: document.querySelectorAll('.zone-wrapper'),
        themeModeToggle: document.getElementById('theme-mode-toggle'),
        closePropertiesBtn: document.getElementById('close-properties'),
        // New Panel Elements
        structurePanel: document.getElementById('structure-panel'),
        collapseLeftBtn: document.getElementById('collapse-left-btn'),
        expandLeftBtn: document.getElementById('expand-left-btn'),
        collapseRightBtn: document.getElementById('collapse-right-btn'),
        expandRightBtn: document.getElementById('expand-right-btn'),
        layoutSearch: document.getElementById('layout-search')
    };

    // State
    let state = {
        activeZone: null,
        activeType: null,
        activeCategory: null,
        configData: { frontend: {}, backend: {} },
        optionsData: { frontend: null, backend: null },
        currentMode: 'light', // 'light' or 'dark'
        recentColors: JSON.parse(localStorage.getItem('ve-recent-colors') || '[]'),
        colorPresets: [
            '#000000', '#ffffff', '#f8f9fa', '#212529', '#6c757d',
            '#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545',
            '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0',
            '#ff9900', '#232f3e', '#37475a', '#146eb4', '#ff6600'
        ]
    };

    // Initialization
    init();

    function init() {
        console.log('Visual Editor initializing...');
        if (!config) {
            console.error('ThemeEditorConfig not found!');
            showToast('配置数据丢失', 'error');
            return;
        }

        // Auto-collapse sidebar if possible
        try {
            document.body.classList.add('sidebar-enable');
            document.body.setAttribute('data-sidebar-size', 'sm');

            // Check for existing theme preference
            const savedTheme = config.themeMode || localStorage.getItem('ve-theme-mode');
            if (savedTheme) {
                state.currentMode = savedTheme;
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                state.currentMode = 'dark';
            }

            // Apply theme mode
            applyThemeMode();

            // 初始化iframe加载事件处理（确保初始加载时也能隐藏loading�?
            if (elements.iframe && elements.loading) {
                // 显示初始加载状�?
                elements.loading.classList.add('show');
                
                // 处理iframe初始加载完成
                elements.iframe.addEventListener('load', function onInitialLoad() {
                    // 延迟隐藏，确保内容已渲染
                    setTimeout(() => {
                        elements.loading.classList.remove('show');
                    }, 300);
                    // 移除事件监听器，避免重复执行
                    elements.iframe.removeEventListener('load', onInitialLoad);
                }, { once: true });
            }

            // Load Initial Data
            loadOptions(config.area);

            // Event Listeners
            setupEventListeners();
            console.log('Visual Editor initialized successfully');
        } catch (e) {
            console.error('Initialization error:', e);
            showToast((config.translations.initFailed || 'Initialization failed') + ': ' + e.message, 'error');
        }
    }

    function applyThemeMode() {
        document.body.setAttribute('data-theme', state.currentMode);

        // Toggle icon
        const icon = elements.themeModeToggle.querySelector('i');
        if (state.currentMode === 'light') {
            icon.className = 'mdi mdi-weather-sunny';
        } else {
            icon.className = 'mdi mdi-weather-night';
        }
    }

    async function syncThemeMode(mode) {
        // Also save to local storage for speed
        localStorage.setItem('ve-theme-mode', mode);
        document.cookie = `weline_theme_mode=${mode}; path=/; max-age=31536000`;

        try {
            const formData = new FormData();
            formData.append('mode', mode);

            await fetch(config.urls.syncThemeMode, {
                method: 'POST',
                body: formData
            });
            console.log('Theme mode synced:', mode);
        } catch (e) {
            console.error('Failed to sync theme mode:', e);
        }
    }

    // --- Data Loading ---

    async function loadOptions(area) {
        try {
            const response = await fetch(`${config.urls.options}?theme_id=${config.themeId}&area=${area}&scope=${config.scope}&locale=${config.locale}`);
            const result = await response.json();

            if (result.code === 200) {
                state.optionsData[area] = result.data;
                state.configData[area] = result.data.config || {};

                // Update Layout Switcher
                renderLayoutSwitcher(result.data.layouts);

                // Update Structure Map based on available partials
                updateStructureMap(result.data.partials);

                console.log('Options loaded:', state.optionsData[area]);
            } else {
                showToast(result.msg || '加载配置失败', 'error');
            }
        } catch (error) {
            console.error('Failed to load options:', error);
            showToast('网络错误', 'error');
        }
    }

    function updateStructureMap(partials) {
        const area = config.area;
        const layoutConfig = state.configData[area].layouts || {};
        // Get active layout category for the 'Body' zone
        const bodyZone = document.querySelector('.zone-body');
        const activeCategory = bodyZone ? bodyZone.dataset.category : 'default';

        // Update body zone dataset for persistence
        if (bodyZone) {
            const currentLayout = layoutConfig[activeCategory] || 'default';
            // bodyZone.dataset.value = currentLayout; // Not used yet
        }

        // Sidebar Visibility Logic based on active layout params
        const leftSidebar = document.querySelector('.zone-sidebar-left');
        const rightSidebar = document.querySelector('.zone-sidebar-right');

        // Check if sidebars are enabled in the active layout config
        let showLeft = true;
        let showRight = true;

        const currentLayoutValue = layoutConfig[activeCategory] || 'default';
        const layouts = state.optionsData[area].layouts || {};
        const layoutOptions = layouts[activeCategory] || [];
        const layoutData = layoutOptions.find(o => o.value === currentLayoutValue);

        if (layoutData && layoutData.meta && layoutData.meta.params) {
            const params = layoutData.meta.params;
            // We can't easily get the saved parameter value here without a separate API call or it being in configData
            // For now, let's assume if it exists in configData, we use it, otherwise use default
            const savedParams = state.configData[area].params || {};
            const metaIdentify = `layouts.${activeCategory}.${currentLayoutValue}`;
            const activeParams = savedParams[metaIdentify] || {};

            if (activeParams['layout.leftsider'] !== undefined) {
                showLeft = activeParams['layout.leftsider'] == '1';
            } else if (params['layout.leftsider']) {
                showLeft = params['layout.leftsider'].default == '1';
            }

            if (activeParams['layout.rightsider'] !== undefined) {
                showRight = activeParams['layout.rightsider'] == '1';
            } else if (params['layout.rightsider']) {
                showRight = params['layout.rightsider'].default == '1';
            }
        }

        if (leftSidebar) leftSidebar.style.display = showLeft ? 'flex' : 'none';
        if (rightSidebar) rightSidebar.style.display = showRight ? 'flex' : 'none';
    }

    // --- Event Handling ---

    function setupEventListeners() {
        // Zone Selection
        elements.zoneWrappers.forEach(zone => {
            zone.addEventListener('click', () => {
                const type = zone.dataset.type;
                const category = zone.dataset.category;
                const zoneName = zone.querySelector('.zone-label').textContent;

                selectZone(zone, type, category, zoneName);
            });
        });

        // Global Settings Actions
        const globalActions = document.querySelectorAll('[data-action]');
        globalActions.forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                if (action === 'global-colors') {
                    openGlobalSettings('colors', 'colors', '全局配色');
                } else if (action === 'global-variables') {
                    openGlobalSettings('variable', 'variables', '全局变量');
                }
            });
        });

        // Properties Panel Tabs (Manual handling if BS fails)
        const tabs = document.querySelectorAll('#properties-tabs button');
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = tab.dataset.bsTarget;

                // Remove active class from all tabs and content
                document.querySelectorAll('#properties-tabs .nav-link').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));

                // Add active to current
                tab.classList.add('active');
                document.querySelector(targetId).classList.add('show', 'active');
            });
        });

        // Device Switching
        elements.deviceButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const device = btn.dataset.device;

                // Update active button
                elements.deviceButtons.forEach(b => b.classList.remove('active', 'btn-primary'));
                elements.deviceButtons.forEach(b => b.classList.add('btn-outline-secondary'));

                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('active', 'btn-primary');

                // Update container class
                const container = document.getElementById('preview-container');
                container.className = `preview-container ${device}`;
            });
        });

        // Theme Mode Toggle
        elements.themeModeToggle.addEventListener('click', () => {
            state.currentMode = state.currentMode === 'light' ? 'dark' : 'light';
            applyThemeMode();
            syncThemeMode(state.currentMode);
        });

        // Close Properties
        elements.closePropertiesBtn.addEventListener('click', () => {
            elements.propertiesPanel.classList.remove('open');
            if (state.activeZone) {
                state.activeZone.classList.remove('active');
                state.activeZone = null;
            }
        });

        // Save Properties
        elements.saveBtn.addEventListener('click', saveCurrentProperties);

        // Publish isolated preview-scope changes into the formal scope
        elements.globalSaveBtn?.addEventListener('click', publishPreviewScope);
        elements.discardPreviewBtn?.addEventListener('click', discardPreviewScope);

        // Locale Selector
        const localeSelector = document.getElementById('locale-selector');
        if (localeSelector) {
            localeSelector.addEventListener('change', () => {
                const newLocale = localeSelector.value;
                // Update URL with new locale and reload
                const url = new URL(window.location.href);
                // Assuming URL pattern includes locale, or we add as query param
                url.searchParams.set('locale', newLocale);
                showToast(`${config.translations.switchingLocale || 'Switching language...'} ${config.availableLocales[newLocale] || newLocale}`, 'info');
                setTimeout(() => {
                    window.location.href = url.toString();
                }, 500);
            });
        }

        // Accordion Logic
        const accordionHeaders = document.querySelectorAll('.accordion-header');
        accordionHeaders.forEach(header => {
            header.addEventListener('click', () => {
                const item = header.closest('.accordion-item');
                item.classList.toggle('expanded');
            });
        });

        // Panel Collapse Logic
        if (elements.collapseLeftBtn) {
            elements.collapseLeftBtn.addEventListener('click', () => {
                elements.structurePanel.classList.add('collapsed');
                elements.expandLeftBtn.style.display = 'flex';
            });
        }

        if (elements.expandLeftBtn) {
            elements.expandLeftBtn.addEventListener('click', () => {
                elements.structurePanel.classList.remove('collapsed');
                elements.expandLeftBtn.style.display = 'none';
            });
        }

        if (elements.collapseRightBtn) {
            elements.collapseRightBtn.addEventListener('click', () => {
                elements.propertiesPanel.classList.add('collapsed');
                elements.expandRightBtn.style.display = 'flex';
            });
        }

        if (elements.expandRightBtn) {
            elements.expandRightBtn.addEventListener('click', () => {
                elements.propertiesPanel.classList.remove('collapsed');
                elements.expandRightBtn.style.display = 'none';
            });
        }

        // Layout Search Logic
        if (elements.layoutSearch) {
            elements.layoutSearch.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase().trim();
                const cards = elements.layoutSwitcherContainer.querySelectorAll('.layout-card');

                cards.forEach(card => {
                    const name = card.querySelector('.layout-name').textContent.toLowerCase();
                    if (name.includes(query)) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    }

    // --- Actions ---

    function openGlobalSettings(type, category, title) {
        // Deselect zones
        if (state.activeZone) {
            state.activeZone.classList.remove('active');
            state.activeZone = null;
        }

        state.activeType = type;
        state.activeCategory = category;

        // Open Sidebar
        elements.propertiesPanel.classList.add('open');
        elements.propertiesTitle.textContent = title;
        elements.propertiesEmpty.style.display = 'none';
        elements.propertiesContent.style.display = 'block';
        elements.propertiesFooter.style.display = 'block';

        // Switch to settings/config tab automatically
        const settingsTab = document.querySelector('[data-bs-target="#tab-settings"]');
        if (settingsTab) settingsTab.click();

        // Get data from optionsData
        const areaData = state.optionsData[config.area];

        if (type === 'colors' && areaData && areaData.colors) {
            // Render editable color variables grid
            renderColorVariablesGrid(areaData.colors);
        } else if (type === 'variable' && areaData && areaData.variables) {
            // For variables, use the existing layout options view
            const options = areaData.variables.map(v => ({
                value: v.value || v,
                label: v.label || v.value || v,
                description: v.description || ''
            }));

            const currentConfig = state.configData[config.area] || {};
            let currentVal = '';
            if (currentConfig[type] && currentConfig[type][category]) {
                currentVal = currentConfig[type][category];
            }

            renderLayoutOptions(options, currentVal);

            if (currentVal) {
                loadParams(type, category, currentVal);
            } else if (options.length > 0) {
                loadParams(type, category, options[0].value);
            } else {
                elements.settingsForm.innerHTML = '<div class="alert alert-info">当前区域没有可配置的' + title + '</div>';
            }
        } else {
            elements.layoutContainer.innerHTML = '';
            elements.settingsForm.innerHTML = '<div class="alert alert-info">当前区域没有可配置的' + title + '</div>';
        }
    }

    // Render editable color variables grid
    function renderColorVariablesGrid(colors) {
        if (!colors || colors.length === 0) {
            elements.layoutContainer.innerHTML = '<div class="text-muted text-center py-3">No color settings available</div>';
            elements.settingsForm.innerHTML = '';
            return;
        }

        elements.layoutContainer.innerHTML = '';

        let html = '<div class="color-variables-grid">';
        const presetsHtml = state.colorPresets.map(c => `<div class="color-preset" style="background-color: ${c}" data-color="${c}"></div>`).join('');
        const recentHtml = state.recentColors.map(c => `<div class="color-preset" style="background-color: ${c}" data-color="${c}"></div>`).join('');

        colors.forEach((color, index) => {
            const varName = color.name || color.value || `color-${index}`;
            const varValue = color.current || color.default || '#000000';
            const varLabel = color.label || varName;
            const varDesc = color.description || '';

            html += `
                <div class="color-variable-card" data-color-name="${varName}">
                    <div class="color-header">
                        <div class="color-swatch" style="background-color: ${varValue}">
                            <input type="color" name="colors[${varName}]" value="${varValue}" title="点击选择颜色">
                        </div>
                        <div class="color-info">
                            <div class="color-name" title="${varName}">${varLabel}</div>
                            <div class="color-value">${varValue}</div>
                        </div>
                        <button type="button" class="color-copy-btn btn-sm" title="Copy color value">
                            <i class="mdi mdi-content-copy"></i>
                        </button>
                    </div>
                    <div class="color-input mt-2">
                        <input type="text" class="form-control form-control-sm" value="${varValue}" placeholder="#000000">
                    </div>
                    <div class="color-presets mt-2">${presetsHtml}</div>
                    ${recentHtml ? `
                    <div class="color-recent mt-1">
                        <div class="color-recent-list">${recentHtml}</div>
                    </div>` : ''}
                    ${varDesc ? `<div class="form-text small mt-1">${varDesc}</div>` : ''}
                </div>
            `;
        });

        html += '</div>';
        elements.settingsForm.innerHTML = html;

        // Bind events
        elements.settingsForm.querySelectorAll('.color-variable-card').forEach(card => {
            const colorInput = card.querySelector('input[type="color"]');
            const textInput = card.querySelector('input[type="text"]');
            const swatch = card.querySelector('.color-swatch');
            const valueDisplay = card.querySelector('.color-value');
            const copyBtn = card.querySelector('.color-copy-btn');
            const presets = card.querySelectorAll('.color-preset');

            const updateCard = (val) => {
                colorInput.value = val;
                textInput.value = val;
                swatch.style.backgroundColor = val;
                if (valueDisplay) valueDisplay.textContent = val;
                updatePreviewStyles(collectColorVars());
                addRecentColor(val);
            };

            if (colorInput && textInput) {
                colorInput.addEventListener('input', () => {
                    textInput.value = colorInput.value;
                    swatch.style.backgroundColor = colorInput.value;
                    if (valueDisplay) valueDisplay.textContent = colorInput.value;
                    updatePreviewStyles(collectColorVars());
                });
                colorInput.addEventListener('change', () => {
                    addRecentColor(colorInput.value);
                });
                textInput.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        colorInput.value = textInput.value;
                        swatch.style.backgroundColor = textInput.value;
                        if (valueDisplay) valueDisplay.textContent = textInput.value;
                        updatePreviewStyles(collectColorVars());
                    }
                });
                textInput.addEventListener('change', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        addRecentColor(textInput.value);
                    }
                });
            }

            if (copyBtn) {
                copyBtn.addEventListener('click', () => {
                    navigator.clipboard.writeText(textInput.value).then(() => {
                        copyBtn.innerHTML = '<i class="mdi mdi-check"></i>';
                        copyBtn.classList.add('text-success');
                        setTimeout(() => {
                            copyBtn.innerHTML = '<i class="mdi mdi-content-copy"></i>';
                            copyBtn.classList.remove('text-success');
                        }, 1500);
                    });
                });
            }

            presets.forEach(preset => {
                preset.addEventListener('click', () => {
                    updateCard(preset.dataset.color);
                });
            });
        });
    }

    // Collect color variables from the color grid
    function collectColorVars() {
        const cssVars = {};
        elements.settingsForm.querySelectorAll('.color-variable-card').forEach(card => {
            const colorInput = card.querySelector('input[type="color"]');
            const varName = card.dataset.colorName;
            if (colorInput && varName) {
                cssVars[`--${varName}`] = colorInput.value;
            }
        });
        return cssVars;
    }

    // --- Zone Logic ---

    function selectZone(zoneEl, type, category, title) {
        // UI Update
        if (state.activeZone) {
            state.activeZone.classList.remove('active');
        }
        zoneEl.classList.add('active');
        state.activeZone = zoneEl;
        state.activeType = type;
        state.activeCategory = category;

        // Open Sidebar
        elements.propertiesPanel.classList.add('open');
        elements.propertiesTitle.textContent = title;
        elements.propertiesEmpty.style.display = 'none';
        elements.propertiesContent.style.display = 'block';
        elements.propertiesFooter.style.display = 'block';

        // Render Options
        renderProperties(type, category);
    }

    function renderProperties(type, category) {
        const areaData = state.optionsData[config.area];
        if (!areaData) return;

        const options = areaData[type] ? areaData[type][category] : [];
        const currentConfig = state.configData[config.area] || {};

        let currentVal = '';
        if (currentConfig[type] && currentConfig[type][category]) {
            currentVal = currentConfig[type][category];
        }

        // 1. Render Layout Options
        renderLayoutOptions(options, currentVal);

        // 2. Render Params Form (for the currently selected option)
        if (currentVal) {
            loadParams(type, category, currentVal);
        } else {
            elements.settingsForm.innerHTML = '<div class="alert alert-info">请先选择一个布局</div>';
        }
    }

    function renderLayoutOptions(options, currentVal) {
        let html = '';
        if (!options || options.length === 0) {
            html = '<div class="text-muted">没有可用的选项</div>';
        } else {
            options.forEach(opt => {
                const isActive = opt.value === currentVal;
                html += `
                    <div class="layout-option-card ${isActive ? 'active' : ''}" data-value="${opt.value}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="layout-title">${opt.label || opt.value}</div>
                            ${isActive ? '<i class="mdi mdi-check-circle text-primary"></i>' : ''}
                        </div>
                        <div class="layout-desc">${opt.description || ''}</div>
                    </div>
                `;
            });
        }
        elements.layoutContainer.innerHTML = html;

        // Bind clicks
        elements.layoutContainer.querySelectorAll('.layout-option-card').forEach(card => {
            card.addEventListener('click', () => {
                const newVal = card.dataset.value;
                // Update active state
                elements.layoutContainer.querySelectorAll('.layout-option-card').forEach(c => {
                    c.classList.remove('active');
                    const icon = c.querySelector('.mdi-check-circle');
                    if (icon) icon.remove();
                });
                card.classList.add('active');
                card.querySelector('.d-flex').insertAdjacentHTML('beforeend', '<i class="mdi mdi-check-circle text-primary"></i>');

                // Save Selection immediately
                saveSelection(state.activeType, state.activeCategory, newVal);

                // Load Params for new selection
                loadParams(state.activeType, state.activeCategory, newVal).then(() => {
                    // Switch to settings tab automatically
                    const settingsTab = document.querySelector('[data-bs-target="#tab-settings"]');
                    if (settingsTab) settingsTab.click();
                });
            });
        });
    }

    async function loadParams(type, category, value) {
        elements.settingsForm.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary spinner-border-sm"></div></div>';

        try {
            const response = await fetch(`${config.urls.fileParams}?theme_id=${config.themeId}&area=${config.area}&type=${type}&category=${category}&value=${value}&locale=${config.locale}`);
            const result = await response.json();

            if (result.code === 200) {
                renderParamsForm(result.data);
            } else {
                elements.settingsForm.innerHTML = `<div class="alert alert-warning">${result.msg || '加载参数失败'}</div>`;
            }
        } catch (error) {
            elements.settingsForm.innerHTML = `<div class="alert alert-danger">加载错误: ${error.message}</div>`;
        }
    }

    function renderParamsForm(data) {
        const params = data.params || {};
        const configVals = data.config || {};

        if (Object.keys(params).length === 0) {
            elements.settingsForm.innerHTML = '<div class="text-muted text-center py-3">此选项没有可配置的参数</div>';
            return;
        }

        let html = '';

        for (const [key, param] of Object.entries(params)) {
            const value = configVals[key] !== undefined ? configVals[key] : (param.default || '');
            const inputId = `param-${key}`;
            const isI18n = param.i18n === true || param.translatable === true;

            html += `<div class="param-group ${isI18n ? 'i18n-field-group' : ''}">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="${inputId}">${param.name || key}</label>
                    <div class="param-actions">
                        ${isI18n ? `<span class="locale-badge" title="多语言字段">${config.locale || 'I18N'}</span>` : ''}
                        <button type="button" class="btn-reset-param" data-key="${key}" title="重置为默认�?>
                            <i class="mdi mdi-refresh"></i>
                        </button>
                    </div>
                </div>`;

            const inputName = `params[${key}]`;

            if (param.type === 'color') {
                const colorId = `color-${key}`;
                const textId = `text-${key}`;
                const presetsHtml = state.colorPresets.map(c => `<div class="color-preset" style="background-color: ${c}" data-color="${c}"></div>`).join('');
                const recentHtml = state.recentColors.map(c => `<div class="color-preset" style="background-color: ${c}" data-color="${c}"></div>`).join('');

                html += `<div class="color-picker-wrapper" data-key="${key}">
                    <div class="color-picker-main">
                        <input type="color" class="form-control-color" id="${colorId}" name="${inputName}" value="${value}">
                        <input type="text" class="form-control" id="${textId}" value="${value}">
                        <button type="button" class="color-copy-btn" title="Copy color value">
                            <i class="mdi mdi-content-copy"></i>
                        </button>
                    </div>
                    <div class="color-presets">${presetsHtml}</div>
                    ${recentHtml ? `
                    <div class="color-recent">
                        <div class="color-recent-label">${config.translations.recentColors || 'Recent colors'}</div>
                        <div class="color-recent-list">${recentHtml}</div>
                    </div>` : ''}
                </div>`;
            } else if (param.type === 'image' || param.type === 'file') {
                const previewId = `preview-${key}`;
                html += `<div class="image-param-wrapper" data-key="${key}">
                    <div class="image-preview-box ${value ? 'has-image' : ''}" id="${previewId}">
                        ${value ? `<img src="${value}" alt="Preview">` : '<i class="mdi mdi-image-plus"></i>'}
                    </div>
                    <div class="input-group">
                        <input type="text" class="form-control" id="${inputId}" name="${inputName}" value="${value}" placeholder="图片 URL">
                        <button class="btn btn-outline-secondary btn-clear-image" type="button" title="清除图片">
                            <i class="mdi mdi-close"></i>
                        </button>
                        <button class="btn btn-outline-secondary btn-upload" type="button" title="上传文件">
                            <i class="mdi mdi-upload"></i>
                        </button>
                    </div>
                </div>`;
            } else if (param.type === 'textarea') {
                html += `<textarea class="form-control" id="${inputId}" name="${inputName}" rows="3">${value}</textarea>`;
            } else if (param.type === 'select' && param.options) {
                html += `<select class="form-select" id="${inputId}" name="${inputName}">`;
                param.options.forEach(opt => {
                    html += `<option value="${opt.value}" ${opt.value == value ? 'selected' : ''}>${opt.label || opt.value}</option>`;
                });
                html += `</select>`;
            } else if (param.type === 'boolean' || param.type === 'checkbox') {
                html += `<div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="${inputId}" name="${inputName}" value="1" ${value == '1' || value === true ? 'checked' : ''}>
                    <label class="form-check-label" for="${inputId}">${param.description || '启用'}</label>
                </div>`;
            } else if (param.type === 'number' || param.type === 'range') {
                html += `<input type="number" class="form-control" id="${inputId}" name="${inputName}" value="${value}">`;
            } else {
                html += `<input type="${param.type || 'text'}" class="form-control" id="${inputId}" name="${inputName}" value="${value}">`;
            }

            if (param.description && param.type !== 'boolean' && param.type !== 'checkbox') {
                html += `<div class="form-text mt-1">${param.description}</div>`;
            }
            html += `</div>`;
        }

        elements.settingsForm.innerHTML = html;

        // Bind Reset Events
        elements.settingsForm.querySelectorAll('.btn-reset-param').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const key = btn.dataset.key;
                resetParam(key);
            });
        });

        // Bind Image Preview & Upload
        elements.settingsForm.querySelectorAll('.image-param-wrapper').forEach(wrapper => {
            const input = wrapper.querySelector('input');
            const preview = wrapper.querySelector('.image-preview-box');
            const uploadBtn = wrapper.querySelector('.btn-upload');

            input.addEventListener('input', () => {
                if (input.value) {
                    preview.innerHTML = `<img src="${input.value}" alt="Preview">`;
                    preview.classList.add('has-image');
                } else {
                    preview.innerHTML = '<i class="mdi mdi-image-plus"></i>';
                    preview.classList.remove('has-image');
                }
            });

            const clearBtn = wrapper.querySelector('.btn-clear-image');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    input.value = '';
                    input.dispatchEvent(new Event('input'));
                    input.dispatchEvent(new Event('change'));
                });
            }

            if (uploadBtn) {
                uploadBtn.addEventListener('click', () => {
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.accept = 'image/*';
                    fileInput.style.display = 'none';
                    document.body.appendChild(fileInput);

                    fileInput.onchange = async (e) => {
                        const file = e.target.files[0];
                        if (!file) return;

                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('theme_id', config.themeId);

                        uploadBtn.disabled = true;
                        uploadBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i>';

                        try {
                            const response = await fetch(config.urls.upload, {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();
                            if (result.code === 200) {
                                input.value = result.url;
                                input.dispatchEvent(new Event('input'));
                                input.dispatchEvent(new Event('change'));
                            } else {
                                showToast('上传失败: ' + result.msg, 'error');
                            }
                        } catch (error) {
                            console.error('Upload Error:', error);
                            showToast('上传失败', 'error');
                        } finally {
                            uploadBtn.disabled = false;
                            uploadBtn.innerHTML = '<i class="mdi mdi-upload"></i>';
                            document.body.removeChild(fileInput);
                        }
                    };
                    fileInput.click();
                });
            }
        });

        // Bind color picker sync events
        elements.settingsForm.querySelectorAll('.color-picker-wrapper').forEach(wrapper => {
            const colorInput = wrapper.querySelector('input[type="color"]');
            const textInput = wrapper.querySelector('input[type="text"]');
            const copyBtn = wrapper.querySelector('.color-copy-btn');
            const presets = wrapper.querySelectorAll('.color-preset');
            const recentPresets = wrapper.querySelectorAll('.color-recent .color-preset');

            const updateAll = (val) => {
                if (colorInput) colorInput.value = val;
                if (textInput) textInput.value = val;
                updatePreviewStyles(collectCssVars());
                addRecentColor(val);
            };

            if (colorInput && textInput) {
                colorInput.addEventListener('input', () => {
                    textInput.value = colorInput.value;
                    updatePreviewStyles(collectCssVars());
                });
                colorInput.addEventListener('change', () => {
                    addRecentColor(colorInput.value);
                });
                textInput.addEventListener('input', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        colorInput.value = textInput.value;
                        updatePreviewStyles(collectCssVars());
                    }
                });
                textInput.addEventListener('change', () => {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        addRecentColor(textInput.value);
                    }
                });
            }

            if (copyBtn && textInput) {
                copyBtn.addEventListener('click', () => {
                    navigator.clipboard.writeText(textInput.value).then(() => {
                        copyBtn.classList.add('copied');
                        copyBtn.innerHTML = '<i class="mdi mdi-check"></i>';
                        setTimeout(() => {
                            copyBtn.classList.remove('copied');
                            copyBtn.innerHTML = '<i class="mdi mdi-content-copy"></i>';
                        }, 1500);
                    });
                });
            }

            [...presets, ...recentPresets].forEach(preset => {
                preset.addEventListener('click', () => {
                    updateAll(preset.dataset.color);
                });
            });
        });

        state.currentValueName = data.value || '';
    }

    function addRecentColor(color) {
        if (!color || !/^#[0-9A-Fa-f]{6}$/.test(color)) return;
        state.recentColors = state.recentColors.filter(c => c.toLowerCase() !== color.toLowerCase());
        state.recentColors.unshift(color.toLowerCase());
        state.recentColors = state.recentColors.slice(0, 20);
        localStorage.setItem('ve-recent-colors', JSON.stringify(state.recentColors));
    }

    // --- Saving ---

    async function saveSelection(type, category, value) {
        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', config.area);
        formData.append('scope', config.scope);
        formData.append('locale', config.locale);
        formData.append('type', type);
        formData.append('category', category);
        formData.append('value', value);

        try {
            const response = await fetch(config.urls.saveSelection, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.code === 200) {
                if (!state.configData[config.area][type]) state.configData[config.area][type] = {};
                state.configData[config.area][type][category] = value;
                showToast(config.translations.saveSuccess, 'success');
                refreshPreview();
            } else {
                showToast(result.msg || config.translations.saveFailed, 'error');
            }
        } catch (error) {
            showToast('保存错误', 'error');
        }
    }

    async function saveCurrentProperties() {
        if (!state.activeZone) return;
        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', config.area);
        formData.append('scope', config.scope);
        formData.append('locale', config.locale);

        const currentVal = state.configData[config.area][state.activeType][state.activeCategory];
        if (!currentVal) {
            showToast(config.translations.selectLayoutBeforeSave || 'Please select a layout before saving parameters', 'warning');
            return;
        }

        const metaIdentify = `${state.activeType}.${state.activeCategory}.${currentVal}`;
        formData.append('meta_identify', metaIdentify);

        const inputs = elements.settingsForm.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (input.name) {
                if (input.type === 'checkbox') {
                    formData.append(input.name, input.checked ? '1' : '0');
                } else if (input.type === 'radio') {
                    if (input.checked) formData.append(input.name, input.value);
                } else {
                    formData.append(input.name, input.value);
                }
            }
        });

        const btn = elements.saveBtn;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 保存�?..';

        try {
            const response = await fetch(config.urls.saveFileParams, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.code === 200) {
                showToast(config.translations.saveSuccess, 'success');
                refreshPreview();
            } else {
                showToast(result.msg || config.translations.saveFailed, 'error');
            }
        } catch (error) {
            showToast('保存错误: ' + error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    async function resetParam(key) {
        const currentVal = state.configData[config.area][state.activeType][state.activeCategory];
        if (!currentVal) return;

        const metaIdentify = `${state.activeType}.${state.activeCategory}.${currentVal}`;
        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', config.area);
        formData.append('scope', config.scope);
        formData.append('locale', config.locale);
        formData.append('meta_identify', metaIdentify);
        formData.append('param_key', key);
        formData.append('action', 'reset');

        try {
            const response = await fetch(config.urls.saveFileParams, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.code === 200) {
                showToast(config.translations.resetSuccess || 'Reset to default', 'success');
                loadParams(state.activeType, state.activeCategory, currentVal);
                refreshPreview();
            } else {
                showToast(result.msg || config.translations.resetFailed || 'Reset failed', 'error');
            }
        } catch (error) {
            showToast((config.translations.resetError || 'Reset error') + ': ' + error.message, 'error');
        }
    }

    async function publishPreviewScope() {
        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', config.area);
        formData.append('scope', config.scope);

        const btn = elements.globalSaveBtn;
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ������...';
        }

        try {
            const response = await fetch(config.urls.publishPreviewScope, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.code === 200) {
                showToast(config.translations.publishSuccess || result.msg || 'Published successfully', 'success');
                await loadOptions(config.area);
                refreshPreview();
            } else {
                showToast(result.msg || config.translations.publishFailed || 'Publish failed', 'error');
            }
        } catch (error) {
            showToast((config.translations.publishFailed || 'Publish failed') + ': ' + error.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }

    function confirmVisualEditorAction(message) {
        if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
            return window.BackendConfirm.show(message, {
                title: config.translations.discardTitle || 'Discard changes',
                type: 'warning',
                confirmText: config.translations.confirmText || 'Confirm',
                cancelText: config.translations.cancelText || 'Cancel'
            });
        }

        console.warn('[Weline Theme] BackendConfirm is unavailable; preview discard cancelled.');
        return Promise.resolve(false);
    }

    async function discardPreviewScope() {
        if (!(await confirmVisualEditorAction(config.translations.discardConfirm || 'Discard current preview configuration changes?'))) {
            return;
        }

        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', config.area);
        formData.append('scope', config.scope);

        const btn = elements.discardPreviewBtn;
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ������...';
        }

        try {
            const response = await fetch(config.urls.discardPreviewScope, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.code === 200) {
                showToast(config.translations.discardSuccess || result.msg || 'Preview config discarded', 'success');
                await loadOptions(config.area);
                refreshPreview();
            } else {
                showToast(result.msg || config.translations.discardFailed || 'Discard failed', 'error');
            }
        } catch (error) {
            showToast((config.translations.discardFailed || 'Discard failed') + ': ' + error.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }


    // --- Helpers ---

    function refreshPreview() {
        if (!elements.loading || !elements.iframe) return;
        
        elements.loading.classList.add('show');
        const currentSrc = elements.iframe.src;
        
        // 使用 addEventListener 而不是直接赋�?onload，避免覆盖其他监听器
        const handleLoad = () => {
            setTimeout(() => {
                elements.loading.classList.remove('show');
            }, 300);
            elements.iframe.removeEventListener('load', handleLoad);
        };
        
        elements.iframe.addEventListener('load', handleLoad, { once: true });
        elements.iframe.src = currentSrc; // Reload
    }

    /**
     * Send CSS variable updates to preview iframe via postMessage
     * This allows instant preview without full page reload
     */
    function updatePreviewStyles(cssVars) {
        try {
            const iframeWindow = elements.iframe.contentWindow;
            if (iframeWindow) {
                iframeWindow.postMessage({
                    type: 'WELINE_THEME_UPDATE',
                    cssVars: cssVars
                }, '*');
            } else {
                console.warn('Iframe window not accessible');
            }
        } catch (e) {
            // Cross-origin restrictions, fall back to reload
            console.warn('postMessage failed, using full reload');
            refreshPreview();
        }
    }

    /**
     * Collect CSS variable values from color inputs for live preview
     */
    function collectCssVars() {
        const cssVars = {};
        elements.settingsForm.querySelectorAll('.color-picker-wrapper').forEach(wrapper => {
            const colorInput = wrapper.querySelector('input[type="color"]');
            if (colorInput && colorInput.name) {
                // Extract variable name from params[varName] format
                const match = colorInput.name.match(/params\[([^\]]+)\]/);
                if (match) {
                    cssVars[`--${match[1]}`] = colorInput.value;
                }
            }
        });
        return cssVars;
    }

    function showToast(msg, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `ve-toast ve-toast-${type}`;
        // Inline glassmorphism for immediate effect if CSS not loaded
        Object.assign(toast.style, {
            position: 'fixed',
            bottom: '30px',
            right: '30px',
            padding: '12px 24px',
            borderRadius: '12px',
            color: '#fff',
            zIndex: '10000',
            backdropFilter: 'blur(10px)',
            webkitBackdropFilter: 'blur(10px)',
            boxShadow: '0 8px 32px rgba(0,0,0,0.15)',
            transform: 'translateY(20px)',
            opacity: '0',
            transition: 'all 0.4s cubic-bezier(0.16, 1, 0.3, 1)',
            display: 'flex',
            alignItems: 'center',
            gap: '10px',
            fontWeight: '600',
            fontSize: '0.9rem'
        });

        let icon = 'mdi-information';
        if (type === 'success') {
            toast.style.backgroundColor = 'rgba(16, 185, 129, 0.85)';
            icon = 'mdi-check-circle';
        } else if (type === 'error') {
            toast.style.backgroundColor = 'rgba(239, 68, 68, 0.85)';
            icon = 'mdi-alert-circle';
        } else {
            toast.style.backgroundColor = 'rgba(59, 130, 246, 0.85)';
        }

        toast.innerHTML = `<i class="mdi ${icon}"></i> <span>${msg}</span>`;
        document.body.appendChild(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        });

        setTimeout(() => {
            toast.style.transform = 'translateY(20px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }

    // --- Layout Switcher ---

    function renderLayoutSwitcher(layouts) {
        if (!elements.layoutSwitcherContainer) return;
        elements.layoutSwitcherContainer.innerHTML = '';

        const area = config.area;
        const layoutConfig = state.configData[area].layouts || {};
        const bodyZone = document.querySelector('.zone-body');
        const currentCategory = bodyZone ? bodyZone.dataset.category : 'default';

        const categories = Object.keys(layouts);
        categories.sort((a, b) => {
            if (a === 'default') return -1;
            if (b === 'default') return 1;
            return a.localeCompare(b);
        });

        categories.forEach(category => {
            const options = layouts[category] || [];
            if (options.length === 0) return;

            // Use the first option's meta for the card
            const firstOption = options[0];
            const name = category === 'default' ? '全局默认' : (firstOption.label || category);
            const iconClass = getLayoutIcon(category);

            const card = document.createElement('div');
            card.className = `layout-card ${category === currentCategory ? 'active' : ''}`;
            card.dataset.category = category;
            card.innerHTML = `
                <div class="layout-icon"><i class="mdi ${iconClass}"></i></div>
                <div class="layout-name">${name}</div>
            `;

            card.addEventListener('click', () => {
                if (category === state.activeCategory && state.activeType === 'layouts') return;
                switchLayout(category, options);
            });

            elements.layoutSwitcherContainer.appendChild(card);
        });

        // Re-apply search filter if any
        if (elements.layoutSearch && elements.layoutSearch.value) {
            elements.layoutSearch.dispatchEvent(new Event('input'));
        }
    }

    async function switchLayout(category, options) {
        const area = config.area;
        const bodyZone = document.querySelector('.zone-body');

        // Update active card
        document.querySelectorAll('.layout-card').forEach(c => c.classList.remove('active'));
        const activeCard = document.querySelector(`.layout-card[data-category="${category}"]`);
        if (activeCard) activeCard.classList.add('active');

        // Update body zone category
        if (bodyZone) {
            bodyZone.dataset.category = category;
        }

        // Show loading
        if (elements.loading) {
            elements.loading.classList.add('show');
        }

        // Reload parameters for this layout type
        const currentVal = state.configData[area].layouts?.[category] || options[0].value;

        // Navigating the iframe to the preview URL
        let previewUrl = options[0].meta?.preview_url || '';
        if (!previewUrl) {
            // Default mapping
            const map = {
                'default': '/',
                'homepage': '/',
                'product': '/product/view/id/1.html',
                'category': '/category/view/id/1.html',
                'cart': '/checkout/cart',
                'checkout': '/checkout/index',
                'account': '/customer/account'
            };
            previewUrl = map[category] || '/';
        }

        // Update iframe
        const url = new URL(elements.iframe.src);
        // We might need to keep the theme preview session but change the path
        // For simplicity, let's assume we can just redirect within the iframe's origin
        try {
            const iframeOrigin = new URL(elements.iframe.src).origin;
            const targetUrl = new URL(previewUrl, iframeOrigin);
            // Re-add preview parameters
            targetUrl.searchParams.set('preview_theme', config.themeId);
            targetUrl.searchParams.set('area', config.area);
            
            // 添加加载完成事件处理
            const handleLoad = () => {
                setTimeout(() => {
                    if (elements.loading) {
                        elements.loading.classList.remove('show');
                    }
                }, 300);
                elements.iframe.removeEventListener('load', handleLoad);
            };
            
            elements.iframe.addEventListener('load', handleLoad, { once: true });
            elements.iframe.src = targetUrl.toString();
        } catch (e) {
            console.error('Failed to update iframe URL:', e);
            if (elements.loading) {
                elements.loading.classList.remove('show');
            }
            refreshPreview();
        }

        // Update structure map (sidebars visibility)
        updateStructureMap(state.optionsData[area].partials);

        // Clear properties panel to avoid confusion
        elements.propertiesPanel.classList.remove('open');
        if (state.activeZone) {
            state.activeZone.classList.remove('active');
            state.activeZone = null;
        }
    }

    function getLayoutIcon(category) {
        const icons = {
            'default': 'mdi-view-quilt',
            'homepage': 'mdi-home',
            'product': 'mdi-package-variant-closed',
            'category': 'mdi-format-list-bulleted',
            'cart': 'mdi-cart',
            'checkout': 'mdi-shield-check',
            'account': 'mdi-account-circle',
            'cms': 'mdi-file-document-outline',
            'product_list': 'mdi-view-grid'
        };
        return icons[category] || 'mdi-view-dashboard';
    }
});
