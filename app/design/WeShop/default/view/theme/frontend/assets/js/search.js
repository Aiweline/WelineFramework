/**
 * WeShop Default Theme - Search Module
 * 
 * 这是一个示例JS模块文件，演示子主题如何覆盖父主题的同名文件。
 * 
 * 如果父主题（Weline/default）也有 search.js 文件，这个文件会覆盖父主题的版本。
 * 模块收集时，系统会优先使用激活主题（WeShop/default）的 search.js，
 * 而不会加载父主题的同名文件。
 */

(function() {
    'use strict';

    // 搜索模块命名空间
    window.WeShop = window.WeShop || {};
    window.WeShop.Search = window.WeShop.Search || {};

    /**
     * 初始化搜索功能
     */
    WeShop.Search.init = function() {
        const searchForm = document.querySelector('form[action*="search"]');
        if (!searchForm) {
            return;
        }

        const searchInput = searchForm.querySelector('input[name="q"]');
        if (!searchInput) {
            return;
        }

        // 添加搜索建议功能
        this.initSearchSuggestions(searchInput);
        
        // 添加搜索历史功能
        this.initSearchHistory(searchInput);
    };

    /**
     * 初始化搜索建议
     */
    WeShop.Search.initSearchSuggestions = function(input) {
        let suggestionTimeout;
        
        input.addEventListener('input', function() {
            clearTimeout(suggestionTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                return;
            }

            suggestionTimeout = setTimeout(function() {
                // 这里可以添加AJAX请求获取搜索建议
                console.log('Search suggestions for:', query);
            }, 300);
        });
    };

    /**
     * 初始化搜索历史
     */
    WeShop.Search.initSearchHistory = function(input) {
        const historyKey = 'weline_search_history';
        
        // 加载搜索历史
        const history = JSON.parse(localStorage.getItem(historyKey) || '[]');
        
        // 保存搜索历史
        const form = input.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                const query = input.value.trim();
                if (query && !history.includes(query)) {
                    history.unshift(query);
                    if (history.length > 10) {
                        history.pop();
                    }
                    localStorage.setItem(historyKey, JSON.stringify(history));
                }
            });
        }
    };

    // DOM加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            WeShop.Search.init();
        });
    } else {
        WeShop.Search.init();
    }

})();
