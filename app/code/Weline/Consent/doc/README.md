# Weline_Consent

Website 隔离的 Cookie/偏好同意（TASK-P1D-REV-005 / TEST-P1D-04）。

## 行为

- 同意记录按 `website_id`（含 0）+ `visitor_key` + `category_code` 隔离
- `visitor_key` 只能来自服务端签发的 `HttpOnly` 随机 Cookie；浏览器参数覆盖固定拒绝
- 当前状态持久化到 `consent_record`，grant/withdraw 追加写入 `consent_audit`
- 撤回非必要类目后横幅重新出现；必要类目不可撤回
- `recording_enabled=false`：禁止新 grant，既有状态与审计保留，隐私撤回仍可执行
- 读取配置或仓储失败时横幅 fail-safe 保持显示

## 入口

| 路径 | 用途 |
|---|---|
| `Service/ConsentService` | 授权/撤回/横幅判定 |
| `Service/OrmConsentRepository` | ORM 当前态与追加审计 |
| `Service/ConsentVisitorIdentity` | 服务端访客 Cookie 签发与校验 |
| `Controller/Frontend/Consent` | `status` / `accept` / `withdraw` |
| `view/hooks/.../body-end.phtml` | 前台横幅 |

验证路由（需 setup:upgrade + WLS≥9502）：`/weline_consent/frontend/consent/status`
