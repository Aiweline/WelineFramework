/**
 * Weline StoreMusic frontend JS module registration
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    storeMusic: {
        paths: [
            "Weline_StoreMusic::js/store-music.js"
        ],
        globalVar: "WelineStoreMusic",
        load: "defer",
        description: "进店音乐"
    }
});
