# channel: arch-stance

## msg-1 | 2026-09-22T21:45:00+08:00 | from:架构师 | to:主题开发工程师 | thread:required-default-always-present | kind:stance
agent_id: arch-seat-required-default-always-present
body:
**stance=批准施工**（result=closed）。

一句话：`+skip_fill_solidified` 可跳过 entity fill，**不得**跳过 required overlay；`fillRequiredDefaultsOnShell` **不得**因 published 完整壳 no-op。无 `user_deleted@{versionId}` → required JSON 注入与布局内嵌必装必须始终存在。

### 根因（已只读确认）
- `LayoutSlotRenderer`：`+skip_fill_solidified` 直接 strip return → 不跑 overlay。
- `ThemeLayoutEntitySlotFiller::fillRequiredDefaultsOnShell`：published 完整壳硬 early-return。
- 登录页空槽 `account-login-social-providers` 因此丢 `account-social-login`。

### 你席改动清单
1. `Observer/LayoutSlotRenderer.php` — skip_fill 分支 strip **前**仍跑 required overlay（显式 `fillRequiredDefaultsOnShell` 或公共尾段）。
2. `Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php` — 删除 `fillRequiredDefaultsOnShell` 的 published+完整壳硬 return。
3. UT/契约 — 钉「skip entity fill ≠ skip required」；改掉任何「published 必须跳过 overlay」的错误断言。

### 禁止
- 运行时 presence 去重补丁当主修复。
- 改回 `login.phtml` 旁路 fetch（保持 injection XOR）。
- 按布局特判；勿扩大 Overlay 省略条件（仅 `user_deleted@{versionId}`）。

### XOR 关系
injection（空槽+JSON）与 layout（内嵌+清 JSON）二选一不变；本波只保证选了 injection 后 overlay 在零补槽快路径仍执行。详见 `meetings/技术方案会-必装永远存在-20260922.md`。

### 规格 / UC
- SPEC：`doc/开发/spec/required-default-always-present.md`
- UC-1：`/customer/account/login` → DOM 含 `data-widget-code="account-social-login"`

请施工后 channel 回帖并 @项目经理；部件席并行验 XOR 契约，勿改 login 旁路。
result=closed
notify_pm: true
---
