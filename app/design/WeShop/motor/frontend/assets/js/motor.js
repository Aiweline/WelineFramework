/**
 * WeShop Motor Theme - Main JavaScript
 * 摩托车主题主 JavaScript 文件
 */
(function() {
    'use strict';

    // ========== Motor 主题配置 ==========
    var MotorTheme = {
        config: {
            theme: 'motor',
            animationDuration: 300,
            toastDuration: 3000
        },

        // ========== 初始化 ==========
        init: function() {
            this.initScrollEffects();
            this.initMobileMenu();
            this.initBackToTop();
            this.initLazyLoad();
        },

        // ========== 滚动效果 ==========
        initScrollEffects: function() {
            var header = document.querySelector('header');
            if (!header) return;

            var lastScroll = 0;
            var scrollThreshold = 100;

            window.addEventListener('scroll', function() {
                var currentScroll = window.pageYOffset;

                // 添加阴影效果
                if (currentScroll > 50) {
                    header.classList.add('shadow-lg');
                } else {
                    header.classList.remove('shadow-lg');
                }

                lastScroll = currentScroll;
            }, { passive: true });
        },

        // ========== 移动端菜单 ==========
        initMobileMenu: function() {
            var mobileMenuBtn = document.querySelector('[onclick*="motor-mobile-menu"]');
            var mobileMenu = document.getElementById('motor-mobile-menu');

            if (!mobileMenuBtn || !mobileMenu) return;

            // 创建遮罩层
            var overlay = document.createElement('div');
            overlay.className = 'motor-mobile-overlay fixed inset-0 bg-black/50 z-40 hidden opacity-0 transition-opacity duration-300';
            document.body.appendChild(overlay);

            // 点击遮罩关闭菜单
            overlay.addEventListener('click', function() {
                closeMobileMenu();
            });

            // 打开/关闭菜单
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (mobileMenu.classList.contains('hidden')) {
                    openMobileMenu();
                } else {
                    closeMobileMenu();
                }
            });

            function openMobileMenu() {
                mobileMenu.classList.remove('hidden');
                mobileMenu.style.maxHeight = '0';
                mobileMenu.style.overflow = 'hidden';
                requestAnimationFrame(function() {
                    mobileMenu.style.transition = 'max-height 0.3s ease';
                    mobileMenu.style.maxHeight = mobileMenu.scrollHeight + 'px';
                });
                overlay.classList.remove('hidden');
                requestAnimationFrame(function() {
                    overlay.classList.remove('opacity-0');
                });
                document.body.style.overflow = 'hidden';
            }

            function closeMobileMenu() {
                mobileMenu.style.maxHeight = '0';
                overlay.classList.add('opacity-0');
                setTimeout(function() {
                    mobileMenu.classList.add('hidden');
                    overlay.classList.add('hidden');
                    document.body.style.overflow = '';
                }, 300);
            }
        },

        // ========== 返回顶部 ==========
        initBackToTop: function() {
            var backToTopBtn = document.querySelector('a[href="#top"]');
            if (!backToTopBtn) return;

            backToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        },

        // ========== 图片懒加载 ==========
        initLazyLoad: function() {
            var lazyImages = document.querySelectorAll('img[data-src]');

            if ('IntersectionObserver' in window) {
                var imageObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var img = entry.target;
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            imageObserver.unobserve(img);
                        }
                    });
                }, {
                    rootMargin: '50px 0px'
                });

                lazyImages.forEach(function(img) {
                    imageObserver.observe(img);
                });
            } else {
                // Fallback for older browsers
                lazyImages.forEach(function(img) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                });
            }
        }
    };

    // ========== Toast 通知（前台） ==========
    var MotorToast = {
        container: null,

        init: function() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'motor-toast-container';
                this.container.style.cssText = 'position:fixed;top:100px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
                document.body.appendChild(this.container);
            }
        },

        show: function(message, type, duration) {
            type = type || 'info';
            duration = duration !== undefined ? duration : 3000;

            this.init();

            var toast = document.createElement('div');
            toast.className = 'motor-toast motor-toast-' + type;
            toast.textContent = message;
            toast.style.cssText = 'padding:12px 20px;border-radius:4px;font-size:14px;font-weight:600;pointer-events:auto;animation:motorSlideIn 0.3s ease;';

            // 根据类型设置颜色
            var colors = {
                success: { bg: '#28a745', color: '#fff' },
                error: { bg: '#dc3545', color: '#fff' },
                warning: { bg: '#f1b44c', color: '#fff' },
                info: { bg: '#e31837', color: '#fff' }
            };
            var c = colors[type] || colors.info;
            toast.style.backgroundColor = c.bg;
            toast.style.color = c.color;

            this.container.appendChild(toast);

            if (duration > 0) {
                setTimeout(function() {
                    toast.style.animation = 'motorSlideOut 0.3s ease';
                    setTimeout(function() {
                        toast.remove();
                    }, 300);
                }, duration);
            }

            return toast;
        },

        success: function(message, duration) {
            return this.show(message, 'success', duration);
        },

        error: function(message, duration) {
            return this.show(message, 'error', duration);
        },

        warning: function(message, duration) {
            return this.show(message, 'warning', duration);
        },

        info: function(message, duration) {
            return this.show(message, 'info', duration);
        }
    };

    // ========== CSS 动画 ==========
    var style = document.createElement('style');
    style.textContent = [
        '@keyframes motorSlideIn {',
        '    from { opacity: 0; transform: translateX(100%); }',
        '    to { opacity: 1; transform: translateX(0); }',
        '}',
        '@keyframes motorSlideOut {',
        '    from { opacity: 1; transform: translateX(0); }',
        '    to { opacity: 0; transform: translateX(100%); }',
        '}',
        '.motor-toast { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }'
    ].join('\n');
    document.head.appendChild(style);

    // ========== 暴露到全局 ==========
    window.MotorTheme = MotorTheme;
    window.MotorToast = MotorToast;

    // ========== DOM Ready ==========
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            MotorTheme.init();
        });
    } else {
        MotorTheme.init();
    }

})();
