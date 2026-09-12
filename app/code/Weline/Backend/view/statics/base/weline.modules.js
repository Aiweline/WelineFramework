// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        dropshipListedAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/listed-accordion.js?v=1.0.64"],
            paths: ["/Weline/Dropship/view/statics/backend/listed-accordion.js?v=1.0.64"],
            globalVar: "WelineDropshipListedAccordion",
            description: "已刊列表手风琴：展开后再异步加载本地产品详情/规格"
        },
        dropshipOrderAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/order-accordion.js?v=1.0.69"],
            paths: ["/Weline/Dropship/view/statics/backend/order-accordion.js?v=1.0.69"],
            globalVar: "WelineDropshipOrderAccordion",
            description: "履约订单手风琴：展开后再异步加载订单商品行"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {

    });
})();