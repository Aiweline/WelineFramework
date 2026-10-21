/**
 * Weline Admin 模块配置
 *
 * 此文件定义 Admin 模块可用的后台 JS 模块。
 * 登记后执行 `php bin/w resource:compile welineModules` 生成合并配置。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    adminCollectorBrowser: {
        paths: ['Weline_Admin::backend/collector-browser.js?v=20261021'],
        description: 'Admin 事件/插件/路由收集器浏览器：搜索、筛选、展开折叠与结果计数'
    },
    adminRoleAssign: {
        paths: ['Weline_Admin::backend/role-assign.js?v=20261021'],
        description: 'Admin 管理员角色分配：下拉选择后异步保存并同步状态徽章'
    },
    adminMenuTree: {
        paths: ['Weline_Admin::backend/menu-tree.js?v=20261021'],
        description: 'Admin 菜单树：节点展开折叠、新增/编辑弹层与删除确认'
    }
});
