/**
 * Weline B2B storefront modules (ToC/ToB selling mode).
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bSellingMode: {
        paths: [
            'Weline_B2B::js/checkout-tob.js?v=20260914-goods-sync1',
            'Weline_B2B::js/selling-mode.js?v=20260914-no-cart-chooser'
        ],
        globalVar: 'WelineB2BSellingMode',
        description: 'B2B ToC/ToB selling mode + mini-cart/cart dual-type injection'
    },
    b2bCheckoutTob: {
        paths: [
            'Weline_B2B::js/checkout-tob.js?v=20260914-goods-sync1'
        ],
        globalVar: 'WelineB2BCheckoutTob',
        description: 'B2B wholesale credit + checkout deposit note for tob carts'
    },
    b2bOrderChat: {
        paths: [
            'Weline_B2B::js/order-chat-accordion.js?v=20260912-order-chat4'
        ],
        globalVar: 'WelineB2BOrderChat',
        description: 'B2B order chat accordion (account + backend same thread)'
    }
});
