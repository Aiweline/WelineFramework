/**
 * Weline B2B storefront modules (ToC/ToB selling mode).
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bSellingMode: {
        paths: [
            'Weline_B2B::js/selling-mode.js?v=20260909-dual-cart1'
        ],
        globalVar: 'WelineB2BSellingMode',
        description: 'B2B ToC/ToB selling mode + mini-cart/cart dual-type injection'
    },
    b2bCheckoutTob: {
        paths: [
            'Weline_B2B::js/checkout-tob.js'
        ],
        globalVar: 'WelineB2BCheckoutTob',
        description: 'B2B checkout deposit note and coupon hide for tob carts'
    }
});
