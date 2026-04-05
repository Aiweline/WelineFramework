# AI 建站工作台 — Websites 侧（智能体、域名生命周期、供应商）

> 模块：`Weline_Websites`  
> 范围：**仅本文档描述 Websites 侧**（会话壳、Agent Tools、域名/订单/供应商、`site_ready` 等）；本机 hosts / `w_query` 见 [`Weline_Server` 计划](../../Server/doc/计划-AI建站-w_query与本机hosts.md)；页面生成、`ai_html`、`AiSiteAgent` 见 [`GuoLaiRen_PageBuilder` 计划](../../../GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md)。

## 目标

- **统一 AI 建站工作台**壳层：智能体按步骤引导；**域名购买、可用性、解析/就绪、账户选择**等封装为 **Agent Tools**（可观测、高风险动作单独确认）。
- **本地/E2E**：推荐 **`*.weline.local`** 子域模拟多站点；**省略真实对外购买**，但 **订单与生命周期记录仍完整**（供应商 **Weline 本地**）。
- **默认本地供应商**：系统内置 **Weline（本地）**，**不可删除**，或删除后 **`setup:upgrade`/同步自动补回**，保证开发与 CI 总有可用供应商。
- **与 PageBuilder**：通过 **provider / handoff**（如 `pagebuilder_workspace_public_id`、`scope` 内域名状态）传递；**`site_ready`（或等价）** 供 PageBuilder **`can_publish`** 组合判断。

## 已确认决策（Websites 子集）

1. **阶段 1（准备信息）**：站点简报、域名流程入口、账户等完成后进入下一阶段；状态写入 **会话 scope / artifact**。
2. **Agent Tools**：域名推荐、购买（本地为模拟/免支付）、状态轮询、DNS/就绪等与 `WebsiteAgentService`、`DomainPurchaseService` 等封装为 **标准 Tool**（JSON 入出参、可写入 artifact）。
3. **阶段剧本（示例）**：`phase1_prepare` → `phase2_workspace`（含域名轮询）→ `handoff_to_pagebuilder_phase3` → `await_domain_and_publish`；可与 Codex [`Websites-AI建站工作台-总规划.plan.md`](../../../../../dev/ai/codex/AI工作台/Websites-AI建站工作台-总规划.plan.md) 对齐，以 **工具化** 为准。
4. **本地生命周期**：与线上一致状态机，**无对外支付**；仍走 **证书申请/续期**（与 WLS/SSL 衔接，见 Server 文档）；**`*.weline.local` 通配策略**（申请前复用检查、仅 `weline.local`）在业务层与证书层配合，**其他域名**按用户选择与参数走既有分支。
5. **域名门禁语义**：**未就绪**时允许业务上「仅草稿」；**就绪**后 PageBuilder 方可发布（具体 API 契约与 PageBuilder 文档一致）。

## 交付物

- [ ] Tool 注册表与权限、确认门槛（购买类）。
- [ ] 默认本地供应商 Weline：数据补丁或 Setup、删除自愈逻辑。
- [ ] handoff scope 字段与 **域名状态** 与 PageBuilder 消费方对齐（文档 + 契约测试）。
- [ ] 与 **WLS hosts** 的协作说明：谁触发写 hosts（业务回调 vs 开发者显式 `w_query`），避免双写冲突（实现时定策略）。

## 非目标

- 不在此定义 **Page** 扩展字段、`render_mode`、**虚拟主题** 物化细节。

## 关联文档

- [Weline Server — w_query 与本机 hosts](../../Server/doc/计划-AI建站-w_query与本机hosts.md)
- [GuoLaiRen PageBuilder — 新 AI 建站（页面侧）](../../../GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md)
