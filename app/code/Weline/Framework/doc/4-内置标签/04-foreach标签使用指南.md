# foreach 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `foreach` 标签的使用方法。`foreach` 标签用于在模板中遍历数组或对象集合。

## 什么是 foreach 标签

`foreach` 标签是 WelineFramework 提供的循环标签，用于在模板中遍历数组或对象集合。该标签提供了类似 PHP `foreach` 的功能，但语法更简洁。

## 为什么需要 foreach 标签

在模板中使用 `foreach` 标签提供了以下优势：

- **循环遍历**：轻松遍历数组和对象集合
- **简洁语法**：比 PHP 原生语法更简洁易读
- **多种格式**：支持标签格式和 `@foreach()` 格式
- **灵活配置**：支持自定义键名和值名

## 语法格式

### 1. `<foreach>` 标签格式

```html
<foreach name="items" item="item">
    <!-- 循环内容 -->
</foreach>

<foreach name="items" key="key" item="item">
    <!-- 循环内容 -->
</foreach>
```

### 2. `@foreach()` 格式

```html
@foreach{$items as $item|<li><var>$item</var></li>}
@foreach{$items as $key=>$item|<li><var>$key</var>:<var>$item</var></li>}
```

## 使用方法

### 基本循环

最简单的用法是遍历数组：

```html
<foreach name="items" item="item">
    <p><var>item</var></p>
</foreach>
```

**编译结果**：
```php
<?php foreach($items as $item):?>
    <p><?=$item??""?></p>
<?php endforeach;?>
```

### 带键名的循环

使用 `key` 属性获取数组键名：

```html
<foreach name="items" key="key" item="item">
    <p><var>key</var>: <var>item</var></p>
</foreach>
```

**编译结果**：
```php
<?php foreach($items as $key => $item):?>
    <p><?=$key??""?>: <?=$item??""?></p>
<?php endforeach;?>
```

### 使用 @foreach() 格式

`@foreach()` 格式支持内联循环：

```html
<!-- 基本循环 -->
@foreach{$items as $item|<li><var>$item</var></li>}

<!-- 带键名 -->
@foreach{$items as $key=>$item|<li><var>$key</var>:<var>$item</var></li>}
```

**语法说明**：
- 使用 `|` 分隔循环表达式和循环内容
- 循环表达式格式：`$变量名 as $值名` 或 `$变量名 as $键名=>$值名`
- 循环内容可以是任意 HTML 和标签

## 属性说明

### name 属性（必填）

指定要遍历的变量名：

```html
<foreach name="products" item="product">
    <!-- 遍历 $products 数组 -->
</foreach>
```

### item 属性（可选，默认：v）

指定循环中当前元素的变量名：

```html
<!-- 默认使用 v -->
<foreach name="items">
    <p><var>v</var></p>
</foreach>

<!-- 自定义变量名 -->
<foreach name="items" item="product">
    <p><var>product</var></p>
</foreach>
```

### key 属性（可选）

指定循环中当前键名的变量名：

```html
<foreach name="items" key="index" item="item">
    <p><var>index</var>: <var>item</var></p>
</foreach>
```

## 完整示例

### 示例 1：商品列表

```html
<div class="product-list">
    <foreach name="products" item="product">
        <div class="product">
            <h3><var>product.name</var></h3>
            <p>价格：¥<var>product.price</var></p>
            <p>库存：<var>product.stock</var></p>
            <a href="@url('/product/view', ['id' => '{{product.id}}'])">查看详情</a>
        </div>
    </foreach>
</div>
```

### 示例 2：带索引的列表

```html
<ol>
    <foreach name="items" key="index" item="item">
        <li>
            <span class="index"><var>index</var></span>
            <span class="content"><var>item</var></span>
        </li>
    </foreach>
</ol>
```

### 示例 3：嵌套循环

```html
<foreach name="categories" item="category">
    <h2><var>category.name</var></h2>
    <ul>
        <foreach name="category.products" item="product">
            <li>
                <var>product.name</var> - ¥<var>product.price</var>
            </li>
        </foreach>
    </ul>
</foreach>
```

### 示例 4：条件循环

```html
<foreach name="items" item="item">
    <if condition="$item.status === 'active'">
        <div class="active-item">
            <var>item.name</var>
        </div>
    </if>
</foreach>
```

### 示例 5：使用 @foreach() 格式

```html
<!-- 简单列表 -->
<ul>
    @foreach{$items as $item|<li><var>$item</var></li>}
</ul>

<!-- 带键名的列表 -->
<ul>
    @foreach{$items as $key=>$item|<li><var>$key</var>: <var>$item</var></li>}
</ul>

<!-- 复杂内容 -->
<div class="product-grid">
    @foreach{$products as $product|
        <div class="product">
            <h3><var>$product.name</var></h3>
            <p>¥<var>$product.price</var></p>
        </div>
    }
</div>
```

## 注意事项

### 1. name 属性必填

`<foreach>` 标签必须提供 `name` 属性：

```html
<!-- 正确 -->
<foreach name="items" item="item">
    <p><var>item</var></p>
</foreach>

<!-- 错误：缺少 name 属性 -->
<foreach item="item">
    <p><var>item</var></p>
</foreach>
```

### 2. 变量作用域

循环内的变量只在循环体内有效：

```html
<foreach name="items" item="item">
    <!-- 这里可以使用 $item -->
    <p><var>item</var></p>
</foreach>
<!-- 这里不能使用 $item -->
```

### 3. 嵌套循环

嵌套循环时，内层循环可以使用外层循环的变量：

```html
<foreach name="categories" item="category">
    <h2><var>category.name</var></h2>
    <foreach name="category.products" item="product">
        <!-- 这里可以同时使用 $category 和 $product -->
        <p><var>category.name</var> - <var>product.name</var></p>
    </foreach>
</foreach>
```

### 4. 空数组处理

如果数组为空，循环不会执行，不会输出任何内容：

```html
<!-- 如果 $items 为空，不会输出任何内容 -->
<foreach name="items" item="item">
    <p><var>item</var></p>
</foreach>
```

可以使用 `empty` 标签处理空数组：

```html
<empty name="items">
    <p>暂无数据</p>
</empty>
<notempty name="items">
    <foreach name="items" item="item">
        <p><var>item</var></p>
    </foreach>
</notempty>
```

## 常见问题

### Q1: 循环没有输出？

**A**: 检查以下几点：
1. 确保 `name` 属性指定的变量存在
2. 确保变量是数组或可遍历的对象
3. 检查数组是否为空

### Q2: 如何获取循环索引？

**A**: 使用 `key` 属性：

```html
<foreach name="items" key="index" item="item">
    <p>第 <var>index</var> 项：<var>item</var></p>
</foreach>
```

### Q3: 如何跳出循环？

**A**: `foreach` 标签不支持 `break` 或 `continue`，如果需要，可以在 PHP 代码中处理数据后再循环。

### Q4: 如何限制循环次数？

**A**: 可以在 PHP 代码中使用 `array_slice()` 限制数组长度：

```php
// 在控制器中
$items = array_slice($allItems, 0, 10); // 只取前 10 项
$this->assign('items', $items);
```

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [if 标签使用指南](03-if-elseif-else标签使用指南.md)
- [empty/notempty/has 标签使用指南](08-empty-notempty-has标签使用指南.md)

