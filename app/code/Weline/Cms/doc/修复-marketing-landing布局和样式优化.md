# Marketing Landing 布局和样式优化

## 修复日期
2025-10-19

## 问题描述
1. 副标题行高不符合设计图要求
2. 全屏模式下表单区域布局错误，宽度过宽
3. 右侧占位图片需要替换为实际书籍封面图片

## 修复方案

### 1. 副标题行高调整

**文件**: `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/header.phtml`

**修改位置**: 第273行

```css
.marketing-subtitle {
    color: #ffffff;
    font-size: <?= $subtitleSize ?>;
    font-weight: <?= htmlspecialchars($subtitleWeight) ?>;
    line-height: 1.5; /* 从1.7调整为1.5，使行高更紧凑 */
    text-align: left;
    margin-bottom: <?= $subtitleMargin ?>;
}
```

**效果**:
- 段落之间间距更紧凑
- 视觉效果与原设计图保持一致

### 2. 全屏布局优化

**文件**: `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/content.phtml`

**修改位置**: 第220-247行

```css
/* 移动端 */
.marketing-form-wrapper {
    flex: 0 0 auto;
    width: 100%;
}

.marketing-image-wrapper {
    flex: 0 0 auto;
    width: 100%;
}

/* 平板端（768px+） */
@media (min-width: 768px) {
    .marketing-image-wrapper {
        width: <?= $imageWidth ?>;  /* 默认45% */
        max-width: 600px;           /* 限制最大宽度 */
    }
    .marketing-form-wrapper {
        flex: 1 1 auto;             /* 可伸缩 */
        max-width: 550px;           /* 限制最大宽度 */
        min-width: 400px;           /* 保证最小宽度 */
    }
}

/* 桌面端（1024px+） */
@media (min-width: 1024px) {
    .marketing-content-layout {
        justify-content: center;    /* 居中对齐 */
        gap: clamp(40px, calc(40px + 40 * ((100vw - 1024px) / 256)), 80px);
    }
}
```

**优化效果**:
- ✅ **移动端**: 表单和图片垂直排列，自适应宽度
- ✅ **平板端**: 表单最小400px，最大550px，图片最大600px
- ✅ **桌面端**: 内容居中对齐，间距响应式增大
- ✅ **超大屏**: 表单和图片不会无限拉伸，保持合理尺寸

### 3. Money Calendar 封面图片

**文件**: `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/content.phtml`

**修改位置**: 第160-168行

```php
// 图片配置 - Money Calendar 封面图片
$defaultImageUrl = 'https://lp.streetsensedaily.com/money-cal/images/money-cal-cover.webp';
$imageUrl = getContentConfig($styleSettings, 'image.url', $defaultImageUrl);
if (empty($imageUrl) || strpos($imageUrl, 'placeholder') !== false) {
    $imageUrl = $defaultImageUrl;
}
$imageAlt = getContentConfig($styleSettings, 'image.alt', 'The Secret Behind The Money Calendar\'s Accuracy - Money Calendar Cover');
```

**特性**:
- 默认使用 Money Calendar 封面图片（WebP格式）
- 图片URL: `https://lp.streetsensedaily.com/money-cal/images/money-cal-cover.webp`
- 自动检测并替换占位图
- 配置化支持：可在后台修改图片URL
- WebP格式提供更好的图片压缩和加载性能

## 响应式布局矩阵

| 屏幕尺寸 | 表单宽度 | 图片宽度 | 布局方式 | 对齐方式 |
|---------|---------|---------|---------|---------|
| < 768px | 100% | 100% | 垂直 | 拉伸 |
| 768px - 1023px | 400px - 550px | 45% (max 600px) | 水平 | 左对齐 |
| ≥ 1024px | 400px - 550px | 45% (max 600px) | 水平 | 居中 |

## 测试验证

### 测试页面
```bash
php bin/w http:request pagebuilder/backend/preview/full?page_id=2 -b
```

### 验证点
- ✅ 副标题行高为1.5
- ✅ 全屏（1920px）时表单宽度不超过550px
- ✅ 全屏时图片宽度不超过600px
- ✅ 表单和图片在大屏幕上居中显示
- ✅ 书籍封面图片正确显示
- ✅ 移动端表单和图片垂直堆叠

## 相关文件
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/header.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/content.phtml`

## CSS关键改进点

### Before（问题代码）
```css
.marketing-subtitle {
    line-height: 1.7; /* 行高过大 */
}

.marketing-form-wrapper {
    flex: 1;          /* 无限拉伸 */
    width: 100%;
}
```

### After（优化代码）
```css
.marketing-subtitle {
    line-height: 1.5; /* 紧凑行高 */
}

.marketing-form-wrapper {
    flex: 1 1 auto;   /* 可伸缩但有限制 */
    max-width: 550px; /* 最大宽度 */
    min-width: 400px; /* 最小宽度 */
}
```

## 注意事项
1. 缓存清理：修改后需清理编译缓存
   ```bash
   rm -f app/code/GuoLaiRen/PageBuilder/view/tpl/zh_Hans_CN/templates/style/marketing-landing/com_*.phtml
   ```

2. 图片URL：当前使用 Money Calendar 封面 (WebP格式)
   - URL: `https://lp.streetsensedaily.com/money-cal/images/money-cal-cover.webp`
   - 如需变更，更新`$defaultImageUrl`变量

3. 响应式断点：
   - 移动端: < 768px
   - 平板端: 768px - 1023px
   - 桌面端: ≥ 1024px

## 性能影响
- ✅ 无性能影响
- ✅ CSS纯静态，无额外计算
- ✅ 图片懒加载友好

## 浏览器兼容性
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ✅ 移动端浏览器全兼容

