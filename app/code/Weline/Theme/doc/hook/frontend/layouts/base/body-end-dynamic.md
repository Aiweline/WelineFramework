# body-end-dynamic

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::body-end-dynamic`
- **显示名称**：基础布局 Body 结束（动态）
- **功能说明**：会话或路径敏感的店面尾注入（I18n 行内编辑壳、Visitor 像素拾取等）。**禁止**登记进 TemplateCachePolicy `aggregate_hooks` / 游客静态袋，避免毒袋。

## 实现

- Theme：空壳 `view/hooks/.../body-end-dynamic.phtml`
- 贡献者：`Weline_I18n`、`Weline_Visitor` 等同路径 hook 文件

## 注意事项

- 游客中性 chrome 不得挂本钩子——走 `storefront-tail-chrome` 部件注入。
