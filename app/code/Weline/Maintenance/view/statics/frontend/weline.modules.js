/**
 * Weline Maintenance 前台 JS 模块登记
 * 503 / 维护等待弹层按需加载，禁止写进 weline.js。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    maintenanceAsyncWait: {
        paths: [
            "Weline_Maintenance::js/maintenance-async-wait.js"
        ],
        globalVar: "WelineMaintenanceAsyncWait",
        description: "异步 503 等待弹层与同址 wait-gift 兑礼"
    }
});
