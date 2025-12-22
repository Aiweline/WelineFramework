/**
 * Weline Default Theme - Backend JavaScript
 * 后端默认主题脚本
 */

(function() {
    'use strict';

    // ========================================
    // Admin Theme Manager - 后台主题管理
    // ========================================
    const AdminTheme = {
        storageKey: 'weline-admin-theme',
        
        init() {
            this.loadSavedTheme();
            this.bindEvents();
        },
        
        loadSavedTheme() {
            const saved = localStorage.getItem(this.storageKey);
            if (saved) {
                this.setTheme(saved);
            }
        },
        
        setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('data-bs-theme', theme);
            document.body.classList.remove('light', 'dark');
            document.body.classList.add(theme);
            localStorage.setItem(this.storageKey, theme);
            
            // 更新切换按钮图标
            document.querySelectorAll('[data-theme-toggle] i').forEach(icon => {
                icon.className = theme === 'dark' ? 'mdi mdi-weather-sunny' : 'mdi mdi-weather-night';
            });
        },
        
        toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            this.setTheme(next);
        },
        
        bindEvents() {
            document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.toggleTheme();
                });
            });
        }
    };

    // ========================================
    // Sidebar Manager - 侧边栏管理
    // ========================================
    const Sidebar = {
        storageKey: 'weline-admin-sidebar',
        
        init() {
            this.sidebar = document.querySelector('.admin-sidebar');
            this.main = document.querySelector('.admin-main');
            this.toggle = document.querySelector('.sidebar-toggle');
            this.overlay = null;
            
            if (this.sidebar) {
                this.loadSavedState();
                this.createOverlay();
                this.bindEvents();
            }
        },
        
        loadSavedState() {
            const collapsed = localStorage.getItem(this.storageKey) === 'collapsed';
            if (collapsed && window.innerWidth > 992) {
                this.collapse();
            }
        },
        
        createOverlay() {
            this.overlay = document.createElement('div');
            this.overlay.className = 'sidebar-overlay';
            this.overlay.style.cssText = `
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            `;
            document.body.appendChild(this.overlay);
        },
        
        bindEvents() {
            if (this.toggle) {
                this.toggle.addEventListener('click', () => this.toggleSidebar());
            }
            
            this.overlay.addEventListener('click', () => this.closeMobile());
            
            // 响应式处理
            window.addEventListener('resize', () => {
                if (window.innerWidth > 992) {
                    this.closeMobile();
                }
            });
            
            // ESC 键关闭
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.closeMobile();
            });
        },
        
        toggleSidebar() {
            if (window.innerWidth <= 992) {
                this.toggleMobile();
            } else {
                this.toggleCollapse();
            }
        },
        
        toggleCollapse() {
            if (this.sidebar.classList.contains('collapsed')) {
                this.expand();
            } else {
                this.collapse();
            }
        },
        
        collapse() {
            this.sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
            localStorage.setItem(this.storageKey, 'collapsed');
        },
        
        expand() {
            this.sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
            localStorage.setItem(this.storageKey, 'expanded');
        },
        
        toggleMobile() {
            if (this.sidebar.classList.contains('show')) {
                this.closeMobile();
            } else {
                this.openMobile();
            }
        },
        
        openMobile() {
            this.sidebar.classList.add('show');
            this.overlay.style.opacity = '1';
            this.overlay.style.visibility = 'visible';
            document.body.style.overflow = 'hidden';
        },
        
        closeMobile() {
            this.sidebar.classList.remove('show');
            this.overlay.style.opacity = '0';
            this.overlay.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
    };

    // ========================================
    // Dropdown Menu - 下拉菜单
    // ========================================
    const Dropdown = {
        init() {
            document.querySelectorAll('[data-dropdown]').forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.toggle(trigger);
                });
            });
            
            // 点击外部关闭
            document.addEventListener('click', () => this.closeAll());
        },
        
        toggle(trigger) {
            const menu = trigger.nextElementSibling;
            if (!menu) return;
            
            const isOpen = menu.classList.contains('show');
            this.closeAll();
            
            if (!isOpen) {
                menu.classList.add('show');
                trigger.setAttribute('aria-expanded', 'true');
            }
        },
        
        closeAll() {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
                const trigger = menu.previousElementSibling;
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }
    };

    // ========================================
    // Modal Manager - 模态框管理
    // ========================================
    const Modal = {
        init() {
            // 打开模态框
            document.querySelectorAll('[data-modal-open]').forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    const modalId = trigger.dataset.modalOpen;
                    this.open(modalId);
                });
            });
            
            // 关闭模态框
            document.querySelectorAll('[data-modal-close]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    if (modal) this.close(modal.id);
                });
            });
            
            // 点击遮罩关闭
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) this.close(modal.id);
                });
            });
            
            // ESC 键关闭
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    const openModal = document.querySelector('.modal.show');
                    if (openModal) this.close(openModal.id);
                }
            });
        },
        
        open(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.classList.add('show');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        },
        
        close(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    // ========================================
    // Tab Manager - 标签页管理
    // ========================================
    const Tabs = {
        init() {
            document.querySelectorAll('[data-tabs]').forEach(container => {
                const tabs = container.querySelectorAll('[data-tab]');
                const panels = container.querySelectorAll('[data-tab-panel]');
                
                tabs.forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        e.preventDefault();
                        const target = tab.dataset.tab;
                        
                        // 更新 tab 状态
                        tabs.forEach(t => t.classList.remove('active'));
                        tab.classList.add('active');
                        
                        // 更新 panel 状态
                        panels.forEach(panel => {
                            if (panel.dataset.tabPanel === target) {
                                panel.classList.add('active');
                                panel.style.display = 'block';
                            } else {
                                panel.classList.remove('active');
                                panel.style.display = 'none';
                            }
                        });
                    });
                });
            });
        }
    };

    // ========================================
    // Toast Notifications - 提示消息
    // ========================================
    window.AdminToast = {
        container: null,
        
        init() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'admin-toast-container';
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
            toast.className = `admin-toast admin-toast-${type}`;
            toast.style.cssText = `
                padding: 14px 18px;
                border-radius: var(--admin-border-radius, 0.5rem);
                background: var(--admin-bg-card, #fff);
                border-left: 4px solid;
                box-shadow: var(--admin-shadow-lg);
                animation: adminSlideIn 0.3s ease;
                display: flex;
                align-items: flex-start;
                gap: 12px;
            `;
            
            const colors = {
                success: '#10b981',
                warning: '#f59e0b',
                danger: '#ef4444',
                info: '#06b6d4'
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
                <span style="flex: 1; color: var(--admin-text);">${message}</span>
                <button style="background: none; border: none; cursor: pointer; color: var(--admin-text-muted); font-size: 1.25rem;" onclick="this.parentElement.remove()">
                    <i class="mdi mdi-close"></i>
                </button>
            `;
            
            this.container.appendChild(toast);
            
            if (duration > 0) {
                setTimeout(() => {
                    toast.style.animation = 'adminSlideOut 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, duration);
            }
        },
        
        success(message, duration) { this.show(message, 'success', duration); },
        warning(message, duration) { this.show(message, 'warning', duration); },
        error(message, duration) { this.show(message, 'danger', duration); },
        info(message, duration) { this.show(message, 'info', duration); }
    };

    // CSS Animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes adminSlideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes adminSlideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    // ========================================
    // Confirm Dialog - 确认对话框
    // ========================================
    window.AdminConfirm = {
        show(message, options = {}) {
            return new Promise((resolve) => {
                const {
                    title = '确认操作',
                    confirmText = '确定',
                    cancelText = '取消',
                    type = 'warning'
                } = options;
                
                const overlay = document.createElement('div');
                overlay.style.cssText = `
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: fadeIn 0.2s ease;
                `;
                
                overlay.innerHTML = `
                    <div style="
                        background: var(--admin-bg-card, #fff);
                        border-radius: var(--admin-border-radius-lg, 0.75rem);
                        padding: 24px;
                        max-width: 400px;
                        width: 90%;
                        box-shadow: var(--admin-shadow-lg);
                    ">
                        <h4 style="margin: 0 0 8px; font-size: 1.125rem; color: var(--admin-text);">${title}</h4>
                        <p style="margin: 0 0 20px; color: var(--admin-text-secondary); font-size: 0.9375rem;">${message}</p>
                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button class="admin-btn admin-btn-outline" data-action="cancel">${cancelText}</button>
                            <button class="admin-btn admin-btn-primary" data-action="confirm">${confirmText}</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                document.body.style.overflow = 'hidden';
                
                const close = (result) => {
                    overlay.remove();
                    document.body.style.overflow = '';
                    resolve(result);
                };
                
                overlay.querySelector('[data-action="confirm"]').addEventListener('click', () => close(true));
                overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => close(false));
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) close(false);
                });
            });
        }
    };

    // ========================================
    // Initialize - 初始化
    // ========================================
    document.addEventListener('DOMContentLoaded', () => {
        AdminTheme.init();
        Sidebar.init();
        Dropdown.init();
        Modal.init();
        Tabs.init();
    });

})();

