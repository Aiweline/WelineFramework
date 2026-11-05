/**
 * Weline App 前端 JS 模块注册
 * - appDownloadFloat：app-download 部件的异步模块（片段注入后由 widgetAsync 基类加载）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    appDownloadFloat: {
        paths: [
            "Weline_Theme::js/widgets/widget-assets-runtime.js",
            "Weline_App::js/widgets/app-download-async.js"
        ],
        globalVar: null,
        load: "defer",
        description: "下载 App 悬浮部件的异步模块（占位注入后接管二维码与版本水合）"
    }
});
