/**
 * Weline Widget 前端 JS 模块注册
 * - widgetAsync：异步部件基类加载器（只做占位扫描 → 片段注入 → 部件模块加载）。
 * 部件模块通过 @widget.async_module 声明自己的模块名，由本模块按 defer 策略加载。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    widgetAsync: {
        paths: [
            "Weline_Widget::js/widget-async.js"
        ],
        globalVar: "WelineWidgetAsync",
        load: "defer",
        description: "异步部件基类：占位扫描、统一片段请求、按 defer 加载部件模块"
    }
});
