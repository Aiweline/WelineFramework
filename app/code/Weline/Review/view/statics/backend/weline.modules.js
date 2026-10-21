/**
 * Weline Review 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    reviewAdmin: {
        paths: [
            'Weline_Review::backend/review-admin.js?v=1.0.0'
        ],
        description: '评论管理：审核动作提交'
    }
});
