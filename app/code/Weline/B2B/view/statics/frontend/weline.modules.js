/**
 * Weline B2B storefront modules (ToC/ToB selling mode).
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bSellingMode: {
        paths: [
            'Weline_B2B::js/selling-mode.js'
        ],
        globalVar: 'WelineB2BSellingMode',
        description: 'B2B ToC/ToB selling mode switcher, qty MOQ, membership apply drawer'
    },
    b2bCheckoutTob: {
        paths: [
            'Weline_B2B::js/checkout-tob.js'
        ],
        globalVar: 'WelineB2BCheckoutTob',
        description: 'B2B checkout deposit note and coupon hide for tob carts'
    }
});
