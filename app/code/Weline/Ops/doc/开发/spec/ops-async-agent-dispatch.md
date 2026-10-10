# Spec：Ops 分析分发任务队列（异步调度）

status: ready-for-plan（施工中；合并原 P2 日更分析 + P3 审批发布）

## 架构

| 字段 | 内容 |
|------|------|
| mechanism | OpsSession + OpsTask（session_id/assignee）+ 单一 OpsTaskDispatchQueue；website_ops 同步受理；必拆 `insight.daily_analysis`→`ops_analyst`，再按 lanes 拆 blog/social；Worker CAS claim；分析成功→done；博客/社媒→awaiting_approval；外发仅批准后 |
| owning_module | 编排/账本/任务管理页=`Weline_Ops`；队列=`Weline_Queue`；Cron 日更触发；执行域 Blog/Social Agent |
| invent | `ops_analyst`；调度任务管理页；只读 Tool `list_ops_queue_tasks`/`get_ops_task`；`OpsDailyDispatchCron` |
| not_to_do | Runtime 硬编码站业务；平行队列；MCP session 当业务会话；调度自外发；无审批外发；Lane 派工；Agent 直写 Ops 表；分析任务进入 awaiting_approval |

## EARS

- WHEN 用户在 Desk 提交运营配置 THEN 系统 SHALL 立即返回 session_id，并至少创建 1 条 `insight.daily_analysis` 任务且入队
- WHEN Queue 消费 `ops_analyst` 任务成功 THEN 系统 SHALL 写 `ops_run_log.kind=daily_analysis`、更新 Session `last_analysis`，任务状态 `done`（不得 awaiting_approval）
- WHEN Queue 消费 blog/social 且 require_approval=1 THEN 系统 SHALL 停在 `awaiting_approval` 且不得公开外发
- WHEN 同一 task_id 重复入队 THEN 系统 SHALL 不双跑（idempotency_key=`ops_task:{task_id}`）
- WHEN 运营者打开调度任务管理页 THEN 系统 SHALL 可按 website/status/assignee/session 筛选调度任务并取消/批准

## UC

| id | 标题 | acceptance |
|----|------|------------|
| UC-dispatch-accept | 配置受理 | session_id + 分析任务 +（可选）分发任务 + queue |
| UC-analysis-done | 分析完成 | RunLog daily_analysis + Session.last_analysis + status=done |
| UC-async-consume | 异步消费分发 | blog/social → awaiting_approval\|done\|failed |
| UC-session-context | 会话上下文 | 仅 session_id 可还原 config/persona/last_analysis |
| UC-no-auto-publish | 无审批不外发 | 未批准无平台 publish / blog published |
| UC-idempotent | 幂等入队 | 同 task_id 不双跑 |
| UC-task-admin | 调度任务管理 | 后台页可见调度任务并可取消/批准 |
| UC-agent-queue-visible | 智能体可见队列 | list_ops_queue_tasks 返回本 assignee queued/running |

关联：`ai-ops-website-program.md`（P1 画像保留；本规格覆盖分析分发队列，不再单独排 P3）。
