/**
 * Weline Theme 前台部件 JS 模块注册
 * 注意：模块名必须是可解析的裸标识符（编译器不识别带连字符的引号键）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    siteBlocks: {
        paths: ["Weline_Theme::js/widgets/site-blocks.js"],
        globalVar: "WelineSiteBlocks",
        dependencies: [],
        async: true
    },
    videoCarousel: {
        paths: ["Weline_Theme::js/widgets/video-carousel.js"],
        globalVar: "WelineVideoCarousel",
        dependencies: [],
        async: true,
        description: "首页/店面视频轮播切换与关联商品 dialog"
    },

    miniCartExtras: {
        paths: [
            "Weline_Theme::js/widgets/mini-cart-extras-tabs.js?v=20261009-eager-tabs1"
        ],
        globalVar: "WelineMiniCartExtras",
        // Must be eager: storefrontMoneySummary is eager; if extras defer, drawer shows
        // 收起明细+金额而优惠券/留言 Tab 尚未建壳（部件自身 visibility:hidden）。
        load: "eager",
        description: "迷你购物车 extras 页签交互"
    },
    miniCartIcon: {
        paths: [
            "Weline_Theme::js/widgets/mini-cart-icon.js?v=20261010-tob-retail-gate1"
        ],
        globalVar: "WelineMiniCartIcon",
        load: "eager",
        description: "迷你购物车图标与抽屉"
    },
    headerSearch: {
        paths: [
            "Weline_Theme::js/widgets/header-search.js"
        ],
        globalVar: null,
        description: "页头搜索框与分类子菜单"
    },
    storefrontImageFallback: {
        paths: [
            "Weline_Theme::js/storefront-image-fallback.js"
        ],
        globalVar: null,
        load: "defer",
        description: "店面图片占位回退"
    },
    storefrontFloatLayer: {
        paths: [
            "Weline_Theme::js/storefront-float-layer.js"
        ],
        globalVar: null,
        load: "defer",
        description: "店面左右悬浮槽 adopt 兜底与贴边收起"
    },
    storefrontShopperToast: {
        paths: [
            "Weline_Theme::js/storefront-shopper-toast.js"
        ],
        globalVar: null,
        load: "defer",
        description: "店面购物者 Toast 区域"
    },
    footerSocialFloat: {
        paths: [
            "Weline_Theme::js/widgets/footer-social-float.js"
        ],
        globalVar: "WelineFooterSocialFloat",
        async: true,
        description: "页脚侧边悬浮社媒贴边收起"
    }
});
