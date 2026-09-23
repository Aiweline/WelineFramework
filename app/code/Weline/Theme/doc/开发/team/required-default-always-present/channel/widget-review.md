# channel: widget-review

## msg-1 | 2026-09-22T21:48:00+08:00 | from:部件开发工程师 | to:项目经理 | thread:required-default-always-present | kind:review
agent_id: widget-seat-required-default-always-present
body:
**席位交付（只读核对 + curl 探活）** · `plan_id=widget-xor` · result=**delivered_with_blocker**（契约 XOR 过；店面 UC 空槽，根因在 Theme overlay，本席不改回 fetch）。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## XOR 结论：**PASS（源码契约仍锁 injection）**

| 检查项 | 结果 | 证据 |
|--------|------|------|
| `login.phtml` 仅空槽 `account-login-social-providers` | ✅ | 无 sibling `fetch('…/account-social-login.phtml')`；注释明确 Slot-only |
| `widget.php` `account-social-login` | ✅ | `placement => 'injection'`；`default_injections` 含 `layout_type => 'account/login'` + `required => true`（另有遗留 `account.auth`） |
| `AccountSocialLoginWidgetContractTest` | ✅ | `OK (1 test, 39 assertions)` — 锁空槽禁止 sibling fetch + `'placement' => 'injection'` + `default_injections` |

**本席未改 Customer / Theme PHP。** 禁止把空槽回滚成旁路 fetch（与架构 stance / 技术方案会一致）。

UT 备注：契约测锁 XOR 核心（空槽 + injection）；`layout_type=account/login` 与 `required=true` 在 `widget.php` 源真相成立，测内以 `default_injections` + `placement` 间接覆盖，本波不扩测。

---

## curl 证据摘要：**FAIL（店面仍无 account-social-login）**

```text
URL: https://p05113ef3.test.weline.com:9555/customer/account/login
http_code=200
size≈815691
data-widget-code="account-social-login"  → 0
data-w-component="account-social-login" → 0
```

社媒区实际 HTML（节选）：

```html
<div class="w-auth-login__social w-auth-login__social--quick">
  <p class="w-auth-login__social-title">Quick sign-in</p>
  <div class="w-auth-login__social-slot" weline-code="customer.account.login.social_providers">
  </div>
</div>
```

- 空槽壳已渲染（`w-auth-login__social-slot`），**无** `weline-template-widget` / `data-widget-code="account-social-login"`。
- 与「改回 fetch」无关：源码已正确走 injection 空槽；**缺的是 Theme required overlay 写入**。

---

## 根因（上报 Theme，本席不修）

对齐 `arch-stance.md` / `meetings/技术方案会-必装永远存在-20260922.md`：

1. `LayoutSlotRenderer`：`+skip_fill_solidified` 直接 strip return → **不跑** required overlay。
2. `ThemeLayoutEntitySlotFiller::fillRequiredDefaultsOnShell`：published 完整壳硬 early-return → overlay **no-op**。

→ 登录页 `account-login-social-providers` 丢 `account-social-login`。

**修复归属：主题席 `theme-runtime`。** 本席禁止改 Theme 核心 PHP；禁止为过 UC 改回 `login.phtml` fetch。

主题席修完后请重跑同 URL curl，期望 ≥1：

- `data-widget-code="account-social-login"` 或
- `data-w-component="account-social-login"`

---

## 本席动作范围

| 做了 | 未做 |
|------|------|
| 只读核对 login / widget.php / 契约 UT | 未改 Customer `doc/开发日志.md`（只读） |
| curl 探活并写清空槽根因 | 未改 Theme PHP / 未 git restore |
| channel 本帖 | 未替测试席做 Browser 汇审 |

result=delivered_with_blocker  
notify_pm: true
---
