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
    }
});
