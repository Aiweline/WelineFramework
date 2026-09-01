/**
 * Weline Frontend 模块配置
 *
 * 模块名必须是裸 JS 标识符（编译器不合并带连字符的引号键）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    weline: {
        paths: [
            "Weline_Frontend::js/weline.js"
        ],
        globalVar: "Weline",
        description: "Weline前端框架主入口"
    },
    welineApi: {
        paths: [
            "Weline_Frontend::js/weline-api.js"
        ],
        globalVar: "WelineApiModule",
        description: "Weline API模块"
    },
    welineApiAccount: {
        paths: [
            "Weline_Frontend::js/weline-api-account.js"
        ],
        globalVar: "WelineAccountModule",
        description: "Weline API账户模块"
    },
    welineApiTokenStorage: {
        paths: [
            "Weline_Frontend::js/weline-api-token-storage.js"
        ],
        globalVar: "WelineTokenStorage",
        description: "Weline API Token存储模块"
    },
    welineApiWorker: {
        paths: [
            "Weline_Frontend::js/weline-api-worker.js"
        ],
        globalVar: null,
        description: "Weline API Worker（Web Worker，无全局变量）"
    },
    welineDom: {
        paths: [
            "Weline_Frontend::js/weline-api-dom.js"
        ],
        globalVar: "WelineDomModule",
        description: "按需 DOM 微核：委托/出现即回调/声明式 data-weline-when|on"
    },
    welineSwitcher: {
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
    },
    location: {
        paths: [
            "Weline_Location::statics/frontend/js/location.js"
        ],
        globalVar: "WelineLocation",
        description: "Location定位模块（浏览器定位和IP定位）"
    }
});

// 合并模块别名（短名 → 注册名）
Object.assign(window.WelineModulesConfig.moduleAliases, {
    api: "welineApi",
    account: "welineApiAccount",
    tokenStorage: "welineApiTokenStorage",
    worker: "welineApiWorker",
    dom: "welineDom",
    switcher: "welineSwitcher",
    geolocation: "location"
});
