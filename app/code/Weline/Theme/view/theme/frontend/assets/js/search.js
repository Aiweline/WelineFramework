/**
 * Weline Framework - 搜索模块
 * 
 * 提供搜索建议、搜索历史、键盘导航等功能
 * 
 * 使用方式：
 *   Weline.declare('search', true); // 声明并立即加载
 *   Weline.Search.init(); // 初始化搜索功能
 */

(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.Weline && window.Weline.Search && window.Weline.Search.__initialized) {
        return;
    }

    /**
     * 搜索管理器
     */
    function SearchManager() {
        this.searchWrapper = null;
        this.searchInput = null;
        this.searchForm = null;
        this.suggestionsContainer = null;
        this.suggestionsList = null;
        this.selectedSuggestionIndex = -1;
        this.searchTimeout = null;
        this.isInitialized = false;
    }

    SearchManager.prototype = {
        /**
         * 初始化搜索功能
         */
        init: function () {
            if (this.isInitialized) {
                return;
            }

            // 等待DOM加载完成
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.setup());
            } else {
                this.setup();
            }
        },

        /**
         * 设置搜索功能
         */
        setup: function () {
            this.searchWrapper = document.querySelector('.header-search-wrapper');
            if (!this.searchWrapper) {
                return;
            }

            this.searchInput = this.searchWrapper.querySelector('.search-input');
            this.searchForm = this.searchWrapper.querySelector('.header-search-form');
            this.suggestionsContainer = this.searchWrapper.querySelector('.search-suggestions');
            this.suggestionsList = this.suggestionsContainer?.querySelector('.search-suggestions-list');

            if (!this.searchInput || !this.suggestionsContainer) {
                return;
            }

            this.setupEventListeners();
            this.isInitialized = true;
        },

        /**
         * 设置事件监听器
         */
        setupEventListeners: function () {
            // Focus 时显示建议容器
            this.searchInput.addEventListener('focus', () => {
                this.searchWrapper.classList.add('active');
                this.suggestionsContainer.setAttribute('aria-hidden', 'false');
                this.loadSearchHistory();
            });

            // Blur 时延迟隐藏（允许点击建议项）
            this.searchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!this.suggestionsContainer.matches(':hover') && !this.searchWrapper.matches(':hover')) {
                        this.searchWrapper.classList.remove('active');
                        this.suggestionsContainer.setAttribute('aria-hidden', 'true');
                        this.selectedSuggestionIndex = -1;
                    }
                }, 200);
            });

            // 输入时更新建议
            this.searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                const query = e.target.value.trim();
                this.selectedSuggestionIndex = -1;

                if (query.length > 0) {
                    this.searchTimeout = setTimeout(() => {
                        this.updateSearchSuggestions(query, this.suggestionsList);
                    }, 300);
                } else {
                    this.loadSearchHistory();
                }
            });

            // 键盘导航：上下箭头键选择建议
            this.searchInput.addEventListener('keydown', (e) => {
                const suggestions = this.suggestionsList?.querySelectorAll('.search-suggestions-item a');
                if (!suggestions || suggestions.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.selectedSuggestionIndex = (this.selectedSuggestionIndex + 1) % suggestions.length;
                    this.highlightSuggestion(suggestions, this.selectedSuggestionIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.selectedSuggestionIndex = this.selectedSuggestionIndex <= 0 
                        ? suggestions.length - 1 
                        : this.selectedSuggestionIndex - 1;
                    this.highlightSuggestion(suggestions, this.selectedSuggestionIndex);
                } else if (e.key === 'Enter' && this.selectedSuggestionIndex >= 0) {
                    e.preventDefault();
                    const selectedSuggestion = suggestions[this.selectedSuggestionIndex];
                    if (selectedSuggestion) {
                        this.selectSuggestion(selectedSuggestion);
                    }
                } else if (e.key === 'Escape') {
                    this.searchWrapper.classList.remove('active');
                    this.suggestionsContainer.setAttribute('aria-hidden', 'true');
                    this.selectedSuggestionIndex = -1;
                }
            });

            // 点击建议项
            if (this.suggestionsList) {
                this.suggestionsList.addEventListener('click', (e) => {
                    const suggestionLink = e.target.closest('.search-suggestions-item a');
                    if (suggestionLink) {
                        e.preventDefault();
                        this.selectSuggestion(suggestionLink);
                    }
                });
            }

            // 表单提交时保存搜索历史
            if (this.searchForm) {
                this.searchForm.addEventListener('submit', (e) => {
                    const query = this.searchInput.value.trim();
                    if (query.length > 0) {
                        this.saveSearchHistory(query);
                    }
                });
            }

            // 快捷键 Ctrl+K / Cmd+K
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    this.searchInput.focus();
                }
            });

            // 点击外部关闭搜索建议
            document.addEventListener('click', (e) => {
                if (this.searchWrapper && !this.searchWrapper.contains(e.target)) {
                    this.searchWrapper.classList.remove('active');
                    if (this.suggestionsContainer) {
                        this.suggestionsContainer.setAttribute('aria-hidden', 'true');
                    }
                    this.selectedSuggestionIndex = -1;
                }
            });
        },

        /**
         * 更新搜索建议
         */
        updateSearchSuggestions: function (query, container) {
            if (!container) return;
            container.innerHTML = '';

            // 触发自定义事件，允许其他模块提供搜索建议
            const customEvent = new CustomEvent('weline:search-suggestions-request', {
                detail: { query: query, container: container },
                bubbles: true,
                cancelable: true
            });
            document.dispatchEvent(customEvent);

            // 如果事件被阻止（有模块处理了），则不使用默认建议
            if (customEvent.defaultPrevented) {
                return;
            }

            // 默认：调用 API 获取搜索建议
            this.fetchSearchSuggestions(query, container);
        },

        /**
         * 从 API 获取搜索建议
         */
        fetchSearchSuggestions: function (query, container) {
            // 尝试从配置中获取搜索建议 API 地址
            const searchApiUrl = (window.Weline && window.Weline.config && window.Weline.config.searchSuggestionsApi) 
                || window.welineConfig?.searchSuggestionsApi 
                || '/search/suggestions';
            
            // 使用 fetch API 获取建议
            fetch(`${searchApiUrl}?q=${encodeURIComponent(query)}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                // 如果 API 返回了数据，使用 API 数据
                if (data && Array.isArray(data.suggestions) && data.suggestions.length > 0) {
                    this.renderSuggestions(data.suggestions, container, query);
                } else {
                    // 否则使用默认模拟数据
                    this.renderDefaultSuggestions(query, container);
                }
            })
            .catch(error => {
                console.warn('Failed to fetch search suggestions:', error);
                // 使用默认模拟数据
                this.renderDefaultSuggestions(query, container);
            });
        },

        /**
         * 渲染搜索建议
         */
        renderSuggestions: function (suggestions, container, query) {
            suggestions.forEach(suggestion => {
                const li = document.createElement('li');
                li.className = 'search-suggestions-item';
                
                const href = suggestion.url || `?q=${encodeURIComponent(suggestion.text || suggestion.query || query)}`;
                const icon = suggestion.icon || 'fa-search';
                const text = suggestion.text || suggestion.query || query;
                
                li.innerHTML = `
                    <a href="${href}" role="option" tabindex="0" data-query="${this.escapeHtml(text)}">
                        <i class="fas ${icon}" aria-hidden="true"></i>
                        <span>${this.escapeHtml(text)}</span>
                    </a>
                `;
                container.appendChild(li);
            });
        },

        /**
         * 渲染默认搜索建议（模拟数据）
         */
        renderDefaultSuggestions: function (query, container) {
            const suggestions = [
                { text: query + ' 相关商品', icon: 'fa-search', url: `?q=${encodeURIComponent(query)}` },
                { text: query + ' 品牌', icon: 'fa-tag', url: `?q=${encodeURIComponent(query)}&type=brand` },
                { text: query + ' 分类', icon: 'fa-folder', url: `?q=${encodeURIComponent(query)}&type=category` }
            ];
            this.renderSuggestions(suggestions, container, query);
        },

        /**
         * 高亮选中的建议项
         */
        highlightSuggestion: function (suggestions, index) {
            suggestions.forEach((item, i) => {
                if (i === index) {
                    item.setAttribute('aria-selected', 'true');
                    item.focus();
                    // 滚动到可见区域
                    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } else {
                    item.setAttribute('aria-selected', 'false');
                }
            });
        },

        /**
         * 选择建议项
         */
        selectSuggestion: function (suggestionLink) {
            const query = suggestionLink.getAttribute('data-query') || suggestionLink.querySelector('span')?.textContent || '';
            const href = suggestionLink.getAttribute('href');
            
            // 更新输入框
            if (this.searchInput && query) {
                this.searchInput.value = query;
            }

            // 保存到搜索历史
            if (query) {
                this.saveSearchHistory(query);
            }

            // 跳转到搜索页面
            if (href && href !== '#') {
                window.location.href = href;
            } else {
                // 如果没有 href，提交表单
                const searchForm = this.searchInput?.closest('.header-search-form');
                if (searchForm) {
                    searchForm.submit();
                }
            }
        },

        /**
         * 加载搜索历史
         */
        loadSearchHistory: function () {
            const container = document.querySelector('.search-suggestions-list');
            if (!container) return;

            try {
                const history = JSON.parse(localStorage.getItem('weline_search_history') || '[]');
                container.innerHTML = '';

                if (history.length === 0) {
                    // 显示空状态提示
                    const emptyDiv = document.createElement('div');
                    emptyDiv.className = 'search-suggestions-empty';
                    emptyDiv.textContent = '暂无搜索历史';
                    container.appendChild(emptyDiv);
                    return;
                }

                // 显示搜索历史标题（如果有建议项）
                const hasHeader = container.closest('.search-suggestions')?.querySelector('.search-suggestions-header');
                if (!hasHeader) {
                    const header = document.createElement('div');
                    header.className = 'search-suggestions-header';
                    header.textContent = '搜索历史';
                    container.parentElement.insertBefore(header, container);
                }

                history.slice(0, 5).forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'search-suggestions-item';
                    li.innerHTML = `
                        <a href="?q=${encodeURIComponent(item)}" role="option" tabindex="0" data-query="${this.escapeHtml(item)}">
                            <i class="fas fa-history" aria-hidden="true"></i>
                            <span>${this.escapeHtml(item)}</span>
                        </a>
                    `;
                    container.appendChild(li);
                });
            } catch (e) {
                console.warn('Failed to load search history:', e);
            }
        },

        /**
         * 保存搜索历史
         */
        saveSearchHistory: function (query) {
            if (!query || query.trim().length === 0) return;

            try {
                const history = JSON.parse(localStorage.getItem('weline_search_history') || '[]');
                const trimmedQuery = query.trim();

                // 移除重复项
                const filteredHistory = history.filter(item => item !== trimmedQuery);

                // 添加到开头
                filteredHistory.unshift(trimmedQuery);

                // 限制最多保存10条
                const limitedHistory = filteredHistory.slice(0, 10);

                // 保存到 localStorage
                localStorage.setItem('weline_search_history', JSON.stringify(limitedHistory));
            } catch (e) {
                console.warn('Failed to save search history:', e);
            }
        },

        /**
         * HTML 转义
         */
        escapeHtml: function (text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // 创建搜索管理器实例
    const searchManager = new SearchManager();

    // 初始化 Weline 对象
    if (!window.Weline) {
        window.Weline = {};
    }

    // 导出搜索模块
    window.Weline.Search = {
        __initialized: true,
        init: function () {
            searchManager.init();
        },
        manager: searchManager
    };

    // 自动初始化（如果DOM已加载）
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            searchManager.init();
        });
    } else {
        searchManager.init();
    }

})(window, document);

