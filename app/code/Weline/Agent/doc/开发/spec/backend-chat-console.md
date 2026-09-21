---
status: validated
work_kind: feature
feature_slug: backend-chat-console
module: Weline_Agent
updated: 2026-09-21
---

# 后台聊天控制台可对话

## 澄清记录

| # | 问题 | 答案 | 时间 |
|---|------|------|------|
| 1 | 当前页状态 | 占位「即将接入」，不可聊 | 2026-09-21 |
| 2 | 传输通道 | **仅 BinQuery** `Weline.Api.resource('agent')`；禁止 `Api/V1/Chat` / 原生 fetch | 2026-09-21 |
| 3 | 流式 | 本回合 **request/response**（Engine 假 SSE 不接）；Loading + 整段回复 | 2026-09-21 |
| 4 | 布局 | 左选角色 / 最近会话；右时间线 + composer；Hook 侧栏与消息工具位 | 2026-09-21 |

## 目标 / 非目标

**目标**

- 管理员可点选启用角色、输入消息、看到用户/智能体气泡、会话落库并可在会话管理回放。
- 前后端写读均走 `agent` QueryProvider（`sendMessage` / `getChatHistory`）。

**非目标**

- 不实现真 token 流式 SSE。
- 不改 `Api/V1/Chat` 前台契约（后台不接）。
- 不做聊天台 A/B/C 视觉变体原型（角色列表原型仍独立）。

## EARS

- When 管理员打开聊天控制台且存在启用角色，the system shall 展示可选角色列表与输入区（非「即将接入」占位）。
- When 管理员选中角色并发送非空消息，the system shall 经 BinQuery `sendMessage` 调用 `AgentEngine::execute`，落库用户与助手消息，并在时间线展示结果或错误 Toast。
- When 存在 `session_id`，the system shall 允许 `getChatHistory` 拉取消息时间线。
- When 浏览器以 HTML 导航写接口，the system shall 不整页吐 JSON（写操作仅经 BinQuery）。
- If 未选角色或消息为空，the system shall 阻止发送并提示。

## 用例

### UC1 选角色发消息

1. 管理员进入「聊天控制台」。
2. 点击左侧「SEO 助手」等角色，右侧显示当前角色条与 composer。
3. 输入「你好」并发送。
4. 时间线出现用户气泡；成功则出现智能体气泡；失败则 Toast 错误且页面仍为 HTML。

### UC2 继续会话

1. 发送成功后 UI 持有 `session_id`。
2. 再发第二条消息，同一会话累加。
3. 会话管理可打开该会话回放。

### UC3 无角色

1. 无启用角色时展示空态与「去创建角色」链接。

## 验收

- Browser：选角色 → 发送 → 时间线更新；无整页 JSON。
- UT：QueryProvider 声明 `sendMessage`/`getChatHistory` 参数。
- e2e chapter + plan-suite PASS。
- 汇审：`doc/开发/team/chat-console/meetings/汇审.md`。
