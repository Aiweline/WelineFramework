// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        jquery: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            paths: ["https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js", "https://code.bdstatic.com/npm/jquery@3.6.0/dist/jquery.min.js", "/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            globalVar: "jQuery",
            description: "jQuery库"
        },
        vue: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/vue/vue2.6.11.js"],
            paths: ["/Weline/Frontend/view/statics/libs/vue/vue2.6.11.js"],
            globalVar: "Vue",
            description: "Vue.js框架"
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
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        jq: "jquery",
        $: "jquery",
        api: "weline-api",
        account: "weline-api-account",
        tokenStorage: "weline-api-token-storage",
        worker: "weline-api-worker",
        switcher: "weline-switcher"
    });
})();