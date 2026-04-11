# PageBuilder 模板开发规范

## 概述

本文档定义了 PageBuilder 模块的模板开发规范，确保所有模板遵循统一的结构和约定，保证渲染系统的稳定性和一致性。

## 目录结构

每个模板必须遵循以下目录结构：

```
style/{template_code}/
├── template.json          # 模板元数据配置（推荐）
├── header.phtml           # 系统 Header 组件（组件片段）
├── footer.phtml           # 系统 Footer 组件（组件片段）
├── content.phtml          # 内容区域容器（可选）
├── layout.phtml           # 布局容器（可选）
├── components/            # 组件目录
│   ├── component.json     # 组件配置文件（必需）
│   ├── header/           # Header 组件目录
│   │   ├── nav.phtml     # 导航组件
│   │   └── banner.phtml  # Banner 组件
│   ├── content/          # Content 组件目录
│   │   ├── hero.phtml    # Hero 组件
│   │   └── features.phtml
│   └── footer/           # Footer 组件目录
│       └── links.phtml   # 链接组件
├── layouts/               # 布局配置目录
│   ├── layouts.json      # 布局列表配置
│   └── default/          # 默认布局配置
│       ├── home_page.json
│       └── custom_page.json
├── colors/               # 颜色主题（可选）
│   └── default.phtml
└── assets/               # 静态资源（可选）
    └── css/
```

## 核心规范

### 1. 模板元数据（template.json）

```json
{
  "code": "tpmst",
  "name": "Teen Patti Master",
  "name_en": "Teen Patti Master Template",
  "version": "1.0.0",
  "description": "高转化率的游戏下载页模板",
  "author": "GuoLaiRen",
  "license": "proprietary",
  "supports": {
    "regions": ["header", "content", "footer"],
    "page_types": ["home_page", "custom_page", "product_page"],
    "features": ["visual_editor", "ai_components"]
  },
  "dependencies": {
    "framework_version": ">=1.0.0"
  }
}
```

### 2. Header/Footer 组件规范

**重要：Header 和 Footer 必须是纯 HTML 片段，不能包含完整的 HTML 文档结构。**

#### 正确示例

```php
<?php
// header.phtml - 正确示例
$page = $this->getData('page');
$styleSettings = $this->getData('style') ?: [];
?>
<!-- 只输出 header 相关的 HTML 片段 -->
<header class="site-header">
    <nav>
        <!-- 导航内容 -->
    </nav>
</header>
```

#### 错误示例（禁止）

```php
<?php
// header.phtml - 错误示例（禁止使用）
?>
<!DOCTYPE html>
<html>
<head>
    <title>...</title>
</head>
<body>
    <header>...</header>
```

### 3. 组件配置（component.json）

```json
{
  "template": "tpmst",
  "version": "1.0.0",
  "regions": {
    "header": {
      "name": "头部区域",
      "multiple": false,
      "default_component": "header-nav"
    },
    "content": {
      "name": "内容区域",
      "multiple": true,
      "default_components": ["content-hero"]
    },
    "footer": {
      "name": "底部区域",
      "multiple": false,
      "default_component": "footer-links"
    }
  },
  "components": {
    "header-nav": {
      "name": "导航栏",
      "name_en": "Navigation Bar",
      "description": "网站顶部导航栏",
      "region": "header",
      "category": "header",
      "type": "section",
      "icon": "bi-list",
      "file": "header/nav.phtml",
      "sort_order": 10,
      "is_default": true,
      "compatible_styles": ["*"],
      "config_schema": {
        "logo": {
          "type": "image",
          "label": "Logo",
          "default": ""
        },
        "navigation_items": {
          "type": "json",
          "label": "导航项",
          "default": []
        }
      }
    }
  }
}
```

### 4. 组件代码命名规范

组件代码必须使用 `{category}-{name}` 格式：

- 全部小写
- 使用破折号（-）连接
- 不带模板前缀

#### 正确示例

- `header-nav`
- `header-banner`
- `content-hero`
- `content-features`
- `footer-links`
- `footer-copyright`

#### 错误示例（禁止）

- `tpmst_header_nav`（不要使用模板前缀）
- `headerNav`（不要使用驼峰命名）
- `header_nav`（不要使用下划线）

### 5. 布局配置格式

布局配置必须使用统一的数组格式，使用 `code` 字段标识组件：

```json
{
  "layout_config": {
    "header": [
      {
        "code": "header-nav",
        "enabled": true,
        "config": {
          "logo": "/images/logo.png"
        }
      }
    ],
    "content": [
      {
        "code": "content-hero",
        "enabled": true,
        "config": {}
      },
      {
        "code": "content-features",
        "enabled": true,
        "config": {}
      }
    ],
    "footer": [
      {
        "code": "footer-links",
        "enabled": true,
        "config": {}
      }
    ]
  }
}
```

## 组件开发指南

### 1. 组件文件结构

每个组件文件应包含：

1. **文件头注释**：描述组件功能和配置字段
2. **数据获取**：从模板上下文获取数据
3. **辅助函数**：定义组件专用的辅助函数
4. **HTML 输出**：组件的 HTML 结构

```php
<?php
/**
 * Hero 组件
 * 
 * @var \Weline\Framework\View\Template $this
 * 
 * @fields_start
 * 
 * group:content => 内容设置
 * content.title => 标题:text:Welcome
 * content.subtitle => 副标题:text:Get started today
 * content.cta_text => CTA按钮文字:text:Download Now
 * 
 * group:style => 样式设置
 * style.background_color => 背景颜色:color:#000000
 * 
 * @fields_end
 */

// 获取数据
$page = $this->getData('page');
$config = $this->getData('component_config') ?: [];
$styleSettings = $this->getData('style_settings') ?: [];

// 辅助函数
if (!function_exists('hero_getConfig')) {
    function hero_getConfig($config, $key, $default = '') {
        return $config[$key] ?? $default;
    }
}

// 配置值
$title = hero_getConfig($config, 'content.title', 'Welcome');
$subtitle = hero_getConfig($config, 'content.subtitle', 'Get started today');
?>

<!-- Hero Section -->
<section class="hero-section">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($subtitle) ?></p>
</section>
```

### 2. 配置字段语法

配置字段使用特殊注释语法定义：

```
group:groupName => 分组名称:分组说明
fieldName => 字段标签:类型:默认值|选项列表:说明
```

支持的字段类型：

| 类型 | 说明 | 示例 |
|------|------|------|
| `text` | 单行文本 | `title => 标题:text:Hello` |
| `textarea` | 多行文本 | `desc => 描述:textarea:` |
| `number` | 数字 | `width => 宽度:number:100` |
| `color` | 颜色选择器 | `bg => 背景色:color:#ffffff` |
| `select` | 下拉选择 | `display => 显示:select:yes\|yes,no` |
| `image` | 图片上传 | `logo => Logo:image:` |
| `json` | JSON 数据 | `items => 项目:json:[]` |
| `responsive` | 响应式值 | `padding => 内边距:responsive:10/20\|px[MD]` |

### 3. 跨模板组件

组件可以通过 `compatible_styles` 字段声明兼容的模板：

```json
{
  "compatible_styles": ["*"]       // 兼容所有模板
  "compatible_styles": ["tpmst", "sattaking"]  // 仅兼容指定模板
}
```

## 验证工具

使用 TemplateValidator 验证模板结构：

```php
use GuoLaiRen\PageBuilder\Service\Template\TemplateValidator;

$validator = TemplateValidator::getInstance();
$result = $validator->validate('tpmst');

if (!$result) {
    $errors = $validator->getErrors();
    $warnings = $validator->getWarnings();
}
```

## 渲染流程

1. **获取布局配置**：从页面数据库或默认布局文件加载
2. **配置标准化**：使用 LayoutConfigNormalizer 统一格式
3. **组件解析**：使用 ComponentResolver 查找组件
4. **组件渲染**：按区域渲染组件 HTML
5. **HTML 组装**：构建完整的 HTML 文档

## 最佳实践

1. **始终使用组件片段**：不要在 header/footer 中包含完整 HTML 结构
2. **使用标准命名**：组件代码使用 `{category}-{name}` 格式
3. **提供默认配置**：在 config_schema 中定义合理的默认值
4. **注释清晰**：使用 @fields_start/@fields_end 定义可配置字段
5. **验证模板**：开发完成后使用验证工具检查

## 变更日志

- **v1.0.0** (2026-01-28): 初始版本
  - 定义模板目录结构
  - 定义组件命名规范
  - 定义布局配置格式
  - 创建验证工具
