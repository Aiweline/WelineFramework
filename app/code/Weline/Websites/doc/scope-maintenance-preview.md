# Scope 维护模式与签名预览

## 目标

`ScopeMaintenanceGate` 为 Website、Store 或 Channel Scope 提供跨请求、跨
WLS Worker 一致的维护门禁。维护状态不得保存在进程数组中；预览令牌只能
放行只读请求，不能把维护态写请求变成可写。

门禁按“当前 Channel → 所属 Store → 所属 Website”从具体到一般查找首个
启用状态。Website 级维护覆盖其全部 Store/Channel；Store 级维护只覆盖该
Store 及其 Channel；兄弟 Store 互不影响。预览令牌绑定实际命中的维护
Scope，而不是由浏览器自行声明请求 Scope。

## 持久化事实源

- `websites_scope_maintenance`：每个 canonical Scope 一行，保存
  `enabled`、`reason`、`generation` 和 `since_at`。
- `websites_maintenance_preview_token`：只保存令牌 SHA-256 摘要、kid、
  generation、签发/过期时间和撤销状态，不保存原始令牌。
- `websites_scope_maintenance_audit`：append-only 记录 enable、disable、
  token issue/revoke 与批量撤销。
- 三个模型均使用主库连接；状态切换、generation 递增、令牌失效和审计在
  owner write transaction 内完成。

不存在状态行等价于 `enabled=false / generation=0`。启用、禁用和重新启用
都会递增 generation；禁用还会持久标记该 Scope 的现有令牌已撤销。因此旧
令牌即使仍在 TTL 内，也不能跨 Worker 继续使用。

## 令牌契约

- 格式：`mpt.v1.{kid}.{canonical_payload}.{hmac}`。
- 签名复用 `ScopeTokenKeyring`，请求路径不生成密钥；keyring 缺失、回退
  或读取失败均视为服务不可用。
- payload 精确包含完整 `ScopeIdentity`、audience、随机 token ID、
  generation、`readonly=true`、`iat`、`exp`。
- 验证顺序：结构与大小 → kid/keyring → HMAC → 精确 claims/规范化 JSON
  → Scope/expiry/readonly → 当前 durable generation → durable token 摘要
  与撤销状态。
- 原始令牌只在授权后台签发响应中出现；日志、ORM 和审计只能记录摘要。

## HTTP 与业务接线

- `ScopeMaintenanceObserver` 在前台路由前读取当前 Scope 的 durable 状态。
- 有效 preview 只设置请求上下文
  `scope.maintenance_preview=true`；业务写路径仍须调用
  `ScopeMaintenanceGate::assertWritable()`，并得到
  `scope_maintenance_preview_readonly`。
- 数据库或 keyring 的意外异常统一返回 503
  `scope_maintenance_unavailable`，禁止静默绕过维护模式。
- 后台模板通过 `Weline.Api.resource('websiteMaintenance')` 调用
  `status`、`set`、`issuePreview`、`revokePreview`；禁止 native
  Ajax/XHR/fetch/axios。

## 运维与回滚

- 紧急阻断预览：调用 `revokePreview`，或禁用/重新启用 Scope 递增
  generation。
- 关闭维护模式：写 durable `enabled=false`，不要删除审计。
- keyring 轮换沿用 `ScopeTokenKeyring` 的 active/verify-only 和单调版本
  规则。
- Schema 通过 `#[Col]` / `#[Index]` 与 `php bin/w setup:upgrade` 管理；
  禁止修改 `generated/` 或 `Setup/Upgrade.php`。
