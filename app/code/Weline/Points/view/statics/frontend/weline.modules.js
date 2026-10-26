/**
 * Weline Points storefront modules.
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    pointsCheckout: {
        paths: [
            'Weline_Points::js/points-discount.js?v=20261027-1'
        ],
        globalVar: 'WelinePointsCheckout',
        description: 'Points redemption quote/apply on cart, mini-cart and checkout'
    }
});
