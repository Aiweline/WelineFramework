// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        currency: {
            origin_paths: ["app/code/Weline/Currency/view/statics/js/currency.js"],
            paths: ["/Weline/Currency/view/statics/js/currency.js"],
            globalVar: "WelineCurrency",
            description: "货币切换器模块"
        },
        weline: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline.js"],
            globalVar: "Weline",
            description: "Weline前端框架主入口"
        },
        cookie: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/cookie.js"],
            paths: ["/Weline/Frontend/view/statics/js/cookie.js"],
            globalVar: null,
            description: "Cookie操作工具函数"
        },
        location: {
            origin_paths: ["app/code/Weline/Location/view/statics/statics/frontend/js/location.js"],
            paths: ["/Weline/Location/view/statics/statics/frontend/js/location.js"],
            globalVar: "WelineLocation",
            description: "Location定位模块（浏览器定位和IP定位）"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        money: "currency",
        api: "weline-api",
        account: "weline-api-account",
        tokenStorage: "weline-api-token-storage",
        worker: "weline-api-worker",
        switcher: "weline-switcher",
        geolocation: "location"
    });
})();