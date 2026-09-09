// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        i18n: {
            origin_paths: ["app/code/Weline/Framework/view/statics/js/i18n.js"],
            paths: ["/Weline/Framework/view/statics/js/i18n.js"],
            globalVar: "WelineI18n",
            load: "defer",
            description: "框架核心国际化：字典 / translate / switchLang / Weline.i18n"
        },
        siteBlocks: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/site-blocks.js"],
            paths: ["/Weline/Theme/view/statics/js/widgets/site-blocks.js"],
            globalVar: "WelineSiteBlocks",
            async: true
        },
        miniCartExtras: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-extras-tabs.js?v=20260909-cart-summary-tabs1"],
            paths: ["/Weline/Theme/view/statics/js/widgets/mini-cart-extras-tabs.js?v=20260909-cart-summary-tabs1"],
            globalVar: "WelineMiniCartExtras",
            description: "迷你购物车 extras 页签交互"
        },
        miniCartIcon: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js?v=20260909-coupon-breakdown1"],
            paths: ["/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js?v=20260909-coupon-breakdown1"],
            globalVar: "WelineMiniCartIcon",
            description: "迷你购物车图标与抽屉"
        },
        headerSearch: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/header-search.js"],
            paths: ["/Weline/Theme/view/statics/js/widgets/header-search.js"],
            globalVar: null,
            description: "页头搜索框与分类子菜单"
        },
        storefrontImageFallback: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-image-fallback.js"],
            paths: ["/Weline/Theme/view/statics/js/storefront-image-fallback.js"],
            globalVar: null,
            load: "defer",
            description: "店面图片占位回退"
        },
        storefrontShopperToast: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-shopper-toast.js"],
            paths: ["/Weline/Theme/view/statics/js/storefront-shopper-toast.js"],
            globalVar: null,
            load: "defer",
            description: "店面购物者 Toast 区域"
        },
        currency: {
            origin_paths: ["app/code/Weline/Currency/view/statics/js/currency.js"],
            paths: ["/Weline/Currency/view/statics/js/currency.js"],
            globalVar: "WelineCurrency",
            description: "货币切换器模块"
        },
        weline: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline.js"],
            globalVar: "Weline",
            description: "Weline前端框架主入口"
        },
        welineApi: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline-api.js"],
            globalVar: "WelineApiModule",
            description: "Weline API模块"
        },
        welineApiTokenStorage: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-token-storage.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline-api-token-storage.js"],
            globalVar: "WelineTokenStorage",
            description: "Weline API Token存储模块"
        },
        welineApiWorker: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-worker.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline-api-worker.js"],
            globalVar: null,
            description: "Weline API Worker（Web Worker，无全局变量）"
        },
        welineDom: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-dom.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline-api-dom.js"],
            globalVar: "WelineDomModule",
            description: "按需 DOM 微核：委托/出现即回调/声明式 data-weline-when|on"
        },
        welineSwitcher: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-switcher.js"],
            paths: ["/Weline/Frontend/view/statics/js/weline-switcher.js"],
            globalVar: "WelineSwitcher",
            description: "Weline切换器组件（语言、货币等）"
        },
        cookie: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/cookie.js"],
            paths: ["/Weline/Frontend/view/statics/js/cookie.js"],
            globalVar: null,
            description: "Cookie操作工具函数"
        },
        account: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-session.js"],
            paths: ["/Weline/Customer/view/statics/js/account-session.js"],
            globalVar: "WelineAccountModule",
            load: "defer",
            description: "前台账户会话与顶栏账户 chrome"
        },
        customerAccount: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-index.js?v=20260906-profile-header-sync-1"],
            paths: ["/Weline/Customer/view/statics/js/account-index.js?v=20260906-profile-header-sync-1"],
            globalVar: "WelineCustomerAccount",
            description: "前台用户中心账户页交互"
        },
        customerLogout: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-logout.js"],
            paths: ["/Weline/Customer/view/statics/js/account-logout.js"],
            globalVar: "WelineCustomerLogout",
            description: "前台账户退出确认"
        },
        customerSocialQuick: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-social-quick.js?v=20260907-chooser-ui-1"],
            paths: ["/Weline/Customer/view/statics/js/account-social-quick.js?v=20260907-chooser-ui-1"],
            globalVar: "WelineSocialQuick",
            description: "未登录右下角社媒快捷登录条（由 account JS 动态拉起）"
        },
        accountTwoFactor: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/frontend/js/account-two-factor-inline-v2.js"],
            paths: ["/Weline/TwoFactorAuth/view/statics/frontend/js/account-two-factor-inline-v2.js"],
            globalVar: null,
            description: "账户中心两步验证面板"
        },
        maintenanceAsyncWait: {
            origin_paths: ["app/code/Weline/Maintenance/view/statics/js/maintenance-async-wait.js"],
            paths: ["/Weline/Maintenance/view/statics/js/maintenance-async-wait.js"],
            globalVar: "WelineMaintenanceAsyncWait",
            description: "异步 503 等待弹层与同址 wait-gift 兑礼"
        },
        orderNotice: {
            origin_paths: ["app/code/Weline/Order/view/statics/js/widgets/order-notice.js"],
            paths: ["/Weline/Order/view/statics/js/widgets/order-notice.js"],
            globalVar: null,
            description: "迷你购物车订单留言"
        },
        customerService: {
            origin_paths: ["app/code/Weline/CustomerService/view/statics/js/customer-service.js"],
            paths: ["/Weline/CustomerService/view/statics/js/customer-service.js"],
            globalVar: "CustomerServiceWidget",
            description: "前台客服聊天部件"
        },
        geo: {
            origin_paths: ["app/code/Weline/Geo/view/statics/frontend/js/geo.js"],
            paths: ["/Weline/Geo/view/statics/frontend/js/geo.js"],
            globalVar: "WelineGeo",
            description: "Geo定位模块（浏览器定位和IP定位）"
        },
        shippingCheckoutAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.v20260917.js"],
            paths: ["/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.v20260917.js"],
            globalVar: "WelineShippingCheckoutAddress",
            description: "结账收货地址部件"
        },
        shippingAccountAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/frontend/js/account-address-v3.js?v=20260908-delete-promise-resolve"],
            paths: ["/Weline/Shipping/view/statics/frontend/js/account-address-v3.js?v=20260908-delete-promise-resolve"],
            globalVar: null,
            description: "账户中心发货/收货地址维护"
        },
        captchaLazy: {
            origin_paths: ["app/code/Weline/Captcha/view/statics/js/captcha-lazy.js?v=20260909-mo-guard1"],
            paths: ["/Weline/Captcha/view/statics/js/captcha-lazy.js?v=20260909-mo-guard1"],
            globalVar: null,
            description: "FPC-safe lazy captcha client runtime (Weline.Captcha)"
        },
        checkoutCoupon: {
            origin_paths: ["app/code/Weline/Marketing/view/statics/js/widgets/checkout-coupon.js?v=20260908-coupon-restore1"],
            paths: ["/Weline/Marketing/view/statics/js/widgets/checkout-coupon.js?v=20260908-coupon-restore1"],
            globalVar: null,
            description: "结账/迷你购物车优惠券部件"
        },
        cart: {
            origin_paths: ["app/code/Weline/Cart/view/statics/js/cart.js", "app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js?v=20260909-purchase-panel8"],
            paths: ["/Weline/Cart/view/statics/js/cart.js", "/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js?v=20260909-purchase-panel8"],
            globalVar: "WelineCartPurchaseActions",
            load: "defer",
            description: "万能购物车：优惠券事件 / 游客续期 / 加购交互"
        },
        location: {
            origin_paths: ["app/code/Weline/Location/view/statics/frontend/js/location.js"],
            paths: ["/Weline/Location/view/statics/frontend/js/location.js"],
            globalVar: "WelineLocation",
            description: "Location定位模块（浏览器定位和IP定位）"
        },
        relatedProducts: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/related-products.js"],
            paths: ["/Weline/Product/view/statics/js/widgets/related-products.js"],
            globalVar: null,
            description: "相关商品轮播/网格"
        },
        recommendedProducts: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/recommended-products.js"],
            paths: ["/Weline/Product/view/statics/js/widgets/recommended-products.js"],
            globalVar: null,
            description: "推荐商品轮播/网格"
        },
        crossSell: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/cross-sell.js"],
            paths: ["/Weline/Product/view/statics/js/widgets/cross-sell.js"],
            globalVar: null,
            description: "经常一起购买（FBT）"
        },
        productReviews: {
            origin_paths: ["app/code/Weline/Review/view/statics/js/widgets/product-reviews.v20260904-pager2.js"],
            paths: ["/Weline/Review/view/statics/js/widgets/product-reviews.v20260904-pager2.js"],
            globalVar: "WelineReviewProductWidget",
            description: "万能评论部件（商品/博客共用）"
        },
        comparePage: {
            origin_paths: ["app/code/Weline/Compare/view/statics/js/compare-page.js"],
            paths: ["/Weline/Compare/view/statics/js/compare-page.js"],
            globalVar: "WelineComparePage",
            description: "商品对比页"
        },
        compareShopper: {
            origin_paths: ["app/code/Weline/Compare/view/statics/js/product-card-actions.js"],
            paths: ["/Weline/Compare/view/statics/js/product-card-actions.js"],
            globalVar: "WelineCompareShopper",
            load: "defer",
            description: "商品卡对比/快速查看/对比栏"
        },
        wishlist: {
            origin_paths: ["app/code/Weline/Wishlist/view/statics/js/wishlist-page.js"],
            paths: ["/Weline/Wishlist/view/statics/js/wishlist-page.js"],
            globalVar: "WelineWishlistModule",
            description: "心愿单列表页交互"
        },
        affiliateAccount: {
            origin_paths: ["app/code/Weline/Affiliate/view/statics/js/affiliate-account.js"],
            paths: ["/Weline/Affiliate/view/statics/js/affiliate-account.js"],
            globalVar: null,
            description: "账户中心分销工作台"
        },
        recentlyViewed: {
            origin_paths: ["app/code/Weline/RecentlyViewed/view/statics/js/widgets/recently-viewed.js"],
            paths: ["/Weline/RecentlyViewed/view/statics/js/widgets/recently-viewed.js"],
            globalVar: null,
            description: "最近浏览轮播/网格"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        language: "i18n",
        lang: "i18n",
        money: "currency",
        api: "welineApi",
        tokenStorage: "welineApiTokenStorage",
        worker: "welineApiWorker",
        dom: "welineDom",
        switcher: "welineSwitcher",
        captcha: "captchaLazy",
        geolocation: "location"
    });
})();