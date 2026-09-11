/**
 * Weline Checkout 前端 JS 模块注册（结账生命周期事件）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    checkoutLifecycle: {
        paths: [
            "Weline_Checkout::js/checkout-lifecycle.js?v=20260910-checkout-lifecycle3"
        ],
        globalVar: "WelineCheckout",
        load: "eager",
        description: "结账生命周期：weline:checkout:order-created / success"
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    checkout: "checkoutLifecycle",
    "WelineCheckout": "checkoutLifecycle"
});
