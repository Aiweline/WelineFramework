/**
 * WeShop Cart 模块配置
 * 
 * 此文件定义购物车模块的JS配置
 * 
 * 模块说明：
 * - cart: 主购物车模块，处理加入购物车、规格选择等核心功能
 * - miniCart: 迷你购物车模块，处理头部购物车弹出层
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

// 合并模块配置
Object.assign(window.WelineModulesConfig.modules, {
    // 主购物车模块 - 处理加入购物车、规格选择弹窗等
    cart: {
        paths: [
            "WeShop_Cart::statics/js/cart.js"
        ],
        globalVar: "WeShopCart",
        description: "WeShop 购物车模块 - 处理加入购物车、规格选择等",
        autoInit: true  // 页面加载时自动初始化
    },
    // 迷你购物车模块 - 头部购物车弹出层
    miniCart: {
        paths: [
            "WeShop_Cart::statics/js/mini-cart.js"
        ],
        globalVar: null,
        description: "WeShop 迷你购物车模块",
        autoInit: true
    }
});

// 合并模块别名
Object.assign(window.WelineModulesConfig.moduleAliases, {
    shoppingCart: "cart"
});
