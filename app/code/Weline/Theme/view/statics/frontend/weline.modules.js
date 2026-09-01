/**
 * Weline Theme 前台部件 JS 模块注册
 * 注意：模块名必须是可解析的裸标识符（编译器不识别带连字符的引号键）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    miniCartExtras: {
        paths: [
            "Weline_Theme::js/widgets/mini-cart-extras-tabs.js"
        ],
        globalVar: "WelineMiniCartExtras",
        description: "迷你购物车 extras 页签交互"
    },
    miniCartIcon: {
        paths: [
            "Weline_Theme::js/widgets/mini-cart-icon.js"
        ],
        globalVar: "WelineMiniCartIcon",
        description: "迷你购物车图标与抽屉"
    },
    headerSearch: {
        paths: [
            "Weline_Theme::js/widgets/header-search.js"
        ],
        globalVar: null,
        description: "页头搜索框与分类子菜单"
    },
    storefrontImageFallback: {
        paths: [
            "Weline_Theme::js/storefront-image-fallback.js"
        ],
        globalVar: null,
        description: "店面图片占位回退"
    },
    storefrontShopperToast: {
        paths: [
            "Weline_Theme::js/storefront-shopper-toast.js"
        ],
        globalVar: null,
        description: "店面购物者 Toast 区域"
    }
});
