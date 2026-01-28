/**
 * WeShop 购物车模块
 * 
 * 功能：
 * 1. 处理加入购物车操作
 * 2. 可配置产品规格选择弹窗
 * 3. 购物车状态更新事件
 * 
 * 事件：
 * - weshop:cart:add - 添加到购物车
 * - weshop:cart:added - 已添加到购物车
 * - weshop:cart:error - 添加失败
 * - weshop:cart:updated - 购物车已更新
 * - weshop:cart:options:show - 显示规格选择弹窗
 * - weshop:cart:options:hide - 隐藏规格选择弹窗
 */
(function (window, document) {
    'use strict';

    // API 端点
    const API_ENDPOINTS = {
        add: '/cart/frontend/api/add',
        getOptions: '/cart/frontend/api/add/getOptions',
    };

    // 状态
    let state = {
        currentProductId: null,
        optionsData: null,
        selectedOptions: {},
        qty: 1,
        isLoading: false,
    };

    // DOM 元素缓存
    let elements = {
        popup: null,
        productImage: null,
        productName: null,
        productPrice: null,
        originalPrice: null,
        productStock: null,
        selectedOptionsText: null,
        optionsContainer: null,
        qtyInput: null,
        addToCartBtn: null,
    };

    /**
     * 初始化模块
     */
    function init() {
        cacheElements();
        bindEvents();
        console.log('[WeShop Cart] Cart module initialized');
    }

    /**
     * 缓存DOM元素
     */
    function cacheElements() {
        elements.popup = document.getElementById('product-options-popup');
        if (elements.popup) {
            elements.productImage = elements.popup.querySelector('#popup-product-image');
            elements.productName = elements.popup.querySelector('#popup-product-name');
            elements.productPrice = elements.popup.querySelector('#popup-product-price');
            elements.originalPrice = elements.popup.querySelector('#popup-product-original-price');
            elements.productStock = elements.popup.querySelector('#popup-product-stock');
            elements.selectedOptionsText = elements.popup.querySelector('#popup-selected-options');
            elements.optionsContainer = elements.popup.querySelector('#popup-options-container');
            elements.qtyInput = elements.popup.querySelector('#popup-qty');
            elements.addToCartBtn = elements.popup.querySelector('#popup-add-to-cart');
        }
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        // 全局事件委托 - 加入购物车按钮
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-action="add-to-cart"]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                handleAddToCartClick(btn);
            }

            // 关闭弹窗
            if (e.target.closest('[data-action="close-options-popup"]')) {
                hideOptionsPopup();
            }

            // 数量增减
            if (e.target.closest('[data-action="qty-minus"]')) {
                adjustQty(-1);
            }
            if (e.target.closest('[data-action="qty-plus"]')) {
                adjustQty(1);
            }

            // 选项点击
            const optionBtn = e.target.closest('.option-value:not(.disabled)');
            if (optionBtn && elements.popup && elements.popup.contains(optionBtn)) {
                handleOptionClick(optionBtn);
            }
        });

        // 弹窗内加入购物车
        if (elements.addToCartBtn) {
            elements.addToCartBtn.addEventListener('click', handlePopupAddToCart);
        }

        // 数量输入框
        if (elements.qtyInput) {
            elements.qtyInput.addEventListener('change', function () {
                state.qty = Math.max(1, parseInt(this.value) || 1);
                this.value = state.qty;
            });
        }

        // ESC键关闭弹窗
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && elements.popup?.getAttribute('aria-hidden') === 'false') {
                hideOptionsPopup();
            }
        });

        // 监听自定义事件
        document.addEventListener('weshop:addToCart', function (e) {
            const { productId, qty } = e.detail || {};
            if (productId) {
                addToCart(productId, qty || 1);
            }
        });
    }

    /**
     * 处理加入购物车按钮点击
     */
    async function handleAddToCartClick(btn) {
        const productId = parseInt(btn.dataset.productId);
        const isConfigurable = btn.dataset.isConfigurable === '1';

        if (!productId) {
            console.warn('[WeShop Cart] Invalid product ID');
            return;
        }

        if (isConfigurable) {
            // 可配置产品 - 显示选项弹窗
            await showOptionsPopup(productId, btn);
        } else {
            // 简单产品 - 直接加入购物车
            await addToCart(productId, 1, [], btn);
        }
    }

    /**
     * 显示规格选择弹窗
     */
    async function showOptionsPopup(productId, triggerBtn = null) {
        if (!elements.popup) {
            console.warn('[WeShop Cart] Options popup element not found');
            // 尝试重新缓存元素
            cacheElements();
            if (!elements.popup) {
                // 回退到直接添加
                await addToCart(productId, 1, []);
                return;
            }
        }

        state.currentProductId = productId;
        state.selectedOptions = {};
        state.qty = 1;

        // 显示加载状态
        if (triggerBtn) {
            setButtonLoading(triggerBtn, true);
        }

        try {
            // 获取产品选项
            const response = await fetchProductOptions(productId);
            
            if (!response.success) {
                throw new Error(response.message || '获取产品信息失败');
            }

            if (!response.is_configurable) {
                // 不是可配置产品，直接添加
                if (triggerBtn) {
                    setButtonLoading(triggerBtn, false);
                }
                await addToCart(productId, 1, []);
                return;
            }

            state.optionsData = response.options;

            // 渲染弹窗内容
            renderPopupContent(response);

            // 显示弹窗
            elements.popup.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            // 触发事件
            dispatchEvent('weshop:cart:options:show', { productId, options: response.options });

        } catch (error) {
            console.error('[WeShop Cart] Failed to get product options:', error);
            showToast(error.message || '获取产品信息失败', 'error');
        } finally {
            if (triggerBtn) {
                setButtonLoading(triggerBtn, false);
            }
        }
    }

    /**
     * 隐藏规格选择弹窗
     */
    function hideOptionsPopup() {
        if (elements.popup) {
            elements.popup.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            dispatchEvent('weshop:cart:options:hide', { productId: state.currentProductId });
        }
        
        // 重置状态
        state.currentProductId = null;
        state.optionsData = null;
        state.selectedOptions = {};
        state.qty = 1;
    }

    /**
     * 渲染弹窗内容
     */
    function renderPopupContent(data) {
        const { product, options } = data;

        // 产品信息
        if (elements.productImage && product.image) {
            elements.productImage.src = product.image;
            elements.productImage.alt = product.name || '';
        }
        if (elements.productName) {
            elements.productName.textContent = product.name || '';
        }
        if (elements.productPrice) {
            elements.productPrice.textContent = formatPrice(product.price);
        }
        if (elements.originalPrice) {
            elements.originalPrice.style.display = 'none';
        }
        if (elements.productStock) {
            elements.productStock.textContent = '';
        }
        if (elements.selectedOptionsText) {
            elements.selectedOptionsText.textContent = '';
        }

        // 渲染选项
        if (elements.optionsContainer && options?.attributes) {
            elements.optionsContainer.innerHTML = renderOptionsHtml(options.attributes);
        }

        // 重置数量
        if (elements.qtyInput) {
            elements.qtyInput.value = 1;
        }

        // 禁用加入购物车按钮（需要选择所有选项）
        updateAddToCartButtonState();
    }

    /**
     * 渲染选项HTML
     */
    function renderOptionsHtml(attributes) {
        if (!attributes || !attributes.length) {
            return '<p class="no-options">' + __('暂无可选规格') + '</p>';
        }

        return attributes.map(attr => {
            const optionsHtml = attr.options.map(opt => {
                const swatchClass = opt.swatch_type ? `swatch-${opt.swatch_type}` : '';
                const swatchContent = renderSwatchContent(opt);
                const availableClass = opt.available_product_ids?.length ? '' : 'disabled';
                
                return `
                    <button type="button" 
                            class="option-value ${swatchClass} ${availableClass}"
                            data-attribute-id="${attr.attribute_id}"
                            data-option-id="${opt.option_id}"
                            data-available-products='${JSON.stringify(opt.available_product_ids || [])}'
                            title="${opt.value}">
                        ${swatchContent}
                    </button>
                `;
            }).join('');

            return `
                <div class="option-group" data-attribute-id="${attr.attribute_id}">
                    <div class="option-label">
                        <span>${attr.name}</span>
                        <span class="required">*</span>
                    </div>
                    <div class="option-values">
                        ${optionsHtml}
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * 渲染色块内容
     */
    function renderSwatchContent(option) {
        if (option.swatch_type === 'color' && option.swatch_value) {
            return `<span class="swatch-inner" style="background-color: ${option.swatch_value}"></span>`;
        }
        if (option.swatch_type === 'image' && option.swatch_value) {
            return `<img class="swatch-inner" src="${option.swatch_value}" alt="${option.value}">`;
        }
        return `<span class="text-value">${option.value}</span>`;
    }

    /**
     * 处理选项点击
     */
    function handleOptionClick(optionBtn) {
        const attributeId = optionBtn.dataset.attributeId;
        const optionId = parseInt(optionBtn.dataset.optionId);

        // 取消同组其他选项的选中状态
        const group = optionBtn.closest('.option-group');
        group.querySelectorAll('.option-value').forEach(btn => {
            btn.classList.remove('selected');
        });

        // 选中当前选项
        optionBtn.classList.add('selected');
        state.selectedOptions[attributeId] = optionId;

        // 更新其他选项的可用状态
        updateOptionsAvailability();

        // 更新显示
        updateSelectedDisplay();

        // 更新按钮状态
        updateAddToCartButtonState();
    }

    /**
     * 更新选项可用状态
     */
    function updateOptionsAvailability() {
        if (!state.optionsData?.variants) return;

        const selectedOptionIds = Object.values(state.selectedOptions);
        
        // 找出与当前选择兼容的变体
        const compatibleVariants = state.optionsData.variants.filter(variant => {
            return selectedOptionIds.every(optId => variant.option_ids.includes(optId));
        });

        // 获取兼容变体的所有选项ID
        const availableOptionIds = new Set();
        compatibleVariants.forEach(variant => {
            variant.option_ids.forEach(id => availableOptionIds.add(id));
        });

        // 更新选项按钮状态
        elements.optionsContainer.querySelectorAll('.option-value').forEach(btn => {
            const optionId = parseInt(btn.dataset.optionId);
            const attributeId = btn.dataset.attributeId;
            
            // 如果是当前已选择的属性组，不更新禁用状态
            if (state.selectedOptions[attributeId]) {
                return;
            }

            if (availableOptionIds.has(optionId)) {
                btn.classList.remove('disabled');
            } else {
                btn.classList.add('disabled');
            }
        });
    }

    /**
     * 更新已选显示
     */
    function updateSelectedDisplay() {
        if (!state.optionsData?.attributes) return;

        const selectedTexts = [];
        state.optionsData.attributes.forEach(attr => {
            const selectedOptionId = state.selectedOptions[attr.attribute_id];
            if (selectedOptionId) {
                const option = attr.options.find(o => o.option_id === selectedOptionId);
                if (option) {
                    selectedTexts.push(`${attr.name}: ${option.value}`);
                }
            }
        });

        if (elements.selectedOptionsText) {
            elements.selectedOptionsText.textContent = selectedTexts.join(' | ');
        }

        // 更新价格和库存
        const selectedVariant = findSelectedVariant();
        if (selectedVariant) {
            if (elements.productPrice) {
                elements.productPrice.textContent = formatPrice(selectedVariant.price);
            }
            if (elements.productStock) {
                if (selectedVariant.stock > 0) {
                    elements.productStock.textContent = __('库存: ') + selectedVariant.stock;
                    elements.productStock.className = 'product-stock in-stock';
                } else {
                    elements.productStock.textContent = __('缺货');
                    elements.productStock.className = 'product-stock out-of-stock';
                }
            }
            if (selectedVariant.image && elements.productImage) {
                elements.productImage.src = selectedVariant.image;
            }
        }
    }

    /**
     * 查找选中的变体
     */
    function findSelectedVariant() {
        if (!state.optionsData?.variants) return null;

        const selectedOptionIds = Object.values(state.selectedOptions).sort((a, b) => a - b);
        if (selectedOptionIds.length === 0) return null;

        return state.optionsData.variants.find(variant => {
            const variantOptionIds = [...variant.option_ids].sort((a, b) => a - b);
            return JSON.stringify(variantOptionIds) === JSON.stringify(selectedOptionIds);
        });
    }

    /**
     * 更新加入购物车按钮状态
     */
    function updateAddToCartButtonState() {
        if (!elements.addToCartBtn || !state.optionsData?.attributes) return;

        // 检查是否所有属性都已选择
        const allSelected = state.optionsData.attributes.every(
            attr => state.selectedOptions[attr.attribute_id]
        );

        // 检查选中的变体是否有库存
        const variant = findSelectedVariant();
        const inStock = variant ? variant.stock > 0 : false;

        elements.addToCartBtn.disabled = !allSelected || !inStock;
    }

    /**
     * 处理弹窗内加入购物车
     */
    async function handlePopupAddToCart() {
        if (!state.currentProductId || !state.optionsData) return;

        const selectedOptionIds = Object.values(state.selectedOptions);
        await addToCart(state.currentProductId, state.qty, selectedOptionIds, elements.addToCartBtn);
        
        // 成功后关闭弹窗
        hideOptionsPopup();
    }

    /**
     * 添加到购物车
     */
    async function addToCart(productId, qty = 1, selectedOptions = [], triggerBtn = null) {
        if (state.isLoading) return;
        
        state.isLoading = true;
        
        if (triggerBtn) {
            setButtonLoading(triggerBtn, true);
        }

        try {
            // 触发添加前事件
            dispatchEvent('weshop:cart:add', { productId, qty, selectedOptions });

            const response = await fetch(API_ENDPOINTS.add, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    product_id: productId,
                    qty: qty,
                    selected_options: JSON.stringify(selectedOptions),
                }),
            });

            const result = await response.json();

            if (result.success) {
                // 触发成功事件
                dispatchEvent('weshop:cart:added', {
                    productId,
                    qty,
                    selectedOptions,
                    cartItemId: result.cart_item_id,
                    cartCount: result.cart_count,
                    cartTotal: result.cart_total,
                    product: result.product,
                });

                // 更新购物车数量显示
                updateCartCount(result.cart_count);

                // 显示成功提示
                showToast(result.message || __('已加入购物车'), 'success');

                // 更新按钮文字
                if (triggerBtn) {
                    showButtonSuccess(triggerBtn);
                }

            } else if (result.requires_options) {
                // 需要选择选项
                state.optionsData = result.options;
                if (elements.popup) {
                    renderPopupContent({
                        product: { id: productId, name: '', price: 0, image: '' },
                        options: result.options,
                    });
                    elements.popup.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }
            } else {
                throw new Error(result.message || '添加购物车失败');
            }

        } catch (error) {
            console.error('[WeShop Cart] Add to cart failed:', error);
            
            // 触发错误事件
            dispatchEvent('weshop:cart:error', { productId, error: error.message });
            
            showToast(error.message || __('添加购物车失败'), 'error');
        } finally {
            state.isLoading = false;
            if (triggerBtn) {
                setButtonLoading(triggerBtn, false);
            }
        }
    }

    /**
     * 获取产品选项
     */
    async function fetchProductOptions(productId) {
        const response = await fetch(`${API_ENDPOINTS.getOptions}?product_id=${productId}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        return response.json();
    }

    /**
     * 调整数量
     */
    function adjustQty(delta) {
        if (elements.qtyInput) {
            const newQty = Math.max(1, (parseInt(elements.qtyInput.value) || 1) + delta);
            elements.qtyInput.value = newQty;
            state.qty = newQty;
        }
    }

    /**
     * 设置按钮加载状态
     */
    function setButtonLoading(btn, loading) {
        if (loading) {
            btn.classList.add('loading');
            btn.disabled = true;
        } else {
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    }

    /**
     * 显示按钮成功状态
     */
    function showButtonSuccess(btn) {
        const textEl = btn.querySelector('.btn-text');
        if (textEl) {
            const originalText = textEl.textContent;
            textEl.textContent = __('已添加');
            setTimeout(() => {
                textEl.textContent = originalText;
            }, 2000);
        }
    }

    /**
     * 更新购物车数量显示
     */
    function updateCartCount(count) {
        const countElements = document.querySelectorAll('.cart-count, [data-cart-count]');
        countElements.forEach(el => {
            el.textContent = count;
            el.style.display = count > 0 ? '' : 'none';
        });

        // 触发购物车更新事件
        dispatchEvent('weshop:cart:updated', { count });
    }

    /**
     * 显示提示消息
     */
    function showToast(message, type = 'info') {
        // 使用 Weline 的消息组件（如果可用）
        if (window.Weline?.Toast?.show) {
            window.Weline.Toast.show(message, type);
            return;
        }

        // 回退到简单的 alert
        if (type === 'error') {
            console.error('[WeShop Cart]', message);
        } else {
            console.log('[WeShop Cart]', message);
        }
    }

    /**
     * 格式化价格
     */
    function formatPrice(price) {
        const currency = window.Weline?.Config?.currency || 'CNY';
        const currencySymbol = { CNY: '¥', USD: '$', EUR: '€', GBP: '£' }[currency] || '¥';
        return currencySymbol + parseFloat(price).toFixed(2);
    }

    /**
     * 翻译函数
     */
    function __(text) {
        if (window.Weline?.i18n?.translate) {
            return window.Weline.i18n.translate(text);
        }
        return text;
    }

    /**
     * 分发自定义事件
     */
    function dispatchEvent(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { detail }));
    }

    // 导出公共API
    window.WeShopCart = {
        init,
        addToCart,
        showOptionsPopup,
        hideOptionsPopup,
        updateCartCount,
        getState: () => ({ ...state }),
    };

    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(window, document);
