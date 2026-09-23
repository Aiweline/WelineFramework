/**
 * Weline Cart 前端 JS 模块注册（万能购物车加购 / 立即结账交互）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    cart: {
        paths: [
            "Weline_Cart::js/cart.js?v=20260923-remove-from-cart-pixel1",
            "Weline_Cart::js/cart-remove-pixel-stamp.js?v=20260923-remove-from-cart-pixel2",
            "Weline_Cart::js/widgets/product-purchase-actions.js?v=20260922-purchase-panel-binquery"
        ],
        globalVar: "WelineCartPurchaseActions",
        load: "defer",
        description: "万能购物车：优惠券事件 / 游客续期 / 加购交互 / remove_from_cart 像素标记"
    }
});
