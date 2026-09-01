/**
 * Weline Product 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    relatedProducts: {
        paths: [
            "Weline_Product::js/widgets/related-products.js"
        ],
        globalVar: null,
        description: "相关商品轮播/网格"
    },
    recommendedProducts: {
        paths: [
            "Weline_Product::js/widgets/recommended-products.js"
        ],
        globalVar: null,
        description: "推荐商品轮播/网格"
    },
    crossSell: {
        paths: [
            "Weline_Product::js/widgets/cross-sell.js"
        ],
        globalVar: null,
        description: "经常一起购买（FBT）"
    }
});
