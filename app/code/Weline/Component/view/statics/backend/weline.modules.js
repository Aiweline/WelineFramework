/**
 * Weline Component 后台 JS 模块登记（后端主题标准 §6.1）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    componentLibrary: {
        paths: [
            'Weline_Component::backend/component-library.js?v=20261021'
        ],
        globalVar: 'WelineComponentLibrary',
        description: '组件库：组件详情弹窗'
    }
});
