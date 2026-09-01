/**
 * Weline TwoFactorAuth 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    accountTwoFactor: {
        paths: [
            "Weline_TwoFactorAuth::frontend/js/account-two-factor-inline-v2.js"
        ],
        globalVar: null,
        description: "账户中心两步验证面板"
    }
});
