# 部件页面类型约束系统

## 概述

部件页面类型（page_types）约束系统用于控制哪些部件可以在哪些页面类型中使用。这确保了：

1. 部件只在适当的上下文中出现（如产品相关部件只在产品页显示）
2. 避免在不适用的页面中添加无意义的部件
3. 提供更好的用户体验和编辑器性能

## 页面类型定义

### 核心页面类型

页面类型标识与 `layouts/` 目录名一一对应：

| 类型标识 | 布局目录 | 描述 | 示例路由 |
|---------|---------|------|---------|
| `homepage` | `layouts/homepage/` | 首页 | `/`, `/home` |
| `cms_page` | `layouts/cms_page/` | CMS 静态页面 | `/about`, `/contact` |
| `category` | `layouts/category/` | 分类/目录页 | `/category/electronics` |
| `product` | `layouts/product/` | 产品详情页 | `/product/iphone-15` |
| `product_list` | `layouts/product_list/` | 产品列表页 | `/products` |
| `cart` | `layouts/cart/` | 购物车页 | `/cart` |
| `checkout` | `layouts/checkout/` | 结账页 | `/checkout` |
| `search` | `layouts/search/` | 搜索结果页 | `/search?q=phone` |
| `account` | `layouts/account/` | 用户账户页 | `/account`, `/account/orders` |
| `default` | `layouts/default/` | 默认布局 | 其他页面 |

### 通用页面类型

| 类型标识 | 描述 |
|---------|------|
| `*` | 所有页面类型（通用部件） |

## 部件分类与适用范围

### 容器型部件（独占）

这些部件定义了页面的主要结构，通常每个区域只能有一个：

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `header-container` | `*` | 头部容器，包含 Logo、搜索、导航等插槽 |
| `footer-container` | `*` | 底部容器，包含链接、社交、版权等插槽 |
| `content-container` | `*` | 主内容容器，包含 Hero、侧栏、主内容等插槽 |

### Header 子部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `logo` | `*` | 网站 Logo，独占 |
| `main-nav` | `*` | 主导航菜单，独占 |
| `header-search` | `*` | 头部搜索框，独占 |
| `account` | `*` | 用户账户入口 |
| `mini-cart-icon` | `*` | 购物车图标 |
| `language-switcher` | `*` | 语言切换 |
| `currency-switcher` | `*` | 货币切换 |

### Banner 部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `hero-slider` | `homepage`, `cms_page` | 大图轮播 |
| `promo-banner` | `*` | 促销横幅 |
| `ad-banner` | `*` | 广告横幅 |

### Product 部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `featured-products` | `homepage`, `cms_page`, `category` | 推荐产品 |
| `new-arrivals` | `homepage`, `cms_page`, `category` | 新品到达 |
| `bestsellers` | `homepage`, `cms_page`, `category` | 畅销产品 |
| `deals-of-day` | `homepage`, `cms_page` | 今日特价 |
| `related-products` | `product` | 相关产品 |
| `recently-viewed` | `*` | 最近浏览 |
| `you-may-like` | `*` | 猜你喜欢 |
| `cross-sell` | `product`, `cart` | 交叉销售 |
| `up-sell` | `product` | 向上销售 |
| `product-carousel` | `homepage`, `cms_page`, `category` | 产品轮播 |

### Category 部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `category-list` | `*` | 分类列表 |
| `category-grid` | `homepage`, `cms_page` | 分类网格 |
| `category-menu` | `*` | 分类菜单 |

### Sidebar 部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `category-filters` | `category`, `search` | 分类筛选 |
| `sidebar-menu` | `*` | 侧栏菜单 |
| `mini-cart` | `*` | 迷你购物车 |
| `sidebar-newsletter` | `*` | 侧栏订阅 |
| `sidebar-ads` | `*` | 侧栏广告 |
| `tags-cloud` | `*` | 标签云 |
| `sidebar-social` | `*` | 侧栏社交 |

### Content 部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `text-block` | `*` | 文本块 |
| `image-text` | `*` | 图文组合 |
| `video-player` | `*` | 视频播放器 |
| `countdown` | `*` | 倒计时 |
| `brand-logos` | `homepage`, `cms_page` | 品牌 Logo |
| `trust-badges` | `*` | 信任徽章 |
| `faq-accordion` | `cms_page`, `product` | FAQ 折叠 |
| `testimonials` | `homepage`, `cms_page` | 客户评价 |

### Footer 子部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `footer-links` | `*` | 页脚链接 |
| `footer-newsletter` | `*` | 页脚订阅，独占 |
| `footer-social` | `*` | 页脚社交，独占 |
| `footer-payment` | `*` | 支付方式，独占 |
| `footer-copyright` | `*` | 版权信息，独占 |

### 通用部件

| 部件代码 | 适用页面 | 说明 |
|---------|---------|------|
| `breadcrumb` | `*` | 面包屑导航 |
| `search-bar` | `*` | 搜索栏 |
| `pagination` | `category`, `search`, `cms_page` | 分页 |
| `social-share` | `product`, `cms_page` | 社交分享 |
| `newsletter-popup` | `homepage`, `cms_page` | 订阅弹窗 |

## 配置说明

### 在部件定义中使用

```php
// widget.php 中的部件定义
[
    'name'        => '推荐产品',
    'code'        => 'featured-products',
    'type'        => 'product',
    // 指定适用的页面类型（使用 layouts/ 目录名）
    'page_types'  => ['homepage', 'cms_page', 'category'],
    // ...其他配置
]
```

### 独占部件配置

```php
[
    'name'        => 'Header 容器',
    'code'        => 'header-container',
    'type'        => 'container',
    'is_container' => true,   // 容器型部件
    'exclusive'   => true,    // 独占部件（同区域只能有一个）
    'page_types'  => ['*'],   // 所有页面类型
    // ...
]
```

## 前端过滤机制

1. **服务端过滤**：`ThemeLayoutService::getAvailableWidgets($pageType)` 根据页面类型过滤部件列表
2. **客户端验证**：拖拽时检查部件是否支持当前页面类型，不支持则提示用户
3. **可视化标识**：容器和独占部件在列表中有特殊徽章标识

## API 参数

### 获取部件列表

```
GET /backend/theme-editor/widgets?page_type=category
```

返回仅适用于 `category` 页面类型的部件列表。

## 最佳实践

1. **通用部件使用 `['*']`**：如面包屑、搜索栏等在所有页面都可用的部件
2. **产品相关部件限制页面**：如相关产品、交叉销售等只在相关页面显示
3. **首页专属部件**：如大图轮播、今日特价等营销型部件通常只在首页使用
4. **独占部件标记清晰**：容器型部件和主要结构部件应设置 `exclusive: true`
