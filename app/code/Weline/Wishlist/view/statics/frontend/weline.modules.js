/**
 * Weline Wishlist 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    wishlist: {
        paths: [
            "Weline_Wishlist::js/wishlist-page.js"
        ],
        globalVar: "WelineWishlistModule",
        description: "心愿单列表页交互"
    },
    wishlistHeader: {
        paths: [
            "Weline_Wishlist::js/wishlist-header.js"
        ],
        globalVar: "WelineWishlistHeaderModule",
        description: "顶栏收藏角标水合（SSR 游客空角标）"
    }
});
