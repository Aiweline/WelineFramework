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
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    jq: "jquery",
    $: "jquery"
});

