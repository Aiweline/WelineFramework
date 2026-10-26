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
    },
    affiliateProductShare: {
        paths: [
            "Weline_Affiliate::js/affiliate-product-share.js?v=20260914-panel-share-url"
        ],
        globalVar: "WelineAffiliateProductShare",
        description: "商品详情/加购弹窗分销分享（等账户会话后异步水合）"
    },
    affiliateFloat: {
        paths: [
            "Weline_Affiliate::js/affiliate-float.js?v=20261025-rules-login-gate-4"
        ],
        globalVar: "WelineAffiliateFloat",
        description: "右侧中悬浮分销面板（游客展示规则，登录后水合个人分销信息）"
    }
});
