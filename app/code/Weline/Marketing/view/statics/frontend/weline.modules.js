/**
 * Weline Marketing 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    checkoutCoupon: {
        paths: [
            "Weline_Marketing::js/widgets/checkout-coupon.js?v=20260914-coupon-totals1"
        ],
        globalVar: null,
        description: "结账/迷你购物车优惠券部件"
    }
});
