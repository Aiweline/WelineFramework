# 模块需求与开发闭环契约

本契约细化 `dev/ai/global-constraints.md` 的模块文档门禁。目标是让需求、实现、版本进度、验收证据和运营交付可以双向追溯，不用从聊天或代码猜产品逻辑。

## 固定所有权

每个模块固定维护并从 `doc/AI-INDEX.md` 链接：

- `app/code/{Vendor}/{Module}/doc/需求.md`：当前已确认产品/业务事实及版本变更。
- `app/code/{Vendor}/{Module}/doc/开发日志.md`：按目标版本定位开发进度、调整和验收证据。

任务记录保存一次执行过程；固定模块文档保存跨任务的产品事实和版本状态。两者互相链接，不复制终端输出或整段会话。

## 需求差异判定

先把当前请求和 `需求.md` 比较。以下任一项语义不同都属于需求差异：

- 新增、删除或改变用户/运营场景、业务规则、状态或分支；
- 改变范围、非目标、优先级、目标版本或兼容策略；
- 改变数据、权限、安全、外部副作用或失败/恢复语义；
- 改变验收标准、WebUI/E2E 路径或完成定义；
- 文档没有覆盖当前请求。

只改措辞且语义完全一致不算差异。存在差异时，在代码前向用户给出「当前文档 → 当前请求 → 逻辑/影响 → 建议写法」，明确询问是否补充或变更。把用户的确认、拒绝、延期及理由写入需求版本记录和开发日志；未确认不得写代码。

## 标识、版本和状态

- 需求 ID 使用稳定格式 `REQ-{MODULE}-{NNNN}`，例如 `REQ-PAYMENT-0001`；标题或版本变化不重编号。
- 每项需求记录引入版本、最近变更版本和状态：`draft`、`confirmed`、`implemented`、`accepted`、`deprecated`。
- 每次开发记录当前基线版本与一个目标版本。目标版本未确认时状态为 `blocked`，不得以 `TBD` 结案。
- 文档中的目标版本是追踪键，不自动授权修改 `etc/module.php`、发布或部署；实际版本文件和发布仍遵循全局规则。
- 开发状态使用：`planned` → `requirements_confirmed` → `implementing` → `review_fixing`/`review_passed` → `testing` → `real_path_e2e` → `e2e_hardening` → `docs_handoff` → `accepted` → `released`。`blocked`、`deferred` 可从任一阶段进入，但必须写原因、决定人和恢复条件。
- `开发日志.md` 的进度事件按时间追加。可以更新版本摘要的当前状态，但不得改写或删除已发生的调整、失败和决策。
- 所有证据先脱敏：禁止写入凭据、Token、Cookie、带密钥的私有 URL、可复用会话、客户/支付原始数据或不必要的个人信息。截图和日志只保留证明结论所需的最小安全片段。

## `需求.md` 模板

```markdown
# {Vendor_Module} 需求

> 本文是本模块当前有效需求与业务逻辑的唯一文档 owner。原始聊天、代码现状和开发日志不是需求授权。

## 文档信息

- 模块：`{Vendor_Module}`
- 当前基线版本：`{version}`
- 下一目标版本：`{version}`
- 最后确认人：`{user/product-owner}`
- 最后确认日期：`YYYY-MM-DD`

## 需求索引

| 需求 ID | 标题 | 状态 | 引入版本 | 最近变更版本 | 验收状态 | 关联功能文档 |
|---|---|---|---|---|---|---|
| `REQ-{MODULE}-0001` | {标题} | confirmed | {version} | {version} | pending | {path} |

## 当前有效需求

### `REQ-{MODULE}-0001` {标题}

- 来源与确认：{来源、确认人、日期}
- 目标版本：{version}
- 背景/问题：{为什么要做}
- 用户与运营场景：{谁在何时做什么}
- 业务逻辑：{状态、分支、计算、优先级、失败与恢复逻辑}
- 范围：{必须交付}
- 非目标：{明确不做}
- 数据/权限/安全：{边界与约束}
- 兼容/迁移：{兼容承诺或不兼容决定}
- 验收标准：{可观察、可判定的结果}
- WebUI/E2E：{主路径、关键分支、错误/权限场景}
- 运营与支持影响：{配置、操作、监控、回退、客服说明}
- 依赖与风险：{依赖、风险、阻断条件}

## 版本变更记录

### {version} — YYYY-MM-DD

| 需求 ID | 变更前 | 变更后 | 业务逻辑/原因 | 用户决定 | 影响范围 |
|---|---|---|---|---|---|
| `REQ-{MODULE}-0001` | {旧语义} | {新语义} | {原因} | confirmed/deferred/rejected | {代码、数据、验收、运营} |

## 待确认

| 项目 | 现有证据 | 不确定点 | 需要谁确认 | 阻断范围 |
|---|---|---|---|---|
| {legacy behavior} | {source/runtime/doc} | {unknown} | {owner} | {scope} |
```

## `开发日志.md` 模板

```markdown
# {Vendor_Module} 开发日志

> 按目标版本记录真实进度、调整与验收证据；不粘贴原始终端日志，不补写未经证实的历史。

## 版本索引

| 目标版本 | 当前状态 | 需求 ID | 开始日期 | 最后更新 | 验收/发布 |
|---|---|---|---|---|---|
| `{version}` | planned | `REQ-{MODULE}-0001` | YYYY-MM-DD | YYYY-MM-DD | pending |

## `{version}` — {功能标题}

- 基线版本：`{version}`
- 目标版本：`{version}`
- 当前状态：`planned`
- 需求 ID：`REQ-{MODULE}-0001`
- 任务记录：`{dev/ai/codex/tasks/... 或 N/A}`
- 范围/排除：{paths, symbols, non-goals}
- 负责人/确认人：{owner}

### 门禁状态

状态只使用 `pending`、`pass`、`fail`、`blocked` 或 `N/A`；`N/A` 必须写可核验理由。

| 门禁 | 状态 | 证据/路径 | 日期 |
|---|---|---|---|
| 需求差异已确认并写入 | pending | `doc/需求.md#{anchor}` | YYYY-MM-DD |
| 实现执行者/模型/范围已记录 | pending | {owner or selector/receipt + owned paths} | YYYY-MM-DD |
| 架构/缺陷/安全复审通过 | pending | {findings/fixes/verdict} | YYYY-MM-DD |
| 分层测试通过 | pending | {case/command/result} | YYYY-MM-DD |
| 真实路径 WebUI/E2E 通过 | pending | {URL/steps/screenshots} | YYYY-MM-DD |
| 可重复 E2E 固化并通过 | pending | {case path/result} | YYYY-MM-DD |
| 功能/运营文档完成 | pending | {paths} | YYYY-MM-DD |
| 用户/产品验收 | pending | {decision/evidence} | YYYY-MM-DD |

### 进度与调整（追加）

| 日期时间 | 阶段 | 状态变化 | 事实/发现 | 调整与原因 | 决定人 | 证据 |
|---|---|---|---|---|---|---|
| YYYY-MM-DD HH:mm | requirements | planned → requirements_confirmed | {difference} | {approved logic} | {user} | {link} |

### 实现范围

- 变更路径/符号：{paths/symbols}
- 数据/配置/迁移：{impact or N/A + reason}
- 兼容与回退：{strategy}

### 审查与整改

- 架构：{finding → fix → verdict}
- 缺陷：{finding → fix → verdict}
- 安全：{finding → fix → verdict}
- 用户批准延期：{item/reason or none}

### 测试与验收

- 聚焦/单元/集成：{actual cases and results}
- 真实 WLS + WebUI：{instance, URL, steps, visible result, console}
- E2E 固化：{case path, branches, actual result}
- 未验证项：{blocker or none}

### 文档与运营交付

- 需求文档：{requirement IDs/version}
- 功能/API/架构文档：{paths}
- 运营文档：{path or N/A + evidence}
- 模块索引：`doc/AI-INDEX.md`

### 版本结论

- 结论：accepted/released/deferred/blocked
- 完成/发布证据：{evidence}
- 剩余事项与恢复条件：{items or none}
```

## 存量模块基线

缺少固定文档的存量模块在下一次代码变更前：

1. 从模块版本、当前源码、现有 README/API/架构文档和已验证运行证据建立「当前基线」，注明证据日期。
2. 只记录能确认的当前行为；未知意图、历史版本和未验证分支进入「待确认」。
3. 不把当前代码缺陷自动写成需求，不猜测过去的用户决定，不补造通过记录。
4. 由用户确认本次涉及的需求和目标版本后再开始实现；无关历史可留作具名待确认项。
5. 在 `开发日志.md` 建立「文档制度启用基线」记录，说明从哪个版本开始可信追踪，不伪装为完整历史。

## 运营交付最小内容

优先更新现有 owner。没有时创建并索引 `doc/运营/{功能名}.md`，至少包含：适用版本、目标读者、功能目的、前置条件与权限、逐步操作、成功/空/失败/重试状态、监控或查询入口、常见错误与恢复、限制、回滚/停用、支持升级信息。内容必须与最终验收结果一致，不向运营暴露未经验证的入口或能力。

## 关闭检查

- 需求差异已由用户明确决定，并先写入 `需求.md`。
- 每个代码变更都映射到需求 ID 和目标版本。
- 所有适用的实现执行者/模型选择、复审、测试、真实路径 E2E、E2E 固化证据齐全；任何 `N/A` 都有可核验理由。
- `开发日志.md` 的当前状态和追加事件一致，没有伪造或隐去失败。
- 功能文档和运营文档已更新/创建并从 `doc/AI-INDEX.md` 可发现。
- 需求、代码、运行结果和文档一致；具名偏差均已解决或获用户明确延期。

## 一致性门禁

在结案前逐项核对：

- 每个改动模块都有两个固定文档，且 `doc/AI-INDEX.md` 可发现它们；
- 开发日志引用的每个需求 ID 都存在于需求文档，目标版本和状态一致；
- 需求变更记录早于对应代码实现，没有用代码现状倒推授权；
- `accepted`/`released` 不得与任何适用门禁的 `pending`、`fail` 或 `blocked` 并存；
- 审查或测试返工有追加事件，并重新经过记录的实现执行者、复审和受影响测试；
- 功能/运营文档与最终可见行为一致，所有证据已脱敏。

结构性规则适合后续加入确定性检查脚本和 CI：校验文件存在、索引链接、必填章节、需求 ID 引用、版本/状态一致性，以及模块代码变化是否同时更新闭环文档。语义是否一致仍必须由用户确认、代码审查和真实验收共同判断，不能只靠脚本判绿。
