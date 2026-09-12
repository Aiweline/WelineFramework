/**
 * Weline B2B storefront modules (ToC/ToB selling mode).
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bSellingMode: {
        paths: [
            'Weline_B2B::js/checkout-tob.js?v=20260912-hang-balance1',
            'Weline_B2B::js/selling-mode.js?v=20260911-credit-fx-ui3'
        ],
        globalVar: 'WelineB2BSellingMode',
        description: 'B2B ToC/ToB selling mode + mini-cart/cart dual-type injection'
    },
    b2bCheckoutTob: {
        paths: [
            'Weline_B2B::js/checkout-tob.js?v=20260912-hang-balance1'
        ],
        globalVar: 'WelineB2BCheckoutTob',
        description: 'B2B wholesale credit + checkout deposit note for tob carts'
    }
});
