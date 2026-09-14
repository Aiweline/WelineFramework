// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        marketingCouponForm: {
            origin_paths: ["app/code/Weline/Marketing/view/statics/js/backend/coupon-form.js?v=20260914-coupon-form4"],
            paths: ["/Weline/Marketing/view/statics/js/backend/coupon-form.js?v=20260914-coupon-form4"],
            globalVar: "WelineMarketingCouponFormModule",
            description: "后台优惠券表单：折扣单位随类型切换"
        },
        dropshipListedAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/listed-accordion.js?v=1.0.70"],
            paths: ["/Weline/Dropship/view/statics/backend/listed-accordion.js?v=1.0.70"],
            globalVar: "WelineDropshipListedAccordion",
            description: "已刊列表手风琴：展开后再异步加载本地产品详情/规格"
        },
        dropshipOrderAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/order-accordion.js?v=1.0.69"],
            paths: ["/Weline/Dropship/view/statics/backend/order-accordion.js?v=1.0.69"],
            globalVar: "WelineDropshipOrderAccordion",
            description: "履约订单手风琴：展开后再异步加载订单商品行"
        },
        b2bOrderChat: {
            origin_paths: ["app/code/Weline/B2B/view/statics/backend/order-chat-accordion.js?v=20260912-order-chat4"],
            paths: ["/Weline/B2B/view/statics/backend/order-chat-accordion.js?v=20260912-order-chat4"],
            globalVar: "WelineB2BOrderChat",
            description: "B2B order chat accordion on backend order view"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {

    });
})();