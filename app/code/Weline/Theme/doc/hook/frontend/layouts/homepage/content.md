# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::homepage::content`
- **显示名称**：首页布局内容
- **功能说明**：覆盖首页布局的主内容区域，允许站点或业务模块在保留默认页头、页脚和布局资源的同时提供完整首页内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme/frontend/layouts/homepage/content.phtml`

## 边界

- 该 Hook 位于 `homepage/default.phtml` 的首页主内容 slot 内。
- 未实现时继续渲染 `Weline_Theme` 默认首页 hero、功能、商品、资讯等默认内容。
- 实现方应只输出首页业务内容；页头、页脚、SEO、语言货币、账户、购物车和布局资源仍由 `Weline_Theme` 管理。
- 站点品牌或业务内容可通过该 Hook、页面内容模板、配置或资产扩展完成，不应复制整页 layout 或 partial 来实现继承。
