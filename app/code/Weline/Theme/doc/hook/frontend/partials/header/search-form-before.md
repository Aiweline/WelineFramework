# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::search-form-before`
- **显示名称**：页头搜索表单之前
- **功能说明**：在渲染页头搜索表单之前触发，允许其他模块在搜索表单开始处注入内容（如分类下拉菜单等）。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/partials/header/search-form-before.phtml`

## 使用场景

- 在搜索表单之前添加分类下拉菜单
- 在搜索表单之前添加筛选选项
- 在搜索表单之前添加其他搜索相关的UI组件

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Theme/frontend/partials/header/search-form-before.phtml 文件中 -->
<?php
// 例如：添加分类下拉菜单
?>
<div class="search-category-dropdown">
    <select name="category" class="search-category-select">
        <option value="">全部分类</option>
        <!-- 分类选项 -->
    </select>
</div>
```

## 注意事项

- 此 hook 在搜索表单 `<form>` 标签开始之前执行
- 可以用于添加搜索相关的UI组件（如分类选择器、筛选器等）
- 如果没有提供内容，将显示默认的分类下拉菜单
- Hook 文件路径遵循目录层级结构：`Weline_Theme/frontend/partials/header/search-form-before.phtml`
