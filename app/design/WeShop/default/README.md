# WeShop Default Theme

## 主题概述

WeShop Default Theme 是基于UI设计文件开发的电商主题，使用Tailwind CSS构建，支持响应式设计和暗色模式。

## 主题结构

```
app/design/WeShop/default/
├── register.php                    # 主题注册文件
├── README.md                       # 本文件
├── assets/
│   └── images/                     # 主题图片资源
├── frontend/
│   ├── assets/
│   │   ├── css/
│   │   │   └── main.css           # 主题CSS ✅
│   │   └── js/
│   │       └── main.js            # 主题JS ✅
│   ├── layouts/
│   │   ├── base.phtml             # 基础布局 ✅
│   │   └── homepage.phtml         # 首页布局 ✅
│   ├── partials/
│   │   ├── header/
│   │   │   └── default.phtml      # Header模板 ✅
│   │   └── footer/
│   │       └── default.phtml      # Footer模板 ✅
│   └── pages/
│       ├── homepage/
│       │   └── index.phtml        # 首页内容 ✅
│       ├── product/
│       │   └── view.phtml         # 商品详情 ✅
│       ├── catalog/
│       │   └── category.phtml     # 商品列表 ✅
│       ├── cart/
│       │   └── index.phtml        # 购物车 ✅
│       ├── checkout/
│       │   ├── index.phtml        # 结算页 ✅
│       │   └── success.phtml      # 订单成功 ✅
│       └── customer/
│           ├── login.phtml        # 登录页 ✅
│           └── register.phtml     # 注册页 ✅
└── backend/
    └── assets/
        ├── css/
        └── js/
```

## 已完成的工作 ✅

### 1. 基础布局 (base.phtml)
- ✅ 完整的HTML结构
- ✅ Tailwind CSS配置
- ✅ Google Fonts和Material Symbols图标
- ✅ 暗色模式支持
- ✅ Hook点集成（head-before/after, body-start/end, header-before/after, content-before/after, footer-before/after）

### 2. Header模板 (header/default.phtml)
- ✅ Logo和品牌名称
- ✅ 导航菜单（分类、今日特价、畅销商品、新品）
- ✅ 搜索栏（带Hook点）
- ✅ 用户账户链接
- ✅ 迷你购物车（带下拉菜单）
- ✅ 语言切换器Hook点
- ✅ 货币切换器Hook点

### 3. Footer模板 (footer/default.phtml)
- ✅ 返回顶部按钮
- ✅ 多列链接（Browse、Company、Help、Follow Us）
- ✅ 社交媒体图标
- ✅ 应用下载链接
- ✅ 版权信息
- ✅ Hook点集成

### 4. 首页布局和内容
- ✅ 首页布局文件 (homepage.phtml)
- ✅ 首页内容模板 (pages/homepage/index.phtml)
- ✅ Hero横幅区域（带Hook点）
- ✅ 今日特价商品列表
- ✅ 分类浏览区域
- ✅ 动态数据绑定（商品、分类）
- ✅ Hook点集成

## 已完成的核心页面 ✅

### 1. 商品相关页面
- [x] 商品详情页模板 (`pages/product/view.phtml`)
- [x] 商品列表页模板 (`pages/catalog/category.phtml`)

### 2. 购物流程页面
- [x] 购物车页模板 (`pages/cart/index.phtml`)
- [x] 结算页模板 (`pages/checkout/index.phtml`)
- [x] 订单确认页模板 (`pages/checkout/success.phtml`)

### 3. 用户相关页面
- [x] 登录页模板 (`pages/customer/login.phtml`)
- [x] 注册页模板 (`pages/customer/register.phtml`)

### 4. 资源文件
- [x] 主题CSS (`frontend/assets/css/main.css`)
- [x] 主题JS (`frontend/assets/js/main.js`)

## 待完成的页面

### 用户相关页面
- [ ] 用户账户页模板（根据 用户账户页面_*.html）
- [ ] 密码重置页模板（根据 密码重置页面_*.html）

### 其他页面
- [ ] 评论查看页模板（根据 评论查看页面_*.html）
- [ ] 商品问答页模板（根据 商品问答页面_*.html）
- [ ] 退换货页模板（根据 退换货页面_*.html）
- [ ] 关于我们页模板（根据 关于我们页面_*.html）
- [ ] 客户服务页模板（根据 客户服务页面_*.html）
- [ ] 活动优惠页模板（根据 活动优惠页面_*.html）
- [ ] 博客页模板（根据 博客/*.html）

### 资源文件
- [ ] 复制UI中的图片到 assets/images/

## 设计特点

### 1. 响应式设计
- 使用Tailwind CSS的响应式类
- 支持移动端、平板、桌面端
- 自适应布局

### 2. 暗色模式
- 使用Tailwind的dark模式
- 完整的暗色主题配色
- 自动切换支持

### 3. 模块化设计
- 使用Hook点实现模块解耦
- 支持模块注入内容
- 易于扩展和维护

### 4. 国际化支持
- 所有文本使用 `__()` 函数
- 支持多语言切换
- 货币格式化支持

## Hook点使用

### Header Hook点
- `WeShop_Frontend::header::categories-menu` - 分类菜单
- `WeShop_Frontend::header::search-before` - 搜索框前
- `WeShop_Frontend::header::search-after` - 搜索框后
- `WeShop_Frontend::header::account` - 账户区域
- `WeShop_Frontend::frontend::partials::header::mini-cart` - 迷你购物车
- `WeShop_Frontend::header::language_switcher` - 语言切换器
- `WeShop_Frontend::header::currency_switcher` - 货币切换器

### Homepage Hook点
- `WeShop_Promotion::homepage::hero_banner` - Hero横幅
- `WeShop_Promotion::homepage::deals_before` - 特价商品前
- `WeShop_Promotion::homepage::deals_after` - 特价商品后
- `WeShop_Catalog::homepage::categories_before` - 分类前
- `WeShop_Catalog::homepage::categories_after` - 分类后
- `WeShop_Frontend::homepage::content_after` - 首页内容后

### Footer Hook点
- `WeShop_Social::footer::social_links` - 社交媒体链接

## 文件覆盖机制

### 核心原则：同名文件以激活主题为准

主题系统支持文件覆盖机制，遵循以下规则：

1. **同名文件覆盖**：如果激活主题存在同名文件，直接使用激活主题的文件，不再查找父主题
2. **文件查找顺序**：
   - 激活主题 → 如果存在同名文件，直接使用
   - 父主题链 → 仅在激活主题不存在时查找
   - 默认主题 → 作为后备

### 支持覆盖的文件类型

- **布局文件**：`view/theme/{area}/layouts/{type}/{option}.phtml`
- **片段文件**：`view/theme/{area}/partials/{type}/{option}.phtml`
- **组件文件**：`view/theme/{area}/components/{name}.phtml`
- **CSS文件**：`view/theme/{area}/assets/css/{file}.css`
- **JS模块文件**：`view/theme/{area}/assets/js/{module}.js` ⭐
- **配置文件**：`view/theme/{area}/config/{file}.json`

### JS模块继承机制

JS模块文件（如 `search.js`）支持主题继承和覆盖：

- **覆盖规则**：如果激活主题存在同名JS文件，直接使用激活主题的文件，跳过父主题的同名文件
- **模块收集**：JS模块收集时，如果激活主题存在同名文件，只收集激活主题的版本
- **示例**：
  - 父主题：`app/design/Weline/default/view/theme/frontend/assets/js/search.js`
  - 激活主题：`app/design/WeShop/default/view/theme/frontend/assets/js/search.js`
  - **结果**：系统只使用激活主题的 `search.js`，不会加载父主题的同名文件

## 文件覆盖机制

### 核心原则：同名文件以激活主题为准

主题系统支持文件覆盖机制，遵循以下规则：

1. **同名文件覆盖**：如果激活主题存在同名文件，直接使用激活主题的文件，不再查找父主题
2. **文件查找顺序**：
   - 激活主题 → 如果存在同名文件，直接使用
   - 父主题链 → 仅在激活主题不存在时查找
   - 默认主题 → 作为后备

### 支持覆盖的文件类型

- **布局文件**：`view/theme/{area}/layouts/{type}/{option}.phtml`
- **片段文件**：`view/theme/{area}/partials/{type}/{option}.phtml`
- **组件文件**：`view/theme/{area}/components/{name}.phtml`
- **CSS文件**：`view/theme/{area}/assets/css/{file}.css`
- **JS模块文件**：`view/theme/{area}/assets/js/{module}.js` ⭐
- **配置文件**：`view/theme/{area}/config/{file}.json`

### JS模块继承机制

JS模块文件（如 `search.js`）支持主题继承和覆盖：

- **覆盖规则**：如果激活主题存在同名JS文件，直接使用激活主题的文件，跳过父主题的同名文件
- **模块收集**：JS模块收集时，如果激活主题存在同名文件，只收集激活主题的版本
- **示例**：
  - 父主题：`app/design/Weline/default/view/theme/frontend/assets/js/search.js`
  - 激活主题：`app/design/WeShop/default/view/theme/frontend/assets/js/search.js`
  - **结果**：系统只使用激活主题的 `search.js`，不会加载父主题的同名文件

## 开发规范

1. **模板文件命名**：使用小写字母和下划线，如 `default.phtml`
2. **Hook点命名**：遵循 `{Module}::{area}::{type}::{component}::{position}` 格式
3. **国际化**：所有用户可见文本必须使用 `__()` 函数
4. **URL生成**：使用 `$this->getUrl()` 方法生成URL
5. **静态资源**：使用 `$this->getStaticUrl()` 方法引用静态资源
6. **数据绑定**：使用 `$this->getData()` 获取数据，使用 `$this->assign()` 设置数据
7. **文件覆盖**：要覆盖父主题的文件，在激活主题中创建同名文件即可
7. **文件覆盖**：要覆盖父主题的文件，在激活主题中创建同名文件即可

## UI设计文件位置

UI设计文件位于：`app/code/WeShop/Theme/stitch_e_commerce_home_page/`

每个页面目录包含：
- `code.html` - HTML代码
- `screen.png` - 设计截图

## 下一步

1. 继续根据UI文件创建其他页面模板
2. 提取CSS和JS到assets目录
3. 复制图片资源
4. 测试所有页面模板
5. 优化响应式布局
6. 完善Hook点集成
