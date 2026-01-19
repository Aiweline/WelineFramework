# Weline Frontend 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`frontend::layouts::base::head-before`
- **显示名称**：前端基础布局头部之前
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在渲染前端基础布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/frontend/layouts/base/head-before.phtml`

## 使用场景

- 在所有前端页面头部之前注入内容
- 添加全局的 meta 标签
- 注入全局的 CSS 或 JavaScript

## 示例代码

```html
<!-- 在模块的 view/hooks/frontend/layouts/base/head-before.phtml 文件中 -->
<meta name="custom-meta" content="custom-value">
```

## 注意事项

- 此 hook 适用于所有使用基础布局的前端页面
- 在 <head> 标签开始之前执行
- 可以用于添加全局的 meta 标签或注释
