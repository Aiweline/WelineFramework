---
status: ready-for-plan
work_kind: feature
feature_slug: site-setup-extends-task-providers
module: Weline_SiteSetupAssistant
related_modules:
  - Weline_Smtp
updated: 2026-09-15
plan_mode: rejected_by_user
implementing: true
---

# 建站助手：抽象 Provider + Extends 收集（删除旧硬编码）

## 澄清记录（终版）

| # | 确认 |
|---|------|
| 架构 | **继承** `AbstractSetupTaskProvider` + Extends 登记 |
| 旧代码 | **全部删除**硬编码 `$tips` 与 `SetupTaskStatus*` |
| 指令 | `dev/ai-command/sitesetup/建站.md` 只建 Provider |
| Smtp | `smtp_transport` / `smtp_mail_template` / `smtp_mail_template_i18n` |

## 目标

1. SiteSetupAssistant 定义抽象 `SetupTaskProviderInterface`（+ 可选抽象基类），Collector 只收集与调用。
2. 浮层**零业务任务硬编码**；无 Provider 则空态。
3. 建站指令抽象「如何为模块建 Provider」，不维护任务清单。
4. Smtp 用新 Provider 注册多条目并自检；删除旧 `SmtpSetupTaskStatusProvider` 半套语义（或以新接口重写并删旧名）。

## 非目标

- 助手内嵌业务表单
- 指令正文列全局任务表
- 迁移期内保留硬编码 `$tips` 兜底（用户要求全部删除）

## 用户故事 / EARS / UC

见前版；新增：

6. WHEN 浮层渲染 THEN SHALL NOT 含任何写死的业务模块 tip 数组；任务列表 SHALL 仅来自 Collector。
7. WHEN 新增建站指令文档 THEN SHALL 描述抽象接口与「为模块建 Provider」步骤，SHALL NOT 教 Agent 改浮层加任务。

## fe_be_scope

- BE：Api/Service/Extends/Smtp Provider/指令文档/UT
- FE：浮层 phtml 只渲染收集结果（有 Web，须 Browser WB-OP）
- ui_skill_decision: participate（浮层结构可能变空态/列表源）

## 架构设计（agent 内落笔，因 Plan Mode 被拒）

- mechanism: Extends 多实现 + Interface/Abstract Provider
- owning_module: Weline_SiteSetupAssistant
- reuse: Extends 扫描模式（对齐现 Collector）、Smtp 发信确认 Helper
- invent: 完整任务定义契约、建站指令 md、Smtp 多条目 Provider
- not_to_do: 浮层硬编码 tips；指令直接建任务；助手内写 Smtp 业务规则

## 就绪检查

- [x] ready-for-plan
- [x] EARS + UC
- [x] 旧代码删除已确认
- [x] Plan Mode 被拒已记录
