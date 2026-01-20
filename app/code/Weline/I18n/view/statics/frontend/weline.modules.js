/**
 * Weline I18n 模块配置
 * 
 * 此文件定义国际化（i18n）模块的JS配置
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    i18n: {
        paths: [
            "Weline_I18n::js/i18n.js"
        ],
        globalVar: "WelineI18n",
        description: "国际化（i18n）语言切换器模块"
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    language: "i18n",
    lang: "i18n"
});
