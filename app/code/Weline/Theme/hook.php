<?php
/**
 * Weline_Theme 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_Theme 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 * 
 * 所有 Hook 必须在 Weline\Framework\Hook\HookInterface 中定义为常量
 */
return [
    // ==================== Theme Frontend Partials - Footer ====================
    'Weline_Theme::frontend::partials::footer::before' => [
        'name' => __('页脚之前'),
        'description' => __('在渲染页脚组件之前触发，允许其他模块在页脚开始处注入内容。'),
        'doc' => 'frontend/partials/footer/before.md',
    ],
    'Weline_Theme::frontend::partials::footer::content-before' => [
        'name' => __('页脚内容之前'),
        'description' => __('在渲染页脚主要内容之前触发，允许其他模块在页脚内容开始处注入内容。'),
        'doc' => 'frontend/partials/footer/content-before.md',
    ],
    'Weline_Theme::frontend::partials::footer::section-before' => [
        'name' => __('页脚区块之前'),
        'description' => __('在渲染页脚区块之前触发，允许其他模块在页脚区块开始处注入内容。'),
        'doc' => 'frontend/partials/footer/section-before.md',
    ],
    'Weline_Theme::frontend::partials::footer::section-after' => [
        'name' => __('页脚区块之后'),
        'description' => __('在渲染页脚区块之后触发，允许其他模块在页脚区块结束处注入内容。'),
        'doc' => 'frontend/partials/footer/section-after.md',
    ],
    'Weline_Theme::frontend::partials::footer::content-after' => [
        'name' => __('页脚内容之后'),
        'description' => __('在渲染页脚主要内容之后触发，允许其他模块在页脚内容结束处注入内容。'),
        'doc' => 'frontend/partials/footer/content-after.md',
    ],
    'Weline_Theme::frontend::partials::footer::social-media-before' => [
        'name' => __('页脚社交媒体之前'),
        'description' => __('在渲染页脚社交媒体链接之前触发，允许其他模块在社交媒体区域开始处注入内容。'),
        'doc' => 'frontend/partials/footer/social-media-before.md',
    ],
    'Weline_Theme::frontend::partials::footer::social-media-links-before' => [
        'name' => __('页脚社交媒体链接之前'),
        'description' => __('在渲染页脚社交媒体链接列表之前触发，允许其他模块在链接列表开始处注入内容。'),
        'doc' => 'frontend/partials/footer/social-media-links-before.md',
    ],
    'Weline_Theme::frontend::partials::footer::social-media-links-after' => [
        'name' => __('页脚社交媒体链接之后'),
        'description' => __('在渲染页脚社交媒体链接列表之后触发，允许其他模块在链接列表结束处注入内容。'),
        'doc' => 'frontend/partials/footer/social-media-links-after.md',
    ],
    'Weline_Theme::frontend::partials::footer::social-media-after' => [
        'name' => __('页脚社交媒体之后'),
        'description' => __('在渲染页脚社交媒体链接之后触发，允许其他模块在社交媒体区域结束处注入内容。'),
        'doc' => 'frontend/partials/footer/social-media-after.md',
    ],
    'Weline_Theme::frontend::partials::footer::copyright-before' => [
        'name' => __('页脚版权信息之前'),
        'description' => __('在渲染页脚版权信息之前触发，允许其他模块在版权信息开始处注入内容。'),
        'doc' => 'frontend/partials/footer/copyright-before.md',
    ],
    'Weline_Theme::frontend::partials::footer::copyright-after' => [
        'name' => __('页脚版权信息之后'),
        'description' => __('在渲染页脚版权信息之后触发，允许其他模块在版权信息结束处注入内容。'),
        'doc' => 'frontend/partials/footer/copyright-after.md',
    ],
    'Weline_Theme::frontend::partials::footer::after' => [
        'name' => __('页脚之后'),
        'description' => __('在渲染页脚组件之后触发，允许其他模块在页脚结束处注入内容。'),
        'doc' => 'frontend/partials/footer/after.md',
    ],
    
    // ==================== Theme Frontend Partials - Header ====================
    'Weline_Theme::frontend::partials::header::before' => [
        'name' => __('页头之前'),
        'description' => __('在渲染页头组件之前触发，允许其他模块在页头开始处注入内容。'),
        'doc' => 'frontend/partials/header/before.md',
    ],
    'Weline_Theme::frontend::partials::header::logo-before' => [
        'name' => __('页头 Logo 之前'),
        'description' => __('在渲染页头 Logo 之前触发，允许其他模块在 Logo 开始处注入内容。'),
        'doc' => 'frontend/partials/header/logo-before.md',
    ],
    'Weline_Theme::frontend::partials::header::logo-after' => [
        'name' => __('页头 Logo 之后'),
        'description' => __('在渲染页头 Logo 之后触发，允许其他模块在 Logo 结束处注入内容。'),
        'doc' => 'frontend/partials/header/logo-after.md',
    ],
    'Weline_Theme::frontend::partials::header::categories-before' => [
        'name' => __('页头分类菜单之前'),
        'description' => __('在渲染页头分类菜单之前触发，允许其他模块在分类菜单开始处注入内容。'),
        'doc' => 'frontend/partials/header/categories-before.md',
    ],
    'Weline_Theme::frontend::partials::header::categories-after' => [
        'name' => __('页头分类菜单之后'),
        'description' => __('在渲染页头分类菜单之后触发，允许其他模块在分类菜单结束处注入内容。'),
        'doc' => 'frontend/partials/header/categories-after.md',
    ],
    'Weline_Theme::frontend::partials::header::nav-before' => [
        'name' => __('页头导航之前'),
        'description' => __('在渲染页头导航菜单之前触发，允许其他模块在导航菜单开始处注入内容。'),
        'doc' => 'frontend/partials/header/nav-before.md',
    ],
    'Weline_Theme::frontend::partials::header::nav-after' => [
        'name' => __('页头导航之后'),
        'description' => __('在渲染页头导航菜单之后触发，允许其他模块在导航菜单结束处注入内容。'),
        'doc' => 'frontend/partials/header/nav-after.md',
    ],
    'Weline_Theme::frontend::partials::header::search-before' => [
        'name' => __('页头搜索之前'),
        'description' => __('在渲染页头搜索框之前触发，允许其他模块在搜索框开始处注入内容。'),
        'doc' => 'frontend/partials/header/search-before.md',
    ],
    'Weline_Theme::frontend::partials::header::search-after' => [
        'name' => __('页头搜索之后'),
        'description' => __('在渲染页头搜索框之后触发，允许其他模块在搜索框结束处注入内容。'),
        'doc' => 'frontend/partials/header/search-after.md',
    ],
    'Weline_Theme::frontend::partials::header::actions-before' => [
        'name' => __('页头操作按钮之前'),
        'description' => __('在渲染页头操作按钮（如购物车、用户菜单等）之前触发，允许其他模块在操作按钮区域开始处注入内容。'),
        'doc' => 'frontend/partials/header/actions-before.md',
    ],
    'Weline_Theme::frontend::partials::header::actions-after' => [
        'name' => __('页头操作按钮之后'),
        'description' => __('在渲染页头操作按钮（如购物车、用户菜单等）之后触发，允许其他模块在操作按钮区域结束处注入内容。'),
        'doc' => 'frontend/partials/header/actions-after.md',
    ],
    'Weline_Theme::frontend::partials::header::after' => [
        'name' => __('页头之后'),
        'description' => __('在渲染页头组件之后触发，允许其他模块在页头结束处注入内容。'),
        'doc' => 'frontend/partials/header/after.md',
    ],
    
    // ==================== Theme Frontend Layouts - Base ====================
    'Weline_Theme::frontend::layouts::base::html-lang' => [
        'name' => __('基础布局 HTML 语言属性'),
        'description' => __('在渲染基础布局的 <html> 标签的 lang 属性时触发，允许其他模块自定义语言代码。此 hook 适用于所有使用基础布局的页面。默认返回当前语言代码。'),
        'doc' => 'frontend/layouts/base/html-lang.md',
    ],
    'Weline_Theme::frontend::layouts::base::html-lang-end' => [
        'name' => __('基础布局 HTML 语言属性结束'),
        'description' => __('在渲染基础布局的 <html> 标签的 lang 属性之后触发，允许其他模块在 lang 属性后注入额外内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/html-lang-end.md',
    ],
    'Weline_Theme::frontend::layouts::base::head-before' => [
        'name' => __('基础布局头部之前'),
        'description' => __('在渲染基础布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::base::head-after' => [
        'name' => __('基础布局头部之后'),
        'description' => __('在渲染基础布局的 <head> 标签之后、</head> 之前触发，允许其他模块在头部结束处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::base::body-start' => [
        'name' => __('基础布局 Body 开始'),
        'description' => __('在渲染基础布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::base::body-end' => [
        'name' => __('基础布局 Body 结束'),
        'description' => __('在渲染基础布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/body-end.md',
    ],
    'Weline_Theme::frontend::layouts::base::header-before' => [
        'name' => __('基础布局页头之前'),
        'description' => __('在渲染基础布局的页头之前触发，允许其他模块在页头开始处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/header-before.md',
    ],
    'Weline_Theme::frontend::layouts::base::header-after' => [
        'name' => __('基础布局页头之后'),
        'description' => __('在渲染基础布局的页头之后触发，允许其他模块在页头结束处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/header-after.md',
    ],
    'Weline_Theme::frontend::layouts::base::content-before' => [
        'name' => __('基础布局内容之前'),
        'description' => __('在渲染基础布局的主要内容之前触发，允许其他模块在内容开始处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::base::content-after' => [
        'name' => __('基础布局内容之后'),
        'description' => __('在渲染基础布局的主要内容之后触发，允许其他模块在内容结束处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::base::footer-before' => [
        'name' => __('基础布局页脚之前'),
        'description' => __('在渲染基础布局的页脚之前触发，允许其他模块在页脚开始处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/footer-before.md',
    ],
    'Weline_Theme::frontend::layouts::base::footer-after' => [
        'name' => __('基础布局页脚之后'),
        'description' => __('在渲染基础布局的页脚之后触发，允许其他模块在页脚结束处注入内容。此 hook 适用于所有使用基础布局的页面。'),
        'doc' => 'frontend/layouts/base/footer-after.md',
    ],
    
    // ==================== Theme Frontend Layouts - Homepage ====================
    'Weline_Theme::frontend::layouts::homepage::html-lang-end' => [
        'name' => __('首页布局 HTML 语言属性结束'),
        'description' => __('在渲染首页布局的 <html> 标签的 lang 属性之后触发，允许其他模块在 lang 属性后注入额外内容。此 hook 仅适用于首页布局。'),
        'doc' => 'frontend/layouts/homepage/html-lang-end.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::head-before' => [
        'name' => __('首页头部之前'),
        'description' => __('在渲染首页布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::head-after' => [
        'name' => __('首页头部之后'),
        'description' => __('在渲染首页布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::body-start' => [
        'name' => __('首页 Body 开始'),
        'description' => __('在渲染首页布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::header-before' => [
        'name' => __('首页页头之前'),
        'description' => __('在渲染首页布局的页头之前触发，允许其他模块在页头开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/header-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::header-after' => [
        'name' => __('首页页头之后'),
        'description' => __('在渲染首页布局的页头之后触发，允许其他模块在页头结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/header-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::content-before' => [
        'name' => __('首页内容之前'),
        'description' => __('在渲染首页布局的主要内容之前触发，允许其他模块在内容开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::content-after' => [
        'name' => __('首页内容之后'),
        'description' => __('在渲染首页布局的主要内容之后触发，允许其他模块在内容结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::footer-before' => [
        'name' => __('首页页脚之前'),
        'description' => __('在渲染首页布局的页脚之前触发，允许其他模块在页脚开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/footer-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::footer-after' => [
        'name' => __('首页页脚之后'),
        'description' => __('在渲染首页布局的页脚之后触发，允许其他模块在页脚结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/footer-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::body-end' => [
        'name' => __('首页 Body 结束'),
        'description' => __('在渲染首页布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/body-end.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::statistics-before' => [
        'name' => __('首页统计数据区块之前'),
        'description' => __('在渲染首页布局的统计数据区块之前触发，允许其他模块在统计数据区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/statistics-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::statistics-after' => [
        'name' => __('首页统计数据区块之后'),
        'description' => __('在渲染首页布局的统计数据区块之后触发，允许其他模块在统计数据区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/statistics-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::features-before' => [
        'name' => __('首页功能特性区块之前'),
        'description' => __('在渲染首页布局的功能特性区块之前触发，允许其他模块在功能特性区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/features-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::features-after' => [
        'name' => __('首页功能特性区块之后'),
        'description' => __('在渲染首页布局的功能特性区块之后触发，允许其他模块在功能特性区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/features-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::products-before' => [
        'name' => __('首页产品推荐区块之前'),
        'description' => __('在渲染首页布局的产品推荐区块之前触发，允许其他模块在产品推荐区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/products-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::products-after' => [
        'name' => __('首页产品推荐区块之后'),
        'description' => __('在渲染首页布局的产品推荐区块之后触发，允许其他模块在产品推荐区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/products-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::testimonials-before' => [
        'name' => __('首页客户评价区块之前'),
        'description' => __('在渲染首页布局的客户评价区块之前触发，允许其他模块在客户评价区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/testimonials-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::testimonials-after' => [
        'name' => __('首页客户评价区块之后'),
        'description' => __('在渲染首页布局的客户评价区块之后触发，允许其他模块在客户评价区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/testimonials-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::news-before' => [
        'name' => __('首页新闻动态区块之前'),
        'description' => __('在渲染首页布局的新闻动态区块之前触发，允许其他模块在新闻动态区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/news-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::news-after' => [
        'name' => __('首页新闻动态区块之后'),
        'description' => __('在渲染首页布局的新闻动态区块之后触发，允许其他模块在新闻动态区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/news-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::partners-before' => [
        'name' => __('首页合作伙伴区块之前'),
        'description' => __('在渲染首页布局的合作伙伴区块之前触发，允许其他模块在合作伙伴区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/partners-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::partners-after' => [
        'name' => __('首页合作伙伴区块之后'),
        'description' => __('在渲染首页布局的合作伙伴区块之后触发，允许其他模块在合作伙伴区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/partners-after.md',
    ],
    
    // ==================== Theme Frontend Layouts - Default ====================
    'Weline_Theme::frontend::layouts::default::head-before' => [
        'name' => __('默认布局头部之前'),
        'description' => __('在渲染默认布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。'),
        'doc' => 'frontend/layouts/default/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::default::head-after' => [
        'name' => __('默认布局头部之后'),
        'description' => __('在渲染默认布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/default/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::default::body-start' => [
        'name' => __('默认布局 Body 开始'),
        'description' => __('在渲染默认布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。'),
        'doc' => 'frontend/layouts/default/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::default::content-before' => [
        'name' => __('默认布局内容之前'),
        'description' => __('在渲染默认布局的主要内容之前触发，允许其他模块在内容开始处注入内容。'),
        'doc' => 'frontend/layouts/default/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::default::content-after' => [
        'name' => __('默认布局内容之后'),
        'description' => __('在渲染默认布局的主要内容之后触发，允许其他模块在内容结束处注入内容。'),
        'doc' => 'frontend/layouts/default/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::default::body-end' => [
        'name' => __('默认布局 Body 结束'),
        'description' => __('在渲染默认布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。'),
        'doc' => 'frontend/layouts/default/body-end.md',
    ],
    
    // ==================== Theme Frontend Layouts - Account ====================
    'Weline_Theme::frontend::layouts::account::head-before' => [
        'name' => __('账户布局头部之前'),
        'description' => __('在渲染账户布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。'),
        'doc' => 'frontend/layouts/account/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::account::head-after' => [
        'name' => __('账户布局头部之后'),
        'description' => __('在渲染账户布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/account/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::account::body-start' => [
        'name' => __('账户布局 Body 开始'),
        'description' => __('在渲染账户布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。'),
        'doc' => 'frontend/layouts/account/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::account::sidebar-before' => [
        'name' => __('账户布局侧边栏之前'),
        'description' => __('在渲染账户布局的侧边栏之前触发，允许其他模块在侧边栏开始处注入内容。'),
        'doc' => 'frontend/layouts/account/sidebar-before.md',
    ],
    'Weline_Theme::frontend::layouts::account::sidebar-after' => [
        'name' => __('账户布局侧边栏之后'),
        'description' => __('在渲染账户布局的侧边栏之后触发，允许其他模块在侧边栏结束处注入内容。'),
        'doc' => 'frontend/layouts/account/sidebar-after.md',
    ],
    'Weline_Theme::frontend::layouts::account::content-before' => [
        'name' => __('账户布局内容之前'),
        'description' => __('在渲染账户布局的主要内容之前触发，允许其他模块在内容开始处注入内容。'),
        'doc' => 'frontend/layouts/account/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::account::content-after' => [
        'name' => __('账户布局内容之后'),
        'description' => __('在渲染账户布局的主要内容之后触发，允许其他模块在内容结束处注入内容。'),
        'doc' => 'frontend/layouts/account/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::account::body-end' => [
        'name' => __('账户布局 Body 结束'),
        'description' => __('在渲染账户布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。'),
        'doc' => 'frontend/layouts/account/body-end.md',
    ],
    
    // ==================== Theme Frontend Layouts - Product Detail ====================
    'Weline_Theme::frontend::layouts::product-detail::head-before' => [
        'name' => __('产品详情布局头部之前'),
        'description' => __('在渲染产品详情布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-detail::head-after' => [
        'name' => __('产品详情布局头部之后'),
        'description' => __('在渲染产品详情布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-detail::body-start' => [
        'name' => __('产品详情布局 Body 开始'),
        'description' => __('在渲染产品详情布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::product-detail::content-before' => [
        'name' => __('产品详情布局内容之前'),
        'description' => __('在渲染产品详情布局的主要内容之前触发，允许其他模块在内容开始处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-detail::content-after' => [
        'name' => __('产品详情布局内容之后'),
        'description' => __('在渲染产品详情布局的主要内容之后触发，允许其他模块在内容结束处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-detail::body-end' => [
        'name' => __('产品详情布局 Body 结束'),
        'description' => __('在渲染产品详情布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。'),
        'doc' => 'frontend/layouts/product_detail/body-end.md',
    ],
    
    // ==================== Theme Frontend Layouts - Product List ====================
    'Weline_Theme::frontend::layouts::product-list::head-before' => [
        'name' => __('产品列表布局头部之前'),
        'description' => __('在渲染产品列表布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。'),
        'doc' => 'frontend/layouts/product_list/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::head-after' => [
        'name' => __('产品列表布局头部之后'),
        'description' => __('在渲染产品列表布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/product_list/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::body-start' => [
        'name' => __('产品列表布局 Body 开始'),
        'description' => __('在渲染产品列表布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。'),
        'doc' => 'frontend/layouts/product_list/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::content-before' => [
        'name' => __('产品列表布局内容之前'),
        'description' => __('在渲染产品列表布局的主要内容之前触发，允许其他模块在内容开始处注入内容。'),
        'doc' => 'frontend/layouts/product_list/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::content-after' => [
        'name' => __('产品列表布局内容之后'),
        'description' => __('在渲染产品列表布局的主要内容之后触发，允许其他模块在内容结束处注入内容。'),
        'doc' => 'frontend/layouts/product_list/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::body-end' => [
        'name' => __('产品列表布局 Body 结束'),
        'description' => __('在渲染产品列表布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。'),
        'doc' => 'frontend/layouts/product_list/body-end.md',
    ],
    
    // ==================== Theme Frontend Layouts - Product (通用) ====================
    'Weline_Theme::frontend::layouts::product::head-before' => [
        'name' => __('产品通用布局头部之前'),
        'description' => __('在渲染产品通用布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/head-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::head-after' => [
        'name' => __('产品通用布局头部之后'),
        'description' => __('在渲染产品通用布局的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::body-start' => [
        'name' => __('产品通用布局 Body 开始'),
        'description' => __('在渲染产品通用布局的 <body> 标签开始处触发，允许其他模块在 body 开始处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::product::content-before' => [
        'name' => __('产品通用布局内容之前'),
        'description' => __('在渲染产品通用布局的主要内容之前触发，允许其他模块在内容开始处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::content-after' => [
        'name' => __('产品通用布局内容之后'),
        'description' => __('在渲染产品通用布局的主要内容之后触发，允许其他模块在内容结束处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::body-end' => [
        'name' => __('产品通用布局 Body 结束'),
        'description' => __('在渲染产品通用布局的 <body> 标签结束处触发，允许其他模块在 body 结束处注入内容。适用于所有产品相关页面。'),
        'doc' => 'frontend/layouts/product/body-end.md',
    ],
    
    // ==================== Theme Frontend Header Actions (简单格式 Hook，向后兼容) ====================
    'header-categories-menu' => [
        'name' => __('页头分类菜单'),
        'description' => __('在页头显示分类菜单，允许其他模块实现商品分类菜单功能。如果没有提供内容，将显示默认的分类菜单。'),
        'doc' => 'frontend/header/categories-menu.md',
    ],
    'header-language-switcher' => [
        'name' => __('页头语言切换器'),
        'description' => __('在页头显示语言切换器，允许其他模块实现语言切换功能。'),
        'doc' => 'frontend/header/language-switcher.md',
    ],
    'header-currency-switcher' => [
        'name' => __('页头货币切换器'),
        'description' => __('在页头显示货币切换器，允许其他模块实现货币切换功能。'),
        'doc' => 'frontend/header/currency-switcher.md',
    ],
    'header-cart' => [
        'name' => __('页头购物车'),
        'description' => __('在页头显示购物车，允许其他模块实现购物车功能。'),
        'doc' => 'frontend/header/cart.md',
    ],
    'header-user-info' => [
        'name' => __('页头用户信息'),
        'description' => __('在页头显示用户相关信息，允许其他模块在用户信息区域注入额外的功能（如消息通知、收藏夹等）。'),
        'doc' => 'frontend/header/user-info.md',
    ],
    'header-account' => [
        'name' => __('页头账户菜单'),
        'description' => __('在页头显示账户菜单，允许其他模块实现账户登录/注册功能。支持 hover 展开下拉菜单。'),
        'doc' => 'frontend/header/account.md',
    ],
    'header-account-links' => [
        'name' => __('页头账户菜单链接'),
        'description' => __('在页头账户下拉菜单中显示账户相关链接，允许其他模块自定义账户菜单项。'),
        'doc' => 'frontend/header/account-links.md',
    ],
    
    // ==================== Theme Frontend Footer (简单格式 Hook，向后兼容) ====================
    'footer' => [
        'name' => __('页脚'),
        'description' => __('在页脚区域显示内容，允许其他模块在页脚注入内容。'),
        'doc' => 'frontend/footer.md',
    ],
];
