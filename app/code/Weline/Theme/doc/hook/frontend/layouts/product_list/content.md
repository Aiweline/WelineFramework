# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::product-list::content`
- **显示名称**：产品列表布局内容
- **功能说明**：覆盖产品列表布局的主内容区域，允许商品模块在保留默认页头、页脚和布局资源的同时提供完整商品列表内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/layouts/product-list/content.phtml`

## 边界

- 该 Hook 位于 `product_list/default.phtml` 的默认内容 slot 外层。
- 未实现时继续渲染默认商品列表 slot、筛选、工具栏、网格、分页和推荐占位。
- 实现方应只输出商品列表内容；页头、页脚、SEO、语言货币、账户、购物车和布局资源仍由 `Weline_Theme` 管理。
- 商品数据读取应通过本模块服务、QueryProvider 或框架扩展点完成，不应在主题 Hook 内重写结账、购物车、支付或配送流程。
