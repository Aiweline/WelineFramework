/**
 * Weline Captcha 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    captchaLazy: {
        paths: [
            'Weline_Captcha::js/captcha-lazy.js?v=20260831-layout-fix1',
        ],
        globalVar: null,
        description: 'FPC-safe lazy captcha client runtime (Weline.Captcha)',
    },
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    captcha: 'captchaLazy',
});
