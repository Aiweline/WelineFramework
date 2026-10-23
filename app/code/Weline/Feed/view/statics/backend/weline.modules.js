/**
 * Weline Feed 后台 JS 模块登记。
 * 页面模板只声明 data-weline-load="feedCatalog"。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    feedCatalog: {
        paths: [
            'Weline_Feed::backend/feed-catalog.js?v=20261023h'
        ],
        globalVar: 'WelineFeedCatalog',
        description: '购物目录：列表、弹层新增编辑、分类挂钩、生成、启停与归档'
    }
});
