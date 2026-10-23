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
    },
    paypalCardFields: {
        paths: [
            "Weline_Payment::js/paypal-card-fields.js?v=20261023-cardfields1"
        ],
        globalVar: "WelineModules",
        load: "eager",
        description: "PayPal 站内银行卡收银台（Card Fields / ACDC）"
    },
    paymentHandoff: {
        paths: [
            "Weline_Payment::js/payment-handoff.js?v=20261021-payment-handoff-1"
        ],
        globalVar: null,
        load: "eager",
        description: "支付交接页轮询"
    },
    paymentMethods: {
        paths: [
            "Weline_Payment::js/payment-methods.js?v=20261021-payment-methods-1"
        ],
        globalVar: null,
        load: "eager",
        description: "支付方式选择与提交"
    },
    paymentPixel: {
        paths: [
            "Weline_Payment::js/payment-pixel.js?v=20261021-payment-pixel-1"
        ],
        globalVar: null,
        load: "eager",
        description: "支付成功像素埋点（payload 由 JSON 配置块提供）"
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    payment: "paymentLifecycle",
    "WelinePayment": "paymentLifecycle",
    productExpressPay: "productExpressPay",
    paypalWalletButtons: "paypalWalletButtons",
    paypalCardFields: "paypalCardFields"
});
