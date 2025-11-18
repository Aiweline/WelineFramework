# empty/notempty/has 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `empty`、`notempty` 和 `has` 标签的使用方法。这些标签用于检查变量是否为空或存在。

## 什么是 empty/notempty/has 标签

- **`empty` 标签**：检查变量是否为空，为空时显示内容
- **`notempty` 标签**：检查变量是否不为空，不为空时显示内容
- **`has` 标签**：检查变量是否存在且不为空，存在时显示内容

## 为什么需要 empty/notempty/has 标签

在模板中使用这些标签提供了以下优势：

- **条件显示**：根据变量是否为空显示不同内容
- **简洁语法**：比 PHP 原生语法更简洁
- **空值处理**：统一处理空值情况

## empty 标签

### 语法格式

```html
<empty name="variable">
    <!-- 变量为空时显示的内容 -->
</empty>

@empty{$variable|<p>变量为空</p>}
```

### 使用方法

#### 基本用法

```html
<empty name="items">
    <p>暂无数据</p>
</empty>
```

**编译结果**：
```php
<?php if(empty($items)):?>
    <p>暂无数据</p>
<?php endif;?>
```

#### 使用 @empty() 格式

```html
@empty{$items|<p>暂无数据</p>}
```

### 完整示例

```html
<empty name="products">
    <div class="empty-state">
        <p>暂无商品</p>
        <a href="@url('/product/add')">添加商品</a>
    </div>
</empty>

<notempty name="products">
    <div class="product-list">
        <foreach name="products" item="product">
            <div class="product">
                <h3><var>product.name</var></h3>
                <p>价格：¥<var>product.price</var></p>
            </div>
        </foreach>
    </div>
</notempty>
```

## notempty 标签

### 语法格式

```html
<notempty name="variable">
    <!-- 变量不为空时显示的内容 -->
</notempty>

@notempty{$variable|<p>变量不为空</p>}
```

### 使用方法

#### 基本用法

```html
<notempty name="user">
    <p>欢迎，<var>user.name</var>！</p>
</notempty>
```

**编译结果**：
```php
<?php if(!empty($user)):?>
    <p>欢迎，<?=$user['name']??""?>！</p>
<?php endif;?>
```

#### 使用 @notempty() 格式

```html
@notempty{$user|<p>欢迎，<var>$user.name</var>！</p>}
```

### 完整示例

```html
<notempty name="cartItems">
    <div class="cart">
        <h2>购物车</h2>
        <foreach name="cartItems" item="item">
            <div class="cart-item">
                <var>item.name</var> × <var>item.quantity</var>
            </div>
        </foreach>
    </div>
</notempty>
```

## has 标签

### 语法格式

```html
<has name="variable">
    <!-- 变量存在且不为空时显示的内容 -->
</has>

@has{$variable=><p>变量存在</p>|$variable2=><p>变量2存在</p>|<p>都不存在</p>}
```

### 使用方法

#### 基本用法

```html
<has name="user">
    <p>用户已登录</p>
</has>
```

**编译结果**：
```php
<?php if(!empty($user)):?>
    <p>用户已登录</p>
<?php endif;?>
```

#### 使用 @has() 格式（支持多个条件）

```html
@has{$user=><p>用户存在</p>|$admin=><p>管理员存在</p>|<p>都不存在</p>}
```

### 完整示例

```html
<has name="user">
    <div class="user-info">
        <p>用户名：<var>user.name</var></p>
        <p>邮箱：<var>user.email</var></p>
    </div>
</has>

<!-- 使用 @has() 格式处理多个条件 -->
<div class="status">
    @has{$user=>
        <p>用户：<var>$user.name</var></p>
    |$admin=>
        <p>管理员：<var>$admin.name</var></p>
    |<p>未登录</p>}
</div>
```

## 标签对比

| 标签 | 条件 | 说明 |
|------|------|------|
| `empty` | 变量为空 | 变量为 `null`、`false`、`0`、`''`、`[]` 等时显示 |
| `notempty` | 变量不为空 | 变量存在且有值时显示 |
| `has` | 变量存在且不为空 | 与 `notempty` 功能相同，但支持多条件 |

## 完整示例

### 示例 1：数据列表

```html
<empty name="items">
    <div class="empty-state">
        <p>暂无数据</p>
    </div>
</empty>

<notempty name="items">
    <ul>
        <foreach name="items" item="item">
            <li><var>item</var></li>
        </foreach>
    </ul>
</notempty>
```

### 示例 2：用户信息

```html
<has name="user">
    <div class="user-card">
        <h3><var>user.name</var></h3>
        <p>邮箱：<var>user.email</var></p>
        <p>电话：<var>user.phone</var></p>
    </div>
    <else/>
    <div class="login-prompt">
        <p>请先登录</p>
        <a href="@url('/login')">登录</a>
    </div>
</has>
```

### 示例 3：购物车

```html
<empty name="cartItems">
    <div class="empty-cart">
        <p>购物车是空的</p>
        <a href="@url('/product/list')">去购物</a>
    </div>
</empty>

<notempty name="cartItems">
    <div class="cart">
        <h2>购物车（<var>cartItemCount</var> 件商品）</h2>
        <foreach name="cartItems" item="item">
            <div class="cart-item">
                <var>item.name</var> - ¥<var>item.price</var> × <var>item.quantity</var>
            </div>
        </foreach>
        <p>总计：¥<var>cartTotal</var></p>
    </div>
</notempty>
```

## 注意事项

### 1. name 属性必填

`empty`、`notempty`、`has` 标签必须提供 `name` 属性：

```html
<!-- 正确 -->
<empty name="items">
    <p>暂无数据</p>
</empty>

<!-- 错误：缺少 name 属性 -->
<empty>
    <p>暂无数据</p>
</empty>
```

### 2. 空值判断

这些标签使用 PHP 的 `empty()` 函数判断：

- `null`、`false`、`0`、`''`、`[]` 等被视为空
- 字符串 `'0'` 被视为空
- 未定义的变量被视为空

### 3. 与 if 标签的区别

- `empty`/`notempty`/`has`：专门用于检查变量是否为空
- `if`：可以检查任意条件表达式

### 4. 嵌套使用

这些标签可以嵌套使用：

```html
<has name="user">
    <has name="user.profile">
        <p>个人资料：<var>user.profile.bio</var></p>
    </has>
</has>
```

## 常见问题

### Q1: 标签没有生效？

**A**: 检查以下几点：
1. 确保 `name` 属性已正确设置
2. 检查变量名是否正确
3. 确保变量已传递到模板

### Q2: 如何判断数组是否为空？

**A**: 使用 `empty` 或 `notempty`：

```html
<empty name="items">
    <p>数组为空</p>
</empty>

<notempty name="items">
    <p>数组不为空，有 <var>count</var> 个元素</p>
</notempty>
```

### Q3: has 和 notempty 有什么区别？

**A**: 功能相同，但 `has` 支持 `@has()` 格式的多条件判断。

## 相关文档

- [if 标签使用指南](03-if-elseif-else标签使用指南.md)
- [foreach 标签使用指南](04-foreach标签使用指南.md)

