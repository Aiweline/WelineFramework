# Task: AI建站工作台域名购买异步 SSE 改造

- Task ID: 2026-03-25-0200-aisse
- Started: 2026-03-25 02:00
- Status: completed
- Owner: Codex
- Source: codex chat

## Goal

- 将 AI 建站工作台中的“域名购买”从主引导流程中拆出，改为独立长连接执行。
- 点击购买后，域名购买、DNS、解析、证书等流程在后台 SSE 中持续推进，直到完成或报错。
- 工作台顶部持续展示域名购买进度与阶段日志，同时不阻塞后续建站阶段继续操作。

## Scope

- In scope:
- 新增域名购买 workbench service 与独立 SSE 入口
- 将域名购买状态持久化到 session scope/provider_state 与 workbench event stream
- 工作台顶部域名进度卡片、日志展开区、自动重连/自动恢复逻辑
- 单元测试与 E2E 覆盖新增异步域名购买流程
- Out of scope:
- 改造现有 quick build 的整条 AI 生成链路
- 其他模块的 unrelated dirty changes

## Constraints

- 域名购买不能阻塞 AI 建站工作台后续阶段
- 顶部日志必须可在任意阶段展开查看
- 域名购买 SSE 仅在完成或报错时结束
- 以 `AiSiteBuilderEvent` 持久化事件流为前端实时日志的权威来源
- 不回滚用户或其他任务的脏工作区改动

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Websites/Service/AiWorkbench/DomainPurchaseWorkbenchService.php`
- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/DomainPurchaseWorkbenchServiceTest.php`
- `tests/e2e/specs/backend/ai-site-workbench.spec.js`

## Resume

- Review `result.md` for verification details and tooling notes.
