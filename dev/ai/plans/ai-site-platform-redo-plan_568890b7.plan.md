---
name: AI建站中台代码重做计划
overview: 基于新的两阶段文档口径，重做 PageBuilder AI 工作台实现计划：先闭环 plan/confirm/resume 接口与持久化模型，再替换自动续跑为显式继续，并补齐集成与E2E验收。
todos:
  - id: backend-plan-confirm-resume
    content: 实现并收敛 post-start-plan / post-confirm-plan / post-resume-build 与 start-build 前置校验
    status: pending
  - id: blueprint-checkpoint-truth-source
    content: 统一 execution_blueprint.tasks 与 build_tasks/checkpoint 真相源与恢复索引
    status: pending
  - id: workspace-explicit-resume-ui
    content: 将 workspace 自动续跑改为显式继续交互并保留观察流
    status: pending
  - id: integration-e2e-acceptance
    content: 补齐 plan-first、build guard、checkpoint 恢复、重入显式继续的测试与验收
    status: pending
isProject: false
---

# PageBuilder 两阶段执行重做计划

## 目标
将现有“自动续跑 + 弱蓝图”实现切换到“阶段一方案书、阶段二确认执行、任务级断点恢复、显式继续”口径，并与最新文档契约完全一致。

## 需求描述
- 将 AI 建站流程拆分为明确的两阶段：阶段一只生成和确认方案，阶段二仅执行已确认方案。
- build 流程必须以 `plan_workbench.confirmed.execution_blueprint.tasks[]` 为唯一输入，不允许未确认直接开跑。
- 工作台重入时禁止默认自动续跑，统一改为“继续/稍后再说”的显式用户决策。
- 恢复逻辑以任务级状态为真相源，`build_checkpoint` 只承担恢复索引职责，并保证“先落库再发 SSE”。
- 测试与验收要覆盖 plan SSE、confirm 持久化、build 前置校验、任务边界恢复、重入交互与多语言文案一致性。

## 当前实现基线（已确认）
- 控制器已暴露 URL 注入位：`post-start-plan`、`post-confirm-plan`、`post-resume-build`、`post-start-build`（[AiSiteAgent.php](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php)）。
- 前端仍有自动续跑逻辑：`workspaceSnapshotResume` / `autoResumeActiveOperation`（[workspace.phtml](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml)）。
- 执行蓝图服务已具备 `execution_blueprint.tasks[]` 生成能力（[AiSiteExecutionBlueprintService.php](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php)）。

## 实施范围
- 后端：控制器流程门禁、plan/confirm/resume 端点、scope/checkpoint 持久化顺序。
- 前端：入场提示与“继续/稍后再说”分支、移除默认自动续跑。
- 测试：plan SSE、confirm 持久化、build 前置拒绝、任务边界恢复、重入交互。

## 分阶段执行
1. **阶段 A：后端契约闭环**
   - 在 [AiSiteAgent.php](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php) 完成 `postStartPlan/postConfirmPlan/postResumeBuild` 的请求校验、返回结构、错误码规范。
   - 在 `postStartBuild` 增加 confirmed plan 前置检查；缺失时统一返回 `PLAN_REQUIRED_BEFORE_BUILD`。
   - 固化持久化顺序：先写 `scope_json.plan_workbench` / `scope_json.build_checkpoint` 与 `build_tasks[*].status`，再发送 SSE 事件。

2. **阶段 B：执行蓝图与恢复状态机收敛**
   - 复用 [AiSiteExecutionBlueprintService.php](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php) 输出，补齐 tasks 字段契约（`field_plan/style_brief/palette_usage/content_brief/seo_brief`）。
   - 确保 `shared:header` / `shared:footer` 始终作为显式 task 持久化。
   - `postResumeBuild` 仅从 checkpoint 索引恢复未完成任务，避免重跑已 `done` 项。

3. **阶段 C：前端交互改造（显式继续）**
   - 在 [workspace.phtml](e:/WelineFramework/DEV-workspace/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml) 删除页面加载即 `startOperationStream(..., 'autoResumeActiveOperation')` 的默认行为。
   - 新增“检测到 running / 检测到未完成任务”的两类提示弹层与按钮分支：`继续` 调 `post-resume-build` 或观察流；`稍后再说` 只保留状态。
   - 保留 SSE 观察能力，但进入观察必须有显式用户动作或明确“继续观察”确认。

4. **阶段 D：测试与验收**
   - 集成测试覆盖：
     - plan SSE 事件序列 `start/progress/chunk/done/error`；
     - confirm 后 `plan_workbench.confirmed.execution_blueprint.tasks[]` 可重进读取；
     - 未确认方案调用 `post-start-build` 返回 `PLAN_REQUIRED_BEFORE_BUILD`；
     - resume 不回退 `done` 任务。
   - E2E 回归覆盖工作台重入：默认不自动续跑，用户确认后才继续。

## 关键风险与缓解
- 现网有未完成操作时，直接移除自动续跑可能导致“用户看不到进度”：通过“继续观察执行流”提示作为替代。
- 任务状态与 checkpoint 双写漂移：以 `build_tasks[*].status` 作为真相源，并在测试中增加一致性断言。
- 多入口文案漂移：同步检查默认模板与 zh-Hans 镜像。

## 验收标准
- 无 confirmed plan 时，build 启动被拒绝并返回标准错误码。
- 重入工作台不自动触发 build 或操作流，必须用户显式继续。
- confirmed execution blueprint 成为唯一 build 输入源。
- 文档口径与代码行为一致，相关集成/E2E 用例通过。
