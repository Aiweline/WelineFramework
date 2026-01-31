# 社交媒体部件配置指南

## 概述

社交媒体部件现已升级为使用 SVG 图标，并支持灵活的自定义配置。所有配置项通过部件文件头部的 `@param` 注释定义，Meta 模块会自动扫描并生成配置界面。

## 功能特性

### 1. Meta 配置系统
- ✅ 配置项定义在文件头部注释中
- ✅ 支持多种参数类型（string、int、bool、array等）
- ✅ 支持选项值（option）定义
- ✅ Meta 模块自动扫描生成配置界面

### 2. SVG 图标系统
- ✅ 使用矢量 SVG 图标，无需加载字体库
- ✅ 完美缩放，支持任意尺寸
- ✅ 更小的文件体积
- ✅ 支持 15+ 主流社交平台

### 3. 样式隔离
- ✅ 使用唯一 ID 作用域，避免外部样式影响
- ✅ 部件内部样式完全独立
- ✅ 支持自定义样式变量

### 4. 支持的平台

| 平台 | 标识 | 品牌色 |
|------|------|--------|
| Facebook | `facebook` | #1877f2 |
| Twitter/X | `twitter` | #000000 |
| Instagram | `instagram` | #e4405f |
| YouTube | `youtube` | #ff0000 |
| LinkedIn | `linkedin` | #0077b5 |
| Pinterest | `pinterest` | #bd081c |
| TikTok | `tiktok` | #000000 |
| 微博 | `weibo` | #e6162d |
| 微信 | `wechat` | #07c160 |
| GitHub | `github` | #181717 |
| Telegram | `telegram` | #0088cc |
| WhatsApp | `whatsapp` | #25d366 |
| Discord | `discord` | #5865f2 |
| Reddit | `reddit` | #ff4500 |
| Snapchat | `snapchat` | #fffc00 |

## 配置项说明

所有配置项通过文件头部 `@param` 注释定义，Meta 模块会自动扫描并生成配置界面。

### 页脚社交图标配置项

| 配置项 | 类型 | 默认值 | 可选值 | 说明 |
|--------|------|--------|--------|------|
| `title` | string | "" | - | 部件标题 |
| `alignment` | string | "left" | left, center, right | 对齐方式 |
| `icon_size` | string | "medium" | small, medium, large | 图标尺寸 |
| `icon_style` | string | "colored" | colored, mono, outline | 图标样式 |
| `gap` | string | "10px" | - | 图标间距（CSS单位） |
| `custom_links` | string | "" | - | JSON格式自定义链接 |
| `facebook` | string | "" | - | Facebook链接 |
| `twitter` | string | "" | - | Twitter/X链接 |
| `instagram` | string | "" | - | Instagram链接 |
| ... | ... | ... | ... | 其他平台链接 |

### 侧栏社交链接配置项

| 配置项 | 类型 | 默认值 | 可选值 | 说明 |
|--------|------|--------|--------|------|
| `title` | string | "关注我们" | - | 部件标题 |
| `icon_style` | string | "colored" | colored, mono, outline | 图标样式 |
| `gap` | string | "10px" | - | 图标间距（CSS单位） |
| `wechat_qr` | string | "" | - | 微信二维码图片URL |
| `custom_links` | string | "" | - | JSON格式自定义链接 |
| ... | ... | ... | ... | 各平台链接 |

## 配置方式

### 方式一：传统字段配置

适用于固定的社交媒体链接配置：

```php
// 在部件数据中设置
$widgetData = [
    'title' => '关注我们',
    'alignment' => 'center',    // 对齐方式
    'icon_size' => 'large',     // 图标尺寸
    'icon_style' => 'colored',  // 图标样式
    'gap' => '15px',            // 图标间距
    'facebook' => 'https://facebook.com/yourpage',
    'twitter' => 'https://twitter.com/youraccount',
    'instagram' => 'https://instagram.com/youraccount',
    'youtube' => 'https://youtube.com/yourchannel',
];
```

### 方式二：自定义 JSON 配置（推荐）

适用于动态配置或需要灵活添加/删除平台：

```php
$customLinks = [
    [
        'platform' => 'facebook',
        'url' => 'https://facebook.com/yourpage'
    ],
    [
        'platform' => 'twitter',
        'url' => 'https://twitter.com/youraccount'
    ],
    [
        'platform' => 'instagram',
        'url' => 'https://instagram.com/youraccount'
    ],
    [
        'platform' => 'github',
        'url' => 'https://github.com/yourusername'
    ],
    // 可以添加更多平台...
];

$widgetData = [
    'title' => '关注我们',
    'alignment' => 'center',
    'icon_size' => 'large',
    'icon_style' => 'colored',
    'gap' => '15px',
    'custom_links' => json_encode($customLinks),
];
```

## 配置选项详解

### 对齐方式 (`alignment`)

仅页脚部件支持，用于控制图标的水平对齐方式：

- `left`: 左对齐
- `center`: 居中对齐
- `right`: 右对齐

### 图标尺寸 (`icon_size`)

- `small`: 32px × 32px（图标16px）
- `medium`: 40px × 40px（图标20px）- 默认
- `large`: 48px × 48px（图标24px）

### 图标间距 (`gap`)

支持任意 CSS 单位：
- `10px` - 默认值
- `15px` - 较大间距
- `0.5rem` - 使用相对单位
- `1em` - 基于字体大小

### 图标样式 (`icon_style`)

#### 1. `colored` - 彩色样式（默认）
- 使用各平台的品牌色
- 悬停时有阴影和上移效果

#### 2. `mono` - 单色样式
- 灰色背景
- 悬停时变为品牌色

#### 3. `outline` - 线框样式
- 透明背景，带边框
- 悬停时边框和图标变为品牌色

## 使用示例

### 页脚社交图标

```php
<?= $this->fetch('Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml', [
    'title' => '关注我们',
    'custom_links' => json_encode([
        ['platform' => 'facebook', 'url' => 'https://facebook.com/yourpage'],
        ['platform' => 'twitter', 'url' => 'https://twitter.com/youraccount'],
        ['platform' => 'instagram', 'url' => 'https://instagram.com/youraccount'],
        ['platform' => 'wechat', 'url' => 'https://weixin.qq.com/youraccount'],
    ]),
    'icon_size' => 'large',
    'icon_style' => 'colored',
]) ?>
```

### 侧栏社交链接（带微信二维码）

```php
<?= $this->fetch('Weline_Theme::theme/frontend/widgets/sidebar/sidebar-social/default.phtml', [
    'title' => '关注我们',
    'facebook' => 'https://facebook.com/yourpage',
    'twitter' => 'https://twitter.com/youraccount',
    'instagram' => 'https://instagram.com/youraccount',
    'wechat_qr' => '/path/to/wechat-qr-code.png', // 微信二维码图片
    'icon_style' => 'mono',
]) ?>
```

## Meta 配置定义

部件配置项通过文件头部的 `@param` 注释定义。示例：

```php
/**
 * @param alignment {default="left",name="对齐方式",description="图标对齐方式",type=string,option={left:左对齐,center:居中,right:右对齐}}
 * @param icon_size {default="medium",name="图标尺寸",description="图标大小",type=string,option={small:小号,medium:中号,large:大号}}
 * @param icon_style {default="colored",name="图标样式",description="图标显示样式",type=string,option={colored:彩色,mono:单色,outline:线框}}
 * @param gap {default="10px",name="图标间距",description="图标之间的间距",type=string}
 * @param facebook {default="",name="Facebook链接",description="Facebook主页链接",type=string}
 */
```

### Meta 格式说明

- **`default`**: 默认值
- **`name`**: 配置项显示名称
- **`description`**: 配置项说明
- **`type`**: 数据类型（string、int、bool、array等）
- **`option`**: 可选值（格式：`{value1:label1,value2:label2}`）

Meta 模块会自动扫描这些注释，并在主题编辑器中生成配置界面。

## 样式隔离机制

部件使用唯一 ID 作为 CSS 作用域，确保样式不受外部影响：

```php
$widgetId = 'footer-social-' . uniqid();
```

```html
<div class="widget-footer-social" id="<?= $widgetId ?>">
    <!-- 部件内容 -->
</div>
```

```css
/* 所有样式使用 ID 选择器 */
#<?= $widgetId ?> .social-links {
    display: flex;
    gap: var(--social-gap, 10px);
}

#<?= $widgetId ?>[data-style="colored"] .social-link {
    background: var(--brand-color);
}
```

这样可以确保：
- ✅ 外部样式不会影响部件
- ✅ 部件样式不会污染全局
- ✅ 多个相同部件互不干扰

## 开发者自定义

### 添加新平台图标

编辑 `Weline\Theme\Helper\SocialIconHelper::getAvailableIcons()` 方法：

```php
'yourplatform' => [
    'name' => 'Your Platform',
    'color' => '#ff0000',
    'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="..."/></svg>'
],
```

### 自定义样式

可以在部件模板中添加自定义 CSS 类：

```php
<div class="widget-footer-social size-large style-colored my-custom-class">
    <!-- ... -->
</div>
```

## 最佳实践

1. **优先使用 JSON 配置**：更灵活，便于动态管理
2. **合理选择图标数量**：建议 3-6 个主要平台，避免过多
3. **统一样式风格**：在同一页面使用相同的 `icon_style`
4. **适配移动端**：建议使用 `medium` 或 `large` 尺寸
5. **定期更新链接**：确保社交媒体链接有效

## 常见问题

### Q: 如何隐藏某个平台？
A: 不配置该平台的 URL 即可，或从 `custom_links` JSON 中移除。

### Q: 可以自定义图标颜色吗？
A: 可以，在 `custom_links` 中添加 `color` 字段会覆盖默认品牌色。

### Q: 支持渐变色图标吗？
A: SVG 支持渐变，但需要修改 SVG 代码中的 `fill` 属性为渐变定义。

### Q: 如何添加新平台？
A: 在 `SocialIconHelper` 中添加新平台的 SVG 图标和配置即可。

## 技术支持

如有问题，请查看：
- SVG 图标助手类：`Weline\Theme\Helper\SocialIconHelper`
- 页脚部件模板：`view/theme/frontend/widgets/social/footer-social/default.phtml`
- 侧栏部件模板：`view/theme/frontend/widgets/sidebar/sidebar-social/default.phtml`
