# PageBuilder 组件开发规范

## 概述

本文档定义了 PageBuilder 模块的组件开发规范，确保所有组件遵循统一的结构、命名和配置约定，支持跨模板复用和 AI 生成。

## 组件代码命名规范

### 格式要求

组件代码必须使用 `{category}-{name}` 格式：

```
{category}-{name}[-{suffix}]
```

- **category**: 组件所属区域（header/content/footer）
- **name**: 组件名称（描述性词汇）
- **suffix**: 可选的后缀（用于区分同类型的不同组件）

### 命名规则

1. 全部使用小写字母
2. 使用破折号（-）连接单词
3. **不使用模板前缀**（模板区分通过 `style_code` 字段实现）
4. 名称应具有描述性，反映组件功能

### 示例

| 正确 | 错误 | 原因 |
|------|------|------|
| `header-nav` | `tpmst_header_nav` | 不要使用模板前缀 |
| `content-hero` | `contentHero` | 不要使用驼峰命名 |
| `footer-links` | `footer_links` | 不要使用下划线 |
| `content-features-grid` | `ContentFeaturesGrid` | 不要使用大写 |

## 组件分类

### 按区域分类

| 区域 | category | 说明 |
|------|----------|------|
| 头部 | `header` | 导航栏、Banner、顶部通知等 |
| 内容 | `content` | Hero、特性展示、产品列表等 |
| 底部 | `footer` | 页脚链接、版权、联系方式等 |

### 按类型分类

| 类型 | 说明 |
|------|------|
| `section` | 页面区块组件（默认） |
| `widget` | 小型功能组件 |
| `system` | 系统组件（header.phtml/footer.phtml） |

## 组件文件结构

### 文件位置

```
components/{category}/{name}.phtml
```

示例：
- `components/header/nav.phtml` -> `header-nav`
- `components/content/hero.phtml` -> `content-hero`
- `components/footer/links.phtml` -> `footer-links`

### 文件模板

```php
<?php
/**
 * {组件名称}
 * 
 * @var \Weline\Framework\View\Template $this
 * 
 * @fields_start
 * 
 * group:{group_name} => {分组显示名}:{分组说明}
 * {field_path} => {字段标签}:{字段类型}:{默认值}|{选项}:{说明}
 * 
 * @fields_end
 */

// ========================================
// 数据获取
// ========================================
$page = $this->getData('page');
$config = $this->getData('component_config') ?: [];
$styleSettings = $this->getData('style_settings') ?: [];

// ========================================
// 辅助函数（使用组件前缀避免冲突）
// ========================================
$componentPrefix = 'hero';  // 根据组件名修改

if (!function_exists("{$componentPrefix}_getConfig")) {
    $getConfig = function($config, $key, $default = '') {
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value !== '' ? $value : $default;
    };
}

// ========================================
// 配置解析
// ========================================
$title = $getConfig($config, 'content.title', '默认标题');
$description = $getConfig($config, 'content.description', '默认描述');

?>

<!-- ========================================
     HTML 输出
     ======================================== -->
<section class="component-hero">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($description) ?></p>
</section>
```

## 配置字段定义

### @fields 语法

组件配置字段使用特殊注释语法定义，位于文件头部：

```php
/**
 * @fields_start
 * 
 * group:content => 内容设置:配置组件显示的内容
 * content.title => 标题:text:Welcome to Our Site
 * content.subtitle => 副标题:text:Your journey starts here
 * content.cta_text => 按钮文字:text:Get Started
 * content.cta_url => 按钮链接:text:#
 * 
 * group:style => 样式设置:自定义组件外观
 * style.background_color => 背景颜色:color:#1a1a2e
 * style.text_color => 文字颜色:color:#ffffff
 * style.text_align => 文字对齐:select:center|left,center,right
 * 
 * group:advanced => 高级设置
 * advanced.extra_classes => 额外CSS类:text:
 * 
 * @fields_end
 */
```

### 字段类型

| 类型 | 说明 | 语法 | 示例 |
|------|------|------|------|
| `text` | 单行文本 | `字段名:text:默认值` | `title => 标题:text:Hello` |
| `textarea` | 多行文本 | `字段名:textarea:默认值\|说明` | `desc => 描述:textarea:\|支持HTML` |
| `number` | 数字 | `字段名:number:默认值\|单位` | `width => 宽度:number:100\|px` |
| `color` | 颜色 | `字段名:color:默认值` | `bg => 背景色:color:#ffffff` |
| `select` | 单选 | `字段名:select:默认值\|选项1,选项2` | `align => 对齐:select:center\|left,center,right` |
| `image` | 图片 | `字段名:image:默认值` | `logo => Logo:image:` |
| `json` | JSON | `字段名:json:默认值` | `items => 项目:json:[]` |
| `responsive` | 响应式 | `字段名:responsive:移动端/PC端\|单位[断点]` | `padding => 内边距:responsive:10/20\|px[MD]` |

### 响应式值格式

```
{mobile_value}/{desktop_value}|{unit}[{breakpoint}]
```

示例：
- `10/20|px` - 移动端 10px，PC 端 20px
- `100/150|%[MD]` - 在 MD 断点切换
- `12/16|rem[LG]` - 在 LG 断点切换

## 组件配置 Schema

### component.json 中的定义

```json
{
  "components": {
    "content-hero": {
      "name": "Hero 横幅",
      "name_en": "Hero Banner",
      "description": "全屏 Hero 区域，包含标题、描述和 CTA 按钮",
      "region": "content",
      "category": "content",
      "type": "section",
      "icon": "bi-image",
      "file": "content/hero.phtml",
      "sort_order": 10,
      "is_default": true,
      "compatible_styles": ["*"],
      "ai_generated": false,
      "config_schema": {
        "content": {
          "label": "内容设置",
          "fields": {
            "title": {
              "type": "text",
              "label": "标题",
              "default": "Welcome"
            },
            "subtitle": {
              "type": "textarea",
              "label": "副标题",
              "default": ""
            }
          }
        },
        "style": {
          "label": "样式设置",
          "fields": {
            "background_color": {
              "type": "color",
              "label": "背景颜色",
              "default": "#1a1a2e"
            }
          }
        }
      },
      "default_config": {
        "content": {
          "title": "Welcome to Our Site",
          "subtitle": "Start your journey"
        },
        "style": {
          "background_color": "#1a1a2e"
        }
      }
    }
  }
}
```

## 跨模板组件

### compatible_styles 字段

组件可以通过 `compatible_styles` 字段声明兼容性：

```json
{
  "compatible_styles": ["*"]              // 兼容所有模板
  "compatible_styles": ["tpmst"]          // 仅兼容 tpmst
  "compatible_styles": ["tpmst", "sattaking"]  // 兼容多个模板
}
```

### 组件查找优先级

ComponentResolver 按以下优先级查找组件：

1. **优先模板**：如果指定了 `preferredStyleCode`，先在该模板中查找
2. **当前模板**：在当前使用的模板中查找
3. **共享组件**：在 `_shared` 模板中查找
4. **兼容组件**：查找其他模板中声明兼容的组件

### 使用其他模板的组件

在布局配置中，可以通过 `style_code` 字段指定使用其他模板的组件：

```json
{
  "layout_config": {
    "content": [
      {
        "code": "content-hero",
        "style_code": "tpmst",  // 使用 tpmst 模板的 hero 组件
        "enabled": true,
        "config": {}
      }
    ]
  }
}
```

## AI 生成组件

### 命名规范

AI 生成的组件遵循相同的命名规范，代码格式为：

```
{category}-{descriptive_name}
```

如果名称包含中文，自动转换为时间戳格式：

```
{category}-ai-{yymmddHHMM}
```

### 标识方式

AI 生成的组件在 component.json 中会标记：

```json
{
  "content-ai-2601281430": {
    "name": "AI生成-产品展示",
    "ai_generated": true,
    "created_at": "2026-01-28 14:30:00",
    ...
  }
}
```

## 数据库存储

### Component 表结构

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | VARCHAR | 组件代码（header-nav） |
| `style_code` | VARCHAR | 所属模板代码 |
| `name` | VARCHAR | 组件名称 |
| `description` | TEXT | 组件描述 |
| `category` | VARCHAR | 组件分类 |
| `type` | VARCHAR | 组件类型 |
| `path` | VARCHAR | 组件文件路径 |
| `config_schema` | TEXT | 配置 Schema（JSON） |
| `default_config` | TEXT | 默认配置（JSON） |
| `compatible_styles` | TEXT | 兼容模板列表（JSON） |
| `is_active` | INT | 是否激活 |
| `sort_order` | INT | 排序顺序 |

### 唯一约束

组件通过 `code` + `style_code` 组合作为唯一键：

- 同一模板内，组件代码必须唯一
- 不同模板可以有相同代码的组件（各自独立）

## 验证工具

### ComponentValidator

```php
use GuoLaiRen\PageBuilder\Service\ComponentValidator;

$validator = ComponentValidator::getInstance();

// 验证模板的所有组件
$result = $validator->validateTemplate('tpmst');
print_r($result);
// ['valid' => true/false, 'errors' => [...], 'warnings' => [...]]

// 验证布局配置中的组件引用
$result = $validator->validateLayoutConfig($layoutConfig, 'tpmst');

// 生成验证报告
echo $validator->generateReport('tpmst');
```

### 验证规则

1. **代码格式**：必须是 `{category}-{name}` 格式
2. **必需字段**：name, file, region, category
3. **文件存在**：组件文件必须存在
4. **区域一致**：region 和 category 应该一致（除非是 widget）
5. **config_schema 格式**：如果存在，必须是有效的 JSON 格式

## 组件预览规范（必须）

### 预览要求

**每个组件必须能够正常显示预览**，这是组件开发的硬性要求。

### 预览显示优先级

组件面板按以下优先级显示组件预览：

1. **预览 HTML**：通过 `renderPreview` 渲染的组件内容
2. **缩略图**：component.json 中配置的 `thumbnail` 字段
3. **组件图标**：component.json 中配置的 `icon` 字段
4. **区域默认图标**：根据组件所属区域显示默认图标

### 确保预览正常的关键要求

#### 1. 必须提供默认值

组件中的**所有配置项**都必须有合理的默认值，确保在没有自定义配置时也能正常渲染：

```php
// ✓ 正确：提供默认值
$title = $config['content.title'] ?? 'Default Title';
$items = $config['features.items'] ?? "Feature 1|Description\nFeature 2|Description";

// ✗ 错误：没有默认值，预览时可能为空
$title = $config['content.title'];
```

#### 2. 处理外部数据依赖

如果组件依赖外部数据（如博客文章、游戏列表），必须在**预览模式下使用示例数据**：

```php
// 获取博客文章数据
$blogPosts = $this->getData('blog_posts') ?: [];
$isPreview = (bool)$this->getData('is_preview');

// 如果是预览模式且没有数据，使用示例数据
if ($isPreview && empty($blogPosts)) {
    $blogPosts = [
        [
            'id' => 1,
            'title' => 'Sample Blog Post',
            'summary' => 'This is a sample blog post for preview.',
            'cover_image' => 'https://placehold.co/800x450/6c5ce7/ffffff?text=Blog+Post',
            'category_name' => 'Sample Category',
            'published_at' => date('Y-m-d H:i:s'),
            'url' => '#',
        ],
        // ... 更多示例数据
    ];
}
```

#### 3. 避免条件性隐藏

不要在预览模式下因为条件判断而完全隐藏组件内容：

```php
// ✗ 错误：预览时可能完全不显示
<?php if (!empty($items)): ?>
    <div class="items">...</div>
<?php endif; ?>

// ✓ 正确：即使为空也显示占位内容
<?php if (!empty($items)): ?>
    <div class="items">...</div>
<?php else: ?>
    <div class="items-placeholder">
        <p>暂无内容</p>
    </div>
<?php endif; ?>
```

### 配置 icon 字段

在 component.json 中为每个组件配置 `icon` 字段作为预览的后备显示：

```json
{
  "header-nav": {
    "name": "导航栏",
    "icon": "bi-menu-button-wide",
    ...
  },
  "content-hero": {
    "name": "Hero 横幅",
    "icon": "bi-badge-hd",
    ...
  },
  "footer-links": {
    "name": "页脚链接",
    "icon": "bi-layout-text-window-reverse",
    ...
  }
}
```

支持的图标格式：
- Bootstrap Icons: `bi-{icon-name}`
- Material Design Icons: `mdi-{icon-name}`

### 系统自动提供的示例数据

`ComponentService` 在预览模式下会自动为以下类型的组件提供示例数据：

| 组件类型 | 自动提供的变量 |
|---------|---------------|
| 博客相关 | `blog_posts`, `blog_categories`, `recent_posts` |
| 游戏相关 | `games` |
| 评价相关 | `testimonials` |
| FAQ | `faq_items` |
| 团队成员 | `team_members` |
| 特性/功能 | `features` |
| 合作伙伴 | `partners` |
| 价格表 | `pricing_plans` |
| 统计数据 | `statistics` |

### 预览验证检查清单

开发组件时，请确保：

- [ ] 所有配置项都有默认值
- [ ] 依赖外部数据的组件在预览模式下能正常显示
- [ ] 配置了 `icon` 字段作为后备显示
- [ ] 组件在没有任何自定义配置时能完整渲染
- [ ] 空状态有合理的占位显示

## 最佳实践

### 1. 组件设计原则

- **单一职责**：每个组件只负责一个功能
- **可配置性**：提供合理的配置项，使组件具有灵活性
- **默认值**：为所有配置提供合理的默认值
- **响应式**：确保组件在不同设备上表现良好
- **可预览性**：确保组件在预览模式下能完整显示

### 2. 代码规范

```php
// 1. 使用组件前缀避免函数名冲突
if (!function_exists('hero_getConfig')) {
    function hero_getConfig($config, $key, $default = '') {
        // ...
    }
}

// 2. 始终转义输出
<?= htmlspecialchars($title) ?>

// 3. 使用条件判断避免空值
<?php if (!empty($subtitle)): ?>
    <p><?= htmlspecialchars($subtitle) ?></p>
<?php endif; ?>

// 4. 支持额外 CSS 类
$extraClasses = $getConfig($config, 'advanced.extra_classes', '');
?>
<section class="hero <?= htmlspecialchars($extraClasses) ?>">
```

### 3. 样式隔离

使用 BEM 或组件前缀确保样式不冲突：

```html
<!-- BEM 命名 -->
<div class="hero">
    <h1 class="hero__title">...</h1>
    <p class="hero__subtitle">...</p>
    <a class="hero__cta hero__cta--primary">...</a>
</div>

<!-- 或使用组件前缀 -->
<div class="tpmst-hero">
    <h1 class="tpmst-hero-title">...</h1>
</div>
```

### 4. 文档注释

为每个组件提供完整的文档注释：

```php
/**
 * Hero 横幅组件
 * 
 * 全屏展示区域，包含：
 * - 主标题和副标题
 * - CTA 按钮（支持自定义链接）
 * - 背景图片或颜色
 * 
 * 适用场景：
 * - 首页顶部展示
 * - 落地页主视觉
 * 
 * @var \Weline\Framework\View\Template $this
 * @since 1.0.0
 * @author GuoLaiRen
 */
```

## 变更日志

- **v1.0.0** (2026-01-28): 初始版本
  - 定义组件命名规范
  - 定义配置字段语法
  - 定义跨模板组件机制
  - 创建验证工具
