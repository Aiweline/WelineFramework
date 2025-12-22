(function() {
    'use strict';

    const config = window.themeEditConfig || {};
    let currentArea = config.area || 'frontend';
    let currentScope = config.scope || 'default';

    // 初始化
    function init() {
        if (!config.themeId) {
            return;
        }

        // 渲染前端配置
        renderFrontendConfig();
        
        // 渲染后端配置
        renderBackendConfig();

        // 初始化预览功能
        initPreview();

        // 监听Area切换
        document.querySelectorAll('.area-tabs .nav-link').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function() {
                currentArea = this.dataset.area;
                updatePreviewUrl();
            });
        });
    }

    // 渲染前端配置
    function renderFrontendConfig() {
        renderLayouts('frontend', config.frontendLayoutOptions || {}, config.frontendLayoutConfig || {});
        renderColors('frontend', config.frontendColorOptions || [], config.frontendColorConfig);
        renderPartials('frontend', config.frontendPartialsOptions || {}, config.frontendPartialsConfig || {});
        renderVariables('frontend', config.frontendVariableOptions || [], config.frontendVariablesConfig || []);
        renderComponents('frontend', config.frontendComponentOptions || [], []);
    }

    // 渲染后端配置
    function renderBackendConfig() {
        renderLayouts('backend', config.backendLayoutOptions || {}, config.backendLayoutConfig || {});
        renderColors('backend', config.backendColorOptions || [], config.backendColorConfig);
        renderPartials('backend', config.backendPartialsOptions || {}, config.backendPartialsConfig || {});
        renderVariables('backend', config.backendVariableOptions || [], config.backendVariablesConfig || []);
        renderComponents('backend', config.backendComponentOptions || [], []);
    }

    // 渲染布局选项
    function renderLayouts(area, options, currentConfig) {
        const container = document.getElementById(`${area}-layouts-container`);
        if (!container) return;

        if (Object.keys(options).length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="mdi mdi-information-outline"></i><p>' + __('暂无可用布局') + '</p></div>';
            return;
        }

        let html = '';
        for (const [layoutType, layoutOptions] of Object.entries(options)) {
            if (!Array.isArray(layoutOptions) || layoutOptions.length === 0) continue;

            html += `<div class="layout-type-group">`;
            html += `<h6 class="layout-type-title">${escapeHtml(layoutType)}</h6>`;
            html += `<div class="row g-2">`;

            const currentValue = currentConfig[layoutType] || '';

            layoutOptions.forEach(option => {
                const value = option.value || '';
                const meta = option.meta || {};
                const name = meta.name || value;
                const description = meta.description || '';
                const isSelected = currentValue === value;

                html += `<div class="col-md-6 col-lg-4">`;
                html += `<div class="config-card layout-card ${isSelected ? 'selected' : ''}" 
                             data-type="layouts" 
                             data-category="${escapeHtml(layoutType)}" 
                             data-value="${escapeHtml(value)}"
                             data-area="${area}">`;
                html += `<div class="config-card-header">`;
                html += `<h6 class="config-card-title">${escapeHtml(name)}</h6>`;
                html += `<div class="config-card-actions">`;
                if (meta.params && Object.keys(meta.params).length > 0) {
                    html += `<button type="button" class="config-card-btn params-btn" title="${__('配置参数')}">`;
                    html += `<i class="mdi mdi-cog"></i>`;
                    html += `</button>`;
                }
                html += `</div>`;
                html += `</div>`;
                if (description) {
                    html += `<div class="config-card-description">${escapeHtml(description)}</div>`;
                }
                html += `</div>`;
                html += `</div>`;
            });

            html += `</div>`;
            html += `</div>`;
        }

        container.innerHTML = html;

        // 绑定点击事件
        container.querySelectorAll('.layout-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.params-btn')) {
                    e.stopPropagation();
                    openParamsModal('layouts', this.dataset.category, this.dataset.value, area);
                } else {
                    selectLayout(area, this.dataset.category, this.dataset.value);
                }
            });
        });
    }

    // 渲染色系选项
    function renderColors(area, options, currentConfig) {
        const container = document.getElementById(`${area}-colors-container`);
        if (!container) return;

        if (options.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="mdi mdi-information-outline"></i><p>' + __('暂无可用色系') + '</p></div>';
            return;
        }

        let html = '<div class="row g-2">';
        options.forEach(option => {
            const value = option.value || '';
            const meta = option.meta || {};
            const name = meta.name || value;
            const description = meta.description || '';
            const colors = meta.colors || {};
            const isSelected = currentConfig === value;

            html += `<div class="col-md-6 col-lg-4">`;
            html += `<div class="config-card color-card ${isSelected ? 'selected' : ''}" 
                         data-type="colors" 
                         data-value="${escapeHtml(value)}"
                         data-area="${area}">`;
            html += `<div class="color-preview" style="--primary-color: ${colors.primary || '#0d6efd'}; --secondary-color: ${colors.secondary || '#6c757d'};"></div>`;
            html += `<div class="config-card-header">`;
            html += `<h6 class="config-card-title">${escapeHtml(name)}</h6>`;
            html += `</div>`;
            if (description) {
                html += `<div class="config-card-description">${escapeHtml(description)}</div>`;
            }
            if (Object.keys(colors).length > 0) {
                html += `<div class="color-tags">`;
                Object.entries(colors).forEach(([key, color]) => {
                    html += `<div class="color-tag" style="background-color: ${color}" title="${key}"></div>`;
                });
                html += `</div>`;
            }
            html += `</div>`;
            html += `</div>`;
        });
        html += '</div>';

        container.innerHTML = html;

        // 绑定点击事件
        container.querySelectorAll('.color-card').forEach(card => {
            card.addEventListener('click', function() {
                selectColor(area, this.dataset.value);
            });
        });
    }

    // 渲染部件选项
    function renderPartials(area, options, currentConfig) {
        const container = document.getElementById(`${area}-partials-container`);
        if (!container) return;

        if (Object.keys(options).length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="mdi mdi-information-outline"></i><p>' + __('暂无可用部件') + '</p></div>';
            return;
        }

        let html = '';
        for (const [partialType, partialOptions] of Object.entries(options)) {
            if (!Array.isArray(partialOptions) || partialOptions.length === 0) continue;

            html += `<div class="partial-type-group">`;
            html += `<h6 class="partial-type-title">${escapeHtml(partialType)}</h6>`;
            html += `<div class="row g-2">`;

            const currentValue = currentConfig[partialType] || '';

            partialOptions.forEach(option => {
                const value = option.value || '';
                const meta = option.meta || {};
                const name = meta.name || value;
                const description = meta.description || '';
                const isSelected = currentValue === value;

                html += `<div class="col-md-6 col-lg-4">`;
                html += `<div class="config-card partial-card ${isSelected ? 'selected' : ''}" 
                             data-type="partials" 
                             data-category="${escapeHtml(partialType)}" 
                             data-value="${escapeHtml(value)}"
                             data-area="${area}">`;
                html += `<div class="config-card-header">`;
                html += `<h6 class="config-card-title">${escapeHtml(name)}</h6>`;
                html += `<div class="config-card-actions">`;
                if (meta.params && Object.keys(meta.params).length > 0) {
                    html += `<button type="button" class="config-card-btn params-btn" title="${__('配置参数')}">`;
                    html += `<i class="mdi mdi-cog"></i>`;
                    html += `</button>`;
                }
                html += `</div>`;
                html += `</div>`;
                if (description) {
                    html += `<div class="config-card-description">${escapeHtml(description)}</div>`;
                }
                html += `</div>`;
                html += `</div>`;
            });

            html += `</div>`;
            html += `</div>`;
        }

        container.innerHTML = html;

        // 绑定点击事件
        container.querySelectorAll('.partial-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.params-btn')) {
                    e.stopPropagation();
                    openParamsModal('partials', this.dataset.category, this.dataset.value, area);
                } else {
                    selectPartial(area, this.dataset.category, this.dataset.value);
                }
            });
        });
    }

    // 渲染变量选项
    function renderVariables(area, options, currentConfig) {
        const container = document.getElementById(`${area}-variables-container`);
        if (!container) return;

        if (options.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="mdi mdi-information-outline"></i><p>' + __('暂无可用变量') + '</p></div>';
            return;
        }

        let html = '<div class="variable-list">';
        options.forEach(option => {
            const value = option.value || '';
            const meta = option.meta || {};
            const name = meta.name || value;
            const description = meta.description || '';
            const isSelected = Array.isArray(currentConfig) && currentConfig.includes(value);

            html += `<div class="config-card variable-card ${isSelected ? 'selected' : ''}" 
                         data-type="variables" 
                         data-value="${escapeHtml(value)}"
                         data-area="${area}">`;
            html += `<div class="config-card-header">`;
            html += `<h6 class="config-card-title">${escapeHtml(name)}</h6>`;
            html += `</div>`;
            if (description) {
                html += `<div class="config-card-description">${escapeHtml(description)}</div>`;
            }
            html += `</div>`;
        });
        html += '</div>';

        container.innerHTML = html;

        // 绑定点击事件
        container.querySelectorAll('.variable-card').forEach(card => {
            card.addEventListener('click', function() {
                selectVariable(area, this.dataset.value);
            });
        });
    }

    // 渲染组件选项
    function renderComponents(area, options, currentConfig) {
        const container = document.getElementById(`${area}-components-container`);
        if (!container) return;

        if (options.length === 0) {
            container.innerHTML = '<div class="empty-state"><i class="mdi mdi-information-outline"></i><p>' + __('暂无可用组件') + '</p></div>';
            return;
        }

        let html = '<div class="component-list">';
        options.forEach(option => {
            const value = option.value || '';
            const meta = option.meta || {};
            const name = meta.name || value;
            const description = meta.description || '';
            const isSelected = Array.isArray(currentConfig) && currentConfig.includes(value);

            html += `<div class="config-card component-card ${isSelected ? 'selected' : ''}" 
                         data-type="components" 
                         data-value="${escapeHtml(value)}"
                         data-area="${area}">`;
            html += `<div class="config-card-header">`;
            html += `<h6 class="config-card-title">${escapeHtml(name)}</h6>`;
                html += `<div class="config-card-actions">`;
                if (meta.params && Object.keys(meta.params).length > 0) {
                    html += `<button type="button" class="config-card-btn params-btn" title="${__('配置参数')}">`;
                    html += `<i class="mdi mdi-cog"></i>`;
                    html += `</button>`;
                }
                html += `</div>`;
            html += `</div>`;
            if (description) {
                html += `<div class="config-card-description">${escapeHtml(description)}</div>`;
            }
            html += `</div>`;
        });
        html += '</div>';

        container.innerHTML = html;

        // 绑定点击事件
        container.querySelectorAll('.component-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.params-btn')) {
                    e.stopPropagation();
                    openParamsModal('components', '', this.dataset.value, area);
                } else {
                    selectComponent(area, this.dataset.value);
                }
            });
        });
    }

    // 选择布局
    async function selectLayout(area, category, value) {
        await saveSelection(area, 'layouts', category, value);
        updateCardSelection(`${area}-layouts-container`, category, value);
    }

    // 选择色系
    async function selectColor(area, value) {
        await saveSelection(area, 'colors', '', value);
        updateCardSelection(`${area}-colors-container`, '', value);
    }

    // 选择部件
    async function selectPartial(area, category, value) {
        await saveSelection(area, 'partials', category, value);
        updateCardSelection(`${area}-partials-container`, category, value);
    }

    // 选择变量
    async function selectVariable(area, value) {
        await saveSelection(area, 'variables', '', value);
        updateCardSelection(`${area}-variables-container`, '', value);
    }

    // 选择组件
    async function selectComponent(area, value) {
        await saveSelection(area, 'components', '', value);
        updateCardSelection(`${area}-components-container`, '', value);
    }

    // 更新卡片选中状态
    function updateCardSelection(containerId, category, value) {
        const container = document.getElementById(containerId);
        if (!container) return;

        // 移除所有选中状态
        container.querySelectorAll('.config-card').forEach(card => {
            card.classList.remove('selected');
        });

        // 添加选中状态
        container.querySelectorAll('.config-card').forEach(card => {
            if (category) {
                if (card.dataset.category === category && card.dataset.value === value) {
                    card.classList.add('selected');
                }
            } else {
                if (card.dataset.value === value) {
                    card.classList.add('selected');
                }
            }
        });
    }

    // 保存选择
    async function saveSelection(area, type, category, value) {
        try {
            const formData = new FormData();
            formData.append('theme_id', config.themeId);
            formData.append('area', area);
            formData.append('scope', currentScope);
            formData.append('type', type);
            if (category) {
                formData.append('category', category);
            }
            formData.append('value', value);

            const response = await fetch(config.saveSelectionUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast(__('保存成功'), 'success');
                // 刷新预览
                if (currentArea === area) {
                    refreshPreview();
                }
            } else {
                showToast(result.msg || __('保存失败'), 'error');
            }
        } catch (error) {
            console.error(__('保存失败') + ':', error);
            showToast(__('保存失败') + ': ' + error.message, 'error');
        }
    }

    // 打开参数配置模态框
    async function openParamsModal(type, category, value, area) {
        try {
            const url = new URL(config.getFileParamsUrl, window.location.origin);
            url.searchParams.set('theme_id', config.themeId);
            url.searchParams.set('area', area);
            url.searchParams.set('type', type);
            if (category) {
                url.searchParams.set('category', category);
            }
            url.searchParams.set('value', value);

            const response = await fetch(url.toString());
            const result = await response.json();

            if (result.code === 200) {
                showParamsModal(result.data);
            } else {
                showToast(result.msg || __('获取参数失败'), 'error');
            }
        } catch (error) {
            console.error(__('获取参数失败') + ':', error);
            showToast(__('获取参数失败') + ': ' + error.message, 'error');
        }
    }

    // 显示参数配置模态框
    function showParamsModal(data) {
        const modal = document.getElementById('paramsModal');
        if (!modal) return;

        const modalTitle = modal.querySelector('.modal-title');
        const modalBody = modal.querySelector('.modal-body');
        const paramsForm = modal.querySelector('#paramsForm');

        if (modalTitle) {
            modalTitle.textContent = data.meta?.name || __('参数配置');
        }

        if (paramsForm) {
            paramsForm.innerHTML = '';
            paramsForm.dataset.metaIdentify = data.meta_identify || '';

            const params = data.params || {};
            const config = data.config || {};

            for (const [paramName, paramDef] of Object.entries(params)) {
                const paramValue = config[paramName] !== undefined ? config[paramName] : (paramDef.default || '');
                const paramType = paramDef.input || paramDef.type || 'text';
                const paramOptions = paramDef.options || [];

                const formGroup = document.createElement('div');
                formGroup.className = 'mb-3';

                const label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = paramDef.name || paramName;
                if (paramDef.description) {
                    label.title = paramDef.description;
                }
                formGroup.appendChild(label);

                let input;
                if (paramType === 'select' && paramOptions.length > 0) {
                    input = document.createElement('select');
                    input.className = 'form-select';
                    paramOptions.forEach(option => {
                        const optionEl = document.createElement('option');
                        if (typeof option === 'object') {
                            optionEl.value = option.value || option;
                            optionEl.textContent = option.label || option.value || option;
                        } else {
                            optionEl.value = option;
                            optionEl.textContent = option;
                        }
                        if (optionEl.value === paramValue) {
                            optionEl.selected = true;
                        }
                        input.appendChild(optionEl);
                    });
                } else if (paramType === 'color') {
                    input = document.createElement('input');
                    input.type = 'color';
                    input.className = 'form-control form-control-color';
                    input.value = paramValue;
                } else if (paramType === 'number') {
                    input = document.createElement('input');
                    input.type = 'number';
                    input.className = 'form-control';
                    input.value = paramValue;
                } else if (paramType === 'textarea') {
                    input = document.createElement('textarea');
                    input.className = 'form-control';
                    input.value = paramValue;
                    input.rows = 3;
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control';
                    input.value = paramValue;
                }

                input.name = paramName;
                input.id = `param_${paramName}`;
                formGroup.appendChild(input);

                if (paramDef.description) {
                    const helpText = document.createElement('div');
                    helpText.className = 'form-text';
                    helpText.textContent = paramDef.description;
                    formGroup.appendChild(helpText);
                }

                paramsForm.appendChild(formGroup);
            }
        }

        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    // 保存参数配置
    async function saveParams() {
        const modal = document.getElementById('paramsModal');
        if (!modal) return;

        const paramsForm = modal.querySelector('#paramsForm');
        if (!paramsForm) return;

        const metaIdentify = paramsForm.dataset.metaIdentify;
        if (!metaIdentify) return;

        const formData = new FormData();
        formData.append('theme_id', config.themeId);
        formData.append('area', currentArea);
        formData.append('scope', currentScope);
        formData.append('meta_identify', metaIdentify);

        const params = {};
        paramsForm.querySelectorAll('input, select, textarea').forEach(input => {
            if (input.name) {
                params[input.name] = input.value;
            }
        });
        formData.append('params', JSON.stringify(params));

        try {
            const response = await fetch(config.saveFileParamsUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.code === 200) {
                showToast(__('参数保存成功'), 'success');
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
                refreshPreview();
            } else {
                showToast(result.msg || __('参数保存失败'), 'error');
            }
        } catch (error) {
            console.error(__('参数保存失败') + ':', error);
            showToast(__('参数保存失败') + ': ' + error.message, 'error');
        }
    }

    // 初始化预览功能
    function initPreview() {
        const previewIframe = document.getElementById('previewIframe');
        const iframeLoading = document.getElementById('iframeLoading');
        const previewContainer = document.getElementById('previewContainer');
        const frameWrapper = document.querySelector('.device-frame-wrapper');
        const deviceFrame = document.querySelector('.device-frame');

        // iframe 加载完成后隐藏loading
        if (previewIframe) {
            previewIframe.addEventListener('load', function() {
                if (iframeLoading) iframeLoading.style.display = 'none';
            });
        }

        // 色系切换
        document.querySelectorAll('.theme-color-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const color = this.dataset.themeColor;
                document.querySelectorAll('.theme-color-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                if (previewContainer) {
                    previewContainer.classList.remove('preview-container-light', 'preview-container-dark');
                    previewContainer.classList.add('preview-container-' + color);
                    previewContainer.dataset.themeColor = color;
                }
            });
        });

        // 设备切换
        const deviceConfig = {
            desktop: { width: 1280, height: 720 },
            tablet:  { width: 820,  height: 1024 },
            mobile:  { width: 414,  height: 896 }
        };

        function applyDeviceScale(device) {
            const cfg = deviceConfig[device] || deviceConfig.desktop;
            if (deviceFrame && device !== 'desktop') {
                deviceFrame.style.height = cfg.height + 'px';
            }
        }

        applyDeviceScale('desktop');

        document.querySelectorAll('.device-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const device = this.dataset.device;
                document.querySelectorAll('.device-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                if (frameWrapper) frameWrapper.dataset.device = device;
                if (deviceFrame) {
                    deviceFrame.classList.remove('device-desktop', 'device-tablet', 'device-mobile');
                    deviceFrame.classList.add('device-' + device);
                }
                applyDeviceScale(device);
            });
        });

        // 预览面板展开/收缩切换
        const mainContentRow = document.getElementById('mainContentRow');
        const previewToggleBtn = document.getElementById('previewToggleBtn');
        
        if (previewToggleBtn) {
            previewToggleBtn.addEventListener('click', function() {
                if (mainContentRow) {
                    mainContentRow.classList.toggle('preview-expanded');
                }
            });
        }

        // 刷新预览
        const refreshPreviewBtn = document.getElementById('refreshPreviewBtn');
        if (refreshPreviewBtn) {
            refreshPreviewBtn.addEventListener('click', refreshPreview);
        }

        // 全屏预览
        const fullscreenBtn = document.getElementById('fullscreenBtn');
        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', function() {
                if (previewIframe) {
                    if (previewIframe.requestFullscreen) {
                        previewIframe.requestFullscreen();
                    } else if (previewIframe.webkitRequestFullscreen) {
                        previewIframe.webkitRequestFullscreen();
                    }
                }
            });
        }

        // 前端/后端 Tab 切换时更新预览 URL
        document.querySelectorAll('.area-tabs .nav-link').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function() {
                updatePreviewUrl();
            });
        });
    }

    // 更新预览URL
    function updatePreviewUrl() {
        const previewIframe = document.getElementById('previewIframe');
        const iframeLoading = document.getElementById('iframeLoading');
        
        if (previewIframe) {
            if (iframeLoading) iframeLoading.style.display = 'flex';
            const url = currentArea === 'frontend' 
                ? previewIframe.dataset.frontendUrl 
                : previewIframe.dataset.backendUrl;
            if (url) {
                const urlObj = new URL(url, window.location.origin);
                urlObj.searchParams.set('_t', Date.now());
                previewIframe.src = urlObj.toString();
            }
        }
    }

    // 刷新预览
    function refreshPreview() {
        const previewIframe = document.getElementById('previewIframe');
        const iframeLoading = document.getElementById('iframeLoading');
        
        if (!previewIframe) return;
        if (iframeLoading) iframeLoading.style.display = 'flex';
        
        const currentSrc = previewIframe.src;
        if (currentSrc) {
            const url = new URL(currentSrc, window.location.origin);
            url.searchParams.set('_t', Date.now());
            previewIframe.src = url.toString();
        }
    }

    // 显示Toast提示
    function showToast(message, type) {
        let toastContainer = document.getElementById('theme-edit-toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'theme-edit-toast-container';
            toastContainer.style.cssText = 'position: fixed; top: 70px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
            document.body.appendChild(toastContainer);
        }
        
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8';
        const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
        
        toast.style.cssText = 'background: ' + bgColor + '; color: #fff; padding: 12px 20px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; font-size: 14px; opacity: 0; transform: translateX(100%); transition: all 0.3s ease; pointer-events: auto;';
        toast.innerHTML = '<span style="font-size: 16px;">' + icon + '</span><span>' + message + '</span>';
        
        toastContainer.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // HTML转义
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 暴露保存参数函数到全局
    window.saveParams = saveParams;

    // DOM加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

