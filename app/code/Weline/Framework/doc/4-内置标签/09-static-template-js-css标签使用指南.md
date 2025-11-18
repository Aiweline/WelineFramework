# static/template/js/css 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `static`、`template`、`js`、`css` 标签的使用方法。这些标签用于在模板中引入静态资源和其他模板文件。

## 什么是 static/template/js/css 标签

- **`static` 标签**：引入静态资源文件（图片、字体等）
- **`template` 标签**：包含其他模板文件
- **`js` 标签**：引入 JavaScript 文件
- **`css` 标签**：引入 CSS 样式文件

## 为什么需要这些标签

在模板中使用这些标签提供了以下优势：

- **资源管理**：统一管理静态资源路径
- **模块化**：支持模块化的资源引用
- **路径解析**：自动解析模块资源路径
- **版本控制**：支持资源版本控制

## static 标签

### 语法格式

```html
<static>Weline_Frontend::img/logo.png</static>
@static(Weline_Frontend::img/logo.png)
@static{Weline_Frontend::img/logo.png}
```

### 使用方法

#### 基本用法

```html
<!-- 图片 -->
<img src="@static(Weline_Frontend::img/logo.png)" alt="Logo">

<!-- 字体文件 -->
<link rel="stylesheet" href="@static(Weline_Frontend::fonts/custom.woff2)">
```

#### 在 HTML 属性中使用

```html
<img src="@static(Weline_Frontend::img/banner.jpg)" alt="Banner">
<div style="background-image: url('@static(Weline_Frontend::img/bg.jpg)')"></div>
```

### 完整示例

```html
<div class="header">
    <img src="@static(Weline_Frontend::img/logo.png)" alt="Logo" class="logo">
    <img src="@static(Weline_Frontend::img/banner.jpg)" alt="Banner" class="banner">
</div>

<div class="content" style="background-image: url('@static(Weline_Frontend::img/bg.jpg)')">
    <p>内容区域</p>
</div>
```

## template 标签

### 语法格式

```html
<template>Weline_Frontend::templates/header.phtml</template>
@template(Weline_Frontend::templates/header.phtml)
@template{Weline_Frontend::templates/header.phtml}
```

### 使用方法

#### 基本用法

```html
<!-- 包含头部模板 -->
<template>Weline_Frontend::templates/header.phtml</template>

<!-- 主要内容 -->
<div class="content">
    <h1>页面内容</h1>
</div>

<!-- 包含底部模板 -->
<template>Weline_Frontend::templates/footer.phtml</template>
```

#### 条件包含

```html
<if condition="$showSidebar">
    <template>Weline_Admin::templates/sidebar.phtml</template>
</if>
```

### 完整示例

```html
<!doctype html>
<html>
<head>
    <template>Weline_Frontend::templates/head.phtml</template>
</head>
<body>
    <template>Weline_Frontend::templates/header.phtml</template>
    
    <main>
        <h1>页面标题</h1>
        <p>页面内容</p>
    </main>
    
    <template>Weline_Frontend::templates/footer.phtml</template>
</body>
</html>
```

## js 标签

### 语法格式

```html
<js>Weline_Frontend::js/main.js</js>
@js(Weline_Frontend::js/main.js)
@js{Weline_Frontend::js/main.js}
```

### 使用方法

#### 基本用法

```html
<!-- 引入 JavaScript 文件 -->
<js>Weline_Frontend::js/main.js</js>
@js(Weline_Frontend::js/jquery.min.js)
```

#### 带属性的 script 标签

```html
<js defer>Weline_Frontend::js/main.js</js>
<js async>Weline_Frontend::js/analytics.js</js>
```

**编译结果**：
```html
<script defer src="/Weline/Frontend/view/statics/js/main.js"></script>
<script async src="/Weline/Frontend/view/statics/js/analytics.js"></script>
```

### 完整示例

```html
<!doctype html>
<html>
<head>
    <title>页面标题</title>
</head>
<body>
    <h1>页面内容</h1>
    
    <!-- 引入 JavaScript 文件 -->
    <js>Weline_Frontend::js/jquery.min.js</js>
    <js>Weline_Frontend::js/bootstrap.min.js</js>
    <js defer>Weline_Frontend::js/main.js</js>
</body>
</html>
```

## css 标签

### 语法格式

```html
<css>Weline_Frontend::css/main.css</css>
@css(Weline_Frontend::css/main.css)
@css{Weline_Frontend::css/main.css}
```

### 使用方法

#### 基本用法

```html
<!-- 引入 CSS 文件 -->
<css>Weline_Frontend::css/bootstrap.min.css</css>
@css(Weline_Frontend::css/main.css)
```

#### 带属性的 link 标签

```html
<css media="print">Weline_Frontend::css/print.css</css>
<css rel="preload">Weline_Frontend::css/critical.css</css>
```

**编译结果**：
```html
<link href="/Weline/Frontend/view/statics/css/print.css" rel="stylesheet" type="text/css" media="print"/>
<link href="/Weline/Frontend/view/statics/css/critical.css" rel="preload" type="text/css"/>
```

### 完整示例

```html
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>页面标题</title>
    
    <!-- 引入 CSS 文件 -->
    <css>Weline_Frontend::css/bootstrap.min.css</css>
    <css>Weline_Frontend::css/icons.min.css</css>
    <css>Weline_Frontend::css/app.min.css</css>
    <css media="print">Weline_Frontend::css/print.css</css>
</head>
<body>
    <h1>页面内容</h1>
</body>
</html>
```

## 资源路径格式

### 模块资源路径

所有资源路径使用 `模块名::路径` 格式：

```html
<!-- 静态资源 -->
@static(Weline_Frontend::img/logo.png)

<!-- 模板文件 -->
@template(Weline_Frontend::templates/header.phtml)

<!-- JavaScript 文件 -->
@js(Weline_Frontend::js/main.js)

<!-- CSS 文件 -->
@css(Weline_Frontend::css/main.css)
```

### 路径说明

- **模块名**：如 `Weline_Frontend`、`Weline_Admin`
- **路径**：相对于模块的 `view/statics/` 或 `view/templates/` 目录
- **分隔符**：使用 `::` 分隔模块名和路径

## 完整示例

### 示例 1：完整页面结构

```html
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>页面标题</title>
    
    <!-- CSS 文件 -->
    <css>Weline_Frontend::css/bootstrap.min.css</css>
    <css>Weline_Frontend::css/app.min.css</css>
</head>
<body>
    <!-- 头部 -->
    <template>Weline_Frontend::templates/header.phtml</template>
    
    <!-- 主要内容 -->
    <main>
        <h1>页面标题</h1>
        <img src="@static(Weline_Frontend::img/banner.jpg)" alt="Banner">
        <p>页面内容</p>
    </main>
    
    <!-- 底部 -->
    <template>Weline_Frontend::templates/footer.phtml</template>
    
    <!-- JavaScript 文件 -->
    <js>Weline_Frontend::js/jquery.min.js</js>
    <js>Weline_Frontend::js/app.min.js</js>
</body>
</html>
```

### 示例 2：条件加载资源

```html
<if condition="$isAdmin">
    <css>Weline_Admin::css/admin.css</css>
    <js>Weline_Admin::js/admin.js</js>
</if>
```

## 注意事项

### 1. 路径格式

- 必须使用 `模块名::路径` 格式
- 路径相对于模块的 `view/statics/` 或 `view/templates/` 目录
- 确保资源文件存在

### 2. 资源加载顺序

- CSS 文件通常放在 `<head>` 中
- JavaScript 文件通常放在 `</body>` 前
- 注意资源依赖关系

### 3. 性能优化

- 合并 CSS 和 JavaScript 文件
- 使用压缩版本（.min.js、.min.css）
- 考虑使用 CDN

### 4. 模块资源

- 资源文件放在模块的 `view/statics/` 目录
- 模板文件放在模块的 `view/templates/` 目录
- 使用模块名引用其他模块的资源

## 常见问题

### Q1: 资源文件未找到？

**A**: 检查以下几点：
1. 确保路径格式正确（`模块名::路径`）
2. 确保资源文件存在
3. 检查文件权限

### Q2: 如何引用其他模块的资源？

**A**: 使用完整的模块路径：

```html
@static(Weline_Admin::img/logo.png)
@js(Weline_Admin::js/admin.js)
```

### Q3: 如何添加资源属性？

**A**: 在标签中添加属性：

```html
<js defer>Weline_Frontend::js/main.js</js>
<css media="print">Weline_Frontend::css/print.css</css>
```

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [if 标签使用指南](03-if-elseif-else标签使用指南.md)

