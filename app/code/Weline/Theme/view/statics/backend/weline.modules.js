/**
 * Weline Theme 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    themeComponentParams: {
        paths: [
            'Weline_Theme::backend/theme-component-params.js'
        ],
        globalVar: 'WelineThemeComponentParams',
        description: '主题组件预览：组件参数保存初始化（页面 data-weline-load 按需异步加载）'
    }
});
