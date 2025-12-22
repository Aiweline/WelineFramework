# PageBuilder Local 标签翻译指南

## 📚 什么是 `<local>` 标签？

`<local>` 标签是 WelineFramework 提供的**数据库字段多语言翻译标签**，用于在前端显示数据库记录的翻译内容。

## 🔄 两种翻译方式的区别

### 1. `__()` 函数 - 静态文本翻译
用于翻译**界面固定文本**（按钮、标签、提示信息等）：

```php
<!-- 界面文本 -->
<h1><?= __('页面构建器') ?></h1>
<button><?= __('创建页面') ?></button>
```

### 2. `<local>` 标签 - 数据库字段翻译  
用于翻译**数据库存储的内容**（页面标题、页面内容、产品描述等）：

```php
<!-- 数据库内容 -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="title" 
       id="<?= $page->getId() ?>" 
       name="page-title">
    <?= $page->getData('title') ?>
</local>
```

---

## 🏗️ PageBuilder 的 LocalDescription 模型

PageBuilder 已经实现了多语言翻译模型：

**文件位置**：`app/code/GuoLaiRen/PageBuilder/Model/Page/LocalDescription.php`

```php
<?php
namespace GuoLaiRen\PageBuilder\Model\Page;

use Weline\I18n\LocalModel;
use GuoLaiRen\PageBuilder\Model\Page;

class LocalDescription extends LocalModel
{
    public const indexer = 'page_local_description';
    
    // 关联主表ID
    public const fields_ID = Page::fields_ID;
    
    // 支持翻译的字段
    public const fields_NAME = 'name';           // 页面名称
    public const fields_TITLE = 'title';         // 页面标题
    public const fields_CONTENT = 'content';     // 页面内容
    public const fields_META_TITLE = 'meta_title';           // SEO标题
    public const fields_META_DESCRIPTION = 'meta_description'; // SEO描述
    public const fields_META_KEYWORDS = 'meta_keywords';     // SEO关键词
}
```

---

## 📝 `<local>` 标签使用方法

### 基本语法

```php
<local model="模型类名" 
       field="字段名" 
       id="记录ID" 
       name="唯一标识">
    默认内容（当前语言没有翻译时显示）
</local>
```

### 属性说明

| 属性 | 说明 | 必填 | 示例 |
|------|------|------|------|
| `model` | LocalModel 类名 | 是 | `GuoLaiRen\PageBuilder\Model\Page\LocalDescription` |
| `field` | 要翻译的字段名 | 是 | `title`, `content`, `name` |
| `id` | 记录的主键ID | 是 | `<?= $page->getId() ?>` |
| `name` | 唯一标识（用于区分页面上的多个标签） | 是 | `page-title`, `page-content` |

---

## 🎯 实际使用示例

### 示例 1：翻译页面标题

```php
<?php
/**@var \GuoLaiRen\PageBuilder\Model\Page $page */
?>

<!-- 页面标题翻译 -->
<h1>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="title" 
           id="<?= $page->getId() ?>" 
           name="page-title-<?= $page->getId() ?>">
        <?= htmlspecialchars($page->getData('title')) ?>
    </local>
</h1>
```

### 示例 2：翻译页面内容

```php
<!-- 页面内容翻译 -->
<div class="page-content">
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="content" 
           id="<?= $page->getId() ?>" 
           name="page-content-<?= $page->getId() ?>">
        <?= $page->getData('content') ?>
    </local>
</div>
```

### 示例 3：翻译 SEO 元信息

```php
<!-- SEO 标题 -->
<title>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="meta_title" 
           id="<?= $page->getId() ?>" 
           name="page-meta-title-<?= $page->getId() ?>">
        <?= htmlspecialchars($page->getData('meta_title') ?: $page->getData('title')) ?>
    </local>
</title>

<!-- SEO 描述 -->
<meta name="description" content="<local model='GuoLaiRen\PageBuilder\Model\Page\LocalDescription' 
                                          field='meta_description' 
                                          id='<?= $page->getId() ?>' 
                                          name='page-meta-desc-<?= $page->getId() ?>'>
    <?= htmlspecialchars($page->getData('meta_description')) ?>
</local>">
```

### 示例 4：列表页中使用

```php
<?php foreach ($pages as $page): ?>
    <div class="page-item">
        <!-- 页面名称 -->
        <h3>
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="name" 
                   id="<?= $page->getId() ?>" 
                   name="page-name-<?= $page->getId() ?>">
                <?= htmlspecialchars($page->getData('name')) ?>
            </local>
        </h3>
        
        <!-- 页面标题 -->
        <p>
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="title" 
                   id="<?= $page->getId() ?>" 
                   name="page-title-<?= $page->getId() ?>">
                <?= htmlspecialchars($page->getData('title')) ?>
            </local>
        </p>
    </div>
<?php endforeach; ?>
```

---

## 🔧 完整的模板示例

### 前端页面显示模板

```php
<?php
/**@var \Weline\Framework\View\Template $this */
/**@var \GuoLaiRen\PageBuilder\Model\Page $page */
$page = $this->getData('page');
?>

<!DOCTYPE html>
<html lang="<?= \Weline\Framework\Http\Cookie::getLang() ?>">
<head>
    <meta charset="UTF-8">
    
    <!-- SEO 标题翻译 -->
    <title>
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="meta_title" 
               id="<?= $page->getId() ?>" 
               name="seo-title-<?= $page->getId() ?>">
            <?= htmlspecialchars($page->getData('meta_title') ?: $page->getData('title')) ?>
        </local>
    </title>
    
    <!-- SEO 描述翻译 -->
    <meta name="description" content="<local model='GuoLaiRen\PageBuilder\Model\Page\LocalDescription' 
                                              field='meta_description' 
                                              id='<?= $page->getId() ?>' 
                                              name='seo-desc-<?= $page->getId() ?>'>
        <?= htmlspecialchars($page->getData('meta_description')) ?>
    </local>">
    
    <!-- SEO 关键词翻译 -->
    <meta name="keywords" content="<local model='GuoLaiRen\PageBuilder\Model\Page\LocalDescription' 
                                           field='meta_keywords' 
                                           id='<?= $page->getId() ?>' 
                                           name='seo-keywords-<?= $page->getId() ?>'>
        <?= htmlspecialchars($page->getData('meta_keywords')) ?>
    </local>">
</head>
<body>
    <div class="container">
        <!-- 页面标题 - 使用界面文本翻译 -->
        <div class="page-header">
            <h1>
                <!-- 使用 local 标签翻译数据库内容 -->
                <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                       field="title" 
                       id="<?= $page->getId() ?>" 
                       name="page-title-<?= $page->getId() ?>">
                    <?= htmlspecialchars($page->getData('title')) ?>
                </local>
            </h1>
            
            <!-- 使用 __() 函数翻译静态文本 -->
            <div class="meta">
                <span><?= __('发布时间：') ?><?= $page->getData('create_time') ?></span>
            </div>
        </div>
        
        <!-- 页面内容翻译 -->
        <div class="page-content">
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="content" 
                   id="<?= $page->getId() ?>" 
                   name="page-content-<?= $page->getId() ?>">
                <?= $page->getData('content') ?>
            </local>
        </div>
    </div>
</body>
</html>
```

---

## 🎨 后台翻译界面

当使用 `<local>` 标签后，框架会自动在标签旁边显示一个**翻译按钮**（仅后台可见），点击即可打开翻译对话框。

### 翻译按钮特点

- 📝 **仅后台显示**：前端用户看不到翻译按钮
- 🔐 **权限控制**：需要登录后台才能翻译
- 🌍 **多语言支持**：可以为每个语言单独翻译
- 💾 **自动保存**：翻译后自动保存到数据库

### 翻译流程

1. 后台登录后访问带有 `<local>` 标签的页面
2. 标签旁边会显示 🌍 翻译图标
3. 点击图标打开翻译对话框
4. 选择目标语言
5. 输入翻译内容
6. 保存

---

## 📊 数据库结构

翻译数据存储在 `page_local_description` 表中：

| 字段 | 类型 | 说明 |
|------|------|------|
| `page_id` | INT | 关联的页面ID |
| `local_code` | VARCHAR | 语言代码（如：zh_Hans_CN, en_US） |
| `name` | VARCHAR | 页面名称翻译 |
| `title` | VARCHAR | 页面标题翻译 |
| `content` | TEXT | 页面内容翻译 |
| `meta_title` | VARCHAR | SEO标题翻译 |
| `meta_description` | TEXT | SEO描述翻译 |
| `meta_keywords` | VARCHAR | SEO关键词翻译 |

### 查询翻译数据

```php
use GuoLaiRen\PageBuilder\Model\Page\LocalDescription;

// 获取特定语言的翻译
$translation = LocalDescription::getInstance()
    ->where('page_id', $pageId)
    ->where('local_code', 'en_US')
    ->find()
    ->fetch();

// 获取页面的所有翻译
$translations = LocalDescription::getInstance()
    ->where('page_id', $pageId)
    ->select()
    ->fetch()
    ->getItems();
```

---

## 🔄 混合使用示例

在实际项目中，通常需要混合使用 `__()` 函数和 `<local>` 标签：

```php
<?php
/**@var \GuoLaiRen\PageBuilder\Model\Page $page */
?>

<div class="page-container">
    <!-- 静态文本：使用 __() -->
    <div class="breadcrumb">
        <a href="/"><?= __('首页') ?></a> / 
        <a href="/pages"><?= __('页面列表') ?></a> / 
        <span class="current">
            <!-- 数据库内容：使用 <local> -->
            <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
                   field="name" 
                   id="<?= $page->getId() ?>" 
                   name="breadcrumb-name">
                <?= htmlspecialchars($page->getData('name')) ?>
            </local>
        </span>
    </div>
    
    <!-- 页面标题：数据库内容 -->
    <h1>
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="title" 
               id="<?= $page->getId() ?>" 
               name="page-title">
            <?= htmlspecialchars($page->getData('title')) ?>
        </local>
    </h1>
    
    <!-- 发布信息：静态文本 + 动态数据 -->
    <div class="meta">
        <?= __('发布时间：') ?><?= $page->getData('create_time') ?>
        <?= __('作者：') ?><?= $page->getData('author') ?>
    </div>
    
    <!-- 页面内容：数据库内容 -->
    <div class="content">
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="content" 
               id="<?= $page->getId() ?>" 
               name="page-content">
            <?= $page->getData('content') ?>
        </local>
    </div>
    
    <!-- 操作按钮：静态文本 -->
    <div class="actions">
        <button><?= __('分享') ?></button>
        <button><?= __('收藏') ?></button>
        <button><?= __('打印') ?></button>
    </div>
</div>
```

---

## ⚙️ 高级用法

### 1. 动态 ID

```php
<!-- 使用变量作为 ID -->
<?php $pageId = $page->getId(); ?>
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="title" 
       id="<?= $pageId ?>" 
       name="page-title-<?= $pageId ?>">
    <?= htmlspecialchars($page->getData('title')) ?>
</local>
```

### 2. 嵌套在循环中

```php
<?php foreach ($pages as $index => $page): ?>
    <div class="page-item">
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="title" 
               id="<?= $page->getId() ?>" 
               name="list-page-title-<?= $index ?>">
            <?= htmlspecialchars($page->getData('title')) ?>
        </local>
    </div>
<?php endforeach; ?>
```

### 3. 条件显示

```php
<?php if ($page->getData('meta_title')): ?>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="meta_title" 
           id="<?= $page->getId() ?>" 
           name="seo-title">
        <?= htmlspecialchars($page->getData('meta_title')) ?>
    </local>
<?php else: ?>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="title" 
           id="<?= $page->getId() ?>" 
           name="fallback-title">
        <?= htmlspecialchars($page->getData('title')) ?>
    </local>
<?php endif; ?>
```

---

## 🎯 最佳实践

### 1. Name 属性命名规范

为了避免 ID 冲突，建议使用以下命名格式：

```php
<!-- 格式：{用途}-{字段}-{记录ID} -->
name="page-title-<?= $page->getId() ?>"
name="page-content-<?= $page->getId() ?>"
name="seo-meta-title-<?= $page->getId() ?>"
```

### 2. 默认内容设置

标签内的内容是**默认值**（当没有翻译时显示）：

```php
<local model="..." field="title" id="1" name="title-1">
    这里是默认内容，没有翻译时显示
</local>
```

### 3. HTML 特殊字符处理

对于可能包含特殊字符的内容，使用 `htmlspecialchars()`：

```php
<local model="..." field="title" id="<?= $page->getId() ?>" name="title">
    <?= htmlspecialchars($page->getData('title')) ?>
</local>
```

### 4. SEO 优化

在 `<head>` 中使用翻译标签时，注意单引号和双引号：

```php
<!-- 正确：使用单引号在属性值中 -->
<meta name="description" content="<local model='...' field='meta_description' id='1' name='desc'>
    <?= htmlspecialchars($page->getData('meta_description')) ?>
</local>">
```

---

## 🆚 选择合适的翻译方式

| 场景 | 使用方式 | 示例 |
|------|---------|------|
| 界面固定文本 | `__()` 函数 | 按钮文字、标签、菜单 |
| 数据库内容 | `<local>` 标签 | 页面标题、页面内容、产品描述 |
| 配置项 | `__()` 函数 | 系统设置、选项名称 |
| 用户输入数据 | `<local>` 标签 | 用户创建的页面、文章 |
| 错误提示 | `__()` 函数 | 表单验证、异常消息 |

---

## 🔧 故障排除

### 1. 翻译按钮不显示

**原因**：可能是前端访问，翻译按钮只在后台显示。

**解决**：使用后台账号登录后访问。

### 2. 翻译保存失败

**原因**：可能是权限不足或数据库表不存在。

**解决**：
```bash
# 运行模块升级
php bin/w setup:upgrade -m GuoLaiRen_PageBuilder

# 清理缓存
php bin/w cache:clear -f
```

### 3. Name 属性冲突

**错误**：`local标签ID不允许重复！`

**解决**：确保每个 `<local>` 标签的 `name` 属性在页面中唯一：

```php
<!-- 错误：重复的 name -->
<local name="title">...</local>
<local name="title">...</local>

<!-- 正确：唯一的 name -->
<local name="title-1">...</local>
<local name="title-2">...</local>
```

---

## 📚 相关文档

- [i18n 翻译指南](./i18n-translation-guide.md) - `__()` 函数使用
- [Editor.js 更新日志](./CHANGELOG-EDITORJS.md) - 编辑器集成说明
- [示例内容说明](./README-EXAMPLES.md) - 示例页面使用

---

## 🎉 总结

- **`__()` 函数**：用于界面静态文本翻译
- **`<local>` 标签**：用于数据库字段内容翻译
- **混合使用**：根据场景选择合适的翻译方式
- **LocalDescription 模型**：PageBuilder 已完整支持
- **自动翻译界面**：后台点击即可翻译

现在你可以轻松实现 PageBuilder 页面内容的多语言支持了！🌍

