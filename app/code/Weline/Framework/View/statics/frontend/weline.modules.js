/**
 * Weline Framework 前台 JS 模块登记
 *
 * 核心 i18n（字典 / translate / switchLang / Weline.i18n）由框架提供。
 * Weline_I18n 外置模块只做增强（语言切换器 UI、国旗、AI 翻译等），不登记本核心。
 * 加载：Theme declare / data-weline-load="i18n"；禁止写进 weline.js。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    i18n: {
        paths: [
            "Weline_Framework::js/i18n.js"
        ],
        globalVar: "WelineI18n",
        load: "defer",
        description: "框架核心国际化：字典 / translate / switchLang / Weline.i18n"
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    language: "i18n",
    lang: "i18n"
});
