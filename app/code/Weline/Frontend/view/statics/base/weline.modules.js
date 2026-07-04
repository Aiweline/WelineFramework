// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        i18n: {
            origin_paths: ["app/code/Weline/I18n/view/statics/js/i18n.js"],
            paths: ["/static/Weline/I18n/js/i18n.js"],
            globalVar: "WelineI18n",
            description: "国际化（i18n）语言切换器模块"
        },
        currency: {
            origin_paths: ["app/code/Weline/Currency/view/statics/js/currency.js"],
            paths: ["/static/Weline/Currency/js/currency.js"],
            globalVar: "WelineCurrency",
            description: "货币切换器模块"
        },
        jquery: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            paths: ["https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js", "https://code.bdstatic.com/npm/jquery@3.6.0/dist/jquery.min.js", "/static/Weline/Frontend/libs/jquery/3.6.0/jquery.min.js"],
            globalVar: "jQuery",
            description: "jQuery库"
        },
        vue: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/vue/vue2.6.11.js"],
            paths: ["/static/Weline/Frontend/libs/vue/vue2.6.11.js"],
            globalVar: "Vue",
            description: "Vue.js框架"
        },
        bootstrap: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js"],
            paths: ["/static/Weline/Frontend/libs/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js"],
            globalVar: "bootstrap",
            description: "Bootstrap JS"
        },
        weline: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline.js"],
            paths: ["/static/Weline/Frontend/js/weline.js"],
            globalVar: "Weline",
            description: "Weline前端框架主入口"
        },
        cookie: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/cookie.js"],
            paths: ["/static/Weline/Frontend/js/cookie.js"],
            globalVar: null,
            description: "Cookie操作工具函数"
        },
        location: {
            origin_paths: ["app/code/Weline/Location/view/statics/statics/frontend/js/location.js"],
            paths: ["/static/Weline/Location/statics/frontend/js/location.js"],
            globalVar: "WelineLocation",
            description: "Location定位模块（浏览器定位和IP定位）"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        language: "i18n",
        lang: "i18n",
        money: "currency",
        jq: "jquery",
        $: "jquery",
        api: "weline-api",
        account: "weline-api-account",
        tokenStorage: "weline-api-token-storage",
        worker: "weline-api-worker",
        switcher: "weline-switcher",
        geolocation: "location"
    });
})();