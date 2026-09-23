/**
 * Weline Newsletter frontend JS module registration
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    newsletterSubscribe: {
        paths: [
            "Weline_Newsletter::js/newsletter-subscribe.js?v=20260922-deferred-p206"
        ],
        globalVar: "WelineNewsletterSubscribe",
        load: "defer",
        description: "邮件订阅表单（BinQuery / 弹窗 cookie）"
    }
});
