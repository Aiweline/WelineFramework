# Theme Structure Reference

## 1. 目录总览

默认主题源码位于 `app/code/Weline/Theme/view/theme/`，按 area 分成：

- `frontend/`
- `backend/`

两个 area 都遵循同一套高层结构：

- `assets/css/theme.css`
- `assets/js/theme.js`
- `colors/_*.css`
- `components/*.phtml`
- `config/modules.json`
- `layouts/<layoutType>/<option>.phtml`
- `partials/<type>/<option>.phtml`
- `variables/_*.css`
- `widgets/<type>/<code>/default.phtml`

## 2. Layout 规则

布局由 `ControllerFetchFileBefore` 解析。

- 控制器设置 `layoutType`
  - 例：`homepage`
  - 解析为 type=`homepage`
- 控制器也可以设置 `layoutType.option`
  - 例：`account.auth`
  - 解析为 type=`account`，option=`auth`
- 如果控制器没指定 option，则 option 可以来自主题配置或预览 scope
- 最终模板路径遵循：
  - `Module::theme/{area}/layouts/{layoutType}/{option}.phtml`

### 当前 frontend layoutType

- `account`
- `account_auth`
- `account_logout`
- `account_orders`
- `account_profile`
- `activity`
- `cart`
- `category`
- `checkout`
- `checkout_failer`
- `checkout_success`
- `cms_page`
- `default`
- `homepage`
- `policy`
- `product`
- `product_list`
- `test`

### 当前 backend layoutType

- `dashboard`
- `default`
- `fullscreen`
- `login`
- `minimal`
- `print`

## 3. Partials 规则

片段由 `Weline\Theme\Block\Partials` 加载。

- 推荐写法：

```html
<w:block
    class="Weline\Theme\Block\Partials"
    area="frontend"
    type="header"
    default-option="default"/>
```

- 路径规则：
  - `Module::theme/{area}/partials/{type}/{option}.phtml`
- 回退顺序：
  - 当前主题
  - 父主题
  - `Weline_Theme`
- option 选择来源：
  - 预览配置
  - 已保存主题配置
  - `default-option`

### 当前 frontend partial type

- `breadcrumb`
- `footer`
- `head`
- `header`
- `pagination`
- `sidebar`

### 当前 backend partial type

- `breadcrumb`
- `footer`
- `head`
- `header`
- `loading`
- `right-sidebar`
- `scripts`
- `sidebar`
- `topbar`
- `topnav`

## 4. Widgets 规则

widget 路径固定为：

- `theme/{area}/widgets/{type}/{code}/default.phtml`

关键点：

- `type` 是一级目录名
- `code` 是二级目录名
- 默认模板文件名固定是 `default.phtml`
- widget 元数据来自文件顶部的 `@widget.*` 注释
- 涉及 widget 清单时，还要检查：
  - `app/code/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php`

### 当前 frontend widget type

- `banner`
- `breadcrumb`
- `carousel`
- `category`
- `category-filters`
- `container`
- `content`
- `faq`
- `footer`
- `header`
- `navigation`
- `newsletter`
- `pagination`
- `product`
- `search`
- `sidebar`
- `social`
- `testimonial`
- `video`

### 当前 backend widget type

- `data`

## 5. Variables / Colors 规则

### `variables/`

- 文件名以 `_` 开头，例如 `_colors.css`
- 用于定义 token
- 当前默认文件：
  - `_colors.css`
  - `_spacing.css`
  - `_typography.css`
  - `_shadows.css`
  - `_borders.css`

### `colors/`

- 文件名以 `_` 开头，例如 `_dark.css`
- 用于定义整套色盘覆盖
- frontend 当前默认色盘：
  - `default`
  - `light`
  - `dark`
  - `amazon`
- backend 当前默认色盘：
  - `default`
  - `light`
  - `dark`

### 运行机制

- `CssVariableScanner` 扫描 `variables/_*.css`
- `LayoutScanner` 扫描 `colors/_*.css`
- `ControllerFetchFileBefore` 会把 `colors` 注入模板数据
- 生成的布局 CSS 会合并变量、色盘和布局特定样式

## 6. Assets 与 Modules

### `assets/css/theme.css`

- 存放 area 级公共样式
- 不要把每个 layout 都会用到的基础样式分散复制到多个模板里

### `assets/js/theme.js`

- 存放 area 级公共脚本入口
- 消费 `window.__WelineThemeConfig`
- 负责模块配置、主题切换、全局交互等公共逻辑

### `config/modules.json`

- 仅负责 JS 模块路径和别名
- 会参与 `weline.modules.js` 编译
- 不要把主题布局、色盘、partials、variables 的选择写进这里

## 7. 配置来源

当前主题配置主要来自：

- `ThemeConfigManager`
- `ConfigLoader`
- `ThemeData`
- `weline_theme.config` 数据库存储

不要再把 `view/theme/**/config/theme.json` 当作当前主题配置入口。这个认知已经过时。

## 8. Scope

主题配置支持 scope。

- 请求参数可能是：
  - `scope`
  - `scope_frontend`
  - `scope_backend`
- `PreviewManager` 也会提供 preview scope
- 读取配置时遵循：
  - preview config
  - saved config
  - default fallback
