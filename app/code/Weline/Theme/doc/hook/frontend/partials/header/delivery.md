# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::header::delivery`
- **显示名称**：页头配送地址槽
- **功能说明**：在 header `delivery` 空槽内触发；结账配送上下文由 Checkout `default_injections` 注入，本 Hook 供扩展覆盖或附加内容。禁止在 Theme 布局/partial 内嵌非 Theme `<w:widget>`。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--partials--header--delivery.phtml`
