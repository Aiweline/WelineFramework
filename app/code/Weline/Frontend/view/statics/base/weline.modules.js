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
        aiIndex: {
            origin_paths: ["app/code/Weline/Ai/view/statics/js/ai-index.js?v=20261021-ai-index-1"],
            paths: ["Weline_Ai::js/ai-index.js?v=20261021-ai-index-1"],
            globalVar: null,
            load: "eager",
            description: "AI 首页粒子背景与滚动显现"
        },
        aiChat: {
            origin_paths: ["app/code/Weline/Ai/view/statics/js/ai-chat.js?v=20261021-ai-chat-1"],
            paths: ["Weline_Ai::js/ai-chat.js?v=20261021-ai-chat-1"],
            globalVar: null,
            load: "eager",
            description: "AI 聊天页应用"
        },
        themePrepaint: {
            origin_paths: ["app/code/Weline/Theme/view/statics/ui/weline-theme-prepaint.js"],
            paths: ["Weline_Theme::ui/weline-theme-prepaint.js"],
            globalVar: null,
            load: "eager",
            description: "html data-theme / colorScheme before interactive chrome"
        },
        welineUi: {
            origin_paths: ["app/code/Weline/Theme/view/statics/ui/weline-ui.js"],
            paths: ["Weline_Theme::ui/weline-ui.js"],
            globalVar: null,
            load: "eager",
            type: "module",
            description: "Weline UI 2 runtime (ES module: import.meta + lazy import(); loader must set type=module)"
        },
        themeCssReady: {
            origin_paths: ["app/code/Weline/Theme/view/statics/ui/weline-css-ready.js"],
            paths: ["Weline_Theme::ui/weline-css-ready.js"],
            globalVar: null,
            load: "defer",
            description: "FOUC pending→ready gate (legacy / design themes)"
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
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-extras-tabs.js?v=20261014-extras-tab-preserve1"],
            paths: ["Weline_Theme::js/widgets/mini-cart-extras-tabs.js?v=20261014-extras-tab-preserve1"],
            globalVar: "WelineMiniCartExtras",
            load: "eager",
            description: "迷你购物车 extras 页签交互"
        },
        miniCartIcon: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/widgets/mini-cart-icon.js?v=20261015-css-leaf-skip2"],
            paths: ["Weline_Theme::js/widgets/mini-cart-icon.js?v=20261015-css-leaf-skip2"],
            globalVar: "WelineMiniCartIcon",
            load: "eager",
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
        storefrontFloatLayer: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-float-layer.js"],
            paths: ["Weline_Theme::js/storefront-float-layer.js"],
            globalVar: null,
            load: "defer",
            description: "店面左右悬浮槽 adopt 兜底与贴边收起"
        },
        storefrontShopperToast: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-shopper-toast.js?v=20261015-shopper-toast-arch-v1"],
            paths: ["Weline_Theme::js/storefront-shopper-toast.js?v=20261015-shopper-toast-arch-v1"],
            globalVar: null,
            load: "defer",
            description: "店面购物者 Toast 区域（架构 CSS ensure）"
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
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-session.js?v=20261009-personal-center-header-race-1"],
            paths: ["Weline_Customer::js/account-session.js?v=20261009-personal-center-header-race-1"],
            globalVar: "WelineAccountModule",
            load: "defer",
            description: "前台账户会话与顶栏账户 chrome"
        },
        customerAccount: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-index.js?v=20261009-scope-css-hoist-retry"],
            paths: ["Weline_Customer::js/account-index.js?v=20261009-scope-css-hoist-retry"],
            globalVar: "WelineCustomerAccount",
            load: "eager",
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
        customerContactChat: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/customer-contact-chat.js?v=20261021-customer-contact-chat-1"],
            paths: ["Weline_Customer::js/customer-contact-chat.js?v=20261021-customer-contact-chat-1"],
            globalVar: "WelineCustomerContactChat",
            load: "eager",
            description: "联系我们页在线客服触发"
        },
        customerAccountSidebar: {
            origin_paths: ["app/code/Weline/Customer/view/statics/js/account-sidebar-menu.js?v=20261021-account-sidebar-menu-1"],
            paths: ["Weline_Customer::js/account-sidebar-menu.js?v=20261021-account-sidebar-menu-1"],
            globalVar: null,
            load: "eager",
            description: "用户中心侧栏菜单展开/收起"
        },
        accountTwoFactor: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/frontend/js/account-two-factor-inline-v2.js"],
            paths: ["Weline_TwoFactorAuth::frontend/js/account-two-factor-inline-v2.js"],
            globalVar: null,
            description: "账户中心两步验证面板"
        },
        twofaIndex: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/js/twofa-index.js?v=20261021-twofa-index-1"],
            paths: ["Weline_TwoFactorAuth::js/twofa-index.js?v=20261021-twofa-index-1"],
            globalVar: null,
            load: "eager",
            description: "双因素验证管理页交互"
        },
        twofaSetup: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/js/twofa-setup.js?v=20261021-twofa-setup-1"],
            paths: ["Weline_TwoFactorAuth::js/twofa-setup.js?v=20261021-twofa-setup-1"],
            globalVar: null,
            load: "eager",
            description: "双因素验证设置页交互"
        },
        twofaAccounts: {
            origin_paths: ["app/code/Weline/TwoFactorAuth/view/statics/js/twofa-accounts.js?v=20261021-twofa-accounts-1"],
            paths: ["Weline_TwoFactorAuth::js/twofa-accounts.js?v=20261021-twofa-accounts-1"],
            globalVar: null,
            load: "eager",
            description: "验证器账户管理页交互"
        },
        checkoutCoupon: {
            origin_paths: ["app/code/Weline/Marketing/view/statics/js/widgets/checkout-coupon.js?v=20261007-coupon-no-double-hydrate"],
            paths: ["Weline_Marketing::js/widgets/checkout-coupon.js?v=20261007-coupon-no-double-hydrate"],
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
            origin_paths: ["app/code/Weline/Payment/view/statics/js/product-express-pay.js?v=20260926-buy-now-isol1"],
            paths: ["Weline_Payment::js/product-express-pay.js?v=20260926-buy-now-isol1"],
            globalVar: "WelineProductExpressPay",
            load: "lazy",
            description: "PDP 快捷智能支付：buy_now 只结当前商品并打开支付商窗体"
        },
        paypalWalletButtons: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/paypal-wallet-buttons.js?v=20260920-gpay-apay1"],
            paths: ["Weline_Payment::js/paypal-wallet-buttons.js?v=20260920-gpay-apay1"],
            globalVar: "WelineModules",
            load: "lazy",
            description: "PayPal JS SDK：Google Pay / Apple Pay funding 按钮容器"
        },
        paypalCardFields: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/paypal-card-fields.js?v=20261025-classic1"],
            paths: ["Weline_Payment::js/paypal-card-fields.js?v=20261025-classic1"],
            globalVar: "WelineModules",
            load: "eager",
            description: "PayPal 站内银行卡收银台（Card Fields / ACDC）"
        },
        paymentHandoff: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/payment-handoff.js?v=20261021-payment-handoff-1"],
            paths: ["Weline_Payment::js/payment-handoff.js?v=20261021-payment-handoff-1"],
            globalVar: null,
            load: "eager",
            description: "支付交接页轮询"
        },
        paymentMethods: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/payment-methods.js?v=20261021-payment-methods-1"],
            paths: ["Weline_Payment::js/payment-methods.js?v=20261021-payment-methods-1"],
            globalVar: null,
            load: "eager",
            description: "支付方式选择与提交"
        },
        paymentPixel: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/payment-pixel.js?v=20261021-payment-pixel-1"],
            paths: ["Weline_Payment::js/payment-pixel.js?v=20261021-payment-pixel-1"],
            globalVar: null,
            load: "eager",
            description: "支付成功像素埋点（payload 由 JSON 配置块提供）"
        },
        orderNotice: {
            origin_paths: ["app/code/Weline/Order/view/statics/js/widgets/order-notice.js"],
            paths: ["Weline_Order::js/widgets/order-notice.js"],
            globalVar: null,
            description: "迷你购物车订单留言"
        },
        storefrontSearchResults: {
            origin_paths: ["app/code/Weline/Search/view/statics/js/storefront-search-results.js"],
            paths: ["Weline_Search::js/storefront-search-results.js"],
            globalVar: "WelineStorefrontSearchResults",
            async: true,
            description: "搜索页壳 FPC：异步 BinQuery 填充结果"
        },
        searchTypeFilter: {
            origin_paths: ["app/code/Weline/Search/view/statics/js/search-type-filter.js?v=20261021-search-type-filter-1"],
            paths: ["Weline_Search::js/search-type-filter.js?v=20261021-search-type-filter-1"],
            globalVar: "WelineSearchTypeFilter",
            load: "eager",
            description: "搜索侧栏类型/分类树折叠与提交"
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
            origin_paths: ["app/code/Weline/Product/view/statics/js/widgets/product-detail-reveal.js?v=20261009-detail-reveal5"],
            paths: ["Weline_Product::js/widgets/product-detail-reveal.js?v=20261009-detail-reveal5"],
            globalVar: null,
            description: "PDP 详情杂志楼层滚轮入场（§5.4）"
        },
        storefrontListing: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/storefront-listing.js?v=20261013-facet-lean1"],
            paths: ["Weline_Product::js/storefront-listing.js?v=20261013-facet-lean1"],
            globalVar: "WelineStorefrontListing",
            async: true,
            description: "PLP path-only：筛选/排序/分页一步 BinQuery 换格"
        },
        productCategoryLayout: {
            origin_paths: ["app/code/Weline/Product/view/statics/js/category-layout.js?v=20261028-filter-drawer-2"],
            paths: ["Weline_Product::js/category-layout.js?v=20261028-filter-drawer-2"],
            globalVar: null,
            load: "eager",
            description: "分类页布局侧栏/筛选抽屉"
        },
        recentlyViewed: {
            origin_paths: ["app/code/Weline/RecentlyViewed/view/statics/js/widgets/recently-viewed.js"],
            paths: ["Weline_RecentlyViewed::js/widgets/recently-viewed.js"],
            globalVar: null,
            description: "最近浏览轮播/网格"
        },
        mailAccountSelect: {
            origin_paths: ["app/code/Weline/Mail/view/statics/js/mail-account-select.js?v=20261021-mail-account-select-1"],
            paths: ["Weline_Mail::js/mail-account-select.js?v=20261021-mail-account-select-1"],
            globalVar: "WelineMailAccountSelect",
            load: "eager",
            description: "邮箱账户切换（下拉变更即提交）"
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
        customerServiceFloat: {
            origin_paths: ["app/code/Weline/CustomerService/view/statics/js/customer-service-float.js?v=20261021-cs-float-1"],
            paths: ["Weline_CustomerService::js/customer-service-float.js?v=20261021-cs-float-1"],
            globalVar: null,
            load: "eager",
            description: "浮动客服部件启动（懒加载 customer-service.js）"
        },
        csBindVerify: {
            origin_paths: ["app/code/Weline/CustomerService/view/statics/js/cs-bind-verify.js?v=20261021-cs-bind-verify-1"],
            paths: ["Weline_CustomerService::js/cs-bind-verify.js?v=20261021-cs-bind-verify-1"],
            globalVar: null,
            load: "eager",
            description: "邮箱绑定验证结果页（BroadcastChannel/localStorage 同步）"
        },
        geo: {
            origin_paths: ["app/code/Weline/Geo/view/statics/frontend/js/geo.js"],
            paths: ["Weline_Geo::frontend/js/geo.js"],
            globalVar: "WelineGeo",
            description: "Geo定位模块（浏览器定位和IP定位）"
        },
        shippingCheckoutAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/js/widgets/checkout-shipping-address.js?v=20261023-phone-intl-1"],
            paths: ["Weline_Shipping::js/widgets/checkout-shipping-address.js?v=20261023-phone-intl-1"],
            globalVar: "WelineShippingCheckoutAddress",
            description: "结账收货地址部件"
        },
        shippingAccountAddress: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/frontend/js/account-address-v3.js?v=20261012-default-country2"],
            paths: ["Weline_Shipping::frontend/js/account-address-v3.js?v=20261012-default-country2"],
            globalVar: null,
            description: "账户中心发货/收货地址维护"
        },
        shippingDeliveryAddressPage: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/js/shipping-delivery-address.js?v=20261021-delivery-address-page-1"],
            paths: ["Weline_Shipping::js/shipping-delivery-address.js?v=20261021-delivery-address-page-1"],
            globalVar: null,
            description: "收货地址列表页交互"
        },
        shippingAddressPage: {
            origin_paths: ["app/code/Weline/Shipping/view/statics/js/shipping-address-page.js?v=20261021-shipping-address-page-1"],
            paths: ["Weline_Shipping::js/shipping-address-page.js?v=20261021-shipping-address-page-1"],
            globalVar: null,
            description: "发货地址列表页交互"
        },
        cart: {
            origin_paths: ["app/code/Weline/Theme/view/statics/js/storefront-shopper-toast.js?v=20261015-shopper-toast-arch-v1", "app/code/Weline/Cart/view/statics/js/cart.js?v=20261015-qty-hit-fold-v1", "app/code/Weline/Cart/view/statics/js/cart-remove-pixel-stamp.js?v=20260923-remove-from-cart-pixel2", "app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions-w18n-v3.js?v=20261015-shopper-toast-v1"],
            paths: ["Weline_Theme::js/storefront-shopper-toast.js?v=20261015-shopper-toast-arch-v1", "Weline_Cart::js/cart.js?v=20261015-qty-hit-fold-v1", "Weline_Cart::js/cart-remove-pixel-stamp.js?v=20260923-remove-from-cart-pixel2", "Weline_Cart::js/widgets/product-purchase-actions-w18n-v3.js?v=20261015-shopper-toast-v1"],
            globalVar: "WelineCartPurchaseActions",
            load: "defer",
            description: "万能购物车：优惠券事件 / 游客续期 / 加购交互 / remove_from_cart 像素标记 / shopper toast"
        },
        cartPage: {
            origin_paths: ["app/code/Weline/Cart/view/statics/js/cart-page.js?v=20261021-cart-page-1"],
            paths: ["Weline_Cart::js/cart-page.js?v=20261021-cart-page-1"],
            globalVar: null,
            load: "eager",
            description: "购物车页交互应用"
        },
        checkoutLifecycle: {
            origin_paths: ["app/code/Weline/Checkout/view/statics/js/checkout-lifecycle.js?v=20260910-checkout-lifecycle3"],
            paths: ["Weline_Checkout::js/checkout-lifecycle.js?v=20260910-checkout-lifecycle3"],
            globalVar: "WelineCheckout",
            load: "eager",
            description: "结账生命周期：weline:checkout:order-created / success"
        },
        checkoutExpressReview: {
            origin_paths: ["app/code/Weline/Checkout/view/statics/js/express-review.js?v=20260927-payment-method-i18n"],
            paths: ["Weline_Checkout::js/express-review.js?v=20260927-payment-method-i18n"],
            globalVar: "WelineCheckoutExpressReview",
            load: "lazy",
            description: "快捷支付回头确认页：摘要/缺口/确认收款"
        },
        storefrontMoneySummary: {
            origin_paths: ["app/code/Weline/Checkout/view/statics/js/widgets/storefront-money-summary.js?v=20261010-tob-order-total1"],
            paths: ["Weline_Checkout::js/widgets/storefront-money-summary.js?v=20261010-tob-order-total1"],
            globalVar: "WelineStorefrontMoneySummary",
            load: "eager",
            description: "店面金额小计：统一 paint 商品/运费/税费/优惠/应付"
        },
        blogCategoryFilter: {
            origin_paths: ["app/code/Weline/Blog/view/statics/js/blog-category-filter.js?v=20261021-blog-category-filter-1"],
            paths: ["Weline_Blog::js/blog-category-filter.js?v=20261021-blog-category-filter-1"],
            globalVar: "WelineBlogCategoryFilter",
            load: "eager",
            description: "博客分类侧栏两级树展开"
        },
        b2bSellingMode: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/checkout-tob.js?v=20261010-shell-toc-wins1", "app/code/Weline/B2B/view/statics/js/selling-mode.js?v=20261014-extras-boot-preserve1"],
            paths: ["Weline_B2B::js/checkout-tob.js?v=20261010-shell-toc-wins1", "Weline_B2B::js/selling-mode.js?v=20261014-extras-boot-preserve1"],
            globalVar: "WelineB2BSellingMode",
            description: "B2B ToC/ToB selling mode + mini-cart/cart dual-type injection"
        },
        b2bCheckoutTob: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/checkout-tob.js?v=20261010-shell-toc-wins1"],
            paths: ["Weline_B2B::js/checkout-tob.js?v=20261010-shell-toc-wins1"],
            globalVar: "WelineB2BCheckoutTob",
            description: "B2B wholesale credit + checkout deposit note for tob carts"
        },
        b2bOrderChat: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/order-chat-accordion.js?v=20260912-order-chat4"],
            paths: ["Weline_B2B::js/order-chat-accordion.js?v=20260912-order-chat4"],
            globalVar: "WelineB2BOrderChat",
            description: "B2B order chat accordion (account + backend same thread)"
        },
        b2bSellingModeBoot: {
            origin_paths: ["app/code/Weline/B2B/view/statics/js/selling-mode-boot.js?v=20261021-selling-mode-boot-1"],
            paths: ["Weline_B2B::js/selling-mode-boot.js?v=20261021-selling-mode-boot-1"],
            globalVar: null,
            load: "eager",
            description: "B2B 购买方式切换兜底启动（主包缓存失效时仍生效）"
        },
        consentBanner: {
            origin_paths: ["app/code/Weline/Consent/view/statics/js/consent-banner.js?v=20261021-consent-banner-1"],
            paths: ["Weline_Consent::js/consent-banner.js?v=20261021-consent-banner-1"],
            globalVar: "WelineConsentBanner",
            load: "eager",
            description: "店面 Cookie 同意横幅"
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
            origin_paths: ["app/code/Weline/Compare/view/statics/js/product-card-actions.js?v=20261010-qv-product-info1"],
            paths: ["Weline_Compare::js/product-card-actions.js?v=20261010-qv-product-info1"],
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
        affiliateFloat: {
            origin_paths: ["app/code/Weline/Affiliate/view/statics/js/affiliate-float.js?v=20261025-rules-login-gate-4"],
            paths: ["Weline_Affiliate::js/affiliate-float.js?v=20261025-rules-login-gate-4"],
            globalVar: "WelineAffiliateFloat",
            description: "右侧中悬浮分销面板（游客展示规则，登录后水合个人分销信息）"
        },
        helpPayShare: {
            origin_paths: ["app/code/Weline/HelpPay/view/statics/js/helppay-share.js?v=20261006-cartamt1"],
            paths: ["Weline_HelpPay::js/helppay-share.js?v=20261006-cartamt1"],
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
            origin_paths: ["app/code/Weline/StoreMusic/view/statics/js/store-music.js?v=20261014-pause-closes-autoplay"],
            paths: ["Weline_StoreMusic::js/store-music.js?v=20261014-pause-closes-autoplay"],
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
        },
        daocharmsTown: {
            origin_paths: ["app/code/Weline/Daocharms3d/view/statics/js/bootstrap.js"],
            paths: ["Weline_Daocharms3d::js/bootstrap.js"],
            globalVar: "WelineDaocharmsTown",
            load: "eager",
            description: "真实商品三维镇内选购"
        },
        pointsCheckout: {
            origin_paths: ["app/code/Weline/Points/view/statics/js/points-discount.js?v=20261027-1"],
            paths: ["Weline_Points::js/points-discount.js?v=20261027-1"],
            globalVar: "WelinePointsCheckout",
            description: "Points redemption quote/apply on cart"
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
        paypalCardFields: "paypalCardFields",
        geolocation: "location",
        checkout: "checkoutLifecycle",
        checkoutExpressReview: "checkoutExpressReview",
        storefrontMoneySummary: "storefrontMoneySummary"
    });
})();