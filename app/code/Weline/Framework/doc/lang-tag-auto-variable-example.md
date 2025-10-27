# Lang 标签自动变量识别功能说明

## 🌟 智能特性

`<lang>` 标签现在支持**自动变量识别**功能！

### 核心特性

当 `<lang>` 标签：
1. **没有设置 `args` 参数**
2. **或者占位符不在 `args` 参数内**

框架会**自动识别**文本中的占位符（如 `%{demo}`），并将其映射到**同名的 PHP 变量**（`$demo`）。

---

## 📝 基本用法

### 示例 1：无 args 参数，自动使用变量

```html
<?php $min = 8; $max = 20; ?>
<lang>Password length must be between %{min} and %{max} characters</lang>
```

**转换为**：
```php
<?= __('Password length must be between %{min} and %{max} characters', ['min' => $min, 'max' => $max]) ?>
```

**输出**：
```
Password length must be between 8 and 20 characters
```

---

### 示例 2：有 args 参数，严格使用 args

```html
<?php $demo = 'from variable'; ?>
<lang args="['demo' => 'from args']">Test %{demo}</lang>
```

**输出**：
```
Test from args
```

**说明**：有 `args` 参数时，严格使用 `args` 中提供的值，忽略变量 `$demo`。

---

### 示例 3：部分占位符在 args 中

```html
<?php $min = 8; $max = 20; ?>
<lang args="['min' => 6]">Length: %{min}-%{max}</lang>
```

**转换为**：
```php
<?= __('Length: %{min}-%{max}', ['min' => 6, 'max' => $max]) ?>
```

**输出**：
```
Length: 6-20
```

**说明**：
- `%{min}` 使用 `args` 中的值：`6`
- `%{max}` 不在 `args` 中，自动使用变量 `$max` 的值：`20`

---

## 🎯 实际应用场景

### 场景 1：表单验证提示

```html
<?php 
$minLength = 6; 
$maxLength = 30; 
?>
<div class="form-group">
    <label>Username</label>
    <input type="text" name="username" />
    <small class="form-text text-muted">
        <lang>Username must be %{minLength}-%{maxLength} characters</lang>
    </small>
</div>
```

**优势**：
- ✅ 无需写 `args` 参数
- ✅ 代码更简洁
- ✅ 自动使用 `$minLength` 和 `$maxLength` 变量

---

### 场景 2：统计信息展示

```html
<?php 
$totalUsers = 100; 
$activeUsers = 85; 
$onlineUsers = 12;
?>
<div class="stats-panel">
    <h4><lang>User Statistics</lang></h4>
    <ul>
        <li><lang>Total: %{totalUsers}</lang></li>
        <li><lang>Active: %{activeUsers}</lang></li>
        <li><lang>Online: %{onlineUsers}</lang></li>
    </ul>
</div>
```

**输出**：
```
User Statistics
• Total: 100
• Active: 85
• Online: 12
```

---

### 场景 3：动态消息提示

```html
<?php 
$userName = 'Admin'; 
$lastLoginTime = '2025-10-26 10:30:00';
$loginCount = 156;
?>
<div class="welcome-message">
    <lang>Welcome back, %{userName}!</lang><br>
    <small>
        <lang>Last login: %{lastLoginTime}</lang><br>
        <lang>Total logins: %{loginCount}</lang>
    </small>
</div>
```

---

### 场景 4：数据表格

```html
<?php foreach ($orders as $order): ?>
    <?php 
    $orderId = $order->getId();
    $orderStatus = $order->getStatus();
    $orderAmount = $order->getAmount();
    ?>
    <tr>
        <td><lang>Order #%{orderId}</lang></td>
        <td><lang>Status: %{orderStatus}</lang></td>
        <td><lang>Amount: %{orderAmount}</lang></td>
    </tr>
<?php endforeach; ?>
```

---

## 🔄 智能规则详解

### 规则 1：优先级顺序

```
args 参数值 > 自动识别的变量
```

```html
<?php $price = 100; ?>

<!-- 情况 1：有 args，使用 args -->
<lang args="['price' => 99]">Price: %{price}</lang>
<!-- 输出：Price: 99 -->

<!-- 情况 2：无 args，使用变量 -->
<lang>Price: %{price}</lang>
<!-- 输出：Price: 100 -->
```

---

### 规则 2：部分覆盖

```html
<?php $min = 8; $max = 20; $type = 'password'; ?>

<lang args="['min' => 6, 'max' => 30]">
    %{type} length: %{min}-%{max} characters
</lang>
```

**结果**：
- `%{type}` → 使用变量 `$type`：`'password'`
- `%{min}` → 使用 args：`6`
- `%{max}` → 使用 args：`30`

**输出**：
```
password length: 6-30 characters
```

---

### 规则 3：变量作用域

```html
<?php 
$username = 'Global User';

if ($condition) {
    $username = 'Local User';
    ?>
    <p><lang>Welcome %{username}</lang></p>
    <?php
}
?>
```

**说明**：使用当前作用域的 `$username` 变量。

---

## ⚠️ 注意事项

### 1. 变量必须存在

```html
<!-- ❌ 错误：变量不存在 -->
<lang>Price: %{price}</lang>
<!-- PHP Warning: Undefined variable $price -->

<!-- ✅ 正确：确保变量存在 -->
<?php $price = $price ?? 0; ?>
<lang>Price: %{price}</lang>
```

---

### 2. 变量命名规范

占位符必须符合 PHP 变量命名规范：

```html
<!-- ✅ 正确 -->
<?php $userName = 'John'; ?>
<lang>Welcome %{userName}</lang>

<?php $user_name = 'John'; ?>
<lang>Welcome %{user_name}</lang>

<?php $user2 = 'John'; ?>
<lang>Welcome %{user2}</lang>

<!-- ❌ 错误：不符合变量命名规范 -->
<lang>Welcome %{2user}</lang>        <!-- 不能以数字开头 -->
<lang>Welcome %{user-name}</lang>    <!-- 不能包含连字符 -->
<lang>Welcome %{user name}</lang>    <!-- 不能包含空格 -->
```

---

### 3. 建议使用默认值

```php
<!-- 推荐：使用默认值防止变量未定义 -->
<?php 
$min = $min ?? 8;
$max = $max ?? 20;
?>
<lang>Length: %{min}-%{max}</lang>
```

---

## 📊 对比：手动 vs 自动

### 手动指定（传统方式）

```html
<?php $userName = 'John'; $userLevel = 'VIP'; ?>
<lang args="['userName' => $userName, 'userLevel' => $userLevel]">
    Hello %{userName}, your level is %{userLevel}
</lang>
```

**特点**：
- ✅ 明确指定参数
- ❌ 代码较长
- ❌ 需要重复变量名

---

### 自动识别（智能方式）⭐

```html
<?php $userName = 'John'; $userLevel = 'VIP'; ?>
<lang>Hello %{userName}, your level is %{userLevel}</lang>
```

**特点**：
- ✅ 代码简洁
- ✅ 自动识别变量
- ✅ 开发效率高
- ⚠️ 需要确保变量存在

---

## 🎁 最佳实践

### 1. 简单场景使用自动识别

```html
<?php $count = 10; ?>
<span><lang>Total: %{count} items</lang></span>
```

---

### 2. 复杂场景使用 args

```html
<lang args="['count' => $items->count(), 'price' => number_format($total, 2)]">
    Total: %{count} items, Price: $%{price}
</lang>
```

---

### 3. 混合使用

```html
<?php $currency = 'USD'; ?>
<lang args="['amount' => number_format($price, 2)]">
    Price: %{amount} %{currency}
</lang>
<!-- currency 自动使用 $currency 变量 -->
```

---

## 🚀 性能说明

### 自动识别的性能影响

**编译阶段**：
- 模板编译时，框架会扫描 `<lang>` 标签
- 提取占位符并生成相应的 PHP 代码
- **一次编译，多次使用**

**运行阶段**：
- 与手动指定 `args` 的性能**完全相同**
- 没有额外的运行时开销

---

## 📖 技术实现

### 编译过程

```html
<!-- 模板源码 -->
<?php $min = 8; $max = 20; ?>
<lang>Length: %{min}-%{max}</lang>

<!-- ↓ 编译后的 PHP 代码 ↓ -->

<?php $min = 8; $max = 20; ?>
<?= __('Length: %{min}-%{max}', ['min' => $min, 'max' => $max]) ?>
```

### 正则表达式

框架使用以下正则表达式识别占位符：

```php
preg_match_all('/%\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $text, $matches)
```

**匹配规则**：
- `%{` - 占位符开始
- `([a-zA-Z_][a-zA-Z0-9_]*)` - 变量名（字母或下划线开头，后跟字母、数字、下划线）
- `}` - 占位符结束

---

## ✅ 总结

### 核心优势

1. **简化代码** - 无需重复写变量名
2. **提高效率** - 减少 50% 的代码量
3. **智能识别** - 自动映射变量
4. **向后兼容** - 不影响现有代码
5. **零性能损耗** - 编译时处理

### 使用建议

- ✅ **简单场景**：使用自动识别
- ✅ **复杂计算**：使用 `args` 参数
- ✅ **混合使用**：根据需要灵活选择

---

**享受更简洁的多语言开发体验！** 🎉

