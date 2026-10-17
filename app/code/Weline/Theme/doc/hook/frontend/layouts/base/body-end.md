# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::base::body-end`
- **显示名称**：基础布局 Body 结束
- **功能说明**：兼容空壳聚合点。游客中性尾 chrome（Compare / Consent / B2B / Geo / Location / Shipping / Theme boot）已迁 **footer** 槽 `storefront-tail-chrome`；可编辑悬浮槽 `storefront-float-start/end` 在 footer `#w-storefront-float-layer`。会话/路径敏感逻辑走 **`body-end-dynamic`**（I18n inline / Visitor pixel）。

## 缓存

本 Hook 仍走 `TemplateCachePolicy` **聚合袋**（`context=body_end`）：各模块若保留空壳实现文件，必须登记 `output_files`，`aggregate_manifests.complete` 须为 true。上下文分片含 auth / 账户区 / i18n-inline / theme id。

## 使用方法

新的游客中性 chrome：**不要**再挂本 Hook——改用 `storefront-tail-chrome` 部件 `default_injections`。敏感逻辑挂 `body-end-dynamic`。

## 执行顺序

1. 详细布局 hook（如 `homepage::body-end`）  
2. `base::body-end`（聚合空壳）  
3. `base::body-end-dynamic`（敏感）  
4. `</body>`

## 注意事项

- 主题绑定整页场景（如 daocharms-3d 古镇）挂 **design 布局 + 模块 Widget**，禁止再塞进全局 Hook 做全站目录 Query。  
- 禁止把 Guest 静态 chrome 与 I18n/Visitor 敏感逻辑混进同一聚合袋。
