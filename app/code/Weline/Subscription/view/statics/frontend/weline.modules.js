/**
 * Weline Subscription 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    subscriptionBuyer: {
        paths: [
            "Weline_Subscription::js/subscription-buyer.js?v=20261121-subscription-buyer-1"
        ],
        globalVar: null,
        load: "eager",
        description: "订阅买家屏：订阅/续订/取消表单 API 提交与消息提示"
    }
});
