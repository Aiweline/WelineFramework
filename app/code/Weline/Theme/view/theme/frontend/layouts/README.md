# layouts/ 目录文档

## 目录概述

`layouts/` 目录包含页面布局模板，按照页面类型分类组织。每个页面类型下包含该类型页面的细分布局模板。

## 目录结构

```
layouts/
├── homepage/              # 首页布局
│   ├── default.phtml     # 默认布局（带banner）
│   └── minimal.phtml     # 极简布局（无banner）
│
├── product/              # 产品页布局
│   ├── detail.phtml      # 产品详情页布局
│   └── list.phtml        # 产品列表页布局
│
├── category/             # 分类页布局
│   ├── list.phtml        # 分类列表页布局
│   └── grid.phtml        # 分类网格布局
│
├── account/              # 个人中心布局
│   ├── dashboard.phtml   # 仪表盘布局（带侧边栏）
│   ├── profile.phtml     # 个人资料布局
│   ├── orders.phtml      # 订单列表布局
│   └── auth.phtml        # 认证页面布局（登录/注册）
│
├── cart/                 # 购物车布局
│   ├── default.phtml     # 默认布局（商品列表+汇总）
│   └── empty.phtml       # 空购物车布局
│
├── checkout/             # 结账页布局
│   ├── default.phtml     # 默认布局（多步骤）
│   ├── one-page.phtml    # 单页布局（所有步骤一页）
│   └── success.phtml     # 订单成功确认布局
│
└── README.md             # 本文档
```

## 布局使用说明

### 首页布局 (homepage/)

#### 1. `default.phtml` - 默认布局

**用途**：首页默认布局，包含header、banner、main、footer

**参数**：
- `title`: 页面标题（默认：首页）
- `content`: 主要内容（HTML字符串）
- `banner`: Banner内容（HTML字符串，可选）
- `showHeader`: 是否显示header（默认：true）
- `showFooter`: 是否显示footer（默认：true）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/homepage/default.phtml', [
    'title' => __('首页'),
    'banner' => $this->fetch('Weline_Frontend::blocks/homepage/banner.phtml'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/homepage/index.phtml')
]);
```

#### 2. `minimal.phtml` - 极简布局

**用途**：首页极简布局，无banner，适合简洁风格

**参数**：
- `title`: 页面标题（默认：首页）
- `content`: 主要内容（HTML字符串）
- `class`: 额外CSS类

---

### 产品页布局 (product/)

#### 1. `detail.phtml` - 产品详情页布局

**用途**：产品详情页布局，包含产品图片、信息、详情等区域

**参数**：
- `title`: 页面标题（默认：产品详情）
- `content`: 主要内容（HTML字符串）
- `sidebar`: 侧边栏内容（相关产品推荐等，可选）
- `breadcrumb`: 面包屑导航（可选）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/product/detail.phtml', [
    'title' => $product->getName(),
    'breadcrumb' => $this->fetch('Weline_Frontend::partials/breadcrumb.phtml'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/product/detail.phtml'),
    'sidebar' => $this->fetch('Weline_Frontend::templates/frontend/product/sidebar.phtml')
]);
```

#### 2. `list.phtml` - 产品列表页布局

**用途**：产品列表页布局，包含筛选、排序、产品网格等

**参数**：
- `title`: 页面标题（默认：产品列表）
- `content`: 主要内容（HTML字符串）
- `filters`: 筛选器内容（HTML字符串，可选）
- `toolbar`: 工具栏（排序、视图切换等，可选）
- `class`: 额外CSS类

---

### 分类页布局 (category/)

#### 1. `list.phtml` - 分类列表页布局

**用途**：分类列表页布局，包含分类筛选、产品展示等

**参数**：
- `title`: 页面标题（默认：分类）
- `content`: 主要内容（HTML字符串）
- `categoryNav`: 分类导航（可选）
- `filters`: 筛选器内容（可选）
- `class`: 额外CSS类

#### 2. `grid.phtml` - 分类网格布局

**用途**：分类网格布局，适合展示分类卡片

**参数**：
- `title`: 页面标题（默认：分类）
- `content`: 主要内容（HTML字符串）
- `class`: 额外CSS类

---

### 个人中心布局 (account/)

#### 1. `dashboard.phtml` - 仪表盘布局

**用途**：个人中心仪表盘布局，包含侧边栏导航和主内容区

**参数**：
- `title`: 页面标题（默认：个人中心）
- `content`: 主要内容（HTML字符串）
- `sidebar`: 侧边栏内容（导航菜单）
- `sidebarCollapsed`: 侧边栏是否折叠（默认：false）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/account/dashboard.phtml', [
    'title' => __('个人中心'),
    'sidebar' => $this->fetch('Weline_Frontend::templates/frontend/account/sidebar.phtml'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/account/index.phtml')
]);
```

#### 2. `profile.phtml` - 个人资料布局

**用途**：个人资料页面布局，适合表单编辑

**参数**：
- `title`: 页面标题（默认：个人资料）
- `content`: 主要内容（HTML字符串）
- `sidebar`: 侧边栏内容（可选）
- `class`: 额外CSS类

#### 3. `orders.phtml` - 订单列表布局

**用途**：订单列表页面布局，适合表格展示

**参数**：
- `title`: 页面标题（默认：我的订单）
- `content`: 主要内容（HTML字符串）
- `filters`: 筛选器（订单状态筛选等，可选）
- `class`: 额外CSS类

#### 4. `auth.phtml` - 认证页面布局

**用途**：登录/注册等认证页面的专用布局

**参数**：
- `title`: 页面标题（默认：登录）
- `content`: 认证表单内容（HTML字符串）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/account/auth.phtml', [
    'title' => __('用户登录'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/account/login.phtml')
]);
```

---

### 购物车布局 (cart/)

#### 1. `default.phtml` - 默认布局

**用途**：购物车页面默认布局，包含商品列表、价格汇总等

**参数**：
- `title`: 页面标题（默认：购物车）
- `content`: 主要内容（HTML字符串）
- `summary`: 价格汇总区域（HTML字符串）
- `breadcrumb`: 面包屑导航（可选）
- `continueShopping`: 继续购物链接（可选）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/cart/default.phtml', [
    'title' => __('购物车'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/cart/items.phtml'),
    'summary' => $this->fetch('Weline_Frontend::templates/frontend/cart/summary.phtml'),
    'continueShopping' => '/',
    'breadcrumb' => $this->fetch('Weline_Frontend::partials/breadcrumb.phtml')
]);
```

#### 2. `empty.phtml` - 空购物车布局

**用途**：空购物车页面布局，提示用户购物车为空

**参数**：
- `title`: 页面标题（默认：购物车）
- `message`: 提示信息（可选）
- `continueShopping`: 继续购物链接
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/cart/empty.phtml', [
    'title' => __('购物车'),
    'message' => __('您的购物车是空的，快去选购心仪的商品吧！'),
    'continueShopping' => '/'
]);
```

---

### 结账页布局 (checkout/)

#### 1. `default.phtml` - 默认布局

**用途**：结账页面默认布局，包含订单信息、收货地址、支付方式等

**参数**：
- `title`: 页面标题（默认：结账）
- `content`: 主要内容（HTML字符串）
- `orderSummary`: 订单摘要（HTML字符串）
- `steps`: 结账步骤导航（HTML字符串，可选）
- `breadcrumb`: 面包屑导航（可选）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/checkout/default.phtml', [
    'title' => __('结账'),
    'steps' => $this->fetch('Weline_Frontend::templates/frontend/checkout/steps.phtml'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/checkout/form.phtml'),
    'orderSummary' => $this->fetch('Weline_Frontend::templates/frontend/checkout/summary.phtml')
]);
```

#### 2. `one-page.phtml` - 单页布局

**用途**：单页结账布局，所有步骤在一个页面完成

**参数**：
- `title`: 页面标题（默认：结账）
- `content`: 主要内容（HTML字符串）
- `orderSummary`: 订单摘要（HTML字符串）
- `breadcrumb`: 面包屑导航（可选）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/checkout/one-page.phtml', [
    'title' => __('结账'),
    'content' => $this->fetch('Weline_Frontend::templates/frontend/checkout/one-page-form.phtml'),
    'orderSummary' => $this->fetch('Weline_Frontend::templates/frontend/checkout/summary.phtml')
]);
```

#### 3. `success.phtml` - 订单成功确认布局

**用途**：订单提交成功后的确认页面布局

**参数**：
- `title`: 页面标题（默认：订单确认）
- `content`: 主要内容（HTML字符串）
- `orderNumber`: 订单号
- `orderDetails`: 订单详情（HTML字符串，可选）
- `continueShopping`: 继续购物链接
- `viewOrder`: 查看订单链接（可选）
- `class`: 额外CSS类

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/checkout/success.phtml', [
    'title' => __('订单确认'),
    'orderNumber' => $order->getOrderNumber(),
    'orderDetails' => $this->fetch('Weline_Frontend::templates/frontend/checkout/order-details.phtml'),
    'viewOrder' => '/account/orders/view?id=' . $order->getId(),
    'continueShopping' => '/'
]);
```

---

## 布局设计原则

1. **按页面类型分类**：根据页面功能分类组织布局
2. **细分布局**：每个页面类型下提供多种布局变体
3. **可复用性**：布局模板可在多个页面使用
4. **灵活性**：支持通过参数自定义布局行为
5. **响应式**：所有布局都适配不同屏幕尺寸
6. **一致性**：保持页面结构统一

---

## 布局文件组织

所有布局文件已按页面类型分类组织：

- 首页布局：`homepage/`
- 产品页布局：`product/`
- 分类页布局：`category/`
- 个人中心布局：`account/`
- 购物车布局：`cart/`
- 结账页布局：`checkout/`

请根据页面类型使用对应的布局文件。

