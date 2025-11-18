# var 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `var` 标签的使用方法。`var` 标签用于在模板中输出 PHP 变量的值。

## 什么是 var 标签

`var` 标签是 WelineFramework 提供的变量输出标签，用于在模板中输出 PHP 变量的值。该标签会自动处理变量路径（如 `$user.name`）和空值处理。

## 为什么需要 var 标签

在模板中使用 `var` 标签提供了以下优势：

- **简洁语法**：比 PHP 原生语法更简洁易读
- **自动路径解析**：支持点号分隔的变量路径（如 `$user.name`）
- **空值处理**：自动处理变量不存在的情况
- **多种格式**：支持 `<var>` 标签、`@var()` 和 `@var{}` 三种格式

## 语法格式

`var` 标签支持以下三种语法格式：

### 1. `<var>` 标签格式

```html
<var>username</var>
<var>user.name</var>
<var>$user.email</var>
```

### 2. `@var()` 格式

```html
@var(username)
@var(user.name)
@var($user.email)
```

### 3. `@var{}` 格式

```html
@var{username}
@var{user.name}
@var{$user.email}
```

## 使用方法

### 基本用法

最简单的用法是直接输出变量：

```html
<!-- 输出 $username 变量 -->
<p>用户名：<var>username</var></p>
<p>用户名：@var(username)</p>
<p>用户名：@var{username}</p>
```

**编译结果**：
```php
<p>用户名：<?=$username??""?></p>
```

### 变量路径

支持使用点号访问嵌套变量：

```html
<!-- 输出 $user['name'] -->
<p>用户名：<var>user.name</var></p>

<!-- 输出 $user['profile']['email'] -->
<p>邮箱：<var>user.profile.email</var></p>

<!-- 输出 $order['items'][0]['name'] -->
<p>商品名：<var>order.items.0.name</var></p>
```

**编译结果**：
```php
<p>用户名：<?=($user??null)['name']??""?></p>
<p>邮箱：<?=($user??null)['profile']??null)['email']??""?></p>
```

### 使用 $ 前缀

可以显式使用 `$` 前缀：

```html
<!-- 两种写法等价 -->
<var>username</var>
<var>$username</var>
```

### 空值处理

当变量不存在时，`var` 标签会输出空字符串：

```html
<!-- 如果 $username 不存在，输出空字符串 -->
<p>用户名：<var>username</var></p>
```

## 完整示例

### 示例 1：用户信息展示

```html
<div class="user-info">
    <h2>用户信息</h2>
    <p>用户名：<var>user.name</var></p>
    <p>邮箱：<var>user.email</var></p>
    <p>电话：<var>user.phone</var></p>
    <p>地址：<var>user.address.city</var> <var>user.address.street</var></p>
</div>
```

### 示例 2：商品列表

```html
<foreach name="products" item="product">
    <div class="product">
        <h3><var>product.name</var></h3>
        <p>价格：¥<var>product.price</var></p>
        <p>库存：<var>product.stock</var></p>
        <p>描述：<var>product.description</var></p>
    </div>
</foreach>
```

### 示例 3：条件输出

```html
<if condition="$user">
    <p>欢迎，<var>user.name</var>！</p>
    <p>您的邮箱是：<var>user.email</var></p>
</if>
```

### 示例 4：混合使用

```html
<div class="order-info">
    <h2>订单信息</h2>
    <p>订单号：<var>order.order_id</var></p>
    <p>下单时间：@var(order.create_time)</p>
    <p>订单状态：@var{order.status}</p>
    <p>总金额：¥<var>order.total_amount</var></p>
    
    <h3>收货地址</h3>
    <p><var>order.address.name</var></p>
    <p><var>order.address.phone</var></p>
    <p><var>order.address.province</var> <var>order.address.city</var> <var>order.address.district</var></p>
    <p><var>order.address.detail</var></p>
</div>
```

## 变量路径说明

### 点号分隔路径

使用点号可以访问数组或对象的嵌套属性：

```html
<!-- $user['name'] -->
<var>user.name</var>

<!-- $user['profile']['email'] -->
<var>user.profile.email</var>

<!-- $data['items'][0]['name'] -->
<var>data.items.0.name</var>
```

### 数组索引

使用数字作为路径的一部分可以访问数组元素：

```html
<!-- $items[0] -->
<var>items.0</var>

<!-- $items[0]['name'] -->
<var>items.0.name</var>
```

## 编译机制

`var` 标签会被编译为 PHP 代码，自动处理变量路径和空值：

```html
<!-- 源代码 -->
<var>user.name</var>

<!-- 编译后 -->
<?=($user??null)['name']??""?>
```

## 注意事项

### 1. 变量名规范

- 变量名可以是字母、数字、下划线的组合
- 可以使用 `$` 前缀，也可以不使用
- 路径使用点号分隔

### 2. 空值处理

- 如果变量不存在，输出空字符串
- 如果路径中的某个环节不存在，也会输出空字符串
- 不会产生 PHP 警告或错误

### 3. 性能考虑

- `var` 标签会在编译时转换为 PHP 代码
- 运行时性能与直接使用 PHP 代码相同
- 支持复杂的变量路径解析

### 4. 与 {{}} 语法的区别

`var` 标签和 `{{}}` 语法功能相同，但 `var` 标签更明确：

```html
<!-- 两种写法等价 -->
<var>username</var>
{{username}}
```

## 常见问题

### Q1: 为什么变量没有输出？

**A**: 检查以下几点：
1. 确保变量已通过 `assign()` 方法传递到模板
2. 检查变量名是否正确（区分大小写）
3. 检查变量路径是否正确

### Q2: 如何输出数组元素？

**A**: 使用点号和索引：

```html
<!-- 输出 $items[0] -->
<var>items.0</var>

<!-- 输出 $items[0]['name'] -->
<var>items.0.name</var>
```

### Q3: 如何输出对象属性？

**A**: 使用点号访问对象属性：

```html
<!-- 输出 $user->getName() 或 $user['name'] -->
<var>user.name</var>
```

## 相关文档

- [if 标签使用指南](03-if-elseif-else标签使用指南.md)
- [foreach 标签使用指南](05-foreach标签使用指南.md)

