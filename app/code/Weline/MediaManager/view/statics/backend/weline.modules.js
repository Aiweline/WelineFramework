/**
 * Weline MediaManager 后台 JS 模块登记（后端主题标准 §6.1）
 *
 * 页面模板只声明 data-weline-load="mediaManagerBoot"，不再内联业务初始化脚本。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    mediaManagerBoot: {
        paths: [
            'Weline_MediaManager::backend/media-manager-boot.js?v=20261021'
        ],
        globalVar: 'WelineMediaManagerBoot',
        description: '媒体管理器：读取页面 mmf-bootstrap-config JSON 后调用 WelineMediaManager.init'
    }
});
