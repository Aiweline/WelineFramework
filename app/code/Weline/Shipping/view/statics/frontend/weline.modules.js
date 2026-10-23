/**
 * Weline Shipping 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    shippingCheckoutAddress: {
        paths: [
            "Weline_Shipping::js/widgets/checkout-shipping-address.js?v=20261023-phone-intl-1"
        ],
        globalVar: "WelineShippingCheckoutAddress",
        description: "结账收货地址部件"
    },
    shippingAccountAddress: {
        paths: [
            // Sticky Frontend assetVersion alone does not bust this module; bump ?v= when delete/confirm logic changes.
            "Weline_Shipping::frontend/js/account-address-v3.js?v=20261012-default-country2"
        ],
        globalVar: null,
        description: "账户中心发货/收货地址维护"
    },
    shippingDeliveryAddressPage: {
        paths: [
            "Weline_Shipping::js/shipping-delivery-address.js?v=20261021-delivery-address-page-1"
        ],
        globalVar: null,
        description: "收货地址列表页交互"
    },
    shippingAddressPage: {
        paths: [
            "Weline_Shipping::js/shipping-address-page.js?v=20261021-shipping-address-page-1"
        ],
        globalVar: null,
        description: "发货地址列表页交互"
    },
});
