/** CDN 后台策略管理业务模块。 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
Object.assign(window.WelineModulesConfig.modules, {
    cdnFpcPolicyManagement: {
        paths: ['Weline_Cdn::js/backend/fpc-policy-management.js?v=20260928-fpc6'],
        globalVar: 'WelineCdnFpcPolicyModule',
        description: '控制器 FPC 策略、注释规则与同步回执'
    }
});
