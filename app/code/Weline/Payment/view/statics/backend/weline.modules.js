/**
 * Weline Payment 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    paymentMethodAdmin: {
        paths: [
            'Weline_Payment::backend/method-admin.js?v=1.0.0'
        ],
        description: '支付方式管理：同步提供商与拖拽排序'
    },
    paymentDevRelay: {
        paths: [
            'Weline_Payment::js/backend/dev-relay.js?v=1.9.61'
        ],
        globalVar: 'WelinePaymentDevRelay',
        description: 'Dev Relay 配置/日志页面逻辑'
    },
    paymentDevRelayInit: {
        paths: [
            'Weline_Payment::backend/dev-relay-init.js?v=1.0.0'
        ],
        description: 'Dev Relay 配置页初始化（读取内嵌 JSON 配置）'
    }
});
