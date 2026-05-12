# AI建站工作台-前端强契约并发任务

本文档服务 [WEL-57] 的执行分工，目标是把 AI 建站工作台前端可并发推进的任务、接口与状态契约、验收用例固化为可执行清单。本文是调度/契约文档，不代表代码行为已变更，也不替代前端、业务、WLS 或 E2E 的实际证据。

维护边界如下：本文放在 PageBuilder 模块 `doc/` 目录内，不在仓库根目录写修复报告；如后续代码行为、模块使用方式或测试运行方式发生变化，需要同步 `app/code/GuoLaiRen/PageBuilder/README.md` 或 `tests/e2e/README.md`；当前首版仅建立契约骨架，因此不改 README。

## 1. 执行边界与来源

契约来源以 `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`、AI 建站工作台前端模板和 PageBuilder E2E 说明为准。接口字段和 SSE 事件的最终验收必须合并业务模块、WLS 运行时、前端主题和 E2E 自动化的证据，本文中的“证据槽位”用于收敛这些证据。

前端实现需要遵守强契约原则：所有用户可见文本走模板翻译或前端 i18n 字典，不新增原生 `alert`、`confirm`、`prompt`；按钮禁用、重试、排队、运行、失败、超时、关闭日志等状态必须由接口字段驱动，不能只靠本地猜测；前端可并发任务之间只能共享明确的状态字段和事件名称。

## 2. 前端并发任务清单

| 编号 | 负责人 | 可并发关系 | 输入 contract | UI 输出 | 验证命令/证据槽位 | 依赖与交付 |
| --- | --- | --- | --- | --- | --- | --- |
| FE-01 工作台状态总线与阶段壳 | Weline-前端主题工程师 | 可与 FE-02 至 FE-07、BE-CONTRACT-01、WLS-SSE-01、E2E-01 并行 | workspace state、`workspace_status`、`active_operation`、`active_operations`、`retryable_ai_failure_summary`、`can_publish`、`publish_status` | 阶段卡、顶部状态、按钮禁用、失败横幅、重试入口、发布守卫 | 前端截图；手工状态切换证据；E2E 工作台打开用例 | 先定义状态消费层，避免各阶段重复解析接口 |
| FE-02 Phase 1 建站计划 | Weline-前端主题工程师 | 可与 FE-03 至 FE-07 并行；依赖 BE-CONTRACT-01 字段确认 | `post-start-plan`、`post-confirm-plan`、`plan_queue_info`、`execution_token`、`stream_url`、`queue_waiting_for_scheduler` | 生成计划、排队等待、运行日志、计划确认、计划失败重试、计划排序/修改入口 | E2E grep：计划生成/确认；前端视频或截图；接口响应样本 | 直接 Plan SSE 已禁用时必须走 start endpoint 返回的 `stream_url` |
| FE-03 BuildPlan 预览与确认 | Weline-前端主题工程师 | 可与 FE-02、FE-04 至 FE-07 并行 | `post-start-plan`、`post-confirm-plan` 返回的 `build_plan_v2`、`plan_projection`、`plan_queue_info`、`retryable_ai_failure_summary` | BuildPlan 预览、计划确认、计划失败重试、计划排序/修改入口 | E2E grep：BuildPlan 生成/确认；接口样本；队列状态截图 | 单阶段方案确认后直接进入构建；不再存在任务计划确认阶段 |
| FE-04 构建与可视编辑 | Weline-前端主题工程师 | 可与 FE-05 至 FE-07 并行；可先用 mock state 验 UI | `post-start-build`、`post-resume-build`、`post-start-regenerate-page`、`post-start-refine-component`、`post-start-patch-block`、`post-update-block-config`、block refine/regenerate SSE | 构建进度、恢复构建、页面切换、区块再生成、组件精修、区块局部修补、配置保存 | E2E grep：preview tab、repick modal、block patch；前端截图 | 依赖 `build_plan_v2` 已确认；区块读写以 PageBuilder 当前后端契约为准 |
| FE-05 发布检查与发布 | Weline-前端主题工程师 | 可与 FE-06、E2E-01 并行 | `post-publish-checklist`、`post-start-publish`、`can_publish`、`site_ready`、`quality_gate`、`publish_status`、`latest_build_failure` | 发布检查清单、发布按钮禁用原因、发布中状态、发布成功或失败反馈 | E2E 发布守卫用例；接口样本；发布卡截图 | 发布前必须确认 BuildPlan、站点就绪和质量门禁 |
| FE-06 SSE 日志、关闭与重连 | Weline-前端主题工程师 + Weline-WLS运行时工程师 | 可与 FE-02 至 FE-05 并行 | `operation-sse`、`stream-sse`、`last_event_id`、`tab_token`、`queue_status`、`terminal_status`、`can_close_stream` | 日志面板、断线重连、关闭日志后不丢任务、超时提示、终态快照恢复 | WLS SSE 事件样本；E2E SSE probing；手工关闭日志证据 | 需要 WLS 确认终态事件和 lease 规则 |
| FE-07 i18n 与用户提示 | Weline-前端主题工程师 | 可与所有前端任务并行 | 后端 `message`、`code`、`errors`、前端 i18n 字典 | 可翻译按钮、失败文案、等待提示、重试提示、超时提示 | 文案 key 清单；截图；无硬编码可见英文/中文检查 | 不引入原生弹窗；提示必须可追踪到状态字段 |
| BE-CONTRACT-01 接口字段确认 | Weline-业务模块工程师 | 与前端、WLS、E2E 并行 | 下方接口矩阵所有 endpoint、status field、错误码、阻断原因 | 字段说明、成功/失败样本、阻断样本 | specialist evidence：业务模块评论/样本 | 是前端最终落地的字段所有者 |
| WLS-SSE-01 运行时事件确认 | Weline-WLS运行时工程师 | 与前端、业务、E2E 并行 | SSE `start`、`snapshot`、`log`、`info`、`warning`、`progress`、`chunk`、`error`、`done`、`complete`、timeout | 事件序列、关闭/重连、排队等待、终态规则 | specialist evidence：WLS 评论/日志 | 需要确认 stop/cancelled 是否独立终态 |
| E2E-01 验收用例自动化 | Weline-E2E自动化工程师 | 可在接口样本冻结前先建 skeleton | 下方验收清单、README 运行命令、fake/mock 模式 | spec 列表、grep 标签、截图/trace、失败复现命令 | `php bin/w e2e:run ...` 输出；trace 截图 | 运行方式变更时同步 `tests/e2e/README.md` |
| DOC-01 证据合并与变更记录 | Weline-文档知识库工程师 | 跟随所有任务增量更新 | specialist evidence、changed files、commands、remaining risks | 本文更新、证据登记、README 同步判断 | markdown 人工检查；diff；评论证据 | 只记录执行所需契约，不写流水账报告 |

## 3. 接口与状态契约矩阵

| Endpoint | Method 与关键输入 | status fields | SSE events | UI consumer | Contract owner | Test owner / evidence |
| --- | --- | --- | --- | --- | --- | --- |
| workspace 页面 | GET，`public_id` 或入口上下文 | `workspace_status`、`stage`、`can_publish`、`publish_status`、`active_operation`、`active_operations`、`retryable_ai_failure_summary`、`domain_status` | 无 | 工作台壳、阶段卡、发布卡、失败横幅 | 业务模块 | 前端工作台打开截图；E2E builder index/workspace |
| `post-create-session` | POST，创建/恢复 AI 建站会话所需上下文 | `success`、`public_id`、workspace state、入口 URL | 无 | 创建会话按钮、进入工作台 | 业务模块 | E2E 会话创建或 fake workspace |
| `post-start-plan` | POST，`public_id`、`scope_patch`、`target_domain`，可选 `confirm_regenerate`、`plan_locale`、`selected_skill_codes`、`prompt_mode`、`instruction`、`target_scope`、`round` | `success`、`operation=plan`、`start_sse`、`requires_confirmation`、`execution_token`、`stream_url`、`queue_id`、`queue_wait`、`data` | 通过返回的 `stream_url` 观察队列 | 计划生成按钮、计划日志、计划排队详情 | 业务模块 + WLS | E2E plan start；接口样本 |
| `plan-sse` | GET/POST，历史直连 SSE；需要 `public_id` 等 | `QUEUE_START_REQUIRED`、`queue_waiting_for_scheduler`、`can_close_stream`、`continue_other_operations` | contract error + complete | 兼容层；前端不应把它作为主要启动入口 | WLS | WLS 事件样本；前端兼容检查 |
| `post-confirm-plan` | POST，`public_id`，确认已生成 draft plan | `success`、`stage`、`plan_confirmed`、阻断码、retryable failure 信息 | 无 | 确认计划按钮、阻断提示、下一阶段解锁 | 业务模块 | E2E confirm plan；失败阻断样本 |
| `post-start-build` / `post-resume-build` | POST，`public_id`、`scope_patch`；恢复构建使用当前 workspace pending work | `success`、`operation=build`、`start_sse`、`execution_token`、`stream_url`、`queue_id`、`queue_wait`、`stage`、`active_operation` | 通过 `operation-sse` 或返回 `stream_url` 观察 | 构建按钮、恢复构建、构建进度、失败重试 | 业务模块 + WLS | E2E build/resume；队列样本 |
| `operation-sse` | GET，`public_id`、`execution_token` | `queue_id`、`queue_status`、`token_usage`、`queue_waiting_for_scheduler`、`can_close_stream`、`continue_other_operations`、`terminal_status` | `info`、`warning`、`progress`、`chunk`、`error`、`done`、`complete` | 通用日志面板、队列状态详情、关闭/重连 | WLS | WLS SSE 日志；E2E SSE probing |
| `stream-sse` | GET，`public_id`，可选 `last_event_id`、`tab_token`、`stage` | `success`、`terminal_status`、`last_event_id`、active operation 快照 | `start`、`snapshot`、`log`、complete | 工作台快照流、断线恢复、跨 tab lease | WLS | WLS lease 证据；E2E reconnect |
| `post-workspace-snapshot` | POST，`public_id` | `success`、workspace operation payload、`stage`、`active_operation`、页面/区块摘要 | 无 | 关闭日志后刷新、断线恢复、阶段同步 | 业务模块 | E2E snapshot；手工刷新 |
| `post-publish-checklist` | POST，`public_id` | `passed`、`items`、`stage`、`workspace_status`、`publish_status`、`quality_gate`、阻断项 | 无 | 发布前检查清单、发布按钮禁用原因 | 业务模块 | E2E publish blocked/pass |
| `post-start-publish` | POST，`public_id` | `success`、`operation=publish`、`workspace_status=publishing`、`execution_token`、`stream_url`、阻断码、`publish_blocked_by_latest_ai_failure` | 通过 `operation-sse` 观察 | 发布按钮、发布中、发布成功/失败 | 业务模块 + WLS | E2E publish flow |
| `post-switch-preview-page` | POST，`public_id`，`preview_page_id` 或 `preview_page_type` | `success`、preview page compact fields、当前页面标识 | 无 | 预览 tab、iframe 切换、页面类型切换 | 业务模块 + 前端 | E2E preview tabs |
| 视觉编辑接口组 | POST/SSE，`post-start-regenerate-page`、`post-start-refine-component`、`post-start-patch-block`、`block-refine-sse`、`block-regenerate-sse`、`post-update-block-config`，关键输入为 `public_id`、`page_type`、`block_id`、patch/config | `success`、`operation`、`execution_token`、`stream_url`、block/page payload、错误码 | block refine/regenerate SSE events | 页面再生成、组件精修、区块局部修补、配置保存 | 业务模块 + WLS + 前端 | E2E block/preview；手工视觉证据 |
| `post-delete-workspace` | POST，`public_id` | `success`、删除结果、阻断原因 | 无 | 删除工作台、关闭页面或回到列表 | 业务模块 | E2E delete 或手工证据 |

## 4. 状态字段契约

| 字段组 | 字段/枚举 | 前端消费规则 | 验收证据槽位 |
| --- | --- | --- | --- |
| Workspace status | `preparing`、`building`、`editing`、`can_publish`、`publishing`、`published`、`failed` | 驱动阶段卡、主操作按钮、发布入口和失败态；不得只靠 DOM 本地状态推断 | 工作台截图；workspace snapshot 样本 |
| Active operation | `operation`、`status`、`execution_token`、`queue_id`、`stream_url`、`message`、`progress_percent`、`updated_at`、`retry_allowed`、`failure_mode`、`retryable_ai_failure_count`、`queue_waiting_for_scheduler`、`can_close_stream`、`continue_other_operations` | `queued/running` 禁用冲突操作；`done` 解锁下一阶段；`error/failed/fail` 显示失败和重试；`stop/stopped/cancelled/canceled` 按可关闭终态处理并允许 snapshot 恢复 | WLS/业务样本；前端状态截图 |
| Queue snapshot | `queue_id`、`name`、`module`、`biz_key`、`status`、`pid`、`type_id`、`finished`、`start_at`、`end_at`、`public_id_hint`、`job_key`、`job_type`、`job_status`、`token`、`token_usage` | 显示排队、运行、完成、错误、token 使用和后台任务详情；`pending/queued` 显示等待调度器 | WLS queue_info 日志；E2E queue detail |
| Build/task progress | `build_tasks`、`plan_queue_info`、`build_queue_info`、任务 `pending/running/done/error/failed` | 阶段内列表必须支持等待、运行、成功、失败和重试；失败任务不能误标成功 | E2E phase progress；接口样本 |
| Publish gates | `can_publish`、`site_ready`、`plan_confirmed`、`build_plan_confirmed`、`publish_status`、`quality_gate`、`publish_blocked_by_latest_ai_failure`、`latest_build_failure` | 发布按钮和检查清单必须给出明确阻断原因；发布中禁用二次提交 | publish checklist 样本；E2E publish guard |
| Terminal and timeout | `terminal_status`、`last_event_id`、observer timeout message、`can_close_stream` | SSE 终态或超时时不丢失 workspace；关闭日志后通过 snapshot 恢复；重连带 `last_event_id` | WLS timeout/lease 证据；E2E reconnect |

注意：当前观察到的队列协调逻辑存在一个需要确认的语义点，部分路径会把 `stop/cancelled` 队列终态合并到 active operation 的 `done`。前端应优先保留并展示原始 `queue_status` 或明确的 `terminal_status`，WLS/业务模块需要确认取消态是否要求独立 UI 终态。

## 5. 验收用例清单

| 用例 | 覆盖场景 | 设置/触发 | 期望 UI 输出 | 验证命令/证据槽位 | Owner |
| --- | --- | --- | --- | --- | --- |
| AC-01 全流程成功 | 创建/打开工作台、生成并确认 BuildPlan、构建、发布检查、发布成功 | fake 或测试实例；依次调用 start/confirm/build/publish | 每阶段按钮状态正确，日志显示排队到完成，最终 `published` 或发布成功态 | `php bin/w e2e:run specs/backend/pagebuilder-ai-site-workbench.spec.js --grep="..." --headless --project=chromium`；截图/trace | E2E + 前端 |
| AC-02 接口或队列失败 | start/confirm/build/publish 返回错误或队列 `error` | mock 失败响应或真实失败任务 | 显示后端 message/code，保留当前阶段，出现允许的重试入口，发布被阻断 | 失败响应样本；E2E error grep；截图 | 业务 + 前端 + E2E |
| AC-03 等待/排队 | 队列 `pending/queued`，调度器尚未接手 | start endpoint 返回 `queue_waiting_for_scheduler` 或 queue wait | 显示等待调度器、允许关闭日志且提示可继续其他操作，不误报失败 | WLS queue_info 样本；手工关闭日志截图 | WLS + 前端 |
| AC-04 运行中 | 队列 `running`，持续 `progress/chunk/log` | operation stream 推送进度 | 按钮禁用冲突操作，日志增量展示，任务/阶段进度不闪退 | WLS stream log；E2E SSE probing | WLS + 前端 + E2E |
| AC-05 取消或关闭日志 | 用户关闭日志、tab lease 切换，或任务终态为 `stop/cancelled/canceled` | 关闭日志面板或模拟终态 | UI 进入可恢复终态；snapshot 后仍能看到最新状态；不重复启动任务 | WLS lease 证据；手工关闭日志视频/截图 | WLS + 前端 |
| AC-06 重试 | plan/build 出现 retryable AI failures | 后端返回 `retryable_ai_failure_summary` 或 retry action | 显示对应阶段重试按钮，只重试允许的失败项，不清空已成功内容 | 前端截图；业务失败样本；E2E retry grep | 业务 + 前端 + E2E |
| AC-07 超时或断线 | observer idle timeout、网络断开、SSE reconnect | 中断 SSE 或模拟 timeout | 显示超时/断线提示；可重新连接或 snapshot 恢复；不误解锁发布 | WLS timeout 日志；E2E reconnect/trace | WLS + E2E |
| AC-08 发布阻断 | `can_publish=false`、质量门禁失败、latest build failed、BuildPlan 未确认 | 调用 checklist 或加载阻断 workspace | 发布按钮禁用，阻断项逐条展示，不能提交发布 | publish checklist 样本；E2E publish guard | 业务 + 前端 + E2E |
| AC-09 预览和页面编辑 | 切换预览页、页面再生成、组件精修、区块修补、配置保存 | preview page endpoint 和视觉编辑接口组 | iframe/tab 正确切换，局部操作显示进度和结果，失败不污染其他区块 | `php bin/w e2e:run specs/backend/pagebuilder-ai-site-workbench.spec.js --grep="preview|repick|block" --headless --project=chromium` | 前端 + E2E |
| AC-10 删除工作台 | 删除当前 workspace | `post-delete-workspace` | 删除成功后回到列表或关闭当前上下文；失败显示原因 | 手工证据或 E2E delete | 业务 + 前端 |

## 6. Specialist evidence 登记

| 来源 | 当前状态 | 需要合并的证据 |
| --- | --- | --- |
| 前端主题工程师子任务评论 `2198ee1c-4356-4499-a93c-1efd6472d250` | 待合并 | 前端 changed files、状态映射、截图、手工/自动化命令 |
| 业务模块工程师子任务评论 `e458a37b-e39a-4048-8f6b-8dda6589fae6` | 待合并 | endpoint 响应样本、错误码、阻断码、字段所有权 |
| WLS 运行时工程师子任务评论 `3047a93e-418b-49cb-8901-6989d4981715` | 待合并 | SSE 事件序列、queue_info 样本、timeout、close/reconnect/lease 证据 |
| E2E 自动化工程师子任务评论 `046ea953-1660-4760-8b4d-e413759a650f` | 待合并 | spec/grep 标签、运行命令、截图/trace、剩余 flaky 风险 |
| 文档知识库工程师本文档 | 已建立骨架 | changed file、人工 markdown 检查、remaining risks |

## 7. 验证命令槽位

当前文档首版的验证命令如下：

```powershell
git diff -- app/code/GuoLaiRen/PageBuilder/doc/AI建站工作台-前端强契约并发任务.md
Get-Content app\code\GuoLaiRen\PageBuilder\doc\AI建站工作台-前端强契约并发任务.md
```

后续 specialist 合并证据时，优先使用现有 E2E 说明中的运行方式。示例：

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-e2e-pb'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin/w e2e:run specs/backend/pagebuilder-ai-site-workbench.spec.js --grep="builder index lists published" --headless --project=chromium
php bin/w e2e:run specs/backend/pagebuilder-ai-site-workbench.spec.js --grep="preview|repick|block" --headless --project=chromium
```

如 E2E 或 WLS 运行方式发生变化，必须同步 `tests/e2e/README.md`。如模块行为或入口说明发生变化，必须同步 `app/code/GuoLaiRen/PageBuilder/README.md`。

## 8. Remaining risks

1. 业务模块和 WLS 仍需确认最终响应字段、错误码、阻断码和 SSE 终态语义，本文当前仅根据现有代码路径建立执行矩阵。
2. `plan-sse` 的直连生成路径当前以 `QUEUE_START_REQUIRED` 作为契约错误完成；前端主要入口应使用 start endpoint 返回的 `stream_url`，但兼容层仍需测试。
3. `stop/cancelled` 在部分 active operation 协调路径中可能被归并为 `done`，需要 WLS/业务确认前端是否必须展示独立取消终态。
4. 本次无代码改动，未运行 PHP 单测或 Playwright E2E；后续验收必须补齐 specialist 的真实命令输出、截图或 trace。
