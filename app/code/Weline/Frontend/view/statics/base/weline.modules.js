// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        cart: {
            origin_paths: ["app/code/WeShop/Cart/view/statics/js/cart.js"],
            paths: ["/WeShop/Cart/view/statics/js/cart.js"],
            globalVar: "WeShopCart",
            description: "WeShop 购物车模块 - 处理加入购物车、规格选择等"
        },
        miniCart: {
            origin_paths: ["app/code/WeShop/Cart/view/statics/js/mini-cart.js"],
            paths: ["/WeShop/Cart/view/statics/js/mini-cart.js"],
            globalVar: "MiniCart",
            description: "WeShop 迷你购物车模块 - Drawer 抽屉式购物车"
        },
        jquery: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            paths: ["https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js", "https://code.bdstatic.com/npm/jquery@3.6.0/dist/jquery.min.js", "/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js"],
            globalVar: "jQuery",
            description: "jQuery库"
        },
        vue: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/vue/vue2.6.11.js"],
            paths: ["/Weline/Frontend/view/statics/libs/vue/vue2.6.11.js"],
            globalVar: "Vue",
            description: "Vue.js框架"
        },
        bootstrap: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/libs/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js"],
            paths: ["/Weline/Frontend/view/statics/libs/bootstrap-5.1.3-dist/js/bootstrap.bundle.min.js"],
            globalVar: "bootstrap",
            description: "Bootstrap JS"
        },
        weline: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline.js"],
            globalVar: "Weline",
            description: "Weline前端框架主入口"
        },
        cookie: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/cookie.js"],
            paths: ["/Weline/Frontend/view/statics/js/cookie.js"],
            globalVar: null,
            description: "Cookie操作工具函数"
        },
        geo: {
            origin_paths: ["app/code/Weline/Geo/view/statics/statics/frontend/js/geo.js"],
            paths: ["/Weline/Geo/view/statics/statics/frontend/js/geo.js"],
            globalVar: "WelineGeo",
            description: "Geo定位模块（浏览器定位和IP定位）"
        },
        currency: {
            origin_paths: ["app/code/Weline/Currency/view/statics/js/currency.js"],
            paths: ["/Weline/Currency/view/statics/js/currency.js"],
            globalVar: "WelineCurrency",
            description: "货币切换器模块"
        },
        i18n: {
            origin_paths: ["app/code/Weline/I18n/view/statics/js/i18n.js"],
            paths: ["/Weline/I18n/view/statics/js/i18n.js"],
            globalVar: "WelineI18n",
            description: "国际化（i18n）语言切换器模块"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        shoppingCart: "cart",
        jq: "jquery",
        $: "jquery",
        api: "weline-api",
        account: "weline-api-account",
        tokenStorage: "weline-api-token-storage",
        worker: "weline-api-worker",
        switcher: "weline-switcher",
        geolocation: "geo",
        money: "currency",
        language: "i18n",
        lang: "i18n"
    });
})();