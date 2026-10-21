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
    },
    customerServiceFloat: {
        paths: [
            "Weline_CustomerService::js/customer-service-float.js?v=20261021-cs-float-1"
        ],
        globalVar: null,
        load: "eager",
        description: "浮动客服部件启动（懒加载 customer-service.js）"
    },
    csBindVerify: {
        paths: [
            "Weline_CustomerService::js/cs-bind-verify.js?v=20261021-cs-bind-verify-1"
        ],
        globalVar: null,
        load: "eager",
        description: "邮箱绑定验证结果页（BroadcastChannel/localStorage 同步）"
    }
});
