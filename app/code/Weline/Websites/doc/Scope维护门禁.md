# Scope 维护与 Preview Token（TASK-P1D-004-MAINTENANCE）

## 类

| 类 | 职责 |
|---|---|
| `OrmScopeMaintenanceRepository` | 主库持久化 Scope 状态、token 摘要与 append-only audit |
| `ScopeMaintenanceGate` | 按完整 Scope 层级解析维护状态；写路径 `assertWritable` |
| `MaintenancePreviewTokenService` | 签名 `mpt.v1` 只读 token；绑定 Scope、generation 与 TTL |

## 规则

- Store A 维护不影响 Store B
- Preview token **只读**：带 token 写操作 → `scope_maintenance_preview_readonly`
- 无 token 访问维护 Scope → `scope_maintenance_blocked`
- 数据库或 keyring 不可用 → `503 scope_maintenance_unavailable`
- 禁用、重新启用或 revoke 都会让旧 generation/token 立即失效
- ORM 仅保存 token SHA-256 摘要，禁止保存原始 token

完整持久化、签名、HTTP 接线及回滚契约见
[`scope-maintenance-preview.md`](scope-maintenance-preview.md)。
