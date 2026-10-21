/**
 * Weline Search storefront JS module registration.
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    storefrontSearchResults: {
        paths: [
            "Weline_Search::js/storefront-search-results.js"
        ],
        globalVar: "WelineStorefrontSearchResults",
        dependencies: ["api"],
        async: true,
        description: "搜索页壳 FPC：异步 BinQuery 填充结果"
    },
    searchTypeFilter: {
        paths: [
            "Weline_Search::js/search-type-filter.js?v=20261021-search-type-filter-1"
        ],
        globalVar: "WelineSearchTypeFilter",
        load: "eager",
        description: "搜索侧栏类型/分类树折叠与提交"
    }
});
