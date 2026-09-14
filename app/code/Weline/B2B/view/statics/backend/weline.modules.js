/**
 * Weline B2B backend modules.
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    b2bOrderChat: {
        paths: [
            'Weline_B2B::backend/order-chat-accordion.js?v=20260912-order-chat4'
        ],
        globalVar: 'WelineB2BOrderChat',
        description: 'B2B order chat accordion on backend order view'
    }
});
