/**
 * WeShop MiniCart 迷你购物车模块
 * 
 * Shopify 风格侧边抽屉购物车
 * 
 * 功能：
 * 1. Drawer 抽屉式打开/关闭动画
 * 2. AJAX 加载购物车内容
 * 3. 数量增减和删除商品
 * 4. 监听添加到购物车事件自动打开
 * 5. 实时更新购物车数量和小计
 * 
 * 事件：
 * - weshop:mini-cart:open - MiniCart 打开
 * - weshop:mini-cart:close - MiniCart 关闭
 * - weshop:mini-cart:loaded - MiniCart 数据加载完成
 * - weshop:mini-cart:updated - MiniCart 内容更新
 */
(function(window, document) {
    'use strict';
    
    // 声明依赖模块
    if (window.Weline) {
        Weline.declare('api');
    }
    
    /**
     * MiniCart 模块
     */
    const MiniCart = {
        // DOM 元素
        drawer: null,
        overlay: null,
        itemsContainer: null,
        loadingElement: null,
        emptyElement: null,
        footerElement: null,
        cartCountElements: [],
        subtotalElement: null,
        
        // 状态
        isOpen: false,
        isLoading: false,
        config: null,
        
        /**
         * 初始化
         */
        init() {
            // 获取配置
            this.config = window.__WelineMiniCartConfig || {
                position: 'right',
                autoOpen: true,
                api: {
                    items: '/cart/api/mini-items',
                    update: '/cart/api/update',
                    remove: '/cart/api/remove',
                },
                cart: {
                    count: 0,
                    subtotal: 0,
                },
            };
            
            // 获取 DOM 元素
            this.drawer = document.getElementById('mini-cart-drawer');
            this.overlay = document.getElementById('mini-cart-overlay');
            this.itemsContainer = document.getElementById('mini-cart-items');
            this.loadingElement = document.getElementById('mini-cart-loading');
            this.emptyElement = document.getElementById('mini-cart-empty');
            this.footerElement = document.querySelector('[data-cart-footer]');
            this.cartCountElements = document.querySelectorAll('[data-cart-count]');
            this.subtotalElement = document.querySelector('[data-mini-cart-subtotal]');
            
            if (!this.drawer) {
                // Drawer 不存在，可能在其他页面
                return;
            }
            
            // 绑定事件
            this.bindEvents();
            
            // 标记初始化完成
            this.drawer.dataset.initialized = 'true';
            
            console.log('[MiniCart] Initialized');
        },
        
        /**
         * 绑定事件
         */
        bindEvents() {
            // 全局点击事件委托
            document.addEventListener('click', (e) => {
                const target = e.target;
                
                // 打开 MiniCart
                if (target.closest('[data-action="open-mini-cart"]')) {
                    e.preventDefault();
                    this.open();
                    return;
                }
                
                // 关闭 MiniCart
                if (target.closest('[data-action="close-mini-cart"]') || target === this.overlay) {
                    e.preventDefault();
                    this.close();
                    return;
                }
                
                // 增加数量
                if (target.closest('[data-action="increase-qty"]')) {
                    e.preventDefault();
                    const btn = target.closest('[data-action="increase-qty"]');
                    const itemId = btn.dataset.itemId;
                    this.updateQuantity(itemId, 1);
                    return;
                }
                
                // 减少数量
                if (target.closest('[data-action="decrease-qty"]')) {
                    e.preventDefault();
                    const btn = target.closest('[data-action="decrease-qty"]');
                    const itemId = btn.dataset.itemId;
                    this.updateQuantity(itemId, -1);
                    return;
                }
                
                // 删除商品
                if (target.closest('[data-action="remove-item"]')) {
                    e.preventDefault();
                    const btn = target.closest('[data-action="remove-item"]');
                    const itemId = btn.dataset.itemId;
                    this.removeItem(itemId);
                    return;
                }
            });
            
            // ESC 键关闭
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                }
            });
            
            // 监听添加到购物车事件
            document.addEventListener('weshop:cart:added', (e) => {
                const detail = e.detail || {};
                
                // 更新数量徽章
                if (detail.cart_count !== undefined) {
                    this.updateCount(detail.cart_count);
                }
                
                // 自动打开 MiniCart
                if (this.config.autoOpen) {
                    this.open();
                } else {
                    // 不自动打开，但刷新数据
                    this.loadItems();
                }
            });
            
            // 监听购物车更新事件
            document.addEventListener('weshop:cart:updated', (e) => {
                const detail = e.detail || {};
                if (detail.cart_count !== undefined) {
                    this.updateCount(detail.cart_count);
                }
                if (detail.subtotal !== undefined) {
                    this.updateSubtotal(detail.subtotal, detail.subtotal_formatted);
                }
            });
            
            // 监听购物车移除事件
            document.addEventListener('weshop:cart:removed', (e) => {
                const detail = e.detail || {};
                if (detail.cart_count !== undefined) {
                    this.updateCount(detail.cart_count);
                }
                this.loadItems();
            });
        },
        
        /**
         * 打开 MiniCart
         */
        open() {
            if (!this.drawer || this.isOpen) return;
            
            // 添加打开类
            this.drawer.classList.add('is-open');
            
            // 显示遮罩
            if (this.overlay) {
                this.overlay.style.display = 'block';
                // 触发 reflow 以启动动画
                this.overlay.offsetHeight;
                this.overlay.classList.add('is-visible');
            }
            
            this.isOpen = true;
            
            // 禁用 body 滚动
            document.body.style.overflow = 'hidden';
            
            // 更新 ARIA 属性
            this.drawer.setAttribute('aria-hidden', 'false');
            const trigger = document.querySelector('[data-action="open-mini-cart"]');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'true');
            }
            
            // 加载最新数据
            this.loadItems();
            
            // 触发事件
            document.dispatchEvent(new CustomEvent('weshop:mini-cart:open', {
                detail: { miniCart: this }
            }));
        },
        
        /**
         * 关闭 MiniCart
         */
        close() {
            if (!this.drawer || !this.isOpen) return;
            
            // 移除打开类
            this.drawer.classList.remove('is-open');
            
            // 隐藏遮罩
            if (this.overlay) {
                this.overlay.classList.remove('is-visible');
                setTimeout(() => {
                    if (!this.isOpen) {
                        this.overlay.style.display = 'none';
                    }
                }, 300);
            }
            
            this.isOpen = false;
            
            // 恢复 body 滚动
            document.body.style.overflow = '';
            
            // 更新 ARIA 属性
            this.drawer.setAttribute('aria-hidden', 'true');
            const trigger = document.querySelector('[data-action="open-mini-cart"]');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
            
            // 触发事件
            document.dispatchEvent(new CustomEvent('weshop:mini-cart:close', {
                detail: { miniCart: this }
            }));
        },
        
        /**
         * 切换 MiniCart 状态
         */
        toggle() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },
        
        /**
         * 加载购物车商品列表
         */
        async loadItems() {
            if (this.isLoading || !this.itemsContainer) return;
            
            this.isLoading = true;
            this.setLoading(true);
            
            try {
                const apiUrl = this.config.api?.items || '/cart/api/mini-items';
                
                let response;
                if (window.Weline && window.Weline.Api) {
                    response = await Weline.Api.request(apiUrl, { method: 'GET' });
                } else {
                    const res = await fetch(apiUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    response = await res.json();
                }
                
                if (response.success) {
                    // 更新商品列表 HTML
                    if (response.html) {
                        this.itemsContainer.innerHTML = response.html;
                    }
                    
                    // 更新小计
                    if (response.totals) {
                        this.updateSubtotal(response.totals.subtotal, response.totals.subtotal_formatted);
                        this.updateCount(response.totals.count || 0);
                    }
                    
                    // 更新空状态显示
                    this.updateEmptyState(response.totals?.count || 0);
                    
                    // 触发事件
                    document.dispatchEvent(new CustomEvent('weshop:mini-cart:loaded', {
                        detail: {
                            items: response.items || [],
                            totals: response.totals || {},
                            html: response.html,
                        }
                    }));
                } else {
                    console.error('[MiniCart] Load failed:', response.message);
                }
            } catch (error) {
                console.error('[MiniCart] Load error:', error);
            } finally {
                this.isLoading = false;
                this.setLoading(false);
            }
        },
        
        /**
         * 更新商品数量
         * @param {string} itemId 购物车项 ID
         * @param {number} delta 数量变化（+1 或 -1）
         */
        async updateQuantity(itemId, delta) {
            if (this.isLoading) return;
            
            // 获取当前数量
            const item = this.itemsContainer.querySelector(`[data-item-id="${itemId}"]`);
            if (!item) return;
            
            const qtyElement = item.querySelector('.qty-value');
            const currentQty = parseInt(qtyElement?.textContent || '1', 10);
            const newQty = currentQty + delta;
            
            if (newQty <= 0) {
                // 数量为0，删除商品
                this.removeItem(itemId);
                return;
            }
            
            this.isLoading = true;
            item.style.opacity = '0.5';
            
            try {
                const apiUrl = this.config.api?.update || '/cart/api/update';
                
                let response;
                if (window.Weline && window.Weline.Api) {
                    response = await Weline.Api.request(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ item_id: itemId, quantity: newQty }),
                    });
                } else {
                    const res = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ item_id: itemId, quantity: newQty }),
                    });
                    response = await res.json();
                }
                
                if (response.success) {
                    // 更新显示
                    if (qtyElement) {
                        qtyElement.textContent = newQty;
                    }
                    
                    // 更新小计和数量
                    if (response.totals) {
                        this.updateSubtotal(response.totals.subtotal, response.totals.subtotal_formatted);
                        this.updateCount(response.totals.count || 0);
                    }
                    
                    // 触发事件
                    document.dispatchEvent(new CustomEvent('weshop:cart:updated', {
                        detail: {
                            item_id: itemId,
                            quantity: newQty,
                            cart_count: response.totals?.count,
                            subtotal: response.totals?.subtotal,
                            subtotal_formatted: response.totals?.subtotal_formatted,
                        }
                    }));
                } else {
                    console.error('[MiniCart] Update failed:', response.message);
                    // 可以显示错误提示
                }
            } catch (error) {
                console.error('[MiniCart] Update error:', error);
            } finally {
                this.isLoading = false;
                item.style.opacity = '';
            }
        },
        
        /**
         * 删除商品
         * @param {string} itemId 购物车项 ID
         */
        async removeItem(itemId) {
            if (this.isLoading) return;
            
            const item = this.itemsContainer.querySelector(`[data-item-id="${itemId}"]`);
            if (!item) return;
            
            this.isLoading = true;
            item.style.opacity = '0.5';
            
            try {
                const apiUrl = this.config.api?.remove || '/cart/api/remove';
                
                let response;
                if (window.Weline && window.Weline.Api) {
                    response = await Weline.Api.request(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ item_id: itemId }),
                    });
                } else {
                    const res = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ item_id: itemId }),
                    });
                    response = await res.json();
                }
                
                if (response.success) {
                    // 移除 DOM 元素（带动画）
                    item.style.transition = 'all 0.3s ease';
                    item.style.transform = 'translateX(100%)';
                    item.style.opacity = '0';
                    
                    setTimeout(() => {
                        item.remove();
                        
                        // 更新小计和数量
                        if (response.totals) {
                            this.updateSubtotal(response.totals.subtotal, response.totals.subtotal_formatted);
                            this.updateCount(response.totals.count || 0);
                            this.updateEmptyState(response.totals.count || 0);
                        }
                    }, 300);
                    
                    // 触发事件
                    document.dispatchEvent(new CustomEvent('weshop:cart:removed', {
                        detail: {
                            item_id: itemId,
                            cart_count: response.totals?.count,
                            subtotal: response.totals?.subtotal,
                        }
                    }));
                } else {
                    console.error('[MiniCart] Remove failed:', response.message);
                    item.style.opacity = '';
                }
            } catch (error) {
                console.error('[MiniCart] Remove error:', error);
                item.style.opacity = '';
            } finally {
                this.isLoading = false;
            }
        },
        
        /**
         * 更新购物车数量徽章
         * @param {number} count 商品数量
         */
        updateCount(count) {
            // 更新所有数量显示元素
            this.cartCountElements = document.querySelectorAll('[data-cart-count]');
            this.cartCountElements.forEach(el => {
                el.textContent = count > 99 ? '99+' : count;
                el.style.display = count > 0 ? '' : 'none';
                
                // 添加动画效果
                if (count > 0) {
                    el.classList.add('has-items');
                } else {
                    el.classList.remove('has-items');
                }
            });
            
            // 更新 Header 购物车标题中的数量
            const countBadge = document.querySelector('[data-cart-count-badge] [data-cart-count]');
            if (countBadge) {
                countBadge.textContent = count;
            }
        },
        
        /**
         * 更新小计显示
         * @param {number} subtotal 小计金额
         * @param {string} subtotalFormatted 格式化后的小计
         */
        updateSubtotal(subtotal, subtotalFormatted) {
            this.subtotalElement = document.querySelector('[data-mini-cart-subtotal]');
            if (this.subtotalElement && subtotalFormatted) {
                this.subtotalElement.textContent = subtotalFormatted;
            }
        },
        
        /**
         * 更新空状态显示
         * @param {number} count 商品数量
         */
        updateEmptyState(count) {
            this.emptyElement = document.getElementById('mini-cart-empty');
            this.footerElement = document.querySelector('[data-cart-footer]');
            
            if (count === 0) {
                // 显示空状态
                if (this.emptyElement) {
                    this.emptyElement.style.display = '';
                }
                if (this.footerElement) {
                    this.footerElement.style.display = 'none';
                }
            } else {
                // 隐藏空状态
                if (this.emptyElement) {
                    this.emptyElement.style.display = 'none';
                }
                if (this.footerElement) {
                    this.footerElement.style.display = '';
                }
            }
        },
        
        /**
         * 设置加载状态
         * @param {boolean} loading 是否加载中
         */
        setLoading(loading) {
            if (this.loadingElement) {
                this.loadingElement.style.display = loading ? 'flex' : 'none';
            }
            if (this.itemsContainer) {
                this.itemsContainer.dataset.loading = loading ? 'true' : 'false';
            }
        },
    };
    
    /**
     * 自动初始化
     */
    function autoInit() {
        MiniCart.init();
    }
    
    // 等待 DOM 加载完成
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        // DOM 已加载，延迟执行以确保其他脚本已运行
        setTimeout(autoInit, 0);
    }
    
    // 导出到全局
    window.MiniCart = MiniCart;
    
})(window, document);
