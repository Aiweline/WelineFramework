/**
 * Weline Consent 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    consentBanner: {
        paths: [
            "Weline_Consent::js/consent-banner.js?v=20261021-consent-banner-1"
        ],
        globalVar: "WelineConsentBanner",
        load: "eager",
        description: "店面 Cookie 同意横幅"
    }
});
