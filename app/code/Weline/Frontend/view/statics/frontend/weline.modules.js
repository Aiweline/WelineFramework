/**
 * Weline Frontend 模块配置
 * 
 * 此文件定义前端可用的JS模块
 * 格式：JSON对象，包含 modules 和 moduleAliases
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    jquery: {
        paths: [
            "https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js",
            "https://code.bdstatic.com/npm/jquery@3.6.0/dist/jquery.min.js",
            "Weline_Frontend::libs/jquery/3.6.0/jquery.min.js"
        ],
        globalVar: "jQuery",
        description: "jQuery库"
    },
    vue: {
        paths: [
            "Weline_Frontend::libs/vue/vue2.6.11.js"
        ],
        globalVar: "Vue",
        description: "Vue.js框架"
    },
    weline: {
        paths: [
            "Weline_Frontend::js/weline.js"
        ],
        globalVar: "Weline",
        description: "Weline前端框架主入口"
    },
    "weline-api": {
        paths: [
            "Weline_Frontend::js/weline-api.js"
        ],
        globalVar: "WelineApiModule",
        description: "Weline API模块"
    },
    "weline-api-account": {
        paths: [
            "Weline_Frontend::js/weline-api-account.js"
        ],
        globalVar: "WelineAccountModule",
        description: "Weline API账户模块"
    },
    "weline-api-token-storage": {
        paths: [
            "Weline_Frontend::js/weline-api-token-storage.js"
        ],
        globalVar: "WelineTokenStorage",
        description: "Weline API Token存储模块"
    },
    "weline-api-worker": {
        paths: [
            "Weline_Frontend::js/weline-api-worker.js"
        ],
        globalVar: null,
        description: "Weline API Worker（Web Worker，无全局变量）"
    },
    "weline-switcher": {
        paths: [
            "Weline_Frontend::js/weline-switcher.js"
        ],
        globalVar: "WelineSwitcher",
        description: "Weline切换器组件（语言、货币等）"
    },
    cookie: {
        paths: [
            "Weline_Frontend::js/cookie.js"
        ],
        globalVar: null,
        description: "Cookie操作工具函数"
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    jq: "jquery",
    $: "jquery",
    api: "weline-api",
    account: "weline-api-account",
    tokenStorage: "weline-api-token-storage",
    worker: "weline-api-worker",
    switcher: "weline-switcher"
});

