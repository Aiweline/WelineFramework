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
    youMayLike: {
        paths: [
            "Weline_Product::js/widgets/you-may-like.js"
        ],
        globalVar: null,
        description: "猜你喜欢轮播/网格"
    },
    crossSell: {
        paths: [
            "Weline_Product::js/widgets/cross-sell.js"
        ],
        globalVar: null,
        description: "经常一起购买（FBT）"
    },
    productStickyPurchase: {
        paths: [
            "Weline_Product::js/widgets/product-sticky-purchase.js?v=20260913-sticky-atc4"
        ],
        globalVar: null,
        description: "PDP 主加购滚出视野后的悬浮代理加购条"
    },
    productDetailReveal: {
        paths: [
            "Weline_Product::js/widgets/product-detail-reveal.js?v=20260923-detail-reveal4"
        ],
        globalVar: null,
        description: "PDP 详情杂志楼层滚轮入场（§5.4）"
    }
});
