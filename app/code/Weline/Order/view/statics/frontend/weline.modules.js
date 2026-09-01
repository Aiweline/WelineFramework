/**
 * Weline Order 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    orderNotice: {
        paths: [
            "Weline_Order::js/widgets/order-notice.js"
        ],
        globalVar: null,
        description: "迷你购物车订单留言"
    }
});
