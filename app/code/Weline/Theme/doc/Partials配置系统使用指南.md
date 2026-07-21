# Partials 配置系统使用指南

## 概述

Partials 配置系统允许在后台为每个主题配置不同的页面片段选项（如 header、footer、sidebar 等），实现主题的灵活定制。

## 目录结构

### 前端 Partials

```
app/code/Weline/Theme/view/theme/frontend/partials/
├── header/
│   ├── default.phtml    # 默认商城头部（Amazon 风格：深色搜索主栏 + 分类导航栏）
│   ├── minimal.phtml    # 极简头部（只包含Logo和导航）
│   └── centered.phtml   # 居中头部（Logo居中，导航在下方）
├── footer/
│   ├── default.phtml    # 默认商城底部（Amazon 风格：深色多栏链接、订阅和版权）
│   └── minimal.phtml    # 极简底部（只包含版权）
├── sidebar/
│   └── default.phtml    # 默认侧边栏
├── breadcrumb/
│   └── default.phtml    # 默认面包屑
└── pagination/
    └── default.phtml    # 默认分页
```

### 后端 Partials

```
app/code/Weline/Theme/view/theme/backend/partials/
├── header/
│   └── default.phtml    # 默认头部
├── footer/
│   └── default.phtml    # 默认底部
└── sidebar/
    └── default.phtml    # 默认侧边栏
```

## 添加新的 Partials 选项

1. 在对应的类型目录下创建新的 `.phtml` 文件
2. 文件名即为选项名称（如 `minimal.phtml` 对应选项 `minimal`）
3. 系统会自动扫描并显示在配置选项中

**示例**：添加一个新的 header 选项

```bash
# 创建文件
app/code/Weline/Theme/view/theme/frontend/partials/header/amazon.phtml
```

系统会自动识别并在配置页面显示 `amazon` 选项。

## 后台配置

## 默认前台商城风格

`frontend/header/default.phtml` 与 `frontend/footer/default.phtml` 是默认 Theme 的公共商城壳，默认使用 Amazon 风格的信息架构：

- Header：`#131921` 深色主栏、橙色搜索按钮、`#232f3e` 分类导航栏，以及语言、货币、账户、购物车入口。
- Header 响应式：视口不超过 `1280px` 时，语言入口仅保留当前国旗，搜索框收起为放大镜按钮；点击后搜索框在主栏下一行全宽展开，支持 `Esc` 或点击外部关闭。
- Footer：`#131a22` 深色背景、多栏帮助/政策链接、订阅、社交与支付信息区。
- 颜色可通过 `--weline-theme-header-*` / `--weline-theme-footer-*` 变量覆盖；子主题无需复制完整 partial。
- 结账、购物车、商品、分类等公共页面只要 `meta.showHeader` / `meta.showFooter` 为真，就复用该默认壳。

### 访问配置页面

1. 进入后台管理界面
2. 导航到主题管理页面
3. 选择要配置的主题
4. 点击 "Partials 配置" 或访问：`/theme/backend/config/partials?theme_id={主题ID}`

### 配置选项

- **前端 Partials**：为前端页面配置 header、footer、sidebar 等
- **后端 Partials**：为后端管理界面配置 header、footer、sidebar 等

每个 partials 类型会显示该主题下所有可用的选项，选择后保存即可。

## 在模板中使用

### 方法 1：使用 w:theme:template 标签（推荐，最简单）

使用 `w:theme:template` 标签是最简单的方式，标签会自动从主题配置中获取对应的模板路径。

```html
<!-- 使用 layout 属性，从主题配置获取模板路径 -->
<w:theme:template layout="partials.header">
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>

<!-- 不使用 layout 属性，使用默认路径 -->
<w:theme:template>
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
```

**标签属性说明：**
- **`layout`**（可选）：布局标识，如 `partials.header`、`partials.footer` 等
  - 如果指定了 `layout` 属性，标签会从主题配置中获取对应的模板路径
  - 如果主题配置中不存在该布局的配置，则使用标签内容中的默认路径
- **`enable`**（可选）：是否启用标签，默认为 `1`（启用）

**工作原理：**
1. 如果指定了 `layout="partials.header"`，标签会从主题配置中查找 `partials.header` 的配置值
2. 如果配置值为 `minimal`，则加载 `theme/frontend/partials/header/minimal.phtml`
3. 如果配置不存在，则使用标签内容中的默认路径 `default.phtml`

**完整示例：**

```html
<!DOCTYPE html>
<html>
<head>
    <title>页面标题</title>
</head>
<body>
    <!-- Header：从主题配置获取 -->
    <w:theme:template layout="partials.header">
        Weline_Theme::theme/frontend/partials/header/default.phtml
    </w:theme:template>
    
    <main>
        <!-- 页面内容 -->
    </main>
    
    <!-- Footer：从主题配置获取 -->
    <w:theme:template layout="partials.footer">
        Weline_Theme::theme/frontend/partials/footer/default.phtml
    </w:theme:template>
</body>
</html>
```

### 方法 2：使用 Partials Block

```php
<?php
// 获取 Partials Block
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);

// 获取配置的 header 路径（会自动根据主题配置选择）
$headerPath = $partialsBlock->getPartialsPath('frontend', 'header', 'default');

// 渲染 header
if ($headerPath) {
    echo $this->fetch($headerPath, [
        'logo' => $logo,
        'logoText' => $logoText,
        'navItems' => $navItems
    ]);
}
?>
```

### 方法 2：使用 renderPartials 方法

```php
<?php
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);
echo $partialsBlock->renderPartials('frontend', 'header', [
    'logo' => $logo,
    'navItems' => $navItems
], 'default');
?>
```

### 方法 3：在布局中使用

```php
<?php
// 在布局文件中
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);

// Header
$headerPath = $partialsBlock->getPartialsPath('frontend', 'header', 'default');
if ($headerPath) {
    echo $this->fetch($headerPath);
}

// Footer
$footerPath = $partialsBlock->getPartialsPath('frontend', 'footer', 'default');
if ($footerPath) {
    echo $this->fetch($footerPath);
}
?>
```

## 配置存储

配置存储在主题表的 `config` 字段中（JSON格式）：

```json
{
    "partials": {
        "frontend": {
            "header": "default",
            "footer": "minimal",
            "sidebar": "default"
        },
        "backend": {
            "header": "default",
            "footer": "default",
            "sidebar": "default"
        }
    }
}
```

## 主题继承

- 如果子主题没有配置某个 partials，会自动使用父主题的配置
- 如果父主题也没有配置，则使用 `default` 选项
- 子主题可以覆盖父主题的配置

## API 使用

### 扫描可用的 Partials

```php
use Weline\Theme\Helper\PartialsScanner;
use Weline\Theme\Model\WelineTheme;

$theme = ObjectManager::getInstance(WelineTheme::class);
$theme->load($themeId);

// 扫描前端 partials
$frontendPartials = PartialsScanner::scanPartials($theme, 'frontend');
// 返回：['header' => ['default', 'minimal', 'centered'], 'footer' => ['default', 'minimal'], ...]

// 扫描后端 partials
$backendPartials = PartialsScanner::scanPartials($theme, 'backend');
```

### 获取配置的 Partials 路径

```php
// 获取配置的 header 路径
$headerPath = PartialsScanner::getPartialsPath($theme, 'frontend', 'header', 'default');
// 返回：'Weline_Theme::theme/frontend/partials/header/default.phtml'
```

## Chrome 输出缓存（声明式）

后台壳片段（head / topbar / sidebar / scripts 等）可通过文件头 `@meta.cache.*` 启用输出缓存，无需在 `Partials` 中维护类型白名单：

```php
/**
 * @meta.cache.mode {default="chrome",name="缓存模式"}
 * @meta.cache.auth {default="role",name="鉴权维度"}
 * @meta.cache.ttl {default="300",name="TTL秒"}
 */
```

- `mode=chrome`：启用 **Worker 进程内** LRU 输出缓存；缺省或解析失败则不缓存
- `auth`：`guest` | `role` | `user`（topbar 含用户名时用 `user`）
- 缓存 key 经 `KeyBuilder::environmentHash`，按网站、lang / lang_local、currency、主题/配色和鉴权维度复用
- 后台 URI、控制器、页面布局和内容数据不进入壳缓存 key；同环境访问新页面继续复用同一壳
- 热路径不走 `theme_runtime` 共享内存：在连接池压力下每次 `wls.memory.get/set` 可达约 200ms，会导致壳缓存比直接渲染更慢
- 每个 Worker 首次渲染后进程内命中；同 Worker 热刷应亚百毫秒级。跨 Worker 需各自预热（bootstrap warmup / 首次请求）
- 不缓存内容插槽；不整页 FPC

## 注意事项

1. **每个类型至少需要一个 `default.phtml` 文件**，作为默认选项
2. **配置的选项必须存在**，否则会自动回退到 `default`
3. **支持主题继承**，子主题可以覆盖父主题的配置
4. **配置保存后会清除主题缓存**，确保立即生效

