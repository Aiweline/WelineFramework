# 多语言翻译占位符使用指南

本指南介绍了增强后的 `__()` 函数和 `<lang>` 标签的占位符功能，现在它们在 JavaScript 和模板中完全支持与 PHP 相同的占位符语法。

## 📚 目录

- [PHP 中的 __() 函数](#php-中的-__-函数)
- [JavaScript 中的 __() 函数](#javascript-中的-__-函数)
- [模板中的 lang 标签](#模板中的-lang-标签)
- [占位符格式说明](#占位符格式说明)
- [使用示例](#使用示例)

---

## PHP 中的 __() 函数

PHP 的 `__()` 函数支持以下占位符格式：

### 1. 通用占位符 `%{}`

```php
// 单个参数
echo __('Hello %{}', 'World');
// 输出：Hello World

echo __('Welcome %{}!', 'John');
// 输出：Welcome John!
```

### 2. 数字占位符 `%{1}`, `%{2}`, ...

```php
// 单个参数（%{1} 会自动转换为 %{}）
echo __('Hello %{1}', 'World');
// 输出：Hello World

// 多个参数
echo __('User %{1} has %{2} messages', ['John', 5]);
// 输出：User John has 5 messages

echo __('%{1} + %{2} = %{3}', [1, 2, 3]);
// 输出：1 + 2 = 3
```

### 3. 命名占位符 `%{name}`, `%{count}`, ...

```php
// 命名参数（推荐）
echo __('User %{name} has %{count} messages', [
    'name' => 'John',
    'count' => 5
]);
// 输出：User John has 5 messages

echo __('%{name}，你好！我 %{age} 岁了', [
    'name' => '杨大大',
    'age' => 23
]);
// 输出：杨大大，你好！我 23 岁了
```

---

## JavaScript 中的 __() 函数

**✨ 新特性**：JavaScript 的 `__()` 函数现在完全支持 PHP 的所有占位符格式！

### 1. 通用占位符 `%{}`

```javascript
// 单个字符串参数
console.log(__('Hello %{}', 'World'));
// 输出：Hello World

console.log(__('Welcome %{}!', 'John'));
// 输出：Welcome John!

// 单个数字参数
console.log(__('You have %{} messages', 5));
// 输出：You have 5 messages
```

### 2. 数字占位符 `%{1}`, `%{2}`, ...

```javascript
// 单个参数（%{1} 会自动转换为 %{}）
console.log(__('Hello %{1}', 'World'));
// 输出：Hello World

// 数组参数
console.log(__('User %{1} has %{2} messages', ['John', 5]));
// 输出：User John has 5 messages

console.log(__('%{1} + %{2} = %{3}', [1, 2, 3]));
// 输出：1 + 2 = 3
```

### 3. 命名占位符 `%{name}`, `%{count}`, ...

```javascript
// 对象参数（命名参数，推荐）
console.log(__('User %{name} has %{count} messages', {
    name: 'John',
    count: 5
}));
// 输出：User John has 5 messages

console.log(__('%{name}，你好！我 %{age} 岁了', {
    name: '杨大大',
    age: 23
}));
// 输出：杨大大，你好！我 23 岁了

// 在 DOM 操作中使用
document.getElementById('welcome').innerText = __('Welcome, %{username}!', {
    username: userInfo.name
});
```

### 4. 在事件处理中使用

```javascript
// 按钮点击事件
button.addEventListener('click', function() {
    alert(__('Are you sure to delete %{count} items?', {
        count: selectedItems.length
    }));
});

// AJAX 回调
$.ajax({
    url: '/api/users',
    success: function(data) {
        showMessage(__('Successfully loaded %{count} users', {
            count: data.length
        }));
    }
});
```

---

## 模板中的 lang 标签

**✨ 新特性**：`<lang>` 标签现在支持 `args` 属性来传递占位符参数！

### 基本用法

#### 1. 无参数翻译

```html
<!-- 简单文本翻译 -->
<h1><lang>Welcome</lang></h1>
<p><lang>User Management</lang></p>
```

#### 2. 使用字符串参数

```html
<!-- 单个字符串参数 -->
<p><lang args="'John'">Welcome %{}!</lang></p>
<!-- 输出：Welcome John! -->

<p><lang args="'World'">Hello %{1}</lang></p>
<!-- 输出：Hello World -->
```

#### 3. 使用数组参数

```html
<!-- 数组参数 -->
<p><lang args="['John', 5]">User %{1} has %{2} messages</lang></p>
<!-- 输出：User John has 5 messages -->

<p><lang args="[1, 2, 3]">%{1} + %{2} = %{3}</lang></p>
<!-- 输出：1 + 2 = 3 -->
```

#### 4. 使用命名参数（推荐）

```html
<!-- 命名参数（对象） -->
<p><lang args="['name' => 'John', 'count' => 5]">User %{name} has %{count} messages</lang></p>
<!-- 输出：User John has 5 messages -->

<h2><lang args="['title' => '用户管理', 'total' => 100]">%{title} (共 %{total} 个)</lang></h2>
<!-- 输出：用户管理 (共 100 个) -->
```

#### 5. 使用模板变量

```html
<!-- 使用模板变量 -->
<p><lang args="$username">Welcome %{}!</lang></p>

<p><lang args="[$user->getName(), $user->getMessageCount()]">
    User %{1} has %{2} messages
</lang></p>

<p><lang args="['name' => $user->getName(), 'count' => $messageCount]">
    User %{name} has %{count} messages
</lang></p>
```

---

## 占位符格式说明

### 支持的占位符类型

| 占位符格式 | 参数类型 | 说明 | 示例 |
|----------|---------|------|------|
| `%{}` | 字符串/数字 | 通用占位符，单个参数时使用 | `__('Hello %{}', 'World')` |
| `%{1}` | 字符串/数字 | 当只有一个参数时，自动转换为 `%{}` | `__('Hello %{1}', 'World')` |
| `%{1}`, `%{2}`, ... | 数组 | 数字索引占位符，从1开始 | `__('User %{1} has %{2} msgs', ['John', 5])` |
| `%{name}`, `%{count}`, ... | 对象/关联数组 | 命名占位符（推荐） | `__('User %{name}', {name: 'John'})` |

### 参数类型对应关系

#### PHP 参数类型

```php
// 字符串参数
__('Hello %{}', 'World')

// 数字参数
__('You have %{} messages', 5)

// 索引数组
__('User %{1} has %{2} messages', ['John', 5])

// 关联数组
__('User %{name} has %{count} messages', ['name' => 'John', 'count' => 5])

// 混合数组
__('User %{1} has %{count} messages', ['John', 'count' => 5])
```

#### JavaScript 参数类型

```javascript
// 字符串参数
__('Hello %{}', 'World')

// 数字参数
__('You have %{} messages', 5)

// 数组
__('User %{1} has %{2} messages', ['John', 5])

// 对象
__('User %{name} has %{count} messages', {name: 'John', count: 5})
```

---

## 使用示例

### 完整的前后端示例

#### Controller (PHP)

```php
<?php
namespace Weline\Example\Controller;

class Index
{
    public function execute()
    {
        $username = 'John';
        $messageCount = 5;
        
        // PHP翻译
        $welcomeMsg = __('Welcome %{}!', $username);
        $userMsg = __('User %{name} has %{count} messages', [
            'name' => $username,
            'count' => $messageCount
        ]);
        
        return [
            'username' => $username,
            'message_count' => $messageCount,
            'welcome' => $welcomeMsg,
            'user_info' => $userMsg
        ];
    }
}
```

#### Template (PHTML)

```php
<?php /** @var \Weline\Framework\View\Template $this */ ?>

<!-- 使用PHP -->
<h1><?= __('User Management') ?></h1>
<p><?= __('Welcome %{}!', $username) ?></p>
<p><?= __('User %{name} has %{count} messages', [
    'name' => $username,
    'count' => $message_count
]) ?></p>

<!-- 使用lang标签 -->
<h2><lang>User Management</lang></h2>
<p><lang args="$username">Welcome %{}!</lang></p>
<p><lang args="['name' => $username, 'count' => $message_count]">
    User %{name} has %{count} messages
</lang></p>

<!-- JavaScript中使用 -->
<script>
    // 简单翻译
    console.log(__('Hello World'));
    
    // 通用占位符
    console.log(__('Welcome %{}!', '<?= $username ?>'));
    
    // 数组参数
    console.log(__('User %{1} has %{2} messages', ['<?= $username ?>', <?= $message_count ?>]));
    
    // 对象参数（推荐）
    console.log(__('User %{name} has %{count} messages', {
        name: '<?= $username ?>',
        count: <?= $message_count ?>
    }));
    
    // 动态使用
    function showUserInfo(name, count) {
        return __('User %{name} has %{count} messages', {
            name: name,
            count: count
        });
    }
    
    // 在DOM操作中
    document.getElementById('user-info').innerText = showUserInfo('<?= $username ?>', <?= $message_count ?>);
</script>
```

### 表单验证示例

```javascript
// 表单验证消息
function validateForm() {
    let errors = [];
    
    if (!username) {
        errors.push(__('Field %{field} is required', {field: 'Username'}));
    }
    
    if (password.length < 8) {
        errors.push(__('Password must be at least %{min} characters', {min: 8}));
    }
    
    if (errors.length > 0) {
        alert(__('Found %{count} errors:\n%{errors}', {
            count: errors.length,
            errors: errors.join('\n')
        }));
        return false;
    }
    
    return true;
}
```

### DataTable 分页示例

```javascript
// DataTable 分页信息
function updatePagination(current, total, perPage) {
    let message = __('Showing page %{current} of %{total} (%{per_page} per page)', {
        current: current,
        total: total,
        per_page: perPage
    });
    
    document.querySelector('.pagination-info').innerText = message;
}

updatePagination(1, 10, 20);
// 输出：Showing page 1 of 10 (20 per page)
```

---

## 最佳实践

### 1. 选择合适的占位符格式

```php
// ✅ 推荐：使用命名占位符（语义清晰）
__('User %{name} has %{count} messages', ['name' => $name, 'count' => $count])

// ✅ 可以：使用数字占位符（参数较多时）
__('Date: %{1}-%{2}-%{3}', [$year, $month, $day])

// ✅ 可以：单个参数使用通用占位符
__('Welcome %{}!', $username)

// ❌ 不推荐：数字占位符太多时难以维护
__('%{1} %{2} %{3} %{4} %{5}', [$a, $b, $c, $d, $e])
```

### 2. 保持翻译文本的完整性

```php
// ✅ 好的做法：完整的句子
__('User %{name} has %{count} new messages')

// ❌ 不好的做法：拆分句子
__('User') . ' ' . $name . ' ' . __('has') . ' ' . $count . ' ' . __('messages')
```

### 3. 在 JavaScript 中使用对象参数

```javascript
// ✅ 推荐：使用对象（可读性强）
__('User %{name} has %{count} messages', {
    name: userName,
    count: messageCount
})

// ✅ 可以：使用数组（参数位置固定）
__('User %{1} has %{2} messages', [userName, messageCount])

// ❌ 避免：字符串拼接
'User ' + userName + ' has ' + messageCount + ' messages'
```

### 4. 在模板中使用 lang 标签

```html
<!-- ✅ 推荐：使用命名参数 -->
<lang args="['name' => $user->getName(), 'count' => $count]">
    User %{name} has %{count} messages
</lang>

<!-- ✅ 可以：使用变量 -->
<lang args="$username">Welcome %{}!</lang>

<!-- ❌ 避免：复杂的内联表达式 -->
<lang args="[$user->getData('first_name') . ' ' . $user->getData('last_name')]">
    Welcome %{}!
</lang>
```

---

## 注意事项

1. **占位符编号从1开始**：数组索引从0开始，但占位符 `%{1}` 对应数组的第一个元素（索引0）

2. **空值处理**：如果参数为 `null` 或 `undefined`，将替换为空字符串

3. **特殊字符**：占位符文本中的特殊字符会被正确转义

4. **性能考虑**：命名参数比数字参数性能略低，但在大多数场景下可以忽略

5. **兼容性**：所有旧的代码都能正常工作，新功能向后兼容

---

## 总结

通过增强 `__()` 函数和 `<lang>` 标签的占位符支持，现在可以：

✅ 在 JavaScript 中使用与 PHP 完全相同的占位符语法
✅ 在模板的 `<lang>` 标签中传递参数
✅ 使用 `%{}` 通用占位符（与 PHP 一致）
✅ 使用数字占位符 `%{1}`, `%{2}`, ...
✅ 使用命名占位符 `%{name}`, `%{count}`, ...（推荐）
✅ 向后兼容所有现有代码

这使得多语言翻译在前后端使用体验完全一致，提高了代码的可维护性和可读性！

