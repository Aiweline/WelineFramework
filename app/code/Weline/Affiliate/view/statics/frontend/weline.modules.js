/**
 * Weline Affiliate 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    affiliateAccount: {
        paths: [
            "Weline_Affiliate::js/affiliate-account.js"
        ],
        globalVar: null,
        description: "账户中心分销工作台"
    }
});
