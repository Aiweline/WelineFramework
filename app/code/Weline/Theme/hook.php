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
    'Weline_Theme::frontend::partials::footer::brand' => [
        'name' => __('页脚品牌内容'),
        'description' => __('覆盖默认页脚品牌区域，允许站点或业务模块替换页脚品牌名称、标识和说明。'),
        'doc' => 'frontend/partials/footer/brand.md',
    ],
    'Weline_Theme::frontend::partials::footer::links' => [
        'name' => __('页脚链接内容'),
        'description' => __('覆盖页脚链接内容，允许其他模块完全替换页脚的链接区域。'),
        'doc' => 'frontend/partials/footer/links.md',
    ],
    'Weline_Theme::frontend::partials::footer::social-media' => [
        'name' => __('页脚社交媒体内容'),
        'description' => __('覆盖页脚社交媒体内容，允许其他模块完全替换页脚的社交媒体区域。'),
        'doc' => 'frontend/partials/footer/social-media.md',
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
    'Weline_Theme::frontend::partials::footer::copyright' => [
        'name' => __('页脚版权信息'),
        'description' => __('覆盖默认页脚版权信息，允许站点或业务模块输出自定义版权文本。'),
        'doc' => 'frontend/partials/footer/copyright.md',
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
    'Weline_Theme::frontend::partials::header::logo' => [
        'name' => __('页头 Logo 内容'),
        'description' => __('覆盖默认页头 Logo 区域，允许站点或业务模块替换品牌标识和首页链接。'),
        'doc' => 'frontend/partials/header/logo.md',
    ],
    'Weline_Theme::frontend::partials::header::logo-after' => [
        'name' => __('页头 Logo 之后'),
        'description' => __('在渲染页头 Logo 之后触发，允许其他模块在 Logo 结束处注入内容。'),
        'doc' => 'frontend/partials/header/logo-after.md',
    ],
    'Weline_Theme::frontend::partials::header::announcement' => [
        'name' => __('页头公告内容'),
        'description' => __('覆盖或注入页头公告区域，允许业务模块提供全站促销、配送或运营提示。'),
        'doc' => 'frontend/partials/header/announcement.md',
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
    'Weline_Theme::frontend::partials::header::navigation' => [
        'name' => __('页头导航内容'),
        'description' => __('覆盖默认页头导航列表，允许站点或业务模块提供业务导航。'),
        'doc' => 'frontend/partials/header/navigation.md',
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
    'Weline_Theme::frontend::partials::header::search' => [
        'name' => __('页头搜索内容'),
        'description' => __('覆盖默认页头搜索表单，允许站点或业务模块提供自己的搜索目标和占位文案。'),
        'doc' => 'frontend/partials/header/search.md',
    ],
    'Weline_Theme::frontend::partials::header::search-form-before' => [
        'name' => __('页头搜索表单之前'),
        'description' => __('在渲染页头搜索表单之前触发，允许其他模块在搜索表单开始处注入内容（如分类下拉菜单等）。'),
        'doc' => 'frontend/partials/header/search-form-before.md',
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
    'Weline_Theme::frontend::partials::header::account' => [
        'name' => __('页头账户操作'),
        'description' => __('覆盖默认页头账户入口，允许客户、会员或站点模块接入自己的账户中心地址。'),
        'doc' => 'frontend/partials/header/account.md',
    ],
    'Weline_Theme::frontend::partials::header::cart' => [
        'name' => __('页头购物车入口'),
        'description' => __('覆盖默认页头购物车入口，允许购物车模块提供数量、金额、迷你购物车或结账入口。'),
        'doc' => 'frontend/partials/header/cart.md',
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
    
    // ==================== Theme Frontend Partials - Head ====================
    'Weline_Theme::frontend::partials::head::favicon' => [
        'name' => __('Frontend head favicon'),
        'description' => __('Allows modules to replace the frontend favicon links in the document head.'),
        'doc' => 'frontend/partials/head/favicon.md',
    ],
    'Weline_Theme::frontend::partials::head::module-declarations' => [
        'name' => __('Head 模块声明'),
        'description' => __('在 head 中 theme.js 加载之后触发，允许其他模块注入 JS 模块声明。使用 Weline.declare() 声明需要加载的模块。'),
        'doc' => 'frontend/partials/head/module-declarations.md',
    ],
    
    // ==================== Theme Frontend Partials - Breadcrumb ====================
    'Weline_Theme::frontend::partials::breadcrumb::items' => [
        'name' => __('面包屑自定义内容（items）'),
        'description' => __('覆盖前台主题的面包屑节点列表，允许其他模块输出自定义的面包屑结构（如基于分类层级、搜索结果等动态生成路径）。如果未实现此 Hook，将回退到主题默认的面包屑渲染逻辑。'),
        'doc' => 'frontend/partials/breadcrumb/items.md',
    ],
    
    // ==================== Theme Frontend Layouts - Base ====================
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
    'Weline_Theme::frontend::layouts::base::breadcrumb-before' => [
        'name' => __('基础布局面包屑之前'),
        'description' => __('在基础布局面包屑之前触发，允许模块注入导航辅助内容。'),
        'doc' => 'frontend/layouts/base/breadcrumb-before.md',
    ],
    'Weline_Theme::frontend::layouts::base::breadcrumb-after' => [
        'name' => __('基础布局面包屑之后'),
        'description' => __('在基础布局面包屑之后触发，允许模块注入导航辅助内容。'),
        'doc' => 'frontend/layouts/base/breadcrumb-after.md',
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
    'Weline_Theme::frontend::layouts::homepage::content' => [
        'name' => __('首页布局内容'),
        'description' => __('覆盖首页布局的主内容区域，允许站点或业务模块在保留默认页头、页脚和布局资源的同时提供完整首页内容。'),
        'doc' => 'frontend/layouts/homepage/content.md',
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
    'Weline_Theme::frontend::layouts::homepage::main-before' => [
        'name' => __('首页主要内容区块之前'),
        'description' => __('在渲染首页布局的主要内容区块（main元素）之前触发，允许其他模块在主要内容区块开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/main-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::main-content-before' => [
        'name' => __('首页主要内容内容之前'),
        'description' => __('在渲染首页布局的主要内容区块内的内容之前触发，允许其他模块在主要内容开始处注入内容。'),
        'doc' => 'frontend/layouts/homepage/main-content-before.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::main-content' => [
        'name' => __('首页主要内容'),
        'description' => __('覆盖首页布局的主要内容区块内容，允许其他模块完全替换主要内容区域。如果没有提供内容，将显示默认的欢迎内容和功能预览。'),
        'doc' => 'frontend/layouts/homepage/main-content.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::main-content-after' => [
        'name' => __('首页主要内容内容之后'),
        'description' => __('在渲染首页布局的主要内容区块内的内容之后触发，允许其他模块在主要内容结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/main-content-after.md',
    ],
    'Weline_Theme::frontend::layouts::homepage::main-after' => [
        'name' => __('首页主要内容区块之后'),
        'description' => __('在渲染首页布局的主要内容区块（main元素）之后触发，允许其他模块在主要内容区块结束处注入内容。'),
        'doc' => 'frontend/layouts/homepage/main-after.md',
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
    'Weline_Theme::frontend::layouts::default::content' => [
        'name' => __('默认布局内容'),
        'description' => __('覆盖默认布局的主内容插槽，允许模块在保留默认布局壳的同时提供完整页面内容。'),
        'doc' => 'frontend/layouts/default/content.md',
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
    'Weline_Theme::frontend::layouts::product-list::content' => [
        'name' => __('产品列表布局内容'),
        'description' => __('覆盖产品列表布局的主内容区域，允许商品模块提供列表、筛选、分页或空状态。'),
        'doc' => 'frontend/layouts/product_list/content.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::header-before' => [
        'name' => __('产品列表布局页头之前'),
        'description' => __('在产品列表布局页头之前触发，允许模块注入公告、导航辅助或埋点。'),
        'doc' => 'frontend/layouts/product_list/header-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::header-after' => [
        'name' => __('产品列表布局页头之后'),
        'description' => __('在产品列表布局页头之后触发，允许模块注入横幅、提示或布局辅助内容。'),
        'doc' => 'frontend/layouts/product_list/header-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::breadcrumb-before' => [
        'name' => __('产品列表面包屑之前'),
        'description' => __('在产品列表布局面包屑之前触发。'),
        'doc' => 'frontend/layouts/product_list/breadcrumb-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::breadcrumb-after' => [
        'name' => __('产品列表面包屑之后'),
        'description' => __('在产品列表布局面包屑之后触发。'),
        'doc' => 'frontend/layouts/product_list/breadcrumb-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::filters-before' => [
        'name' => __('产品列表筛选之前'),
        'description' => __('在产品列表筛选区域之前触发。'),
        'doc' => 'frontend/layouts/product_list/filters-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::filters-after' => [
        'name' => __('产品列表筛选之后'),
        'description' => __('在产品列表筛选区域之后触发。'),
        'doc' => 'frontend/layouts/product_list/filters-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::toolbar-before' => [
        'name' => __('产品列表工具栏之前'),
        'description' => __('在产品列表工具栏之前触发。'),
        'doc' => 'frontend/layouts/product_list/toolbar-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::toolbar-content' => [
        'name' => __('产品列表工具栏内容'),
        'description' => __('覆盖或补充产品列表排序、视图切换、分页大小等工具栏内容。'),
        'doc' => 'frontend/layouts/product_list/toolbar-content.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::toolbar-after' => [
        'name' => __('产品列表工具栏之后'),
        'description' => __('在产品列表工具栏之后触发。'),
        'doc' => 'frontend/layouts/product_list/toolbar-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::grid-before' => [
        'name' => __('产品列表网格之前'),
        'description' => __('在产品列表网格之前触发。'),
        'doc' => 'frontend/layouts/product_list/grid-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::grid-content' => [
        'name' => __('产品列表网格内容'),
        'description' => __('覆盖或补充产品列表网格内容。'),
        'doc' => 'frontend/layouts/product_list/grid-content.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::grid-after' => [
        'name' => __('产品列表网格之后'),
        'description' => __('在产品列表网格之后触发。'),
        'doc' => 'frontend/layouts/product_list/grid-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::pagination-before' => [
        'name' => __('产品列表分页之前'),
        'description' => __('在产品列表分页之前触发。'),
        'doc' => 'frontend/layouts/product_list/pagination-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::pagination-content' => [
        'name' => __('产品列表分页内容'),
        'description' => __('覆盖或补充产品列表分页内容。'),
        'doc' => 'frontend/layouts/product_list/pagination-content.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::pagination-after' => [
        'name' => __('产品列表分页之后'),
        'description' => __('在产品列表分页之后触发。'),
        'doc' => 'frontend/layouts/product_list/pagination-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::recommendations-before' => [
        'name' => __('产品列表推荐之前'),
        'description' => __('在产品列表推荐区域之前触发。'),
        'doc' => 'frontend/layouts/product_list/recommendations-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::recommendations-after' => [
        'name' => __('产品列表推荐之后'),
        'description' => __('在产品列表推荐区域之后触发。'),
        'doc' => 'frontend/layouts/product_list/recommendations-after.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::footer-before' => [
        'name' => __('产品列表页脚之前'),
        'description' => __('在产品列表布局页脚之前触发。'),
        'doc' => 'frontend/layouts/product_list/footer-before.md',
    ],
    'Weline_Theme::frontend::layouts::product-list::footer-after' => [
        'name' => __('产品列表页脚之后'),
        'description' => __('在产品列表布局页脚之后触发。'),
        'doc' => 'frontend/layouts/product_list/footer-after.md',
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
    'Weline_Theme::frontend::layouts::product::header-before' => [
        'name' => __('产品布局页头之前'),
        'description' => __('在产品布局页头之前触发。'),
        'doc' => 'frontend/layouts/product/header-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::header-after' => [
        'name' => __('产品布局页头之后'),
        'description' => __('在产品布局页头之后触发。'),
        'doc' => 'frontend/layouts/product/header-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::breadcrumb-before' => [
        'name' => __('产品布局面包屑之前'),
        'description' => __('在产品布局面包屑之前触发。'),
        'doc' => 'frontend/layouts/product/breadcrumb-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::breadcrumb-after' => [
        'name' => __('产品布局面包屑之后'),
        'description' => __('在产品布局面包屑之后触发。'),
        'doc' => 'frontend/layouts/product/breadcrumb-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::main-content-before' => [
        'name' => __('产品主内容之前'),
        'description' => __('在产品主内容区域之前触发。'),
        'doc' => 'frontend/layouts/product/main-content-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::main-content' => [
        'name' => __('产品主内容'),
        'description' => __('覆盖或补充产品主内容区域。'),
        'doc' => 'frontend/layouts/product/main-content.md',
    ],
    'Weline_Theme::frontend::layouts::product::main-content-after' => [
        'name' => __('产品主内容之后'),
        'description' => __('在产品主内容区域之后触发。'),
        'doc' => 'frontend/layouts/product/main-content-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::sidebar-before' => [
        'name' => __('产品侧栏之前'),
        'description' => __('在产品侧栏区域之前触发。'),
        'doc' => 'frontend/layouts/product/sidebar-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::sidebar-after' => [
        'name' => __('产品侧栏之后'),
        'description' => __('在产品侧栏区域之后触发。'),
        'doc' => 'frontend/layouts/product/sidebar-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::tabs-before' => [
        'name' => __('产品标签之前'),
        'description' => __('在产品标签区域之前触发。'),
        'doc' => 'frontend/layouts/product/tabs-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::tabs-content' => [
        'name' => __('产品标签内容'),
        'description' => __('覆盖或补充产品描述、规格、评价、问答等标签内容。'),
        'doc' => 'frontend/layouts/product/tabs-content.md',
    ],
    'Weline_Theme::frontend::layouts::product::tabs-slot-content' => [
        'name' => __('产品标签插槽内容'),
        'description' => __('在产品标签插槽中触发，允许模块补充可视化部件内容。'),
        'doc' => 'frontend/layouts/product/tabs-slot-content.md',
    ],
    'Weline_Theme::frontend::layouts::product::tabs-after' => [
        'name' => __('产品标签之后'),
        'description' => __('在产品标签区域之后触发。'),
        'doc' => 'frontend/layouts/product/tabs-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::related-before' => [
        'name' => __('产品推荐之前'),
        'description' => __('在产品推荐区域之前触发。'),
        'doc' => 'frontend/layouts/product/related-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::recommendations-content' => [
        'name' => __('产品推荐内容'),
        'description' => __('覆盖或补充产品推荐、搭配、继续浏览等推荐内容。'),
        'doc' => 'frontend/layouts/product/recommendations-content.md',
    ],
    'Weline_Theme::frontend::layouts::product::related-products' => [
        'name' => __('相关产品'),
        'description' => __('在产品推荐区渲染相关产品内容。'),
        'doc' => 'frontend/layouts/product/related-products.md',
    ],
    'Weline_Theme::frontend::layouts::product::bestsellers' => [
        'name' => __('热销产品'),
        'description' => __('在产品推荐区渲染热销产品内容。'),
        'doc' => 'frontend/layouts/product/bestsellers.md',
    ],
    'Weline_Theme::frontend::layouts::product::recently-viewed' => [
        'name' => __('最近浏览'),
        'description' => __('在产品推荐区渲染最近浏览内容。'),
        'doc' => 'frontend/layouts/product/recently-viewed.md',
    ],
    'Weline_Theme::frontend::layouts::product::cross-sell' => [
        'name' => __('交叉销售'),
        'description' => __('在产品推荐区渲染搭配或交叉销售内容。'),
        'doc' => 'frontend/layouts/product/cross-sell.md',
    ],
    'Weline_Theme::frontend::layouts::product::related-after' => [
        'name' => __('产品推荐之后'),
        'description' => __('在产品推荐区域之后触发。'),
        'doc' => 'frontend/layouts/product/related-after.md',
    ],
    'Weline_Theme::frontend::layouts::product::footer-before' => [
        'name' => __('产品布局页脚之前'),
        'description' => __('在产品布局页脚之前触发。'),
        'doc' => 'frontend/layouts/product/footer-before.md',
    ],
    'Weline_Theme::frontend::layouts::product::footer-after' => [
        'name' => __('产品布局页脚之后'),
        'description' => __('在产品布局页脚之后触发。'),
        'doc' => 'frontend/layouts/product/footer-after.md',
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

    // ==================== Theme Frontend Layouts - Cart ====================
    'Weline_Theme::frontend::layouts::cart::head-after' => [
        'name' => __('购物车布局头部之后'),
        'description' => __('在购物车布局 <head> 结束前触发。'),
        'doc' => 'frontend/layouts/cart/head-after.md',
    ],
    'Weline_Theme::frontend::layouts::cart::body-start' => [
        'name' => __('购物车布局 Body 开始'),
        'description' => __('在购物车布局 <body> 开始处触发。'),
        'doc' => 'frontend/layouts/cart/body-start.md',
    ],
    'Weline_Theme::frontend::layouts::cart::header-before' => [
        'name' => __('购物车页头之前'),
        'description' => __('在购物车页头之前触发。'),
        'doc' => 'frontend/layouts/cart/header-before.md',
    ],
    'Weline_Theme::frontend::layouts::cart::header-after' => [
        'name' => __('购物车页头之后'),
        'description' => __('在购物车页头之后触发。'),
        'doc' => 'frontend/layouts/cart/header-after.md',
    ],
    'Weline_Theme::frontend::layouts::cart::content-before' => [
        'name' => __('购物车内容之前'),
        'description' => __('在购物车内容区域之前触发。'),
        'doc' => 'frontend/layouts/cart/content-before.md',
    ],
    'Weline_Theme::frontend::layouts::cart::content-after' => [
        'name' => __('购物车内容之后'),
        'description' => __('在购物车内容区域之后触发。'),
        'doc' => 'frontend/layouts/cart/content-after.md',
    ],
    'Weline_Theme::frontend::layouts::cart::main-before' => [
        'name' => __('购物车主体之前'),
        'description' => __('在购物车主体区域之前触发。'),
        'doc' => 'frontend/layouts/cart/main-before.md',
    ],
    'Weline_Theme::frontend::layouts::cart::main-after' => [
        'name' => __('购物车主体之后'),
        'description' => __('在购物车主体区域之后触发。'),
        'doc' => 'frontend/layouts/cart/main-after.md',
    ],
    'Weline_Theme::frontend::layouts::cart::recommendations' => [
        'name' => __('购物车推荐内容'),
        'description' => __('在购物车推荐区域触发。'),
        'doc' => 'frontend/layouts/cart/recommendations.md',
    ],
    'Weline_Theme::frontend::layouts::cart::footer-before' => [
        'name' => __('购物车页脚之前'),
        'description' => __('在购物车页脚之前触发。'),
        'doc' => 'frontend/layouts/cart/footer-before.md',
    ],
    'Weline_Theme::frontend::layouts::cart::footer-after' => [
        'name' => __('购物车页脚之后'),
        'description' => __('在购物车页脚之后触发。'),
        'doc' => 'frontend/layouts/cart/footer-after.md',
    ],
    'Weline_Theme::frontend::layouts::cart::body-end' => [
        'name' => __('购物车布局 Body 结束'),
        'description' => __('在购物车布局 <body> 结束处触发。'),
        'doc' => 'frontend/layouts/cart/body-end.md',
    ],
    'Weline_Theme::frontend::layouts::cart-empty::recommendations' => [
        'name' => __('空购物车推荐内容'),
        'description' => __('在空购物车推荐区域触发。'),
        'doc' => 'frontend/layouts/cart-empty/recommendations.md',
    ],

    // ==================== Theme Frontend Layouts - Category ====================
    'Weline_Theme::frontend::layouts::category::subcategories-filter' => [
        'name' => __('分类页子分类筛选'),
        'description' => __('在分类页左侧筛选栏中渲染子分类筛选区域，支持显示上级分类返回入口和当前分类的直接子分类列表。'),
        'doc' => 'frontend/layouts/category/subcategories-filter.md',
    ],
    'Weline_Theme::frontend::layouts::category::filters-sidebar' => [
        'name' => __('分类页筛选侧栏'),
        'description' => __('在分类页左侧渲染产品筛选侧栏，支持价格、品牌、颜色、评分等多维度筛选。由 WeShop_Filters 模块实现。'),
        'doc' => 'frontend/layouts/category/filters-sidebar.md',
        'slot' => true,
    ],
    'Weline_Theme::frontend::layouts::category::filters-before' => [
        'name' => __('分类筛选区之前'),
        'description' => __('在分类筛选区域之前触发，允许其他模块注入内容。'),
        'doc' => 'frontend/layouts/category/filters-before.md',
    ],
    'Weline_Theme::frontend::layouts::category::filters-after' => [
        'name' => __('分类筛选区之后'),
        'description' => __('在分类筛选区域之后触发，允许其他模块注入内容。'),
        'doc' => 'frontend/layouts/category/filters-after.md',
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
    'header-location-selector' => [
        'name' => __('页头配送地址选择器'),
        'description' => __('在页头显示配送地址选择器，允许其他模块实现配送地址选择功能。'),
        'doc' => 'frontend/header/location-selector.md',
    ],
    'header-orders' => [
        'name' => __('页头订单'),
        'description' => __('在页头显示订单相关功能，允许其他模块实现订单查看、订单管理等功能。'),
        'doc' => 'frontend/header/orders.md',
    ],
    'header-hamburger-menu' => [
        'name' => __('页头汉堡菜单'),
        'description' => __('在页头显示汉堡菜单（移动端侧边栏菜单），允许其他模块实现移动端导航菜单功能。如果没有提供内容，将显示默认的汉堡菜单。'),
        'doc' => 'frontend/header/hamburger-menu.md',
    ],
    'header-nav-links' => [
        'name' => __('页头导航链接'),
        'description' => __('在页头导航区域显示横向导航链接（如今日特价、Prime Video等），允许其他模块添加自定义导航链接。'),
        'doc' => 'frontend/header/nav-links.md',
    ],
    'header-nav-right' => [
        'name' => __('页头右侧导航'),
        'description' => __('在页头右侧导航区域显示内容，允许其他模块在右侧导航区域注入内容（如分类菜单等）。'),
        'doc' => 'frontend/header/nav-right.md',
    ],
    
    // ==================== Theme Frontend Footer (简单格式 Hook，向后兼容) ====================
    'footer' => [
        'name' => __('页脚'),
        'description' => __('在页脚区域显示内容，允许其他模块在页脚注入内容。'),
        'doc' => 'frontend/footer.md',
    ],
    
    // ==================== Theme Frontend Account Sidebar (简单格式 Hook，向后兼容) ====================
    'account.sidebar' => [
        'name' => __('账户侧边栏'),
        'description' => __('在账户页面的侧边栏导航中注入内容，允许其他模块添加自定义导航项。'),
        'doc' => 'frontend/account/sidebar.md',
    ],
    'account.sidebar.content' => [
        'name' => __('账户侧边栏内容'),
        'description' => __('在账户页面的侧边栏内容区域注入内容，允许其他模块添加自定义内容。'),
        'doc' => 'frontend/account/sidebar-content.md',
    ],

    // ==================== Theme Backend Partials - Topbar ====================
    'Weline_Theme::backend::partials::topbar::logo' => [
        'name' => __('后台 Topbar Logo'),
        'description' => __('覆盖后台顶部栏的 Logo 区域，可由 Weline_Backend 等模块实现，从自身配置读取 logo_dark/logo_light/logo_sm 等。未实现时使用 Admin 默认静态 Logo。'),
        'doc' => 'backend/partials/topbar/logo.md',
    ],
];
