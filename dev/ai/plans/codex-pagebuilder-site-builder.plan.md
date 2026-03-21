---
name: Codex Recovered Plan - PageBuilder Site Builder
overview: PageBuilder 原生建站工作台：在会话/事件持久化与混合主题能力之上，接通后台 UI、SSE 与发布物料化；本文件已与当前仓库对齐并持续迭代。
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T10-32-29-019d0e3c-a905-76c2-849c-8e2117376d1d.jsonl
source_timestamp: 2026-03-21T04:40:49.188Z
status: in_progress
isProject: true
todos:
  - id: codex-pagebuilder-site-builder-1
    content: Inspect PageBuilder and Websites models, render pipeline, quick-build and domain services, and existing site-builder references
    status: completed
  - id: codex-pagebuilder-site-builder-2
    content: Implement persistence-layer changes for session and event storage, virtual-theme models, and Page fields
    status: in_progress
  - id: codex-pagebuilder-site-builder-3
    content: Add hybrid theme source infrastructure and refactor render and style selection to support file and virtual themes
    status: in_progress
  - id: codex-pagebuilder-site-builder-4
    content: Implement draft preview and layout or component services plus backend controllers for the PageBuilder site builder
    status: in_progress
  - id: codex-pagebuilder-site-builder-5
    content: Build the PageBuilder-native site-builder UI, SSE endpoints, menu, ACL, i18n, and final site materialization flow
    status: in_progress
  - id: codex-pagebuilder-site-builder-6
    content: Run setup upgrades and targeted verification
    status: pending
---

# Codex Recovered Plan - PageBuilder Site Builder

## Recovery Note

最初条目来自 Codex session 的 `update_plan`，已在 **2026-03-21** 按仓库现状重写说明与待办状态。

## 关联计划

- 同主题细化方案：`dev/ai/plans/codex-pagebuilder-ai-site-agent.plan.md`
- 结构化旅程与阶段：`dev/ai/codex/plans/ai-site-agent.plan.md`

## 仓库对照（2026-03-21）

| 能力 | 位置 / 说明 |
|------|-------------|
| 快速建站向导（DNS/CDN/SSL 等） | `GuoLaiRen_PageBuilder` → `QuickBuild` + `QuickBuildAggregator` + `Weline_Websites` 等 quickbuild 观察者 |
| 建站智能体（购买/解析/SSE） | `Weline_Websites` → `SiteBuilderAgent` + `WebsiteAgentService` |
| AI 建站会话持久化 | `GuoLaiRen_PageBuilder` → `AiSiteAgentSession` / `AiSiteAgentSessionEvent` / `AiSiteAgentSessionService` |
| 虚拟主题部件源 | `Weline_Theme` → `VirtualThemeComponentSource`（库表 `SOURCE_TYPE_VIRTUAL`） |
| **PageBuilder 原生工作台入口（本切片）** | `GuoLaiRen_PageBuilder` → `Controller/Backend/AiSiteAgent`；菜单「快速建站」→「AI 建站工作台」 |

## 已完成（相对原始 Explanation）

- **数据契约（会话/事件）**：`AiSiteAgentSession` / `AiSiteAgentSessionEvent`、scope JSON、阶段与 `website_id` / `weline_theme_id` 已在模型层落地；**Page 侧专用草稿字段**若与页面表强绑定仍需单独设计（todo 2 未收口）。
- **混合主题基础设施（部分）**：`VirtualThemeComponentSource` 与 `ThemeComponentCatalog` 已聚合虚拟部件；全链路渲染/样式选择在「建站会话 → 预览 → 正式站点」上仍需收口（见 todo 3）。
- **后台控制器（首版）**：`AiSiteAgent::index`（创建会话、按令牌打开、最近列表）、`workspace`（scope/事件只读展示）、`postCreateSession`（JSON）。

## 进行中 / 待办

- **草稿预览与组件服务**：把可视化预览接到会话 `weline_theme_id` 与 PageBuilder 页面草稿字段（与 `codex-pagebuilder-site-builder-4` 对齐）。
- **SSE 与阶段驱动 UI**：复用 `WelineSseTerminal` 模式或独立端点，驱动 `STAGE_*` 与 `appendEvent`。
- **最终物料化**：域名就绪后绑定站点、创建/挂载页面，复用 QuickBuild / Websites 已有链路。
- **验证**：登录后台后 `http:request` 或浏览器打开 `pagebuilder/backend/aiSiteAgent/index`；新建 Controller 后需 `setup:upgrade`（本环境 `--route` 单独传参曾报 CLI 校验异常，可用模块级 `setup:upgrade -m GuoLaiRen_PageBuilder`）。

## Original Explanation（保留）

This work spans schema, services, render pipeline, controllers, and backend UI. I’m sequencing it so data contracts and hybrid theme support land before SSE/UI wiring.
