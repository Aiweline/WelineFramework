/**
 * Weline Default Theme - Frontend JavaScript
 * 前端默认主题脚本
 */

(function() {
    'use strict';

    // ========================================
    // Theme Manager - 主题管理
    // ========================================
    const ThemeManager = {
        storageKey: 'weline-theme',
        
        init() {
            this.loadSavedTheme();
            this.bindEvents();
        },
        
        loadSavedTheme() {
            const saved = localStorage.getItem(this.storageKey);
            if (saved) {
                this.setTheme(saved);
            } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                this.setTheme('dark');
            }
        },
        
        setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            document.body.classList.remove('light', 'dark');
            document.body.classList.add(theme);
            localStorage.setItem(this.storageKey, theme);
        },
        
        toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            this.setTheme(next);
        },
        
        bindEvents() {
            // 监听主题切换按钮
            document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
                btn.addEventListener('click', () => this.toggleTheme());
            });
            
            // 监听系统主题变化
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem(this.storageKey)) {
                    this.setTheme(e.matches ? 'dark' : 'light');
                }
            });
        }
    };

    // ========================================
    // Mobile Menu - 移动端菜单
    // ========================================
    const MobileMenu = {
        init() {
            this.toggle = document.querySelector('.mobile-menu-toggle');
            this.nav = document.querySelector('.main-nav');
            this.overlay = null;
            
            if (this.toggle && this.nav) {
                this.createOverlay();
                this.bindEvents();
            }
        },
        
        createOverlay() {
            this.overlay = document.createElement('div');
            this.overlay.className = 'mobile-menu-overlay';
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
            this.toggle.addEventListener('click', () => this.toggleMenu());
            this.overlay.addEventListener('click', () => this.closeMenu());
            
            // ESC 键关闭
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.closeMenu();
            });
        },
        
        toggleMenu() {
            const isOpen = this.nav.classList.contains('show');
            isOpen ? this.closeMenu() : this.openMenu();
        },
        
        openMenu() {
            this.nav.classList.add('show');
            this.nav.style.cssText = `
                display: flex;
                position: fixed;
                top: var(--theme-header-height, 64px);
                left: 0;
                right: 0;
                flex-direction: column;
                background: var(--theme-bg);
                padding: 1rem;
                border-bottom: 1px solid var(--theme-border);
                z-index: 1000;
            `;
            this.overlay.style.opacity = '1';
            this.overlay.style.visibility = 'visible';
            document.body.style.overflow = 'hidden';
        },
        
        closeMenu() {
            this.nav.classList.remove('show');
            this.nav.style.cssText = '';
            this.overlay.style.opacity = '0';
            this.overlay.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
    };

    // ========================================
    // Smooth Scroll - 平滑滚动
    // ========================================
    const SmoothScroll = {
        init() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', (e) => {
                    const href = anchor.getAttribute('href');
                    if (href === '#') return;
                    
                    const target = document.querySelector(href);
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
        }
    };

    // ========================================
    // Back to Top - 返回顶部
    // ========================================
    const BackToTop = {
        init() {
            this.button = document.querySelector('.back-to-top');
            if (!this.button) return;
            
            this.bindEvents();
        },
        
        bindEvents() {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    this.button.classList.add('show');
                } else {
                    this.button.classList.remove('show');
                }
            });
            
            this.button.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }
    };

    // ========================================
    // Lazy Loading - 图片懒加载
    // ========================================
    const LazyLoad = {
        init() {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            if (img.dataset.src) {
                                img.src = img.dataset.src;
                                img.removeAttribute('data-src');
                            }
                            observer.unobserve(img);
                        }
                    });
                });
                
                document.querySelectorAll('img[data-src]').forEach(img => {
                    observer.observe(img);
                });
            } else {
                // Fallback: 直接加载
                document.querySelectorAll('img[data-src]').forEach(img => {
                    img.src = img.dataset.src;
                });
            }
        }
    };

    // ========================================
    // Form Validation - 表单验证
    // ========================================
    const FormValidation = {
        init() {
            document.querySelectorAll('form[data-validate]').forEach(form => {
                form.addEventListener('submit', (e) => {
                    if (!this.validate(form)) {
                        e.preventDefault();
                    }
                });
            });
        },
        
        validate(form) {
            let isValid = true;
            
            // 清除之前的错误
            form.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });
            form.querySelectorAll('.invalid-feedback').forEach(el => {
                el.remove();
            });
            
            // 验证必填字段
            form.querySelectorAll('[required]').forEach(field => {
                if (!field.value.trim()) {
                    this.showError(field, '此字段为必填项');
                    isValid = false;
                }
            });
            
            // 验证邮箱
            form.querySelectorAll('[type="email"]').forEach(field => {
                if (field.value && !this.isValidEmail(field.value)) {
                    this.showError(field, '请输入有效的邮箱地址');
                    isValid = false;
                }
            });
            
            return isValid;
        },
        
        showError(field, message) {
            field.classList.add('is-invalid');
            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.textContent = message;
            feedback.style.cssText = 'color: var(--theme-danger); font-size: 0.875rem; margin-top: 0.25rem;';
            field.parentNode.appendChild(feedback);
        },
        
        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
    };

    // ========================================
    // Toast Notifications - 提示消息
    // ========================================
    window.Toast = {
        container: null,
        
        init() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'toast-container';
                this.container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                `;
                document.body.appendChild(this.container);
            }
        },
        
        show(message, type = 'info', duration = 3000) {
            this.init();
            
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.style.cssText = `
                padding: 12px 20px;
                border-radius: var(--theme-border-radius, 0.5rem);
                color: #fff;
                font-size: 0.875rem;
                box-shadow: var(--theme-shadow-lg);
                animation: slideIn 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            
            const colors = {
                success: '#22c55e',
                warning: '#f59e0b',
                danger: '#ef4444',
                info: '#06b6d4'
            };
            toast.style.backgroundColor = colors[type] || colors.info;
            
            toast.innerHTML = `<span>${message}</span>`;
            this.container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },
        
        success(message, duration) { this.show(message, 'success', duration); },
        warning(message, duration) { this.show(message, 'warning', duration); },
        error(message, duration) { this.show(message, 'danger', duration); },
        info(message, duration) { this.show(message, 'info', duration); }
    };

    // CSS Animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    // ========================================
    // Initialize - 初始化
    // ========================================
    document.addEventListener('DOMContentLoaded', () => {
        ThemeManager.init();
        MobileMenu.init();
        SmoothScroll.init();
        BackToTop.init();
        LazyLoad.init();
        FormValidation.init();
    });

})();

