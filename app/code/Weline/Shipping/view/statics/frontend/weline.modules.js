/**
 * Weline Shipping 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    shippingCheckoutAddress: {
        paths: [
            "Weline_Shipping::js/widgets/checkout-shipping-address.js"
        ],
        globalVar: null,
        description: "结账收货地址部件"
    },
    shippingAccountAddress: {
        paths: [
            "Weline_Shipping::frontend/js/account-address-v3.js"
        ],
        globalVar: null,
        description: "账户中心发货/收货地址维护"
    }
});
