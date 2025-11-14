# php/include 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `php` 和 `include` 标签的使用方法。这两个标签用于在模板中嵌入 PHP 代码和包含其他文件。

## 什么是 php/include 标签

- **`php` 标签**：用于在模板中嵌入 PHP 代码块
- **`include` 标签**：用于在模板中包含其他 PHP 文件

## 为什么需要 php/include 标签

在模板中使用这些标签提供了以下优势：

- **PHP 代码**：可以直接在模板中编写 PHP 代码
- **文件包含**：可以包含其他 PHP 文件，实现代码复用
- **灵活性**：提供更大的灵活性，适合复杂逻辑

## php 标签

### 语法格式

```html
<php>
    // PHP 代码
</php>

@php($variable = 'value')
```

### 使用方法

#### 基本用法

```html
<php>
    $name = 'John';
    $age = 25;
</php>

<p>姓名：<?= $name ?></p>
<p>年龄：<?= $age ?></p>
```

#### 使用 @php() 格式

```html
@php($name = 'John')
@php($items = ['item1', 'item2', 'item3'])

<p>姓名：<?= $name ?></p>
```

### 完整示例

```html
<php>
    $total = 0;
    foreach ($items as $item) {
        $total += $item['price'];
    }
    $discount = $total * 0.1;
    $finalTotal = $total - $discount;
</php>

<div class="summary">
    <p>商品总数：<?= count($items) ?></p>
    <p>原价：¥<?= $total ?></p>
    <p>折扣：¥<?= $discount ?></p>
    <p>实付：¥<?= $finalTotal ?></p>
</div>
```

## include 标签

### 语法格式

```html
<include file="path/to/file.php"/>
<include>path/to/file.php</include>

@include(path/to/file.php)
@include{path/to/file.php}
```

### 使用方法

#### 基本用法

```html
<!-- 包含头部文件 -->
<include file="header.php"/>

<!-- 主要内容 -->
<div class="content">
    <h1>页面内容</h1>
</div>

<!-- 包含底部文件 -->
<include file="footer.php"/>
```

#### 使用 @include() 格式

```html
@include(header.php)
@include{footer.php}
```

### 完整示例

```html
<!doctype html>
<html>
<head>
    <include file="head.php"/>
</head>
<body>
    <include file="header.php"/>
    
    <main>
        <h1>页面标题</h1>
        <p>页面内容</p>
    </main>
    
    <include file="footer.php"/>
</body>
</html>
```

## 注意事项

### 1. 文件路径

- 文件路径相对于模板文件所在目录
- 可以使用绝对路径
- 确保文件存在且有读取权限

### 2. PHP 代码执行

- `php` 标签中的代码会在模板渲染时执行
- 注意代码的性能影响
- 避免在 `php` 标签中执行耗时操作

### 3. 变量作用域

- `php` 标签中定义的变量在后续模板中可用
- `include` 的文件可以访问当前模板的变量
- 注意变量作用域和命名冲突

## 常见问题

### Q1: include 文件未找到？

**A**: 检查文件路径是否正确，确保文件存在。

### Q2: PHP 代码执行错误？

**A**: 检查 PHP 代码语法是否正确，确保变量已定义。

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)

