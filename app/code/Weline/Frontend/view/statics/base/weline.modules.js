// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        i18n: {
            origin_paths: ["app/code/Weline/Framework/view/statics/js/i18n.js"],
            paths: ["Weline_Framework::js/i18n.js"],
            globalVar: "WelineI18n",
            load: "defer",
            description: "框架核心国际化：字典 / translate / switchLang / Weline.i18n"
        },
        captchaLazy: {
            origin_paths: ["app/code/Weline/Captcha/view/statics/js/captcha-lazy.js?v=20260909-mo-guard1"],
            paths: ["Weline_Captcha::js/captcha-lazy.js?v=20260909-mo-guard1"],
            globalVar: null,
            description: "FPC-safe lazy captcha client runtime (Weline.Captcha)"
        },
        siteBlocks: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/site-blocks.js"],
            paths: ["Weline_Theme::js/widgets/site-blocks.js"],
            globalVar: "WelineSiteBlocks",
            async: true
        },
        videoCarousel: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/video-carousel.js"],
            paths: ["Weline_Theme::js/widgets/video-carousel.js"],
            globalVar: "WelineVideoCarousel",
            async: true,
            description: "首页/店面视频轮播切换与关联商品 dialog"
        },
        miniCartExtras: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-extras-tabs.js?v=20260914-skip-empty-tabs1"],
            paths: ["Weline_Theme::js/widgets/mini-cart-extras-tabs.js?v=20260914-skip-empty-tabs1"],
            globalVar: "WelineMiniCartExtras",
            description: "迷你购物车 extras 页签交互"
        },
        miniCartIcon: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js?v=20260923-ops02-zero-price"],
            paths: ["Weline_Theme::js/widgets/mini-cart-icon.js?v=20260923-ops02-zero-price"],
            globalVar: "WelineMiniCartIcon",
            description: "迷你购物车图标与抽屉"
        },
        headerSearch: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/header-search.js"],
            paths: ["Weline_Theme::js/widgets/header-search.js"],
            globalVar: null,
            description: "页头搜索框与分类子菜单"
        },
        storefrontImageFallback: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-image-fallback.js"],
            paths: ["Weline_Theme::js/storefront-image-fallback.js"],
            globalVar: null,
            load: "defer",
            description: "店面图片占位回退"
        },
        storefrontShopperToast: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-shopper-toast.js"],
            paths: ["Weline_Theme::js/storefront-shopper-toast.js"],
            globalVar: null,
            load: "defer",
            description: "店面购物者 Toast 区域"
        },
        footerSocialFloat: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/footer-social-float.js"],
            paths: ["Weline_Theme::js/widgets/footer-social-float.js"],
            globalVar: "WelineFooterSocialFloat",
            async: true,
            description: "页脚侧边悬浮社媒贴边收起"
        },
        currency: {
            origin_paths: ["app/code/Weline/Currency/view/statics/js/currency.js"],
            paths: ["Weline_Currency::js/currency.js"],
            globalVar: "WelineCurrency",
            description: "货币切换器模块"
        },
        weline: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline.js"],
            paths: ["Weline_Frontend::js/weline.js"],
            globalVar: "Weline",
            description: "Weline前端框架主入口"
        },
        welineApi: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api.js"],
            paths: ["Weline_Frontend::js/weline-api.js"],
            globalVar: "WelineApiModule",
            description: "Weline API模块"
        },
        welineApiTokenStorage: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-token-storage.js"],
            paths: ["Weline_Frontend::js/weline-api-token-storage.js"],
            globalVar: "WelineTokenStorage",
            description: "Weline API Token存储模块"
        },
        welineApiWorker: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-worker.js"],
            paths: ["Weline_Frontend::js/weline-api-worker.js"],
            globalVar: null,
            description: "Weline API Worker（Web Worker，无全局变量）"
        },
        welineDom: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-api-dom.js"],
            paths: ["Weline_Frontend::js/weline-api-dom.js"],
            globalVar: "WelineDomModule",
            description: "按需 DOM 微核：委托/出现即回调/声明式 data-weline-when|on"
        },
        welineSwitcher: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/weline-switcher.js"],
            paths: ["Weline_Frontend::js/weline-switcher.js"],
            globalVar: "WelineSwitcher",
            description: "Weline切换器组件（语言、货币等）"
        },
        cookie: {
            origin_paths: ["app/code/Weline/Frontend/view/statics/js/cookie.js"],
            paths: ["Weline_Frontend::js/cookie.js"],
            globalVar: null,
            description: "Cookie操作工具函数"
        },
        account: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-session.js"],
            paths: ["Weline_Customer::js/account-session.js"],
            globalVar: "WelineAccountModule",
            load: "defer",
            description: "前台账户会话与顶栏账户 chrome"
        },
        customerAccount: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-index.js?v=20260917-sidebar-empty-retry"],
            paths: ["Weline_Customer::js/account-index.js?v=20260917-sidebar-empty-retry"],
            globalVar: "WelineCustomerAccount",
            description: "前台用户中心账户页交互"
        },
        customerLogout: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-logout.js"],
            paths: ["Weline_Customer::js/account-logout.js"],
            globalVar: "WelineCustomerLogout",
            description: "前台账户退出确认"
        },
        customerSocialQuick: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-social-quick.js?v=20260914-google-fixed-start-1"],
            paths: ["Weline_Customer::js/account-social-quick.js?v=20260914-google-fixed-start-1"],
            globalVar: "WelineSocialQuick",
            description: "未登录右下角社媒快捷登录条（由 account JS 动态拉起）"
        },
        customerLoginPanel: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-login-panel.js?v=20260910-mount-self-provide"],
            paths: ["Weline_Customer::js/account-login-panel.js?v=20260910-mount-self-provide"],
            globalVar: "WelineLoginPanel",
            description: "可挂载完整登录面板（快捷+账密）"
        },
        accountTwoFactor: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/frontend/js/account-two-factor-inline-v2.js"],
            paths: ["Weline_TwoFactorAuth::frontend/js/account-two-factor-inline-v2.js"],
            globalVar: null,
            description: "账户中心两步验证面板"
        },
        checkoutCoupon: {
            origin_paths: ["app/code/Weline/Marketing/view/statics/js/widgets/checkout-coupon.js?v=20260922-cpay-coupon-paint1"],
            paths: ["Weline_Marketing::js/widgets/checkout-coupon.js?v=20260922-cpay-coupon-paint1"],
            globalVar: null,
            description: "结账/迷你购物车优惠券部件"
        },
        maintenanceAsyncWait: {
            origin_paths: ["app/code/Weline/Maintenance/view/statics/js/maintenance-async-wait.js"],
            paths: ["Weline_Maintenance::js/maintenance-async-wait.js"],
            globalVar: "WelineMaintenanceAsyncWait",
            description: "异步 503 等待弹层与同址 wait-gift 兑礼"
        },
        paymentLifecycle: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/payment-lifecycle.js?v=20260910-payment-lifecycle3"],
            paths: ["Weline_Payment::js/payment-lifecycle.js?v=20260910-payment-lifecycle3"],
            globalVar: "WelinePayment",
            load: "eager",
            description: "支付生命周期：weline:payment:* 统一事件"
        },
        productExpressPay: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/product-express-pay.js?v=20260918-express-ga4params1"],
            paths: ["Weline_Payment::js/product-express-pay.js?v=20260918-express-ga4params1"],
            globalVar: "WelineProductExpressPay",
            load: "lazy",
            description: "PDP 快捷智能支付：加车后 startExpressCheckout 并打开支付商窗体"
        },
        paypalWalletButtons: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/paypal-wallet-buttons.js?v=20260920-gpay-apay1"],
            paths: ["Weline_Payment::js/paypal-wallet-buttons.js?v=20260920-gpay-apay1"],
            globalVar: "WelineModules",
            load: "lazy",
            description: "PayPal JS SDK：Google Pay / Apple Pay funding 按钮容器"
        },
        orderNotice: {
            origin_paths: ["app/code/Weline/Order/view/statics/js/widgets/order-notice.js"],
            paths: ["Weline_Order::js/widgets/order-notice.js"],
            globalVar: null,
            description: "迷你购物车订单留言"
        },
        relatedProducts: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/related-products.js"],
            paths: ["Weline_Product::js/widgets/related-products.js"],
            globalVar: null,
            description: "相关商品轮播/网格"
        },
        recommendedProducts: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/recommended-products.js"],
            paths: ["Weline_Product::js/widgets/recommended-products.js"],
            globalVar: null,
            description: "推荐商品轮播/网格"
        },
        youMayLike: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/you-may-like.js"],
            paths: ["Weline_Product::js/widgets/you-may-like.js"],
            globalVar: null,
            description: "猜你喜欢轮播/网格"
        },
        crossSell: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/cross-sell.js"],
            paths: ["Weline_Product::js/widgets/cross-sell.js"],
            globalVar: null,
            description: "经常一起购买（FBT）"
        },
        productStickyPurchase: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/product-sticky-purchase.js?v=20260913-sticky-atc4"],
            paths: ["Weline_Product::js/widgets/product-sticky-purchase.js?v=20260913-sticky-atc4"],
            globalVar: null,
            description: "PDP 主加购滚出视野后的悬浮代理加购条"
        },
        productDetailReveal: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/product-detail-reveal.js?v=20260923-detail-reveal4"],
            paths: ["Weline_Product::js/widgets/product-detail-reveal.js?v=20260923-detail-reveal4"],
            globalVar: null,
            description: "PDP 详情杂志楼层滚轮入场（§5.4）"
        },
        recentlyViewed: {
            origin_paths: ["app/code/Weline/RecentlyViewed/view/statics/js/widgets/recently-viewed.js"],
            paths: ["Weline_RecentlyViewed::js/widgets/recently-viewed.js"],
            globalVar: null,
            description: "最近浏览轮播/网格"
        },
        location: {
            origin_paths: ["app/code/Weline/Location/view/statics/frontend/js/location.js"],
            paths: ["Weline_Location::frontend/js/location.js"],
            globalVar: "WelineLocation",
            description: "Location定位模块（浏览器定位和IP定位）"
        },
        customerService: {
            origin_paths: ["app/code/Weline/CustomerService/view/statics/js/customer-service.js"],
            paths: ["Weline_CustomerService::js/customer-service.js"],
            globalVar: "CustomerServiceWidget",
            description: "前台客服聊天部件"
        },
        geo: {
            origin_paths: ["app/code/Weline/Geo/view/statics/frontend/js/geo.js"],
            paths: ["Weline_Geo::frontend/js/geo.js"],
            globalVar: "WelineGeo",
            description: "Geo定位模块（浏览器定位和IP定位）"
        },
        shippingCheckoutAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.js?v=20260921-cpay-addr3"],
            paths: ["Weline_Shipping::js/widgets/checkout-shipping-address.js?v=20260921-cpay-addr3"],
            globalVar: "WelineShippingCheckoutAddress",
            description: "结账收货地址部件"
        },
        shippingAccountAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/frontend/js/account-address-v3.js?v=20260916-purpose-tags2"],
            paths: ["Weline_Shipping::frontend/js/account-address-v3.js?v=20260916-purpose-tags2"],
            globalVar: null,
            description: "账户中心发货/收货地址维护"
        },
        cart: {
            origin_paths: ["app/code/Weline/Cart/view/statics/js/cart.js?v=20260923-remove-from-cart-pixel1", "app/code/Weline/Cart/view/statics/js/cart-remove-pixel-stamp.js?v=20260923-remove-from-cart-pixel2", "app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js?v=20260922-purchase-panel-binquery"],
            paths: ["Weline_Cart::js/cart.js?v=20260923-remove-from-cart-pixel1", "Weline_Cart::js/cart-remove-pixel-stamp.js?v=20260923-remove-from-cart-pixel2", "Weline_Cart::js/widgets/product-purchase-actions.js?v=20260922-purchase-panel-binquery"],
            globalVar: "WelineCartPurchaseActions",
            load: "defer",
            description: "万能购物车：优惠券事件 / 游客续期 / 加购交互 / remove_from_cart 像素标记"
        },
        checkoutLifecycle: {
            origin_paths: ["app/code/Weline/Checkout/view/statics/js/checkout-lifecycle.js?v=20260910-checkout-lifecycle3"],
            paths: ["Weline_Checkout::js/checkout-lifecycle.js?v=20260910-checkout-lifecycle3"],
            globalVar: "WelineCheckout",
            load: "eager",
            description: "结账生命周期：weline:checkout:order-created / success"
        },
        checkoutExpressReview: {
            origin_paths: ["app/code/Weline/Checkout/view/statics/js/express-review.js?v=20260921-shipping-i18n1"],
            paths: ["Weline_Checkout::js/express-review.js?v=20260921-shipping-i18n1"],
            globalVar: "WelineCheckoutExpressReview",
            load: "lazy",
            description: "快捷支付回头确认页：摘要/缺口/确认收款"
        },
        b2bSellingMode: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/checkout-tob.js?v=20260914-goods-sync1", "app/code/Weline/B2B/view/statics/js/selling-mode.js?v=20260914-no-cart-chooser"],
            paths: ["Weline_B2B::js/checkout-tob.js?v=20260914-goods-sync1", "Weline_B2B::js/selling-mode.js?v=20260914-no-cart-chooser"],
            globalVar: "WelineB2BSellingMode",
            description: "B2B ToC/ToB selling mode + mini-cart/cart dual-type injection"
        },
        b2bCheckoutTob: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/checkout-tob.js?v=20260914-goods-sync1"],
            paths: ["Weline_B2B::js/checkout-tob.js?v=20260914-goods-sync1"],
            globalVar: "WelineB2BCheckoutTob",
            description: "B2B wholesale credit + checkout deposit note for tob carts"
        },
        b2bOrderChat: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/order-chat-accordion.js?v=20260912-order-chat4"],
            paths: ["Weline_B2B::js/order-chat-accordion.js?v=20260912-order-chat4"],
            globalVar: "WelineB2BOrderChat",
            description: "B2B order chat accordion (account + backend same thread)"
        },
        productReviews: {
            origin_paths: ["app/code/Weline/Review/view/statics/js/widgets/product-reviews.v20260904-pager2.js"],
            paths: ["Weline_Review::js/widgets/product-reviews.v20260904-pager2.js"],
            globalVar: "WelineReviewProductWidget",
            description: "万能评论部件（商品/博客共用）"
        },
        comparePage: {
            origin_paths: ["app/code/Weline/Compare/view/statics/js/compare-page.js"],
            paths: ["Weline_Compare::js/compare-page.js"],
            globalVar: "WelineComparePage",
            description: "商品对比页"
        },
        compareShopper: {
            origin_paths: ["app/code/Weline/Compare/view/statics/js/product-card-actions.js"],
            paths: ["Weline_Compare::js/product-card-actions.js"],
            globalVar: "WelineCompareShopper",
            load: "defer",
            description: "商品卡对比/快速查看/对比栏"
        },
        affiliateAccount: {
            origin_paths: ["app/code/Weline/Affiliate/view/statics/js/affiliate-account.js"],
            paths: ["Weline_Affiliate::js/affiliate-account.js"],
            globalVar: null,
            description: "账户中心分销工作台"
        },
        affiliateProductShare: {
            origin_paths: ["app/code/Weline/Affiliate/view/statics/js/affiliate-product-share.js?v=20260914-panel-share-url"],
            paths: ["Weline_Affiliate::js/affiliate-product-share.js?v=20260914-panel-share-url"],
            globalVar: "WelineAffiliateProductShare",
            description: "商品详情/加购弹窗分销分享（等账户会话后异步水合）"
        },
        helpPayShare: {
            origin_paths: ["app/code/Weline/HelpPay/view/statics/js/helppay-share.js?v=20260917-buybox-flow-auto1"],
            paths: ["Weline_HelpPay::js/helppay-share.js?v=20260917-buybox-flow-auto1"],
            globalVar: "WelineModules.helpPayShare",
            load: "defer",
            description: "帮我付 / 纯分享 / 快捷购买 / 商品找朋友代付：规则确认、出链双形态复制（样式由脚本注入主题 Token CSS）"
        },
        wishlist: {
            origin_paths: ["app/code/Weline/Wishlist/view/statics/js/wishlist-page.js"],
            paths: ["Weline_Wishlist::js/wishlist-page.js"],
            globalVar: "WelineWishlistModule",
            description: "心愿单列表页交互"
        },
        wishlistHeader: {
            origin_paths: ["app/code/Weline/Wishlist/view/statics/js/wishlist-header.js"],
            paths: ["Weline_Wishlist::js/wishlist-header.js"],
            globalVar: "WelineWishlistHeaderModule",
            description: "顶栏收藏角标水合（SSR 游客空角标）"
        },
        storeMusic: {
            paths: ["Weline_StoreMusic::js/store-music.js?v=20260917-storemusic-speccenter2"],
            globalVar: "WelineStoreMusic",
            load: "defer",
            description: "进店音乐"
        },
        newsletterSubscribe: {
            origin_paths: ["app/code/Weline/Newsletter/view/statics/js/newsletter-subscribe.js?v=20260922-deferred-p206"],
            paths: ["Weline_Newsletter::js/newsletter-subscribe.js?v=20260922-deferred-p206"],
            globalVar: "WelineNewsletterSubscribe",
            load: "defer",
            description: "邮件订阅表单（BinQuery / 弹窗 cookie）"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {
        language: "i18n",
        lang: "i18n",
        captcha: "captchaLazy",
        money: "currency",
        api: "welineApi",
        tokenStorage: "welineApiTokenStorage",
        worker: "welineApiWorker",
        dom: "welineDom",
        switcher: "welineSwitcher",
        payment: "paymentLifecycle",
        productExpressPay: "productExpressPay",
        paypalWalletButtons: "paypalWalletButtons",
        geolocation: "location",
        checkout: "checkoutLifecycle",
        checkoutExpressReview: "checkoutExpressReview"
    });
})();