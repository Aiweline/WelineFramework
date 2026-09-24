# 必装默认部件：无卸载则固化进布局模板

## 背景

登录页等槽位出现「默认应用部件未注入」。旧误解把「发布壳 / `+skip_fill_solidified`」当成可省略必装，或指望请求期 overlay 永远补洞。

## 用户纠偏（冻结 · 2026-09-23 / 关系模板补强 2026-09-24）

权威全文：[布局固化与默认注入.md](../../布局固化与默认注入.md)。

1. **JSON 默认注入的主路径是布局固化**：固化时把无卸载的 `default_injections` **放置关系**写入对应布局固化模板；槽存在则必须写入，**与主题激活态、主题版本无关**。写入的是关系模板，**不是**某次请求的部件 HTML 快照。
2. **唯一省略条件**：本版本人工卸载 `user_deleted@{versionId}`。
3. **遗漏 = 固化方案问题**：缺默认部件优先查固化是否缺失/过期/未纳入注入；禁止把「壳完整 / skip_fill / 编辑器不回填」当成合法省略。
4. **无固化模板**：运行期对**当前激活主题**动态固化该布局模板（仍须写入无卸载的默认注入关系）。
5. **已有固化模板**：仅主题**新增或移除**部件时再固化。
6. **插件安装/变更 JSON 默认注入**：重固化**所有主题**下该注入涉及的全部对应布局固化模板（`rebakeAfterInjectionCollect` 同闸）。
7. 布局标签内嵌必装（`placement=layout`）仍由源标签保证；与 JSON 路径 XOR，无卸载时选中路径必须在店面可见；内嵌 `<w:widget>` 编译为 `renderRuntimeInline` 关系壳，HTML 在请求期产生。

## 方案要点（对齐固化）

- Bake / Materializer：固化布局时合并 required（及应写入的）`default_injections`，跳过 `user_deleted@{versionId}`。
- 注入收集后：`ThemeLayoutEntityBakeCoordinator::rebakeAfterInjectionCollect` 刷新涉及布局（全主题）。
- 请求期：仅在**无可用固化模板**时动态固化激活主题；不得把「每请求 overlay」当成替代固化的长期主路径；部件 HTML 由已固化模板内的运行时壳 hydrate。
- XOR：登录页等保持 `placement=injection` + 空槽（禁止旁路 fetch）。

## UC

- UC-1：游客打开含 required JSON 注入槽的页，固化模板（或首次动态固化后的模板）含对应部件放置关系 / `data-widget-code`（或等价关系壳）；HTML 在该次请求 hydrate。
- UC-2：无 `user_deleted@*` 时，任意主题下对应布局固化产物均含该默认注入关系（插件安装后全主题重固化）。
- UC-3：人工卸载后重固化，该部件不再出现在固化模板中。
- UC-4：同一份已编译 `com_*.phtml` 在不同商品/请求身份下执行，不得串用首次编译时的 HTML 快照。