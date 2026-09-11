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
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    payment: "paymentLifecycle",
    "WelinePayment": "paymentLifecycle"
});
