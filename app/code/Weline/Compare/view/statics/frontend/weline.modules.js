/**
 * Weline Compare 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    comparePage: {
        paths: [
            "Weline_Compare::js/compare-page.js"
        ],
        globalVar: "WelineComparePage",
        description: "商品对比页"
    },
    compareShopper: {
        paths: [
            "Weline_Compare::js/product-card-actions.js"
        ],
        globalVar: "WelineCompareShopper",
        description: "商品卡对比/快速查看/对比栏"
    }
});
