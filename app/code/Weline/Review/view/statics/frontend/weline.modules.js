/**
 * Weline Review 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    productReviews: {
        paths: [
            "Weline_Review::js/widgets/product-reviews.js"
        ],
        globalVar: "WelineReviewProductWidget",
        description: "万能评论部件（商品/博客共用）"
    }
});
