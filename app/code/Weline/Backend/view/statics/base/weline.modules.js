// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        jquery: {
            origin_paths: ["app/code/Weline/Backend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            paths: ["https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js", "https://code.bdstatic.com/npm/jquery@3.6.0/dist/jquery.min.js", "/Weline/Backend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            globalVar: "jQuery",
            description: "jQuery库"
        },
        vue: {
            origin_paths: ["app/code/Weline/Backend/view/statics/libs/vue/vue2.6.11.js"],
            paths: ["/Weline/Backend/view/statics/libs/vue/vue2.6.11.js"],
            globalVar: "Vue",
            description: "Vue.js框架"
        },
        bootstrapBundle: {
            origin_paths: ["app/code/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js", "app/code/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.min.js", "app/code/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.esm.min.js"],
            paths: ["/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js", "/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.min.js", "/Weline/Admin/view/statics/lib/bootstrap-5.1.3-dist/js/bootstrap.esm.min.js"],
            globalVar: "bootstrap",
            description: "Bootstrap JS Bundle"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        jq: "jquery",
        $: "jquery"
    });
})();