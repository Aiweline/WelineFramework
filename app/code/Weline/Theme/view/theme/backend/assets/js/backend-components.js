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
        
        show(message, type = 'info', duration = 3000) {
            this.init();
            
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
            
            toast.innerHTML = `
                <i class="mdi ${icons[type] || icons.info}" style="color: ${colors[type]}; font-size: 1.25rem;"></i>
                <span style="flex: 1; color: var(--backend-color-text-primary, #212529);">${this.escapeHtml(message)}</span>
                <button style="background: none; border: none; cursor: pointer; color: var(--backend-color-text-tertiary, #adb5bd); font-size: 1.25rem; padding: 0; line-height: 1;" onclick="this.parentElement.remove()">
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
        
        success(message, duration) { this.show(message, 'success', duration); },
        warning(message, duration) { this.show(message, 'warning', duration); },
        error(message, duration) { this.show(message, 'danger', duration); },
        info(message, duration) { this.show(message, 'info', duration); }
    };

    // ========================================
    // BackendConfirm - 后台确认对话框
    // ========================================
    const BackendConfirm = {
        show(message, options = {}) {
            return new Promise((resolve) => {
                const {
                    title = '确认操作',
                    confirmText = '确定',
                    cancelText = '取消',
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
    
    // 向后兼容：保留 AdminToast 和 AdminConfirm 别名
    window.AdminToast = BackendToast;
    window.AdminConfirm = BackendConfirm;

})(window, document);
