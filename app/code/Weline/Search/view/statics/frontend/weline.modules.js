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
    }
});
