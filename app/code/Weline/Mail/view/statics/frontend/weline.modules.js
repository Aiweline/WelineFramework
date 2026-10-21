/**
 * Weline Mail 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    mailAccountSelect: {
        paths: [
            "Weline_Mail::js/mail-account-select.js?v=20261021-mail-account-select-1"
        ],
        globalVar: "WelineMailAccountSelect",
        load: "eager",
        description: "邮箱账户切换（下拉变更即提交）"
    }
});
