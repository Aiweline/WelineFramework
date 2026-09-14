/**
 * Weline Marketing 后台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    marketingCouponForm: {
        paths: [
            'Weline_Marketing::js/backend/coupon-form.js?v=20260914-coupon-form4'
        ],
        globalVar: 'WelineMarketingCouponFormModule',
        description: '后台优惠券表单：折扣单位随类型切换'
    }
});
