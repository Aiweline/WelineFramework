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
                
                // API 端点
                filterApiUrl: '/weshop/filters/ajax/filter',
                
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
                
                // 保留的 URL 参数
                reservedParams: ['page', 'limit', 'sort', 'order'],
            }, options);
            
            this.currentFilters = {};
            this.isLoading = false;
            
            this.init();
        }
        
        /**
         * 初始化
         */
        init() {
            // 解析当前 URL 中的筛选参数
            this.parseUrlParams();
            
            // 绑定事件
            this.bindEvents();
            
            // 初始化已选状态
            this.updateSelectedState();
        }
        
        /**
         * 绑定事件
         */
        bindEvents() {
            const container = document.querySelector(this.options.filterContainer);
            if (!container) return;
            
            // 筛选项点击
            container.addEventListener('click', (e) => {
                const filterItem = e.target.closest('.category-filter-item');
                if (filterItem) {
                    e.preventDefault();
                    this.handleFilterClick(filterItem);
                }
                
                // 清除单个筛选
                const chipRemove = e.target.closest('.chip-remove');
                if (chipRemove) {
                    e.preventDefault();
                    this.handleChipRemove(chipRemove);
                }
                
                // 清除所有筛选
                const clearAll = e.target.closest('.category-filter-clear');
                if (clearAll) {
                    e.preventDefault();
                    this.clearAllFilters();
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
            
            if (!filterCode || !filterValue) return;
            
            // 切换筛选值
            this.toggleFilter(filterCode, filterValue);
            
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
            // 更新 URL
            if (this.options.updateUrl) {
                this.updateUrl();
            }
            
            // 更新选中状态
            this.updateSelectedState();
            
            // AJAX 获取结果
            if (this.options.enableAjax) {
                this.fetchFilteredProducts();
            } else {
                // 刷新页面
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
        
        /**
         * 获取筛选后的产品（AJAX）
         */
        async fetchFilteredProducts() {
            if (this.isLoading) return;
            
            this.isLoading = true;
            
            if (this.options.onLoading) {
                this.options.onLoading();
            }
            
            try {
                const params = new URLSearchParams({
                    category_id: this.options.categoryId,
                    ...this.currentFilters,
                });
                
                // 保留分页和排序参数
                const urlParams = new URLSearchParams(window.location.search);
                this.options.reservedParams.forEach(param => {
                    if (urlParams.has(param)) {
                        params.set(param, urlParams.get(param));
                    }
                });
                
                const response = await fetch(`${this.options.filterApiUrl}?${params.toString()}`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                });
                
                const result = await response.json();
                
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
            } finally {
                this.isLoading = false;
                if (this.options.onLoaded) {
                    this.options.onLoaded();
                }
            }
        }
        
        /**
         * 处理筛选结果
         */
        handleFilterResult(data) {
            // 更新筛选选项和计数
            if (data.filters) {
                this.updateFilterOptions(data.filters);
            }
            
            // 更新产品列表
            if (this.options.renderProducts && data.products) {
                this.options.renderProducts(data.products);
            }
            
            // 更新分页
            if (this.options.renderPagination && data.pagination) {
                this.options.renderPagination(data.pagination);
            }
            
            // 更新结果数量
            this.updateResultCount(data.pagination?.total || 0);
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
            if (countEl) {
                countEl.textContent = total > 0 
                    ? `${total} 个产品`
                    : '没有找到产品';
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
    };
    
    // 自动初始化（如果存在筛选容器）
    document.addEventListener('DOMContentLoaded', () => {
        const filterContainer = document.querySelector('.category-filter-mock');
        if (filterContainer) {
            // 从容器或页面数据中获取分类ID
            const categoryId = filterContainer.dataset.categoryId 
                || document.body.dataset.categoryId 
                || 0;
            
            if (categoryId) {
                window.weShopFilterController = WeShopFilters.init({
                    categoryId: parseInt(categoryId),
                });
            }
        }
    });
})();
