/**
 * WeShop Cart 模块配置
 * 
 * 此文件定义购物车模块的JS配置
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    cart: {
        paths: [
            "WeShop_Cart::statics/js/mini-cart.js"
        ],
        globalVar: null,
        description: "WeShop 迷你购物车模块"
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    miniCart: "cart"
});
