// 如果没有__函数，则定义一个
if (typeof __ === 'undefined') {
    function __(text) {
        return text;
    }
}
/**
 * DataTable Manager - 数据表格管理器
 * 提供数据表格的初始化、配置、数据加载、筛选、排序等功能
 *
 * @version 2.0.0
 * @author Weline Framework
 * @description 增强版数据表格管理器，支持多模型、JOIN查询、实时编辑等功能
 */

// 添加手风琴式筛选工具栏的CSS样式
if (typeof filterToolbarStyles === 'undefined') {
    var filterToolbarStyles = `
<style>
.filter-toolbar-item {
    display: inline-block;
    margin-right: 15px;
    margin-bottom: 10px;
    vertical-align: top;
}

.filter-toolbar-item label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 4px;
    font-weight: 500;
}

.filter-toolbar-item .filter-input {
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    min-width: 120px;
}

.filter-toolbar-item select.filter-input {
    min-width: 140px;
}

.filter-specified-fields {
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.filter-accordion {
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}

.filter-accordion-header {
    background: #f8f9fa;
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    color: #495057;
    border-bottom: 1px solid #ddd;
}

.filter-accordion-header:hover {
    background: #e9ecef;
}

.filter-accordion-header i {
    margin-right: 6px;
}

.filter-accordion-icon {
    margin-left: auto;
    transition: transform 0.2s;
}

.filter-accordion-content {
    background: white;
    padding: 12px;
}

.filter-accordion-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.filter-accordion-fields .filter-toolbar-item {
    margin-right: 0;
    margin-bottom: 0;
}
</style>
`;
}

// 将样式添加到页面
if (!document.querySelector('#datatable-filter-toolbar-styles')) {
    const styleElement = document.createElement('div');
    styleElement.id = 'datatable-filter-toolbar-styles';
    styleElement.innerHTML = filterToolbarStyles;
    document.head.appendChild(styleElement);
}

// 确保 DataTableManager 暴露到 window 上（单例模式）
// 如果已经存在且完整，直接使用；否则创建新实例
if (typeof window === 'undefined' || !window.DataTableManager || typeof window.DataTableManager.initTable !== 'function') {
    // 创建新的 DataTableManager 实例
    var DataTableManager = {
    // 表格实例缓存
    instances: {},

    // 配置选项
    config: {
        apiUrl: (typeof window !== 'undefined' && typeof window.api === 'function') 
            ? window.api('datatable/rest/v1/data-table') 
            : (typeof window !== 'undefined' && window.site && window.site.api_host)
                ? (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') + 'datatable/data-table'
                : '/api/rest/v1/datatable/data-table',
        defaultPageSize: 20,
        maxPageSize: 100,
        debounceDelay: 300,
        autoSave: true,
        confirmDelete: true
    },

    // 注意：editingState 已移到每个实例中，确保实例隔离

    /**
     * 初始化下拉菜单功能
     */
    initDropdowns: function () {
        // 防止重复初始化
        if (window._wDropdownInitialized) {
            return;
        }
        window._wDropdownInitialized = true;

        // 使用事件委托处理下拉菜单切换
        document.addEventListener('click', function (e) {
            const toggle = e.target.closest('[data-w-toggle="dropdown"]');
            
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownContainer = toggle.closest('.w-dropdown');
                const dropdown = dropdownContainer ? dropdownContainer.querySelector('.w-dropdown-menu') : toggle.parentElement.querySelector('.w-dropdown-menu');
                
                if (!dropdown) {
                    console.warn('DataTable: dropdown menu not found for toggle button');
                    return;
                }
                
                // 关闭其他所有下拉菜单
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    if (menu !== dropdown) {
                        menu.classList.remove('show');
                    }
                });
                
                // 切换当前下拉菜单
                const isOpen = dropdown.classList.contains('show');
                dropdown.classList.toggle('show');
                
                // 添加旋转动画到图标（如果有的话）
                const icon = toggle.querySelector('i.fas.fa-undo, i.fas.fa-chevron-down');
                if (icon) {
                    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
                }
                
                return;
            }
            
            // 如果点击的不是下拉菜单内部，关闭所有下拉菜单
            if (!e.target.closest('.w-dropdown-menu') && !e.target.closest('.w-dropdown-item')) {
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    menu.classList.remove('show');
                });
                document.querySelectorAll('[data-w-toggle="dropdown"] i.fas.fa-undo, [data-w-toggle="dropdown"] i.fas.fa-chevron-down').forEach(function (icon) {
                    icon.style.transform = 'rotate(0deg)';
                });
            }
        }, false);

        // ESC键关闭下拉菜单
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.w-dropdown-menu.show').forEach(function (menu) {
                    menu.classList.remove('show');
                });
                document.querySelectorAll('[data-w-toggle="dropdown"] i.fas.fa-undo, [data-w-toggle="dropdown"] i.fas.fa-chevron-down').forEach(function (icon) {
                    icon.style.transform = 'rotate(0deg)';
                });
            }
        }, false);
    },

    /**
     * 获取当前主题
     * @returns {string} 'dark' | 'light'
     */
    getCurrentTheme: function () {
        const body = document.body;
        const sidebarTheme = body.getAttribute('data-sidebar');
        const topbarTheme = body.getAttribute('data-topbar');

        // 如果sidebar或topbar是dark，则返回dark
        if (sidebarTheme === 'dark' || topbarTheme === 'dark') {
            return 'dark';
        }

        // 检查媒体查询
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }

        return 'light';
    },

    /**
     * 应用主题
     * @param {string} theme - 'dark' | 'light'
     */
    applyTheme: function (theme) {
        const body = document.body;
        const tables = document.querySelectorAll('.weline-datatable, .w-datatable');

        tables.forEach(function (table) {
            if (theme === 'dark') {
                table.classList.add('theme-dark');
            } else {
                table.classList.remove('theme-dark');
            }
        });

        // 应用表单主题
        const forms = document.querySelectorAll('.w-form-container, .w-form-inline-container');
        forms.forEach(function (form) {
            if (theme === 'dark') {
                form.classList.add('theme-dark');
            } else {
                form.classList.remove('theme-dark');
            }
        });
    },

    /**
     * 初始化主题
     */
    initTheme: function () {
        const currentTheme = this.getCurrentTheme();
        this.applyTheme(currentTheme);

        // 监听主题变化（如果系统有全局主题切换事件）
        if (window.addEventListener && typeof MutationObserver !== 'undefined') {
            // 监听body属性变化
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'attributes' &&
                        (mutation.attributeName === 'data-sidebar' || mutation.attributeName === 'data-topbar')) {
                        const newTheme = this.getCurrentTheme();
                        this.applyTheme(newTheme);
                    }
                });
            });

            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['data-sidebar', 'data-topbar']
            });

            // 监听媒体查询变化
            if (window.matchMedia) {
                const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
                if (mediaQuery.addEventListener) {
                    mediaQuery.addEventListener('change', (e) => {
                        const theme = e.matches ? 'dark' : 'light';
                        this.applyTheme(theme);
                    });
                } else {
                    // 兼容旧版浏览器
                    mediaQuery.addListener((e) => {
                        const theme = e.matches ? 'dark' : 'light';
                        this.applyTheme(theme);
                    });
                }
            }
        }
    },

    /**
     * 初始化主题配置功能
     */
    initThemeConfig: function () {
        // 创建主题配置面板
        if (!document.querySelector('.w-theme-config')) {
            // 确保翻译函数存在
            const translate = window.__ || function (text) { return text; };

            const tableThemeConfig = __('表格主题配置');
            const displayOptions = __('显示选项');
            const showZebra = __('显示斑马纹');
            const showHover = __('显示悬停效果');
            const showSort = __('显示排序图标');
            const colorTheme = __('颜色主题');
            const primaryColor = __('主色调');
            const headerBackground = __('表头背景');
            const hoverColor = __('行悬停色');
            const fontSettings = __('字体设置');
            const fontSize = __('字体大小');
            const small = __('小');
            const medium = __('中');
            const large = __('大');

            const themeConfigHtml = `
                <div class="w-theme-config">
                    <div class="w-theme-config-header">
                        <h4 class="w-theme-config-title">
                            <i class="fas fa-palette"></i>
                            ${tableThemeConfig}
                        </h4>
                    </div>
                    <div class="w-theme-config-body">
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${displayOptions}</div>
                            <div class="w-theme-option">
                                <label>${showZebra}</label>
                                <input type="checkbox" id="theme-zebra" checked>
                            </div>
                            <div class="w-theme-option">
                                <label>${showHover}</label>
                                <input type="checkbox" id="theme-hover" checked>
                            </div>
                            <div class="w-theme-option">
                                <label>${showSort}</label>
                                <input type="checkbox" id="theme-sort" checked>
                            </div>
                        </div>
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${colorTheme}</div>
                            <div class="w-theme-option">
                                <label>${primaryColor}</label>
                                <input type="color" id="theme-primary" value="#3b82f6">
                            </div>
                            <div class="w-theme-option">
                                <label>${headerBackground}</label>
                                <input type="color" id="theme-header" value="#f8fafc">
                            </div>
                            <div class="w-theme-option">
                                <label>${hoverColor}</label>
                                <input type="color" id="theme-hover-color" value="#f1f5f9">
                            </div>
                        </div>
                        <div class="w-theme-section">
                            <div class="w-theme-section-title">${fontSettings}</div>
                            <div class="w-theme-option">
                                <label>${fontSize}</label>
                                <select id="theme-font-size">
                                    <option value="0.875rem">${small}</option>
                                    <option value="1rem" selected>${medium}</option>
                                    <option value="1.125rem">${large}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', themeConfigHtml);
            console.log('主题配置面板已创建');
        } else {
            console.log('主题配置面板已存在');
        }

        // 绑定主题配置事件
        this.bindThemeEvents();
    },

    /**
     * 绑定主题配置事件
     */
    bindThemeEvents: function () {
        // 主题配置切换
        document.removeEventListener('click', window._wThemeToggleHandler, false);
        window._wThemeToggleHandler = function (e) {
            const btn = e.target.closest('[data-w-action="theme-config"]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                console.log('主题配置按钮被点击');
                const themeConfig = document.querySelector('.w-theme-config');
                if (themeConfig) {
                    themeConfig.classList.toggle('show');
                    console.log('主题配置面板切换:', themeConfig.classList.contains('show'));
                } else {
                    console.error('主题配置面板未找到');
                }
            }
        };
        document.addEventListener('click', window._wThemeToggleHandler, false);

        // 点击外部关闭主题配置
        document.removeEventListener('click', window._wThemeCloseHandler, false);
        window._wThemeCloseHandler = function (e) {
            if (!e.target.closest('.w-theme-config')) {
                document.querySelector('.w-theme-config').classList.remove('show');
            }
        };
        document.addEventListener('click', window._wThemeCloseHandler, false);

        // 主题选项变化事件 - 延迟绑定，确保面板已创建
        setTimeout(() => {
            var themeInputs = document.querySelectorAll('.w-theme-config input, .w-theme-config select');
            themeInputs.forEach(function (input) {
                input.removeEventListener('change', window._wThemeChangeHandler, false);
                window._wThemeChangeHandler = function () {
                    DataTableManager.applyThemeConfig();
                };
                input.addEventListener('change', window._wThemeChangeHandler, false);
            });
        }, 100);
    },

    /**
     * 应用主题配置
     */
    applyThemeConfig: function () {
        const config = {
            zebra: document.getElementById('theme-zebra') && document.getElementById('theme-zebra').checked,
            hover: document.getElementById('theme-hover') && document.getElementById('theme-hover').checked,
            sort: document.getElementById('theme-sort') && document.getElementById('theme-sort').checked,
            primary: document.getElementById('theme-primary') && document.getElementById('theme-primary').value,
            header: document.getElementById('theme-header') && document.getElementById('theme-header').value,
            hoverColor: document.getElementById('theme-hover-color') && document.getElementById('theme-hover-color').value,
            fontSize: document.getElementById('theme-font-size') && document.getElementById('theme-font-size').value
        };

        // 应用配置到所有表格
        document.querySelectorAll('.w-datatable').forEach(function (table) {
            // 斑马纹
            if (config.zebra) {
                table.querySelectorAll('tbody tr:nth-child(even)').forEach(function (row) {
                    row.style.display = '';
                });
            } else {
                table.querySelectorAll('tbody tr:nth-child(even)').forEach(function (row) {
                    row.style.display = 'none';
                });
            }

            // 悬停效果
            if (config.hover) {
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.style.cursor = 'pointer';
                });
            } else {
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.style.cursor = 'default';
                });
            }

            // 排序图标
            if (config.sort) {
                table.querySelectorAll('th.sortable').forEach(function (th) {
                    th.style.display = '';
                });
            } else {
                table.querySelectorAll('th.sortable').forEach(function (th) {
                    th.style.display = 'none';
                });
            }

            // 字体大小
            table.querySelectorAll('td, th').forEach(function (cell) {
                cell.style.fontSize = config.fontSize;
            });
        });

        // 保存配置到本地存储
        localStorage.setItem('weline-datatable-theme', JSON.stringify(config));
    },

    /**
     * 加载主题配置
     */
    loadThemeConfig: function () {
        const savedConfig = localStorage.getItem('weline-datatable-theme');
        if (savedConfig) {
            const config = JSON.parse(savedConfig);

            // 设置表单值
            if (document.getElementById('theme-zebra')) document.getElementById('theme-zebra').checked = config.zebra;
            if (document.getElementById('theme-hover')) document.getElementById('theme-hover').checked = config.hover;
            if (document.getElementById('theme-sort')) document.getElementById('theme-sort').checked = config.sort;
            if (document.getElementById('theme-primary')) document.getElementById('theme-primary').value = config.primary;
            if (document.getElementById('theme-header')) document.getElementById('theme-header').value = config.header;
            if (document.getElementById('theme-hover-color')) document.getElementById('theme-hover-color').value = config.hoverColor;
            if (document.getElementById('theme-font-size')) document.getElementById('theme-font-size').value = config.fontSize;

            // 应用配置
            this.applyThemeConfig();
        }
    },

    /**
     * 重要列标注功能
     */
    initImportantFlags: function () {
        // 绑定重要列切换事件
        document.removeEventListener('click', window._wImportantToggleHandler, false);
        window._wImportantToggleHandler = function (e) {
            const btn = e.target.closest('[data-w-action="important-view"]');
            if (btn) {
                e.preventDefault();
                const table = btn.closest('.w-datatable');
                if (table) {
                    DataTableManager.toggleImportantView(table);
                }
            }
        };
        document.addEventListener('click', window._wImportantToggleHandler, false);
    },

    /**
     * 切换重要列显示
     */
    toggleImportantView: function (tableIdOrElement) {
        // 支持传入 tableId 字符串或 DOM 元素
        let table = tableIdOrElement;
        if (typeof tableIdOrElement === 'string') {
            const instance = this.getInstance(tableIdOrElement);
            if (instance) {
                table = instance.container[0] || instance.container;
            } else {
                table = document.getElementById(tableIdOrElement);
            }
        }
        
        if (!table) {
            console.error('toggleImportantView: table not found');
            return;
        }

        const isImportantView = table.classList.contains('w-important-view');

        if (isImportantView) {
            // 显示所有列
            table.classList.remove('w-important-view');
            table.querySelectorAll('th, td').forEach(function (cell) {
                cell.style.display = '';
            });
            const btn = table.querySelector('[data-w-action="important-view"]');
            if (btn) btn.textContent = __('只显示重要数据');
        } else {
            // 只显示重要列
            table.classList.add('w-important-view');
            table.querySelectorAll('th, td').forEach(function (cell) {
                cell.style.display = 'none';
            });
            table.querySelectorAll('.w-important-column').forEach(function (cell) {
                cell.style.display = '';
            });
            const btn = table.querySelector('[data-w-action="important-view"]');
            if (btn) btn.textContent = __('显示所有数据');
        }
    },

    /**
     * 保存重要列配?
     */
    saveImportantColumns: function (tableId, columnIndex, isImportant) {
        const key = `weline-datatable-important-${tableId}`;
        let importantColumns = JSON.parse(localStorage.getItem(key) || '[]');

        if (isImportant) {
            if (!importantColumns.includes(columnIndex)) {
                importantColumns.push(columnIndex);
            }
        } else {
            importantColumns = importantColumns.filter(col => col !== columnIndex);
        }

        localStorage.setItem(key, JSON.stringify(importantColumns));
    },

    /**
     * 加载重要列配?
     */
    loadImportantColumns: function (tableId) {
        const key = `weline-datatable-important-${tableId}`;
        const importantColumns = JSON.parse(localStorage.getItem(key) || '[]');

        const $table = $(`#${tableId}`);
        importantColumns.forEach(columnIndex => {
            $table.find(`th:eq(${columnIndex}), td:eq(${columnIndex})`).addClass('w-important-column');
            $table.find(`td:eq(${columnIndex}) .w-important-flag`).addClass('active');
        });
    },

    /**
     * 导出数据功能
     */
    exportData: function (tableId, format = 'excel') {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        // 显示导出进度模态框
        this.showExportModal(tableId, format);

        // 开始导出过?
        this.startExport(tableId, format);
    },

    /**
     * 显示导出进度模态框
     */
    showExportModal: function (tableId, format) {
        const instance = this.instances[tableId];
        const totalRecords = instance ? instance.totalCount || 0 : 0;
        const pageSize = instance ? instance.pageSize || 20 : 20;
        const totalPages = Math.ceil(totalRecords / pageSize);

        const modalHtml = `
            <div class="w-export-modal show">
                <div class="w-export-content">
                    <div class="w-export-header">
                        <h3 class="w-export-title">
                            <i class="fas fa-download me-2"></i>
                            ${__('正在导出数据')}
                        </h3>
                        <p class="w-export-subtitle">
                            ${__('导出格式')}：<span class="format-badge ${format}">${format.toUpperCase()}</span>
                            <br>${__('预计导出')} <strong>${totalRecords}</strong> ${__('条记录')}，${__('共')} <strong>${totalPages}</strong> ${__('页')}
                        </p>
                    </div>
                    
                    <div class="w-export-progress-section">
                        <div class="w-progress-info">
                            <div class="progress-stats">
                                <div class="stat-item">
                                    <span class="stat-label">${__('当前页')}：</span>
                                    <span class="stat-value current-page">0</span>
                                    <span class="stat-separator">/</span>
                                    <span class="stat-value total-pages">${totalPages}</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">${__('已导出')}：</span>
                                    <span class="stat-value exported-records">0</span>
                                    <span class="stat-separator">/</span>
                                    <span class="stat-value total-records">${totalRecords}</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">${__('进度')}：</span>
                                    <span class="stat-value progress-percentage">0%</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="w-export-progress">
                            <div class="w-progress-bar">
                                <div class="w-progress-fill" style="width: 0%"></div>
                                <div class="w-progress-text">0%</div>
                            </div>
                        </div>
                        
                        <div class="w-export-status">
                            <i class="fas fa-spinner fa-spin loading"></i>
                            <span class="w-export-status-text">${__('正在初始化导出...')}</span>
                        </div>
                        
                        <div class="w-export-time-info">
                            <div class="time-item">
                                <span class="time-label">${__('已用时间')}：</span>
                                <span class="time-value elapsed-time">00:00</span>
                            </div>
                            <div class="time-item">
                                <span class="time-label">${__('预计剩余')}：</span>
                                <span class="time-value remaining-time">${__('计算中...')}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="w-export-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="w-export-warning-text">${__('导出过程中请勿关闭此窗口，以免导致数据丢失')}</span>
                    </div>
                    
                    <div class="w-export-actions">
                        <button type="button" class="w-export-btn secondary" onclick="DataTableManager.cancelExport()" id="cancel-export-btn">
                            <i class="fas fa-times me-1"></i>${__('取消导出')}
                        </button>
                    </div>
                </div>
                
                <!-- 添加导出模态框样式（支持主题适配） -->
                <style>
                    .w-export-modal {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 0, 0, 0.6);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 10000;
                    }
                    
                    .w-export-content {
                        background: var(--datatable-bg, var(--bs-body-bg, #fff));
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        border-radius: 8px;
                        padding: 24px;
                        min-width: 480px;
                        max-width: 600px;
                        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                        position: relative;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .w-export-header {
                        margin-bottom: 20px;
                        text-align: center;
                    }
                    
                    .w-export-title {
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        margin: 0 0 8px 0;
                        font-size: 18px;
                        font-weight: 600;
                    }
                    
                    .w-export-subtitle {
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                        margin: 0;
                        font-size: 14px;
                        line-height: 1.5;
                    }
                    
                    .format-badge {
                        display: inline-block;
                        padding: 2px 8px;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: bold;
                        color: white;
                    }
                    
                    .format-badge.excel { background: #217346; }
                    .format-badge.csv { background: #d63384; }
                    .format-badge.json { background: #6f42c1; }
                    
                    .w-export-progress-section {
                        margin-bottom: 20px;
                    }
                    
                    .progress-stats {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 16px;
                        padding: 12px;
                        background: var(--datatable-hover-bg, var(--bs-tertiary-bg, #f8f9fa));
                        border-radius: 6px;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .stat-item {
                        text-align: center;
                        flex: 1;
                    }
                    
                    .stat-label {
                        font-size: 12px;
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                        display: block;
                        margin-bottom: 4px;
                    }
                    
                    .stat-value {
                        font-size: 16px;
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                    }
                    
                    .stat-separator {
                        color: var(--datatable-muted, var(--bs-secondary-color, #999));
                        margin: 0 2px;
                    }
                    
                    .w-progress-bar {
                        position: relative;
                        height: 20px;
                        background: var(--datatable-hover-bg, var(--bs-tertiary-bg, #e9ecef));
                        border-radius: 10px;
                        overflow: hidden;
                        margin-bottom: 12px;
                        border: 1px solid var(--datatable-border, var(--bs-border-color, #dee2e6));
                    }
                    
                    .w-progress-fill {
                        height: 100%;
                        background: linear-gradient(45deg, #28a745, #20c997);
                        transition: width 0.3s ease;
                        position: relative;
                    }
                    
                    .w-progress-text {
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        font-size: 12px;
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        z-index: 1;
                    }
                    
                    .w-export-status {
                        display: flex;
                        align-items: center;
                        margin-bottom: 16px;
                        padding: 8px 0;
                    }
                    
                    .w-export-status i {
                        margin-right: 8px;
                        font-size: 16px;
                        color: var(--datatable-primary, var(--bs-primary, #007bff));
                    }
                    
                    .w-export-status-text {
                        font-size: 14px;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                    }
                    
                    .w-export-time-info {
                        display: flex;
                        justify-content: space-between;
                        margin-bottom: 16px;
                        font-size: 13px;
                    }
                    
                    .time-label {
                        color: var(--datatable-muted, var(--bs-secondary-color, #666));
                    }
                    
                    .time-value {
                        font-weight: bold;
                        color: var(--datatable-text, var(--bs-body-color, #333));
                        margin-left: 4px;
                    }
                    
                    .w-export-warning {
                        background: var(--bs-warning-bg-subtle, #fff3cd);
                        border: 1px solid var(--bs-warning-border-subtle, #ffeaa7);
                        color: var(--bs-warning-text-emphasis, #856404);
                        padding: 8px 12px;
                        border-radius: 4px;
                        margin-bottom: 20px;
                        font-size: 13px;
                        display: flex;
                        align-items: center;
                    }
                    
                    .w-export-warning i {
                        margin-right: 8px;
                        color: var(--bs-warning, #f39c12);
                    }
                    
                    .w-export-actions {
                        text-align: center;
                    }
                    
                    .w-export-btn {
                        padding: 8px 20px;
                        border: none;
                        border-radius: 4px;
                        font-size: 14px;
                        cursor: pointer;
                        transition: all 0.2s;
                    }
                    
                    .w-export-btn.secondary {
                        background: var(--bs-secondary, #6c757d);
                        color: white;
                    }
                    
                    .w-export-btn.secondary:hover {
                        background: var(--bs-secondary-bg-subtle, #5a6268);
                    }
                    
                    .w-export-btn.primary {
                        background: var(--datatable-primary, var(--bs-primary, #007bff));
                        color: white;
                    }
                    
                    .w-export-btn.primary:hover {
                        opacity: 0.9;
                    }
                </style>
            </div>
        `;

        // 移除现有模态框
        $('.w-export-modal').remove();

        // 添加新模态框
        $('body').append(modalHtml);
    },

    /**
     * 开始导出过程
     */
    startExport: function (tableId, format) {
        const instance = this.instances[tableId];
        const totalRecords = instance.totalCount || 0;
        const pageSize = instance.pageSize || 20;
        const totalPages = Math.ceil(totalRecords / pageSize);
        let currentPage = 1;
        let allData = [];
        let isCancelled = false;
        let startTime = Date.now();

        // 计时器
        const timer = setInterval(() => {
            if (isCancelled) {
                clearInterval(timer);
                return;
            }

            const elapsed = Date.now() - startTime;
            const elapsedText = this.formatTime(elapsed);
            $('.elapsed-time').text(elapsedText);

            // 计算剩余时间
            if (currentPage > 1) {
                const avgTimePerPage = elapsed / (currentPage - 1);
                const remainingPages = totalPages - currentPage + 1;
                const remainingTime = avgTimePerPage * remainingPages;
                const remainingText = this.formatTime(remainingTime);
                $('.remaining-time').text(remainingText);
            }
        }, 1000);

        // 取消导出事件
        window.DataTableManager.cancelExport = function () {
            isCancelled = true;
            clearInterval(timer);
            $('.w-export-modal').remove();
        };

        const exportNextPage = () => {
            if (isCancelled) {
                clearInterval(timer);
                return;
            }

            // 更新进度信息
            const progress = Math.round(((currentPage - 1) / totalPages) * 100);
            const exportedRecords = (currentPage - 1) * pageSize;

            $('.w-progress-fill').css('width', progress + '%');
            $('.w-progress-text').text(`${progress}%`);
            $('.current-page').text(currentPage);
            $('.exported-records').text(Math.min(exportedRecords, totalRecords));
            $('.progress-percentage').text(`${progress}%`);
            $('.w-export-status-text').text(`正在获取第 ${currentPage} 页数据...`);

            // 使用数据获取API进行分页导出
            const dataApiUrl = (typeof window !== 'undefined' && typeof window.api === 'function') 
                ? window.api('datatable/rest/v1/data-table/data') 
                : (typeof window !== 'undefined' && window.site && window.site.api_host)
                    ? (window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/') + 'datatable/data-table/data'
                    : '/api/rest/v1/datatable/data-table/data';
            fetch(dataApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    model: instance.options.model,
                    scope: instance.options.scope,
                    page: currentPage,
                    limit: pageSize,
                    filters: instance.filters || {},
                    sort: instance.sorts || {},
                    search: instance.search || ''
                })
            })
                .then(response => response.json())
                .then(response => {
                    if (isCancelled) {
                        clearInterval(timer);
                        return;
                    }

                    if ((response.code == 200 || response.code === '200' || response.success) && response.data) {
                        // 添加当前页数据
                        if (response.data.data) {
                            allData = allData.concat(response.data.data);
                        }

                        currentPage++;

                        if (currentPage <= totalPages) {
                            // 继续下一页，添加小延时避免服务器压力
                            setTimeout(exportNextPage, 200);
                        } else {
                            // 完成导出
                            clearInterval(timer);
                            this.completeExport(allData, format, tableId);
                        }
                    } else {
                        clearInterval(timer);
                        this.showExportError('获取数据失败：' + (response.msg || '未知错误'));
                    }
                })
                .catch(error => {
                    clearInterval(timer);
                    this.showExportError('网络错误：' + error.message);
                });
        };

        // 开始导出
        exportNextPage();
    },

    /**
     * 格式化时间显示
     */
    formatTime: function (milliseconds) {
        const seconds = Math.floor(milliseconds / 1000);
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;

        return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
    },

    /**
     * 完成导出
     */
    completeExport: function (data, format, tableId) {
        document.querySelector('.w-progress-fill').style.width = '100%';
        document.querySelector('.w-progress-text').textContent = __('正在生成文件...');
        document.querySelector('.w-export-status-text').textContent = __('导出完成！');
        let icon = document.querySelector('.w-export-status i');
        icon.classList.remove('fa-spinner', 'fa-spin', 'loading');
        icon.classList.add('fa-check-circle');
        try {
            let content, filename, mimeType;
            if (format === 'excel') {
                content = this.generateExcel(data, tableId);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.xlsx`;
                mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else if (format === 'csv') {
                content = this.generateCSV(data, tableId);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.csv`;
                mimeType = 'text/csv';
            } else if (format === 'json') {
                content = JSON.stringify(data, null, 2);
                filename = `datatable_export_${tableId}_${new Date().getTime()}.json`;
                mimeType = 'application/json';
            }
            // 创建下载链接
            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            // 更新模态框
            document.querySelector('.w-export-actions').innerHTML = `
                <button type="button" class="w-export-btn primary" onclick="document.querySelector('.w-export-modal').remove()">完成</button>
            `;
        } catch (error) {
            this.showExportError('生成文件失败：' + error.message);
        }
    },

    /**
     * 生成Excel文件
     */
    generateExcel: function (data, tableId) {
        // 这里使用简单的CSV格式，实际项目中可以使用SheetJS等库
        return this.generateCSV(data, tableId);
    },

    /**
     * 生成CSV文件
     */
    generateCSV: function (data, tableId) {
        if (!data || data.length === 0) return '';

        const instance = this.instances[tableId];
        const headers = instance.displayFields.map(field => field.label || field.name);
        const csvRows = [headers.join(',')];

        data.forEach(row => {
            const values = instance.displayFields.map(field => {
                let value = row[field.name] || '';
                // 处理包含逗号的?
                if (typeof value === 'string' && value.includes(',')) {
                    value = `"${value}"`;
                }
                return value;
            });
            csvRows.push(values.join(','));
        });

        return csvRows.join('\n');
    },

    /**
     * 显示导出错误
     */
    showExportError: function (message) {
        let icon = document.querySelector('.w-export-status i');
        icon.classList.remove('fa-spinner', 'fa-spin', 'loading');
        icon.classList.add('fa-exclamation-circle');
        document.querySelector('.w-export-status-text').textContent = __('导出失败');
        document.querySelector('.w-export-actions').innerHTML = `
            <button type="button" class="w-export-btn primary" onclick="document.querySelector('.w-export-modal').remove()">${__('关闭')}</button>
        `;
        console.error('Export error:', message);
    },

    /**
     * 字段类型选项
     */
    fieldTypeOptions: [
        { value: 'text', label: '文本' },
        { value: 'number', label: '数字' },
        { value: 'date', label: '日期' },
        { value: 'select', label: '下拉选项' },
        { value: 'email', label: '邮箱' },
        { value: 'tel', label: '电话' },
        { value: 'url', label: '网址' },
        { value: 'image', label: '图片' }
    ],

    /**
     * 初始化表格（实例隔离）
     */
    initTable: function (selector, options) {
        const container = document.querySelector(selector);
        if (!container) {
            console.error('DataTable container not found:', selector);
            return null;
        }
        
        // 检查是否设置了隔离标志
        const isolate = options.isolate === true;
        
        let tableId = container.getAttribute('id');
        let instanceKey = tableId; // 用于存储实例的键
        
        // 如果设置了隔离标志，使用 scope 作为实例标识符
        if (isolate) {
            if (!options.scope) {
                console.error('DataTable: isolate flag is set but scope is not provided');
                return null;
            }
            // 使用 scope 作为实例标识符
            instanceKey = 'scope-' + options.scope;
            
            // 如果容器没有 ID，使用 scope 生成 ID
            if (!tableId) {
                tableId = 'datatable-scope-' + options.scope;
                container.setAttribute('id', tableId);
            } else {
                // 如果已有 ID，但设置了隔离标志，确保 ID 与 scope 一致
                const expectedId = 'datatable-scope-' + options.scope;
                if (tableId !== expectedId) {
                    console.warn('DataTable: isolate flag is set, but container ID does not match scope. Expected:', expectedId, 'Got:', tableId);
                    // 更新容器 ID 以匹配 scope
                    container.setAttribute('id', expectedId);
                    tableId = expectedId;
                }
            }
            
            // 检查是否已存在相同 scope 的实例
            if (this.instances[instanceKey]) {
                console.warn('DataTable instance with scope already exists:', options.scope, 'Reusing existing instance.');
                // 更新容器的引用（可能同一个 scope 有多个容器）
                this.instances[instanceKey].container = container;
                return this.instances[instanceKey];
            }
        } else {
            // 未设置隔离标志，使用 tableId 作为实例标识符
            if (!tableId) {
                // 如果没有 ID，自动生成一个唯一的 ID
                tableId = 'datatable-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                container.setAttribute('id', tableId);
            }
            instanceKey = tableId;
            
            // 如果实例已存在，返回现有实例
            if (this.instances[instanceKey]) {
                console.warn('DataTable instance already exists for:', tableId);
                return this.instances[instanceKey];
            }
            
            // 确保 scope 的唯一性（如果未提供或已存在，添加 tableId 后缀）
            if (options.scope) {
                const existingScope = this.getInstanceByScope(options.scope);
                if (existingScope && existingScope.id !== tableId) {
                    options.scope = options.scope + '-' + tableId;
                    console.warn('Scope conflict detected, using:', options.scope);
                }
            }
        }
        // 自动推断API基础路径
        let apiUrl = options.apiUrl;
        if (!apiUrl && typeof window !== 'undefined' && typeof window.api === 'function') {
            apiUrl = window.api('datatable/rest/v1/data-table');
        } else if (!apiUrl && typeof window !== 'undefined' && window.site && window.site.api_host) {
            const apiHost = window.site.api_host.endsWith('/') ? window.site.api_host : window.site.api_host + '/';
            apiUrl = apiHost + 'datatable/rest/v1/data-table';
        } else if (!apiUrl) {
            apiUrl = '/api/rest/v1/datatable/data-table';
        }
        const instance = {
            id: tableId, // 容器ID
            instanceKey: instanceKey, // 实例存储键（可能是 tableId 或 scope）
            scope: options.scope, // scope 值
            isolate: isolate, // 隔离标志
            container: container,
            options: options,
            currentPage: 1,
            pageSize: options.pageSize || 20,
            data: [],
            config: {},
            filters: {},
            search: '',
            sorts: {},
            isEditing: false,
            editingRow: null,
            editingData: {},
            apiUrl: apiUrl,
            allFields: [],
            displayFields: [],
            filterFields: [],
            // 每个实例独立的编辑状态，确保实例隔离
            editingState: {
                isEditing: false,
                currentCell: null,
                originalValue: null,
                editingRow: null
            },
            // 事件处理器存储，用于清理
            eventHandlers: {},
            // 实例特定的命名空间
            namespace: isolate ? 'datatable-scope-' + options.scope : 'datatable-' + tableId
        };
        // 使用 instanceKey 存储实例（可能是 tableId 或 scope）
        this.instances[instanceKey] = instance;

        // 初始化下拉菜单（确保每次初始化表格时都检查）
        this.initDropdowns();

        // 初始化主题
        this.initTheme();

        // 初始化批量操作工具栏
        this.initBatchActionToolbar(instance);

        // 初始化时加载字段配置
        this.loadFieldsOnInit(instance);
        
        // 在容器上添加实例标记，便于查找
        container.setAttribute('data-datatable-instance', tableId);
        
        return instance;
    },
    
    /**
     * 销毁表格实例（清理所有事件和资源，确保实例隔离）
     * @param {string} identifier - 实例标识符（tableId 或 scope，取决于是否设置了隔离标志）
     */
    destroyInstance: function (identifier) {
        // 尝试直接查找
        let instance = this.instances[identifier];
        let instanceKey = identifier;
        
        // 如果未找到，尝试通过 scope 查找
        if (!instance) {
            const scopeKey = 'scope-' + identifier;
            instance = this.instances[scopeKey];
            if (instance) {
                instanceKey = scopeKey; // 更新为正确的键
            }
        }
        
        // 如果仍未找到，尝试通过 tableId 查找
        if (!instance) {
            for (const key in this.instances) {
                if (this.instances[key].id === identifier) {
                    instance = this.instances[key];
                    instanceKey = key; // 更新为正确的键
                    break;
                }
            }
        }
        
        if (!instance) {
            console.warn('DataTable instance not found:', identifier);
            return;
        }
        
        // 清理所有事件处理器
        if (instance.eventHandlers) {
            // 清理批量操作事件
            if (instance.eventHandlers.batchActions) {
                instance.eventHandlers.batchActions.forEach(({ element, event, handler }) => {
                    if (element && handler) {
                        element.removeEventListener(event, handler);
                    }
                });
            }
            
            // 清理其他事件
            if (instance.eventHandlers.dblclick) {
                const table = document.getElementById(instance.id);
                if (table) {
                    table.removeEventListener('dblclick', instance.eventHandlers.dblclick);
                }
            }
            
            if (instance.eventHandlers.keydown) {
                document.removeEventListener('keydown', instance.eventHandlers.keydown);
            }
        }
        
        // 清理编辑状态
        if (instance.editingState && instance.editingState.isEditing) {
            this.cancelCellEdit(instance.id);
        }
        
        // 从容器上移除实例标记
        if (instance.container) {
            instance.container.removeAttribute('data-datatable-instance');
        }
        
        // 从实例列表中移除（使用正确的键）
        delete this.instances[instanceKey];
        
        console.log('DataTable instance destroyed:', instanceKey, instance.isolate ? '(isolated by scope: ' + instance.scope + ')' : '');
    },

    /**
     * 初始化批量操作工具栏
     */
    initBatchActionToolbar: function (instance) {
        if (instance.options.enableBatchActions === false) return;

        const tableId = instance.container.getAttribute('id');
        const container = instance.container[0] || instance.container;

        // 检查是否已存在工具栏
        let toolbar = container.querySelector('.batch-action-toolbar');
        if (!toolbar) {
            // 创建批量操作工具栏
            const toolbarHtml = `
                <div class="batch-action-toolbar" style="display: none; margin-bottom: 10px;">
                    <div class="d-flex align-items-center gap-2">
                        <span class="selected-count">已选中 <strong>0</strong> 项</span>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-danger batch-delete-btn">
                                <i class="fas fa-trash me-1"></i>删除选中
                            </button>
                            <button type="button" class="btn btn-sm btn-warning batch-soft-delete-btn">
                                <i class="fas fa-archive me-1"></i>移至回收站
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary batch-clear-btn">
                                <i class="fas fa-times me-1"></i>取消选择
                            </button>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-info batch-export-btn">
                                    <i class="fas fa-download me-1"></i>导出选中
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split"
                                        data-bs-toggle="dropdown">
                                    <span class="visually-hidden">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item export-excel-btn" href="#"><i class="fas fa-file-excel me-2"></i>导出为Excel</a></li>
                                    <li><a class="dropdown-item export-csv-btn" href="#"><i class="fas fa-file-csv me-2"></i>导出为CSV</a></li>
                                    <li><a class="dropdown-item export-json-btn" href="#"><i class="fas fa-file-code me-2"></i>导出为JSON</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // 插入到表格前面
            const tableElement = container.querySelector('table');
            if (tableElement) {
                tableElement.insertAdjacentHTML('beforebegin', toolbarHtml);
                toolbar = container.querySelector('.batch-action-toolbar');
            }
        }

        // 绑定工具栏事件
        this.bindBatchActionEvents(instance, toolbar);
    },

    /**
     * 绑定批量操作事件（实例隔离）
     */
    bindBatchActionEvents: function (instance, toolbar) {
        if (!toolbar) {
            console.warn('批量操作工具栏不存在，跳过事件绑定');
            return;
        }
        const tableId = instance.container.getAttribute('id');
        
        // 初始化事件处理器存储
        if (!instance.eventHandlers) instance.eventHandlers = {};
        if (!instance.eventHandlers.batchActions) instance.eventHandlers.batchActions = [];

        // 删除选中项
        const deleteHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.batchDelete(instance, selectedIds, { softDelete: false });
        };
        toolbar.querySelector('.batch-delete-btn')?.addEventListener('click', deleteHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-delete-btn'), event: 'click', handler: deleteHandler });

        // 软删除选中项
        const softDeleteHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.batchDelete(instance, selectedIds, { softDelete: true });
        };
        toolbar.querySelector('.batch-soft-delete-btn')?.addEventListener('click', softDeleteHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-soft-delete-btn'), event: 'click', handler: softDeleteHandler });

        // 取消选择
        const clearHandler = () => {
            this.clearSelection(instance);
        };
        toolbar.querySelector('.batch-clear-btn')?.addEventListener('click', clearHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-clear-btn'), event: 'click', handler: clearHandler });

        // 导出功能
        const exportHandler = () => {
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'excel');
        };
        toolbar.querySelector('.batch-export-btn')?.addEventListener('click', exportHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.batch-export-btn'), event: 'click', handler: exportHandler });

        const exportExcelHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'excel');
        };
        toolbar.querySelector('.export-excel-btn')?.addEventListener('click', exportExcelHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-excel-btn'), event: 'click', handler: exportExcelHandler });

        const exportCsvHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'csv');
        };
        toolbar.querySelector('.export-csv-btn')?.addEventListener('click', exportCsvHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-csv-btn'), event: 'click', handler: exportCsvHandler });

        const exportJsonHandler = (e) => {
            e.preventDefault();
            const selectedIds = this.getSelectedRowIds(instance);
            this.exportDataBatch(instance, selectedIds, 'json');
        };
        toolbar.querySelector('.export-json-btn')?.addEventListener('click', exportJsonHandler);
        instance.eventHandlers.batchActions.push({ element: toolbar.querySelector('.export-json-btn'), event: 'click', handler: exportJsonHandler });

        // 全选/取消全选
        const selectAllCheckbox = instance.container.querySelector(`#select-all-${tableId}`);
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleSelectAll(instance, e.target.checked);
            });
        }
    },

    /**
     * 获取选中行的ID
     */
    getSelectedRowIds: function (instance) {
        const checkboxes = instance.container.querySelectorAll('.row-checkbox:checked');
        return Array.from(checkboxes).map(checkbox => checkbox.value);
    },

    /**
     * 切换全选状态
     */
    toggleSelectAll: function (instance, checked) {
        const checkboxes = instance.container.querySelectorAll('.row-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
            const row = checkbox.closest('tr');
            if (row) {
                row.classList.toggle('selected', checked);
            }
        });

        this.updateBatchActionButtons(instance);
    },

    /**
     * 更新批量操作按钮状态
     */
    updateBatchActionButtons: function (instance) {
        const selectedIds = this.getSelectedRowIds(instance);
        const toolbar = instance.container.querySelector('.batch-action-toolbar');
        const countElement = toolbar?.querySelector('.selected-count strong');

        if (toolbar) {
            if (selectedIds.length > 0) {
                toolbar.style.display = 'block';
                if (countElement) {
                    countElement.textContent = selectedIds.length;
                }
            } else {
                toolbar.style.display = 'none';
            }
        }

        // 更新全选复选框状态
        const tableId = instance.container.getAttribute('id');
        const selectAllCheckbox = instance.container.querySelector(`#select-all-${tableId}`);
        const allCheckboxes = instance.container.querySelectorAll('.row-checkbox');

        if (selectAllCheckbox && allCheckboxes.length > 0) {
            const checkedCount = instance.container.querySelectorAll('.row-checkbox:checked').length;
            selectAllCheckbox.checked = checkedCount === allCheckboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
        }
    },

    /**
     * 批量导出数据（用于批量操作工具栏）
     */
    exportDataBatch: function (instance, selectedIds = null, format = 'excel') {
        const tableId = instance.container.getAttribute('id');

        // 如果没有选中任何行，导出所有数据
        if (!selectedIds || selectedIds.length === 0) {
            selectedIds = instance.data.map(row => row.id || row.index);
        }

        if (selectedIds.length === 0) {
            this.showWarning(tableId, __('没有可导出的数据'));
            return;
        }

        // 显示加载状态
        this.showLoading(tableId, __('正在准备导出数据...'));

        // 准备导出参数
        const exportParams = {
            model: instance.options.model,
            ids: selectedIds,
            format: format,
            fields: instance.displayFields.map(field => ({
                name: field.name,
                label: field.label || field.name
            }))
        };

        // 发送导出请求
        fetch(window.api('datatable/rest/v1/data-table/export-data'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(exportParams)
        })
            .then(response => {
                this.hideLoading(tableId);

                if (response.ok) {
                    // 如果是文件下载，直接处理
                    if (format === 'excel' || format === 'csv') {
                        return response.blob().then(blob => {
                            this.downloadFile(blob, `export_${Date.now()}.${format === 'excel' ? 'xlsx' : 'csv'}`);
                        });
                    } else {
                        // JSON格式返回数据
                        return response.json().then(data => {
                            this.downloadJsonFile(data, `export_${Date.now()}.json`);
                        });
                    }
                } else {
                    throw new Error('导出失败');
                }
            })
            .then(() => {
                this.showSuccess(tableId, __('成功导出 %{1} 条记录', selectedIds.length));
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Export error:', error);
                this.showError(tableId, __('导出失败：%{1}', error.message));
            });
    },

    /**
     * 下载文件
     */
    downloadFile: function (blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    },

    /**
     * 下载JSON文件
     */
    downloadJsonFile: function (data, filename) {
        const jsonStr = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        this.downloadFile(blob, filename);
    },

    /**
     * 客户端导出功能（备用方案）
     */
    exportDataClient: function (instance, selectedIds = null, format = 'csv') {
        const tableId = instance.container.getAttribute('id');

        // 获取要导出的数据
        let exportData = instance.data;
        if (selectedIds && selectedIds.length > 0) {
            exportData = instance.data.filter(row => selectedIds.includes(row.id || row.index));
        }

        if (exportData.length === 0) {
            this.showWarning(tableId, __('没有可导出的数据'));
            return;
        }

        // 获取可见字段
        const visibleFields = instance.displayFields.filter(field => field.visible !== false);

        if (format === 'csv') {
            this.exportToCsv(exportData, visibleFields);
        } else if (format === 'json') {
            this.exportToJson(exportData, visibleFields);
        } else {
            this.showError(tableId, __('不支持的导出格式'));
        }
    },

    /**
     * 导出为CSV
     */
    exportToCsv: function (data, fields) {
        // 构建CSV头部
        const headers = fields.map(field => field.label || field.name);
        let csvContent = headers.join(',') + '\n';

        // 构建CSV数据行
        data.forEach(row => {
            const values = fields.map(field => {
                let value = row[field.name] || '';
                // 处理包含逗号或引号的值
                if (typeof value === 'string' && (value.includes(',') || value.includes('"') || value.includes('\n'))) {
                    value = '"' + value.replace(/"/g, '""') + '"';
                }
                return value;
            });
            csvContent += values.join(',') + '\n';
        });

        // 下载文件
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        this.downloadFile(blob, `export_${Date.now()}.csv`);
    },

    /**
     * 导出为JSON
     */
    exportToJson: function (data, fields) {
        // 只导出可见字段的数据
        const exportData = data.map(row => {
            const exportRow = {};
            fields.forEach(field => {
                exportRow[field.name] = row[field.name];
            });
            return exportRow;
        });

        this.downloadJsonFile(exportData, `export_${Date.now()}.json`);
    },

    /**
     * 初始化时加载字段配置
     */
    loadFieldsOnInit: function (instance) {
        console.log('loadFieldsOnInit: 开始加载字段配置', {
            model: instance.options.model,
            scope: instance.options.scope
        });
        // 先尝试从HTML中初始化基础配置
        this.initFromHTML(instance);
        // 然后加载字段配置并渲染表格
        this.loadModelFieldsForInit(instance.container.getAttribute('id'));
    },

    /**
     * 初始化时加载模型字段（会触发表格重新构建）
     */
    loadModelFieldsForInit: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl未设置，无法加载字段配置');
            return;
        }

        // 1. 提取模板字段（field指定字段）
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFieldsForInit: 模板字段', templateFields);
        console.log('loadModelFieldsForInit: 模板筛选字段', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFieldsForInit: 开始加载字段配置', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        fetch(instance.apiUrl + '/fields', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                table_id: tableId,
                model: instance.options.model,
                scope: instance.options.scope
            })
        })
            .then(response => response.json())
            .then(response => {
                // 3. 合并模板字段和接口字段（用于"可用字段"列表）
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 合并filter字段
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 确定显示字段：优先级为 缓存配置 > 模板字段 > API默认字段
                let displayFields;
                const cachedDisplayFields = response.data.cached_display_fields;
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                if (cachedDisplayFields && cachedDisplayFields.length > 0) {
                    // 有缓存配置，使用缓存配置，但确保模板字段属性优先
                    displayFields = cachedDisplayFields.map(cachedField => {
                        const templateField = templateFields.find(t => t.name === cachedField.name);
                        return templateField ? { ...cachedField, ...templateField } : cachedField;
                    });
                    console.log('loadModelFieldsForInit: 使用缓存配置', displayFields);
                } else if (templateFields.length > 0) {
                    // 没有缓存配置，但有模板字段，只显示模板字段
                    displayFields = [...templateFields];
                    console.log('loadModelFieldsForInit: 使用模板字段（默认只显示模板中指定的字段）', displayFields);
                } else {
                    // 没有缓存配置也没有模板字段，使用API默认字段
                    displayFields = response.data.display_fields || [];
                    console.log('loadModelFieldsForInit: 使用API默认字段', displayFields);
                }

                // 5. 记录用户选择的字段（非模板字段）
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFieldsForInit: 用户选择的字段', userSelectedFields);

                // 6. 处理受保护字段的配置
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 主键/索引字段不能排序和移动
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 其它受保护字段默认可以排序和移动
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 确保指定字段排到前面
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 重新排序：模板字段在前，用户字段在后
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 更新实例中的字段数据
                instance.allFields = mergedFields;
                instance.displayFields = displayFields;
                instance.filterFields = mergedFilterFields;

                // 9. 触发表格重新构建
                this.rebuildTableFromConfig(tableId, displayFields, mergedFilterFields);
            })
            .catch(error => {
                console.error('loadModelFieldsForInit: 加载字段配置失败', error);
                this.showError(tableId, error || __('获取字段失败'));
            });
    },

    /**
     * 从HTML中初始化配置（支持data-w-field属性）
     */
    initFromHTML: function (instance) {
        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        const filterContainer = container.querySelector('.datatable-filter');

        // 优先从th[data-w-field]读取字段配置
        const fields = [];
        if (thead) {
            const thElements = thead.querySelectorAll('th[data-w-field]');
            thElements.forEach(function (th) {
                try {
                    const fieldConfig = JSON.parse(th.getAttribute('data-w-field'));
                    fields.push(fieldConfig);
                } catch (e) {
                    // fallback: 兼容旧结构
                    const fieldName = th.getAttribute('data-field');
                    if (fieldName) fields.push({ name: fieldName, label: th.textContent.trim(), type: 'text', visible: true });
                }
            });
        }

        // 设置基础配置
        instance.config = {
            fields: fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination !== false,
            showToolbar: instance.options.showToolbar !== false,
            showConfig: instance.options.showConfig !== false
        };

        // 初始化所有过滤器容器
        this.initAllFilters(instance);

        console.log('initFromHTML: 基础配置初始化完成', {
            fieldsCount: fields.length,
            config: instance.config
        });

        // 注意：这里不渲染表格，等字段配置加载完成后再渲染
    },

    /**
     * 初始化过滤器
     */
    initFilters: function (instance, filterContainer) {
        if (!filterContainer) return;

        // 绑定过滤器事件
        const searchButtons = filterContainer.querySelectorAll('button[onclick*="search"]');
        searchButtons.forEach(button => {
            button.removeEventListener('click', this.applyFilters.bind(this, instance));
            button.addEventListener('click', this.applyFilters.bind(this, instance));
        });

        const resetButtons = filterContainer.querySelectorAll('button[onclick*="reset"]');
        resetButtons.forEach(button => {
            button.removeEventListener('click', this.resetFilters.bind(this, instance));
            button.addEventListener('click', this.resetFilters.bind(this, instance));
        });
    },

    /**
     * 初始化所有筛选器容器
     */
    initAllFilters: function (instance) {
        const container = instance.container[0] || instance.container;

        // 初始化主要的筛选器容器
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            this.initFilters(instance, filterContainer);
        }

        // 初始化筛选器工具栏
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            this.initFilters(instance, filterToolbar);
        }

        // 初始化筛选器表单
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            this.initFilters(instance, filterForm);
        }
    },

    /**
     * 应用过滤器
     */
    applyFilters: function (instance) {
        const container = instance.container[0] || instance.container;
        instance.filters = {};

        // 处理主要的筛选器容器
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            const filterInputs = filterContainer.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        // 处理筛选器工具栏
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            const filterInputs = filterToolbar.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        // 处理筛选器表单
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            const filterInputs = filterForm.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                const fieldName = input.getAttribute('data-field');
                const value = input.value;

                if (value !== '' && value !== null && value !== undefined) {
                    instance.filters[fieldName] = value;
                }
            });
        }

        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 重置过滤器
     */
    resetFilters: function (instance) {
        const container = instance.container[0] || instance.container;

        // 重置主要的筛选器容器
        const filterContainer = container.querySelector('.datatable-filter');
        if (filterContainer) {
            const filterInputs = filterContainer.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        // 重置筛选器工具栏
        const filterToolbar = container.querySelector('.datatable-filter-toolbar');
        if (filterToolbar) {
            const filterInputs = filterToolbar.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        // 重置筛选器表单
        const filterForm = container.querySelector('.datatable-filter-form');
        if (filterForm) {
            const filterInputs = filterForm.querySelectorAll('[data-field]');
            filterInputs.forEach(function (input) {
                if (input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 渲染表格
     */
    renderTable: function (instance) {
        const container = instance.container[0] || instance.container;
        const tbody = container.querySelector('tbody');

        // 只渲染数据行，不重新渲染表头
        this.renderBody(instance, tbody);

        // 渲染分页
        this.renderPagination(instance);
    },

    /**
     * 解析URL中的排序参数
     */
    parseUrlSortParams: function () {
        const urlParams = new URLSearchParams(window.location.search);
        const current = urlParams.get('current');
        const sortParams = {};

        // 解析sort参数，如sort.store_id=desc
        for (const [key, value] of urlParams.entries()) {
            if (key.startsWith('sort.')) {
                const fieldName = key.replace('sort.', '');
                sortParams[fieldName] = value;
            }
        }

        return {
            current: current,
            sorts: sortParams
        };
    },

    /**
     * 渲染表格头部
     */
    renderHeader: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (!thead) return;

        // 确保字段顺序正确
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let headerHtml = '<tr>';
        let hasSortableFields = false;

        // 添加复选框列（如果启用了批量操作）
        if (instance.options.enableBatchActions !== false) {
            headerHtml += `
                <th class="checkbox-column" style="width: 40px;">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="select-all-${tableId}">
                        <label class="form-check-label" for="select-all-${tableId}"></label>
                    </div>
                </th>`;
        }

        orderedFields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSort = isProtected ?
                (field.sortable === true || field.sortable === 'true') :
                (field.sortable !== false);
            const canEdit = isProtected ?
                (field.editable === true || field.editable === 'true') :
                (field.editable !== false);
            // 检查列是否可以拖动排序（默认允许，除非明确禁止）
            const canDragOrder = field.display_orderable !== false && 
                                 field.display_orderable !== 'false' && 
                                 field.display_orderable !== 0 && 
                                 field.display_orderable !== '0';

            if (canSort) {
                hasSortableFields = true;
            }

            const sortIcon = canSort ?
                '<i class="fas fa-sort sort-icon" data-field="' + field.name + '"></i>' : '';
            const editIcon = canEdit ?
                '<i class="fas fa-edit edit-icon" data-field="' + field.name + '"></i>' : '';
            // 拖动手柄图标（只有可拖动的列才显示）
            const dragHandle = canDragOrder ?
                '<i class="fas fa-grip-vertical column-drag-handle" title="' + __('拖动调整列顺序') + '"></i>' : '';

            headerHtml += `
                <th data-field="${field.name}"
                    class="${canSort ? 'sortable' : ''} ${canEdit ? 'editable' : ''} resizable ${canDragOrder ? 'column-draggable' : ''}"
                    style="min-width: ${field.minWidth || '100px'}; max-width: ${field.maxWidth || 'none'}; position: relative;"
                    ${canDragOrder ? 'draggable="true"' : ''}>
                    <div class="header-content">
                        ${dragHandle}
                        <span class="field-label">${field.label || field.name}</span>
                        ${sortIcon}
                        ${editIcon}
                    </div>
                    <div class="resize-handle" style="
                        position: absolute;
                        top: 0;
                        right: 0;
                        width: 5px;
                        height: 100%;
                        cursor: col-resize;
                        background: transparent;
                        z-index: 10;
                    "></div>
                </th>`;
        });
        headerHtml += '</tr>';

        thead.innerHTML = headerHtml;

        // 绑定排序事件
        if (hasSortableFields) {
            const sortIcons = thead.querySelectorAll('.sort-icon');
            sortIcons.forEach(icon => {
                icon.addEventListener('click', function () {
                    const fieldName = this.getAttribute('data-field');
                    DataTableManager.sortTable(tableId, fieldName);
                });
            });
        }

        // 重新初始化拖拽排序功能（字段配置弹窗）
        this.initDragSort(tableId);
        
        // 初始化列头拖动排序功能
        this.initColumnDragSort(tableId);
    },
    
    /**
     * 初始化表格列头拖动排序功能
     * @param {string} tableId 表格ID
     */
    initColumnDragSort: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (!thead) return;

        const headerCells = thead.querySelectorAll('th.column-draggable');
        const self = this;

        headerCells.forEach(th => {
            const fieldName = th.getAttribute('data-field');
            if (!fieldName) return;

            // 拖动开始
            th.addEventListener('dragstart', function (e) {
                // 检查是否点击的是拖动手柄或非resize-handle区域
                const target = e.target;
                if (target.classList.contains('resize-handle')) {
                    e.preventDefault();
                    return;
                }
                
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', fieldName);
                e.dataTransfer.setData('source', 'column-header');
                th.classList.add('column-dragging');
                
                // 添加拖动时的视觉效果
                setTimeout(() => {
                    th.style.opacity = '0.5';
                }, 0);
            });

            // 拖动结束
            th.addEventListener('dragend', function () {
                th.classList.remove('column-dragging');
                th.style.opacity = '';
                
                // 清除所有拖放指示器
                thead.querySelectorAll('.column-drop-indicator').forEach(el => el.remove());
                thead.querySelectorAll('.column-drag-over').forEach(el => el.classList.remove('column-drag-over'));
            });

            // 拖动经过
            th.addEventListener('dragover', function (e) {
                const source = e.dataTransfer.types.includes('source') ? 'column-header' : '';
                // 只处理来自列头的拖动
                if (e.dataTransfer.getData('source') === 'column-header' || e.dataTransfer.types.includes('text/plain')) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    th.classList.add('column-drag-over');
                    
                    // 显示放置指示器
                    self.showColumnDropIndicator(th, e);
                }
            });

            // 拖动离开
            th.addEventListener('dragleave', function (e) {
                // 确保是真正离开而不是进入子元素
                if (!th.contains(e.relatedTarget)) {
                    th.classList.remove('column-drag-over');
                    self.removeColumnDropIndicator(th);
                }
            });

            // 放置
            th.addEventListener('drop', function (e) {
                e.preventDefault();
                th.classList.remove('column-drag-over');
                self.removeColumnDropIndicator(th);
                
                const draggedFieldName = e.dataTransfer.getData('text/plain');
                
                if (draggedFieldName && draggedFieldName !== fieldName) {
                    // 执行列移动
                    self.moveColumnByDrag(tableId, draggedFieldName, fieldName);
                }
            });
        });
    },
    
    /**
     * 显示列放置指示器
     */
    showColumnDropIndicator: function (th, e) {
        // 移除之前的指示器
        this.removeColumnDropIndicator(th);
        
        const rect = th.getBoundingClientRect();
        const midPoint = rect.left + rect.width / 2;
        const isLeftSide = e.clientX < midPoint;
        
        // 创建指示器
        const indicator = document.createElement('div');
        indicator.className = 'column-drop-indicator';
        indicator.style.cssText = `
            position: absolute;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--datatable-primary, #2563eb);
            z-index: 1000;
            pointer-events: none;
            ${isLeftSide ? 'left: 0;' : 'right: 0;'}
        `;
        
        th.style.position = 'relative';
        th.appendChild(indicator);
        th.setAttribute('data-drop-position', isLeftSide ? 'before' : 'after');
    },
    
    /**
     * 移除列放置指示器
     */
    removeColumnDropIndicator: function (th) {
        const indicator = th.querySelector('.column-drop-indicator');
        if (indicator) {
            indicator.remove();
        }
        th.removeAttribute('data-drop-position');
    },
    
    /**
     * 通过拖动移动列
     * @param {string} tableId 表格ID
     * @param {string} draggedFieldName 被拖动的字段名
     * @param {string} targetFieldName 目标字段名
     */
    moveColumnByDrag: function (tableId, draggedFieldName, targetFieldName) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const fieldList = instance.displayFields;
        const draggedIndex = fieldList.findIndex(f => f.name === draggedFieldName);
        const targetIndex = fieldList.findIndex(f => f.name === targetFieldName);

        if (draggedIndex === -1 || targetIndex === -1) {
            console.warn('moveColumnByDrag: 字段未找到', { draggedFieldName, targetFieldName });
            return;
        }

        const draggedField = fieldList[draggedIndex];
        const targetField = fieldList[targetIndex];

        // 检查字段是否允许移动
        const draggedCanMove = draggedField.display_orderable !== false && 
                               draggedField.display_orderable !== 'false' && 
                               draggedField.display_orderable !== 0 && 
                               draggedField.display_orderable !== '0';
        const targetCanMove = targetField.display_orderable !== false && 
                              targetField.display_orderable !== 'false' && 
                              targetField.display_orderable !== 0 && 
                              targetField.display_orderable !== '0';

        if (!draggedCanMove) {
            console.warn('moveColumnByDrag: 被拖动的字段不允许移动', draggedFieldName);
            this.showWarning(tableId, __('该列不允许移动'));
            return;
        }

        if (!targetCanMove) {
            console.warn('moveColumnByDrag: 目标位置字段不允许移动', targetFieldName);
            this.showWarning(tableId, __('无法放置到该位置'));
            return;
        }

        // 执行移动
        const movedField = fieldList.splice(draggedIndex, 1)[0];
        // 计算新的目标索引（因为删除了一个元素）
        const newTargetIndex = draggedIndex < targetIndex ? targetIndex - 1 : targetIndex;
        fieldList.splice(newTargetIndex, 0, movedField);

        // 保存用户配置到缓存
        this.saveFieldConfigToCache(tableId);

        // 重新渲染表头和数据
        this.renderHeader(tableId, fieldList);
        this.renderTable(instance);

        // 显示成功提示
        this.showSuccess(tableId, __('列顺序已更新'));

        console.log('moveColumnByDrag: 列拖动移动完成', {
            dragged: draggedFieldName,
            target: targetFieldName,
            newOrder: fieldList.map(f => f.name)
        });
    },

    /**
     * 渲染数据行
     */
    renderBody: function (instance, tbody) {
        if (!instance.data || instance.data.length === 0) {
            // 计算总列数（包括复选框列和操作列）
            let totalColumns = instance.config.fields.length;
            if (instance.options.enableBatchActions !== false) totalColumns += 1; // 复选框列
            if (instance.options.editable) totalColumns += 1; // 操作列
            tbody.innerHTML = `<tr><td colspan="${totalColumns}" class="text-center">暂无数据</td></tr>`;
            return;
        }

        let bodyHtml = '';

        instance.data.forEach((row, index) => {
            bodyHtml += '<tr data-row-index="' + index + '">';

            // 添加复选框列（如果启用了批量操作）
            if (instance.options.enableBatchActions !== false) {
                bodyHtml += `
                    <td class="checkbox-column">
                        <div class="form-check">
                            <input class="form-check-input row-checkbox" type="checkbox"
                                   value="${row.id || index}" data-row-index="${index}">
                        </div>
                    </td>`;
            }

            // 渲染数据列
            instance.config.fields.forEach(field => {
                if (field.visible) {
                    const value = row[field.name] || '';

                    // 对于受保护的字段，只有在明确指定editable=true时才启用编辑
                    const isTemplateField = field.template_defined || field.field_defined || field.from_field;
                    const canEdit = isTemplateField ?
                        (field.editable === true || field.editable === 'true') :
                        (field.editable !== false);

                    const cellClass = canEdit ? 'editable-cell' : '';

                    bodyHtml += `
                        <td data-field="${field.name}" class="${cellClass}">
                            <div class="cell-content">${this.formatCellValue(value, field)}</div>
                            ${canEdit ? '<div class="edit-overlay"><i class="fas fa-edit"></i></div>' : ''}
                        </td>
                    `;
                }
            });

            // 渲染操作列
            if (instance.options.editable) {
                bodyHtml += `
                    <td class="actions-cell">
                        <button class="btn btn-sm btn-primary edit-row-btn" data-row-index="${index}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger delete-row-btn" data-row-index="${index}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
            }

            bodyHtml += '</tr>';
        });

        tbody.innerHTML = bodyHtml;

        // 绑定行事件
        this.bindRowEvents(instance, tbody);
    },

    /**
     * 格式化单元格?
     */
    formatCellValue: function (value, field) {
        if (field.formatter && typeof window[field.formatter] === 'function') {
            return window[field.formatter](value, field);
        }

        switch (field.type) {
            case 'date':
                return value ? new Date(value).toLocaleDateString() : '';
            case 'datetime':
                return value ? new Date(value).toLocaleString() : '';
            case 'number':
                return value ? Number(value).toLocaleString() : '';
            case 'boolean':
                return value ? '<span class="badge bg-success"></span>' : '<span class="badge bg-secondary"></span>';
            default:
                return value;
        }
    },

    /**
     * 渲染分页
     */
    renderPagination: function (instance) {
        const container = instance.container[0] || instance.container;
        const paginationContainer = container.querySelector('.datatable-pagination');

        if (!instance.options.showPagination || !instance.pagination) {
            if (paginationContainer) {
                paginationContainer.style.display = 'none';
            }
            return;
        }

        const pagination = instance.pagination;
        let paginationHtml = `
            <div class="pagination-info">
                显示第 ${(pagination.page - 1) * pagination.pageSize + 1} 到
                ${Math.min(pagination.page * pagination.pageSize, pagination.total)} 条，
                共 ${pagination.total} 条记录
            </div>
            <ul class="pagination">
        `;

        // 上一页
        paginationHtml += `
            <li class="page-item ${pagination.hasPrevPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page - 1}">上一页</a>
            </li>
        `;

        // 页码
        const startPage = Math.max(1, pagination.page - 2);
        const endPage = Math.min(pagination.lastPage, pagination.page + 2);

        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === pagination.page ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        }

        // 下一页
        paginationHtml += `
            <li class="page-item ${pagination.hasNextPage ? '' : 'disabled'}">
                <a class="page-link" href="#" data-page="${pagination.page + 1}">下一页</a>
            </li>
        `;

        paginationHtml += '</ul>';

        if (paginationContainer) {
            paginationContainer.innerHTML = paginationHtml;
            paginationContainer.style.display = 'block';
        }

        // 绑定分页事件
        this.bindPaginationEvents(instance, paginationContainer);
    },

    /**
     * 加载数据
     */
    loadData: function (instance) {
        const $loading = instance.container.find('.datatable-loading');
        const $content = instance.container.find('.datatable-content');

        console.log('开始加载数?', {
            model: instance.options.model,
            scope: instance.options.scope,
            page: instance.currentPage,
            pageSize: instance.pageSize,
            filters: instance.filters
        });

        $loading.show();
        $content.hide();

        fetch(window.api('datatable/rest/v1/data-table/data'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: instance.options.model,
                scope: instance.options.scope,
                page: instance.currentPage,
                pageSize: instance.pageSize,
                search: instance.search,
                filters: instance.filters,
                sorts: instance.sorts
            })
        })
            .then(response => response.json())
            .then(response => {
                console.log('API响应:', response);
                $loading.hide();
                $content.show();

                // 兼容 code 为字符串或数字
                if (response.code == 200 || response.code === '200' || response.success) {
                    instance.data = response.data.data || [];
                    instance.pagination = response.data.pagination;
                    // 设置 totalCount 用于导出功能
                    instance.totalCount = response.data.total || response.data.pagination?.total || instance.data.length || 0;
                    this.renderTable(instance);
                } else {
                    console.error('API错误:', response.msg);
                    this.showError(response.msg || response.message || __('加载数据失败'));
                }
            })
            .catch(error => {
                console.error('AJAX错误:', error);
                $loading.hide();
                $content.show();
                this.showError(__('加载数据失败: %{1}', error));
            });
    },

    /**
     * 搜索
     */
    search: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.search = instance.container.find('#search-input-' + scope).val();
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 清除搜索
     */
    clearSearch: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.container.find('#search-input-' + scope).val('');
        instance.search = '';
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 应用过滤?
     */
    applyFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        const $form = instance.container.find('#filter-form-' + scope);
        instance.filters = {};

        $form.find('[data-field]').each(function () {
            const field = $(this).data('field');
            const value = $(this).val();
            if (value !== '' && value !== null) {
                instance.filters[field] = value;
            }
        });

        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 清除过滤?
     */
    clearFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        instance.container.find('#filter-form-' + scope)[0].reset();
        instance.filters = {};
        instance.currentPage = 1;
        this.loadData(instance);
    },

    /**
     * 保存过滤?
     */
    saveFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        const filterName = prompt(__('请输入过滤器名称'));
        if (!filterName) return;

        const $form = instance.container.find('#filter-form-' + scope);
        const filterData = {};

        $form.find('[data-field]').each(function () {
            const field = $(this).data('field');
            const value = $(this).val();
            filterData[field] = value;
        });

        // 保存到本地存?
        const savedFilters = JSON.parse(localStorage.getItem('datatable_filters_' + scope) || '{}');
        savedFilters[filterName] = filterData;
        localStorage.setItem('datatable_filters_' + scope, JSON.stringify(savedFilters));

        this.showSuccess(scope, __('过滤器保存成功'));
    },

    /**
     * 保存表格配置
     */
    saveTableConfig: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (!instance) return;

        // 收集配置数据
        const config = {
            fields: instance.config.fields,
            pageSize: instance.pageSize,
            showPagination: instance.options.showPagination,
            showToolbar: instance.options.showToolbar,
            showConfig: instance.options.showConfig
        };

        fetch(window.api('datatable/rest/v1/data-table/save-config'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                scope: scope,
                config: config
            })
        })
            .then(response => response.json())
            .then(response => {
                // 兼容 code 为字符串或数字
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(scope, __('配置保存成功'));
                    const modal = document.getElementById('table-config-modal-' + scope);
                    if (modal && typeof bootstrap !== 'undefined') {
                        const bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) {
                            bsModal.hide();
                        }
                    }
                } else {
                    this.showError(scope, response.msg || response.message || __('保存失败'));
                }
            })
            .catch(() => {
                this.showError(scope, __('保存配置失败'));
            });
    },

    /**
     * 编辑?
     */
    editRow: function (instance, rowIndex) {
        if (instance.isEditing) {
            this.showWarning(instance.container.attr('id'), __('请先保存当前编辑的行'));
            return;
        }

        const row = instance.data[rowIndex];
        if (!row) return;

        instance.isEditing = true;
        instance.editingRow = rowIndex;
        instance.editingData = { ...row };

        // 显示编辑模态框
        this.showEditModal(instance, row);
    },

    /**
     * 显示编辑模态框
     */
    showEditModal: function (instance, row) {
        const modalId = 'edit-modal-' + instance.container.attr('id');
        const editTitle = __('编辑数据');
        let modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${editTitle}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="edit-form-${modalId}">
        `;

        instance.config.fields.forEach(field => {
            if (field.editable) {
                const value = row[field.name] || '';
                modalHtml += this.renderEditField(field, value);
            }
        });
        const saveBtnText = __('保存');
        const cancelBtnText = __('取消');
        modalHtml += `
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelBtnText}</button>
                            <button type="button" class="btn btn-primary" onclick="DataTableManager.saveRow('${instance.container.attr('id')}')">${saveBtnText}</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // 移除已存在的模态框
        $('#' + modalId).remove();

        // 添加新模态框
        $('body').append(modalHtml);

        // 显示模态框
        $('#' + modalId).modal('show');

        // 绑定模态框事件
        this.bindEditModalEvents(instance, modalId);
    },

    /**
     * 渲染编辑字段
     */
    renderEditField: function (field, value) {
        const fieldId = 'edit-' + field.name;

        const pleaseSelect = __('请选择');

        switch (field.type) {
            case 'textarea':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <textarea class="form-control" id="${fieldId}" name="${field.name}" rows="3">${value}</textarea>
                    </div>
                `;
            case 'select':
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <select class="form-control" id="${fieldId}" name="${field.name}">
                            <option value="">${pleaseSelect}</option>
                            ${field.options ? field.options.map(opt =>
                    `<option value="${opt.value}" ${value == opt.value ? 'selected' : ''}>${opt.label}</option>`
                ).join('') : ''}
                        </select>
                    </div>
                `;
            case 'checkbox':
                return `
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="${fieldId}" name="${field.name}" value="1" ${value ? 'checked' : ''}>
                            <label class="form-check-label" for="${fieldId}">${field.label}</label>
                        </div>
                    </div>
                `;
            default:
                return `
                    <div class="mb-3">
                        <label for="${fieldId}" class="form-label">${field.label}</label>
                        <input type="${field.type}" class="form-control" id="${fieldId}" name="${field.name}" value="${value}">
                    </div>
                `;
        }
    },

    /**
     * 保存行数?
     */
    saveRow: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance || !instance.isEditing) return;

        const modalId = 'edit-modal-' + tableId;
        const $form = $('#' + modalId + ' form');
        const formData = {};

        $form.find('[name]').each(function () {
            const name = $(this).attr('name');
            let value = $(this).val();

            if ($(this).attr('type') === 'checkbox') {
                value = $(this).prop('checked') ? 1 : 0;
            }

            formData[name] = value;
        });

        // 添加ID
        formData.id = instance.data[instance.editingRow].id;

        fetch(window.api('datatable/rest/v1/data-table/save-data'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: instance.options.model,
                data: formData
            })
        })
            .then(response => response.json())
            .then(response => {
                // 兼容 code 为字符串或数字
                if (response.code == 200 || response.code === '200' || response.success) {
                    this.showSuccess(tableId, __('保存成功'));
                    $('#' + modalId).modal('hide');
                    instance.isEditing = false;
                    instance.editingRow = null;
                    instance.editingData = {};
                    this.loadData(instance);
                } else {
                    this.showError(tableId, response.msg || response.message || __('保存失败'));
                }
            })
            .catch(() => {
                this.showError(tableId, __('保存失败'));
            });
    },

    /**
     * 删除行（增强版）
     */
    deleteRow: function (instance, rowIndex, options = {}) {
        const row = instance.data[rowIndex];
        if (!row || !row.id) return;

        // 合并默认选项
        const deleteOptions = {
            confirmMessage: __('确定要删除这条记录吗？'),
            softDelete: false,
            showDetails: true,
            ...options
        };

        // 显示删除确认对话框
        this.showDeleteConfirmDialog(instance, [row], deleteOptions, () => {
            this.performDelete(instance, [row.id], deleteOptions);
        });
    },

    /**
     * 批量删除
     */
    batchDelete: function (instance, selectedIds, options = {}) {
        if (!selectedIds || selectedIds.length === 0) {
            this.showError(instance.container.attr('id'), __('请选择要删除的记录'));
            return;
        }

        // 获取选中的行数据
        const selectedRows = instance.data.filter(row => selectedIds.includes(row.id));

        // 合并默认选项
        const deleteOptions = {
            confirmMessage: __(`确定要删除选中的 ${selectedIds.length} 条记录吗？`),
            softDelete: false,
            showDetails: true,
            ...options
        };

        // 显示删除确认对话框
        this.showDeleteConfirmDialog(instance, selectedRows, deleteOptions, () => {
            this.performDelete(instance, selectedIds, deleteOptions);
        });
    },

    /**
     * 显示删除确认对话框
     */
    showDeleteConfirmDialog: function (instance, rows, options, onConfirm) {
        const tableId = instance.container.attr('id');

        // 创建确认对话框HTML
        const dialogHtml = `
            <div class="modal fade" id="delete-confirm-modal-${tableId}" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                ${__('删除确认')}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ${options.confirmMessage}
                            </div>

                            ${options.showDetails ? this.generateDeleteDetailsHtml(rows) : ''}

                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="soft-delete-${tableId}">
                                <label class="form-check-label" for="soft-delete-${tableId}">
                                    ${__('软删除（可恢复）')}
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                ${__('取消')}
                            </button>
                            <button type="button" class="btn btn-danger" id="confirm-delete-${tableId}">
                                <i class="fas fa-trash me-2"></i>
                                ${__('确认删除')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // 移除已存在的对话框
        $(`#delete-confirm-modal-${tableId}`).remove();

        // 添加到页面
        $('body').append(dialogHtml);

        // 显示对话框
        const modal = new bootstrap.Modal(document.getElementById(`delete-confirm-modal-${tableId}`));
        modal.show();

        // 绑定确认按钮事件
        $(`#confirm-delete-${tableId}`).on('click', () => {
            const softDelete = $(`#soft-delete-${tableId}`).is(':checked');
            options.softDelete = softDelete;
            modal.hide();
            onConfirm();
        });

        // 清理事件
        $(`#delete-confirm-modal-${tableId}`).on('hidden.bs.modal', function () {
            $(this).remove();
        });
    },

    /**
     * 生成删除详情HTML
     */
    generateDeleteDetailsHtml: function (rows) {
        if (rows.length === 1) {
            const row = rows[0];
            return `
                <div class="delete-details">
                    <h6>${__('即将删除的记录：')}</h6>
                    <div class="card">
                        <div class="card-body p-2">
                            ${this.generateRowDetailsHtml(row)}
                        </div>
                    </div>
                </div>
            `;
        } else {
            return `
                <div class="delete-details">
                    <h6>${__('即将删除的记录：')}</h6>
                    <div class="alert alert-info">
                        ${__('共选中')} <strong>${rows.length}</strong> ${__('条记录')}
                    </div>
                    <div class="row-list" style="max-height: 200px; overflow-y: auto;">
                        ${rows.map(row => `
                            <div class="card mb-2">
                                <div class="card-body p-2">
                                    ${this.generateRowDetailsHtml(row)}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }
    },

    /**
     * 生成行详情HTML
     */
    generateRowDetailsHtml: function (row) {
        const details = [];

        // 显示主要字段
        const mainFields = ['id', 'name', 'title', 'username', 'email'];
        mainFields.forEach(field => {
            if (row[field] !== undefined) {
                details.push(`<strong>${field}:</strong> ${row[field]}`);
            }
        });

        // 如果没有主要字段，显示前几个字段
        if (details.length === 0) {
            const keys = Object.keys(row).slice(0, 3);
            keys.forEach(key => {
                if (row[key] !== undefined) {
                    details.push(`<strong>${key}:</strong> ${row[key]}`);
                }
            });
        }

        return details.join('<br>');
    },

    /**
     * 执行删除操作
     */
    performDelete: function (instance, ids, options) {
        const tableId = instance.container.attr('id');

        // 显示加载状态
        this.showLoading(tableId, __('正在删除...'));

        fetch(window.api('datatable/rest/v1/data-table/delete-data'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: instance.options.model,
                ids: Array.isArray(ids) ? ids : [ids],
                soft_delete: options.softDelete || false
            })
        })
            .then(response => response.json())
            .then(response => {
                this.hideLoading(tableId);

                // 兼容 code 为字符串或数字
                if (response.code == 200 || response.code === '200' || response.success) {
                    const message = options.softDelete
                        ? __('记录已移至回收站')
                        : __('删除成功');
                    this.showSuccess(tableId, message);

                    // 重新加载数据
                    this.loadData(instance);

                    // 清除选中状态
                    this.clearSelection(instance);
                } else {
                    this.showError(tableId, response.msg || response.message || __('删除失败'));
                }
            })
            .catch(error => {
                this.hideLoading(tableId);
                console.error('Delete error:', error);
                this.showError(tableId, __('删除失败'));
            });
    },

    /**
     * 清除选中状态
     */
    clearSelection: function (instance) {
        const tableId = instance.container.attr('id');

        // 清除复选框选中状态
        $(`#${tableId} input[type="checkbox"]`).prop('checked', false);

        // 清除选中行样式
        $(`#${tableId} tbody tr`).removeClass('selected');

        // 更新批量操作按钮状态
        this.updateBatchActionButtons(instance);
    },

    /**
     * 绑定事件
     */
    bindEvents: function (instance) {
        // 获取容器（支持 DOM 元素和 jQuery 对象）
        const container = instance.container.jquery ? instance.container[0] : instance.container;
        
        // 搜索事件
        const searchInput = container.querySelector('#search-input-' + instance.options.scope);
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.which === 13 || e.keyCode === 13) {
                    this.search(instance.options.scope);
                }
            });
        }

        // 过滤器事件
        const filterForm = container.querySelector('#filter-form-' + instance.options.scope);
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilter(instance.options.scope);
            });
        }

        // 窗口关闭前提示
        window.addEventListener('beforeunload', (e) => {
            if (instance.isEditing) {
                e.preventDefault();
                e.returnValue = __('您有未保存的编辑内容，确定要离开吗？');
            }
        });
    },

    /**
     * 绑定表头事件
     */
    bindHeaderEvents: function (instance, $thead) {
        // 排序链接点击事件
        $thead.on('click', '.sort-link', function (e) {
            e.preventDefault();
            const field = $(this).data('field');
            const currentSort = instance.sorts[field];
            let newSortDirection;

            // 确定新的排序方向
            if (currentSort === 'asc') {
                newSortDirection = 'desc';
            } else if (currentSort === 'desc') {
                newSortDirection = null; // 取消排序
            } else {
                newSortDirection = 'asc';
            }

            // 更新实例中的排序状?
            if (newSortDirection) {
                instance.sorts[field] = newSortDirection;
            } else {
                delete instance.sorts[field];
            }

            // 更新URL参数
            DataTableManager.updateUrlSortParams(field, newSortDirection);

            // 重新加载数据
            DataTableManager.loadData(instance);
        });

        // 兼容旧的排序事件（点击整个th?
        $thead.on('click', '[data-sortable="true"]', function (e) {
            // 如果点击的是排序链接，不处理（避免重复）
            if ($(e.target).closest('.sort-link').length) {
                return;
            }

            const field = $(this).data('field');
            const currentSort = instance.sorts[field];

            if (currentSort === 'asc') {
                instance.sorts[field] = 'desc';
            } else if (currentSort === 'desc') {
                delete instance.sorts[field];
            } else {
                instance.sorts[field] = 'asc';
            }

            DataTableManager.loadData(instance);
        });

        // 列宽调整事件
        $thead.on('mousedown', '.resize-handle', function (e) {
            e.preventDefault();
            const $th = $(this).parent();
            const startX = e.clientX;
            const startWidth = $th.width();

            const onMouseMove = function (e) {
                const newWidth = startWidth + (e.clientX - startX);
                $th.css('width', Math.max(50, newWidth) + 'px');
            };

            const onMouseUp = function () {
                $(document).off('mousemove', onMouseMove).off('mouseup', onMouseUp);

                // 保存列宽配置
                const field = $th.data('field');
                const width = $th.width() + 'px';

                instance.config.fields.forEach(f => {
                    if (f.name === field) {
                        f.width = width;
                    }
                });
            };

            $(document).on('mousemove', onMouseMove).on('mouseup', onMouseUp);
        });
    },

    /**
     * 更新URL中的排序参数
     */
    updateUrlSortParams: function (field, sortDirection) {
        const url = new URL(window.location);
        const urlParams = url.searchParams;

        if (sortDirection) {
            // 设置排序参数
            urlParams.set('current', field);
            urlParams.set(`sort.${field}`, sortDirection);
        } else {
            // 取消排序
            urlParams.delete('current');
            urlParams.delete(`sort.${field}`);
        }

        // 更新URL（不刷新页面）
        window.history.replaceState({}, '', url.toString());
        console.log('updateUrlSortParams: URL已更新', url.toString());
    },

    /**
     * 绑定行事件
     */
    bindRowEvents: function (instance, $tbody) {
        // 编辑按钮事件
        $tbody.on('click', '.edit-row-btn', function () {
            const rowIndex = $(this).data('row-index');
            DataTableManager.editRow(instance, rowIndex);
        });

        // 删除按钮事件
        $tbody.on('click', '.delete-row-btn', function () {
            const rowIndex = $(this).data('row-index');
            DataTableManager.deleteRow(instance, rowIndex);
        });

        // 复选框事件
        $tbody.on('change', '.row-checkbox', function () {
            const $row = $(this).closest('tr');
            $row.toggleClass('selected', this.checked);
            DataTableManager.updateBatchActionButtons(instance);
        });

        // 单元格编辑事件
        $tbody.on('click', '.editable-cell', function () {
            const $cell = $(this);
            const field = $cell.data('field');
            const value = $cell.find('.cell-content').text();

            // 创建内联编辑器
            const $input = $('<input type="text" class="form-control form-control-sm">').val(value);
            $cell.find('.cell-content').hide();
            $cell.append($input);
            $input.focus();

            $input.on('blur keypress', function (e) {
                if (e.type === 'blur' || e.which === 13) {
                    const newValue = $(this).val();
                    $cell.find('.cell-content').text(newValue).show();
                    $(this).remove();

                    // 保存数据
                    const rowIndex = $cell.closest('tr').data('row-index');
                    const row = instance.data[rowIndex];
                    if (row) {
                        row[field] = newValue;
                        DataTableManager.saveRowData(instance, row);
                    }
                }
            });
        });
    },

    /**
     * 绑定分页事件
     */
    bindPaginationEvents: function (instance, $pagination) {
        $pagination.on('click', '.page-link', function (e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && page > 0 && page <= instance.pagination.lastPage) {
                instance.currentPage = page;
                DataTableManager.loadData(instance);
            }
        });
    },

    /**
     * 绑定编辑模态框事件
     */
    bindEditModalEvents: function (instance, modalId) {
        $('#' + modalId).on('hidden.bs.modal', function () {
            if (instance.isEditing) {
                instance.isEditing = false;
                instance.editingRow = null;
                instance.editingData = {};
            }
        });
    },

    /**
     * 根据任意标识符获取实例（通用查找方法）
     * 支持: tableId, scope, datatable-scope-xxx 格式
     */
    getInstance: function (identifier) {
        // 1. 直接查找
        if (this.instances[identifier]) {
            return this.instances[identifier];
        }
        
        // 2. 尝试 scope- 前缀
        const scopeKey = 'scope-' + identifier;
        if (this.instances[scopeKey]) {
            return this.instances[scopeKey];
        }
        
        // 3. 尝试从 datatable-scope-xxx 格式中提取 scope
        if (identifier.startsWith('datatable-scope-')) {
            const extractedScope = identifier.replace('datatable-scope-', '');
            const extractedScopeKey = 'scope-' + extractedScope;
            if (this.instances[extractedScopeKey]) {
                return this.instances[extractedScopeKey];
            }
        }
        
        // 4. 遍历所有实例查找匹配的 scope 或 tableId
        for (const instanceKey in this.instances) {
            const instance = this.instances[instanceKey];
            if (instance.scope === identifier || 
                instance.tableId === identifier ||
                (instance.options && instance.options.scope === identifier)) {
                return instance;
            }
        }
        
        return null;
    },

    /**
     * 根据scope获取实例
     */
    getInstanceByScope: function (scope) {
        return this.getInstance(scope);
    },

    /**
     * 保存行数?
     */
    saveRowData: function (instance, row) {
        fetch(window.api('datatable/rest/v1/data-table/save-data'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: instance.options.model,
                data: row
            })
        })
            .then(response => response.json())
            .then(response => {
                if (response.code !== 200) {
                    this.showError(instance.container.attr('id'), response.msg);
                }
            })
            .catch(() => {
                this.showError(instance.container.attr('id'), __('保存失败'));
            });
    },

    /**
     * 显示成功信息
     */
    /**
     * 显示成功信息（增强版）
     */
    showSuccess: function (tableId, message, options = {}) {
        const {
            autoHide = true,
            hideDelay = 3000
        } = options;

        const container = document.getElementById('w-datatable-' + tableId) || document.getElementById(tableId);
        if (!container) {
            console.error('DataTable container not found:', tableId);
            return;
        }

        // 移除已存在的成功消息
        const existingSuccess = container.querySelector('.datatable-success-message');
        if (existingSuccess) {
            existingSuccess.remove();
        }

        // 创建成功消息元素
        const successHtml = `
            <div class="datatable-success-message">
                <i class="fas fa-check-circle"></i>
                <span>${message}</span>
            </div>
        `;

        // 在表格容器顶部显示成功消息
        const toolbar = container.querySelector('.w-datatable-toolbar');
        if (toolbar) {
            toolbar.insertAdjacentHTML('afterend', successHtml);
        } else {
            container.insertAdjacentHTML('afterbegin', successHtml);
        }

        // 自动隐藏
        if (autoHide) {
            setTimeout(() => {
                const successMsg = container.querySelector('.datatable-success-message');
                if (successMsg) {
                    successMsg.style.opacity = '0';
                    successMsg.style.transition = 'opacity 0.3s';
                    setTimeout(() => successMsg.remove(), 300);
                }
            }, hideDelay);
        }
    },

    /**
     * 显示加载状态
     */
    showLoading: function (tableId, message = __('加载中...')) {
        const container = document.getElementById(tableId);
        if (!container) return;

        // 移除已存在的加载提示
        const existingLoading = container.querySelector('.loading-overlay');
        if (existingLoading) {
            existingLoading.remove();
        }

        // 创建加载覆盖层
        const loadingHtml = `
            <div class="loading-overlay" style="
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
            ">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">${message}</div>
                </div>
            </div>
        `;

        // 确保容器有相对定位
        if (getComputedStyle(container).position === 'static') {
            container.style.position = 'relative';
        }

        container.insertAdjacentHTML('beforeend', loadingHtml);
    },

    /**
     * 隐藏加载状态
     */
    hideLoading: function (tableId) {
        const container = document.getElementById(tableId);
        if (!container) return;

        const loadingOverlay = container.querySelector('.loading-overlay');
        if (loadingOverlay) {
            loadingOverlay.remove();
        }
    },

    /**
     * 显示警告信息
     */
    showWarning: function (tableId, message) {
        const warningHtml = `
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>警告！</strong>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        // 在表格容器顶部显示警告消息
        const container = document.getElementById(tableId);
        if (container) {
            const existingAlert = container.querySelector('.alert-warning');
            if (existingAlert) {
                existingAlert.remove();
            }
            container.insertAdjacentHTML('afterbegin', warningHtml);

            // 3秒后自动隐藏
            setTimeout(() => {
                const alert = container.querySelector('.alert-warning');
                if (alert) {
                    alert.remove();
                }
            }, 3000);
        }
    },

    /**
     * 显示错误信息（增强版）
     */
    showError: function (tableId, message, options = {}) {
        const {
            autoHide = true,
            hideDelay = 5000,
            showRetry = false,
            retryCallback = null
        } = options;

        const container = document.getElementById('w-datatable-' + tableId) || document.getElementById(tableId);
        if (!container) {
            console.error('DataTable container not found:', tableId);
            return;
        }

        // 移除已存在的错误消息
        const existingError = container.querySelector('.datatable-error-message');
        if (existingError) {
            existingError.remove();
        }

        // 创建错误消息元素
        const errorHtml = `
            <div class="datatable-error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span>${message}</span>
                ${showRetry && retryCallback ? `
                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="${retryCallback}">
                        <i class="fas fa-redo"></i> ${__('重试')}
                    </button>
                ` : ''}
            </div>
        `;

        // 在表格容器顶部显示错误消息
        const toolbar = container.querySelector('.w-datatable-toolbar');
        if (toolbar) {
            toolbar.insertAdjacentHTML('afterend', errorHtml);
        } else {
            container.insertAdjacentHTML('afterbegin', errorHtml);
        }

        // 自动隐藏
        if (autoHide) {
            setTimeout(() => {
                const errorMsg = container.querySelector('.datatable-error-message');
                if (errorMsg) {
                    errorMsg.style.opacity = '0';
                    errorMsg.style.transition = 'opacity 0.3s';
                    setTimeout(() => errorMsg.remove(), 300);
                }
            }, hideDelay);
        }
    },

    /**
     * 初始化表格主体
     */
    initBody: function (scope, options) {
        console.log('初始化表格主?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-body', ''));
        if (instance) {
            instance.bodyConfig = options;
            // 可以在这里添加表格主体的特定初始化逻辑
        }
    },

    /**
     * 初始化表格底?
     */
    initFooter: function (scope, options) {
        console.log('初始化表格底?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.footerConfig = options;
            // 可以在这里添加表格底部的特定初始化逻辑
        }
    },

    /**
     * 初始化表?
     */
    initHeader: function (scope, options) {
        console.log('初始化表?', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-header', ''));
        if (instance) {
            instance.headerConfig = options;
            // 可以在这里添加表头的特定初始化逻辑
        }
    },

    /**
     * 初始化过滤器
     */
    initFilter: function (scope, options) {
        console.log('初始化过滤器:', scope, options);
        const instance = this.getInstanceByScope(scope.replace('-filter', ''));
        if (instance) {
            instance.filterConfig = options;
            // 可以在这里添加过滤器的特定初始化逻辑
        }
    },

    /**
     * 字段配置弹窗tab切换（自定义w-前缀?
     */
    bindFieldConfigTabs: function (tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (!modal) return;
        var tabLinks = modal.querySelectorAll('.w-nav-link');
        tabLinks.forEach(function (link) {
            link.onclick = function () {
                // 取消所有tab激?
                tabLinks.forEach(function (l) { l.classList.remove('active'); });
                link.classList.add('active');
                // 切换内容?
                var target = link.getAttribute('data-w-target');
                var tabPanes = modal.querySelectorAll('.w-tab-pane');
                tabPanes.forEach(function (pane) {
                    pane.classList.remove('w-show', 'active');
                });
                var showPane = modal.querySelector(target);
                if (showPane) {
                    showPane.classList.add('w-show', 'active');
                }
            };
        });
    },

    // 修改openFieldConfig，弹窗打开时绑定tab切换
    openFieldConfig: function (tableId) {
        document.querySelectorAll('.w-modal').forEach(function (modal) {
            modal.style.display = 'none';
        });
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'flex';

            // 检查是否已经有缓存的字段数据
            const instance = DataTableManager.getInstance(tableId);
            if (instance && instance.allFields && instance.allFields.length > 0) {
                console.log('openFieldConfig: 使用缓存的字段数据', {
                    allFields: instance.allFields.length,
                    displayFields: instance.displayFields.length,
                    filterFields: instance.filterFields.length
                });

                // 直接渲染缓存的字段数据，不触发表格重新构建
                DataTableManager.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            } else {
                // 只在字段设置中没有数据时才加载
                console.log('openFieldConfig: 字段设置中没有数据，开始加载');
                DataTableManager.loadModelFieldsForConfig(tableId);
            }

            DataTableManager.bindFieldConfigTabs(tableId);
            
            // 确保拖拽功能在DOM渲染完成后初始化
            setTimeout(function () {
                DataTableManager.initDragSort(tableId);
                var firstInput = modal.querySelector('input,select,textarea,button');
                if (firstInput) firstInput.focus();
            }, 200);
        }
    },

    /**
     * 关闭字段配置自定义弹窗（w-modal）
     */
    closeFieldConfig: function (tableId) {
        var modal = document.getElementById('w-field-config-modal-' + tableId);
        if (modal) {
            modal.style.display = 'none';
            // 关闭时重置加载标记
            delete modal.dataset.wFieldsLoaded;
        }
    },

    /**
     * 专门为字段配置加载模型字段（不会触发表格重新构建）
     */
    loadModelFieldsForConfig: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl未设置，无法加载字段配置');
            return;
        }

        // 1. 提取模板字段（field指定字段）
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFieldsForConfig: 模板字段', templateFields);
        console.log('loadModelFieldsForConfig: 模板筛选字段', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFieldsForConfig: 开始加载字段配置', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        // 显示loading
        const availableFields = document.getElementById('w-available-fields-' + tableId);
        const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
        if (availableFields) {
            availableFields.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("加载中...") + '</div>';
        }
        if (availableFieldsFilter) {
            availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("加载中...") + '</div>';
        }

        fetch(instance.apiUrl + '/fields', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                table_id: tableId,
                model: instance.options.model,
                scope: instance.options.scope
            })
        })
            .then(response => response.json())
            .then(response => {
                // 3. 合并模板字段和接口字段（用于"可用字段"列表）
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 合并filter字段
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 确定显示字段：优先级为 缓存配置 > 模板字段 > API默认字段
                let displayFields;
                const cachedDisplayFields = response.data.cached_display_fields;
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                if (cachedDisplayFields && cachedDisplayFields.length > 0) {
                    // 有缓存配置，使用缓存配置，但确保模板字段属性优先
                    displayFields = cachedDisplayFields.map(cachedField => {
                        const templateField = templateFields.find(t => t.name === cachedField.name);
                        return templateField ? { ...cachedField, ...templateField } : cachedField;
                    });
                    console.log('loadModelFieldsForConfig: 使用缓存配置', displayFields);
                } else if (templateFields.length > 0) {
                    // 没有缓存配置，但有模板字段，只显示模板字段
                    displayFields = [...templateFields];
                    console.log('loadModelFieldsForConfig: 使用模板字段（默认只显示模板中指定的字段）', displayFields);
                } else {
                    // 没有缓存配置也没有模板字段，使用API默认字段
                    displayFields = response.data.display_fields || [];
                    console.log('loadModelFieldsForConfig: 使用API默认字段', displayFields);
                }

                // 5. 记录用户选择的字段（非模板字段）
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFieldsForConfig: 用户选择的字段', userSelectedFields);

                // 6. 处理受保护字段的配置
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 主键/索引字段不能排序和移动
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 其它受保护字段默认可以排序和移动
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 确保指定字段排到前面
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 重新排序：模板字段在前，用户字段在后
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 只渲染字段配置弹窗，不触发表格重新构建
                this.renderModelFieldsFromData(tableId, {
                    all_fields: mergedFields,
                    display_fields: displayFields,
                    filter_fields: mergedFilterFields
                });
            })
            .catch(error => {
                console.error('loadModelFieldsForConfig: 加载字段配置失败', error);
                const availableFields = document.getElementById('w-available-fields-' + tableId);
                const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
                if (availableFields) {
                    availableFields.innerHTML = '<div class="w-text-center w-text-danger w-py-4"><i class="fas fa-exclamation-triangle"></i> ' + __("加载失败") + '</div>';
                }
                if (availableFieldsFilter) {
                    availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-danger w-py-4"><i class="fas fa-exclamation-triangle"></i> ' + __("加载失败") + '</div>';
                }
            });
    },

    // 合并模板字段和接口字段，模板字段优先
    mergeTemplateAndApiFields: function (templateFields, apiFields) {
        const map = {};
        templateFields.forEach(f => map[f.name] = f);
        apiFields.forEach(f => {
            if (!map[f.name]) map[f.name] = f;
        });
        return Object.values(map);
    },

    /**
     * 应用字段配置到表?
     */
    applyFieldsToTable: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 确保模板字段在前?
        const templateFields = instance.displayFields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = instance.displayFields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );

        // 重新排序：模板字段在前，用户字段在后
        const orderedDisplayFields = [...templateFields, ...userFields];

        // 应用字段配置到表格头?
        this.renderHeader(tableId, orderedDisplayFields);

        // 应用字段配置到筛选区?
        this.renderFilter(tableId, instance.filterFields);

        // 更新实例中的字段顺序
        instance.displayFields = orderedDisplayFields;

        // 保存配置到缓?
        this.saveFieldConfigToCache(tableId);
    },

    /**
     * 渲染字段类型下拉
     */
    renderFieldTypeSelect: function (tableId, field, type) {
        const options = DataTableManager.fieldTypeOptions;
        const selectId = `w-field-type-select-${type}-${tableId}-${field.name}`;
        let html = `<select class="w-field-type-select w-btn-sm" id="${selectId}" data-table="${tableId}" data-field="${field.name}" data-type="${type}">`;
        options.forEach(opt => {
            html += `<option value="${opt.value}"${field.type === opt.value ? ' selected' : ''}>${opt.label}</option>`;
        });
        html += '</select>';
        return html;
    },

    /**
     * 字段类型下拉变更事件
     */
    bindFieldTypeChange: function (tableId) {
        // 解绑再绑定，防止重复
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        // 先移除之前的事件
        if (modal._fieldTypeChangeHandler) {
            modal.removeEventListener('change', modal._fieldTypeChangeHandler, true);
        }
        modal._fieldTypeChangeHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-field-type-select')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 只更新基本信息显示，不重新渲染整个列
                    const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            }
        };
        modal.addEventListener('change', modal._fieldTypeChangeHandler, true);
    },

    /**
     * 字段label/placeholder输入变更事件
     */
    bindFieldLabelInput: function (tableId) {
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        // 先移除之前的事件
        if (modal._fieldLabelInputHandler) {
            modal.removeEventListener('input', modal._fieldLabelInputHandler, true);
        }
        modal._fieldLabelInputHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-field-label-input')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 只更新基本信息显示，不重新渲染整个列
                    const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameEl = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameEl) {
                            fieldNameEl.textContent = value || field.name;
                        }
                    }
                }
            }
            // placeholder
            if (target.classList.contains('w-field-placeholder-input')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    field.placeholder = value;
                }
            }
        };
        modal.addEventListener('input', modal._fieldLabelInputHandler, true);
    },

    /**
     * 字段校验输入变更事件
     */
    bindFieldValidationInput: function (tableId) {
        const modal = document.getElementById('w-field-config-modal-' + tableId) || document;
        if (modal._fieldValidationInputHandler) {
            modal.removeEventListener('input', modal._fieldValidationInputHandler, true);
        }
        modal._fieldValidationInputHandler = function (e) {
            const target = e.target;
            if (target.classList.contains('w-validation-min') || target.classList.contains('w-validation-max') || target.classList.contains('w-validation-pattern')) {
                const tableId = target.dataset.table;
                const fieldName = target.dataset.field;
                const type = target.dataset.type;
                const value = target.value;
                const instance = DataTableManager.instances[tableId];
                if (!instance) return;
                let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
                const field = fieldList.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    if (target.classList.contains('w-validation-min')) field.validation.min = value;
                    if (target.classList.contains('w-validation-max')) field.validation.max = value;
                    if (target.classList.contains('w-validation-pattern')) field.validation.pattern = value;
                }
            }
        };
        modal.addEventListener('input', modal._fieldValidationInputHandler, true);
    },

    /**
     * 从数据渲染模型字段（适配w-前缀class/id?
     */
    renderModelFieldsFromData: function (tableId, data) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const $availableFields = $('#w-available-fields-' + tableId);
        const $availableFieldsFilter = $('#w-available-fields-filter-' + tableId);
        const $displayFields = $('#w-display-fields-' + tableId);
        const $filterFields = $('#w-filter-fields-' + tableId);

        // 处理数据结构
        let allFields = [];
        let displayFields;
        let filterFields;
        if (data && typeof data === 'object') {
            allFields = data.all_fields || data.fields || [];
            // 优先用接口返回的display_fields/filter_fields（即使为空也用）
            if ('display_fields' in data) {
                displayFields = data.display_fields || [];
                console.log('renderModelFieldsFromData: 接口返回display_fields', displayFields);
            }
            if ('filter_fields' in data) {
                filterFields = data.filter_fields || [];
                console.log('renderModelFieldsFromData: 接口返回filter_fields', filterFields);
            }
        }

        // 如果没有返回，使用默认逻辑
        if (typeof displayFields === 'undefined') {
            displayFields = this.getDefaultDisplayFields(allFields);
            console.log('renderModelFieldsFromData: 使用默认displayFields', displayFields);
        }
        if (typeof filterFields === 'undefined') {
            filterFields = [];
            console.log('renderModelFieldsFromData: 使用默认filterFields', filterFields);
        }

        // 保存到实例中，供后续使用
        instance.allFields = allFields;
        instance.displayFields = displayFields;
        instance.filterFields = filterFields;

        console.log('renderModelFieldsFromData: 最终数据', {
            allFields: allFields.length,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        // 计算可用字段（分别计算两个tab的可用字段）
        // displayFields: 模板字段+用户配置字段
        // allFields: 接口返回的所有字段
        const displayFieldNames = new Set(displayFields.map(f => f.name));
        const availableFieldsForDisplay = allFields.filter(field => !displayFieldNames.has(field.name));

        // 受保护字段定义
        function isProtectedField(field) {
            return DataTableManager.isFieldProtected(field) || DataTableManager.isPrimaryOrIndexField(field);
        }

        const filterFieldNames = new Set(filterFields.map(f => f.name));
        // 可用筛选字段：排除受保护字段
        const availableFieldsForFilter = allFields.filter(field => !filterFieldNames.has(field.name) && !isProtectedField(field));

        // 受保护字段应始终在已选筛选字段中
        // 过滤出所有受保护字段
        const protectedFilterFields = allFields.filter(field => isProtectedField(field));
        // 合并：受保护字段 + 其它已选字段（去重）
        const filterFieldsNoProtected = filterFields.filter(f => !isProtectedField(f));
        const finalFilterFields = [...protectedFilterFields, ...filterFieldsNoProtected.filter(f => !protectedFilterFields.some(pf => pf.name === f.name))];
        instance.filterFields = finalFilterFields;

        console.log('renderModelFieldsFromData: 可用字段计算', {
            availableFieldsForDisplay: availableFieldsForDisplay.length,
            availableFieldsForFilter: availableFieldsForFilter.length
        });

        // 渲染字段条目时，将所有属性设置为data-属?
        function getFieldDataAttrs(field) {
            let attrs = '';
            for (const key in field) {
                if (Object.prototype.hasOwnProperty.call(field, key) && field[key] !== undefined) {
                    // 将驼峰转为中划线
                    const dataKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
                    attrs += ` data-${dataKey}="${String(field[key]).replace(/"/g, '&quot;')}"`;
                }
            }
            return attrs;
        }

        // 渲染列设置tab的可用字段
        let availableHtmlForDisplay = '';
        if (availableFieldsForDisplay.length > 0) {
            availableFieldsForDisplay.forEach(field => {
                const isProtected = this.isFieldProtected(field);
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("受保护") + '</span>' : '';

                availableHtmlForDisplay += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
            ${protectionBadge}
        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-primary" 
                    onclick="DataTableManager.addField('${tableId}', '${field.name}', 'display')"
                    ${disabledAttr}>
                <i class="fas fa-table"></i> ${__("显示")}
            </button>
        </div>
    </div>`;
            });
        } else {
            availableHtmlForDisplay = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("所有字段都已配置")}
    </div>`;
        }

        // 渲染筛选设置tab的可用字段
        let availableHtmlForFilter = '';
        if (availableFieldsForFilter.length > 0) {
            availableFieldsForFilter.forEach(field => {
                const isProtected = isProtectedField(field);
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("受保护") + '</span>' : '';

                availableHtmlForFilter += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info">
            <span class="w-field-name">${field.label || field.name}</span>
            <small class="w-text-muted">${field.name}</small>
            <span class="w-field-type-badge">${field.type || 'text'}</span>
            ${protectionBadge}
        </div>
        <div class="w-field-actions">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-success" 
                    onclick="DataTableManager.addField('${tableId}', '${field.name}', 'filter')"
                    ${disabledAttr}>
                <i class="fas fa-filter"></i> ${__("筛选")}
            </button>
        </div>
    </div>`;
            });
        } else {
            availableHtmlForFilter = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("所有字段都已配置")}
    </div>`;
        }

        // 分别更新两个可用字段区域
        var availableFieldsEl = document.getElementById('w-available-fields-' + tableId);
        if (availableFieldsEl) availableFieldsEl.innerHTML = availableHtmlForDisplay;
        var availableFieldsFilterEl = document.getElementById('w-available-fields-filter-' + tableId);
        if (availableFieldsFilterEl) availableFieldsFilterEl.innerHTML = availableHtmlForFilter;

        // 渲染显示字段
        let displayHtml = '';
        if (displayFields.length > 0) {
            console.log('renderModelFieldsFromData: 开始渲染显示字段', displayFields);
            displayFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 渲染显示字段', index, field);
                const isProtected = this.isFieldProtected(field);
                const isFromScope = field.from_scope === true;
                const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
                const isUserSelected = field.user_selected === true;
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("受保护") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("已保存") + '</span>' : '';
                const userSelectedBadge = isUserSelected ? '<span class="w-badge" style="background:#dbeafe;color:#1e40af;">' + __("用户选择") + '</span>' : '';
                let validationHtml = '';

                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("最小长度")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("最大长度")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("正则表达式")}" style="width:120px;" />
                    </div>`;
                }

                // 主键/索引字段不能隐藏
                const isPrimaryOrIndex = DataTableManager.isPrimaryOrIndexField(field);
                // 对于模板字段和主键/索引字段，不显示隐藏按钮
                const hideButtonHtml = (isTemplateField || isPrimaryOrIndex) ? '' : `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    onclick="DataTableManager.removeField('${tableId}', '${field.name}', 'display')"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("隐藏")}
            </button>`;
                // 主键/索引字段不能移动
                const canMove = field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0';
                const moveUpButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'up', 'display')"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("上移")}
            </button>` : '';
                const moveDownButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'down', 'display')"
                    ${index === displayFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("下移")}
            </button>` : '';

                displayHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info" style="flex:1;min-width:0;">
            <div class="w-field-basic-info">
                <small class="w-text-muted">${field.name}</small>
                <span class="w-field-name">${field.label || field.name}</span>
                <span class="w-field-type-badge">${field.type || 'text'}</span>
                <div class="w-field-badges">
                    ${protectionBadge}
                    ${scopeBadge}
                    ${userSelectedBadge}
                </div>
            </div>
            <div class="w-field-detail-config" style="display:none;margin-top:8px;padding-top:8px;border-top:1px solid var(--datatable-border);">
                <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("字段标题")}" style="margin-bottom:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                <span class="w-field-type-badge">${isProtected ? field.type || 'text' : DataTableManager.renderFieldTypeSelect(tableId, field, 'display')}</span>
                <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("占位符（可选）")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="display" placeholder="${__("选项(?:启用,0:禁用)")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />` : ''}
                ${validationHtml}
            </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary w-btn-toggle-config" 
                    onclick="DataTableManager.toggleFieldConfig('${tableId}', '${field.name}', 'display')"
                    data-field="${field.name}" data-type="display" ${isProtected ? 'disabled' : ''}>
                <i class="fas fa-cog"></i> ${__("设置")}
            </button>
            ${hideButtonHtml}
            ${moveUpButtonHtml}
            ${moveDownButtonHtml}
        </div>
    </div>`;
            });
        } else {
            displayHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("暂无显示字段")}
        <br><small>${__("您可以在右侧调整字段配置")}</small>
    </div>`;
        }
        var displayFieldsEl = document.getElementById('w-display-fields-' + tableId);
        if (displayFieldsEl) displayFieldsEl.innerHTML = displayHtml;

        // 渲染筛选字段
        let filterHtml = '';
        if (finalFilterFields.length > 0) {
            console.log('renderModelFieldsFromData: 开始渲染筛选字段', finalFilterFields);
            finalFilterFields.forEach((field, index) => {
                console.log('renderModelFieldsFromData: 渲染筛选字段', index, field);
                const isProtected = isProtectedField(field);
                const isFromScope = field.from_scope === true;
                const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
                const isUserSelected = field.user_selected === true;
                const disabledAttr = isProtected ? 'disabled' : '';
                const disabledClass = isProtected ? 'disabled' : '';
                const protectionBadge = isProtected ? '<span class="w-badge w-badge-protected">' + __("受保护") + '</span>' : '';
                const scopeBadge = isFromScope ? '<span class="w-badge" style="background:#bbf7d0;color:#166534;">' + __("已保存") + '</span>' : '';
                const userSelectedBadge = isUserSelected ? '<span class="w-badge" style="background:#dbeafe;color:#1e40af;">' + __("用户选择") + '</span>' : '';
                let validationHtml = '';

                if (field.validation) {
                    const validation = field.validation;
                    validationHtml = `
                    <div class="w-validation-settings">
                        <input class="w-validation-min w-btn-sm" type="number" value="${validation.min || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("最小长度")}" style="width:80px;" />
                        <input class="w-validation-max w-btn-sm" type="number" value="${validation.max || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("最大长度")}" style="width:80px;" />
                        <input class="w-validation-pattern w-btn-sm" type="text" value="${validation.pattern || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("正则表达式")}" style="width:120px;" />
                    </div>`;
                }

                // 受保护字段不显示移除按钮
                const removeButtonHtml = isProtected ? '' : `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-danger" 
                    onclick="DataTableManager.removeField('${tableId}', '${field.name}', 'filter')"
                    ${disabledAttr}>
                <i class="fas fa-eye-slash"></i> ${__("移除")}
            </button>`;

                // 筛选字段的移动按钮
                const canMove = field.filter_orderable !== false && field.filter_orderable !== 'false' && field.filter_orderable !== 0 && field.filter_orderable !== '0';
                const moveUpButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'up', 'filter')"
                    ${index === 0 ? 'disabled' : ''}>
                <i class="fas fa-arrow-up"></i> ${__("上移")}
            </button>` : '';
                const moveDownButtonHtml = canMove ? `
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary" 
                    onclick="DataTableManager.moveField('${tableId}', '${field.name}', 'down', 'filter')"
                    ${index === finalFilterFields.length - 1 ? 'disabled' : ''}>
                <i class="fas fa-arrow-down"></i> ${__("下移")}
            </button>` : '';

                filterHtml += `
    <div class="w-field-item ${disabledClass}" data-field="${field.name}" ${getFieldDataAttrs(field)}>
        <div class="w-field-info" style="flex:1;min-width:0;">
            <div class="w-field-basic-info">
                <small class="w-text-muted">${field.name}</small>
                <span class="w-field-name">${field.label || field.name}</span>
                <span class="w-field-type-badge">${field.type || 'text'}</span>
                <div class="w-field-badges">
                    ${protectionBadge}
                    ${scopeBadge}
                    ${userSelectedBadge}
                </div>
            </div>
            <div class="w-field-detail-config" style="display:none;margin-top:8px;padding-top:8px;border-top:1px solid var(--datatable-border);">
                <input class="w-field-label-input w-btn-sm" type="text" value="${field.label || field.name}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("字段标题")}" style="margin-bottom:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                <span class="w-field-type-badge">${isProtected ? field.type || 'text' : DataTableManager.renderFieldTypeSelect(tableId, field, 'filter')}</span>
                <input class="w-field-placeholder-input w-btn-sm" type="text" value="${field.placeholder || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("占位符（可选）")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />
                ${field.type === 'select' ? `<input class="w-field-options-input w-btn-sm" type="text" value="${field.options || ''}" data-table="${tableId}" data-field="${field.name}" data-type="filter" placeholder="${__("选项(值:标签,值:标签)")}" style="margin-top:4px;max-width:120px;" ${isProtected ? 'disabled' : ''} />` : ''}
                ${validationHtml}
            </div>
        </div>
        <div class="w-field-actions" style="flex-direction:column;gap:6px;align-items:flex-end;min-width:70px;">
            <button type="button" class="w-btn w-btn-sm w-btn-outline-secondary w-btn-toggle-config" 
                    onclick="DataTableManager.toggleFieldConfig('${tableId}', '${field.name}', 'filter')"
                    data-field="${field.name}" data-type="filter" ${isProtected ? 'disabled' : ''}>
                <i class="fas fa-cog"></i> ${__("设置")}
            </button>
            ${removeButtonHtml}
            ${moveUpButtonHtml}
            ${moveDownButtonHtml}
        </div>
    </div>`;
            });
        } else {
            filterHtml = `
    <div class="w-text-center w-text-muted w-py-4">
        <i class="fas fa-info-circle"></i> ${__("暂无筛选字段")}
        <br><small>${__("您可以在右侧调整字段配置")}</small>
    </div>`;
        }
        var filterFieldsEl = document.getElementById('w-filter-fields-' + tableId);
        if (filterFieldsEl) filterFieldsEl.innerHTML = filterHtml;

        // 绑定事件
        this.bindFieldEvents(tableId);

        // 初始化拖拽排序
        this.initDragSort(tableId);
    },

    /**
     * 重置为默认字段配?
     */
    resetToDefault: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        if (confirm(__('确定要重置为默认字段配置吗？这将显示所有可用字段？'))) {
            // 清除缓存
            const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
            localStorage.removeItem(cacheKey);
            // 重新加载字段数据
            this.loadModelFields(tableId);
        }
    },

    /**
     * 添加字段到显示或筛选列?
     */
    addField: function (tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const field = instance.allFields.find(f => f.name === fieldName);
        if (!field) return;

        let targetList = type === 'display' ? instance.displayFields : instance.filterFields;
        const existingIndex = targetList.findIndex(f => f.name === fieldName);
        if (existingIndex !== -1) return;

        // 标记为用户选择的字?
        const fieldToAdd = {
            ...field,
            user_selected: true,
            sortable: field.sortable !== false,
            editable: field.editable !== false,
            searchable: field.searchable !== false,
            resizable: field.resizable !== false,
            visible: field.visible !== false
        };

        if (type === 'display') {
            // 对于显示字段，用户选择的字段应该插入到模板字段之后
            const templateFieldCount = instance.displayFields.filter(f =>
                f.template_defined || f.field_defined || f.from_field
            ).length;
            instance.displayFields.splice(templateFieldCount, 0, fieldToAdd);
        } else {
            // 对于筛选字段，直接添加到末?
            instance.filterFields.push(fieldToAdd);
        }

        // 保存配置到缓?
        this.saveFieldConfigToCache(tableId);

        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 从显示列表或筛选列表移除字?
     */
    removeField: function (tableId, fieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx !== -1) {
            const field = fieldList[idx];
            // 检查字段是否受保护
            if (this.isFieldProtected(field)) {
                console.warn('removeField: 尝试删除受保护的字段', fieldName);
                return;
            }
            // 额外检查是否为模板字段
            const isTemplateField = field.field_defined === true || field.template_defined === true || field.from_field === true;
            if (isTemplateField) {
                console.warn('removeField: 尝试删除模板字段', fieldName);
                return;
            }
            fieldList.splice(idx, 1);
            this.renderModelFieldsFromData(tableId, {
                all_fields: instance.allFields,
                display_fields: instance.displayFields,
                filter_fields: instance.filterFields
            });
        }
    },

    /**
     * 移动字段位置
     */
    moveField: function (tableId, fieldName, direction, type) {
        const instance = this.instances[tableId];
        if (!instance) return;
        let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const idx = fieldList.findIndex(f => f.name === fieldName);
        if (idx === -1) return;

        const field = fieldList[idx];
        const isPrimary = DataTableManager.isPrimaryOrIndexField(field);
        // 只有明确设置display_orderable为false的字段和主键字段才不允许移动
        const canMove = !isPrimary && (field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0');

        if (!canMove) {
            console.warn('moveField: 字段不允许移动', fieldName);
            return;
        }

        let newIdx = direction === 'up' ? idx - 1 : idx + 1;
        if (newIdx < 0 || newIdx >= fieldList.length) return;

        // 检查目标位置字段是否允许移动
        const targetField = fieldList[newIdx];
        const targetIsPrimary = DataTableManager.isPrimaryOrIndexField(targetField);
        const targetCanMove = !targetIsPrimary && (targetField.display_orderable !== false && targetField.display_orderable !== 'false' && targetField.display_orderable !== 0 && targetField.display_orderable !== '0');

        if (!targetCanMove) {
            console.warn('moveField: 目标位置字段不允许移动', targetField.name);
            return;
        }

        // 执行移动
        const temp = fieldList[idx];
        fieldList.splice(idx, 1);
        fieldList.splice(newIdx, 0, temp);

        // 保存配置到缓存
        this.saveFieldConfigToCache(tableId);

        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });
    },

    /**
     * 保存字段配置到缓?
     */
    saveFieldConfigToCache: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        // 强制主键/索引字段始终存在于显示字段列?
        const allPrimaryOrIndexFields = (instance.allFields || []).filter(DataTableManager.isPrimaryOrIndexField);
        allPrimaryOrIndexFields.forEach(pkField => {
            if (!instance.displayFields.some(f => f.name === pkField.name)) {
                instance.displayFields.unshift(pkField);
            }
        });
        const cacheKey = `datatable_fields_${tableId}_${instance.options.model}_${instance.options.scope}`;
        const configData = {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        };
        localStorage.setItem(cacheKey, JSON.stringify(configData));

        console.log('字段配置已自动保存到缓存:', configData);
    },

    /**
     * 刷新数据
     */
    refreshData: function (tableId) {
        const instance = this.getInstance(tableId);
        
        if (instance) {
            this.loadData(instance);
        } else {
            console.error('DataTable instance not found for:', tableId, 'Available instances:', Object.keys(this.instances));
        }
    },

    /**
     * 切换高级过滤?
     */
    toggleAdvancedFilter: function (scope) {
        const instance = this.getInstanceByScope(scope);
        if (instance) {
            const $filter = instance.container.find('.datatable-filter');
            $filter.find('.advanced-filters').toggle();
        }
    },

    /**
     * 跳转到指定页?
     */
    goToPage: function (scope, page) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            if (page === 'prev') {
                page = Math.max(1, instance.currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(instance.pagination.lastPage, instance.currentPage + 1);
            } else if (page === 'last') {
                page = instance.pagination.lastPage;
            }

            if (page !== instance.currentPage) {
                instance.currentPage = page;
                this.loadData(instance);
            }
        }
    },

    /**
     * 改变每页显示数量
     */
    changePageSize: function (scope, pageSize) {
        const instance = this.getInstanceByScope(scope.replace('-footer', ''));
        if (instance) {
            instance.pageSize = parseInt(pageSize);
            instance.currentPage = 1;
            this.loadData(instance);
        }
    },

    /**
     * 保存字段配置
     */
    saveFieldConfig: function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) return;

        const displayFields = instance.displayFields || [];
        const filterFields = instance.filterFields || [];

        console.log('saveFieldConfig: 保存配置', {
            tableId,
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        const configData = {
            table_id: tableId,
            display_fields: displayFields,
            filter_fields: filterFields,
            page_size: 20,
            sort_field: '',
            sort_direction: 'asc'
        };

        var $saveBtn = document.querySelector(`#w-field-config-modal-${tableId} .w-btn-primary`);
        if ($saveBtn) {
            var originalText = $saveBtn.innerHTML;
            $saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存?..';
            $saveBtn.disabled = true;
        }

        fetch(instance.apiUrl + '/save-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: instance.options.scope, config: configData })
        }).then(r => r.json()).then(response => {
            // 兼容 code 为字符串或数字
            if (response.code == 200 || response.code === '200' || response.success) {
                console.log('saveFieldConfig: 保存成功，开始重新渲染表');

                // 关闭配置弹窗
                DataTableManager.closeFieldConfig(tableId);

                // 根据新的字段配置重新渲染表格
                DataTableManager.rebuildTableFromConfig(tableId, displayFields, filterFields);

            } else {
                DataTableManager.showError(tableId, response.msg || response.message || __('保存失败'));
            }
        }).catch(error => {
            console.error('saveFieldConfig: 保存失败', error);
            DataTableManager.showError(tableId, __('保存失败: %{1}', [error.message || '未知错误']));
        }).finally(() => {
            if ($saveBtn) {
                $saveBtn.innerHTML = originalText;
                $saveBtn.disabled = false;
            }
        });
    },

    /**
     * 根据配置重新构建表格
     */
    rebuildTableFromConfig: function (tableId, displayFields, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        console.log('rebuildTableFromConfig: 开始重新构建表', {
            displayFields: displayFields.length,
            filterFields: filterFields.length
        });

        // 第一步：清空旧数据和状态
        instance.data = [];
        instance.currentPage = 1;
        instance.filters = {};
        instance.search = '';
        instance.sorts = {};

        // 第二步：更新实例中的字段配置
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text',
            placeholder: field.placeholder || '',
            options: field.options || ''
        }));

        // 第三步：更新筛选器配置
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `请输入${field.label || field.name}`,
            options: field.options || ''
        }));

        // 同时更新实例中的filterFields
        instance.filterFields = filterFields;

        console.log('rebuildTableFromConfig: 字段配置已更新', {
            configFields: instance.config.fields.length,
            filterConfig: instance.filterConfig.length,
            filterFields: instance.filterFields.length
        });

        // 第四步：重新构建表头
        console.log('rebuildTableFromConfig: 开始重新构建表头');
        const container = instance.container[0] || instance.container;
        const thead = container.querySelector('thead');
        if (thead) {
            this.renderHeader(tableId, displayFields);
            console.log('rebuildTableFromConfig: 表头重新构建完成');

            // 验证表头构建结果
            const headerCells = thead.querySelectorAll('th');
            console.log('rebuildTableFromConfig: 表头验证', {
                expectedFields: instance.config.fields.length,
                actualCells: headerCells.length,
                headerTexts: Array.from(headerCells).map(cell => cell.textContent.trim())
            });
        } else {
            console.error('rebuildTableFromConfig: 未找到表头内容');
        }

        // 第五步：重新构建筛选器
        console.log('rebuildTableFromConfig: 开始重新构建筛选器');
        this.renderFilter(tableId, instance.filterFields);
        console.log('rebuildTableFromConfig: 筛选器重新构建完成');

        // 验证筛选器构建结果
        const filterContainers = [
            '.datatable-filter',
            '.datatable-filter-toolbar',
            '.datatable-filter-form'
        ];

        filterContainers.forEach(selector => {
            const filterContainer = container.querySelector(selector);
            if (filterContainer) {
                const filterInputs = filterContainer.querySelectorAll('[data-field]');
                console.log(`rebuildTableFromConfig: 筛选器验证 ${selector}`, {
                    expectedFilters: instance.filterConfig.length,
                    actualInputs: filterInputs.length,
                    filterFields: Array.from(filterInputs).map(input => input.getAttribute('data-field'))
                });
            } else {
                console.warn(`rebuildTableFromConfig: 未找到筛选器容器 ${selector}`);
            }
        });

        // 第六步：重新绑定事件
        console.log('rebuildTableFromConfig: 开始重新绑定事件');
        this.bindEvents(instance);
        console.log('rebuildTableFromConfig: 事件绑定完成');

        // 第七步：重新构建表格主体
        console.log('rebuildTableFromConfig: 开始重新构建表格主体');
        this.renderTable(instance);
        console.log('rebuildTableFromConfig: 表格主体重新构建完成');

        // 验证表格构建结果
        const tbody = container.querySelector('tbody');
        if (tbody) {
            const tbodyRows = tbody.querySelectorAll('tr');
            console.log('rebuildTableFromConfig: 表格主体验证', {
                rows: tbodyRows.length,
                hasData: tbodyRows.length > 0 && !tbodyRows[0].querySelector('td')?.textContent.includes('暂无数据')
            });
        }

        // 第八步：重新加载数据
        console.log('rebuildTableFromConfig: 开始重新加载数据');
        this.loadData(instance);
        console.log('rebuildTableFromConfig: 数据加载完成');

        // 最终验证
        setTimeout(() => {
            console.log('rebuildTableFromConfig: 最终验证', {
                tableId: tableId,
                configFields: instance.config.fields?.length || 0,
                filterConfig: instance.filterConfig?.length || 0,
                data: instance.data?.length || 0,
                headerCells: container.querySelectorAll('thead th').length,
                filterInputs: container.querySelectorAll('.datatable-filter [data-field]').length,
                toolbarInputs: container.querySelectorAll('.datatable-filter-toolbar [data-field]').length,
                formInputs: container.querySelectorAll('.datatable-filter-form [data-field]').length
            });
        }, 100);

        console.log('rebuildTableFromConfig: 表格重新构建完成');
    },

    /**
     * 更新表格字段配置
     */
    updateTableFields: function (tableId, displayFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 更新实例中的表格字段配置
        instance.config.fields = displayFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            visible: true,
            sortable: field.sortable !== false,
            searchable: field.searchable !== false,
            editable: field.editable !== false,
            width: field.width || 'auto',
            minWidth: field.minWidth || 'auto',
            maxWidth: field.maxWidth || 'auto',
            resizable: field.resizable !== false,
            type: field.type || 'text'
        }));

        // 重新渲染表头
        this.renderHeader(tableId, displayFields);

        // 重新渲染表格数据
        this.renderTable(instance);

        // 重新加载数据
        this.loadData(instance);
    },

    /**
     * 更新筛选器字段配置
     */
    updateFilterFields: function (tableId, filterFields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 更新筛选器字段配置
        instance.filterConfig = filterFields.map(field => ({
            name: field.name,
            label: field.label || field.name,
            type: field.type || 'text',
            searchable: field.searchable !== false,
            placeholder: field.placeholder || `请输入${field.label || field.name}`,
            options: field.options || ''
        }));

        // 重新渲染筛选器
        this.renderFilter(tableId, filterFields);
    },

    /**
     * 渲染筛选器
     */
    renderFilter: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;

        console.log('renderFilter: 开始渲染筛选器', {
            tableId,
            fieldsCount: fields.length,
            fields: fields.map(f => ({ name: f.name, label: f.label, type: f.type }))
        });

        // 渲染主要的筛选器容器
        this.renderFilterContainer(tableId, fields, '.datatable-filter');

        // 渲染筛选器工具栏
        this.renderFilterContainer(tableId, fields, '.datatable-filter-toolbar');

        // 渲染筛选器表单
        this.renderFilterContainer(tableId, fields, '.datatable-filter-form');

        console.log('renderFilter: 筛选器渲染完成');
    },

    /**
     * 渲染指定的筛选器容器
     */
    renderFilterContainer: function (tableId, fields, selector) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const container = instance.container[0] || instance.container;
        const filterContainer = container.querySelector(selector);
        if (!filterContainer) {
            console.warn(`renderFilterContainer: 未找到筛选器容器 ${selector}`);
            return;
        }

        console.log(`renderFilterContainer: 开始渲染筛选器容器 ${selector}`, {
            tableId,
            fieldsCount: fields.length,
            fields: fields.map(f => ({ name: f.name, label: f.label, type: f.type }))
        });

        // 确保字段顺序正确
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let filterHtml = '';

        // 为不同的容器提供不同的渲染逻辑
        if (selector === '.datatable-filter-form') {
            // 筛选器表单容器的特殊渲染逻辑
            filterHtml = this.renderFilterFormHtml(tableId, orderedFields);
        } else if (selector === '.datatable-filter-toolbar') {
            // 筛选器工具栏的手风琴式渲染逻辑
            filterHtml = this.renderFilterToolbarHtml(tableId, orderedFields);
        } else {
            // 其他容器的标准渲染逻辑
            filterHtml = this.renderStandardFilterHtml(tableId, orderedFields);
        }

        // 直接设置容器的HTML内容
        filterContainer.innerHTML = filterHtml;

        console.log(`renderFilterContainer: 筛选器容器 ${selector} 渲染完成`, {
            renderedFields: filterContainer.querySelectorAll('[data-field]').length,
            containerHtml: filterContainer.innerHTML.substring(0, 200) + '...'
        });

        // 绑定筛选事件
        filterContainer.querySelectorAll('.filter-input').forEach(input => {
            input.addEventListener('input', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });

            input.addEventListener('change', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });
        });

        // 初始化手风琴功能（如果是工具栏）
        if (selector === '.datatable-filter-toolbar') {
            this.initFilterAccordion(tableId);
        }
    },

    /**
     * 渲染标准筛选器HTML
     */
    renderStandardFilterHtml: function (tableId, fields) {
        let filterHtml = '';
        fields.forEach(field => {
            filterHtml += this.renderFilterFieldHtml(tableId, field, 'standard');
        });
        return filterHtml;
    },

    /**
     * 渲染筛选器表单HTML
     */
    renderFilterFormHtml: function (tableId, fields) {
        let filterHtml = '';
        fields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSearch = isProtected ?
                (field.searchable === true || field.searchable === 'true') :
                (field.searchable !== false);

            if (canSearch) {
                const fieldType = field.type || 'text';
                const placeholder = field.placeholder || `请输入${field.label || field.name}`;
                const fieldId = 'filter-form-' + field.name;

                if (fieldType === 'select') {
                    let optionsHtml = '<option value="">请选择</option>';
                    if (field.options) {
                        const optionPairs = field.options.split(',');
                        optionPairs.forEach(pair => {
                            const [value, label] = pair.split(':');
                            if (value && label) {
                                optionsHtml += `<option value="${value.trim()}">${label.trim()}</option>`;
                            }
                        });
                    }

                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <select class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}">
                                ${optionsHtml}
                            </select>
                        </div>`;
                } else if (fieldType === 'date') {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="date" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                } else if (fieldType === 'number') {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="number" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                } else {
                    filterHtml += `
                        <div class="filter-field" data-field="${field.name}">
                            <label for="${fieldId}" class="form-label">${field.label || field.name}</label>
                            <input type="text" class="form-control form-control-sm filter-input" id="${fieldId}" name="filter[${field.name}]" data-field="${field.name}" placeholder="${placeholder}">
                        </div>`;
                }
            }
        });
        return filterHtml;
    },

    /**
     * 渲染筛选器工具栏HTML（手风琴式）
     */
    renderFilterToolbarHtml: function (tableId, fields) {
        // 分离指定字段和其他字段
        const specifiedFields = fields.filter(field =>
            field.field_defined === true || field.template_defined === true || field.from_field === true
        );
        const otherFields = fields.filter(field =>
            !field.field_defined && !field.template_defined && !field.from_field
        );

        let filterHtml = '';

        // 渲染指定字段（直接显示）
        if (specifiedFields.length > 0) {
            filterHtml += '<div class="filter-specified-fields">';
            specifiedFields.forEach(field => {
                filterHtml += this.renderFilterFieldHtml(tableId, field, 'toolbar');
            });
            filterHtml += '</div>';
        }

        // 渲染其他字段（手风琴式）
        if (otherFields.length > 0) {
            filterHtml += `
                <div class="filter-accordion">
                    <div class="filter-accordion-header" onclick="DataTableManager.toggleFilterAccordion('${tableId}')">
                        <i class="fas fa-filter"></i>
                        <span>更多筛选条件 (${otherFields.length})</span>
                        <i class="fas fa-chevron-down filter-accordion-icon"></i>
                    </div>
                    <div class="filter-accordion-content" style="display: none;">
                        <div class="filter-accordion-fields">`;

            otherFields.forEach(field => {
                filterHtml += this.renderFilterFieldHtml(tableId, field, 'toolbar');
            });

            filterHtml += `
                        </div>
                    </div>
                </div>`;
        }

        return filterHtml;
    },

    /**
     * 渲染单个筛选字段HTML
     */
    renderFilterFieldHtml: function (tableId, field, containerType = 'toolbar') {
        const isProtected = this.isFieldProtected(field);
        const canSearch = isProtected ?
            (field.searchable === true || field.searchable === 'true') :
            (field.searchable !== false);

        if (!canSearch) return '';

        const fieldType = field.type || 'text';
        const placeholder = field.placeholder || `请输入${field.label || field.name}`;
        const fieldId = `filter-${containerType}-${field.name}`;

        // 根据容器类型设置CSS类
        let containerClass;
        switch (containerType) {
            case 'form':
                containerClass = 'filter-field';
                break;
            case 'standard':
                containerClass = 'filter-item';
                break;
            default:
                containerClass = 'filter-toolbar-item';
        }

        if (fieldType === 'select') {
            let optionsHtml = '<option value="">请选择</option>';
            if (field.options) {
                const optionPairs = field.options.split(',');
                optionPairs.forEach(pair => {
                    const [value, label] = pair.split(':');
                    if (value && label) {
                        optionsHtml += `<option value="${value.trim()}">${label.trim()}</option>`;
                    }
                });
            }

            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <select class="filter-input" id="${fieldId}" data-field="${field.name}">
                        ${optionsHtml}
                    </select>
                </div>`;
        } else if (fieldType === 'date') {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="date" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        } else if (fieldType === 'number') {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="number" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        } else {
            return `
                <div class="${containerClass}" data-field="${field.name}">
                    <label for="${fieldId}">${field.label || field.name}:</label>
                    <input type="text" class="filter-input" id="${fieldId}" data-field="${field.name}" placeholder="${placeholder}" />
                </div>`;
        }
    },

    /**
     * 切换筛选器手风琴
     */
    toggleFilterAccordion: function (tableId) {
        const container = this.instances[tableId]?.container[0] || this.instances[tableId]?.container;
        if (!container) return;

        const accordionContent = container.querySelector('.filter-accordion-content');
        const accordionIcon = container.querySelector('.filter-accordion-icon');

        if (accordionContent && accordionIcon) {
            const isVisible = accordionContent.style.display !== 'none';
            accordionContent.style.display = isVisible ? 'none' : 'block';
            accordionIcon.className = isVisible ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
        }
    },

    /**
     * 初始化筛选器手风琴功能
     */
    initFilterAccordion: function (tableId) {
        const container = this.instances[tableId]?.container[0] || this.instances[tableId]?.container;
        if (!container) return;

        const accordionHeader = container.querySelector('.filter-accordion-header');
        if (accordionHeader) {
            // 移除旧的事件监听器
            accordionHeader.removeEventListener('click', this._accordionClickHandler);

            // 添加新的事件监听器
            this._accordionClickHandler = () => this.toggleFilterAccordion(tableId);
            accordionHeader.addEventListener('click', this._accordionClickHandler);
        }
    },

    /**
     * 字段拖拽排序（w-前缀）
     */
    bindFieldDragSort: function (tableId) {
        ['display', 'filter'].forEach(type => {
            const container = document.getElementById(type === 'display' ? 'w-display-fields-' + tableId : 'w-filter-fields-' + tableId);
            if (!container) return;
            let dragSrc = null;
            container.querySelectorAll('.w-field-item').forEach(item => {
                item.draggable = true;
                item.ondragstart = function (e) {
                    dragSrc = this;
                    this.classList.add('w-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                };
                item.ondragover = function (e) {
                    e.preventDefault();
                    if (this !== dragSrc) this.classList.add('w-drag-over');
                };
                item.ondragleave = function () {
                    this.classList.remove('w-drag-over');
                };
                item.ondrop = function (e) {
                    e.preventDefault();
                    this.classList.remove('w-drag-over');
                    if (this !== dragSrc) {
                        const items = Array.from(container.querySelectorAll('.w-field-item'));
                        const from = items.indexOf(dragSrc);
                        const to = items.indexOf(this);
                        if (from !== -1 && to !== -1) {
                            let fieldList = type === 'display' ? DataTableManager.instances[tableId].displayFields : DataTableManager.instances[tableId].filterFields;
                            const moved = fieldList.splice(from, 1)[0];
                            fieldList.splice(to, 0, moved);
                            DataTableManager.renderModelFieldsFromData(tableId, {
                                all_fields: DataTableManager.instances[tableId].allFields,
                                display_fields: DataTableManager.instances[tableId].displayFields,
                                filter_fields: DataTableManager.instances[tableId].filterFields
                            });
                        }
                    }
                };
                item.ondragend = function () {
                    this.classList.remove('w-dragging');
                    container.querySelectorAll('.w-field-item').forEach(i => i.classList.remove('w-drag-over'));
                };
            });
        });
    },

    /**
     * 绑定options输入事件
     */
    bindFieldOptionsInput: function (tableId) {
        // 解绑再绑定，防止重复
        $(document).off('input', '.w-field-options-input');
        $(document).on('input', '.w-field-options-input', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.options = value;
                // 不重新渲染，只更新数?
            }
        });
    },

    /**
     * 字段只读/必填checkbox变更事件
     */
    bindFieldCheckboxInput: function (tableId) {
        // 解绑再绑定，防止重复
        $(document).off('change', '.w-field-readonly-checkbox');
        $(document).on('change', '.w-field-readonly-checkbox', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.readonly = checked;
                // 不重新渲染，只更新数?
            }
        });
        $(document).off('change', '.w-field-required-checkbox');
        $(document).on('change', '.w-field-required-checkbox', function () {
            const tableId = $(this).data('table');
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const checked = $(this).is(':checked');
            const instance = DataTableManager.instances[tableId];
            if (!instance) return;
            let fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (field) {
                field.required = checked;
                // 不重新渲染，只更新数?
            }
        });
    },

    /**
     * 绑定字段配置相关事件
     */
    bindFieldEvents: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 绑定字段标签输入事件
        $(`#w-field-config-modal-${tableId} .w-field-label-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 只更新基本信息显示?
                    const fieldItem = document.querySelector(`#w-display-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameElement = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameElement) {
                            fieldNameElement.textContent = value || field.name;
                        }
                    }
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    field.label = value;
                    // 只更新基本信息显示?
                    const fieldItem = document.querySelector(`#w-filter-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const fieldNameElement = fieldItem.querySelector('.w-field-basic-info .w-field-name');
                        if (fieldNameElement) {
                            fieldNameElement.textContent = value || field.name;
                        }
                    }
                }
            }
        });

        // 绑定字段占位符输入事?
        $(`#w-field-config-modal-${tableId} .w-field-placeholder-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.placeholder = value;
            }
        });

        // 绑定字段选项输入事件
        $(`#w-field-config-modal-${tableId} .w-field-options-input`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) field.options = value;
            }
        });

        // 绑定字段类型选择事件
        $(`#w-field-config-modal-${tableId} .w-field-type-select`).off('change').on('change', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 只更新基本信息显示?
                    const fieldItem = document.querySelector(`#w-display-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    field.type = value;
                    // 只更新基本信息显示?
                    const fieldItem = document.querySelector(`#w-filter-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
                    if (fieldItem) {
                        const typeBadge = fieldItem.querySelector('.w-field-basic-info .w-field-type-badge');
                        if (typeBadge) {
                            typeBadge.textContent = value;
                        }
                    }
                }
            }
        });

        // 绑定校验规则输入事件
        $(`#w-field-config-modal-${tableId} .w-validation-min, #w-field-config-modal-${tableId} .w-validation-max, #w-field-config-modal-${tableId} .w-validation-pattern`).off('input').on('input', function () {
            const fieldName = $(this).data('field');
            const type = $(this).data('type');
            const validationType = $(this).hasClass('w-validation-min') ? 'min' :
                $(this).hasClass('w-validation-max') ? 'max' : 'pattern';
            const value = $(this).val();

            if (type === 'display') {
                const field = instance.displayFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            } else if (type === 'filter') {
                const field = instance.filterFields.find(f => f.name === fieldName);
                if (field) {
                    if (!field.validation) field.validation = {};
                    field.validation[validationType] = value;
                }
            }
        });
    },

    /**
     * 初始化拖拽排?
     */
    initDragSort: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;

        // 为字段配置弹窗中的字段项添加拖拽排序功能
        const displayFieldsContainer = document.getElementById('w-display-fields-' + tableId);
        const filterFieldsContainer = document.getElementById('w-filter-fields-' + tableId);

        if (displayFieldsContainer) {
            this.initContainerDragSort(displayFieldsContainer, tableId, 'display');
        }
        if (filterFieldsContainer) {
            this.initContainerDragSort(filterFieldsContainer, tableId, 'filter');
        }
    },

    /**
     * 为容器初始化拖拽排序
     */
    initContainerDragSort: function (container, tableId, type) {
        const fieldItems = container.querySelectorAll('.w-field-item');

        fieldItems.forEach(item => {
            const fieldName = item.getAttribute('data-field');
            if (!fieldName) return;

            // 检查字段是否允许拖动
            const instance = this.instances[tableId];
            if (!instance) return;

            const fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
            const field = fieldList.find(f => f.name === fieldName);
            if (!field) return;

            // 只有明确设置display_orderable为false的字段才不允许拖动
            const canDrag = field.display_orderable !== false && field.display_orderable !== 'false' && field.display_orderable !== 0 && field.display_orderable !== '0';

            if (!canDrag) {
                // 不允许拖动的字段，移除拖拽相关样式和属性
                item.style.cursor = 'default';
                item.removeAttribute('draggable');
                return;
            }

            // 添加拖拽样式
            item.style.cursor = 'move';
            item.setAttribute('draggable', 'true');

            // 绑定拖拽事件
            item.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', fieldName);
                e.dataTransfer.setData('type', type);
                item.classList.add('dragging');
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                item.classList.add('drag-over');
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('drag-over');
            });

            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('drag-over');

                const draggedFieldName = e.dataTransfer.getData('text/plain');
                const draggedType = e.dataTransfer.getData('type');

                if (draggedFieldName && draggedFieldName !== fieldName && draggedType === type) {
                    // 执行字段移动
                    DataTableManager.moveFieldByDrag(tableId, draggedFieldName, fieldName, type);
                }
            });
        });
    },

    /**
     * 通过拖拽移动字段
     */
    moveFieldByDrag: function (tableId, draggedFieldName, targetFieldName, type) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const fieldList = type === 'display' ? instance.displayFields : instance.filterFields;
        const draggedIndex = fieldList.findIndex(f => f.name === draggedFieldName);
        const targetIndex = fieldList.findIndex(f => f.name === targetFieldName);

        if (draggedIndex === -1 || targetIndex === -1) return;

        const draggedField = fieldList[draggedIndex];
        const targetField = fieldList[targetIndex];

        // 检查字段是否允许移动 - 只有明确设置display_orderable为false的字段才不允许移动
        const draggedCanMove = draggedField.display_orderable !== false && draggedField.display_orderable !== 'false' && draggedField.display_orderable !== 0 && draggedField.display_orderable !== '0';
        const targetCanMove = targetField.display_orderable !== false && targetField.display_orderable !== 'false' && targetField.display_orderable !== 0 && targetField.display_orderable !== '0';

        if (!draggedCanMove) {
            console.warn('moveFieldByDrag: 字段不允许移动', draggedFieldName);
            return;
        }

        if (!targetCanMove) {
            console.warn('moveFieldByDrag: 目标位置字段不允许移动', targetFieldName);
            return;
        }

        // 执行移动
        const temp = fieldList[draggedIndex];
        fieldList.splice(draggedIndex, 1);
        fieldList.splice(targetIndex, 0, temp);

        // 立即保存用户配置
        this.saveFieldConfigToCache(tableId);

        // 重新渲染字段配置弹窗
        this.renderModelFieldsFromData(tableId, {
            all_fields: instance.allFields,
            display_fields: instance.displayFields,
            filter_fields: instance.filterFields
        });

        console.log('moveFieldByDrag: 字段拖拽移动完成', {
            type: type,
            dragged: draggedFieldName,
            target: targetFieldName,
            newOrder: fieldList.map(f => f.name)
        });
    },

    /**
     * 清理表头字段配置
     * @param {string} tableId 表格ID
     */
    clearHeaderConfig: function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (confirm(__('确定要重置表头字段配置吗？这将清除所有自定义的显示字段设置'))) {
            this.clearConfig(tableId, 'header');
        }
    },

    /**
     * 清理筛选字段配?
     * @param {string} tableId 表格ID
     */
    clearFilterConfig: function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (confirm(__('确定要重置筛选字段配置吗？这将清除所有自定义的筛选字段设置'))) {
            this.clearConfig(tableId, 'filter');
        }
    },

    /**
     * 清理全部配置
     * @param {string} tableId 表格ID
     */
    clearAllConfig: function (tableId) {
        const instance = this.getInstance(tableId);
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        if (confirm(__('确定要重置全部配置吗？这将清除所有自定义的表头字段和筛选字段设置'))) {
            this.clearConfig(tableId, 'all');
        }
    },

    /**
     * 清理配置的核心方法
     * @param {string} tableId 表格ID
     * @param {string} type 清理类型：header、filter、all
     */
    clearConfig: function (tableId, type) {
        const instance = this.instances[tableId];
        if (!instance) {
            console.error('Table instance not found:', tableId);
            return;
        }

        // 显示加载状态
        const container = instance.container[0] || instance.container; // 确保是DOM元素
        container.classList.add('loading');

        // 调用后端API清理配置
        fetch(instance.apiUrl + '/clear-config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                scope: instance.options.scope,
                type: type
            })
        })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    // 更新本地配置
                    if (type === 'header' || type === 'all') {
                        instance.displayFields = [];
                    }
                    if (type === 'filter' || type === 'all') {
                        instance.filterFields = [];
                    }

                    // 重新加载字段配置
                    this.loadModelFields(tableId);

                    // 显示成功消息
                    this.showMessage(__('配置已重置'), 'success');
                } else {
                    this.showMessage(response.message || __('重置失败'), 'error');
                }
            })
            .catch(error => {
                console.error('Clear config error:', error);
                this.showMessage(__('重置配置失败，请稍后重试'), 'error');
            })
            .finally(() => {
                container.classList.remove('loading');
            });
    },

    /**
     * 显示消息提示
     * @param {string} message 消息内容
     * @param {string} type 消息类型：success、error、warning、info
     */
    showMessage: function (message, type = 'info') {
        // 创建消息元素
        const alertClass = type === 'success' ? 'success' : type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'info';
        const messageElement = document.createElement('div');
        messageElement.className = `alert alert-${alertClass} alert-dismissible fade show`;
        messageElement.setAttribute('role', 'alert');
        messageElement.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        // 添加到页面顶部
        document.body.insertBefore(messageElement, document.body.firstChild);

        // 3秒后自动消失
        setTimeout(() => {
            if (messageElement.parentNode) {
                messageElement.style.opacity = '0';
                setTimeout(() => {
                    if (messageElement.parentNode) {
                        messageElement.parentNode.removeChild(messageElement);
                    }
                }, 300);
            }
        }, 3000);
    },

    /**
     * 切换字段配置展开/收起
     */
    toggleFieldConfig: function (tableId, fieldName, type) {
        const fieldItem = document.querySelector(`#w-${type}-fields-${tableId} .w-field-item[data-field="${fieldName}"]`);
        if (!fieldItem) return;

        const detailConfig = fieldItem.querySelector('.w-field-detail-config');
        const toggleBtn = fieldItem.querySelector('.w-btn-toggle-config');

        if (detailConfig.style.display === 'none') {
            detailConfig.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i> ' + __("收起");
            toggleBtn.classList.add('active');
        } else {
            detailConfig.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-cog"></i> ' + __("设置");
            toggleBtn.classList.remove('active');
        }
    },

    /**
     * 检查字段是否受保护（不允许配置、删除、更改）
     */
    isFieldProtected: function (field) {
        // 主键字段保护
        if (field.is_primary === true || field.primary === true) {
            return true;
        }

        // 模板定义的字段保?
        if (field.template_defined === true) {
            return true;
        }

        // field指定的字段保?
        if (field.field_defined === true || field.from_field === true) {
            return true;
        }

        // 检查字段名是否为常见主?
        const primaryKeyNames = ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'];
        if (primaryKeyNames.includes(field.name)) {
            return true;
        }

        // 检查data-属性中的字段定?
        if (field.dataset) {
            if (field.dataset.fieldDefined === 'true' ||
                field.dataset.templateDefined === 'true' ||
                field.dataset.fromField === 'true') {
                return true;
            }
        }

        return false;
    },

    /**
     * 获取默认显示字段（包含受保护的字段）
     */
    getDefaultDisplayFields: function (allFields) {
        const defaultFields = [];

        // 首先添加所有受保护的字?
        allFields.forEach(field => {
            if (this.isFieldProtected(field)) {
                defaultFields.push({ ...field, template_defined: true });
            }
        });

        // 然后添加其他字段（最?个）
        const remainingFields = allFields.filter(field => !this.isFieldProtected(field));
        const maxFields = Math.max(0, 8 - defaultFields.length);
        const additionalFields = remainingFields.slice(0, maxFields);

        return [...defaultFields, ...additionalFields];
    },

    // 在字段配置弹窗初始化时提取字段信息
    extractFieldsFromDOM: function (tableId, type) {
        const instance = this.instances[tableId];
        if (!instance) return [];

        const container = instance.container[0] || instance.container; // 确保是DOM元素
        let fields = [];

        if (type === 'display') {
            // 从表格头部提取字段
            const thElements = container.querySelectorAll('th[data-field]');
            thElements.forEach(function (th) {
                const fieldName = th.getAttribute('data-field');
                const dataWField = th.getAttribute('data-w-field');

                let fieldConfig = {
                    name: fieldName,
                    type: th.getAttribute('data-type') || 'text',
                    sortable: th.getAttribute('data-sortable') === 'true',
                    visible: th.getAttribute('data-visible') !== 'false',
                    editable: th.getAttribute('data-editable') === 'true',
                    searchable: th.getAttribute('data-searchable') === 'true',
                    resizable: th.getAttribute('data-resizable') === 'true',
                    width: th.getAttribute('data-width') || '',
                    min_width: th.getAttribute('data-min-width') || '',
                    max_width: th.getAttribute('data-max-width') || '',
                    placeholder: th.getAttribute('data-placeholder') || '',
                    options: th.getAttribute('data-options') || '',
                    class: th.getAttribute('data-class') || '',
                    style: th.getAttribute('data-style') || '',
                    formatter: th.getAttribute('data-formatter') || '',
                    validator: th.getAttribute('data-validator') || '',
                    default: th.getAttribute('data-default') || '',
                    belong: th.getAttribute('data-belong') || 't-header',
                    template_defined: th.getAttribute('data-template-defined') === 'true',
                    field_defined: th.getAttribute('data-field-defined') === 'true',
                    from_field: th.getAttribute('data-from-field') === 'true',
                    content: th.getAttribute('data-content') || fieldName,
                    label: th.getAttribute('data-content') || fieldName,
                    // 指定字段默认可以移动，除非明确设置为false
                    display_orderable: th.getAttribute('data-display-orderable') !== 'false' && th.getAttribute('data-display-orderable') !== '0'
                };

                // 如果有data-w-field属性，解析JSON配置
                if (dataWField) {
                    try {
                        const jsonConfig = JSON.parse(dataWField);
                        fieldConfig = { ...fieldConfig, ...jsonConfig };
                    } catch (e) {
                        console.warn('extractFieldsFromDOM: 解析data-w-field失败', e);
                    }
                }

                // 只提取模板定义的字段
                if (fieldConfig.template_defined || fieldConfig.field_defined || fieldConfig.from_field) {
                    fields.push(fieldConfig);
                }
            });
        } else if (type === 'filter') {
            // 从筛选器提取字段
            const filterElements = container.querySelectorAll('.filter-field[data-field]');
            filterElements.forEach(function (filter) {
                const fieldName = filter.getAttribute('data-field');
                const dataWField = filter.getAttribute('data-w-field');

                let fieldConfig = {
                    name: fieldName,
                    type: filter.getAttribute('data-type') || 'text',
                    visible: filter.getAttribute('data-visible') !== 'false',
                    searchable: filter.getAttribute('data-searchable') === 'true',
                    placeholder: filter.getAttribute('data-placeholder') || '',
                    options: filter.getAttribute('data-options') || '',
                    class: filter.getAttribute('data-class') || '',
                    style: filter.getAttribute('data-style') || '',
                    validator: filter.getAttribute('data-validator') || '',
                    default: filter.getAttribute('data-default') || '',
                    belong: filter.getAttribute('data-belong') || 't-filter',
                    template_defined: filter.getAttribute('data-template-defined') === 'true',
                    field_defined: filter.getAttribute('data-field-defined') === 'true',
                    from_field: filter.getAttribute('data-from-field') === 'true',
                    content: filter.getAttribute('data-content') || fieldName,
                    label: filter.getAttribute('data-content') || fieldName,
                    // 指定字段默认可以移动，除非明确设置为false
                    display_orderable: filter.getAttribute('data-display-orderable') !== 'false' && filter.getAttribute('data-display-orderable') !== '0'
                };

                // 如果有data-w-field属性，解析JSON配置
                if (dataWField) {
                    try {
                        const jsonConfig = JSON.parse(dataWField);
                        fieldConfig = { ...fieldConfig, ...jsonConfig };
                    } catch (e) {
                        console.warn('extractFieldsFromDOM: 解析data-w-field失败', e);
                    }
                }

                // 只提取模板定义的字段
                if (fieldConfig.template_defined || fieldConfig.field_defined || fieldConfig.from_field) {
                    fields.push(fieldConfig);
                }
            });
        }

        console.log('extractFieldsFromDOM: 提取到模板字段', type, fields);
        return fields;
    },

    // 修改渲染逻辑，固化field字段行为
    isFieldConfigLocked: function (field) {
        return field.field_defined === true || field.field_defined === 'true' || field.template_defined === true || field.template_defined === 'true';
    },

    // 在渲染按钮和输入时：
    // 例如隐藏按钮?
    // <button ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled style="display:none"' : ''}>
    // 例如排序按钮?
    // <button ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled style="display:none"' : ''}>
    // 其它输入?
    // <input ... ${DataTableManager.isFieldConfigLocked(field) ? 'disabled' : ''}>
    // 其余功能按配置设?

    // 判断字段是否允许隐藏
    isFieldHideAllowed: function (field) {
        // field_defined/template_defined字段永远不可隐藏
        if (field.field_defined === true || field.field_defined === 'true' || field.template_defined === true || field.template_defined === 'true') {
            return false;
        }
        // 其它字段按visible配置
        return field.visible !== false && field.visible !== 'false';
    },

    // 判断字段是否允许排序
    isFieldSortable: function (field) {
        return field.sortable === true || field.sortable === 'true';
    },

    // 判断字段是否允许编辑
    isFieldEditable: function (field) {
        return field.editable === true || field.editable === 'true';
    },

    // 渲染按钮和输入时严格按data-属性控?
    // 隐藏按钮?
    // <button ... ${DataTableManager.isFieldHideAllowed(field) ? '' : 'disabled style="display:none"'}>
    // 排序按钮?
    // <button ... ${DataTableManager.isFieldSortable(field) ? '' : 'disabled style="display:none"'}>
    // 编辑输入?
    // <input ... ${DataTableManager.isFieldEditable(field) ? '' : 'disabled'}>
    // 其它操作同理

    /**
     * 渲染筛选区域
     */
    renderFilter: function (tableId, fields) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const filterContainer = (instance.container[0] || instance.container).querySelector('.datatable-filter');
        if (!filterContainer) return;

        // 确保字段顺序正确
        const templateFields = fields.filter(field =>
            field.template_defined || field.field_defined || field.from_field
        );
        const userFields = fields.filter(field =>
            !field.template_defined && !field.field_defined && !field.from_field
        );
        const orderedFields = [...templateFields, ...userFields];

        let filterHtml = '';
        orderedFields.forEach(field => {
            const isProtected = this.isFieldProtected(field);
            const canSearch = isProtected ?
                (field.searchable === true || field.searchable === 'true') :
                (field.searchable !== false);

            if (canSearch) {
                const inputType = field.type === 'select' ? 'select' : 'text';
                const placeholder = field.placeholder || `请输入${field.label || field.name}`;

                if (inputType === 'select') {
                    const options = field.options ? field.options.split(',').map(opt => {
                        const [value, label] = opt.split(':');
                        return `<option value="${value}">${label || value}</option>`;
                    }).join('') : '';

                    filterHtml += `
                        <div class="filter-item" data-field="${field.name}">
                            <label>${field.label || field.name}:</label>
                            <select class="filter-input" data-field="${field.name}" placeholder="${placeholder}">
                                <option value="">${placeholder}</option>
                                ${options}
                            </select>
                        </div>`;
                } else {
                    filterHtml += `
                        <div class="filter-item" data-field="${field.name}">
                            <label>${field.label || field.name}:</label>
                            <input type="text" class="filter-input" data-field="${field.name}" placeholder="${placeholder}" />
                        </div>`;
                }
            }
        });

        filterContainer.innerHTML = filterHtml;

        // 绑定筛选事件
        filterContainer.querySelectorAll('.filter-input').forEach(input => {
            input.addEventListener('input', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });

            input.addEventListener('change', function () {
                const fieldName = this.getAttribute('data-field');
                const value = this.value;
                DataTableManager.applyFilter(tableId, fieldName, value);
            });
        });
    },

    /**
     * 排序表格
     */
    sortTable: function (tableId, fieldName) {
        const instance = this.instances[tableId];
        if (!instance) return;

        const field = instance.displayFields.find(f => f.name === fieldName);
        if (!field) return;

        const isProtected = this.isFieldProtected(field);
        const canSort = isProtected ?
            (field.sortable === true || field.sortable === 'true') :
            (field.sortable !== false);

        if (!canSort) {
            console.warn('sortTable: 字段不允许排序', fieldName);
            return;
        }

        // 切换排序方向
        const currentSort = instance.sorts[fieldName];
        const newSort = currentSort === 'asc' ? 'desc' : 'asc';

        // 清除其他字段的排?
        instance.sorts = {};
        instance.sorts[fieldName] = newSort;

        // 重新加载数据
        this.loadData(instance);
    },

    /**
     * 应用筛?
     */
    applyFilter: function (tableId, fieldName, value) {
        const instance = this.instances[tableId];
        if (!instance) return;

        if (value) {
            instance.filters[fieldName] = value;
        } else {
            delete instance.filters[fieldName];
        }

        // 重置到第一?
        instance.currentPage = 1;

        // 重新加载数据
        this.loadData(instance);
    },

    /**
     * 加载数据
     */
    loadData: function (instance) {
        // 这里应该实现数据加载逻辑
        // 根据instance.filters, instance.sorts, instance.currentPage等参?
        console.log('loadData: 加载数据', {
            filters: instance.filters,
            sorts: instance.sorts,
            page: instance.currentPage
        });
    },

    // 检查是否为模板字段
    isPrimaryOrIndexField: function (field) {
        return (
            field.is_primary === true ||
            field.primary === true ||
            field.primary_key === true ||
            field.pk === true ||
            ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name)
        );
    },

    /**
     * 加载模型字段配置（原始方法，会触发表格重新构建）
     */
    loadModelFields: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        if (!instance.apiUrl) {
            console.error('[DataTableManager] apiUrl未设置，无法加载字段配置');
            return;
        }

        // 1. 提取模板字段（field指定字段）
        const templateFields = this.extractFieldsFromDOM(tableId, 'display');
        const templateFilterFields = this.extractFieldsFromDOM(tableId, 'filter');
        console.log('loadModelFields: 模板字段', templateFields);
        console.log('loadModelFields: 模板筛选字段', templateFilterFields);
        instance.templateFields = templateFields;
        instance.templateFilterFields = templateFilterFields;

        console.log('loadModelFields: 开始加载字段配置', {
            tableId,
            model: instance.options.model,
            scope: instance.options.scope
        });

        // 2. 请求接口
        const isInFieldConfig = document.querySelector('#w-field-config-modal-' + tableId) !== null;
        if (instance.allFields && instance.allFields.length > 0) {
            // 如果有缓存数据，直接使用
            console.log('loadModelFields: 使用缓存的字段数据');
            if (isInFieldConfig) {
                this.renderModelFieldsFromData(tableId, {
                    all_fields: instance.allFields,
                    display_fields: instance.displayFields,
                    filter_fields: instance.filterFields
                });
            }
            return;
        }

        if (isInFieldConfig) {
            // 在字段配置弹窗中显示loading
            const availableFields = document.getElementById('w-available-fields-' + tableId);
            const availableFieldsFilter = document.getElementById('w-available-fields-filter-' + tableId);
            if (availableFields) {
                availableFields.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("加载中...") + '</div>';
            }
            if (availableFieldsFilter) {
                availableFieldsFilter.innerHTML = '<div class="w-text-center w-text-muted w-py-4"><i class="fas fa-spinner fa-spin"></i> ' + __("加载中...") + '</div>';
            }
        }

        fetch(instance.apiUrl + '/fields', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                table_id: tableId,
                model: instance.options.model,
                scope: instance.options.scope
            })
        })
            .then(response => response.json())
            .then(response => {
                // 3. 合并模板字段和接口字段
                let apiFields = (response.data && response.data.all_fields) ? response.data.all_fields : [];
                let mergedFields = this.mergeTemplateAndApiFields(templateFields, apiFields);
                // 合并filter字段
                let apiFilterFields = (response.data && response.data.filter_fields) ? response.data.filter_fields : [];
                let mergedFilterFields = this.mergeTemplateAndApiFields(templateFilterFields, apiFilterFields);

                // 4. 保护模板字段，确保它们始终在显示字段中
                let displayFields = response.data.display_fields || [];
                const templateFieldNames = new Set(templateFields.map(f => f.name));

                // 确保模板字段始终在显示字段中
                templateFields.forEach(templateField => {
                    const existingIndex = displayFields.findIndex(f => f.name === templateField.name);
                    if (existingIndex === -1) {
                        // 模板字段不在显示字段中，添加到开头
                        displayFields.unshift(templateField);
                    } else {
                        // 模板字段已存在，用模板字段替换（保护模板配置）
                        displayFields[existingIndex] = templateField;
                    }
                });

                // 5. 添加用户选择的字段（非模板字段）
                const userSelectedFields = displayFields.filter(field => !templateFieldNames.has(field.name));
                console.log('loadModelFields: 用户选择的字段', userSelectedFields);

                // 6. 处理受保护字段的配置
                displayFields = displayFields.map(field => {
                    const isProtected = this.isFieldProtected(field);
                    const isPrimaryOrIndex = field.is_primary === true || field.primary === true || field.primary_key === true || field.pk === true || ['id', 'ID', 'Id', 'primary', 'pk', 'primary_key', 'is_primary'].includes(field.name);
                    if (isProtected) {
                        // 主键/索引字段不能排序和移动
                        if (isPrimaryOrIndex) {
                            return {
                                ...field,
                                sortable: false,
                                editable: field.editable === true || field.editable === 'true',
                                searchable: field.searchable !== false,
                                resizable: field.resizable !== false,
                                visible: field.visible !== false,
                                display_orderable: false
                            };
                        }
                        // 其它受保护字段默认可以排序和移动
                        return {
                            ...field,
                            sortable: field.sortable !== false && field.sortable !== 'false',
                            editable: field.editable === true || field.editable === 'true',
                            searchable: field.searchable !== false,
                            resizable: field.resizable !== false,
                            visible: field.visible !== false,
                            display_orderable: field.display_orderable !== false && field.display_orderable !== 0 && field.display_orderable !== 'false' && field.display_orderable !== '0'
                        };
                    }
                    return field;
                });

                // 7. 确保指定字段排到前面
                const displayTemplateFields = displayFields.filter(field =>
                    field.template_defined || field.field_defined || field.from_field
                );
                const userFields = displayFields.filter(field =>
                    !field.template_defined && !field.field_defined && !field.from_field
                );

                // 重新排序：模板字段在前，用户字段在后
                displayFields = [...displayTemplateFields, ...userFields];

                // 8. 传递合并后的字段到渲染
                if (isInFieldConfig) {
                    this.renderModelFieldsFromData(tableId, {
                        all_fields: mergedFields,
                        display_fields: displayFields,
                        filter_fields: mergedFilterFields
                    });
                } else {
                    this.rebuildTableFromConfig(tableId, mergedFields, mergedFilterFields);
                }
            })
            .catch(error => {
                this.showError(tableId, error || __('获取字段失败'));
            });
    },

    /**
     * 初始化实时编辑功能（实例隔离）
     */
    initInlineEdit: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        const table = document.getElementById(tableId);
        if (!table) return;

        // 绑定单元格双击事件（使用实例命名空间）
        const dblClickHandler = (e) => {
            const cell = e.target.closest('td[data-editable="true"]');
            if (cell && !instance.editingState.isEditing) {
                this.startCellEdit(cell, tableId);
            }
        };
        table.addEventListener('dblclick', dblClickHandler);
        // 存储事件处理器，用于清理
        if (!instance.eventHandlers) instance.eventHandlers = {};
        instance.eventHandlers['dblclick'] = dblClickHandler;

        // 绑定键盘事件（使用实例命名空间，确保只处理当前实例的编辑）
        const keydownHandler = (e) => {
            if (instance.editingState.isEditing) {
                if (e.key === 'Enter') {
                    this.saveCellEdit(tableId);
                } else if (e.key === 'Escape') {
                    this.cancelCellEdit(tableId);
                }
            }
        };
        document.addEventListener('keydown', keydownHandler);
        instance.eventHandlers['keydown'] = keydownHandler;
    },

    /**
     * 开始单元格编辑（实例隔离）
     */
    startCellEdit: function (cell, tableId) {
        // 通过 cell 找到 tableId（如果未提供）
        if (!tableId) {
            const table = cell.closest('table, .w-datatable');
            if (table) {
                tableId = table.id || table.closest('[id]')?.id;
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance) {
            console.warn('DataTable instance not found for tableId:', tableId);
            return;
        }
        
        if (instance.editingState.isEditing) return;

        instance.editingState.isEditing = true;
        instance.editingState.currentCell = cell;
        instance.editingState.originalValue = cell.textContent.trim();
        instance.editingState.editingRow = cell.closest('tr');

        const fieldType = cell.getAttribute('data-field-type') || 'text';
        const fieldName = cell.getAttribute('data-field');

        // 创建编辑器
        const editor = this.createCellEditor(fieldType, instance.editingState.originalValue);

        // 替换单元格内容
        cell.innerHTML = '';
        cell.appendChild(editor);

        // 聚焦编辑器
        editor.focus();
        if (editor.select) editor.select();

        // 添加编辑状态样式
        cell.classList.add('editing');
    },

    /**
     * 创建单元格编辑器
     */
    createCellEditor: function (type, value) {
        let editor;

        switch (type) {
            case 'select':
                editor = document.createElement('select');
                editor.className = 'form-control form-control-sm';
                // 这里需要根据字段配置添加选项
                break;

            case 'textarea':
                editor = document.createElement('textarea');
                editor.className = 'form-control form-control-sm';
                editor.rows = 2;
                break;

            case 'number':
                editor = document.createElement('input');
                editor.type = 'number';
                editor.className = 'form-control form-control-sm';
                break;

            case 'date':
                editor = document.createElement('input');
                editor.type = 'date';
                editor.className = 'form-control form-control-sm';
                break;

            default:
                editor = document.createElement('input');
                editor.type = 'text';
                editor.className = 'form-control form-control-sm';
        }

        editor.value = value;

        // 绑定失焦事件
        editor.addEventListener('blur', () => {
            setTimeout(() => this.saveCellEdit(), 100);
        });

        return editor;
    },

    /**
     * 保存单元格编辑（实例隔离）
     */
    saveCellEdit: function (tableId) {
        // 通过当前编辑状态找到 tableId（如果未提供）
        if (!tableId) {
            for (const id in this.instances) {
                if (this.instances[id].editingState.isEditing) {
                    tableId = id;
                    break;
                }
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance || !instance.editingState.isEditing) return;

        const cell = instance.editingState.currentCell;
        if (!cell) return;
        
        const editor = cell.querySelector('input, select, textarea');
        const newValue = editor ? editor.value : '';

        if (newValue !== instance.editingState.originalValue) {
            // 发送保存请求
            this.saveCellValue(cell, newValue, tableId);
        } else {
            // 值未改变，直接恢复
            this.restoreCellContent(cell, instance.editingState.originalValue);
        }
        this.resetEditingState(tableId);
    },

    /**
     * 取消单元格编辑（实例隔离）
     */
    cancelCellEdit: function (tableId) {
        // 通过当前编辑状态找到 tableId（如果未提供）
        if (!tableId) {
            for (const id in this.instances) {
                if (this.instances[id].editingState.isEditing) {
                    tableId = id;
                    break;
                }
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance || !instance.editingState.isEditing) return;

        const cell = instance.editingState.currentCell;
        if (!cell) return;
        
        this.restoreCellContent(cell, instance.editingState.originalValue);
        this.resetEditingState(tableId);
    },

    /**
     * 恢复单元格内容
     */
    restoreCellContent: function (cell, value) {
        cell.innerHTML = value;
        cell.classList.remove('editing');
    },

    /**
     * 重置编辑状态（实例隔离）
     */
    resetEditingState: function (tableId) {
        const instance = this.instances[tableId];
        if (!instance) return;
        
        instance.editingState.isEditing = false;
        instance.editingState.currentCell = null;
        instance.editingState.originalValue = null;
        instance.editingState.editingRow = null;
    },

    /**
     * 保存单元格值到服务器（实例隔离）
     */
    saveCellValue: function (cell, newValue, tableId) {
        // 通过 cell 找到 tableId（如果未提供）
        if (!tableId) {
            const table = cell.closest('.w-datatable');
            if (table) {
                tableId = table.id;
            }
        }
        
        const instance = this.instances[tableId];
        if (!instance) {
            console.warn('DataTable instance not found for tableId:', tableId);
            return;
        }
        
        const row = cell.closest('tr');
        const recordId = row.getAttribute('data-id');
        const fieldName = cell.getAttribute('data-field');
        const model = instance.options.model || instance.container.getAttribute('data-model');

        // 显示保存状态
        cell.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        // 发送保存请求（使用实例的 API URL）
        fetch(instance.apiUrl + '/update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                model: model,
                id: recordId,
                data: {
                    [fieldName]: newValue
                }
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 保存成功
                    cell.innerHTML = newValue;
                    cell.classList.add('save-success');
                    setTimeout(() => cell.classList.remove('save-success'), 2000);
                } else {
                    // 保存失败
                    this.restoreCellContent(cell, instance.editingState.originalValue);
                    this.showError(tableId, data.message || __('保存失败'));
                }
            })
            .catch(error => {
                // 网络错误
                this.restoreCellContent(cell, instance.editingState.originalValue);
                this.showError(tableId, __('网络错误：%{1}', error.message));
            });
    }
    };
    
    // 将 DataTableManager 暴露到 window 上
    if (typeof window !== 'undefined') {
        window.DataTableManager = DataTableManager;
    }
}

// 全局事件委托，支持动态插入的字段设置按钮
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.w-btn[data-w-action="field-config"]');
    if (btn && window.DataTableManager) {
        const tableId = btn.getAttribute('data-table');
        if (tableId) {
            window.DataTableManager.openFieldConfig(tableId);
        }
    }
});

// 初始化下拉菜单功能（只在单例模式下执行一次）
if (typeof window !== 'undefined' && window.DataTableManager && !window.DataTableManager._initialized) {
    window.DataTableManager._initialized = true;
    
    // 初始化函数
    var initDataTableManager = function () {
        if (window.DataTableManager) {
            window.DataTableManager.initDropdowns();
            window.DataTableManager.initTheme();
            window.DataTableManager.initThemeConfig();
            window.DataTableManager.initImportantFlags();
            window.DataTableManager.loadThemeConfig();

            // 初始化所有表格的实时编辑功能
            document.querySelectorAll('.w-datatable[data-editable="true"]').forEach(table => {
                window.DataTableManager.initInlineEdit(table.id);
            });
        }
    };
    
    // 根据页面加载状态决定如何初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDataTableManager);
    } else {
        // 页面已加载，直接初始化
        initDataTableManager();
    }
}

// 自动翻译所有带data-w-i18n的元素
function applyI18n() {
    document.querySelectorAll('[data-w-i18n]').forEach(function (el) {
        var key = el.getAttribute('data-w-i18n');
        if (key && typeof window.__ === 'function') {
            el.innerText = __(key);
        }
    });
}
// 页面加载和每次弹窗渲染后都调用
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyI18n);
} else {
    applyI18n();
}
