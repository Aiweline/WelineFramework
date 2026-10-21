/**
 * Weline B2B backend modules.
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bControlCenter: {
        paths: [
            'Weline_B2B::backend/control-center.js?v=1.0.0'
        ],
        description: 'B2B 控制中心：挂起订单复制链接与订单聊天启动'
    },

    b2bOrderChat: {
        paths: [
            'Weline_B2B::backend/order-chat-accordion.js?v=20260912-order-chat4'
        ],
        globalVar: 'WelineB2BOrderChat',
        description: 'B2B order chat accordion on backend order view'
    }
});
