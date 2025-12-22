# PageBuilder 多语言翻译指南

本指南详细说明如何在 PageBuilder 模块中使用 WelineFramework 的多语言翻译功能。

## 📚 目录

- [翻译函数使用](#翻译函数使用)
- [模板中的翻译](#模板中的翻译)
- [JavaScript 中的翻译](#javascript-中的翻译)
- [创建翻译文件](#创建翻译文件)
- [收集翻译词条](#收集翻译词条)
- [最佳实践](#最佳实践)

---

## 翻译函数使用

### 1. `__()` 函数

WelineFramework 提供了 `__()` 函数用于翻译文本。

#### 基本用法

```php
// 简单翻译
echo __('页面构建器');
// 输出：页面构建器（中文环境）
// 输出：Page Builder（英文环境）
```

#### 带参数的翻译

```php
// 单个参数
echo __('欢迎 %{1}', 'John');
// 输出：欢迎 John

// 多个参数（数组索引）
echo __('用户 %{1} 有 %{2} 条消息', ['John', 5]);
// 输出：用户 John 有 5 条消息

// 命名参数（推荐）
echo __('用户 %{name} 有 %{count} 条消息', [
    'name' => 'John',
    'count' => 5
]);
// 输出：用户 John 有 5 条消息
```

#### 函数定义

```php
/**
 * 翻译函数
 * @param string $words 要翻译的文本
 * @param array|string|int $args 参数（可选）
 * @return string 翻译后的文本
 */
function __(string $words, array|string|int $args = ''): string
```

---

## 模板中的翻译

### PHP 模板 (.phtml)

#### 基本翻译

```php
<!-- 标题翻译 -->
<h1><?= __('页面构建器') ?></h1>

<!-- 按钮文本 -->
<button><?= __('创建页面') ?></button>

<!-- 提示信息 -->
<div class="alert">
    <?= __('页面创建成功！') ?>
</div>
```

#### 带参数的翻译

```php
<!-- 单个参数 -->
<span><?= __('欢迎，%{1}！', $username) ?></span>

<!-- 多个参数 -->
<p><?= __('共有 %{1} 个页面，%{2} 个已发布', [$totalPages, $publishedPages]) ?></p>

<!-- 命名参数 -->
<p><?= __('页面 "%{name}" 创建于 %{date}', [
    'name' => $pageName,
    'date' => $createDate
]) ?></p>
```

#### 表单标签翻译

```php
<!-- 表单字段标签 -->
<label for="handle">
    <?= __('页面句柄') ?> <span class="text-danger">*</span>
</label>

<!-- 占位符文本 -->
<input type="text" 
       placeholder="<?= __('输入页面名称') ?>"
       name="name">

<!-- 帮助文本 -->
<div class="form-text">
    <?= __('页面的唯一标识符，用于URL，只能包含小写字母、数字和连字符') ?>
</div>
```

#### 实际示例（PageBuilder 表单）

```php
<!-- app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Index/form.phtml -->

<!-- 页面标题 -->
<h4 class="mb-sm-0">
    <?= $this->getData('page_title') ?: __('页面表单') ?>
</h4>

<!-- 面包屑导航 -->
<li class="breadcrumb-item">
    <a href="<?= $this->getBackendUrl('*/backend/index/index') ?>">
        <?= __('页面构建器') ?>
    </a>
</li>

<!-- 卡片标题 -->
<h4 class="card-title mb-4">
    <i class="mdi mdi-information-outline"></i> <?= __('基本信息') ?>
</h4>

<!-- 字段标签 -->
<label for="handle" class="form-label">
    <?= __('页面句柄') ?> <span class="text-danger">*</span>
</label>

<!-- 下拉选项 -->
<option value=""><?= __('请选择页面类型') ?></option>

<!-- 按钮 -->
<button type="submit" class="btn btn-primary">
    <i class="mdi mdi-content-save"></i> 
    <?= $isEdit ? __('更新页面') : __('创建页面') ?>
</button>

<!-- 警告消息 -->
<?php if (!empty($missingTranslations)): ?>
    <?= __('页面还有以下语言未翻译：%{1}', implode(', ', $missingTranslations)) ?>
<?php endif; ?>
```

---

## JavaScript 中的翻译

### 前端翻译函数

框架在前端也提供了 `__()` 函数，使用方式与 PHP 相同。

#### 基本用法

```javascript
// 简单翻译
console.log(__('页面构建器'));
// 输出：页面构建器

// 带参数
alert(__('确定要删除 %{1} 吗？', pageName));

// 命名参数
showMessage('success', __('已保存 %{count} 个页面', {count: 5}));
```

#### 实际示例

```javascript
// 确认对话框
if (confirm(__('确定要删除这个页面吗？此操作不可恢复。'))) {
    deletePage();
}

// 表单验证消息
if (!pageEditor) {
    alert(__('编辑器未初始化，请刷新页面重试'));
    return;
}

// 保存成功提示
showMessage('success', __('页面内容已保存'));

// 错误提示
console.error(__('保存编辑器数据失败: %{1}', error.message));

// Ajax 请求错误处理
fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(__('操作成功'));
        } else {
            alert(__('操作失败: %{reason}', {reason: data.message}));
        }
    })
    .catch(error => {
        alert(__('网络错误，请稍后重试'));
    });
```

---

## 创建翻译文件

### 1. 创建 i18n 目录

在模块根目录下创建 `i18n` 目录：

```bash
mkdir -p app/code/GuoLaiRen/PageBuilder/i18n
```

### 2. 创建翻译 CSV 文件

#### 中文翻译文件

创建 `app/code/GuoLaiRen/PageBuilder/i18n/zh_Hans_CN.csv`：

```csv
"页面构建器","页面构建器"
"页面管理","页面管理"
"表单提交管理","表单提交管理"
"创建页面","创建页面"
"编辑页面","编辑页面"
"删除页面","删除页面"
"基本信息","基本信息"
"SEO设置","SEO设置"
"多语言设置","多语言设置"
"页面句柄","页面句柄"
"页面类型","页面类型"
"页面名称","页面名称"
"页面标题","页面标题"
"页面内容","页面内容"
"默认语言","默认语言"
"支持的语言翻译","支持的语言翻译"
"请选择页面类型","请选择页面类型"
"请输入页面名称","请输入页面名称"
"使用 Editor.js 所见即所得编辑器，数据以 JSON 格式存储，支持多语言翻译","使用 Editor.js 所见即所得编辑器，数据以 JSON 格式存储，支持多语言翻译"
"选择页面的主要语言版本","选择页面的主要语言版本"
"选择后需要在编辑页面完善对应语言的翻译内容","选择后需要在编辑页面完善对应语言的翻译内容"
"页面创建成功！","页面创建成功！"
"页面创建失败！","页面创建失败！"
"页面更新成功！","页面更新成功！"
"页面更新失败！","页面更新失败！"
"页面删除成功！","页面删除成功！"
"页面删除失败！","页面删除失败！"
"页面还有以下语言未翻译：%{1}","页面还有以下语言未翻译：%{1}"
"确定要删除这个页面吗？此操作不可恢复。","确定要删除这个页面吗？此操作不可恢复。"
```

#### 英文翻译文件

创建 `app/code/GuoLaiRen/PageBuilder/i18n/en_US.csv`：

```csv
"页面构建器","Page Builder"
"页面管理","Page Management"
"表单提交管理","Form Submissions"
"创建页面","Create Page"
"编辑页面","Edit Page"
"删除页面","Delete Page"
"基本信息","Basic Information"
"SEO设置","SEO Settings"
"多语言设置","Multilingual Settings"
"页面句柄","Page Handle"
"页面类型","Page Type"
"页面名称","Page Name"
"页面标题","Page Title"
"页面内容","Page Content"
"默认语言","Default Language"
"支持的语言翻译","Supported Translations"
"请选择页面类型","Please select page type"
"请输入页面名称","Please enter page name"
"使用 Editor.js 所见即所得编辑器，数据以 JSON 格式存储，支持多语言翻译","Use Editor.js WYSIWYG editor, data stored in JSON format, supports multilingual translation"
"选择页面的主要语言版本","Select the main language version of the page"
"选择后需要在编辑页面完善对应语言的翻译内容","After selection, you need to complete the translation content in the edit page"
"页面创建成功！","Page created successfully!"
"页面创建失败！","Failed to create page!"
"页面更新成功！","Page updated successfully!"
"页面更新失败！","Failed to update page!"
"页面删除成功！","Page deleted successfully!"
"页面删除失败！","Failed to delete page!"
"页面还有以下语言未翻译：%{1}","The following languages are not translated: %{1}"
"确定要删除这个页面吗？此操作不可恢复。","Are you sure you want to delete this page? This action cannot be undone."
```

### 3. CSV 文件格式说明

```csv
"原文","译文"
```

- **第一列**：原文（代码中 `__()` 函数的参数）
- **第二列**：译文（翻译后的文本）
- 使用双引号包裹
- 逗号分隔
- 每行一个翻译条目

---

## 收集翻译词条

### 自动收集

框架提供了命令行工具自动收集代码中的翻译词条：

```bash
# 收集所有模块的翻译词条
php bin/w i18n:collect

# 收集特定模块的翻译词条
php bin/w i18n:collect GuoLaiRen_PageBuilder
```

### 收集原理

框架会扫描以下文件中的 `__()` 函数调用：

- **PHP 文件** (`.php`)
- **模板文件** (`.phtml`)
- **JavaScript 文件** (`.js`)

**正则表达式匹配**：
```
/__\(['"](.+?)['"]\)/
```

### 自动生成 CSV

收集后会自动生成或更新 `i18n/*.csv` 文件：

```bash
app/code/GuoLaiRen/PageBuilder/i18n/
├── zh_Hans_CN.csv
├── en_US.csv
└── ja_JP.csv
```

---

## 最佳实践

### 1. 翻译词条命名规范

✅ **推荐做法**：
```php
// 使用完整的、描述性的短语
__('页面构建器')
__('创建新页面')
__('确定要删除吗？')
```

❌ **不推荐**：
```php
// 避免使用单个单词或缩写
__('页面')  // 太模糊
__('创建')  // 缺乏上下文
__('OK')    // 过于简单
```

### 2. 参数化翻译

✅ **推荐做法**：
```php
// 使用命名参数，更清晰
__('用户 %{username} 有 %{count} 条消息', [
    'username' => $user,
    'count' => $msgCount
]);
```

❌ **不推荐**：
```php
// 避免使用数字索引，不够清晰
__('用户 %{1} 有 %{2} 条消息', [$user, $msgCount]);
```

### 3. 避免拼接翻译

✅ **推荐做法**：
```php
// 将完整句子作为一个翻译单元
__('页面 "%{name}" 创建于 %{date}', [
    'name' => $pageName,
    'date' => $date
]);
```

❌ **不推荐**：
```php
// 避免拼接多个翻译片段
echo __('页面 "') . $pageName . __('" 创建于 ') . $date;
```

### 4. 统一术语

在整个应用中使用一致的术语：

```php
// 统一使用"页面构建器"
__('页面构建器')  // ✅

// 不要混用
__('页面生成器')  // ❌
__('页面编辑器')  // ❌
```

### 5. 提供上下文信息

对于可能产生歧义的词语，在注释中提供上下文：

```php
// 按钮文本
__('保存')  // Context: Save button in form

// 状态文本
__('已保存')  // Context: Status message after saving
```

### 6. HTML 标签处理

✅ **推荐做法**：
```php
// 将 HTML 放在翻译外
echo '<strong>' . __('重要提示') . '</strong>';
```

或者：
```php
// 在翻译内包含简单的 HTML
__('<strong>重要提示：</strong>请填写所有必填字段');
```

### 7. 单复数处理

对于需要单复数的情况，使用参数化：

```php
// 根据数量显示不同文本
if ($count == 1) {
    echo __('有 %{count} 个页面', ['count' => $count]);
} else {
    echo __('有 %{count} 个页面', ['count' => $count]);
}

// 或使用条件翻译
echo $count == 1 
    ? __('1 个页面') 
    : __('%{count} 个页面', ['count' => $count]);
```

---

## 完整示例

### 创建带翻译的模板

```php
<?php
/**@var \Weline\Framework\View\Template $this */
?>
<div class="page-builder-form">
    <!-- 页面标题 -->
    <h1><?= __('页面构建器') ?></h1>
    
    <!-- 表单 -->
    <form method="post" action="<?= $this->getBackendUrl('*/backend/index/save') ?>">
        <!-- 基本信息卡片 -->
        <div class="card">
            <h4><?= __('基本信息') ?></h4>
            
            <!-- 页面名称 -->
            <div class="form-group">
                <label><?= __('页面名称') ?> *</label>
                <input type="text" 
                       name="name" 
                       placeholder="<?= __('请输入页面名称') ?>"
                       required>
                <small><?= __('显示在页面列表中的名称') ?></small>
            </div>
            
            <!-- 默认语言 -->
            <div class="form-group">
                <label><?= __('默认语言') ?> *</label>
                <select name="default_locale">
                    <option value=""><?= __('请选择默认语言') ?></option>
                    <option value="zh_Hans_CN"><?= __('简体中文') ?></option>
                    <option value="en_US"><?= __('English') ?></option>
                </select>
                <small><?= __('选择页面的主要语言版本') ?></small>
            </div>
        </div>
        
        <!-- 按钮 -->
        <button type="submit" class="btn btn-primary">
            <i class="mdi mdi-content-save"></i>
            <?= __('保存页面') ?>
        </button>
        <a href="<?= $this->getBackendUrl('*/backend/index/index') ?>" 
           class="btn btn-secondary">
            <?= __('返回列表') ?>
        </a>
    </form>
</div>

<script>
// JavaScript 翻译
document.querySelector('form').addEventListener('submit', function(e) {
    if (!confirm(__('确定要保存吗？'))) {
        e.preventDefault();
        return false;
    }
});

// 显示成功消息
function showSuccessMessage() {
    alert(__('页面保存成功！'));
}
</script>
```

---

## 常见问题

### 1. 翻译不生效？

**检查清单**：
- ✅ 确认 CSV 文件格式正确
- ✅ 确认文件编码为 UTF-8
- ✅ 运行 `php bin/w cache:clear` 清理缓存
- ✅ 检查当前语言设置

### 2. 参数替换不工作？

确保使用正确的占位符格式：

```php
// ✅ 正确
__('欢迎 %{1}', 'John')
__('欢迎 %{name}', ['name' => 'John'])

// ❌ 错误
__('欢迎 $name', 'John')  // 不是有效占位符
__('欢迎 {name}', 'John')  // 缺少 %
```

### 3. 如何更新已有的翻译？

1. 修改 CSV 文件中的译文
2. 清理缓存：`php bin/w cache:clear`
3. 刷新页面查看效果

---

## 相关命令

```bash
# 收集翻译词条
php bin/w i18n:collect

# 收集特定模块
php bin/w i18n:collect GuoLaiRen_PageBuilder

# 清理翻译缓存
php bin/w cache:clear

# 查看当前语言设置
php bin/w i18n:status
```

---

## 总结

1. **使用 `__()` 函数**进行翻译
2. **创建 i18n/语言代码.csv** 文件
3. **使用命名参数**提高可读性
4. **保持术语统一**
5. **定期收集**翻译词条
6. **及时清理缓存**

通过遵循本指南，你可以轻松实现 PageBuilder 模块的多语言支持！🌍

