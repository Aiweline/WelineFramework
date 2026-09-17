---
status: superseded
work_kind: feature
feature_slug: site-setup-smtp-template-subtasks
module: Weline_Smtp
superseded_by: Weline_SiteSetupAssistant/doc/开发/spec/site-setup-extends-task-providers.md
updated: 2026-09-15
---

# （已纠偏）原「SMTP 模板子任务」草稿

用户已澄清：问题不在「助手里手写 SMTP 三条」，而在 **建站步骤 Extends 收集架构** + **指令只建 Provider**。

权威规格改见：

`app/code/Weline/SiteSetupAssistant/doc/开发/spec/site-setup-extends-task-providers.md`

Smtp 发信 / 模板 / 多语言检查将作为 **Smtp 侧 Provider 自带条目** 在该架构下落地，不再由助手或指令直接建任务。
