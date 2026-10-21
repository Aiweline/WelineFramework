/**
 * Weline Bt_Center 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    btServerList: {
        paths: [
            'Weline_Bt_Center::backend/bt-server-list.js?v=1.0.0'
        ],
        description: 'BT 服务器列表：删除确认'
    },
    btServerForm: {
        paths: [
            'Weline_Bt_Center::backend/bt-server-form.js?v=1.0.0'
        ],
        description: 'BT 服务器表单：密码显隐与异步保存'
    }
});
