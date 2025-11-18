# if/elseif/else 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `if`、`elseif` 和 `else` 标签的使用方法。这些标签用于在模板中实现条件判断逻辑。

## 什么是 if/elseif/else 标签

`if`、`elseif` 和 `else` 标签是 WelineFramework 提供的条件判断标签，用于在模板中根据条件显示不同的内容。这些标签提供了类似 PHP 的条件判断功能，但语法更简洁。

## 为什么需要 if/elseif/else 标签

在模板中使用条件标签提供了以下优势：

- **逻辑控制**：根据条件显示不同的内容
- **简洁语法**：比 PHP 原生语法更简洁易读
- **多种格式**：支持标签格式和 `@if()` 格式
- **灵活组合**：支持 `if`、`elseif`、`else` 的组合使用

## 语法格式

### 1. `<if>` 标签格式

```html
<if condition="$condition">
    <!-- 条件为真时显示的内容 -->
</if>

<if condition="$a > $b">
    <p>a 大于 b</p>
    <elseif condition="$a < $b"/>
    <p>a 小于 b</p>
    <else/>
    <p>a 等于 b</p>
</if>
```

### 2. `@if()` 格式

```html
@if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
@if{$a > $b=><p>a 大于 b</p>|$a < $b=><p>a 小于 b</p>|<p>a 等于 b</p>}
```

## 使用方法

### 基本条件判断

最简单的用法是判断单个条件：

```html
<if condition="$isLoggedIn">
    <p>欢迎回来！</p>
</if>
```

**编译结果**：
```php
<?php if($isLoggedIn):?>
    <p>欢迎回来！</p>
<?php endif;?>
```

### if-else 结构

使用 `else` 标签处理条件为假的情况：

```html
<if condition="$isLoggedIn">
    <p>欢迎回来！</p>
    <else/>
    <p>请先登录</p>
</if>
```

**编译结果**：
```php
<?php if($isLoggedIn):?>
    <p>欢迎回来！</p>
<?php else:?>
    <p>请先登录</p>
<?php endif;?>
```

### if-elseif-else 结构

使用 `elseif` 标签处理多个条件：

```html
<if condition="$score >= 90">
    <p>优秀</p>
    <elseif condition="$score >= 80"/>
    <p>良好</p>
    <elseif condition="$score >= 60"/>
    <p>及格</p>
    <else/>
    <p>不及格</p>
</if>
```

**编译结果**：
```php
<?php if($score >= 90):?>
    <p>优秀</p>
<?php elseif($score >= 80):?>
    <p>良好</p>
<?php elseif($score >= 60):?>
    <p>及格</p>
<?php else:?>
    <p>不及格</p>
<?php endif;?>
```

### 使用 @if() 格式

`@if()` 格式支持内联条件判断：

```html
<!-- 单个条件 -->
@if{$isLoggedIn=><p>欢迎回来！</p>}

<!-- 多个条件 -->
@if{$score >= 90=><p>优秀</p>|$score >= 80=><p>良好</p>|$score >= 60=><p>及格</p>|<p>不及格</p>}
```

**语法说明**：
- 使用 `=>` 分隔条件和内容
- 使用 `|` 分隔多个条件分支
- 最后一个分支（没有 `=>`）作为 `else` 分支

## 条件表达式

### 比较运算符

支持所有 PHP 比较运算符：

```html
<!-- 等于 -->
<if condition="$a == $b">
    <p>a 等于 b</p>
</if>

<!-- 严格等于 -->
<if condition="$a === $b">
    <p>a 严格等于 b</p>
</if>

<!-- 不等于 -->
<if condition="$a != $b">
    <p>a 不等于 b</p>
</if>

<!-- 大于 -->
<if condition="$a > $b">
    <p>a 大于 b</p>
</if>

<!-- 小于 -->
<if condition="$a < $b">
    <p>a 小于 b</p>
</if>

<!-- 大于等于 -->
<if condition="$a >= $b">
    <p>a 大于等于 b</p>
</if>

<!-- 小于等于 -->
<if condition="$a <= $b">
    <p>a 小于等于 b</p>
</if>
```

### 逻辑运算符

支持逻辑与、或、非：

```html
<!-- 逻辑与 -->
<if condition="$a > 0 && $b > 0">
    <p>a 和 b 都大于 0</p>
</if>

<!-- 逻辑或 -->
<if condition="$a > 0 || $b > 0">
    <p>a 或 b 大于 0</p>
</if>

<!-- 逻辑非 -->
<if condition="!$isEmpty">
    <p>不为空</p>
</if>
```

### 变量检查

可以检查变量是否存在或为空：

```html
<!-- 检查变量是否存在 -->
<if condition="isset($user)">
    <p>用户已设置</p>
</if>

<!-- 检查变量是否为空 -->
<if condition="!empty($items)">
    <p>有数据</p>
</if>

<!-- 检查变量是否为真 -->
<if condition="$isActive">
    <p>已激活</p>
</if>
```

## 完整示例

### 示例 1：用户登录状态

```html
<if condition="$isLoggedIn">
    <div class="user-menu">
        <p>欢迎，<var>user.name</var>！</p>
        <a href="@url('/logout')">退出登录</a>
    </div>
    <else/>
    <div class="login-form">
        <a href="@url('/login')">登录</a>
        <a href="@url('/register')">注册</a>
    </div>
</if>
```

### 示例 2：权限判断

```html
<if condition="$user.role === 'admin'">
    <a href="@url('/admin')">管理后台</a>
    <elseif condition="$user.role === 'editor'"/>
    <a href="@url('/editor')">编辑中心</a>
    <else/>
    <p>普通用户</p>
</if>
```

### 示例 3：数据展示

```html
<if condition="!empty($products)">
    <div class="product-list">
        <foreach name="products" item="product">
            <div class="product">
                <h3><var>product.name</var></h3>
                <p>价格：¥<var>product.price</var></p>
            </div>
        </foreach>
    </div>
    <else/>
    <p class="empty">暂无商品</p>
</if>
```

### 示例 4：使用 @if() 格式

```html
<!-- 简单的条件判断 -->
<div class="status">
    @if{$order.status === 'paid'=><span class="badge badge-success">已支付</span>|$order.status === 'pending'=><span class="badge badge-warning">待支付</span>|<span class="badge badge-danger">已取消</span>}
</div>

<!-- 嵌套使用 -->
<div class="user-info">
    @if{$user=>
        <p>用户名：<var>user.name</var></p>
        @if{$user.email=><p>邮箱：<var>user.email</var></p>}
    |<p>用户未登录</p>}
</div>
```

## 注意事项

### 1. condition 属性必填

`<if>` 标签必须提供 `condition` 属性：

```html
<!-- 正确 -->
<if condition="$isLoggedIn">
    <p>已登录</p>
</if>

<!-- 错误：缺少 condition 属性 -->
<if>
    <p>已登录</p>
</if>
```

### 2. elseif 和 else 的使用

- `elseif` 必须使用自闭合标签：`<elseif condition="..."/>`
- `else` 必须使用自闭合标签：`<else/>`
- `elseif` 和 `else` 必须放在 `<if>` 标签内部

### 3. 条件表达式

- 条件表达式会被解析为 PHP 代码
- 支持所有 PHP 表达式语法
- 变量路径会自动解析（如 `$user.name`）

### 4. 嵌套使用

`if` 标签可以嵌套使用：

```html
<if condition="$isLoggedIn">
    <if condition="$user.role === 'admin'">
        <p>管理员</p>
        <else/>
        <p>普通用户</p>
    </if>
</if>
```

## 常见问题

### Q1: 条件判断不生效？

**A**: 检查以下几点：
1. 确保 `condition` 属性已正确设置
2. 检查条件表达式语法是否正确
3. 确保变量已传递到模板

### Q2: 如何使用复杂的条件表达式？

**A**: 支持所有 PHP 表达式：

```html
<if condition="($a > 0 && $b > 0) || ($c > 0)">
    <p>复杂条件</p>
</if>
```

### Q3: 如何检查数组是否为空？

**A**: 使用 `empty()` 或 `count()`：

```html
<if condition="!empty($items)">
    <p>有数据</p>
</if>

<if condition="count($items) > 0">
    <p>有数据</p>
</if>
```

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [foreach 标签使用指南](05-foreach标签使用指南.md)
- [empty/notempty/has 标签使用指南](08-empty-notempty-has标签使用指南.md)

