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
        loadingStateHtml: '',
        emptyStateHtml: '',
        footerElement: null,
        cartCountElements: [],
        subtotalElement: null,
        
        // 状态
        isOpen: false,
        isLoading: false,
        config: null,
        cartApiPromise: null,
        hasLoadedItems: false,

        normalizeApiPayload(payload) {
            if (!payload || typeof payload !== 'object') {
                return payload || {};
            }

            if (!Object.prototype.hasOwnProperty.call(payload, 'code') || typeof payload.data !== 'object' || !payload.data) {
                return payload;
            }

            const code = Number(payload.code || 0);
            return Object.assign({
                code: code,
                message: payload.msg || payload.data.message || '',
                success: code >= 200 && code < 300 && payload.data.success !== false,
            }, payload.data);
        },

        translate(text) {
            if (window.Weline?.i18n?.translate) {
                return window.Weline.i18n.translate(text);
            }
            if (window.WelineI18n?.translate) {
                return window.WelineI18n.translate(text);
            }
            if (typeof window.__ === 'function') {
                return window.__(text);
            }
            return text;
        },

        defaultConfig() {
            return {
                position: 'right',
                autoOpen: true,
                cart: {
                    count: 0,
                    subtotal: 0,
                },
                labels: {
                    decreaseQty: this.translate('减少数量'),
                    increaseQty: this.translate('增加数量'),
                    removeItem: this.translate('删除商品'),
                    loading: this.translate('正在加载购物车...'),
                    emptyCart: this.translate('购物车是空的'),
                    startShopping: this.translate('开始购物'),
                    loadFailed: this.translate('购物车内容加载失败，请重试。'),
                    retry: this.translate('重试'),
                },
                icons: {},
            };
        },

        mergeConfig(config) {
            const defaults = this.defaultConfig();
            const incoming = config && typeof config === 'object' ? config : {};
            return Object.assign({}, defaults, incoming, {
                cart: Object.assign({}, defaults.cart, incoming.cart || {}),
                labels: Object.assign({}, defaults.labels, incoming.labels || {}),
                icons: Object.assign({}, defaults.icons, incoming.icons || {}),
            });
        },

        /**
         * 初始化
         */
        init() {
            // 获取配置
            this.config = this.mergeConfig(window.__WelineMiniCartConfig);
            
            // 获取 DOM 元素
            this.drawer = document.getElementById('mini-cart-drawer');
            this.overlay = document.getElementById('mini-cart-overlay');
            this.itemsContainer = document.getElementById('mini-cart-items');
            this.loadingElement = document.getElementById('mini-cart-loading');
            this.emptyElement = document.getElementById('mini-cart-empty');
            this.loadingStateHtml = this.loadingElement ? this.loadingElement.outerHTML : '';
            this.emptyStateHtml = this.emptyElement ? this.emptyElement.outerHTML : '';
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

        getCartApi() {
            if (!this.cartApiPromise) {
                if (window.WelineApiModule?.__full === true && typeof window.WelineApiModule.resource === 'function') {
                    this.cartApiPromise = Promise.resolve(window.WelineApiModule.resource('cart'));
                } else if (typeof window.Weline?.Api?.resource === 'function') {
                    this.cartApiPromise = Promise.resolve(window.Weline.Api.resource('cart'));
                } else {
                    return Promise.reject(new Error('Weline.Api.resource is not available'));
                }
            }
            return this.cartApiPromise;
        },

        normalizeCount(count) {
            const normalized = parseInt(String(count ?? 0).replace(/[^0-9]/g, ''), 10);
            return Number.isNaN(normalized) ? 0 : Math.max(0, normalized);
        },

        getCurrentCount() {
            let count = 0;
            let hasCountElement = false;

            document.querySelectorAll('[data-cart-count]').forEach(el => {
                hasCountElement = true;
                count = Math.max(count, this.normalizeCount(el.textContent));
            });

            if (!hasCountElement) {
                count = this.normalizeCount(this.config?.cart?.count);
                const hydrateRoot = document.querySelector('[data-weshop-cart-hydrate]');
                if (hydrateRoot?.dataset.cartCountSeed !== undefined) {
                    count = Math.max(count, this.normalizeCount(hydrateRoot.dataset.cartCountSeed));
                }
            }

            return count;
        },

        hasRenderedItems() {
            return !!this.itemsContainer?.querySelector('.mini-cart-item');
        },

        ensureEmptyStateElement() {
            this.emptyElement = document.getElementById('mini-cart-empty');
            if (!this.emptyStateHtml) {
                this.emptyStateHtml = this.renderEmptyState();
            }

            if (!this.emptyElement && this.itemsContainer && this.emptyStateHtml && !this.hasRenderedItems()) {
                const loadingElement = document.getElementById('mini-cart-loading');
                if (loadingElement) {
                    loadingElement.insertAdjacentHTML('beforebegin', this.emptyStateHtml);
                } else {
                    this.itemsContainer.insertAdjacentHTML('beforeend', this.emptyStateHtml);
                }
                this.emptyElement = document.getElementById('mini-cart-empty');
            }

            return this.emptyElement;
        },

        ensureLoadingElement() {
            this.loadingElement = document.getElementById('mini-cart-loading');
            if (!this.loadingStateHtml) {
                this.loadingStateHtml = this.renderLoadingState();
            }

            if (!this.loadingElement && this.itemsContainer && this.loadingStateHtml) {
                this.itemsContainer.insertAdjacentHTML('beforeend', this.loadingStateHtml);
                this.loadingElement = document.getElementById('mini-cart-loading');
            }

            return this.loadingElement;
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        sanitizeUrl(value) {
            const url = String(value ?? '').trim();
            if (url === '' || url === '#') {
                return '#';
            }
            if (url.startsWith('/') || /^https?:\/\//i.test(url)) {
                return url;
            }
            return '#';
        },

        sanitizeCssColor(value) {
            const color = String(value ?? '').trim();
            if (/^#[0-9a-f]{3,8}$/i.test(color)) {
                return color;
            }
            if (/^(rgb|rgba|hsl|hsla)\([0-9%,.\s-]+\)$/i.test(color)) {
                return color;
            }
            return '';
        },

        getIcon(name) {
            return String(this.config?.icons?.[name] || '');
        },

        getLabel(name) {
            return String(this.config?.labels?.[name] || '');
        },

        renderItems(items) {
            if (!Array.isArray(items) || items.length === 0) {
                return this.emptyStateHtml || this.renderEmptyState();
            }

            return items
                .filter(item => item && typeof item === 'object')
                .map(item => this.renderItem(item))
                .join('');
        },

        renderLoadingState() {
            return [
                '<div class="mini-cart-drawer__loading" id="mini-cart-loading" style="display:none;">',
                '<div class="mini-cart-drawer__spinner"></div>',
                `<p class="mini-cart-drawer__loading-text">${this.escapeHtml(this.getLabel('loading'))}</p>`,
                '</div>',
            ].join('');
        },

        renderEmptyState() {
            return [
                '<div class="mini-cart-items__empty" id="mini-cart-empty" style="display:none;">',
                '<div class="mini-cart-empty">',
                `<span class="mini-cart-empty__icon mini-cart-icon" aria-hidden="true">${this.getIcon('cart')}</span>`,
                `<p class="mini-cart-empty__message">${this.escapeHtml(this.getLabel('emptyCart'))}</p>`,
                `<a href="/" class="mini-cart-empty__link" data-action="close-mini-cart">${this.escapeHtml(this.getLabel('startShopping'))}</a>`,
                '</div>',
                '</div>',
            ].join('');
        },

        renderErrorState(message) {
            const errorMessage = this.escapeHtml(message || this.getLabel('loadFailed'));
            return [
                '<div class="mini-cart-error" role="alert">',
                `<p class="mini-cart-error__message">${errorMessage}</p>`,
                `<button type="button" class="mini-cart-error__retry" data-action="retry-mini-cart">${this.escapeHtml(this.getLabel('retry'))}</button>`,
                '</div>',
            ].join('');
        },

        formatItemOptions(item) {
            const directOptions = String(item.options || '').trim();
            if (directOptions !== '') {
                return directOptions;
            }

            const optionItems = Array.isArray(item.option_items) ? item.option_items : [];
            return optionItems
                .filter(option => option && typeof option === 'object')
                .map(option => {
                    const label = String(option.label || '').trim();
                    const value = String(option.value || '').trim();
                    if (value === '') {
                        return '';
                    }
                    return label !== '' ? `${label}: ${value}` : value;
                })
                .filter(Boolean)
                .join(' / ');
        },

        normalizeItemOptionItems(item) {
            const optionItems = Array.isArray(item.option_items) ? item.option_items : [];
            return optionItems
                .filter(option => option && typeof option === 'object')
                .map(option => ({
                    label: String(option.label || '').trim(),
                    value: String(option.value || '').trim(),
                    swatchType: String(option.swatch_type || '').trim().toLowerCase(),
                    swatchValue: String(option.swatch_value || '').trim(),
                }))
                .filter(option => option.value !== '');
        },

        renderOptionSwatch(option) {
            if (option.swatchType === 'color') {
                const color = this.sanitizeCssColor(option.swatchValue);
                if (color !== '') {
                    return `<span class="mini-cart-item__option-swatch mini-cart-item__option-swatch--color" style="background-color:${this.escapeHtml(color)}"></span>`;
                }
            }

            if (option.swatchType === 'image') {
                const image = this.sanitizeUrl(option.swatchValue);
                if (image !== '#') {
                    return `<span class="mini-cart-item__option-swatch mini-cart-item__option-swatch--image"><img src="${this.escapeHtml(image)}" alt="" loading="lazy"></span>`;
                }
            }

            return '';
        },

        renderItemOptions(item) {
            const optionItems = this.normalizeItemOptionItems(item);
            if (optionItems.length === 0) {
                const options = this.formatItemOptions(item);
                return options !== ''
                    ? `<div class="mini-cart-item__options">${this.escapeHtml(options)}</div>`
                    : '';
            }

            const optionsHtml = optionItems.map(option => {
                const label = option.label !== ''
                    ? `<span class="mini-cart-item__option-label">${this.escapeHtml(option.label)}:</span>`
                    : '';
                const swatch = this.renderOptionSwatch(option);
                return [
                    '<span class="mini-cart-item__option">',
                    label,
                    swatch,
                    `<span class="mini-cart-item__option-value">${this.escapeHtml(option.value)}</span>`,
                    '</span>',
                ].join('');
            }).join('<span class="mini-cart-item__option-separator">/</span>');

            return `<div class="mini-cart-item__options">${optionsHtml}</div>`;
        },

        renderItem(item) {
            const cartId = this.normalizeCount(item.cart_id ?? item.item_id);
            const productId = this.normalizeCount(item.product_id);
            const name = this.escapeHtml(item.name || '');
            const url = this.escapeHtml(this.sanitizeUrl(item.url || '#'));
            const image = this.sanitizeUrl(item.image || '');
            const price = this.escapeHtml(item.price_formatted || '');
            const quantity = Math.max(1, this.normalizeCount(item.quantity || 1));
            const optionsHtml = this.renderItemOptions(item);
            const imageHtml = image !== ''
                ? `<img src="${this.escapeHtml(image)}" alt="${name}" loading="lazy"/>`
                : `<div class="mini-cart-item__placeholder"><span class="mini-cart-icon" aria-hidden="true">${this.getIcon('image')}</span></div>`;
            const decreaseLabel = this.escapeHtml(this.getLabel('decreaseQty'));
            const increaseLabel = this.escapeHtml(this.getLabel('increaseQty'));
            const removeLabel = this.escapeHtml(this.getLabel('removeItem'));

            return [
                `<div class="mini-cart-item" data-item-id="${cartId}" data-product-id="${productId}">`,
                `<div class="mini-cart-item__image">${imageHtml}</div>`,
                '<div class="mini-cart-item__details">',
                `<a href="${url}" class="mini-cart-item__name">${name}</a>`,
                optionsHtml,
                `<div class="mini-cart-item__price">${price}</div>`,
                '<div class="mini-cart-item__qty">',
                `<button type="button" class="mini-cart-item__qty-btn" data-action="decrease-qty" data-item-id="${cartId}" aria-label="${decreaseLabel}">`,
                `<span class="mini-cart-icon" aria-hidden="true">${this.getIcon('minus')}</span>`,
                '</button>',
                `<span class="mini-cart-item__qty-value">${quantity}</span>`,
                `<button type="button" class="mini-cart-item__qty-btn" data-action="increase-qty" data-item-id="${cartId}" aria-label="${increaseLabel}">`,
                `<span class="mini-cart-icon" aria-hidden="true">${this.getIcon('plus')}</span>`,
                '</button>',
                '</div>',
                '</div>',
                `<button type="button" class="mini-cart-item__remove" data-action="remove-item" data-item-id="${cartId}" aria-label="${removeLabel}">`,
                `<span class="mini-cart-icon" aria-hidden="true">${this.getIcon('trash')}</span>`,
                '</button>',
                '</div>',
            ].join('');
        },

        showErrorState(message) {
            if (!this.itemsContainer) {
                return;
            }

            this.itemsContainer.innerHTML = this.renderErrorState(message);
            this.ensureLoadingElement();
            this.itemsContainer.dataset.needsRefresh = 'true';
            this.hasLoadedItems = false;
            if (this.footerElement) {
                this.footerElement.style.display = 'none';
            }
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

                if (target.closest('[data-action="retry-mini-cart"]')) {
                    e.preventDefault();
                    if (this.itemsContainer) {
                        this.itemsContainer.dataset.needsRefresh = 'true';
                    }
                    this.loadItems();
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
                if (this.itemsContainer) {
                    this.itemsContainer.dataset.needsRefresh = 'true';
                }
                
                // 更新数量徽章
                if (detail.cart_count !== undefined) {
                    this.updateCount(detail.cart_count);
                }
                
                // 自动打开 MiniCart
                if (this.config.autoOpen) {
                    this.open();
                }
            });
            
            // 监听购物车更新事件
            document.addEventListener('weshop:cart:updated', (e) => {
                const detail = e.detail || {};
                const rawCount = detail.cart_count ?? detail.count;
                const hasCount = rawCount !== undefined;
                const count = hasCount ? this.normalizeCount(rawCount) : null;

                if (hasCount) {
                    this.updateCount(count);
                    if (this.itemsContainer && count > 0 && !this.hasRenderedItems()) {
                        this.itemsContainer.dataset.needsRefresh = 'true';
                        if (this.isOpen) {
                            this.loadItems();
                        }
                    } else if (count === 0) {
                        this.updateEmptyState(0);
                    }
                }
                if (detail.subtotal !== undefined) {
                    this.updateSubtotal(detail.subtotal, detail.subtotal_formatted);
                }
            });
            
            // 监听购物车移除事件
            document.addEventListener('weshop:cart:removed', (e) => {
                const detail = e.detail || {};
                if (this.itemsContainer) {
                    this.itemsContainer.dataset.needsRefresh = 'true';
                }
                if (detail.cart_count !== undefined) {
                    this.updateCount(detail.cart_count);
                }
                if (this.isOpen && detail.source !== 'mini-cart') {
                    this.loadItems();
                }
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
            if (this.shouldLoadItemsOnOpen()) {
                this.loadItems();
            }
            
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
            const existingError = this.itemsContainer.querySelector('.mini-cart-error');
            if (existingError) {
                existingError.remove();
            }
            this.setLoading(true);
            
            try {
                const CartApi = await this.getCartApi();
                const raw = await CartApi.miniItems({});
                const response = this.normalizeApiPayload(raw);
                if (response.success) {
                    const items = Array.isArray(response.items) ? response.items : null;
                    const html = typeof response.html === 'string' ? response.html.trim() : '';
                    const totalCount = this.normalizeCount(response.totals?.count ?? response.cart_count ?? response.count ?? (items ? items.length : 0));

                    if (items !== null && items.length > 0) {
                        this.itemsContainer.innerHTML = this.renderItems(items);
                    } else if (html !== '') {
                        this.itemsContainer.innerHTML = html;
                    } else if (totalCount === 0) {
                        this.itemsContainer.innerHTML = this.renderItems([]);
                    } else {
                        this.showErrorState(response.message || this.getLabel('loadFailed'));
                        return;
                    }
                    this.ensureLoadingElement();
                    this.ensureEmptyStateElement();
                    this.itemsContainer.dataset.needsRefresh = 'false';
                    this.hasLoadedItems = true;
                    
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
                    this.showErrorState(response.message || this.getLabel('loadFailed'));
                }
            } catch (error) {
                console.error('[MiniCart] Load error:', error);
                this.showErrorState(error.message || this.getLabel('loadFailed'));
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
            
            const qtyElement = item.querySelector('.mini-cart-item__qty-value');
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
                const CartApi = await this.getCartApi();
                const raw = await CartApi.update({ item_id: parseInt(itemId, 10), quantity: newQty });
                const response = this.normalizeApiPayload(raw);
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
                            source: 'mini-cart',
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
                const CartApi = await this.getCartApi();
                const raw = await CartApi.remove({ item_id: parseInt(itemId, 10) });
                const response = this.normalizeApiPayload(raw);
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
                            source: 'mini-cart',
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
            const normalized = this.normalizeCount(count);
            // 更新所有数量显示元素
            this.cartCountElements = document.querySelectorAll('[data-cart-count]');
            this.cartCountElements.forEach(el => {
                el.textContent = normalized > 99 ? '99+' : normalized;

                const isHeaderBadge = el.classList.contains('cart-count') || !!el.closest('.header-cart-trigger');
                el.style.display = isHeaderBadge && normalized === 0 ? 'none' : '';
                
                // 添加动画效果
                if (normalized > 0) {
                    el.classList.add('has-items');
                } else {
                    el.classList.remove('has-items');
                }
            });
            
            // 更新 Header 购物车标题中的数量
            const countBadge = document.querySelector('[data-cart-count-badge] [data-cart-count]');
            if (countBadge) {
                countBadge.textContent = normalized;
                countBadge.style.display = '';
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
            this.footerElement = document.querySelector('[data-cart-footer]');

            const normalized = this.normalizeCount(count);

            if (normalized === 0) {
                const emptyElement = this.ensureEmptyStateElement();
                if (emptyElement) {
                    emptyElement.style.display = '';
                }
                if (this.footerElement) {
                    this.footerElement.style.display = 'none';
                }
            } else if (!this.hasRenderedItems()) {
                if (this.footerElement) {
                    this.footerElement.style.display = 'none';
                }
            } else {
                this.emptyElement = document.getElementById('mini-cart-empty');
                if (this.emptyElement) {
                    this.emptyElement.style.display = 'none';
                }
                if (this.footerElement) {
                    this.footerElement.style.display = '';
                }
            }
        },

        shouldLoadItemsOnOpen() {
            if (!this.itemsContainer) {
                return false;
            }

            if (this.itemsContainer.dataset.needsRefresh === 'true') {
                return true;
            }

            if (!this.hasLoadedItems) {
                return true;
            }

            return !this.hasRenderedItems() && !this.itemsContainer.querySelector('#mini-cart-empty');
        },
        
        /**
         * 设置加载状态
         * @param {boolean} loading 是否加载中
         */
        setLoading(loading) {
            const loadingElement = this.ensureLoadingElement();
            if (loadingElement) {
                loadingElement.style.display = loading ? 'flex' : 'none';
            }
            this.emptyElement = document.getElementById('mini-cart-empty');
            if (this.emptyElement && loading) {
                this.emptyElement.style.display = 'none';
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
