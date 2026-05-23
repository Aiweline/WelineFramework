/**
 * WeShop Filters - 前端筛选控制器
 * 
 * 提供分类页面的筛选交互功能
 * 支持 AJAX 无刷新筛选和 URL 参数同步
 */
(function() {
    'use strict';
    
    /**
     * 筛选控制器类
     */
    class FilterController {
        constructor(options = {}) {
            this.options = Object.assign({
                // 容器选择器
                filterContainer: '.category-filter-mock',
                productContainer: '.product-list-container',
                paginationContainer: '.pagination-container',
                
                // API 端点 (格式: /module/controller/action)
                filterApiUrl: '/filters/filter',
                
                // 分类ID
                categoryId: 0,
                
                // 是否启用 AJAX
                enableAjax: true,
                
                // 是否更新 URL
                updateUrl: true,
                
                // 加载状态回调
                onLoading: null,
                onLoaded: null,
                onError: null,
                
                // 产品渲染回调
                renderProducts: null,
                renderPagination: null,
                
                // 保留的 URL 参数（这些属于路由/分页/排序，不应被识别为筛选维度）
                reservedParams: ['id', 'handle', 'q', 'page', 'page_size', 'limit', 'sort', 'order'],
            }, options);
            
            this.currentFilters = {};
            this.isLoading = false;
            this.filterApiPromise = null;
            
            this.init();
        }
        
        /**
         * 初始化
         */
        init() {
            console.log('[WeShop Filters] 初始化筛选控制器，配置:', this.options);
            
            // 解析当前 URL 中的筛选参数
            this.parseUrlParams();
            
            // 绑定事件
            this.bindEvents();
            
            // 初始化已选状态
            this.updateSelectedState();
            
            console.log('[WeShop Filters] 初始化完成，当前筛选:', this.currentFilters);
        }
        
        /**
         * 绑定事件
         */
        bindEvents() {
            const container = document.querySelector(this.options.filterContainer);
            if (!container) {
                console.warn('[WeShop Filters] 未找到筛选容器:', this.options.filterContainer);
                return;
            }
            
            console.log('[WeShop Filters] 绑定事件到容器:', container);
            
            // 筛选项点击
            container.addEventListener('click', (e) => {
                const filterItem = e.target.closest('.category-filter-item');
                if (filterItem) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.handleFilterClick(filterItem);
                    return;
                }
                
                // 清除单个筛选
                const chipRemove = e.target.closest('.chip-remove');
                if (chipRemove) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.handleChipRemove(chipRemove);
                    return;
                }
                
                // 清除所有筛选
                const clearAll = e.target.closest('.category-filter-clear');
                if (clearAll) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.clearAllFilters();
                    return;
                }
            });
            
            // 价格滑块变化
            container.addEventListener('input', (e) => {
                if (e.target.matches('.price-slider-min, .price-slider-max')) {
                    this.handlePriceSliderChange(e.target);
                }
            });
            
            // 价格滑块释放
            container.addEventListener('change', (e) => {
                if (e.target.matches('.price-slider-min, .price-slider-max')) {
                    this.applyPriceSlider();
                }
            });
            
            // 浏览器前进/后退
            window.addEventListener('popstate', (e) => {
                this.parseUrlParams();
                this.updateSelectedState();
                if (this.options.enableAjax) {
                    this.fetchFilteredProducts();
                }
            });
        }
        
        /**
         * 解析 URL 参数
         */
        parseUrlParams() {
            const params = new URLSearchParams(window.location.search);
            this.currentFilters = {};
            
            for (const [key, value] of params) {
                if (this.options.reservedParams.includes(key)) {
                    continue;
                }
                
                // 解析逗号分隔的多值
                if (value.includes(',')) {
                    this.currentFilters[key] = value.split(',');
                } else {
                    this.currentFilters[key] = [value];
                }
            }
        }
        
        /**
         * 处理筛选项点击
         */
        handleFilterClick(filterItem) {
            const filterCode = filterItem.dataset.filterCode || filterItem.closest('[data-filter-code]')?.dataset.filterCode;
            const filterValue = filterItem.dataset.value;
            
            console.log('[WeShop Filters] 点击筛选项:', { filterCode, filterValue, filterItem });
            
            if (!filterCode || !filterValue) {
                console.warn('[WeShop Filters] 筛选项缺少必要属性:', { filterCode, filterValue });
                return;
            }
            
            // 切换筛选值
            this.toggleFilter(filterCode, filterValue);
            
            console.log('[WeShop Filters] 当前筛选条件:', this.currentFilters);
            
            // 更新 URL 和获取结果
            this.applyFilters();
        }
        
        /**
         * 处理移除筛选标签
         */
        handleChipRemove(chipRemove) {
            const chip = chipRemove.closest('.filter-chip');
            const filterCode = chip?.dataset.filterCode;
            const filterValue = chip?.dataset.value;
            
            if (filterCode && filterValue) {
                this.removeFilter(filterCode, filterValue);
                this.applyFilters();
            }
        }
        
        /**
         * 切换筛选值
         */
        toggleFilter(filterCode, filterValue) {
            if (!this.currentFilters[filterCode]) {
                this.currentFilters[filterCode] = [];
            }
            
            const index = this.currentFilters[filterCode].indexOf(filterValue);
            if (index > -1) {
                this.currentFilters[filterCode].splice(index, 1);
                if (this.currentFilters[filterCode].length === 0) {
                    delete this.currentFilters[filterCode];
                }
            } else {
                this.currentFilters[filterCode].push(filterValue);
            }
        }
        
        /**
         * 添加筛选
         */
        addFilter(filterCode, filterValue) {
            if (!this.currentFilters[filterCode]) {
                this.currentFilters[filterCode] = [];
            }
            
            if (!this.currentFilters[filterCode].includes(filterValue)) {
                this.currentFilters[filterCode].push(filterValue);
            }
        }
        
        /**
         * 移除筛选
         */
        removeFilter(filterCode, filterValue) {
            if (!this.currentFilters[filterCode]) return;
            
            const index = this.currentFilters[filterCode].indexOf(filterValue);
            if (index > -1) {
                this.currentFilters[filterCode].splice(index, 1);
                if (this.currentFilters[filterCode].length === 0) {
                    delete this.currentFilters[filterCode];
                }
            }
        }
        
        /**
         * 清除所有筛选
         */
        clearAllFilters() {
            this.currentFilters = {};
            this.applyFilters();
        }
        
        /**
         * 应用筛选
         */
        applyFilters() {
            console.log('[WeShop Filters] 应用筛选:', this.currentFilters);
            
            // 更新 URL
            if (this.options.updateUrl) {
                const url = this.buildUrl();
                console.log('[WeShop Filters] 更新 URL:', url);
                this.updateUrl();
            }
            
            // 更新选中状态
            this.updateSelectedState();
            
            // AJAX 获取结果
            if (this.options.enableAjax) {
                console.log('[WeShop Filters] 发送 AJAX 请求');
                this.fetchFilteredProducts();
            } else {
                // 刷新页面
                console.log('[WeShop Filters] 刷新页面');
                window.location.href = this.buildUrl();
            }
        }
        
        /**
         * 更新 URL
         */
        updateUrl() {
            const url = this.buildUrl();
            window.history.pushState({ filters: this.currentFilters }, '', url);
        }
        
        /**
         * 构建 URL
         */
        buildUrl() {
            const params = new URLSearchParams(window.location.search);
            
            // 移除现有的筛选参数
            for (const key of Array.from(params.keys())) {
                if (!this.options.reservedParams.includes(key)) {
                    params.delete(key);
                }
            }
            
            // 重置页码
            params.delete('page');
            
            // 添加新的筛选参数
            for (const [key, values] of Object.entries(this.currentFilters)) {
                if (values.length > 0) {
                    params.set(key, values.join(','));
                }
            }
            
            const queryString = params.toString();
            const baseUrl = window.location.pathname;
            
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }
        
        /**
         * 更新选中状态
         */
        updateSelectedState() {
            const container = document.querySelector(this.options.filterContainer);
            if (!container) return;
            
            // 重置所有选中状态
            container.querySelectorAll('.category-filter-item').forEach(item => {
                item.classList.remove('is-active', 'is-selected');
            });
            
            // 设置当前选中状态
            for (const [filterCode, values] of Object.entries(this.currentFilters)) {
                values.forEach(value => {
                    const item = container.querySelector(
                        `.category-filter-item[data-filter-code="${filterCode}"][data-value="${value}"], ` +
                        `[data-filter-code="${filterCode}"] .category-filter-item[data-value="${value}"]`
                    );
                    if (item) {
                        item.classList.add('is-active', 'is-selected');
                    }
                });
            }
            
            // 更新已选筛选标签
            this.updateAppliedFilters();
        }
        
        /**
         * 更新已选筛选标签
         */
        updateAppliedFilters() {
            const container = document.querySelector('.category-filter-applied');
            if (!container) return;
            
            const chips = [];
            
            for (const [filterCode, values] of Object.entries(this.currentFilters)) {
                values.forEach(value => {
                    const label = this.getFilterLabel(filterCode, value);
                    chips.push(`
                        <span class="filter-chip is-active" data-filter-code="${filterCode}" data-value="${value}">
                            ${label}
                            <button type="button" class="chip-remove" aria-label="移除">×</button>
                        </span>
                    `);
                });
            }
            
            container.innerHTML = chips.join('');
            container.style.display = chips.length > 0 ? 'flex' : 'none';
        }
        
        /**
         * 获取筛选标签
         */
        getFilterLabel(filterCode, value) {
            const container = document.querySelector(this.options.filterContainer);
            const item = container?.querySelector(
                `.category-filter-item[data-filter-code="${filterCode}"][data-value="${value}"] .label, ` +
                `[data-filter-code="${filterCode}"] .category-filter-item[data-value="${value}"] .label`
            );
            return item?.textContent || value;
        }

        getFilterApi() {
            if (!this.filterApiPromise) {
                this.filterApiPromise = Promise.resolve(window.Weline.Api.resource('filter'));
            }

            return this.filterApiPromise;
        }
        
        /**
         * 获取筛选后的产品（AJAX）
         */
        async fetchFilteredProducts() {
            if (this.isLoading) return;
            
            this.isLoading = true;
            
            if (this.options.onLoading) {
                this.options.onLoading();
            }
            
            // 显示加载状态
            this.showLoadingState();
            
            try {
                const params = new URLSearchParams();
                params.set('category_id', this.options.categoryId);
                
                // 正确处理数组类型的筛选参数
                for (const [key, values] of Object.entries(this.currentFilters)) {
                    if (Array.isArray(values) && values.length > 0) {
                        params.set(key, values.join(','));
                    }
                }
                
                // 保留分页和排序参数
                const urlParams = new URLSearchParams(window.location.search);
                this.options.reservedParams.forEach(param => {
                    if (urlParams.has(param)) {
                        params.set(param, urlParams.get(param));
                    }
                });
                
                const filterPayload = {
                    category_id: parseInt(this.options.categoryId, 10) || 0,
                    filters: Object.assign({}, this.currentFilters),
                };
                params.forEach((value, key) => {
                    if (key !== 'category_id' && key !== 'filters') {
                        filterPayload[key] = value;
                    }
                });

                const result = await (await this.getFilterApi()).filter(filterPayload, {
                    silent: true,
                });
                
                if (result.success) {
                    this.handleFilterResult(result.data);
                } else {
                    throw new Error(result.message || '筛选失败');
                }
            } catch (error) {
                console.error('Filter error:', error);
                if (this.options.onError) {
                    this.options.onError(error);
                }
                // AJAX 失败时回退到页面刷新
                window.location.href = this.buildUrl();
            } finally {
                this.isLoading = false;
                this.hideLoadingState();
                if (this.options.onLoaded) {
                    this.options.onLoaded();
                }
            }
        }
        
        /**
         * 显示加载状态
         */
        showLoadingState() {
            const productContainer = document.querySelector(this.options.productContainer);
            if (productContainer) {
                productContainer.classList.add('is-loading');
            }
        }
        
        /**
         * 隐藏加载状态
         */
        hideLoadingState() {
            const productContainer = document.querySelector(this.options.productContainer);
            if (productContainer) {
                productContainer.classList.remove('is-loading');
            }
        }
        
        /**
         * 处理筛选结果
         */
        handleFilterResult(data) {
            this.lastPaginationTotal = data.pagination?.total;
            
            // 更新筛选选项和计数
            if (data.filters) {
                this.updateFilterOptions(data.filters);
            }
            
            // 更新产品列表（使用自定义回调或默认渲染）
            if (data.products) {
                if (this.options.renderProducts) {
                    this.options.renderProducts(data.products);
                } else {
                    this.defaultRenderProducts(data.products);
                }
            }
            
            // 更新分页
            if (this.options.renderPagination && data.pagination) {
                this.options.renderPagination(data.pagination);
            }
            
            // 更新结果数量
            this.updateResultCount(data.pagination?.total || 0);
        }
        
        /**
         * 默认产品列表渲染（AJAX 无刷新时用 JSON 数据填充 .category-products）
         */
        defaultRenderProducts(products) {
            const container = document.querySelector(this.options.productContainer);
            if (!container) return;
            
            const grid = container.querySelector('.products-grid, #product-grid, .category-products-grid');
            const countEl = container.querySelector('.products-count, .filter-result-count');
            const total = (this.lastPaginationTotal !== undefined && this.lastPaginationTotal !== null) ? this.lastPaginationTotal : products.length;
            
            if (countEl) {
                countEl.textContent = typeof window.__ === 'function'
                    ? window.__('共 %{count} 件商品', { count: total })
                    : (total + '');
            }
            
            if (!grid) return;
            
            if (products.length === 0) {
                const noProducts = typeof window.__ === 'function'
                    ? window.__('该分类下暂无商品')
                    : 'No products in this category';
                grid.innerHTML = '<div class="no-products-inline" style="grid-column:1/-1;text-align:center;padding:2rem;color:#6b7280;">' + noProducts + '</div>';
                return;
            }
            
            const html = products.map(p => {
                const name = (p.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                const price = parseFloat(p.price) || 0;
                const productUrl = this.buildProductUrl(p);
                const imgSrc = (p.image || '').replace(/"/g, '&quot;');
                const inStock = p.in_stock !== false && (p.stock || 0) > 0;
                const outOfStockLabel = typeof window.__ === 'function' ? window.__('缺货') : 'Out of stock';
                return (
                    '<div class="product-card" data-product-id="' + (p.product_id || '') + '">' +
                    '<div class="product-card-image">' +
                    '<a href="' + productUrl + '" class="product-image-link">' +
                    (imgSrc ? '<img src="' + imgSrc + '" alt="' + name + '" class="product-image" loading="lazy"/>' : '<div class="product-image-placeholder"><span>📷</span></div>') +
                    '</a>' +
                    (!inStock ? '<div class="product-badge out-of-stock">' + outOfStockLabel + '</div>' : '') +
                    '</div>' +
                    '<div class="product-card-info">' +
                    '<h3 class="product-name"><a href="' + productUrl + '">' + name + '</a></h3>' +
                    '<div class="product-price-row"><span class="product-price">' + (typeof window.formatConvertedCurrency === 'function' ? window.formatConvertedCurrency(price) : parseFloat(price).toFixed(2)) + '</span></div>' +
                    '</div></div>'
                );
            }).join('');
            
            grid.innerHTML = html;
        }
        
        /**
         * 产品详情链接：优先走框架 url()/frontend_url()，与模板 $this->getUrl() 一致。
         */
        buildProductUrl(product) {
            const handle = (product && product.handle) ? String(product.handle).trim() : '';
            const productId = product && product.product_id ? String(product.product_id) : '';
            if (typeof window.url === 'function') {
                if (handle) {
                    return window.url('product/' + handle);
                }
                if (productId) {
                    return window.url('weshop/product/view', { id: productId });
                }
            }
            if (typeof window.frontend_url === 'function') {
                if (handle) {
                    return window.frontend_url('product/' + handle);
                }
                if (productId) {
                    return window.frontend_url('weshop/product/view', { id: productId });
                }
            }
            const basePath = this.getProductBasePathFallback();
            if (handle) {
                return basePath + '/product/' + encodeURIComponent(handle);
            }
            return basePath + '/weshop/product/view?id=' + encodeURIComponent(productId);
        }

        /**
         * url()/frontend_url() 不可用时的兜底（沿用页内已有产品卡链接前缀）。
         */
        getProductBasePathFallback() {
            const firstLink = document.querySelector('.category-products .product-card a[href*="/product/"], .product-list-container .product-card a[href*="/product/"], .category-products-grid .product-card a[href*="/product/"]');
            if (firstLink && firstLink.href) {
                try {
                    const u = new URL(firstLink.href);
                    return u.origin + u.pathname.replace(/\/product\/[^/]+$/, '');
                } catch (e) {}
            }
            return window.location.origin + (document.body.dataset.basePath || '');
        }
        
        /**
         * 更新筛选选项
         */
        updateFilterOptions(filters) {
            const container = document.querySelector(this.options.filterContainer);
            if (!container) return;
            
            filters.forEach(filter => {
                const group = container.querySelector(`[data-filter-code="${filter.code}"]`);
                if (!group) return;
                
                const list = group.querySelector('.category-filter-list');
                if (!list) return;
                
                // 更新选项计数
                filter.options.forEach(option => {
                    const item = list.querySelector(`.category-filter-item[data-value="${option.value}"]`);
                    if (item) {
                        const countEl = item.querySelector('.count');
                        if (countEl) {
                            countEl.textContent = option.count;
                        }
                        
                        // 更新选中状态
                        if (option.selected) {
                            item.classList.add('is-active', 'is-selected');
                        } else {
                            item.classList.remove('is-active', 'is-selected');
                        }
                    }
                });
            });
        }
        
        /**
         * 更新结果数量
         */
        updateResultCount(total) {
            const countEl = document.querySelector('.filter-result-count');
            if (countEl && typeof window.__ === 'function') {
                countEl.textContent = total > 0
                    ? window.__('共 %{count} 件商品', { count: total })
                    : window.__('该分类下暂无商品');
            } else if (countEl) {
                countEl.textContent = total > 0 ? total + '' : '';
            }
        }
        
        /**
         * 处理价格滑块变化
         */
        handlePriceSliderChange(slider) {
            const container = slider.closest('.price-slider-container');
            if (!container) return;
            
            const minSlider = container.querySelector('.price-slider-min');
            const maxSlider = container.querySelector('.price-slider-max');
            const minDisplay = container.querySelector('.price-min-display');
            const maxDisplay = container.querySelector('.price-max-display');
            
            if (minSlider && maxSlider) {
                const minVal = parseInt(minSlider.value);
                const maxVal = parseInt(maxSlider.value);
                
                // 确保最小值不超过最大值
                if (slider === minSlider && minVal > maxVal) {
                    minSlider.value = maxVal;
                }
                if (slider === maxSlider && maxVal < minVal) {
                    maxSlider.value = minVal;
                }
                
                // 更新显示
                if (minDisplay) {
                    minDisplay.textContent = minSlider.value;
                }
                if (maxDisplay) {
                    maxDisplay.textContent = maxSlider.value;
                }
            }
        }
        
        /**
         * 应用价格滑块筛选
         */
        applyPriceSlider() {
            const container = document.querySelector('.price-slider-container');
            if (!container) return;
            
            const minSlider = container.querySelector('.price-slider-min');
            const maxSlider = container.querySelector('.price-slider-max');
            
            if (minSlider && maxSlider) {
                const minVal = minSlider.value;
                const maxVal = maxSlider.value;
                
                // 设置价格筛选
                this.currentFilters['price'] = [`${minVal}-${maxVal}`];
                this.applyFilters();
            }
        }
        
        /**
         * 检查是否有筛选条件
         */
        hasFilters() {
            return Object.keys(this.currentFilters).length > 0;
        }
        
        /**
         * 获取当前筛选条件
         */
        getFilters() {
            return { ...this.currentFilters };
        }
        
        /**
         * 设置筛选条件
         */
        setFilters(filters) {
            this.currentFilters = { ...filters };
            this.applyFilters();
        }
    }
    
    // 导出到全局
    window.WeShopFilters = {
        FilterController,
        
        /**
         * 初始化筛选控制器
         */
        init(options = {}) {
            return new FilterController(options);
        },
        
        /**
         * 自动初始化（用于确保在 JS 加载后初始化）
         */
        autoInit() {
            if (window.weShopFilterController) return;
            
            const filterContainer = document.querySelector('.category-filter-mock');
            if (filterContainer) {
                const categoryId = filterContainer.dataset.categoryId 
                    || document.body.dataset.categoryId 
                    || 0;
                const filterApiUrl = filterContainer.dataset.filterApiUrl || '/filters/filter';
                
                if (categoryId) {
                    window.weShopFilterController = WeShopFilters.init({
                        categoryId: parseInt(categoryId),
                        filterContainer: '.category-filter-mock',
                        productContainer: '.category-products',
                        filterApiUrl: filterApiUrl,
                        enableAjax: true,
                        updateUrl: true,
                    });
                    console.log('[WeShop Filters] 筛选控制器已初始化，分类ID:', categoryId);
                }
            }
        }
    };
    
    // 自动初始化 - 支持 DOMContentLoaded 和直接调用
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', WeShopFilters.autoInit);
    } else {
        // DOM 已加载，直接初始化
        WeShopFilters.autoInit();
    }
})();
