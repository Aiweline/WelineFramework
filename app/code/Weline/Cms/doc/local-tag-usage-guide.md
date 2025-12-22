# `<local>` 标签使用指南

## 📖 概述

`<local>` 标签是 WelineFramework 提供的数据库字段翻译标签，用于在前端和后台模板中实现字段级别的多语言翻译功能。

---

## ✨ 核心特性

1. **数据库驱动** - 翻译内容存储在数据库中
2. **点击翻译** - 自动生成翻译按钮/图标 🌍
3. **自动回退** - 无翻译时显示默认内容
4. **在线编辑** - 直接在页面上翻译，所见即所得

---

## 🎯 基本语法

### 标准格式

```html
<local model="模型类完整路径" 
       field="字段名" 
       id="记录ID的模板变量引用" 
       name="唯一标识符">
    {{显示内容的模板变量}}
</local>
```

### 属性说明

| 属性 | 类型 | 必填 | 说明 | 示例 |
|------|------|------|------|------|
| `model` | 字符串 | ✅ | LocalDescription 模型类的完整路径 | `GuoLaiRen\PageBuilder\Model\Page\LocalDescription` |
| `field` | 字符串 | ✅ | 要翻译的字段名 | `name`, `title`, `meta_title` |
| `id` | 模板变量 | ✅ | 记录ID的模板变量引用（用点号访问） | `page.page_id`, `attribute.attribute_id` |
| `name` | 字符串 | ✅ | 唯一标识符，用于区分不同翻译元素 | `page-name`, `page-title` |

---

## ⚠️ 重要规则

### ❌ 禁止在 `<local>` 标签内使用 PHP 代码

**错误示例**：
```php
<!-- ❌ 错误：不能使用 PHP 标签 -->
<local model="..." field="name" id="<?= $page->getId() ?>" name="page-name">
    <?= htmlspecialchars($page->getData('name')) ?>
</local>

<!-- ❌ 错误：id 和 name 属性不能使用 PHP 代码 -->
<local model="..." 
       field="name" 
       id="<?= $page->getId() ?>" 
       name="page-name-<?= $page->getId() ?>">
    {{page.name}}
</local>
```

**原因**：`<local>` 标签由框架的模板引擎处理，不支持嵌套 PHP 代码。

### ✅ 正确用法

**正确示例**：
```html
<!-- ✅ 正确：使用模板变量语法 -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="name" 
       id="page.page_id" 
       name="page-name">{{page.name}}</local>

<!-- ✅ 正确：使用默认值 -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="meta_title" 
       id="page.page_id" 
       name="page-meta-title">{{page.meta_title|page.title}}</local>
```

---

## 📋 完整示例

### 示例 1：PageBuilder 页面名称翻译

```html
<!-- 页面名称字段 -->
<label for="name" class="form-label">
    <?= __('页面名称') ?> <span class="text-danger">*</span>
</label>
<input type="text" 
       class="form-control" 
       id="name" 
       name="name" 
       value="<?= htmlspecialchars($page ? $page->getData('name') : '') ?>"
       required>

<!-- 翻译显示 -->
<?php if ($isEdit && $page && $page->getId()): ?>
    <div class="form-text">
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="name" 
               id="page.page_id" 
               name="page-name">{{page.name}}</local>
    </div>
<?php endif; ?>
```

### 示例 2：Eav 属性名称翻译

```html
<td class='co-name'>
    <local model="Weline\Eav\Model\EavAttribute\LocalDescription"
           field="name" 
           id="attribute.attribute_id"
           name="attribute-name">
        {{attribute.local_name|attribute.name}}
    </local>
</td>
```

### 示例 3：带默认值的翻译

```html
<!-- SEO标题：如果 meta_title 为空，显示 title -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="meta_title" 
       id="page.page_id" 
       name="page-meta-title">{{page.meta_title|page.title}}</local>
```

---

## 🔧 模板变量语法

### 1. 简单变量

```html
{{变量名}}
```

示例：
```html
{{page.name}}
{{page.title}}
{{attribute.code}}
```

### 2. 嵌套属性访问

使用 **点号 (.)** 访问对象属性：

```html
{{对象.属性名}}
```

示例：
```html
{{page.page_id}}          <!-- 访问 $page 的 page_id -->
{{attribute.attribute_id}} <!-- 访问 $attribute 的 attribute_id -->
{{user.email}}            <!-- 访问 $user 的 email -->
```

### 3. 默认值（回退值）

使用 **竖线 (|)** 指定默认值：

```html
{{变量1|变量2|默认值}}
```

示例：
```html
{{page.meta_title|page.title}}           <!-- meta_title 为空时使用 title -->
{{attribute.local_name|attribute.name}}  <!-- local_name 为空时使用 name -->
{{user.nickname|user.username|'匿名'}}    <!-- 多级回退 -->
```

---

## 🎨 实际效果

### 后台编辑页面显示

```
┌─────────────────────────────────────────┐
│ 页面名称 *                               │
│ ┌─────────────────────────────────┐    │
│ │ 关于我们                         │    │
│ └─────────────────────────────────┘    │
│ 关于我们 🌍 ← 点击翻译                   │
│                                         │
└─────────────────────────────────────────┘
```

点击 🌍 图标后：

```
┌───────────────────────────────────────┐
│ 🌍 翻译字段：页面名称                  │
│                             [✕ 关闭]  │
├───────────────────────────────────────┤
│                                       │
│ 默认语言（简体中文）:                  │
│ 关于我们                               │
│                                       │
│ English (en_US):                     │
│ ┌─────────────────────────────┐     │
│ │ About Us                    │     │
│ └─────────────────────────────┘     │
│                                       │
│ 日本語 (ja_JP):                      │
│ ┌─────────────────────────────┐     │
│ │ 私たちについて                │     │
│ └─────────────────────────────┘     │
│                                       │
│        [取消]  [保存翻译]            │
│                                       │
└───────────────────────────────────────┘
```

---

## 🏗️ 技术实现

### 1. LocalDescription 模型要求

模型必须继承 `Weline\I18n\LocalModel`：

```php
<?php

namespace GuoLaiRen\PageBuilder\Model\Page;

use Weline\I18n\LocalModel;
use GuoLaiRen\PageBuilder\Model\Page;

class LocalDescription extends LocalModel
{
    public const indexer = 'page_local_description';
    
    // 关联主表ID（必须）
    public const fields_ID = Page::fields_ID;
    
    // 多语言字段
    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
}
```

### 2. 数据库表结构

框架自动创建翻译表，结构如下：

```sql
CREATE TABLE `page_local_description` (
  `page_id` INT NOT NULL,              -- 主表记录ID
  `local_code` VARCHAR(10) NOT NULL,   -- 语言代码（en_US, zh_Hans_CN）
  `name` VARCHAR(255) NOT NULL,        -- 必须字段（框架要求）
  `title` TEXT,                        -- 其他翻译字段
  `content` TEXT,
  `meta_title` TEXT,
  `meta_description` TEXT,
  `meta_keywords` TEXT,
  PRIMARY KEY (`page_id`, `local_code`)
);
```

### 3. 工作流程

```
用户点击 🌍 翻译图标
    ↓
框架打开翻译弹窗
    ↓
加载现有翻译数据（从 page_local_description 表）
    ↓
用户填写各语言的翻译内容
    ↓
保存到数据库（UPSERT）
    ↓
前端访问时自动显示对应语言版本
    ↓
如果无翻译，显示默认语言内容（自动回退）
```

---

## 📝 最佳实践

### 1. 模板中的使用位置

#### ✅ 推荐：在字段下方显示翻译

```php
<!-- 输入框 -->
<input type="text" id="name" name="name" value="...">

<!-- 翻译显示（仅编辑模式） -->
<?php if ($isEdit && $page && $page->getId()): ?>
    <div class="form-text">
        <local model="..." field="name" id="page.page_id" name="page-name">
            {{page.name}}
        </local>
    </div>
<?php endif; ?>
```

#### ✅ 推荐：在表格单元格中显示

```html
<td class='co-name'>
    <local model="..." field="name" id="item.id" name="item-name">
        {{item.name}}
    </local>
</td>
```

#### ✅ 推荐：在前端页面显示

```html
<h1>
    <local model="..." field="title" id="page.page_id" name="page-title">
        {{page.title}}
    </local>
</h1>
```

### 2. ID 属性规范

使用**模板变量引用**，而非 PHP 代码：

```html
<!-- ✅ 正确：模板变量 -->
id="page.page_id"
id="attribute.attribute_id"
id="product.product_id"

<!-- ❌ 错误：PHP 代码 -->
id="<?= $page->getId() ?>"
id="<?= $item['id'] ?>"
```

### 3. name 属性规范

使用**简短、描述性的字符串标识符**：

```html
<!-- ✅ 推荐：描述性命名 -->
name="page-name"
name="page-title"
name="page-meta-title"
name="product-description"
name="category-name"

<!-- ❌ 避免：动态值 -->
name="page-name-<?= $id ?>"
name="item-<?= $index ?>"
```

### 4. 字段命名一致性

确保 `field` 属性与数据库字段和模型常量一致：

```php
// 模型定义
public const fields_NAME = 'name';
public const fields_TITLE = 'title';
public const fields_META_TITLE = 'meta_title';

// 数据库表
CREATE TABLE ... (
  `name` VARCHAR(255),
  `title` VARCHAR(255),
  `meta_title` TEXT
);

// 模板使用
field="name"         ✅
field="title"        ✅
field="meta_title"   ✅
```

---

## 🔄 完整示例：PageBuilder 模块

### 1. LocalDescription 模型

```php
<?php
namespace GuoLaiRen\PageBuilder\Model\Page;

use Weline\I18n\LocalModel;
use GuoLaiRen\PageBuilder\Model\Page;

class LocalDescription extends LocalModel
{
    public const indexer = 'page_local_description';
    public const fields_ID = Page::fields_ID;
    
    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
}
```

### 2. 后台编辑表单

```php
<!-- 页面名称 -->
<div class="mb-3">
    <label for="name" class="form-label">
        <?= __('页面名称') ?> <span class="text-danger">*</span>
    </label>
    <input type="text" 
           class="form-control" 
           id="name" 
           name="name" 
           value="<?= htmlspecialchars($page ? $page->getData('name') : '') ?>"
           required>
    <?php if ($isEdit && $page && $page->getId()): ?>
        <div class="form-text">
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="name" 
                   id="page.page_id" 
                   name="page-name">{{page.name}}</local>
        </div>
    <?php endif; ?>
</div>

<!-- 页面标题 -->
<div class="mb-3">
    <label for="title" class="form-label">
        <?= __('页面标题') ?> <span class="text-danger">*</span>
    </label>
    <input type="text" 
           class="form-control" 
           id="title" 
           name="title" 
           value="<?= htmlspecialchars($page ? $page->getData('title') : '') ?>"
           required>
    <?php if ($isEdit && $page && $page->getId()): ?>
        <div class="form-text">
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="title" 
                   id="page.page_id" 
                   name="page-title">{{page.title}}</local>
        </div>
    <?php endif; ?>
</div>

<!-- SEO标题 -->
<div class="mb-3">
    <label for="meta_title" class="form-label"><?= __('SEO标题') ?></label>
    <input type="text" 
           class="form-control" 
           id="meta_title" 
           name="meta_title" 
           value="<?= htmlspecialchars($page ? $page->getData('meta_title') : '') ?>">
    <?php if ($isEdit && $page && $page->getId()): ?>
        <div class="form-text">
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="meta_title" 
                   id="page.page_id" 
                   name="page-meta-title">{{page.meta_title|page.title}}</local>
        </div>
    <?php endif; ?>
</div>
```

### 3. 前端页面显示

```html
<!DOCTYPE html>
<html>
<head>
    <title>
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="meta_title" 
               id="page.page_id" 
               name="seo-title">{{page.meta_title|page.title}}</local>
    </title>
    <meta name="description" content="<local model='GuoLaiRen\PageBuilder\Model\Page\LocalDescription' field='meta_description' id='page.page_id' name='seo-desc'>{{page.meta_description}}</local>">
</head>
<body>
    <h1>
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="title" 
               id="page.page_id" 
               name="page-title">{{page.title}}</local>
    </h1>
    
    <div class="page-content">
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="content" 
               id="page.page_id" 
               name="page-content">{{page.content}}</local>
    </div>
</body>
</html>
```

---

## 🐛 常见问题

### 1. 翻译图标不显示

**问题**：`<local>` 标签没有显示翻译按钮 🌍

**原因**：
- 模型未正确继承 `LocalModel`
- 数据库表未创建
- 缓存未清理

**解决**：
```bash
# 运行模块升级
php bin/w setup:upgrade -m GuoLaiRen_PageBuilder

# 清理缓存
php bin/w cache:clear -f
```

### 2. 点击翻译没反应

**问题**：点击翻译图标无响应

**原因**：
- JavaScript 未加载
- `id` 属性格式错误（使用了 PHP 代码）
- 记录 ID 不存在

**解决**：
```html
<!-- ❌ 错误 -->
id="<?= $page->getId() ?>"

<!-- ✅ 正确 -->
id="page.page_id"
```

### 3. 翻译不显示

**问题**：保存了翻译但前端不显示

**原因**：
- 语言代码不匹配
- 缓存问题
- 字段名不一致

**解决**：
1. 检查 Cookie 中的语言代码
2. 清理缓存
3. 确认 `field` 属性与数据库字段一致

### 4. 显示 {{page.name}} 原始文本

**问题**：页面直接显示 `{{page.name}}` 而不是实际值

**原因**：
- 模板引擎未正确解析
- 变量名错误
- 数据未传递到模板

**解决**：
1. 确认变量名正确（区分大小写）
2. 检查 Controller 是否传递了数据
3. 清理模板缓存

---

## 📚 相关文档

- [翻译快速参考](./translation-quick-reference.md)
- [后台字段翻译指南](./backend-field-translation-guide.md)
- [i18n 翻译指南](./i18n-translation-guide.md)

---

## 🎉 总结

### 核心要点

1. **禁止 PHP 代码** - `<local>` 标签内只能使用模板语法
2. **使用模板变量** - `id` 和内容都使用 `{{}}` 语法
3. **点号访问属性** - `page.page_id`、`attribute.attribute_id`
4. **竖线设置默认值** - `{{page.meta_title|page.title}}`
5. **继承 LocalModel** - 翻译模型必须继承此类

### 标准模板

```html
<local model="命名空间\模型\LocalDescription" 
       field="字段名" 
       id="对象.主键字段" 
       name="标识符">{{对象.字段名|默认值}}</local>
```

### 记住这个

```
<local> 标签 = 数据库翻译 + 点击编辑 + 自动回退
```

现在您可以轻松为任何字段添加多语言翻译功能了！🌍✨

