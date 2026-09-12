/**
 * Weline Dropship 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    dropshipListedAccordion: {
        paths: [
            'Weline_Dropship::backend/listed-accordion.js?v=1.0.64'
        ],
        globalVar: 'WelineDropshipListedAccordion',
        description: '已刊列表手风琴：展开后再异步加载本地产品详情/规格'
    },
    dropshipOrderAccordion: {
        paths: [
            'Weline_Dropship::backend/order-accordion.js?v=1.0.69'
        ],
        globalVar: 'WelineDropshipOrderAccordion',
        description: '履约订单手风琴：展开后再异步加载订单商品行'
    }
});
