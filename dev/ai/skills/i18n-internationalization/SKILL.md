---
name: i18n-internationalization
description: Implements internationalization (i18n) in Weline Framework. CRITICAL - ALL user-facing text MUST use i18n! Use when writing __() function, <lang> tags, translation files (CSV), multilingual support, 翻译, 多语言, 国际化. NEVER use %1/%2 format - must use %{1}/%{name} with braces!
globs:
  - "**/i18n/**/*.csv"
  - "**/*.phtml"
alwaysApply: false
---

# Internationalization (i18n) in Weline Framework

This skill guides you through implementing internationalization in Weline Framework using the `__()` translation function and `<lang>` template tags.

Last Updated: 2026-02-25
Version: 2.0

## 触发条件

**ALWAYS use this skill when:**
- 用户提到"翻译" (translation)
- 用户提到"多语言" (multilingual)
- 用户提到"国际化" (internationalization)
- 用户提到"i18n"
- 用户提到"语言切换" (language switching)
- 创建或修改任何用户可见文本
- 添加带有标签、消息或UI文本的新功能

## 核心原则：所有用户可见文本必须使用 i18n

**所有用户可见文本必须使用国际化，包括但不限于：**

- ✅ **所有提示和提示信息** (prompts, hints)
- ✅ **所有按钮文本** (button labels)
- ✅ **所有表单标签** (form labels)
- ✅ **所有错误消息** (error messages)
- ✅ **所有成功消息** (success messages)
- ✅ **所有警告消息** (warning messages)
- ✅ **所有通知消息** (notifications)
- ✅ **所有工具提示** (tooltips)
- ✅ **所有占位符文本** (placeholder text)
- ✅ **所有表头** (table headers)
- ✅ **所有菜单项** (menu items)
- ✅ **所有页面标题** (page titles)
- ✅ **所有帮助文本** (help text)
- ✅ **所有验证消息** (validation messages)
- ✅ **所有确认对话框** (confirmation dialogs)
- ✅ **所有状态文本** (status text)

**规则：如果用户可见，就必须国际化！**

**永远不要硬编码任何语言的用户可见文本！**

## 快速开始

### 翻译函数：`__()`

`__()` 函数是核心翻译函数，在 PHP、JavaScript 和模板中都可用。

**函数签名：**
```php
function __(string $words, array|string|int $args = ''): string
```

**基本用法：**
```php
// 简单翻译
echo __('用户管理');
// 输出：用户管理（中文）或 User Management（英文）

// 带参数
echo __('欢迎 %{}!', $username);
echo __('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count]);
```

## 文件结构

### 翻译文件位置

翻译文件放在模块的 `i18n/` 目录下：

```
app/code/YourModule/
├── i18n/
│   ├── zh_Hans_CN.csv     # 中文翻译（默认）
│   └── en_US.csv          # 英文翻译
├── Controller/
└── ...
```

**重要**：中文必须使用 `zh_Hans_CN.csv`，不是 `zh_CN.csv`。

### CSV 文件格式

翻译文件使用带双引号的 CSV 格式：

```csv
"源文本","翻译文本"
"用户管理","用户管理"
"操作成功","操作成功"
"用户 %{name} 有 %{count} 条消息","用户 %{name} 有 %{count} 条消息"
```

**中文文件 (zh_Hans_CN.csv):**
- 源文本和翻译通常相同
- 占位符保持不变

**英文文件 (en_US.csv):**
- 提供英文翻译
- 占位符保持不变

## PHP 中的用法

### 基本翻译

```php
// 简单翻译
$title = __('用户管理');
$this->assign('title', $title);

// 在控制器中
public function index()
{
    $this->assign('title', __('用户管理'));
    $this->messageManager->addSuccess(__('操作成功'));
    return $this->fetch();
}
```

### 带占位符

**1. 通用占位符 `%{}`（单个参数）：**
```php
echo __('欢迎 %{}!', $username);
// 输出：欢迎 John!
```

**2. 数字占位符 `%{1}`, `%{2}`, ...（多个参数）：**
```php
echo __('用户 %{1} 在 %{2} 登录', [$username, $loginTime]);
// 输出：用户 John 在 2025-01-26 登录
```

**3. 命名占位符 `%{name}`, `%{count}`, ...（推荐）：**
```php
echo __('用户 %{name} 有 %{count} 条消息', [
    'name' => $username,
    'count' => $messageCount
]);
// 输出：用户 John 有 5 条消息
```

### 在控制器中

```php
namespace YourModule\Controller;

class Index extends BackendController
{
    public function postSave()
    {
        try {
            $this->model->save();
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('保存失败：%{error}', ['error' => $e->getMessage()])
            ]);
        }
    }
}
```

### 在模型中

```php
public function validate()
{
    if (empty($this->getData('name'))) {
        throw new \Exception(__('用户名不能为空'));
    }
    
    if (strlen($this->getData('password')) < 8) {
        throw new \Exception(__('密码长度不能少于 %{min} 位', ['min' => 8]));
    }
}
```

## 模板中的用法

### 重要：模板中的优先规则

**在模板中翻译时，当没有动态参数时，始终优先使用标签形式（`<lang>`）而不是 PHP 函数（`__()`）。**

**规则：**
- ✅ **无动态参数** → 使用 `<lang>` 标签（编译时翻译，性能更好）
- ✅ **有动态参数** → 使用带 `args` 属性的 `<lang>` 标签或 PHP `__()` 函数
- ❌ **不需要参数时不要使用 PHP `__()`**

**示例：**
```html
<!-- ✅ 正确：无参数，使用 <lang> 标签 -->
<h1><lang>用户管理</lang></h1>
<button><lang>保存</lang></button>

<!-- ❌ 错误：无参数，但使用 PHP 函数 -->
<h1><?= __('用户管理') ?></h1>
<button><?= __('保存') ?></button>

<!-- ✅ 正确：有参数，使用带 args 的 <lang> -->
<p><lang args="$username">欢迎 %{}!</lang></p>

<!-- ✅ 也正确：有参数，可以使用 PHP 函数 -->
<p><?= __('欢迎 %{}!', $username) ?></p>
```

### 使用 `<lang>` 标签（推荐）

**基本用法：**
```html
<h1><lang>用户管理</lang></h1>
<p><lang>欢迎使用系统</lang></p>
```

**带 `args` 属性的参数：**
```html
<!-- 单个参数 -->
<p><lang args="'John'">欢迎 %{}!</lang></p>

<!-- 数组参数 -->
<p><lang args="['John', 5]">用户 %{1} 有 %{2} 条消息</lang></p>

<!-- 命名参数（推荐） -->
<p><lang args="['name' => $username, 'count' => $message_count]">
    用户 %{name} 有 %{count} 条消息
</lang></p>

<!-- 使用模板变量 -->
<p><lang args="$username">欢迎 %{}!</lang></p>
```

**自动变量识别（智能功能）：**
```html
<?php $min = 8; $max = 20; ?>
<lang>密码长度必须在 %{min} 到 %{max} 个字符之间</lang>
<!-- 自动使用 $min 和 $max 变量 -->
```

### 使用 `@lang()` 格式

```html
<!-- 基本 -->
<title>@lang(网站维护中...)</title>

<!-- 带参数 -->
<p>@lang(欢迎 %{}!, 'John')</p>
<p>@lang(用户 %{1} 有 %{2} 条消息, ['John', 5])</p>
<p>@lang(用户 %{name} 有 %{count} 条消息, ['name' => 'John', 'count' => 5])</p>
```

### 使用 `@lang{}` 格式

```html
<!-- 基本 -->
<span>@lang{返回首页}</span>

<!-- 带参数 -->
<p>@lang{欢迎 %{}!, 'John'}</p>
<p>@lang{用户 %{name} 有 %{count} 条消息, ['name' => 'John', 'count' => 5]}</p>
```

### 在 HTML 属性中

```html
<input type="text" placeholder="<?= __('请输入用户名') ?>" />
<button title="<?= __('点击保存') ?>"><?= __('保存') ?></button>

<!-- 或使用 lang 标签 -->
<input type="text" placeholder="<lang>请输入用户名</lang>" />
```

## JavaScript 中的用法

`__()` 函数会自动注入到页面中，可以直接在 JavaScript 中使用。

### 基本用法

```javascript
// 简单翻译
console.log(__('用户管理'));
document.getElementById('title').innerText = __('用户管理');
```

### 带占位符

**1. 通用占位符：**
```javascript
console.log(__('欢迎 %{}!', username));
// 输出：欢迎 John!
```

**2. 数组参数：**
```javascript
console.log(__('用户 %{1} 有 %{2} 条消息', [username, count]));
// 输出：用户 John 有 5 条消息
```

**3. 对象参数（推荐）：**
```javascript
console.log(__('用户 %{name} 有 %{count} 条消息', {
    name: username,
    count: messageCount
}));
// 输出：用户 John 有 5 条消息
```

### 在事件处理程序中

```javascript
// 按钮点击
button.addEventListener('click', function() {
    if (confirm(__('确定要删除 %{count} 项吗？', {
        count: selectedItems.length
    }))) {
        // 删除操作
    }
});

// AJAX 回调
$.ajax({
    url: '/api/users',
    success: function(data) {
        showMessage(__('成功加载 %{count} 个用户', {
            count: data.length
        }));
    },
    error: function() {
        showError(__('加载失败，请稍后重试'));
    }
});
```

## 占位符格式

### 对比表

| 格式 | 参数类型 | 使用场景 | 示例 |
|------|---------|---------|------|
| `%{}` | String/Number | 单个参数 | `__('欢迎 %{}!', $name)` |
| `%{1}`, `%{2}` | Array | 多个参数，固定顺序 | `__('用户 %{1} 有 %{2} 条消息', [$name, $count])` |
| `%{name}`, `%{count}` | Object/Assoc Array | 多个参数，语义化（推荐） | `__('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count])` |

### ⚠️ 严重错误：永远不要使用 `%1`, `%2` 格式（不带大括号）

**❌ 错误 - 这是常见错误：**
```php
// 错误！缺少大括号 {}
__('加载失败：%1', $error);
__('共 %1 个站点，第 %2/%3 页', [$total, $page, $pages]);
__('百度返回错误：%1 - %2', [$error, $message]);
```

**✅ 正确 - 始终使用大括号 `{}`：**
```php
// 单个参数 - 使用 %{1}
__('加载失败：%{1}', $error);

// 多个参数（数组）- 使用 %{1}, %{2}, %{3}
__('共 %{1} 个站点，第 %{2}/%{3} 页', [$total, $page, $pages]);
__('百度返回错误：%{1} - %{2}', [$error, $message]);

// 推荐：命名参数更清晰
__('加载失败：%{error}', ['error' => $error]);
__('共 %{total} 个站点，第 %{page}/%{pages} 页', [
    'total' => $total,
    'page' => $page,
    'pages' => $pages
]);
```

**为什么这很重要：**
- `%1`, `%2`（不带大括号）在 Weline Framework 中不是有效的占位符
- 框架不会用参数值替换这些
- 翻译会向用户显示字面的 `%1`, `%2` 文本
- 必须使用带大括号的 `%{1}`, `%{2}` 作为数字占位符

**迁移模式：**
```php
// 之前（错误）          → 之后（正确）
__('错误：%1', $msg)      → __('错误：%{1}', $msg)
__('用户 %1 有 %2 条', [$name, $count]) 
                          → __('用户 %{1} 有 %{2} 条', [$name, $count])

// 更好：使用命名占位符
__('错误：%1', $msg)      → __('错误：%{error}', ['error' => $msg])
```

## 常见场景

### 表单元素
```html
<!-- ✅ 正确 -->
<label><lang>用户名</lang></label>
<input type="text" placeholder="<lang>请输入用户名</lang>" />
<button><lang>提交</lang></button>
<small><lang>用户名长度为3-20个字符</lang></small>

<!-- ❌ 错误 -->
<label>用户名</label>
<input type="text" placeholder="请输入用户名" />
<button>提交</button>
<small>用户名长度为3-20个字符</small>
```

### 消息和通知
```php
// ✅ 正确
$this->messageManager->addSuccess(__('操作成功'));
$this->messageManager->addError(__('操作失败：%{error}', ['error' => $error]));
$this->messageManager->addWarning(__('请注意：%{message}', ['message' => $msg]));

// ❌ 错误
$this->messageManager->addSuccess('操作成功');
$this->messageManager->addError('操作失败：' . $error);
```

### 工具提示和标题
```html
<!-- ✅ 正确 -->
<span title="<lang>点击查看详情</lang>"><lang>详情</lang></span>
<a href="#" title="<lang>编辑用户信息</lang>"><lang>编辑</lang></a>

<!-- ❌ 错误 -->
<span title="点击查看详情">详情</span>
<a href="#" title="编辑用户信息">编辑</a>
```

### 表头
```html
<!-- ✅ 正确 -->
<th><lang>用户名</lang></th>
<th><lang>邮箱</lang></th>
<th><lang>操作</lang></th>

<!-- ❌ 错误 -->
<th>用户名</th>
<th>邮箱</th>
<th>操作</th>
```

### 验证消息
```php
// ✅ 正确
if (empty($username)) {
    throw new \Exception(__('用户名不能为空'));
}
if (strlen($password) < 8) {
    throw new \Exception(__('密码长度不能少于 %{min} 位', ['min' => 8]));
}

// ❌ 错误
if (empty($username)) {
    throw new \Exception('用户名不能为空');
}
```

### 确认对话框
```javascript
// ✅ 正确
if (confirm(__('确定要删除 %{count} 项吗？', {count: selectedItems.length}))) {
    // 删除操作
}

// ❌ 错误
if (confirm('确定要删除' + selectedItems.length + '项吗？')) {
    // 删除操作
}
```

## 反模式：不要这样做

### 永远不要硬编码语言检查

**❌ 错误 - 硬编码语言检测：**
```php
// 这很糟糕！永远不要这样做！
$lang = State::getLangLocal();
$isEnglish = str_starts_with($lang, 'en');
return $isEnglish ? 'Free Shipping' : __('免运费');
```

**✅ 正确 - 只使用 `__()` 函数：**
```php
// 简单干净 - 让 i18n 系统处理翻译
return __('免运费');
```

### 永远不要跳过翻译文件

**❌ 错误：**
- 只使用 `__()` 而不创建翻译 CSV 文件
- 假设中文"会工作"而不需要 zh_Hans_CN.csv

**✅ 正确：**
1. 在代码中使用 `__('中文文本')`
2. 创建 `i18n/en_US.csv` 包含英文翻译
3. 创建 `i18n/zh_Hans_CN.csv` 包含中文（源=翻译）
4. 运行 `php bin/w i18n:collect` 收集翻译

## 最佳实践

1. **所有用户可见文本必须使用 i18n** - 没有例外！
2. **在模板中，静态文本优先使用 `<lang>` 标签**
3. **在 Hook 模板中使用 `<?= __() ?>`** - Hook 模板可能在初始翻译后加载
4. **始终使用 `__()` 函数**用于 PHP 代码中的所有用户可见文本
5. **代码中使用中文**，在 CSV 文件中提供翻译
6. **使用命名占位符**以提高清晰度和可维护性
7. **保持文本完整** - 不要拆分句子
8. **添加新翻译文本后运行 `i18n:collect`**：`php bin/w i18n:collect`
9. **实现后在多种语言中测试**
10. **更新翻译文件后清除缓存**：`php bin/w cache:flush -a`

## 常见问题

### 翻译不工作

- 检查翻译文件是否存在于 `i18n/` 目录中
- 验证 CSV 格式（双引号，逗号分隔）
- 清除缓存：`php bin/w cache:flush -a`
- 检查 CSV 文件中是否存在翻译条目
- **运行 `i18n:collect` 命令**收集所有翻译：`php bin/w i18n:collect`

### 占位符未替换

- 验证占位符格式（`%{}`、`%{1}`、`%{name}`）
- 检查参数传递是否正确
- 确保所有语言文件中的占位符一致

### JavaScript `__()` 函数未定义

- 确保框架 JavaScript 文件已加载
- 检查浏览器控制台是否有错误
- 验证框架是否正确初始化

### CSV 格式错误

- CSV 文件必须使用双引号包裹文本
- 逗号分隔两列，没有额外逗号
- 每行一个翻译对，没有换行

### Hook 模板中翻译不工作

在 Hook 模板（如 `view/hooks/`）中使用翻译时，模块的翻译可能未加载。在 Hook 模板顶部添加这些行：

```php
// 将模块添加到请求链以加载翻译
$this->request->addModule('YourModule_Name');
// 强制重新加载翻译（模块可能在初始翻译加载后添加）
\Weline\Framework\Phrase\Parser::$loaded = false;
```

## 完整工作流程

### 步骤 1：在代码中使用 `__()`

```php
// 在 PHP 类中（控制器、模型、服务等）
public function getName(): string
{
    return __('配送方式');  // 中文源文本
}

public function getOptions(): array
{
    return [
        ['label' => __('免运费'), 'value' => 'free'],
        ['label' => __('次日达'), 'value' => 'next_day'],
    ];
}
```

### 步骤 2：创建翻译文件

创建 `app/code/YourModule/i18n/en_US.csv`：
```csv
"配送方式","Shipping"
"免运费","Free Shipping"
"次日达","Next Day Delivery"
```

创建 `app/code/YourModule/i18n/zh_Hans_CN.csv`：
```csv
"配送方式","配送方式"
"免运费","免运费"
"次日达","次日达"
```

### 步骤 3：收集翻译

```bash
php bin/w i18n:collect
```

### 步骤 4：清除缓存

```bash
php bin/w cache:flush -a
```

### 步骤 5：删除编译模板（如需要）

```bash
# PowerShell
Remove-Item -Path "app/code/YourModule/view/tpl" -Recurse -Force

# Bash
rm -rf app/code/YourModule/view/tpl
```

### 步骤 6：测试

在浏览器中使用不同语言 URL 测试：
- 中文：`http://localhost/CNY/zh_Hans_CN/your-page`
- 英文：`http://localhost/CNY/en_US/your-page`

或使用 CLI：
```bash
php bin/w http:req "/CNY/en_US/your-page" "YourSearchTerm" -n=5
```

## 参考文件

- 翻译函数：`app/code/Weline/Framework/Common/functions.php`
- 翻译指南：`app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md`
- 占位符指南：`app/code/Weline/Framework/doc/i18n-placeholder-usage.md`
- Lang 标签指南：`app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md`
- I18n 模块：`app/code/Weline/I18n/doc/README.md`
- Phrase 解析器：`app/code/Weline/Framework/Phrase/Parser.php`
