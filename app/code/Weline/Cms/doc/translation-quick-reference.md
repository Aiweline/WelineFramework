# PageBuilder 翻译快速参考

## 🔥 两种翻译方式

### 1. `__()` 函数 - 界面文本翻译

**用途**：翻译**固定的界面文本**（按钮、标签、提示等）

**示例**：
```php
<!-- 按钮文本 -->
<button><?= __('创建页面') ?></button>

<!-- 表单标签 -->
<label><?= __('页面标题') ?> *</label>

<!-- 提示消息 -->
<?= __('页面创建成功！') ?>

<!-- 带参数 -->
<?= __('页面还有以下语言未翻译：%{1}', implode(', ', $languages)) ?>
```

**翻译文件**：`app/code/GuoLaiRen/PageBuilder/i18n/zh_Hans_CN.csv`
```csv
"创建页面","Create Page"
"页面标题","Page Title"
"页面创建成功！","Page created successfully!"
```

---

### 2. `<local>` 标签 - 数据库字段翻译

**用途**：翻译**数据库存储的内容**（页面标题、内容、SEO等）

**示例**：
```php
<!-- 页面标题 -->
<h1>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="title" 
           id="<?= $page->getId() ?>" 
           name="page-title-<?= $page->getId() ?>">
        <?= htmlspecialchars($page->getData('title')) ?>
    </local>
</h1>

<!-- 页面内容 -->
<div class="content">
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="content" 
           id="<?= $page->getId() ?>" 
           name="page-content-<?= $page->getId() ?>">
        <?= $page->getData('content') ?>
    </local>
</div>

<!-- SEO Meta -->
<title>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="meta_title" 
           id="<?= $page->getId() ?>" 
           name="seo-title-<?= $page->getId() ?>">
        <?= htmlspecialchars($page->getData('meta_title')) ?>
    </local>
</title>
```

**翻译方式**：
- 后台登录后，`<local>` 标签旁会显示 🌍 翻译按钮
- 点击按钮打开翻译对话框
- 选择语言并输入翻译内容
- 翻译数据保存在 `page_local_description` 表

---

## 📊 对比表格

| 特性 | `__()` 函数 | `<local>` 标签 |
|------|-------------|----------------|
| **用途** | 界面固定文本 | 数据库内容 |
| **示例** | 按钮、标签、提示 | 页面标题、内容 |
| **翻译文件** | CSV 文件 | 数据库表 |
| **翻译方式** | 编辑 CSV | 后台界面点击翻译 |
| **缓存** | 需要清理缓存 | 实时生效 |
| **适合内容** | 静态文本 | 动态内容 |

---

## 🎯 使用场景

### 使用 `__()` 的场景：
- ✅ 按钮文字："保存"、"取消"、"删除"
- ✅ 表单标签："页面名称"、"页面类型"
- ✅ 菜单项："页面构建器"、"表单提交"
- ✅ 错误提示："页面不存在"、"保存失败"
- ✅ 系统消息："操作成功"、"确认删除"

### 使用 `<local>` 的场景：
- ✅ 页面标题（用户输入的）
- ✅ 页面内容（Editor.js JSON）
- ✅ SEO 信息（meta_title, meta_description）
- ✅ 用户创建的任何内容
- ✅ 需要在线翻译的字段

---

## 🚀 完整示例

### 后台表单（混合使用）

```php
<div class="card">
    <!-- 卡片标题：固定文本，用 __() -->
    <h4><?= __('基本信息') ?></h4>
    
    <!-- 字段标签：固定文本，用 __() -->
    <label><?= __('页面名称') ?> *</label>
    
    <!-- 输入框占位符：固定文本，用 __() -->
    <input type="text" 
           name="name" 
           placeholder="<?= __('请输入页面名称') ?>">
    
    <!-- 帮助文本：固定文本，用 __() -->
    <div class="form-text">
        <?= __('显示在页面列表中的名称') ?>
    </div>
    
    <!-- 按钮：固定文本，用 __() -->
    <button type="submit">
        <?= __('保存页面') ?>
    </button>
</div>
```

### 前端页面（混合使用）

```php
<div class="page">
    <!-- 面包屑：固定文本，用 __() -->
    <div class="breadcrumb">
        <a href="/"><?= __('首页') ?></a> / 
        <span><?= __('页面详情') ?></span>
    </div>
    
    <!-- 页面标题：数据库内容，用 <local> -->
    <h1>
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="title" 
               id="<?= $page->getId() ?>" 
               name="page-title">
            <?= htmlspecialchars($page->getData('title')) ?>
        </local>
    </h1>
    
    <!-- 发布信息：固定文本 + 动态数据 -->
    <div class="meta">
        <?= __('发布时间：') ?><?= $page->getData('create_time') ?>
    </div>
    
    <!-- 页面内容：数据库内容，用 <local> -->
    <div class="content">
        <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
               field="content" 
               id="<?= $page->getId() ?>" 
               name="page-content">
            <?= $page->getData('content') ?>
        </local>
    </div>
    
    <!-- 操作按钮：固定文本，用 __() -->
    <button><?= __('分享') ?></button>
    <button><?= __('收藏') ?></button>
</div>
```

---

## 📝 LocalDescription 支持的字段

PageBuilder 的 `LocalDescription` 模型支持以下字段翻译：

| 字段 | 说明 | field 值 |
|------|------|----------|
| 页面名称 | 显示名称 | `name` |
| 页面标题 | 浏览器标题 | `title` |
| 页面内容 | Editor.js JSON | `content` |
| SEO 标题 | 搜索引擎标题 | `meta_title` |
| SEO 描述 | 搜索引擎描述 | `meta_description` |
| SEO 关键词 | 搜索关键词 | `meta_keywords` |

**示例**：
```php
<!-- 翻译页面名称 -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="name" 
       id="1" 
       name="page-name-1">
    默认名称
</local>

<!-- 翻译 SEO 标题 -->
<local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
       field="meta_title" 
       id="1" 
       name="seo-title-1">
    默认 SEO 标题
</local>
```

---

## 🔧 常用命令

```bash
# 收集 __() 翻译词条
php bin/w i18n:collect GuoLaiRen_PageBuilder

# 清理缓存（__() 翻译生效需要）
php bin/w cache:clear -f

# 更新模块（确保翻译表存在）
php bin/w setup:upgrade -m GuoLaiRen_PageBuilder
```

---

## 📚 详细文档

- **`__()` 函数详解**：[i18n-translation-guide.md](./i18n-translation-guide.md)
- **`<local>` 标签详解**：[local-tag-translation-guide.md](./local-tag-translation-guide.md)
- **Editor.js 集成**：[CHANGELOG-EDITORJS.md](./CHANGELOG-EDITORJS.md)

---

## ✨ 最佳实践

1. **界面文本**用 `__()`，**数据内容**用 `<local>`
2. **统一术语**，避免同一概念用不同翻译
3. **参数化翻译**，使用 `%{name}` 而不是 `%{1}`
4. **name 属性唯一**，避免页面中重复
5. **定期收集**翻译词条，保持 CSV 最新
6. **及时清理缓存**，确保翻译生效

---

## 🎉 快速上手

### 第一步：添加界面翻译
```php
<!-- 替换硬编码文本 -->
<button>创建</button>
<!-- 改为 -->
<button><?= __('创建') ?></button>
```

### 第二步：收集翻译词条
```bash
php bin/w i18n:collect GuoLaiRen_PageBuilder
```

### 第三步：翻译 CSV 文件
编辑 `i18n/en_US.csv`：
```csv
"创建","Create"
```

### 第四步：添加数据库字段翻译
```php
<!-- 原代码 -->
<h1><?= $page->getData('title') ?></h1>

<!-- 改为 -->
<h1>
    <local model="GuoLaiRen\PageBuilder\Model\Page\LocalDescription" 
           field="title" 
           id="<?= $page->getId() ?>" 
           name="title-<?= $page->getId() ?>">
        <?= htmlspecialchars($page->getData('title')) ?>
    </local>
</h1>
```

### 第五步：后台翻译
1. 登录后台
2. 访问前端页面
3. 点击 `<local>` 标签旁的翻译按钮 🌍
4. 输入翻译内容并保存

---

## 🌍 现在就开始多语言化！

您的 PageBuilder 模块已经完全支持多语言！

- ✅ 界面文本翻译：CSV 文件
- ✅ 数据库内容翻译：`<local>` 标签
- ✅ 中英文翻译文件：已创建
- ✅ 前端页面：已集成翻译标签

**下一步**：根据需要添加更多语言的 CSV 文件（日语、韩语、法语等）！

