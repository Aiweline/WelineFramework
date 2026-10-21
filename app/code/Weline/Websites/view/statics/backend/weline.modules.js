/**
 * Weline Websites 后台 JS 模块登记（后端主题标准 §6.1）
 *
 * 页面模板只声明 data-weline-load="<module>"，不再内联业务初始化脚本。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    websiteMaintenance: {
        paths: [
            'Weline_Websites::backend/websites-maintenance.js?v=20261021'
        ],
        globalVar: 'WelineWebsitesMaintenance',
        description: '网站维护模式：切换站点维护开关'
    },
    websiteBackup: {
        paths: [
            'Weline_Websites::backend/websites-backup.js?v=20261021'
        ],
        globalVar: 'WelineWebsitesBackup',
        description: '网站备份管理：创建/下载/删除备份'
    }
});
