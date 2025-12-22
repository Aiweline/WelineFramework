# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::head-before`
- **显示名称**：基础布局头部之前
- **功能说明**：在渲染基础布局的 <head> 标签之前触发，允许其他模块在头部开始处注入内容。此 hook 适用于所有使用基础布局的页面。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--layouts--base--head-before.phtml`
