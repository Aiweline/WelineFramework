# 📄 PageBuilder Header 配置说明

## 🎯 功能概述

新的 Header 模板已经重新设计，现在支持：

1. ✅ **Logo 配置** - 简洁的 Logo 展示，支持图片或文字
2. ✅ **位置控制** - Logo 可以左对齐、居中或右对齐
3. ✅ **SEO 信息** - 可选择性显示页面的 SEO 信息
4. ✅ **跟踪代码** - 支持 Google Analytics、GTM、Facebook Pixel 等跟踪代码
5. ✅ **响应式设计** - 自动适配移动端

---

## 📋 配置项说明

### 1. Logo 配置 (group:logo)

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `logo.image_url` | Logo 图片地址 | 文本 | 空 |
| `logo.image_width` | Logo 宽度 | 数字 | 120px |
| `logo.image_height` | Logo 高度 | 数字 | 40px |
| `logo.position` | Logo 位置 | 选择 | left |
| `logo.link_url` | Logo 链接地址 | 文本 | / |

**使用说明**：
- 如果设置了 `logo.image_url`，将显示图片 Logo
- 如果未设置图片地址，将显示页面标题作为文字 Logo
- Logo 位置可选：`left`（左对齐）、`center`（居中）、`right`（右对齐）

**示例配置**：
```json
{
  "logo.image_url": "https://example.com/logo.png",
  "logo.image_width": "150",
  "logo.image_height": "50",
  "logo.position": "left",
  "logo.link_url": "/"
}
```

---

### 2. 样式配置 (group:style)

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `style.background_color` | 背景颜色 | 颜色 | #ffffff |
| `style.border_bottom` | 底部边框 | 选择 | yes |
| `style.border_color` | 边框颜色 | 颜色 | #eeeeee |

**使用说明**：
- 设置 Header 的背景颜色
- 可以选择是否显示底部边框
- 自定义边框颜色

---

### 3. 尺寸配置 (group:size)

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `size.height` | 头部高度 | 数字 | 80px |
| `size.max_width` | 内容最大宽度 | 数字 | 1200px |
| `size.padding_horizontal` | 左右内边距 | 数字 | 40px |

**使用说明**：
- 控制 Header 的高度
- 设置内容区域的最大宽度
- 调整左右两侧的内边距

**响应式调整**：
- 移动端（≤768px）时，Header 高度自动缩小为 60px
- 移动端左右内边距自动调整为 20px
- Logo 尺寸在移动端缩小为 80%

---

### 4. SEO 信息显示 (group:seo)

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `seo.show_title` | 显示页面标题 | 选择 | no |
| `seo.title_tag` | 标题标签 | 选择 | h1 |
| `seo.show_description` | 显示页面描述 | 选择 | no |
| `seo.show_keywords` | 显示关键词 | 选择 | no |

**使用说明**：
- **默认不显示** SEO 信息（对用户隐藏）
- 如果启用，SEO 信息会显示在 Header 下方
- 标题标签可选：`h1`、`h2`、`h3`、`div`
- 关键词会以"关键词: xxx"的形式显示

**适用场景**：
- **一般情况**：不显示（`show_title: no`），SEO 信息仅在页面源代码中
- **特殊需求**：如需在页面上展示标题、描述等信息时启用

---

### 5. 跟踪代码配置 (group:tracking)

| 配置项 | 说明 | 类型 | 默认值 |
|--------|------|------|--------|
| `tracking.head_code` | Head 跟踪代码 | 文本域 | 空 |
| `tracking.body_code` | Body 跟踪代码 | 文本域 | 空 |

**使用说明**：
- `head_code` 用于放置需要在 `<head>` 中执行的代码（如 Google Analytics、GTM）
- `body_code` 用于放置需要在 `<body>` 开始处执行的代码（如 Facebook Pixel）
- 跟踪代码**仅在非预览模式下**执行
- 预览模式下会在控制台输出跟踪代码信息（仅显示前100字符）

**示例 - Google Analytics 4**：
```html
<!-- head_code -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXXXXX');
</script>
```

**示例 - Google Tag Manager**：
```html
<!-- head_code -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXXXXX');</script>

<!-- body_code -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
```

**示例 - Facebook Pixel**：
```html
<!-- body_code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=YOUR_PIXEL_ID&ev=PageView&noscript=1"
/></noscript>
```

---

## 🎨 视觉效果

### 默认样式（Logo 左对齐）
```
┌─────────────────────────────────────────────┐
│  [Logo]                                     │
└─────────────────────────────────────────────┘
```

### Logo 居中
```
┌─────────────────────────────────────────────┐
│                  [Logo]                     │
└─────────────────────────────────────────────┘
```

### Logo 右对齐
```
┌─────────────────────────────────────────────┐
│                                     [Logo]  │
└─────────────────────────────────────────────┘
```

### 带 SEO 信息显示
```
┌─────────────────────────────────────────────┐
│  [Logo]                                     │
└─────────────────────────────────────────────┘
  我的页面标题
  这是页面的描述信息
  关键词: 关键词1, 关键词2, 关键词3
```

---

## 🚀 使用步骤

### 1. 在可视化配置中设置

1. 编辑页面
2. 点击"可视化配置"按钮
3. 找到以下配置分组：
   - 📷 **Logo配置** - 设置 Logo 图片、尺寸和位置
   - 🎨 **样式** - 设置背景色和边框
   - 📐 **尺寸** - 调整高度和宽度
   - 🔍 **SEO信息显示** - 是否显示 SEO 信息
   - 📊 **跟踪代码** - 添加 GA、GTM、FB Pixel 等

4. 修改配置后会自动保存
5. 右侧预览会实时刷新

---

## 📝 完整配置示例

```json
{
  "logo.image_url": "https://example.com/logo.png",
  "logo.image_width": "150",
  "logo.image_height": "50",
  "logo.position": "left",
  "logo.link_url": "/",
  
  "style.background_color": "#ffffff",
  "style.border_bottom": "yes",
  "style.border_color": "#e0e0e0",
  
  "size.height": "80",
  "size.max_width": "1200",
  "size.padding_horizontal": "40",
  
  "seo.show_title": "no",
  "seo.title_tag": "h1",
  "seo.show_description": "no",
  "seo.show_keywords": "no",
  
  "tracking.head_code": "<!-- Google Analytics code -->",
  "tracking.body_code": "<!-- Facebook Pixel code -->"
}
```

---

## ⚠️ 注意事项

### 跟踪代码
1. **预览模式不执行**：在预览时跟踪代码不会执行，避免污染统计数据
2. **调试方法**：预览时可以打开浏览器控制台查看跟踪代码信息
3. **代码格式**：跟踪代码必须是完整的 HTML 代码（包括 `<script>` 标签）
4. **性能考虑**：建议使用异步加载方式（`async` 或 `defer`）

### SEO 信息
1. **页面源代码中的 SEO**：页面的 meta 标签（title、description、keywords）会自动在 HTML 的 `<head>` 中生成
2. **Header 中的 SEO 显示**：这里的配置是控制是否在页面上**可见地显示**这些信息
3. **一般建议**：保持 SEO 信息显示为"no"，除非有特殊需求

### Logo 配置
1. **图片格式**：支持 PNG、JPG、SVG、WebP 等
2. **推荐尺寸**：宽度 120-200px，高度 40-60px
3. **无图片时**：会显示页面标题作为文字 Logo
4. **链接地址**：默认链接到首页 (`/`)，可以修改为任意 URL

---

## 🔧 技术细节

### 文件位置
```
app/code/GuoLaiRen/PageBuilder/view/templates/style/default/header.phtml
```

### 数据来源
- 配置数据：`$styleSettings` (来自数据库 `style_settings` 字段)
- 页面数据：`$page` (Page 模型)
- 预览标志：`$isPreview` (判断是否为预览模式)

### 响应式断点
- 桌面：> 768px
- 移动：≤ 768px

---

## 📚 相关文档

- [可视化配置功能使用指南](./可视化配置功能使用指南.md)
- [WelineFramework模型开发最佳实践](./WelineFramework模型开发最佳实践.md)

---

## 🎉 更新日志

### v2.0.0 (2024-01-16)
- ✅ 重新设计 Header，移除了不必要的导航栏
- ✅ 简化为单一 Logo 展示
- ✅ 增加 Logo 位置控制（左/中/右）
- ✅ 增加 SEO 信息可选显示
- ✅ 增加跟踪代码配置（Head/Body）
- ✅ 优化响应式设计
- ✅ 增加预览模式检测，避免跟踪代码污染数据

---

**祝您使用愉快！** 🎊

