# pp/dd/count/string 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `pp`、`dd`、`count`、`string` 标签的使用方法。这些标签用于调试输出、计数和字符串处理。

## 什么是 pp/dd/count/string 标签

- **`pp` 标签**：使用 `p()` 函数输出变量（格式化输出）
- **`dd` 标签**：使用 `dd()` 函数输出变量并终止执行（调试用）
- **`count` 标签**：计算数组或对象的元素数量
- **`string` 标签**：截取字符串并添加省略号

## 为什么需要这些标签

在模板中使用这些标签提供了以下优势：

- **调试工具**：`pp` 和 `dd` 用于调试输出
- **计数功能**：`count` 用于统计元素数量
- **字符串处理**：`string` 用于截取和格式化字符串

## pp 标签

### 语法格式

```html
<pp>variable</pp>
@pp(variable)
@pp{variable}
```

### 使用方法

#### 基本用法

```html
<pp>user</pp>
@pp(user)
@pp{user}
```

**编译结果**：
```php
<?=p($user??null)?>
```

**说明**：`p()` 函数会格式化输出变量，适合调试使用。

### 完整示例

```html
<div class="debug">
    <h3>调试信息</h3>
    <p>用户信息：<pp>user</pp></p>
    <p>商品列表：<pp>products</pp></p>
</div>
```

## dd 标签

### 语法格式

```html
<dd>variable</dd>
@dd(variable)
@dd{variable}
```

### 使用方法

#### 基本用法

```html
<dd>user</dd>
@dd(user)
@dd{user}
```

**编译结果**：
```php
<?=dd($user??null)?>
```

**说明**：`dd()` 函数会输出变量并终止脚本执行，仅用于调试。

### 完整示例

```html
<!-- 调试用户数据 -->
<dd>user</dd>

<!-- 调试商品数据 -->
<dd>products</dd>
```

**注意**：`dd` 标签会终止脚本执行，生产环境应移除。

## count 标签

### 语法格式

```html
<count>variable</count>
@count(variable)
@count{variable}
```

### 使用方法

#### 基本用法

```html
<count>items</count>
@count(items)
@count{items}
```

**编译结果**：
```php
<?=$items?count($items):0?>
```

**说明**：如果变量存在且可计数，返回元素数量，否则返回 0。

### 完整示例

```html
<div class="product-list">
    <h2>商品列表（共 <count>products</count> 件）</h2>
    
    <if condition="<count>products</count> > 0">
        <foreach name="products" item="product">
            <div class="product">
                <h3><var>product.name</var></h3>
            </div>
        </foreach>
    </if>
</div>
```

## string 标签

### 语法格式

```html
<string>variable|length</string>
@string(variable|length)
@string{variable|length}
```

### 使用方法

#### 基本用法

```html
<string>description|50</string>
@string(description|50)
@string{description|50}
```

**说明**：
- 第一个参数：要截取的变量
- 第二个参数：最大长度（字符数）
- 如果字符串超过指定长度，会截取并添加 `...`

**编译结果**：
```php
<?php if(!empty($description)&&50>0 && strlen($description)>50){
    echo mb_substr($description,0,50,'UTF8').'...';
}else{
    echo $description;
}?>
```

### 完整示例

```html
<div class="product-list">
    <foreach name="products" item="product">
        <div class="product">
            <h3><var>product.name</var></h3>
            <p><string>product.description|100</string></p>
        </div>
    </foreach>
</div>
```

### 字符串截取示例

```html
<!-- 截取标题 -->
<h2><string>title|30</string></h2>

<!-- 截取描述 -->
<p><string>description|150</string></p>

<!-- 截取内容 -->
<div class="content">
    <string>content|200</string>
</div>
```

## 完整示例

### 示例 1：调试输出

```html
<div class="debug-section">
    <h3>调试信息</h3>
    
    <h4>用户信息</h4>
    <pp>user</pp>
    
    <h4>商品列表</h4>
    <pp>products</pp>
    
    <h4>订单信息</h4>
    <pp>order</pp>
</div>
```

### 示例 2：统计和显示

```html
<div class="dashboard">
    <div class="stat-card">
        <h3>商品总数</h3>
        <p class="count"><count>products</count></p>
    </div>
    
    <div class="stat-card">
        <h3>用户总数</h3>
        <p class="count"><count>users</count></p>
    </div>
    
    <div class="stat-card">
        <h3>订单总数</h3>
        <p class="count"><count>orders</count></p>
    </div>
</div>
```

### 示例 3：字符串截取

```html
<div class="article-list">
    <foreach name="articles" item="article">
        <div class="article">
            <h3><string>article.title|50</string></h3>
            <p><string>article.summary|150</string></p>
            <p><string>article.content|200</string></p>
            <a href="@url('/article/view', ['id' => '{{article.id}}'])">阅读全文</a>
        </div>
    </foreach>
</div>
```

## 注意事项

### 1. pp 和 dd 标签

- **`pp`**：格式化输出，不终止执行
- **`dd`**：输出并终止执行，仅用于调试
- 生产环境应移除这些调试标签

### 2. count 标签

- 只对数组和可计数对象有效
- 如果变量不存在，返回 0
- 不会产生 PHP 警告

### 3. string 标签

- 使用 `mb_substr()` 函数，支持多字节字符
- 长度参数是字符数，不是字节数
- 如果字符串长度小于指定长度，不添加 `...`

### 4. 性能考虑

- `pp` 和 `dd` 仅用于开发调试
- `count` 和 `string` 可以正常使用
- 注意字符串截取的性能影响

## 常见问题

### Q1: dd 标签导致页面停止？

**A**: `dd` 标签会终止脚本执行，这是正常行为。生产环境应移除 `dd` 标签。

### Q2: count 返回 0？

**A**: 检查变量是否存在且可计数：

```html
<if condition="isset($items)">
    <p>数量：<count>items</count></p>
</if>
```

### Q3: string 截取不正确？

**A**: 确保长度参数正确，注意多字节字符：

```html
<!-- 正确：指定字符数 -->
<string>description|50</string>

<!-- 注意：中文字符也按 1 个字符计算 -->
```

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [if 标签使用指南](03-if-elseif-else标签使用指南.md)

