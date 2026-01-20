/**
 * Weline Currency 模块配置
 * 
 * 此文件定义货币模块的JS配置
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    currency: {
        paths: [
            "Weline_Currency::js/currency.js"
        ],
        globalVar: "WelineCurrency",
        description: "货币切换器模块"
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    money: "currency"
});
