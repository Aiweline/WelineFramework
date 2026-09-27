/**
 * Weline Payment 前端 JS 模块注册（支付生命周期事件）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    paymentLifecycle: {
        paths: [
            "Weline_Payment::js/payment-lifecycle.js?v=20260910-payment-lifecycle3"
        ],
        globalVar: "WelinePayment",
        load: "eager",
        description: "支付生命周期：weline:payment:* 统一事件"
    },
    productExpressPay: {
        paths: [
            "Weline_Payment::js/product-express-pay.js?v=20260926-buy-now-isol1"
        ],
        globalVar: "WelineProductExpressPay",
        load: "lazy",
        description: "PDP 快捷智能支付：buy_now 只结当前商品并打开支付商窗体"
    },
    paypalWalletButtons: {
        paths: [
            "Weline_Payment::js/paypal-wallet-buttons.js?v=20260920-gpay-apay1"
        ],
        globalVar: "WelineModules",
        load: "lazy",
        description: "PayPal JS SDK：Google Pay / Apple Pay funding 按钮容器"
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    payment: "paymentLifecycle",
    "WelinePayment": "paymentLifecycle",
    productExpressPay: "productExpressPay",
    paypalWalletButtons: "paypalWalletButtons"
});
