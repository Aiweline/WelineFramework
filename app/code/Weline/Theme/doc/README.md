# Weline Theme 主题模块

## 模块概述

Weline Theme 是系统的主题管理模块，提供了多主题支持、主题切换、主题定制等功能，让系统具有灵活的界面展示能力。

## 当前规划入口

- [Theme 虚拟布局与产品/分类布局计划](./virtual-layout-scope-plan.md)：Theme 模块子计划，覆盖虚拟布局、产品/分类布局、源码编辑、可视化编辑、AI 创建和定时恢复。
- [SystemConfig 与 Theme 虚拟布局总计划](../../SystemConfig/doc/scope-config-theme-layout-master-plan.md)：跨模块总计划，关联 Framework Scope、SystemConfig 配置树和 Theme 虚拟布局。
- [SystemConfig Scope 配置树计划](../../SystemConfig/doc/scope-config-tree-plan.md)：配置模块子计划，定义统一 scope 配置系统、fallback、缓存和后台配置树。

## 主要功能

### 1. 主题管理
- **多主题支持**：系统支持多个主题并存，可在后台切换
- **主题切换**：支持前后台分别配置不同的主题
- **主题配置**：通过后台界面配置主题的各项参数
- **主题继承**：支持主题继承机制，子主题可以继承父主题的配置
- **主题列表**：后台提供主题列表页面，显示所有已安装的主题

### 2. 后台管理功能
- **主题列表页面** (`theme/backend/index`)：显示所有主题，包括主题名称、模块名、路径、激活状态等
- **布局配置** (`theme/backend/config/layout`)：为每个主题配置前后台的布局和Header样式
- **Partials配置** (`theme/backend/config/partials`)：为每个主题配置前后台的页面片段（header、footer、sidebar等）

### 3. 模板系统
- **模板引擎**：基于PHP的模板引擎，支持模板继承和区块系统
- **布局管理**：支持前后台独立的布局配置
- **区块系统**：支持可复用的页面区块
- **Partials系统**：支持页面片段（header、footer、sidebar等）的灵活配置
- **主题模板标签**：提供 `w:theme:template` 标签，支持从主题配置动态加载模板

### 4. 资源管理
- **静态资源管理**：统一管理主题的CSS、JS、图片等静态资源
- **主题资源打包**：支持主题资源的打包和压缩
- **CDN 支持**：支持将静态资源部署到CDN

### 5. 主题定制
- **主题参数配置**：通过数据库存储主题配置（JSON格式）
- **样式定制**：支持CSS变量和主题配色方案
- **功能扩展**：支持通过Helper类扩展主题功能

### 6. 响应式设计
- **移动端适配**：支持移动端和桌面端的自适应布局
- **多设备支持**：支持不同屏幕尺寸的设备
- **自适应布局**：布局自动适配不同设备

## 使用方法

### 主题模板标签 (w:theme:template)

`w:theme:template` 标签用于加载主题配置的模板文件，支持通过 `layout` 属性从主题配置中动态获取模板路径。

#### 基本用法

**使用 layout 属性，从主题配置获取模板路径：**

```html
<w:theme:template layout="partials.header">
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
```

**不使用 layout 属性，使用默认路径：**

```html
<w:theme:template>
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
```

#### 标签属性

- **`layout`**（可选）：布局标识，如 `partials.header`、`partials.footer` 等
  - 如果指定了 `layout` 属性，标签会从主题配置中获取对应的模板路径
  - 如果主题配置中不存在该布局的配置，则使用标签内容中的默认路径
- **`enable`**（可选）：是否启用标签，默认为 `1`（启用）
  - 设置为 `0` 或 `false` 时，标签会被禁用，显示注释信息

#### 工作原理

1. **有 layout 属性时**：
   - 标签会调用 `ThemeConfigHelper::getTemplatePath()` 方法
   - 根据当前区域（frontend/backend）和语言自动获取配置的模板路径
   - 如果配置存在，使用配置的路径（如 `theme/frontend/partials/header/minimal.phtml`）
   - 如果配置不存在，回退到标签内容中的默认路径

2. **无 layout 属性时**：
   - 直接使用标签内容中指定的模板路径

#### 使用示例

**示例 1：加载主题配置的头部模板**

```html
<!-- 从主题配置获取头部模板路径 -->
<w:theme:template layout="partials.header">
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
```

如果主题配置了 `partials.header = minimal`，则实际加载：
```
Weline_Theme::theme/frontend/partials/header/minimal.phtml
```

**示例 2：加载主题配置的底部模板**

```html
<w:theme:template layout="partials.footer">
    Weline_Theme::theme/frontend/partials/footer/default.phtml
</w:theme:template>
```

**示例 3：使用默认路径（不依赖配置）**

```html
<w:theme:template>
    Weline_Theme::theme/frontend/partials/sidebar/default.phtml
</w:theme:template>
```

**示例 4：禁用标签**

```html
<w:theme:template enable="0" layout="partials.header">
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
```

#### 配置说明

主题配置存储在 `w_meta_config` 表中，通过以下方式配置：

- **命名空间**：`theme.frontend` 或 `theme.backend`
- **配置键**：布局标识，如 `partials.header`
- **配置值**：模板文件名（不含路径和扩展名），如 `minimal`、`default` 等
- **语言支持**：支持多语言配置，自动回退到默认语言

#### 相关文件

- **标签类**：`app/code/Weline/Theme/Taglib/ThemeTemplate.php`
- **配置助手**：`app/code/Weline/Theme/Helper/ThemeConfigHelper.php`
- **配置模型**：`app/code/Weline/Meta/Model/MetaConfig.php`

### 主题创建
```php
namespace Your\Theme;

use Weline\Theme\ThemeInterface;

class YourTheme implements ThemeInterface
{
    public function getName()
    {
        return 'your_theme';
    }
    
    public function getTitle()
    {
        return 'Your Theme';
    }
    
    public function getVersion()
    {
        return '1.0.0';
    }
    
    public function getAuthor()
    {
        return 'Your Name';
    }
}
```

### 主题配置
```php
// 主题配置文件: etc/theme.xml
<?xml version="1.0"?>
<theme>
    <name>your_theme</name>
    <title>Your Theme</title>
    <version>1.0.0</version>
    <author>Your Name</author>
    
    <areas>
        <area name="frontend">
            <layout>default</layout>
        </area>
        <area name="admin">
            <layout>admin</layout>
        </area>
    </areas>
    
    <assets>
        <css>static/css/style.css</css>
        <js>static/js/app.js</js>
    </assets>
</theme>
```

### 模板开发
```html
<!-- 布局文件: design/frontend/default/layout.html -->
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {block name="head"}
        <link rel="stylesheet" href="{$theme_url}/css/style.css">
    {/block}
</head>
<body>
    <header>
        {block name="header"}
            {include file="header.html"}
        {/block}
    </header>
    
    <main>
        {block name="content"}
            {include file="$template"}
        {/block}
    </main>
    
    <footer>
        {block name="footer"}
            {include file="footer.html"}
        {/block}
    </footer>
    
    {block name="scripts"}
        <script src="{$theme_url}/js/app.js"></script>
    {/block}
</body>
</html>
```

### 区块开发
```php
namespace Your\Theme\Block;

use Weline\Theme\Block\AbstractBlock;

class YourBlock extends AbstractBlock
{
    public function render()
    {
        $data = $this->getData();
        return $this->fetch('your_block.html', $data);
    }
    
    protected function getData()
    {
        return [
            'title' => '区块标题',
            'content' => '区块内容'
        ];
    }
}
```

## 配置说明

### 模块路由配置
在 `app/code/Weline/Theme/etc/env.php` 中配置路由别名：

```php
<?php
return [
    'router' => 'theme',  // 路由别名，用于生成URL路径
];
```

**路由访问地址**：
- 主题列表：`/theme/backend/index`
- 布局配置：`/theme/backend/config/layout?theme_id={主题ID}`
- Partials配置：`/theme/backend/config/partials?theme_id={主题ID}`

### 主题数据库配置
主题配置存储在数据库的 `weline_theme` 表中，`config` 字段存储JSON格式的配置：

```json
{
    "layouts": {
        "frontend": "default",
        "backend": "admin"
    },
    "headers": {
        "frontend": "default",
        "backend": "default"
    },
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

### 主题参数
```php
'theme_params' => [
    'default' => [
        'primary_color' => '#007bff',
        'secondary_color' => '#6c757d',
        'font_family' => 'Arial, sans-serif',
        'logo' => 'static/images/logo.png'
    ]
]
```

## 后台管理功能详解

### 1. 主题列表页面
**访问路径**：`/theme/backend/index`

**功能说明**：
- 显示所有已安装的主题
- 显示主题的基本信息：名称、模块名、路径、激活状态
- 提供快速操作按钮：布局配置、Partials配置
- 支持主题激活状态标识

**控制器**：`Weline\Theme\Controller\Backend\Index`
**模板**：`Weline_Theme::templates/backend/index.phtml`

### 2. 布局配置页面
**访问路径**：`/theme/backend/config/layout?theme_id={主题ID}`

**功能说明**：
- 为指定主题配置前后台的布局文件
- 为指定主题配置前后台的Header样式
- 支持主题继承，自动扫描父主题的布局和Header
- 配置保存后自动更新主题的config字段

**控制器**：`Weline\Theme\Controller\Backend\Config\Layout`
**模板**：`Weline_Theme::templates/backend/config/layout.phtml`
**Helper**：`Weline\Theme\Helper\LayoutScanner`

### 3. Partials配置页面
**访问路径**：`/theme/backend/config/partials?theme_id={主题ID}`

**功能说明**：
- 为指定主题配置前后台的页面片段（header、footer、sidebar等）
- 自动扫描主题目录下的partials文件
- 支持主题继承，子主题可以覆盖父主题的配置
- 配置保存后自动更新主题的config字段

**控制器**：`Weline\Theme\Controller\Backend\Config\Partials`
**模板**：`Weline_Theme::templates/backend/config/partials.phtml`
**Helper**：`Weline\Theme\Helper\PartialsScanner`

### 4. 菜单配置
**配置文件**：`app/code/Weline/Theme/etc/backend/menu.xml`

**菜单结构**：
- 主题管理（父菜单）
  - 主题列表
  - 主题配置（子菜单）
    - 布局配置
    - Partials配置

## 依赖关系

- **Weline_Framework**：核心框架，提供控制器基类、路由系统、数据库模型等
- **Weline_Backend**：后台管理模块，提供菜单系统和后台布局

## Hook 使用

Weline Theme 模块提供了完整的 Hook 机制，允许 Customer（客户/开发者）通过 hook 将自己的逻辑注入到主题布局中，实现功能扩展而不修改主题核心代码。

### Hook 类型

1. **Base Hook（基础 Hook）**：为所有布局提供统一配置的 hook 点
   - 一次配置，所有布局生效
   - 适合全局 CSS/JS 注入、全局功能模块
   - 示例：`Weline_Theme::frontend::layouts::base::head-after`

2. **详细布局 Hook**：为特定布局类型提供的 hook 点
   - 只为特定布局生效
   - 适合布局特定的功能定制
   - 示例：`Weline_Theme::frontend::layouts::homepage::content-before`

### 快速开始

1. **查找可用的 Hook**：查看 `app/code/Weline/Theme/hook.php` 文件
2. **创建 Hook 文件**：在模块的 `view/hooks/` 目录下创建 hook 文件
3. **文件命名规则**：Hook 名称中的 `::` 需要转换为 `--`
   - Hook 名称：`Weline_Theme::frontend::layouts::base::head-after`
   - 文件名：`Weline_Theme--frontend--layouts--base--head-after.phtml`

### 详细文档

- [Hook 使用指南](Hook使用指南.md) - 完整的使用指南和最佳实践
- [Base Hook 文档](hook/frontend/layouts/base/) - Base Hook 详细文档
- [首页布局 Hook 文档](hook/frontend/layouts/homepage/) - 首页布局 Hook 详细文档

### 使用示例

**全局 CSS 注入（使用 Base Hook）**：
```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/custom.css') ?>">
```

**首页特定功能（使用详细布局 Hook）**：
```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--homepage--content-before.phtml -->
<div class="homepage-banner">
    <img src="<?= $this->getUrl('static/images/banner.jpg') ?>" alt="Banner">
</div>
```

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 主题结构

### 标准主题目录结构
```
your_theme/
├── etc/
│   └── theme.xml
├── design/
│   ├── frontend/
│   │   └── default/
│   │       ├── layout.html
│   │       ├── header.html
│   │       ├── footer.html
│   │       └── templates/
│   └── admin/
│       └── default/
├── static/
│   ├── css/
│   ├── js/
│   └── images/
├── Block/
├── Helper/
└── register.php
```

### 模板继承
```html
<!-- 父模板 -->
{block name="content"}
    <div class="content">
        默认内容
    </div>
{/block}

<!-- 子模板 -->
{extends file="parent.html"}

{block name="content"}
    <div class="custom-content">
        自定义内容
    </div>
{/block}
```

## 主题切换

### 程序化切换
```php
use Weline\Theme\Helper\Theme;

$theme = new Theme();
$theme->setCurrentTheme('your_theme');
```

### 用户切换
```php
// 在控制器中处理主题切换
public function switchTheme()
{
    $themeName = $this->getRequest()->getParam('theme');
    $theme = new Theme();
    $theme->setCurrentTheme($themeName);
    
    $this->redirect('frontend/index/index');
}
```

## 响应式设计

### 移动端适配
```css
/* 响应式样式 */
@media (max-width: 768px) {
    .container {
        width: 100%;
        padding: 0 15px;
    }
    
    .nav {
        display: none;
    }
    
    .mobile-nav {
        display: block;
    }
}

@media (max-width: 480px) {
    .header {
        padding: 10px 0;
    }
    
    .logo {
        max-width: 150px;
    }
}
```

### 触摸优化
```css
/* 触摸友好的按钮 */
.btn {
    min-height: 44px;
    min-width: 44px;
    padding: 12px 20px;
}

/* 触摸反馈 */
.btn:active {
    transform: scale(0.95);
}
```

## 主题定制

### 样式定制
```css
/* 主题变量 */
:root {
    --primary-color: #007bff;
    --secondary-color: #6c757d;
    --font-family: 'Arial', sans-serif;
}

/* 使用变量 */
.btn-primary {
    background-color: var(--primary-color);
    font-family: var(--font-family);
}
```

### 功能扩展
```php
// 主题助手类
namespace Your\Theme\Helper;

class ThemeHelper
{
    public function getCustomData()
    {
        return [
            'custom_setting' => 'value',
            'theme_config' => $this->getThemeConfig()
        ];
    }
}
```

## 性能优化

### 1. 资源优化
- CSS/JS 文件合并压缩
- 图片优化和压缩
- 使用 CDN 加速

### 2. 缓存策略
- 模板缓存
- 静态资源缓存
- 浏览器缓存

### 3. 加载优化
- 异步加载非关键资源
- 预加载关键资源
- 懒加载图片

## 主题开发工具

### 主题生成器
```bash
# 生成新主题
php bin/w theme:create your_theme

# 生成主题资源
php bin/w theme:assets your_theme

# 清理主题缓存
php bin/w theme:clear your_theme
```

### 主题测试
```php
// 主题功能测试
class ThemeTest extends TestCase
{
    public function testThemeRendering()
    {
        $theme = new Theme();
        $theme->setCurrentTheme('test_theme');
        
        $result = $theme->render('test_template');
        $this->assertNotEmpty($result);
    }
}
```

## 最佳实践

### 1. 主题设计
- 遵循设计规范
- 保持界面一致性
- 注重用户体验

### 2. 代码组织
- 模块化开发
- 代码复用
- 文档完善

### 3. 性能考虑
- 优化资源加载
- 减少HTTP请求
- 合理使用缓存

### 4. 兼容性
- 多浏览器支持
- 移动端适配
- 渐进增强 

## Developer Guides

- [Theme layout discovery and extension guide](./layout-discovery-guide.md): explains how modules add layouts, how `app/design` overrides layouts, how adjacent `*.layout.json` is discovered, and how to validate layout discovery.
