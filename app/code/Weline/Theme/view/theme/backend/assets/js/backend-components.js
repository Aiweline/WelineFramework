/**
 * Weline Framework - 后台全局组件
 * 包含 BackendToast、BackendConfirm 等全局工具
 * 
 * 使用方式：
 *   BackendToast.success('保存成功');
 *   BackendToast.error('操作失败');
 *   BackendToast.warning('请注意');
 *   BackendToast.info('提示信息');
 *   
 *   BackendConfirm.show('确定删除吗？').then(confirmed => { ... });
 */
(function(window, document) {
    'use strict';

    /**
     * i18n 翻译（优先使用 __WelineThemeConfig.i18n 或 Weline.i18n.translate）
     */
    function t(key, fallback) {
        const cfg = window.__WelineThemeConfig?.i18n;
        if (cfg && typeof cfg[key] === 'string') {
            return cfg[key];
        }
        if (window.Weline?.i18n?.translate) {
            return window.Weline.i18n.translate(key, {}) || fallback || key;
        }
        return fallback || key;
    }

    // ========================================
    // BackendToast - 后台通知提示
    // ========================================
    const BackendToast = {
        container: null,
        
        init() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'backend-toast-container';
                this.container.style.cssText = `
                    position: fixed;
                    top: 80px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    max-width: 360px;
                `;
                document.body.appendChild(this.container);
            }
        },
        
        defaultDuration: 10000,
        
        /**
         * 显示 Toast 消息
         * @param {string} message 消息内容
         * @param {string} type 类型 (success, warning, danger, info)
         * @param {number|Object} durationOrOptions 持续时间(ms)或配置对象
         * @param {number} durationOrOptions.duration 持续时间(ms)，默认 10s
         * @param {boolean} durationOrOptions.html 是否允许 HTML 内容，默认 false
         */
        show(message, type = 'info', durationOrOptions) {
            this.init();
            
            let duration = this.defaultDuration;
            let allowHtml = false;
            
            if (typeof durationOrOptions === 'object') {
                duration = durationOrOptions.duration !== undefined ? durationOrOptions.duration : this.defaultDuration;
                allowHtml = durationOrOptions.html === true;
            } else if (typeof durationOrOptions === 'number') {
                duration = durationOrOptions;
            }
            
            const toast = document.createElement('div');
            toast.className = `backend-toast backend-toast-${type}`;
            toast.style.cssText = `
                padding: 14px 18px;
                border-radius: var(--backend-border-radius, 0.5rem);
                background: var(--backend-color-card-bg, #fff);
                border-left: 4px solid;
                box-shadow: var(--backend-shadow-lg, 0 10px 15px -3px rgba(0, 0, 0, 0.1));
                animation: backendSlideIn 0.3s ease;
                display: flex;
                align-items: flex-start;
                gap: 12px;
            `;
            
            const colors = {
                success: 'var(--backend-color-success, #34c38f)',
                warning: 'var(--backend-color-warning, #f1b44c)',
                danger: 'var(--backend-color-danger, #f46a6a)',
                info: 'var(--backend-color-info, #50a5f1)'
            };
            const icons = {
                success: 'mdi-check-circle',
                warning: 'mdi-alert',
                danger: 'mdi-close-circle',
                info: 'mdi-information'
            };
            
            toast.style.borderLeftColor = colors[type] || colors.info;
            
            const messageContent = allowHtml ? message : this.escapeHtml(message);
            
            toast.innerHTML = `
                <i class="mdi ${icons[type] || icons.info}" style="color: ${colors[type]}; font-size: 1.25rem; flex-shrink: 0;"></i>
                <div style="flex: 1; color: var(--backend-color-text-primary, #212529); word-break: break-word;">${messageContent}</div>
                <button style="background: none; border: none; cursor: pointer; color: var(--backend-color-text-tertiary, #adb5bd); font-size: 1.25rem; padding: 0; line-height: 1; flex-shrink: 0;" onclick="this.parentElement.remove()">
                    <i class="mdi mdi-close"></i>
                </button>
            `;
            
            this.container.appendChild(toast);
            
            if (duration > 0) {
                setTimeout(() => {
                    toast.style.animation = 'backendSlideOut 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, duration);
            }
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * 显示成功消息
         * @param {string} message 消息内容
         * @param {number|Object} durationOrOptions 持续时间(ms)或配置对象 {duration, html}
         */
        success(message, durationOrOptions) { this.show(message, 'success', durationOrOptions); },
        
        /**
         * 显示警告消息
         * @param {string} message 消息内容
         * @param {number|Object} durationOrOptions 持续时间(ms)或配置对象 {duration, html}
         */
        warning(message, durationOrOptions) { this.show(message, 'warning', durationOrOptions); },
        
        /**
         * 显示错误消息
         * @param {string} message 消息内容
         * @param {number|Object} durationOrOptions 持续时间(ms)或配置对象 {duration, html}
         */
        error(message, durationOrOptions) { this.show(message, 'danger', durationOrOptions); },
        
        /**
         * 显示信息消息
         * @param {string} message 消息内容
         * @param {number|Object} durationOrOptions 持续时间(ms)或配置对象 {duration, html}
         */
        info(message, durationOrOptions) { this.show(message, 'info', durationOrOptions); }
    };

    // ========================================
    // BackendConfirm - 后台确认对话框
    // ========================================
    const BackendConfirm = {
        show(message, options = {}) {
            return new Promise((resolve) => {
                const {
                    title = t('confirm_action', '确认操作'),
                    confirmText = t('confirm', '确定'),
                    cancelText = t('cancel', '取消'),
                    type = 'warning'
                } = options;
                
                const overlay = document.createElement('div');
                overlay.className = 'backend-confirm-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: backendFadeIn 0.2s ease;
                `;
                
                const typeColors = {
                    warning: 'var(--backend-color-warning, #f1b44c)',
                    danger: 'var(--backend-color-danger, #f46a6a)',
                    info: 'var(--backend-color-info, #50a5f1)',
                    success: 'var(--backend-color-success, #34c38f)'
                };
                
                const borderColor = typeColors[type] || typeColors.warning;
                
                overlay.innerHTML = `
                    <div class="backend-confirm-dialog" style="
                        background: var(--backend-color-card-bg, #fff);
                        border-radius: var(--backend-border-radius-lg, 0.75rem);
                        padding: 24px;
                        max-width: 400px;
                        width: 90%;
                        box-shadow: var(--backend-shadow-lg, 0 10px 15px -3px rgba(0, 0, 0, 0.1));
                        border-top: 3px solid ${borderColor};
                    ">
                        <h4 style="margin: 0 0 8px; font-size: 1.125rem; color: var(--backend-color-text-primary, #212529);">${this.escapeHtml(title)}</h4>
                        <p style="margin: 0 0 20px; color: var(--backend-color-text-secondary, #6c757d); font-size: 0.9375rem;">${this.escapeHtml(message)}</p>
                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button class="backend-confirm-btn backend-confirm-btn-cancel" data-action="cancel" style="
                                padding: 8px 16px;
                                border: 1px solid var(--backend-color-border-default, #dee2e6);
                                border-radius: var(--backend-border-radius, 0.375rem);
                                background: var(--backend-color-bg-primary, #fff);
                                color: var(--backend-color-text-secondary, #6c757d);
                                cursor: pointer;
                                font-size: 0.875rem;
                                transition: all 0.2s ease;
                            ">${this.escapeHtml(cancelText)}</button>
                            <button class="backend-confirm-btn backend-confirm-btn-confirm" data-action="confirm" style="
                                padding: 8px 16px;
                                border: none;
                                border-radius: var(--backend-border-radius, 0.375rem);
                                background: var(--backend-color-primary, #556ee6);
                                color: var(--backend-color-text-inverse, #fff);
                                cursor: pointer;
                                font-size: 0.875rem;
                                transition: all 0.2s ease;
                            ">${this.escapeHtml(confirmText)}</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                document.body.style.overflow = 'hidden';
                
                const close = (result) => {
                    overlay.style.animation = 'backendFadeOut 0.2s ease';
                    setTimeout(() => {
                        overlay.remove();
                        document.body.style.overflow = '';
                    }, 200);
                    resolve(result);
                };
                
                overlay.querySelector('[data-action="confirm"]').addEventListener('click', () => close(true));
                overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => close(false));
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) close(false);
                });
                
                document.addEventListener('keydown', function escHandler(e) {
                    if (e.key === 'Escape') {
                        document.removeEventListener('keydown', escHandler);
                        close(false);
                    }
                });
            });
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * 显示输入对话框（替代 prompt()）
         * @param {Object} options 配置项
         * @param {string} options.title 标题
         * @param {string} options.message 提示信息
         * @param {string} options.placeholder 输入框占位符
         * @param {string} options.defaultValue 默认值
         * @param {string} options.confirmText 确认按钮文本
         * @param {string} options.cancelText 取消按钮文本
         * @param {string} options.type 类型 (info, warning, success, danger)
         * @returns {Promise<string|null>} 用户输入的值或 null（取消时）
         */
        showInput(options = {}) {
            return new Promise((resolve) => {
                const {
                    title = t('input', '输入'),
                    message = '',
                    placeholder = '',
                    defaultValue = '',
                    confirmText = t('confirm', '确定'),
                    cancelText = t('cancel', '取消'),
                    type = 'info'
                } = options;

                const overlay = document.createElement('div');
                overlay.className = 'backend-confirm-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: backendFadeIn 0.2s ease;
                `;

                const typeColors = {
                    warning: 'var(--backend-color-warning, #f1b44c)',
                    danger: 'var(--backend-color-danger, #f46a6a)',
                    info: 'var(--backend-color-info, #50a5f1)',
                    success: 'var(--backend-color-success, #34c38f)'
                };

                const borderColor = typeColors[type] || typeColors.info;

                overlay.innerHTML = `
                    <div class="backend-confirm-dialog" style="
                        background: var(--backend-color-card-bg, #fff);
                        border-radius: var(--backend-border-radius-lg, 0.75rem);
                        padding: 24px;
                        max-width: 480px;
                        width: 90%;
                        box-shadow: var(--backend-shadow-lg, 0 10px 15px -3px rgba(0, 0, 0, 0.1));
                        border-top: 3px solid ${borderColor};
                    ">
                        <h4 style="margin: 0 0 8px; font-size: 1.125rem; color: var(--backend-color-text-primary, #212529);">${this.escapeHtml(title)}</h4>
                        ${message ? `<p style="margin: 0 0 12px; color: var(--backend-color-text-secondary, #6c757d); font-size: 0.9375rem;">${this.escapeHtml(message)}</p>` : ''}
                        <input type="text" class="backend-input-value" value="${this.escapeHtml(defaultValue)}" placeholder="${this.escapeHtml(placeholder)}" style="
                            width: 100%;
                            padding: 10px 14px;
                            border: 1px solid var(--backend-color-border-default, #dee2e6);
                            border-radius: var(--backend-border-radius, 0.375rem);
                            background: var(--backend-color-input-bg, #fff);
                            color: var(--backend-color-text-primary, #333);
                            font-size: 0.9375rem;
                            margin-bottom: 20px;
                            box-sizing: border-box;
                            transition: border-color 0.2s ease;
                        ">
                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button class="backend-confirm-btn backend-confirm-btn-cancel" data-action="cancel" style="
                                padding: 8px 16px;
                                border: 1px solid var(--backend-color-border-default, #dee2e6);
                                border-radius: var(--backend-border-radius, 0.375rem);
                                background: var(--backend-color-bg-primary, #fff);
                                color: var(--backend-color-text-secondary, #6c757d);
                                cursor: pointer;
                                font-size: 0.875rem;
                                transition: all 0.2s ease;
                            ">${this.escapeHtml(cancelText)}</button>
                            <button class="backend-confirm-btn backend-confirm-btn-confirm" data-action="confirm" style="
                                padding: 8px 16px;
                                border: none;
                                border-radius: var(--backend-border-radius, 0.375rem);
                                background: var(--backend-color-primary, #556ee6);
                                color: var(--backend-color-text-inverse, #fff);
                                cursor: pointer;
                                font-size: 0.875rem;
                                transition: all 0.2s ease;
                            ">${this.escapeHtml(confirmText)}</button>
                        </div>
                    </div>
                `;

                document.body.appendChild(overlay);
                document.body.style.overflow = 'hidden';

                const inputEl = overlay.querySelector('.backend-input-value');
                inputEl.focus();
                inputEl.select();

                const close = (value) => {
                    overlay.style.animation = 'backendFadeOut 0.2s ease';
                    setTimeout(() => {
                        overlay.remove();
                        document.body.style.overflow = '';
                    }, 200);
                    resolve(value);
                };

                overlay.querySelector('[data-action="confirm"]').addEventListener('click', () => close(inputEl.value));
                overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => close(null));
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) close(null);
                });

                inputEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        close(inputEl.value);
                    }
                });

                document.addEventListener('keydown', function escHandler(e) {
                    if (e.key === 'Escape') {
                        document.removeEventListener('keydown', escHandler);
                        close(null);
                    }
                });

                inputEl.addEventListener('focus', function() {
                    this.style.borderColor = 'var(--backend-color-primary, #556ee6)';
                    this.style.boxShadow = 'var(--backend-focus-ring)';
                });
                inputEl.addEventListener('blur', function() {
                    this.style.borderColor = 'var(--backend-color-border-default, #dee2e6)';
                    this.style.boxShadow = 'none';
                });
            });
        }
    };

    // ========================================
    // BackendModal - 主题兼容弹窗（替代 Swal 等，统一使用主题变量）
    // ========================================
    const BackendModal = {
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * 单按钮提示弹窗（如加载失败、操作结果）
         * @param {string} title 标题
         * @param {string} message 正文
         * @param {Object} options 可选 { confirmText, type, icon }
         * @returns {Promise<void>}
         */
        alert(title, message = '', options = {}) {
            return new Promise((resolve) => {
                const {
                    confirmText = t('confirm', '确定'),
                    type = 'danger',
                    icon = 'mdi-close-circle'
                } = options;

                const typeColors = {
                    danger: 'var(--backend-color-danger, #f46a6a)',
                    warning: 'var(--backend-color-warning, #f1b44c)',
                    success: 'var(--backend-color-success, #34c38f)',
                    info: 'var(--backend-color-info, #50a5f1)'
                };
                const borderColor = typeColors[type] || typeColors.danger;

                const overlay = document.createElement('div');
                overlay.className = 'backend-modal-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 10001;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: backendFadeIn 0.2s ease;
                `;

                overlay.innerHTML = `
                    <div class="backend-modal-dialog" style="
                        background: var(--backend-color-card-bg);
                        border-radius: var(--backend-border-radius-lg, 0.75rem);
                        padding: 24px;
                        max-width: 400px;
                        width: 90%;
                        box-shadow: var(--backend-shadow-lg);
                        border-top: 4px solid ${borderColor};
                        text-align: center;
                    ">
                        <div style="margin-bottom: 16px;">
                            <i class="mdi ${icon}" style="font-size: 48px; color: ${borderColor};"></i>
                        </div>
                        <h4 style="margin: 0 0 8px; font-size: 1.125rem; color: var(--backend-color-text-primary);">${this.escapeHtml(title)}</h4>
                        ${message ? `<p style="margin: 0 0 20px; color: var(--backend-color-text-secondary); font-size: 0.9375rem;">${this.escapeHtml(message)}</p>` : ''}
                        <button class="backend-modal-btn" data-action="confirm" style="
                            padding: 8px 24px;
                            border: none;
                            border-radius: var(--backend-border-radius, 0.375rem);
                            background: var(--backend-color-primary);
                            color: var(--backend-color-text-inverse);
                            cursor: pointer;
                            font-size: 0.9375rem;
                            transition: all 0.2s ease;
                        ">${this.escapeHtml(confirmText)}</button>
                    </div>
                `;

                document.body.appendChild(overlay);
                document.body.style.overflow = 'hidden';

                const close = () => {
                    overlay.style.animation = 'backendFadeOut 0.2s ease';
                    setTimeout(() => {
                        overlay.remove();
                        document.body.style.overflow = '';
                        resolve();
                    }, 200);
                };

                overlay.querySelector('[data-action="confirm"]').addEventListener('click', close);
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) close();
                });
                document.addEventListener('keydown', function escHandler(e) {
                    if (e.key === 'Escape') {
                        document.removeEventListener('keydown', escHandler);
                        close();
                    }
                });
            });
        }
    };

    // ========================================
    // CSS 动画样式
    // ========================================
    (function injectStyles() {
        if (document.getElementById('backend-components-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'backend-components-styles';
        style.textContent = `
            @keyframes backendSlideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes backendSlideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            @keyframes backendFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes backendFadeOut {
                from { opacity: 1; }
                to { opacity: 0; }
            }
            
            .backend-confirm-btn:hover {
                filter: brightness(0.95);
            }
            .backend-confirm-btn-cancel:hover {
                background: var(--backend-color-bg-secondary, #f8f9fa) !important;
            }
            .backend-confirm-btn-confirm:hover {
                background: var(--backend-color-primary-hover, #4857d4) !important;
            }
        `;
        document.head.appendChild(style);
    })();

    // ========================================
    // 挂载到全局
    // ========================================
    window.BackendToast = BackendToast;
    window.BackendConfirm = BackendConfirm;
    window.BackendModal = BackendModal;

    // 向后兼容：保留 BackendToast 和 AdminConfirm 别名
    window.BackendToast = BackendToast;
    window.AdminConfirm = BackendConfirm;

})(window, document);
