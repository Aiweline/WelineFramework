/**
 * WeShop 迷你购物车模块
 * 
 * 功能：
 * 1. 自动初始化迷你购物车
 * 2. 处理购物车打开/关闭
 * 3. 加载购物车内容
 * 4. 渲染购物车商品列表
 */
(function (window, document) {
    'use strict';

    let miniCartInitialized = false;

    function initMiniCart() {
        if (miniCartInitialized) {
            return;
        }

        const headerCart = document.querySelector('.header-cart');
        if (!headerCart) {
            return;
        }

        const miniCart = document.getElementById('mini-cart');
        const miniCartOverlay = document.getElementById('mini-cart-overlay');
        const miniCartClose = document.getElementById('mini-cart-close');
        const cartLink = headerCart.querySelector('.cart-link');

        if (!miniCart || !miniCartOverlay) {
            return;
        }

        // 打开购物车
        function openMiniCart() {
            headerCart.classList.add('mini-cart-open');
            miniCart.setAttribute('aria-hidden', 'false');
            miniCartOverlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            // 加载购物车内容
            if (window.Weline && window.Weline.Api) {
                loadMiniCartContent();
            }
        }

        // 关闭购物车
        function closeMiniCart() {
            headerCart.classList.remove('mini-cart-open');
            miniCart.setAttribute('aria-hidden', 'true');
            miniCartOverlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        // 加载购物车内容
        async function loadMiniCartContent() {
            try {
                if (!window.Weline || !window.Weline.Api) {
                    return;
                }

                // 这里可以调用API获取购物车内容
                // const cartData = await window.Weline.Api.request('/cart/mini', { method: 'GET' });
                // renderMiniCart(cartData);
            } catch (error) {
                console.error('加载购物车内容失败:', error);
            }
        }

        // 渲染购物车内容
        function renderMiniCart(cartData) {
            const miniCartItems = document.getElementById('mini-cart-items');
            const miniCartEmpty = document.getElementById('mini-cart-empty');
            const miniCartFooter = document.getElementById('mini-cart-footer');

            if (!miniCartItems || !miniCartEmpty) {
                return;
            }

            if (cartData && cartData.items && cartData.items.length > 0) {
                miniCartEmpty.style.display = 'none';
                miniCartItems.style.display = 'block';
                if (miniCartFooter) {
                    miniCartFooter.style.display = 'block';
                }
                // 渲染购物车商品列表
                // TODO: 实现商品列表渲染逻辑
            } else {
                miniCartEmpty.style.display = 'flex';
                miniCartItems.style.display = 'none';
                if (miniCartFooter) {
                    miniCartFooter.style.display = 'none';
                }
            }
        }

        // 绑定事件
        if (cartLink) {
            cartLink.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openMiniCart();
            });
        }

        if (miniCartClose) {
            miniCartClose.addEventListener('click', closeMiniCart);
        }

        if (miniCartOverlay) {
            miniCartOverlay.addEventListener('click', closeMiniCart);
        }

        // ESC键关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && headerCart.classList.contains('mini-cart-open')) {
                closeMiniCart();
            }
        });

        miniCartInitialized = true;
    }

    // 监听购物车模块declare事件
    function waitForCartModule() {
        if (window.Weline && window.Weline.declarer) {
            // 检查购物车模块是否已声明
            if (window.Weline.declarer.isDeclared('cart') || window.Weline.declarer.isDeclared('api')) {
                initMiniCart();
            } else {
                // 监听declare事件
                const originalDeclare = window.Weline.declarer.declare;
                if (originalDeclare) {
                    window.Weline.declarer.declare = function (...args) {
                        const result = originalDeclare.apply(this, args);
                        const moduleNames = Array.isArray(args[0]) ? args[0] : [args[0]];
                        if (moduleNames.includes('cart') || moduleNames.includes('api')) {
                            setTimeout(initMiniCart, 0);
                        }
                        return result;
                    };
                }
            }
        } else {
            // 如果Weline未加载，延迟重试
            setTimeout(waitForCartModule, 100);
        }
    }

    // 等待DOM和Weline加载
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForCartModule);
    } else {
        setTimeout(waitForCartModule, 0);
    }
})(window, document);
