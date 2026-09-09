/**
 * Weline Cart 前端 JS 模块注册（万能购物车加购 / 立即结账交互）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    cart: {
        paths: [
            "Weline_Cart::js/cart.js",
            "Weline_Cart::js/widgets/product-purchase-actions.js?v=20260909-purchase-panel8"
        ],
        globalVar: "WelineCartPurchaseActions",
        load: "defer",
        description: "万能购物车：优惠券事件 / 游客续期 / 加购交互"
    }
});
