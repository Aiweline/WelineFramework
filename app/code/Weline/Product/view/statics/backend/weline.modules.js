/**
 * Weline Product 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    productQuoteMail: {
        paths: [
            'Weline_Product::backend/quote-request-mail.js?v=1.0.0'
        ],
        globalVar: 'WelineProductQuoteMail',
        description: '商品询价：企业邮箱回复入口（data-quote-mail-open 绑定）'
    }
});
