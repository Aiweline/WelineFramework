# Spec：网站 AI 运营程序（Weline_Ops）

## 架构

| 字段 | 内容 |
|------|------|
| mechanism | 按 website 的运营程序 + 画像/任务/运行日志结构化持久化；Lane Extends 聚合连接健康；智能体经 `Weline_Ai` Extends 注册，经 `AiRuntimeInterface::executeAgent` 执行 |
| owning_module | `Weline_Ops`（产品面/编排/记忆表）；执行引擎：Ai / Social / Seo / Visitor / Blog / Websites / Marketing |
| reuse | Agent Extends 扫描；各域后台深链与公开查询；Promotion Desk 布局习惯 |
| invent | OpsWebsiteProgram / OpsPersona / OpsTask / OpsRunLog；OpsLaneContributionInterface；WebsiteOpsAgent；统一 Desk |
| not_to_do | 不在 Ai Runtime 硬编码站业务；不重写 GSC/OAuth/像素；无审批不自动外发；不以 AiKnowledge 文档 RAG 冒充站点运营记忆 |

## 记忆策略（相对 RAG）

- **P1**：结构化表保证「不忘」——画像、任务、连接缺口、运行日志。
- **真向量 RAG**：`Weline_AiKnowledge` 面向框架文档且 dense embedding 仍文本回退；`Weline_Agent` Memory 检索为关键词——**站点运营记忆不依赖二者**；另立项再接 embedding 默认模型。

## 日更分析三源（P2）

Visitor 事件摘要 + Seo/GSC（可选）+ Social（可选）；缺外部源可降级，须在报告标明。

## UC（P1）

- UC-enable-site / UC-persona / UC-connections / UC-task-create|close

## 画像证据包（1.3.0）

| 阶段 | 源 | 说明 |
|------|----|------|
| site | Website name/description | 描述为低可信口述 |
| catalog | StorefrontCategoryTreeIndex | 顶级+子类摘要 |
| products | Product shard published sample | 标题/可选价格带 |
| policy | Cms Page + Theme PolicyDocumentDefaults + Faq | 履约边界 hints |
| market | WebsiteLanguage codes | 启用语种 |
| ai | AiRuntime 云端文本模型 | `ops_persona_ai_v4`；禁 Ollama 默认 |
| persist | OpsPersona / OpsRunLog | 结构化记忆 |

Desk：`GET …/refresh-persona-stream?website_id=&sse=1` + `<w:theme:sse-progress id=ops-persona-progress>`。

## 分析分发任务队列（原 P2/P3 合并）

见 [`ops-async-agent-dispatch.md`](./ops-async-agent-dispatch.md)（Session + Queue + ops_analyst/blog_publish/social_ops + 调度任务管理页 + 审批闸门）。

## 验收

- unit：ScopeCatalog、Lane 聚合、Program/Persona/Task 状态
- 契约：菜单 `ai_ops_group`、Desk 模板钩子
- 后台 Browser：打开运营台（店面发文留 P3）
