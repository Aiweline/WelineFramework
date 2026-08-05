# 基线与 Dirty 归属

## 1. 基线

| 项 | 值 |
|---|---|
| 仓库 | `/Users/weline/Project/Official/框架` |
| 分支 | `master` |
| HEAD | `6f96cc3ed89fb19f4e16a17eba51553d9404b168` |
| Worktree | 当前仅主 worktree |
| Dirty 总数 | 260（163 修改、97 未跟踪） |
| Dirty 指纹 | `93b84651d52ea8f2f937d5129e1afbcb4e2a2fe085a93b5ae08134b3064147f9`（17:41:34–17:41:54 三次采样一致；不等价于 Owner 冻结） |
| R4.1 | `021186844ba138eae8874be94e46e6a569f369c9a57fccb942d1d3fdd557ab22` |

## 2. 已确认归属

### 上游异步事件工程

Owner task：`dev/ai/codex/tasks/2026-07-22/2026-07-22-0419-async-observer-resource-change-r2/`，状态 `verification_blocked`。

主要范围：

- Framework：TransactionContext、TransactionCoordinator、Event/Async/Outbox/Delivery、Namespace Cache/FPC、Runtime、Backend Delivery；
- Queue：幂等、Transport、attempt/reconcile、QueryProvider；
- Websites/SystemConfig：producer、原子写、精准失效；
- Cdn/Seo/Geo：ResourceChange 消费和 default website 0；
- Server：namespace IPC、Worker READY/reload；
- 相关 i18n、module/event 配置和文档。

这些改动与商城 `PRJ-MIG-00`、`PRJ-SCOPE-10`、`PRJ-ASYNC-11`、`PRJ-CONFIG-12` 高度重叠。商城项目不得覆盖、复制或在旧 HEAD 上另建平行实现。

### 商城计划前序文档

以下是 R4 修订任务中已登记的当前文档调整，不属于本次 P0 新代码：

- `.codex/skills/payment-provider-development/SKILL.md`
- `AGENTS.md`
- `AI-ENTRY.md`
- `app/code/Weline/Framework/Runtime/doc/WLS-Fiber-Concurrency-Test-Design.md`
- `app/code/Weline/Payment/doc/provider-development.md`
- `app/code/Weline/Payment/doc/需求.md`
- `dev/ai/agent/Weline-WLS运行时工程师.md`
- `dev/ai/codex/MEMORY.md`
- `dev/ai/codex/plugins/weline-codex-plugin/skills/testing/SKILL.md`
- `dev/ai/global-constraints.md`
- `dev/ai/skills/testing/SKILL.md`

### 审查资料

- `dev/ai/codex/tmp/2026-07-22-commerce-kernel-plan-review-sol-max.md`
- `dev/ai/codex/tmp/2026-07-22-commerce-kernel-plan-recommendations-sol-max.md`
- `dev/ai/codex/tmp/2026-07-22-plan-review-gpt56-sol.md`

保持只读，不纳入实施 diff。

### P1a 第一阶段实现

Owner task：`dev/ai/codex/tasks/2026-07-22/2026-07-22-0900-commerce-kernel-p1a-store-channel-scope/`；当前判定 `IMPLEMENTED_UNACCEPTED`，修复由 `0946-commerce-kernel-p1a-gate-remediation` 接管。

独占新路径包括 Store/SalesChannel 模型、Catalog/DTO/Service、ScopeResolver、ScopeTokenService、StoreChannelSeedService、ScopeIdentity 与专项文档。与上游异步任务混写的路径为：

- `app/code/Weline/Websites/Model/Website.php`
- `app/code/Weline/Websites/Observer/DetectWebsite.php`
- `app/code/Weline/Websites/etc/module.php`
- `app/code/Weline/Websites/doc/README.md`

独占路径可在 `0946` 写锁内修复；混写路径必须等待上游 manifest/受控合并，不以最后写入者推断所有权。

## 3. 待确认归属

- `.codex/skills/ui-ux-pro-max/scripts/__pycache__/`：生成缓存，不属于商城源文件；不得在本任务清理，等 Owner 确认。
- `dev/ai/agents/tasks.json`：由其他任务/调度流程修改；本任务只读，不写入。
- Schema Gate/命令副作用与无任务记录的后续修复约 32 个独占新路径，以及其他未被 manifest 覆盖的 dirty：默认 `UNKNOWN_OWNER_LOCKED`。

## 4. 冲突等级

| 冲突面 | 等级 | 商城受影响项目 | 处理 |
|---|---|---|---|
| Framework Database/TransactionContext | CRITICAL | MIG、Payment、Order、Inventory | 上游验收并冻结后只复用，不另改；若缺契约走 C2 |
| Framework Cache/FPC/Runtime | CRITICAL | Scope、Config、Product/Search | 上游验收后重新 impact/L4；同文件串行 |
| Queue/Async/Event | CRITICAL | Async、Webhook、Subscription/Search | 上游先形成公开契约和 schema 基线 |
| Websites default 0/Observer/Service | CRITICAL | Scope、Store/Channel | 合并事实后再细化 P1A，禁止重复修 zero-site |
| SystemConfig | CRITICAL | Config/Security/Tax | 上游精准失效成为输入，P1C 后接 |
| Server/WLS | HIGH | Scope reset、runtime validation | 上游 READY/IPC 能力作为 P1 验证基线 |
| Cdn/Seo/Geo | MEDIUM | Security/P1D | P1D 只在上游接受后扩展，不覆盖事件接入 |

## 5. 隔离策略

1. 当前不在脏 `master` 实施商城代码；
2. 也不从 HEAD 创建缺少上游 dirty 能力的“假干净”实现分支；
3. 先让上游异步工程得到正式接受并形成可引用提交/基线，或由其 Owner 明确移交完整 diff；
4. 再创建计划中的 `codex/commerce-kernel-program` 集成分支和独立 worktree；
5. 商城 L4 开工时重新检查最新 HEAD、dirty、impact、文件锁和公共契约；
6. 未解决前只做计划、只读核实和不产生业务副作用的 P0 静态验证。
7. P1a 可在独占路径上继续最小修复；需要混写文件时必须停止并等待 Owner 冻结，不得从当前 HEAD 创建遗漏 dirty 能力的假干净 worktree。
