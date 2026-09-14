/**
 * Weline Shipping 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    shippingCheckoutAddress: {
        paths: [
            "Weline_Shipping::js/widgets/checkout-shipping-address.js?v=20260914-picker-all-addr1"
        ],
        globalVar: "WelineShippingCheckoutAddress",
        description: "结账收货地址部件"
    },
    shippingAccountAddress: {
        paths: [
            // Sticky Frontend assetVersion alone does not bust this module; bump ?v= when delete/confirm logic changes.
            "Weline_Shipping::frontend/js/account-address-v3.js?v=20260908-delete-promise-resolve"
        ],
        globalVar: null,
        description: "账户中心发货/收货地址维护"
    }
});
