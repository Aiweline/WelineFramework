/**
 * Weline Admin 模块配置
 * 
 * 此文件定义Admin模块可用的JS模块
 * 格式：JSON对象，包含 modules 和 moduleAliases
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    bootstrapBundle: {
        paths: [
            "Weline_Admin::lib/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js",
            "Weline_Admin::lib/bootstrap-5.1.3-dist/js/bootstrap.min.js",
            "Weline_Admin::lib/bootstrap-5.1.3-dist/js/bootstrap.esm.min.js"
        ],
        globalVar: "bootstrap",
        description: "Bootstrap JS Bundle"
    }
});

