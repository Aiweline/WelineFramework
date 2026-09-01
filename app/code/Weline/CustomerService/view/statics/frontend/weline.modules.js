/**
 * Weline CustomerService 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    customerService: {
        paths: [
            "Weline_CustomerService::js/customer-service.js"
        ],
        globalVar: "CustomerServiceWidget",
        description: "前台客服聊天部件"
    }
});
