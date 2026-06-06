# 📊 PageBuilder 页面配置与跟踪代码

## 🎯 配置架构说明

### 配置分层

PageBuilder 采用了两层配置架构：

1. **页面级配置** - 存储在 `Page` 模型中
   - SEO 信息（标题、描述、关键词）
   - 跟踪代码 ID（GA4、GTM、Facebook Pixel）
   - 页面基础信息

2. **样式级配置** - 存储在 `style_settings` 字段中
   - Logo 配置
   - 样式配置
   - 尺寸配置
   - 排版配置

---

## 📄 页面配置字段

### SEO 字段

| 字段名 | 类型 | 说明 |
|--------|------|------|
| `title` | varchar | 页面标题 |
| `description` | text | 页面描述 |
| `keywords` | varchar | 页面关键词 |

### 跟踪代码字段

| 字段名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `ga4_id` | varchar | Google Analytics 4 ID | G-XXXXXXXXXX |
| `gtm_id` | varchar | Google Tag Manager ID | GTM-XXXXXXX |
| `fb_pixel_id` | varchar | Facebook Pixel ID | 123456789012345 |

---

## 🔧 跟踪代码自动集成

### Google Analytics 4 (GA4)

当设置了 `ga4_id` 字段后，Header 会自动在 `<head>` 中插入：

```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-XXXXXXXXXX');
</script>
```

### Google Tag Manager (GTM)

当设置了 `gtm_id` 字段后，会自动插入：

**Head 部分：**
```html
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXXXXX');</script>
```

**Body 部分（noscript）：**
```html
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
```

### Facebook Pixel

当设置了 `fb_pixel_id` 字段后，会自动在 Body 开始处插入：

```html
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '123456789012345');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=123456789012345&ev=PageView&noscript=1"
/></noscript>
```

---

## 🔒 预览模式保护

### 自动禁用跟踪

在预览模式下（`$isPreview = true`），所有跟踪代码将**不会执行**，避免污染统计数据。

### 调试信息

预览模式下，会在浏览器控制台输出跟踪代码配置信息：

```javascript
console.log('[预览模式] 跟踪代码已禁用');
console.log('Google Analytics 4 ID: G-XXXXXXXXXX');
console.log('Google Tag Manager ID: GTM-XXXXXXX');
console.log('Facebook Pixel ID: 123456789012345');
```

---

## 📝 配置示例

### 在数据库中配置

```sql
-- 更新页面的跟踪代码
UPDATE w_pagebuilder_page 
SET 
    ga4_id = 'G-XXXXXXXXXX',
    gtm_id = 'GTM-XXXXXXX',
    fb_pixel_id = '123456789012345'
WHERE id = 1;
```

### 在后台表单中配置

1. 编辑页面
2. 找到 "跟踪代码" 区域
3. 填写以下字段：
   - **Google Analytics 4 ID**: `G-XXXXXXXXXX`
   - **Google Tag Manager ID**: `GTM-XXXXXXX`
   - **Facebook Pixel ID**: `123456789012345`
4. 保存页面

---

## 🎨 Header 配置说明

### 配置分组

Header 样式配置现在仅包含以下分组：

#### 1. Logo 配置 (group:logo)

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| logo.image_url | text | 空 | Logo 图片地址 |
| logo.image_width | number | 120 | Logo 宽度（px） |
| logo.image_height | number | 40 | Logo 高度（px） |
| logo.position | select | left | Logo 位置（left/center/right） |
| logo.link_url | text | / | Logo 链接地址 |

#### 2. 样式配置 (group:style)

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| style.background_color | color | #ffffff | 背景颜色 |
| style.border_bottom | select | yes | 底部边框（yes/no） |
| style.border_color | color | #eeeeee | 边框颜色 |

#### 3. 尺寸配置 (group:size)

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| size.height | number | 80 | 头部高度（px） |
| size.max_width | number | 1200 | 内容最大宽度（px） |
| size.padding_horizontal | number | 40 | 左右内边距（px） |

---

## 📊 数据流程图

```
页面配置 (Page Model)
    ├─ SEO 信息
    │   ├─ title
    │   ├─ description
    │   └─ keywords
    │
    └─ 跟踪代码 ID
        ├─ ga4_id ──────→ Google Analytics 4 Script
        ├─ gtm_id ──────→ Google Tag Manager Script
        └─ fb_pixel_id ─→ Facebook Pixel Script

样式配置 (style_settings)
    ├─ Logo 配置
    ├─ 样式配置
    └─ 尺寸配置
```

---

## ⚡ 性能优化

### 异步加载

所有跟踪代码都采用异步加载方式：

- **GA4**: 使用 `async` 属性
- **GTM**: 使用动态插入脚本方式
- **Facebook Pixel**: 使用异步加载方式

### 延迟加载

跟踪代码在页面 DOM 加载完成后执行，不影响页面首屏渲染。

---

## 🔍 获取跟踪代码 ID

### Google Analytics 4

1. 登录 [Google Analytics](https://analytics.google.com/)
2. 选择您的属性
3. 进入 "管理" > "数据流"
4. 点击您的网站数据流
5. 查看 "衡量 ID"，格式为 `G-XXXXXXXXXX`

### Google Tag Manager

1. 登录 [Google Tag Manager](https://tagmanager.google.com/)
2. 选择您的容器
3. 查看容器 ID，格式为 `GTM-XXXXXXX`

### Facebook Pixel

1. 登录 [Facebook Events Manager](https://business.facebook.com/events_manager/)
2. 选择您的像素
3. 查看像素 ID，格式为 15 位数字

---

## ⚠️ 注意事项

### GDPR 合规

如果您的网站面向欧盟用户，请确保：

1. 在加载跟踪代码前获取用户同意
2. 提供隐私政策页面
3. 允许用户选择退出跟踪

### 隐私保护

- 预览模式下不执行跟踪代码
- 不记录敏感个人信息
- 遵守相关隐私法规

### 调试技巧

1. 使用浏览器控制台查看跟踪代码是否正确加载
2. 使用 Google Tag Assistant 验证 GTM 配置
3. 使用 Facebook Pixel Helper 验证 Pixel 配置

---

## 🔧 技术实现

### 文件位置

```
app/code/GuoLaiRen/PageBuilder/
├── Model/
│   └── Page.php                          # 页面模型（包含跟踪字段）
└── view/templates/style/default/
    └── header.phtml                      # Header 模板（集成跟踪代码）
```

### 关键代码

**读取跟踪代码 ID：**
```php
$ga4Id = $page ? $page->getData('ga4_id') : '';
$gtmId = $page ? $page->getData('gtm_id') : '';
$fbPixelId = $page ? $page->getData('fb_pixel_id') : '';
```

**条件渲染：**
```php
<?php if (!$isPreview && $ga4Id): ?>
    <!-- GA4 Script -->
<?php endif; ?>
```

---

## 📚 相关文档

- [可视化配置功能使用指南](./可视化配置功能使用指南.md)
- [WelineFramework模型开发最佳实践](../../../../../docs/WelineFramework模型开发最佳实践.md)

---

## 🎉 更新日志

### v2.1.0 (2024-01-16)
- ✅ 将 SEO 信息和跟踪代码配置从样式配置移至页面配置
- ✅ 简化 Header 配置，只保留 Logo、样式、尺寸配置
- ✅ 跟踪代码自动根据页面配置中的 ID 渲染
- ✅ 支持 Google Analytics 4、Google Tag Manager、Facebook Pixel
- ✅ 预览模式自动禁用跟踪代码
- ✅ 添加调试信息输出

---

**配置更加清晰合理！** 🎊

