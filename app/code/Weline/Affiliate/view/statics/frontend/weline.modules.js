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
            "Weline_Affiliate::js/affiliate-product-share.js?v=20260909-default-icons2"
        ],
        globalVar: "WelineAffiliateProductShare",
        description: "商品详情/加购弹窗分销分享（等账户会话后异步水合）"
    }
});
