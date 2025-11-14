# Block 标签以及其他框架标签简介

## 摘要

本文档介绍 WelineFramework 中的 Block 标签以及其他常用框架标签的使用方法。

## 什么是 Block 标签

Block 标签是 WelineFramework 提供的布局管理标签，用于定义可复用的页面区块。Block 允许开发者将页面拆分为多个独立的区块，每个区块可以独立管理、替换和扩展。

## 为什么需要 Block 标签

Block 标签提供了以下优势：
- **布局管理**：将页面拆分为多个区块，便于管理
- **模块化**：每个区块可以独立开发和维护
- **可扩展性**：支持区块的替换和扩展
- **代码复用**：区块可以在多个页面中复用

## Block 标签使用

### 定义 Block

在模板中使用 `w:block` 标签定义区块：

```php
<w:block name="header">
    <header>
        <h1>网站标题</h1>
    </header>
</w:block>

<w:block name="content">
    <main>
        主要内容
    </main>
</w:block>

<w:block name="footer">
    <footer>
        页脚信息
    </footer>
</w:block>
```

### 引用 Block

在其他模板中引用已定义的 Block：

```php
<?= $this->getTag('w:block', ['name' => 'header']) ?>
<?= $this->getTag('w:block', ['name' => 'content']) ?>
<?= $this->getTag('w:block', ['name' => 'footer']) ?>
```

### 替换 Block

子模板可以替换父模板中的 Block：

```php
<w:block name="content">
    <!-- 替换父模板中的 content 区块 -->
    <main>
        新的内容
    </main>
</w:block>
```

## 其他框架标签

### 1. w:if - 条件标签

根据条件显示内容：

```php
<w:if condition="$show">
    <p>条件为真时显示</p>
</w:if>
```

### 2. w:foreach - 循环标签

遍历数组：

```php
<w:foreach items="$items" item="item">
    <div><?= htmlspecialchars($item['name'] ?? '') ?></div>
</w:foreach>
```

### 3. w:include - 包含标签

包含其他模板文件：

```php
<w:include file="path/to/template.phtml" />
```

### 4. w:url - URL 生成标签

生成 URL：

```php
<w:url path="module/controller/action" params="id=1" />
```

### 5. w:translate - 翻译标签

翻译文本：

```php
<w:translate>Hello World</w:translate>
```

## 查看其他模块的标签

框架标签由各个模块提供，可以在以下位置查看：

1. **Weline_Taglib 模块**：核心标签库模块
2. **各模块的 Taglib 目录**：每个模块可以定义自己的标签
3. **标签管理界面**：在后台可以查看所有已注册的标签

### 查看标签文档

在 `app/code/Weline/Taglib/doc/` 目录下查看标签文档，或在各模块的 `Taglib` 目录下查看标签实现。

## 后续处理

### 1. 学习更多标签

查看框架提供的其他标签，了解其用法和参数。

### 2. 创建自定义标签

根据需求创建自定义标签，参考 `06-自定义标签.md` 文档。

## 验证

### 验证 Block 标签

1. 在模板中定义 Block
2. 在其他模板中引用 Block
3. 检查 Block 是否正确渲染

### 常见问题

1. **Block 未找到**：检查 Block 名称是否正确，确保 Block 已定义
2. **标签未识别**：检查标签语法是否正确，确保使用 `w:` 前缀
3. **渲染错误**：检查标签参数是否正确，确保变量已传递

