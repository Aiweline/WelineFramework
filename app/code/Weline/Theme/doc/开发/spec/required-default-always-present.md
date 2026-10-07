# 必装默认部件：无卸载则固化进布局模板

## 背景

历史上登录页等槽位出现「默认应用部件未注入」。旧误解把「发布壳 / `+skip_fill_solidified`」当成可省略必装，或指望请求期 overlay 永远补洞。

**归属纠偏（2026-10-07）**：登录/注册/挑战**主内容**归属 Customer，不得再依赖 Theme required 注入当唯一主路径。本文「无卸载必装」仍适用于**正确拥有模块**的 required 注入（如 Customer 社媒槽、Visitor chrome 槽）；不得用本规格把外模块主内容空挂到 Theme。见 [theme-mechanism-not-foreign-content.md](./theme-mechanism-not-foreign-content.md)。

## 用户纠偏（冻结 · 2026-09-23 / 关系模板补强 2026-09-24 / **产品五条 2026-09-28**）

权威全文：[布局固化与默认注入.md](../../布局固化与默认注入.md) **§0（最高优先）**。

2026-09-25 边界收口：以下“必装”指 `required=true`；未标 required/false 的推荐项仍需显式安装，与 `REQ-THEME-0014` 一致。版本化决定按[主题固化物实施方案](./layout-entity-per-version-isolation.md)：运行只查目标 V/R；不从源版本借省略权限。与 §0 冲突时以 §0 为准。

1. **JSON 默认注入的主路径是布局固化**：固化时把无卸载的 `default_injections` **放置关系**写入对应布局固化模板；槽存在则必须写入，不得用激活态/版本号省略。写入的是**模板**，不是某次请求的部件 HTML / 语种快照。
2. **唯一省略条件**：本版本人工卸载 `user_deleted@{versionId}`。
3. **遗漏 = 固化方案问题**：缺默认部件优先查固化是否缺失/过期/未纳入注入；禁止 Overlay 当稳态。
4. **模板选择**：已有对应派生 PHTML 时优先选择，否则选择原模板；无卸载的必装声明在生成阶段写入对应派生文件。
5. **编辑保存**：装卸、移动、参数和语言配置变更均同步重固，固化目录仅保留 PHTML。
6. **插件安装/变更 JSON 默认注入**：按变更前后涉及布局的并集，重固化**所有相关主题和版本**；卸载决定继续生效。
7. **系统更新**：基于现有编辑意图重建 **`generated/theme-layout-entities/`** 中受影响 PHTML；`theme:upgrade` 可指定或全部更新。禁止把 DB 历史当缓存删除。
8. 布局标签内嵌必装仍由源标签保证；与 JSON 路径 XOR。

## 方案要点（对齐固化）

- Bake / Materializer：布局文件 + 编辑信息 + required 默认注入 → 固定模板；跳过 `user_deleted`。
- 注入收集后：`rebakeAfterInjectionCollect` 刷新涉及布局（全主题）。
- 请求期：选择派生或原始模板，复用普通 Template/Taglib/语言 `com_*`；正常命中不从数据库重播种部件位置，禁止 Overlay 替代固化。
- XOR：拥有模块自选 `placement=injection` + 空槽，或 `placement=layout` 内嵌；Theme 不得代持外模块主内容 required 注入。

## UC

- UC-1：游客打开含 required JSON 注入槽的页，派生 PHTML 含显式部件调用，实际请求显示对应部件；动态内容按当前请求执行。
- UC-2：无 `user_deleted@*` 时，任意主题下对应布局固化产物均含该默认注入关系（插件安装后全主题重固化）。
- UC-3：人工卸载后重固化，该部件不再出现在固化模板中。
- UC-4：同一份已编译 `com_*.phtml` 在不同商品/请求身份下执行，不得串用首次编译时的 HTML 快照。
