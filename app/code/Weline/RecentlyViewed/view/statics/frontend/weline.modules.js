/**
 * Weline RecentlyViewed 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    recentlyViewed: {
        paths: [
            "Weline_RecentlyViewed::js/widgets/recently-viewed.js"
        ],
        globalVar: null,
        description: "最近浏览轮播/网格"
    }
});
