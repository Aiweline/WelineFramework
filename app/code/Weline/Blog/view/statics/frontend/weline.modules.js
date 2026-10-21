/**
 * Weline Blog 前端 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    blogCategoryFilter: {
        paths: [
            "Weline_Blog::js/blog-category-filter.js?v=20261021-blog-category-filter-1"
        ],
        globalVar: "WelineBlogCategoryFilter",
        load: "eager",
        description: "博客分类侧栏两级树展开"
    }
});
