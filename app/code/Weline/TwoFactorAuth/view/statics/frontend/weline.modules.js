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
    },
    twofaIndex: {
        paths: [
            "Weline_TwoFactorAuth::js/twofa-index.js?v=20261021-twofa-index-1"
        ],
        globalVar: null,
        load: "eager",
        description: "双因素验证管理页交互"
    },
    twofaSetup: {
        paths: [
            "Weline_TwoFactorAuth::js/twofa-setup.js?v=20261021-twofa-setup-1"
        ],
        globalVar: null,
        load: "eager",
        description: "双因素验证设置页交互"
    },
    twofaAccounts: {
        paths: [
            "Weline_TwoFactorAuth::js/twofa-accounts.js?v=20261021-twofa-accounts-1"
        ],
        globalVar: null,
        load: "eager",
        description: "验证器账户管理页交互"
    }
});
